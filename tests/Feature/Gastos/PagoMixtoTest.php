<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Reglas del PAGO que deben quedar coherentes desde la base, aunque su pantalla
 * propia se construya después de aprobar la presentación del formulario.
 *
 * Un pago puede cubrir obligaciones empresariales y personales del MISMO
 * destinatario y moneda. No tiene ámbito editable: los subtotales por ámbito se
 * DERIVAN de sus aplicaciones a cuotas, así que no hay un segundo reparto que
 * pueda contradecir al primero.
 *
 * Registrar o revertir un pago mixto exige acceso a TODAS las obligaciones que
 * toca. Quien solo consulta empresa no puede registrarlo, no puede revertirlo y no
 * ve su cabecera: ocultarle filas dejando un total revelador no sería privacidad.
 */
class PagoMixtoTest extends TestCase
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
    }

    private function usuario(array $permisos): User
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $usuario->fresh();
    }

    private function operadorTotal(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
            PermisoSistema::GastosPersonales->value,
            PermisoSistema::GastosPagosRegistrar->value,
            PermisoSistema::GastosPagosCorregir->value,
        ]);
    }

    private function soloEmpresa(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosPagosRegistrar->value,
            PermisoSistema::GastosPagosCorregir->value,
        ]);
    }

    /** Un gasto de una cuota, creado directamente (esta prueba es del servicio de pagos). */
    private function gasto(User $usuario, string $ambito, string $importe, array $extra = []): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A',
            'concepto' => 'Concepto '.$ambito,
            'categoria' => 'Servicios',
            'ambito' => $ambito,
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'importe' => $importe,
            'documentacion' => 'pendiente',
            'responsable_id' => $usuario->id,
            'registrado_por' => $usuario->id,
        ], $extra));

        $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => '2026-09-03']);

        return $gasto->fresh();
    }

    private function datosPago(User $usuario, string $importe): array
    {
        return [
            'clave' => (string) Str::uuid(),
            'importe' => $importe,
            'fecha' => '2026-09-06',
            'metodo' => 'transferencia',
            'pagado_por' => $usuario->id,
            'referencia' => 'TRF-1',
            'sin_comprobante' => null,
        ];
    }

    public function test_un_pago_cubre_empresa_y_personal_del_mismo_destinatario_y_moneda(): void
    {
        $usuario = $this->operadorTotal();
        $empresa = $this->gasto($usuario, 'empresarial', '60.00');
        $personal = $this->gasto($usuario, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '100.00'), [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $this->assertSame('100.00', $pago->importe);
        $this->assertSame(2, DB::table('gastos_pago_aplicaciones')->where('pago_id', $pago->id)->count());

        // El subtotal por ámbito se DERIVA de las aplicaciones; el pago no lo guarda.
        $porAmbito = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->where('a.pago_id', $pago->id)
            ->groupBy('g.ambito')
            ->selectRaw('g.ambito as ambito, SUM(a.importe) as total')
            ->pluck('total', 'ambito');

        $this->assertEquals(60, $porAmbito['empresarial']);
        $this->assertEquals(40, $porAmbito['personal']);

        $saldos = app(SaldosGastos::class);
        $this->assertSame(0, $saldos->resumen($empresa->fresh(), '2026-09-15')['pendiente']);
        $this->assertSame(0, $saldos->resumen($personal->fresh(), '2026-09-15')['pendiente']);
    }

    public function test_quien_solo_consulta_empresa_no_puede_registrar_el_pago_mixto(): void
    {
        $duenio = $this->operadorTotal();
        $empresa = $this->gasto($duenio, 'empresarial', '60.00');
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $restringido = $this->soloEmpresa();

        $this->expectException(HttpException::class);

        try {
            app(RegistrarPago::class)->registrar($restringido, $this->datosPago($restringido, '100.00'), [
                ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
                ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
            ]);
        } finally {
            $this->assertSame(0, Pago::count(), 'Nada se registró a medias.');
            $this->assertSame(0, DB::table('gastos_pago_aplicaciones')->count());
        }
    }

    public function test_quien_solo_consulta_empresa_no_ve_la_cabecera_de_un_pago_mixto(): void
    {
        $duenio = $this->operadorTotal();
        $empresa = $this->gasto($duenio, 'empresarial', '60.00');
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($duenio, $this->datosPago($duenio, '100.00'), [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $acceso = app(AccesoGastos::class);

        $this->assertTrue($acceso->pagoCompleto($duenio, $pago));
        $this->assertFalse(
            $acceso->pagoCompleto($this->soloEmpresa(), $pago),
            'El total mixto revelaría el importe personal aunque se ocultara su fila.'
        );
    }

    public function test_un_pago_no_puede_mezclar_dos_destinatarios(): void
    {
        $usuario = $this->operadorTotal();
        $a = $this->gasto($usuario, 'empresarial', '60.00');
        $b = $this->gasto($usuario, 'empresarial', '40.00', ['beneficiario' => 'Proveedor B']);

        $this->expectException(ValidationException::class);

        try {
            app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '100.00'), [
                ['cuota_id' => $a->cuotas->first()->id, 'importe' => '60.00'],
                ['cuota_id' => $b->cuotas->first()->id, 'importe' => '40.00'],
            ]);
        } finally {
            $this->assertSame(0, Pago::count());
        }
    }

    public function test_un_pago_no_puede_mezclar_dos_monedas(): void
    {
        config()->set('gastos.monedas', ['USD', 'EUR']);

        $usuario = $this->operadorTotal();
        $a = $this->gasto($usuario, 'empresarial', '60.00');
        $b = $this->gasto($usuario, 'empresarial', '40.00', ['moneda' => 'EUR']);

        $this->expectException(ValidationException::class);

        try {
            app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '100.00'), [
                ['cuota_id' => $a->cuotas->first()->id, 'importe' => '60.00'],
                ['cuota_id' => $b->cuotas->first()->id, 'importe' => '40.00'],
            ]);
        } finally {
            $this->assertSame(0, Pago::count());
        }
    }

    public function test_el_sobrepago_se_bloquea_contra_el_pendiente_real_de_la_cuota(): void
    {
        $usuario = $this->operadorTotal();
        $gasto = $this->gasto($usuario, 'empresarial', '100.00');
        $cuota = $gasto->cuotas->first();

        app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '70.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '70.00'],
        ]);

        $this->expectException(ValidationException::class);

        try {
            // Queda pendiente 30: aplicar 40 más excedería la deuda.
            app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '40.00'), [
                ['cuota_id' => $cuota->id, 'importe' => '40.00'],
            ]);
        } finally {
            $this->assertSame(1, Pago::count(), 'El segundo pago no llegó a registrarse.');
            $this->assertSame(3000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
        }
    }

    public function test_repetir_la_misma_clave_devuelve_el_mismo_pago(): void
    {
        $usuario = $this->operadorTotal();
        $cuota = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $datos = $this->datosPago($usuario, '50.00');
        $aplicaciones = [['cuota_id' => $cuota->id, 'importe' => '50.00']];

        $primero = app(RegistrarPago::class)->registrar($usuario, $datos, $aplicaciones);
        $segundo = app(RegistrarPago::class)->registrar($usuario, $datos, $aplicaciones);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, Pago::count());
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
    }

    public function test_la_misma_clave_con_otro_reparto_se_rechaza(): void
    {
        $usuario = $this->operadorTotal();
        $gasto = $this->gasto($usuario, 'empresarial', '50.00');
        $cuota = $gasto->cuotas->first();

        $datos = $this->datosPago($usuario, '50.00');
        app(RegistrarPago::class)->registrar($usuario, $datos, [['cuota_id' => $cuota->id, 'importe' => '50.00']]);

        $otra = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $this->expectException(ValidationException::class);
        app(RegistrarPago::class)->registrar($usuario, $datos, [['cuota_id' => $otra->id, 'importe' => '50.00']]);
    }

    // ───────────────────────────── Reversión ─────────────────────────────

    public function test_revertir_devuelve_el_pendiente_sin_borrar_el_historial(): void
    {
        $usuario = $this->operadorTotal();
        $gasto = $this->gasto($usuario, 'empresarial', '100.00');
        $cuota = $gasto->cuotas->first();

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '30.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '30.00'],
        ]);
        $this->assertSame(7000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));

        app(RegistrarPago::class)->revertir($usuario, $pago, 'Se registró con la fecha equivocada');

        $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));

        // El pago NO se borra: queda marcado, con motivo y autor.
        $pago->refresh();
        $this->assertNotNull($pago->revertido_at);
        $this->assertSame($usuario->id, $pago->revertido_por);
        $this->assertSame('Se registró con la fecha equivocada', $pago->motivo_reversion);
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count(), 'Las aplicaciones originales se conservan.');
        $this->assertDatabaseHas('gastos_eventos', ['pago_id' => $pago->id, 'accion' => 'pago_revertido']);
    }

    public function test_revertir_dos_veces_no_cambia_nada_ni_duplica_el_evento(): void
    {
        $usuario = $this->operadorTotal();
        $cuota = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '50.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '50.00'],
        ]);

        app(RegistrarPago::class)->revertir($usuario, $pago, 'Motivo suficiente');
        $primerInstante = $pago->fresh()->revertido_at;

        app(RegistrarPago::class)->revertir($usuario, $pago->fresh(), 'Otro intento del mismo motivo');

        $this->assertEquals($primerInstante, $pago->fresh()->revertido_at);
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'pago_revertido')->count());
    }

    public function test_revertir_exige_permiso_de_correccion(): void
    {
        $usuario = $this->operadorTotal();
        $cuota = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '50.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '50.00'],
        ]);

        $sinCorregir = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosPagosRegistrar->value,
        ]);

        $this->expectException(HttpException::class);

        try {
            app(RegistrarPago::class)->revertir($sinCorregir, $pago, 'Quiero deshacerlo');
        } finally {
            $this->assertNull($pago->fresh()->revertido_at);
        }
    }

    public function test_revertir_un_pago_mixto_exige_acceso_a_los_dos_ambitos(): void
    {
        $duenio = $this->operadorTotal();
        $empresa = $this->gasto($duenio, 'empresarial', '60.00');
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($duenio, $this->datosPago($duenio, '100.00'), [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $this->expectException(HttpException::class);

        try {
            app(RegistrarPago::class)->revertir($this->soloEmpresa(), $pago, 'No debería poder');
        } finally {
            $this->assertNull($pago->fresh()->revertido_at);
        }
    }

    public function test_un_motivo_vacio_no_alcanza_para_revertir(): void
    {
        $usuario = $this->operadorTotal();
        $cuota = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '50.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '50.00'],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(RegistrarPago::class)->revertir($usuario, $pago, '   ');
        } finally {
            $this->assertNull($pago->fresh()->revertido_at);
        }
    }

    public function test_un_pago_revertido_deja_de_contar_para_el_vencido(): void
    {
        $usuario = $this->operadorTotal();
        $gasto = $this->gasto($usuario, 'empresarial', '100.00');
        $cuota = $gasto->cuotas->first(); // vence 2026-09-03

        $pago = app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '100.00'), [
            ['cuota_id' => $cuota->id, 'importe' => '100.00'],
        ]);

        $this->assertSame(0, app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-09-15')['vencido']);

        app(RegistrarPago::class)->revertir($usuario, $pago, 'Nunca salió la transferencia');

        $this->assertSame(10000, app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-09-15')['vencido']);
    }

    // ───────── Lo que SÍ conserva quien solo alcanza el ámbito empresarial ─────────

    /**
     * Ocultar la cabecera de un pago mixto no puede llevarse por delante la parte que
     * sí es del lector. Si alguien ve un gasto empresarial, tiene que poder ver cuánto
     * se le aplicó: si no, el saldo de su cuota bajaría sin explicación.
     */
    public function test_quien_solo_ve_empresa_conserva_el_importe_aplicado_a_su_gasto_empresarial(): void
    {
        $duenio = $this->operadorTotal();
        $empresa = $this->gasto($duenio, 'empresarial', '60.00');
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($duenio, $this->datosPago($duenio, '100.00'), [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $restringido = $this->soloEmpresa();
        $acceso = app(AccesoGastos::class);

        $visibles = $acceso->aplicacionesVisibles($restringido, $pago);

        $this->assertCount(1, $visibles, 'Ve su aplicación empresarial y solo esa.');
        $this->assertSame($empresa->id, (int) $visibles->first()->gasto_id);
        $this->assertSame('empresarial', $visibles->first()->ambito);
        $this->assertEquals(60, $visibles->first()->importe);

        // El subtotal es el de SU parte, nunca el total mixto de 100.
        $this->assertSame(6000, $acceso->subtotalVisible($restringido, $pago));
        $this->assertSame(10000, $acceso->subtotalVisible($duenio, $pago));

        // Y la cabecera sigue cerrada.
        $this->assertFalse($acceso->pagoCompleto($restringido, $pago));
    }

    public function test_un_pago_solo_personal_no_deja_ver_ninguna_aplicacion_a_quien_no_alcanza(): void
    {
        $duenio = $this->operadorTotal();
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($duenio, $this->datosPago($duenio, '40.00'), [
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $acceso = app(AccesoGastos::class);

        $this->assertTrue($acceso->aplicacionesVisibles($this->soloEmpresa(), $pago)->isEmpty());
        $this->assertSame(0, $acceso->subtotalVisible($this->soloEmpresa(), $pago));
    }

    /**
     * La misma regla en la pantalla: el lector restringido abre SU gasto empresarial y
     * ve los 60 aplicados, pero no el total de 100, ni la referencia, ni el comprobante.
     */
    public function test_la_pantalla_muestra_el_subtotal_propio_y_esconde_la_cabecera_mixta(): void
    {
        $duenio = $this->operadorTotal();
        $empresa = $this->gasto($duenio, 'empresarial', '60.00');
        $personal = $this->gasto($duenio, 'personal', '40.00');

        $pago = app(RegistrarPago::class)->registrar($duenio, $this->datosPago($duenio, '100.00'), [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $restringido = $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
        ]);

        $respuesta = $this->actingAs($restringido)->get(route('gastos.create', ['guardado' => $empresa->id]));

        $respuesta->assertOk();
        $respuesta->assertSee('Aplicado a este gasto: USD 60.00');
        $respuesta->assertDontSee('USD 100.00');
        $respuesta->assertDontSee('Pago #'.$pago->id);
        $respuesta->assertDontSee('TRF-1');
        $respuesta->assertDontSee($personal->concepto);

        // Y el operador completo sí ve la cabecera entera.
        $completa = $this->actingAs($duenio)->get(route('gastos.create', ['guardado' => $empresa->id]));
        $completa->assertSee('Pago #'.$pago->id);
        $completa->assertSee('TRF-1');
    }

    public function test_una_cuota_inexistente_no_se_puede_pagar(): void
    {
        $usuario = $this->operadorTotal();
        $cuota = $this->gasto($usuario, 'empresarial', '50.00')->cuotas->first();

        $this->expectException(ValidationException::class);

        try {
            app(RegistrarPago::class)->registrar($usuario, $this->datosPago($usuario, '100.00'), [
                ['cuota_id' => $cuota->id, 'importe' => '50.00'],
                ['cuota_id' => Cuota::max('id') + 999, 'importe' => '50.00'],
            ]);
        } finally {
            $this->assertSame(0, Pago::count());
        }
    }
}
