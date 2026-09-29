<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Registrar gasto», con y sin «Ya lo pagué».
 *
 * Lo que estas pruebas defienden, que es lo que el módulo existe para hacer bien:
 *
 *  1. VENCIDO SE CALCULA POR CUOTA. Una cuota futura impaga no vuelve vencido el
 *     gasto entero, y un pago repartido cambia el vencido solo por lo que cubrió.
 *  2. TODO O NADA. Si falla el archivo, no queda ni gasto, ni cuota, ni pago, ni
 *     aplicación, ni fichero en disco.
 *  3. NO SE DUPLICA. Reintento y doble clic con la misma clave devuelven el mismo
 *     gasto y el mismo pago, no dos.
 *  4. NO SE SOBREPAGA. Aplicar más que el pendiente de una cuota se rechaza.
 *  5. EL ÁMBITO ES UN CANDADO, no una etiqueta. Quien solo consulta empresa no ve
 *     un gasto personal, ni un pago mixto, ni el comprobante que los comparte.
 *
 * Toda la prueba corre sobre SQLite :memory: (ver Tests\TestCase) y disco fingido.
 */
class RegistrarGastoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('gastos.enabled', true);
        Storage::fake('local');
    }

    /** Usuario con exactamente los permisos que se le den (sin rol: permisos directos). */
    private function usuario(array $permisos = [], bool $activo = true): User
    {
        $usuario = User::factory()->create(['activo' => $activo]);
        $usuario->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $usuario->fresh();
    }

    private function operador(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
            PermisoSistema::GastosPersonales->value,
            PermisoSistema::GastosPagosRegistrar->value,
        ]);
    }

    /** Payload mínimo válido de un gasto pendiente de pago, de una sola cuota. */
    private function datos(User $usuario, array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(),
            'concepto' => 'Internet de septiembre',
            'beneficiario' => 'Proveedor A',
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'monto_pendiente' => 0,
            'importe' => '50.00',
            'responsable_id' => $usuario->id,
            'documentacion' => 'pendiente',
            'ya_pagado' => 0,
            'cuotas' => [['importe' => '50.00', 'vence' => '2026-09-30', 'aplicar' => '']],
        ], $extra);
    }

    // ───────────────────────────── El camino normal ─────────────────────────────

    public function test_guarda_un_gasto_pendiente_de_pago(): void
    {
        $usuario = $this->operador();

        $respuesta = $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario));

        $gasto = Gasto::sole();
        $respuesta->assertRedirect(route('gastos.create', ['guardado' => $gasto->id]));

        $this->assertSame('Internet de septiembre', $gasto->concepto);
        $this->assertSame('50.00', $gasto->importe);
        $this->assertSame('empresarial', $gasto->ambito);
        $this->assertSame($usuario->id, $gasto->registrado_por);
        $this->assertSame(1, $gasto->cuotas()->count());
        $this->assertSame(0, Pago::count());

        $this->assertDatabaseHas('gastos_eventos', ['gasto_id' => $gasto->id, 'accion' => 'gasto_registrado']);
    }

    public function test_ya_lo_pague_guarda_gasto_pago_aplicacion_y_comprobante_en_una_sola_operacion(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => '2026-09-30', 'aplicar' => '50.00']],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'pago_referencia' => 'TRF-9931',
            'comprobantes' => [UploadedFile::fake()->image('comprobante.jpg')],
        ]))->assertRedirect();

        $gasto = Gasto::sole();
        $pago = Pago::sole();
        $cuota = $gasto->cuotas()->sole();

        $this->assertSame('50.00', $pago->importe);
        $this->assertSame('Proveedor A', $pago->beneficiario);
        $this->assertSame('USD', $pago->moneda);
        $this->assertNull($pago->revertido_at);

        $this->assertDatabaseHas('gastos_pago_aplicaciones', [
            'pago_id' => $pago->id, 'cuota_id' => $cuota->id, 'importe' => '50.00',
        ]);

        // La cuota queda saldada y el gasto sin pendiente.
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($cuota));

        // El comprobante cuelga del PAGO, nunca del gasto: son respaldos distintos.
        $adjunto = DB::table('gastos_adjuntos')->sole();
        $this->assertSame($pago->id, $adjunto->pago_id);
        $this->assertNull($adjunto->gasto_id);
        Storage::disk('local')->assertExists($adjunto->ruta);

        // Nombre y ruta los genera el servidor; el del cliente solo se conserva como etiqueta.
        $this->assertSame('comprobante.jpg', $adjunto->nombre);
        $this->assertStringStartsWith('gastos/', $adjunto->ruta);
        $this->assertStringNotContainsString('comprobante', $adjunto->ruta);

        $this->assertDatabaseHas('gastos_eventos', ['pago_id' => $pago->id, 'accion' => 'pago_registrado']);
    }

    public function test_ya_lo_pague_admite_un_abono_parcial(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'importe' => '100.00',
            'cuotas' => [['importe' => '100.00', 'vence' => '2026-09-30', 'aplicar' => '30.00']],
            'ya_pagado' => 1,
            'pago_importe' => '30.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'efectivo',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'Pago en efectivo sin recibo; lo adjuntaré después',
        ]))->assertRedirect();

        $gasto = Gasto::sole();
        $this->assertSame('30.00', Pago::sole()->importe);
        $this->assertSame(7000, app(SaldosGastos::class)->pendienteCuota($gasto->cuotas()->sole()));
        $this->assertSame(
            'Pago en efectivo sin recibo; lo adjuntaré después',
            Pago::sole()->sin_comprobante,
            'Sin comprobante se permite, pero con motivo registrado.'
        );
    }

    /**
     * El ejemplo textual del diseño: cuotas de 40 (vencida) y 60 (futura). Un pago de
     * 50 repartido 40/10 deja pendiente 50 y vencido 0. Con el reparto al revés
     * —20 a la futura— quedan pendiente 80 y vencido 40.
     */
    public function test_vencido_suma_solo_el_saldo_de_las_cuotas_vencidas(): void
    {
        $usuario = $this->operador();
        $this->travelTo('2026-09-15 09:00:00');

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'importe' => '100.00',
            'cuotas' => [
                ['importe' => '40.00', 'vence' => '2026-09-03', 'aplicar' => '40.00'],
                ['importe' => '60.00', 'vence' => '2026-10-03', 'aplicar' => '10.00'],
            ],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'Comprobante pendiente de descargar del banco',
        ]))->assertRedirect();

        $resumen = app(SaldosGastos::class)->resumen(Gasto::sole()->fresh(), '2026-09-15');

        $this->assertSame(5000, $resumen['pendiente'], 'Quedan 50 por pagar.');
        $this->assertSame(0, $resumen['vencido'], 'La cuota vencida quedó saldada; la de octubre todavía no vence.');
    }

    public function test_un_pago_a_la_cuota_futura_deja_vencida_la_anterior(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'importe' => '100.00',
            'cuotas' => [
                ['importe' => '40.00', 'vence' => '2026-09-03', 'aplicar' => ''],
                ['importe' => '60.00', 'vence' => '2026-10-03', 'aplicar' => '20.00'],
            ],
            'ya_pagado' => 1,
            'pago_importe' => '20.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'Comprobante pendiente',
        ]))->assertRedirect();

        $resumen = app(SaldosGastos::class)->resumen(Gasto::sole()->fresh(), '2026-09-15');

        $this->assertSame(8000, $resumen['pendiente']);
        $this->assertSame(4000, $resumen['vencido'], 'Solo la cuota de septiembre, no el gasto entero.');

        // Y la aplicación fue exactamente una: la cuota sin reparto no genera fila de importe cero.
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
    }

    public function test_una_cuota_sin_fecha_nunca_cuenta_como_vencida(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => null, 'aplicar' => '']],
        ]))->assertRedirect();

        $resumen = app(SaldosGastos::class)->resumen(Gasto::sole(), '2030-01-01');

        $this->assertSame(5000, $resumen['pendiente']);
        $this->assertSame(0, $resumen['vencido']);
    }

    public function test_monto_desconocido_no_inventa_cero_ni_deuda_vencida(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), [
            'clave' => (string) Str::uuid(),
            'concepto' => 'Electricidad, falta el recibo',
            'beneficiario' => 'Distribuidora',
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'monto_pendiente' => 1,
            'responsable_id' => $usuario->id,
            'documentacion' => 'pendiente',
            'ya_pagado' => 0,
        ])->assertRedirect();

        $gasto = Gasto::sole();
        $this->assertNull($gasto->importe);
        $this->assertSame(0, $gasto->cuotas()->count());

        $resumen = app(SaldosGastos::class)->resumen($gasto, '2030-01-01');
        $this->assertNull($resumen['pendiente'], 'Pendiente desconocido, no cero.');
        $this->assertSame(0, $resumen['vencido']);
    }

    public function test_no_se_puede_marcar_ya_lo_pague_sin_conocer_el_monto(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'monto_pendiente' => 1,
            'ya_pagado' => 1,
            'pago_importe' => '10.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'efectivo',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'sin recibo',
        ]))->assertSessionHasErrors('importe');

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    // ─────────────────────── Importes exactos y sobrepagos ───────────────────────

    public function test_las_cuotas_deben_sumar_el_importe_del_gasto(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'importe' => '100.00',
            'cuotas' => [['importe' => '40.00', 'vence' => null, 'aplicar' => '']],
        ]))->assertSessionHasErrors('cuotas');

        $this->assertSame(0, Gasto::count());
    }

    public function test_el_reparto_debe_sumar_exactamente_el_importe_pagado(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => null, 'aplicar' => '20.00']],
            'ya_pagado' => 1,
            'pago_importe' => '30.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'efectivo',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'sin recibo',
        ]))->assertSessionHasErrors('pago_importe');

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_aplicar_mas_que_la_cuota_se_bloquea(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => null, 'aplicar' => '80.00']],
            'ya_pagado' => 1,
            'pago_importe' => '80.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'efectivo',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'sin recibo',
        ]))->assertSessionHasErrors('cuotas.0.aplicar');

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_los_importes_con_mas_de_dos_decimales_se_rechazan(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)
            ->post(route('gastos.store'), $this->datos($usuario, [
                'importe' => '50.005',
                'cuotas' => [['importe' => '50.005', 'vence' => null, 'aplicar' => '']],
            ]))
            ->assertSessionHasErrors('importe');

        $this->assertSame(0, Gasto::count());
    }

    // ───────────────────────── Duplicados y reintentos ─────────────────────────

    public function test_reenviar_el_mismo_formulario_no_crea_un_segundo_gasto_ni_un_segundo_pago(): void
    {
        $usuario = $this->operador();

        $datos = $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => '2026-09-30', 'aplicar' => '50.00']],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'sin recibo',
        ]);

        $primera = $this->actingAs($usuario)->post(route('gastos.store'), $datos);
        $segunda = $this->actingAs($usuario)->post(route('gastos.store'), $datos);

        $this->assertSame(1, Gasto::count(), 'La misma clave no crea dos gastos.');
        $this->assertSame(1, Pago::count(), 'Ni dos pagos.');
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
        $this->assertSame(1, Cuota::count());
        $this->assertSame($primera->headers->get('Location'), $segunda->headers->get('Location'));
    }

    public function test_la_misma_clave_con_otros_datos_se_rechaza_en_vez_de_sobrescribir(): void
    {
        $usuario = $this->operador();
        $datos = $this->datos($usuario);

        $this->actingAs($usuario)->post(route('gastos.store'), $datos)->assertRedirect();

        $this->actingAs($usuario)
            ->post(route('gastos.store'), array_replace($datos, ['concepto' => 'Otra cosa distinta']))
            ->assertSessionHasErrors('clave');

        $this->assertSame(1, Gasto::count());
        $this->assertSame('Internet de septiembre', Gasto::sole()->concepto);
    }

    // ─────────────────────────── Fallos de archivo ───────────────────────────

    public function test_si_falla_el_archivo_no_queda_ni_gasto_ni_pago_ni_fichero(): void
    {
        $usuario = $this->operador();

        // El disco rechaza la escritura: el equivalente a un disco lleno o sin permisos.
        Storage::shouldReceive('disk')->with('local')->andReturn($falso = \Mockery::mock());
        $falso->shouldReceive('putFileAs')->andReturn(false);
        $falso->shouldReceive('delete')->andReturn(true);

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => '2026-09-30', 'aplicar' => '50.00']],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'comprobantes' => [UploadedFile::fake()->image('comprobante.jpg')],
        ]))->assertSessionHasErrors('comprobantes');

        $this->assertSame(0, Gasto::count(), 'Ni el gasto.');
        $this->assertSame(0, Pago::count(), 'Ni el pago.');
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, DB::table('gastos_pago_aplicaciones')->count());
        $this->assertSame(0, DB::table('gastos_adjuntos')->count());
    }

    public function test_un_ejecutable_disfrazado_de_imagen_se_rechaza(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'documentacion' => 'adjunto',
            'documentos' => [UploadedFile::fake()->create('recibo.svg', 4, 'image/svg+xml')],
        ]))->assertSessionHasErrors('documentos.0');

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, DB::table('gastos_adjuntos')->count());
    }

    // ─────────────────────── Ámbito, permisos y privacidad ───────────────────────

    public function test_el_modulo_apagado_responde_404_incluso_con_todos_los_permisos(): void
    {
        config()->set('gastos.enabled', false);

        $this->actingAs($this->operador())->get(route('gastos.create'))->assertNotFound();
    }

    public function test_un_usuario_desactivado_no_entra_aunque_conserve_la_sesion(): void
    {
        $usuario = $this->operador();
        $usuario->update(['activo' => false]);

        $this->actingAs($usuario->fresh())->get(route('gastos.create'))->assertForbidden();
    }

    public function test_sin_permiso_de_registrar_no_se_abre_el_formulario(): void
    {
        $usuario = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->actingAs($usuario)->get(route('gastos.create'))->assertForbidden();
    }

    public function test_sin_permiso_personal_no_se_puede_registrar_un_gasto_personal(): void
    {
        $usuario = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
        ]);

        $this->actingAs($usuario)
            ->post(route('gastos.store'), $this->datos($usuario, ['ambito' => 'personal', 'persona' => 'Estudiante']))
            ->assertForbidden();

        $this->assertSame(0, Gasto::count());
    }

    public function test_sin_permiso_de_pagos_no_se_puede_usar_ya_lo_pague(): void
    {
        $usuario = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
        ]);

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'cuotas' => [['importe' => '50.00', 'vence' => null, 'aplicar' => '50.00']],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'efectivo',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'sin recibo',
        ]))->assertForbidden();

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_quien_solo_consulta_empresa_no_ve_un_gasto_personal_ni_su_confirmacion(): void
    {
        $duenio = $this->operador();
        $this->actingAs($duenio)->post(route('gastos.store'), $this->datos($duenio, [
            'ambito' => 'personal',
            'persona' => 'Estudiante',
            'concepto' => 'Universidad',
        ]))->assertRedirect();

        $soloEmpresa = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
        ]);

        $this->actingAs($soloEmpresa)
            ->get(route('gastos.create', ['guardado' => Gasto::sole()->id]))
            ->assertForbidden();
    }

    // ───────────────────────── Archivos privados ─────────────────────────

    public function test_el_documento_del_gasto_se_descarga_solo_por_controlador_autorizado(): void
    {
        $duenio = $this->operador();

        $this->actingAs($duenio)->post(route('gastos.store'), $this->datos($duenio, [
            'documentacion' => 'adjunto',
            'documentos' => [UploadedFile::fake()->image('recibo.png')],
        ]))->assertRedirect();

        $adjunto = DB::table('gastos_adjuntos')->sole();
        $this->assertSame(Gasto::sole()->id, $adjunto->gasto_id);

        $respuesta = $this->actingAs($duenio)->get(route('gastos.archivo', $adjunto->id));
        $respuesta->assertOk();
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('Cache-Control', 'no-store, private'); // Symfony normaliza el orden

        // Sin sesión, ni siquiera se ofrece.
        auth()->logout();
        $this->get(route('gastos.archivo', $adjunto->id))->assertRedirect(route('login'));
    }

    public function test_el_comprobante_de_un_gasto_personal_no_se_entrega_a_quien_solo_ve_empresa(): void
    {
        $duenio = $this->operador();

        $this->actingAs($duenio)->post(route('gastos.store'), $this->datos($duenio, [
            'ambito' => 'personal',
            'persona' => 'Estudiante',
            'cuotas' => [['importe' => '50.00', 'vence' => null, 'aplicar' => '50.00']],
            'ya_pagado' => 1,
            'pago_importe' => '50.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $duenio->id,
            'comprobantes' => [UploadedFile::fake()->image('voucher.jpg')],
        ]))->assertRedirect();

        $adjunto = DB::table('gastos_adjuntos')->sole();

        $soloEmpresa = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
            PermisoSistema::GastosPagosRegistrar->value,
        ]);

        $this->actingAs($soloEmpresa)->get(route('gastos.archivo', $adjunto->id))->assertForbidden();
        $this->actingAs($duenio)->get(route('gastos.archivo', $adjunto->id))->assertOk();
    }

    // ───────────────────────────── Presentación ─────────────────────────────

    public function test_el_formulario_muestra_sus_secciones_y_la_opcion_ya_lo_pague(): void
    {
        $respuesta = $this->actingAs($this->operador())->get(route('gastos.create'));

        $respuesta->assertOk();
        $respuesta->assertSee('Registrar gasto');
        $respuesta->assertSee('Pendiente de pago');
        $respuesta->assertSee('Ya lo pagué', false);
        $respuesta->assertSee('Datos del gasto');
        $respuesta->assertSee('Importe y vencimiento');
        $respuesta->assertSee('Documento del gasto');
        $respuesta->assertSee('Pago realizado');
        $respuesta->assertSee('¿Para quién es?', false);
        $respuesta->assertSee('Comprobantes del pago');
    }

    public function test_quien_no_puede_pagar_no_ve_la_opcion_ya_lo_pague(): void
    {
        $usuario = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
        ]);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.create'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Ya lo pagué', false);
    }

    public function test_la_confirmacion_muestra_el_pago_y_el_saldo_que_queda(): void
    {
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('gastos.store'), $this->datos($usuario, [
            'importe' => '100.00',
            'cuotas' => [['importe' => '100.00', 'vence' => '2026-09-30', 'aplicar' => '30.00']],
            'ya_pagado' => 1,
            'pago_importe' => '30.00',
            'pago_fecha' => '2026-09-06',
            'pago_metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'sin_comprobante' => 'Lo descargo del banco mañana',
        ]))->assertRedirect();

        $respuesta = $this->actingAs($usuario)->get(route('gastos.create', ['guardado' => Gasto::sole()->id]));

        $respuesta->assertOk();
        $respuesta->assertSee('guardado junto con su pago');
        $respuesta->assertSee('USD 30.00');   // el pago
        $respuesta->assertSee('70.00');       // el pendiente que queda
        $respuesta->assertSee('Falta comprobante');
    }

    // ───────────────────────────── Aritmética ─────────────────────────────

    public function test_el_dinero_se_calcula_en_centavos_enteros(): void
    {
        $this->assertSame(5000, Dinero::centavos('50'));
        $this->assertSame(5000, Dinero::centavos('50.00'));
        $this->assertSame(5, Dinero::centavos('0.05'));
        $this->assertSame(1050, Dinero::centavos('10.5'));
        $this->assertSame('10.50', Dinero::decimal(1050));
        $this->assertSame('0.05', Dinero::decimal(5));

        $this->expectException(\InvalidArgumentException::class);
        Dinero::centavos('10.005');
    }
}
