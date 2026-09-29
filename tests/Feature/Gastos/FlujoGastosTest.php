<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Fuente;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\InformeGastos;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use App\Services\Gastos\VincularCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 1 completa: listado, ficha, pagos posteriores, adjuntar después, ajustes,
 * vínculo con Compras e informes.
 *
 * Las garantías que estas pruebas defienden, más allá de que las pantallas abran:
 *
 *  1. El ámbito recorta la CONSULTA, no la vista. Un lector de solo empresa no
 *     puede deducir lo personal de un total, de un contador de pestaña ni de una
 *     exportación.
 *  2. Un ajuste no es un pago. Saldar con nota de crédito informa «Saldada por
 *     ajuste», no «Pagada», y no aparece como dinero salido en el informe.
 *  3. Un documento de Compras origina UNA deuda. Colgarlo de un gasto que ya
 *     existía es respaldo, no una obligación nueva.
 *  4. Reversión y adjuntar-después no rompen el saldo ni el historial.
 */
class FlujoGastosTest extends TestCase
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

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function operador(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::GastosAdministrar, PermisoSistema::GastosExportar,
        ]));
    }

    private function soloEmpresa(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
            PermisoSistema::GastosPagosRegistrar->value,
            PermisoSistema::GastosExportar->value,
        ]);
    }

    private function gasto(User $u, array $extra = [], array $cuotas = [['100.00', null]]): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Concepto de prueba',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '100.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ], $extra));

        foreach ($cuotas as $i => [$importe, $vence]) {
            $gasto->cuotas()->create(['numero' => $i + 1, 'importe' => $importe, 'vence' => $vence]);
        }

        return $gasto->fresh();
    }

    // ──────────────────────────── Pantalla principal ────────────────────────────

    public function test_el_listado_separa_pendientes_vencidos_proximos_y_pagados(): void
    {
        $u = $this->operador();
        $this->travelTo('2026-09-15 09:00:00');

        // Conceptos con nombres que NO aparecen en la interfaz: «Vencido» a secas
        // también está en la pestaña «Vencidos» y en la columna «De ello vencido», así
        // que una aserción sobre esa palabra no mediría la fila sino el marco.
        $this->gasto($u, ['concepto' => 'ConceptoAlfa'], [['100.00', '2026-09-01']]);
        $this->gasto($u, ['concepto' => 'ConceptoBravo'], [['50.00', '2026-09-15']]);
        $this->gasto($u, ['concepto' => 'ConceptoCharlie'], [['70.00', '2026-09-30']]);
        $pagado = $this->gasto($u, ['concepto' => 'ConceptoDelta'], [['40.00', '2026-09-05']]);
        $this->gasto($u, ['concepto' => 'ConceptoEcho', 'importe' => null], []);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '40.00', 'fecha' => '2026-09-06',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $pagado->cuotas->first()->id, 'importe' => '40.00']]);

        $ver = fn (string $pestana) => $this->actingAs($u)->get(route('gastos.index', ['pestana' => $pestana]));

        $ver('pendientes')->assertSee('ConceptoAlfa')->assertSee('ConceptoBravo')
            ->assertSee('ConceptoCharlie')->assertDontSee('ConceptoDelta');
        $ver('vencidos')->assertSee('ConceptoAlfa')->assertDontSee('ConceptoCharlie');
        $ver('hoy')->assertSee('ConceptoBravo')->assertDontSee('ConceptoAlfa');
        $ver('proximos')->assertSee('ConceptoCharlie')->assertDontSee('ConceptoAlfa');
        $ver('pagados')->assertSee('ConceptoDelta')->assertDontSee('ConceptoAlfa');
        // Esperando monto NO entra en ninguna pestaña de saldo: no tiene uno.
        $ver('por_completar')->assertSee('ConceptoEcho');
        $ver('pendientes')->assertDontSee('ConceptoEcho');
    }

    public function test_los_totales_van_separados_por_ambito_y_el_vencido_es_subconjunto(): void
    {
        $u = $this->operador();
        $this->travelTo('2026-09-15 09:00:00');

        $this->gasto($u, ['concepto' => 'Empresa vencida'], [['100.00', '2026-09-01']]);
        $this->gasto($u, ['concepto' => 'Empresa futura'], [['60.00', '2026-10-01']]);
        $this->gasto($u, ['ambito' => 'personal', 'concepto' => 'Personal'], [['25.00', '2026-09-20']]);

        $totales = app(ConsultaGastos::class)
            ->totales($u, ['pestana' => 'pendientes'], '2026-09-15')
            ->keyBy('ambito');

        $this->assertSame(16000, (int) $totales['empresarial']->pendiente);
        $this->assertSame(10000, (int) $totales['empresarial']->vencido, 'Solo la de septiembre está vencida.');
        $this->assertSame(2500, (int) $totales['personal']->pendiente);
        $this->assertSame(0, (int) $totales['personal']->vencido);
    }

    public function test_quien_solo_ve_empresa_no_recibe_filas_ni_totales_personales(): void
    {
        $duenio = $this->operador();
        $this->gasto($duenio, ['ambito' => 'personal', 'concepto' => 'Universidad de Andrea']);
        $this->gasto($duenio, ['concepto' => 'Internet de la oficina']);

        $restringido = $this->soloEmpresa();
        $respuesta = $this->actingAs($restringido)->get(route('gastos.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('Internet de la oficina');
        $respuesta->assertDontSee('Universidad de Andrea');

        // Y el total tampoco la delata.
        $totales = app(ConsultaGastos::class)->totales($restringido, [], now()->toDateString());
        $this->assertCount(1, $totales);
        $this->assertSame('empresarial', $totales->first()->ambito);
    }

    public function test_la_busqueda_no_deja_que_un_comodin_del_usuario_devuelva_de_mas(): void
    {
        $u = $this->operador();
        $this->gasto($u, ['concepto' => 'Internet']);
        $this->gasto($u, ['concepto' => 'Agua']);

        // Un «%» escrito por el usuario debe buscarse como texto, no como comodín.
        $this->actingAs($u)->get(route('gastos.index', ['q' => '%']))
            ->assertOk()->assertDontSee('Internet')->assertDontSee('Agua');
    }

    /**
     * Una fila no puede decir «Vencida» y «Sin fecha» a la vez. `proxima` es la fecha
     * más próxima a reclamar, esté vencida o no; si solo se registrara para las
     * futuras, todo gasto vencido aparecería sin fecha al lado de su propia insignia
     * de vencimiento.
     */
    public function test_un_gasto_vencido_muestra_su_fecha_y_no_sin_fecha(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, [], [['100.00', '2026-01-10']]);

        $resumen = app(SaldosGastos::class)->resumen($gasto, '2026-03-01');

        $this->assertSame('vencida', $resumen['vencimiento']);
        $this->assertSame('2026-01-10', $resumen['proxima'], 'La fecha vencida sigue siendo la fecha a reclamar.');

        // Y coincide con lo que calcula el SQL del listado.
        $fila = app(ConsultaGastos::class)->base($u, ['pestana' => 'vencidos'], '2026-03-01')->first();
        $this->assertSame('2026-01-10', substr((string) $fila->proxima, 0, 10));
    }

    // ─────────────────────────────── Ficha ───────────────────────────────

    public function test_la_ficha_muestra_cuotas_pagos_saldo_documentos_e_historial(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Seguro anual'], [['40.00', '2026-01-10'], ['60.00', '2030-01-10']]);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '40.00', 'fecha' => '2026-01-11',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => 'TRF-77', 'sin_comprobante' => 'pendiente del banco',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '40.00']]);

        $r = $this->actingAs($u)->get(route('gastos.show', $gasto));

        $r->assertOk();
        $r->assertSee('Cuotas y vencimientos');
        $r->assertSee('Pagos aplicados');
        $r->assertSee('TRF-77');
        $r->assertSee('Historial');
        $r->assertSee('Pago registrado');
        // El motivo del comprobante faltante se dice aparte de «falta comprobante».
        $r->assertSee('Falta comprobante');
        $r->assertSee('pendiente del banco');
    }

    public function test_la_ficha_de_un_gasto_personal_no_se_abre_sin_alcance(): void
    {
        $duenio = $this->operador();
        $gasto = $this->gasto($duenio, ['ambito' => 'personal']);

        $this->actingAs($this->soloEmpresa())->get(route('gastos.show', $gasto))->assertForbidden();
    }

    // ──────────────────── Pagos posteriores y multiobligación ────────────────────

    public function test_un_pago_posterior_cubre_varias_obligaciones_del_mismo_destinatario(): void
    {
        $u = $this->operador();
        $a = $this->gasto($u, ['concepto' => 'Insumos'], [['60.00', '2026-01-05']]);
        $b = $this->gasto($u, ['concepto' => 'Empaques'], [['40.00', '2026-01-05']]);

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(),
            'importe' => '100.00', 'fecha' => '2026-02-01', 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'referencia' => 'TRF-9',
            'aplicar' => [
                $a->cuotas->first()->id => '60.00',
                $b->cuotas->first()->id => '40.00',
            ],
            'comprobantes' => [UploadedFile::fake()->image('voucher.jpg')],
        ])->assertRedirect();

        $pago = Pago::sole();
        $this->assertSame('100.00', $pago->importe);
        $this->assertSame(2, DB::table('gastos_pago_aplicaciones')->where('pago_id', $pago->id)->count());
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($a->cuotas->first()));
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($b->cuotas->first()));
        $this->assertSame(1, DB::table('gastos_adjuntos')->where('pago_id', $pago->id)->count());
    }

    public function test_un_abono_posterior_deja_el_resto_pendiente(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, [], [['100.00', '2026-01-05']]);

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '30.00', 'fecha' => '2026-02-01',
            'metodo' => 'efectivo', 'pagado_por' => $u->id,
            'sin_comprobante' => 'Efectivo sin recibo',
            'aplicar' => [$gasto->cuotas->first()->id => '30.00'],
        ])->assertRedirect();

        $resumen = app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-02-02');
        $this->assertSame(7000, $resumen['pendiente']);
        $this->assertSame('parcial', $resumen['liquidacion'], 'Parcialmente pagado es un eje propio.');
    }

    public function test_el_pago_posterior_rechaza_un_reparto_que_no_suma_el_importe(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-02-01',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'sin recibo',
            'aplicar' => [$gasto->cuotas->first()->id => '30.00'],
        ])->assertSessionHasErrors();

        $this->assertSame(0, Pago::count());
    }

    public function test_registrar_un_pago_sin_comprobante_exige_motivo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-01',
            'metodo' => 'efectivo', 'pagado_por' => $u->id,
            'aplicar' => [$gasto->cuotas->first()->id => '100.00'],
        ])->assertSessionHasErrors('sin_comprobante');

        $this->assertSame(0, Pago::count());
    }

    // ────────────────────── Adjuntar después y reversión ──────────────────────

    public function test_el_comprobante_se_puede_adjuntar_despues_y_limpia_el_motivo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-01',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null,
            'sin_comprobante' => 'Lo descargo del banco mañana',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $this->actingAs($u)->post(route('gastos.pagos.comprobantes', $pago), [
            'comprobantes' => [UploadedFile::fake()->image('banco.png')],
        ])->assertRedirect();

        $pago->refresh();
        $this->assertNull($pago->sin_comprobante, 'Ya no falta: el motivo se retira.');
        $this->assertSame(1, DB::table('gastos_adjuntos')->where('pago_id', $pago->id)->count());
        // Pero el motivo NO se pierde: queda en el historial.
        $evento = DB::table('gastos_eventos')->where('pago_id', $pago->id)->where('accion', 'comprobantes_adjuntados')->first();
        $this->assertStringContainsString('Lo descargo del banco', $evento->datos);
    }

    public function test_el_documento_del_gasto_se_puede_adjuntar_despues(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['documentacion' => 'pendiente']);

        $this->actingAs($u)->post(route('gastos.documentos', $gasto), [
            'documentos' => [UploadedFile::fake()->image('recibo.png')],
        ])->assertRedirect();

        $this->assertSame('adjunto', $gasto->fresh()->documentacion);
        $this->assertSame(1, DB::table('gastos_adjuntos')->where('gasto_id', $gasto->id)->count());
    }

    public function test_adjuntar_despues_respeta_el_tope_de_archivos(): void
    {
        config()->set('gastos.max_archivos', 2);
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->post(route('gastos.documentos', $gasto), [
            'documentos' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')],
        ])->assertRedirect();

        $this->actingAs($u)->post(route('gastos.documentos', $gasto), [
            'documentos' => [UploadedFile::fake()->image('c.png')],
        ])->assertSessionHasErrors('documentos');

        $this->assertSame(2, DB::table('gastos_adjuntos')->where('gasto_id', $gasto->id)->count());
    }

    public function test_revertir_desde_la_pantalla_devuelve_el_saldo_y_exige_motivo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $cuota = $gasto->cuotas->first();

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-01',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cuota->id, 'importe' => '100.00']]);

        $this->actingAs($u)->post(route('gastos.pagos.revertir', $pago), ['motivo' => 'x'])
            ->assertSessionHasErrors('motivo');
        $this->assertNull($pago->fresh()->revertido_at);

        $this->actingAs($u)->post(route('gastos.pagos.revertir', $pago), ['motivo' => 'Se registró con la fecha equivocada'])
            ->assertRedirect();

        $this->assertNotNull($pago->fresh()->revertido_at);
        $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
    }

    // ──────────────────────────── Ajustes de deuda ────────────────────────────

    public function test_una_nota_de_credito_salda_la_deuda_pero_no_la_declara_pagada(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $cuota = $gasto->cuotas->first();

        $this->actingAs($u)->post(route('gastos.ajustes.store', $cuota), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '100.00', 'motivo' => 'Nota de crédito 44 por devolución completa',
        ])->assertRedirect();

        $resumen = app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-02-02');

        $this->assertSame(0, $resumen['pendiente']);
        $this->assertSame(10000, $resumen['credito']);
        $this->assertSame(0, $resumen['pagado'], 'Un ajuste no es dinero que salió.');
        $this->assertSame('saldada_por_ajuste', $resumen['liquidacion'], 'No se vende como «Pagada».');
    }

    public function test_un_credito_mayor_que_el_saldo_se_rechaza_en_vez_de_dejar_negativo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $cuota = $gasto->cuotas->first();

        $this->actingAs($u)->post(route('gastos.ajustes.store', $cuota), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '150.00', 'motivo' => 'Crédito que no cabe en esta deuda',
        ])->assertSessionHasErrors('importe');

        $this->assertSame(0, Ajuste::count());
        $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
    }

    public function test_un_debito_sube_la_deuda_y_se_puede_revertir(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $cuota = $gasto->cuotas->first();

        $ajuste = app(RegistrarAjuste::class)->registrar($u, $cuota, [
            'clave' => (string) Str::uuid(), 'direccion' => 'debito', 'tipo' => 'nota_debito',
            'importe' => '20.00', 'motivo' => 'Nota de débito por intereses',
        ]);

        $this->assertSame(12000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));

        $this->actingAs($u)->post(route('gastos.ajustes.revertir', $ajuste), ['motivo' => 'Se cargó por error'])
            ->assertRedirect();

        $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
        $this->assertNotNull($ajuste->fresh()->revertido_at);
    }

    public function test_un_ajuste_exige_el_permiso_de_administrar(): void
    {
        $duenio = $this->operador();
        $cuota = $this->gasto($duenio)->cuotas->first();

        $this->actingAs($this->soloEmpresa())->post(route('gastos.ajustes.store', $cuota), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '10.00', 'motivo' => 'No debería poder',
        ])->assertForbidden();

        $this->assertSame(0, Ajuste::count());
    }

    // ───────────────────────── Vínculo con Compras ─────────────────────────

    private function documento(array $extra = []): DocumentoRecibido
    {
        return DocumentoRecibido::create(array_replace([
            'identidad' => (string) Str::uuid(), 'origen_email' => 'proveedor@ejemplo.test',
            'asunto' => 'CCF', 'remitente' => 'Proveedor A', 'fecha_correo' => now(),
            'fecha_dte' => '2026-02-01', 'tipo_documento' => '03', 'numero_control' => 'DTE-03-0001',
            'codigo_generacion' => strtoupper((string) Str::uuid()), 'emisor_nombre' => 'Proveedor A',
            'total' => 100.00, 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
        ], $extra));
    }

    public function test_un_documento_de_compras_prellena_el_alta_sin_crear_deuda(): void
    {
        $u = $this->operador();
        $u->givePermissionTo('documentos-recibidos.ver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $documento = $this->documento();

        $r = $this->actingAs($u->fresh())->get(route('gastos.create', ['documento' => $documento->id]));

        $r->assertOk();
        $r->assertSee('Proveedor A');
        $r->assertSee('Datos tomados del documento de Compras');
        // Mirar la pantalla no crea nada.
        $this->assertSame(0, Gasto::count());
    }

    public function test_vincular_a_un_gasto_existente_no_crea_otra_deuda(): void
    {
        $u = $this->operador();
        $u->givePermissionTo('documentos-recibidos.ver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gasto = $this->gasto($u, ['beneficiario' => 'Proveedor A']);
        $documento = $this->documento();

        $this->actingAs($u->fresh())->post(route('gastos.compras.vincular', $documento), [
            'gasto_id' => $gasto->id, 'papel' => 'respaldo',
        ])->assertRedirect(route('gastos.show', $gasto));

        $this->assertSame(1, Gasto::count(), 'Sigue habiendo UNA sola obligación.');
        $fuente = Fuente::sole();
        $this->assertSame('respaldo', $fuente->papel);
        $this->assertNull($fuente->deuda_unica, 'Un respaldo no ocupa el candado de deuda única.');
        $this->assertSame('DTE-03-0001', $fuente->snapshot['numero_control']);
    }

    public function test_un_documento_no_puede_originar_dos_deudas(): void
    {
        $u = $this->operador();
        $documento = $this->documento();
        $servicio = app(VincularCompra::class);

        $servicio->vincular($u, $this->gasto($u, ['concepto' => 'Primera']), $documento, 'deuda');

        $this->expectException(ValidationException::class);

        try {
            $servicio->vincular($u, $this->gasto($u, ['concepto' => 'Segunda']), $documento, 'deuda');
        } finally {
            $this->assertSame(1, Fuente::where('papel', 'deuda')->count());
        }
    }

    public function test_una_retencion_y_una_nota_de_credito_nunca_se_convierten_en_deuda(): void
    {
        $servicio = app(VincularCompra::class);

        $this->assertStringContainsString('retención', $servicio->motivoNoGeneraDeuda($this->documento(['tipo_documento' => '07'])));
        $this->assertStringContainsString('nota de crédito', $servicio->motivoNoGeneraDeuda($this->documento(['tipo_documento' => '05'])));
        $this->assertNull($servicio->motivoNoGeneraDeuda($this->documento(['tipo_documento' => '03'])));
    }

    // ─────────────────────────────── Informes ───────────────────────────────

    public function test_el_informe_separa_pendientes_a_corte_de_pagos_del_periodo(): void
    {
        $u = $this->operador();
        $abierto = $this->gasto($u, ['concepto' => 'Sigue abierto'], [['100.00', '2026-01-10']]);
        $cerrado = $this->gasto($u, ['concepto' => 'Ya se pago'], [['80.00', '2026-01-10']]);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '80.00', 'fecha' => '2026-02-10',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cerrado->cuotas->first()->id, 'importe' => '80.00']]);

        $informes = app(InformeGastos::class);

        $pendientes = $informes->pendientes($u, '2026-03-01');
        $this->assertCount(1, $pendientes);
        $this->assertSame('Sigue abierto', $pendientes->first()->concepto);

        $pagos = $informes->pagos($u, '2026-02-01', '2026-02-28');
        $this->assertCount(1, $pagos);
        $this->assertSame('Ya se pago', $pagos->first()->concepto);

        // Fuera del período no aparece.
        $this->assertCount(0, $informes->pagos($u, '2026-03-01', '2026-03-31'));
    }

    public function test_el_informe_de_pagos_reparte_por_aplicacion_y_no_repite_el_total(): void
    {
        $u = $this->operador();
        $a = $this->gasto($u, ['concepto' => 'Insumos', 'categoria' => 'Insumos'], [['60.00', null]]);
        $b = $this->gasto($u, ['concepto' => 'Servicios', 'categoria' => 'Servicios'], [['40.00', null]]);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-10',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [
            ['cuota_id' => $a->cuotas->first()->id, 'importe' => '60.00'],
            ['cuota_id' => $b->cuotas->first()->id, 'importe' => '40.00'],
        ]);

        $filas = app(InformeGastos::class)->pagos($u, '2026-02-01', '2026-02-28');

        $this->assertCount(2, $filas, 'Una fila por aplicación.');
        $this->assertSame(1, $filas->pluck('pago_id')->unique()->count(), 'Pero una sola salida de dinero.');
        $this->assertEqualsCanonicalizing([60.0, 40.0], $filas->pluck('aplicado')->map(fn ($v) => (float) $v)->all());
    }

    /**
     * Un pago revertido DENTRO del período no es dinero que salió: desaparece del
     * informe de ese período. Revertido DESPUÉS es otro caso y tiene prueba propia
     * ({@see test_el_informe_de_pagos_distingue_la_reversion_dentro_y_fuera_del_periodo}):
     * sigue apareciendo, marcado, para que el mismo mes no dé cifras distintas según
     * el día en que se consulte.
     */
    public function test_un_pago_revertido_dentro_del_periodo_no_cuenta_como_dinero_salido(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-10',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $informes = app(InformeGastos::class);
        $this->assertCount(1, $informes->pagos($u, '2026-02-01', '2026-02-28'));

        $this->travelTo('2026-02-20 10:00:00');
        app(RegistrarPago::class)->revertir($u, $pago, 'Nunca salió la transferencia');

        $this->assertCount(0, $informes->pagos($u, '2026-02-01', '2026-02-28'));
    }

    /**
     * Los totales de la cabecera tienen que coincidir con la suma de las filas. Suena
     * obvio; no lo es: `pendiente` sale del SQL en centavos y el driver lo devuelve
     * como CADENA, así que convertirlo «por si acaso» lo multiplicaba por cien y la
     * pantalla mostraba 465735.00 donde había 4657.35.
     */
    public function test_los_totales_del_informe_coinciden_con_la_suma_de_sus_filas(): void
    {
        $u = $this->operador();
        $this->gasto($u, ['concepto' => 'Uno'], [['300.00', '2026-01-10']]);
        $this->gasto($u, ['concepto' => 'Dos'], [['500.00', '2026-01-10']]);
        $this->gasto($u, ['ambito' => 'personal', 'concepto' => 'Tres'], [['60.00', '2026-01-10']]);

        $informes = app(InformeGastos::class);
        $filas = $informes->pendientes($u, '2026-02-01');
        $totales = $informes->totalesPorAmbito($filas, 'pendiente', enCentavos: true);

        $this->assertSame(80000, $totales['USD']['empresarial']);
        $this->assertSame(6000, $totales['USD']['personal']);
        $this->assertSame(86000, $totales['USD']['total']);
        $this->assertSame('860.00', Dinero::decimal($totales['USD']['total']));

        // Y los pagos, que vienen de una columna DECIMAL, tampoco se desmadran.
        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '300.00', 'fecha' => '2026-01-20',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => Gasto::where('concepto', 'Uno')->first()->cuotas->first()->id, 'importe' => '300.00']]);

        $totalesPagos = $informes->totalesPorAmbito(
            $informes->pagos($u, '2026-01-01', '2026-01-31'), 'aplicado', enCentavos: false
        );
        $this->assertSame(30000, $totalesPagos['USD']['total']);
    }

    // ───────────── Informe histórico: la foto no se reescribe hacia atrás ─────────────

    /**
     * «Pendiente al 31 de enero» no puede cambiar porque en febrero alguien pague: el
     * 31 de enero ese dinero no había salido. Antes la subconsulta de saldos no
     * filtraba por fecha, así que el informe «a una fecha» mostraba en realidad el
     * saldo de hoy con la etiqueta de otro día.
     */
    public function test_un_pago_posterior_al_corte_no_altera_el_saldo_de_esa_fecha(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Deuda de enero'], [['100.00', '2026-01-10']]);

        // El pago ocurre en FEBRERO.
        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-02-15',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $informes = app(InformeGastos::class);

        // Al 31 de enero la deuda seguía entera.
        $enero = $informes->pendientes($u, '2026-01-31');
        $this->assertCount(1, $enero);
        $this->assertSame(10000, (int) $enero->first()->pendiente);
        $this->assertSame(10000, (int) $enero->first()->vencido);

        // Al 28 de febrero ya no hay saldo.
        $this->assertCount(0, $informes->pendientes($u, '2026-02-28'));
    }

    /**
     * Y al revés: un pago de enero revertido en marzo SÍ contaba el 31 de enero.
     * Revertirlo después no puede reescribir lo que el informe decía entonces.
     */
    public function test_una_reversion_posterior_al_corte_no_reescribe_el_saldo_de_esa_fecha(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Pagada en enero'], [['100.00', '2026-01-10']]);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-01-20',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        // La reversión ocurre en MARZO.
        $this->travelTo('2026-03-15 10:00:00');
        app(RegistrarPago::class)->revertir($u, $pago, 'Se registró con el mes equivocado');

        $informes = app(InformeGastos::class);

        // Al 31 de enero la deuda estaba saldada y así debe seguir viéndose.
        $this->assertCount(0, $informes->pendientes($u, '2026-01-31'),
            'La reversión de marzo no puede resucitar una deuda que en enero estaba pagada.');

        // Hoy, en cambio, la deuda está viva otra vez.
        $marzo = $informes->pendientes($u, '2026-03-31');
        $this->assertCount(1, $marzo);
        $this->assertSame(10000, (int) $marzo->first()->pendiente);
    }

    public function test_un_ajuste_posterior_al_corte_tampoco_cuenta_para_esa_fecha(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, [], [['100.00', '2026-01-10']]);

        $this->travelTo('2026-02-15 10:00:00');
        app(RegistrarAjuste::class)->registrar($u, $gasto->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '40.00', 'motivo' => 'Nota de crédito de febrero',
        ]);

        $informes = app(InformeGastos::class);
        $this->assertSame(10000, (int) $informes->pendientes($u, '2026-01-31')->first()->pendiente);
        $this->assertSame(6000, (int) $informes->pendientes($u, '2026-02-28')->first()->pendiente);
    }

    /**
     * En el informe de PAGOS pasa lo simétrico: uno revertido dentro del período no
     * aparece, y uno revertido después sí, pero marcado para que nadie lo lea como
     * vigente.
     */
    public function test_el_informe_de_pagos_distingue_la_reversion_dentro_y_fuera_del_periodo(): void
    {
        $u = $this->operador();
        $dentro = $this->gasto($u, ['concepto' => 'Revertido en el mes'], [['50.00', null]]);
        $fuera = $this->gasto($u, ['concepto' => 'Revertido despues'], [['70.00', null]]);

        $pagoDentro = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-01-05',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $dentro->cuotas->first()->id, 'importe' => '50.00']]);

        $pagoFuera = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '70.00', 'fecha' => '2026-01-06',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $fuera->cuotas->first()->id, 'importe' => '70.00']]);

        $this->travelTo('2026-01-20 10:00:00');
        app(RegistrarPago::class)->revertir($u, $pagoDentro, 'Corregido dentro del mismo mes');

        $this->travelTo('2026-03-10 10:00:00');
        app(RegistrarPago::class)->revertir($u, $pagoFuera, 'Corregido dos meses despues');

        $filas = app(InformeGastos::class)->pagos($u, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $filas, 'El revertido dentro del período no está.');
        $this->assertSame('Revertido despues', $filas->first()->concepto);
        $this->assertNotNull($filas->first()->revertido_despues, 'Y el que sí está viene marcado.');
    }

    // ─────────── Notas de crédito de Compras: sin doble descuento ───────────

    public function test_una_nota_de_credito_de_compras_se_aplica_como_ajuste_y_no_como_deuda(): void
    {
        $u = $this->operador();
        $nc = $this->documento(['tipo_documento' => '05', 'total' => 60.00, 'numero_control' => 'DTE-05-0009']);
        $compras = app(VincularCompra::class);

        // Como deuda: bloqueada.
        $this->assertStringContainsString('nota de crédito', $compras->motivoNoGeneraDeuda($nc));
        $this->assertTrue($compras->esNotaDeCredito($nc));

        // Como crédito: disponible entera.
        $this->assertSame(['total' => 6000, 'aplicado' => 0, 'disponible' => 6000], $compras->creditoDelDocumento($nc));

        $gasto = $this->gasto($u, [], [['100.00', null]]);
        app(RegistrarAjuste::class)->registrar($u, $gasto->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '60.00', 'motivo' => 'Nota de crédito 9 por devolución',
            'documento_recibido_id' => $nc->id,
        ]);

        $this->assertSame(4000, app(SaldosGastos::class)->pendienteCuota($gasto->cuotas->first()->fresh()));
        $this->assertSame(0, $compras->creditoDelDocumento($nc)['disponible']);
        // Y sigue sin existir deuda nacida de esa NC.
        $this->assertSame(0, Fuente::where('papel', 'deuda')->count());
    }

    public function test_la_misma_nota_de_credito_no_se_puede_descontar_dos_veces(): void
    {
        $u = $this->operador();
        $nc = $this->documento(['tipo_documento' => '05', 'total' => 60.00]);

        $a = $this->gasto($u, ['concepto' => 'Uno'], [['100.00', null]]);
        $b = $this->gasto($u, ['concepto' => 'Dos'], [['100.00', null]]);

        app(RegistrarAjuste::class)->registrar($u, $a->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '60.00', 'motivo' => 'Aplicada entera al primero',
            'documento_recibido_id' => $nc->id,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(RegistrarAjuste::class)->registrar($u, $b->cuotas->first(), [
                'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
                'importe' => '60.00', 'motivo' => 'Intento de aplicarla otra vez',
                'documento_recibido_id' => $nc->id,
            ]);
        } finally {
            $this->assertSame(1, Ajuste::count());
            $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($b->cuotas->first()->fresh()));
        }
    }

    public function test_una_nota_de_credito_se_puede_repartir_y_el_sobrante_queda_identificado(): void
    {
        $u = $this->operador();
        $nc = $this->documento(['tipo_documento' => '05', 'total' => 100.00]);
        $compras = app(VincularCompra::class);

        $a = $this->gasto($u, ['concepto' => 'Uno'], [['40.00', null]]);
        app(RegistrarAjuste::class)->registrar($u, $a->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '40.00', 'motivo' => 'Parte de la nota de crédito',
            'documento_recibido_id' => $nc->id,
        ]);

        // Sobran 60 y quedan IDENTIFICADOS como pendientes de aplicar.
        $credito = $compras->creditoDelDocumento($nc);
        $this->assertSame(4000, $credito['aplicado']);
        $this->assertSame(6000, $credito['disponible']);
        $this->assertTrue($compras->notasDeCreditoConRemanente()->contains(fn ($f) => $f->documento->id === $nc->id));

        // El resto se aplica a otra deuda, y entonces ya no sobra nada.
        $b = $this->gasto($u, ['concepto' => 'Dos'], [['80.00', null]]);
        app(RegistrarAjuste::class)->registrar($u, $b->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '60.00', 'motivo' => 'Resto de la nota de crédito',
            'documento_recibido_id' => $nc->id,
        ]);

        $this->assertSame(0, $compras->creditoDelDocumento($nc)['disponible']);
        $this->assertFalse($compras->notasDeCreditoConRemanente()->contains(fn ($f) => $f->documento->id === $nc->id));
    }

    public function test_revertir_un_ajuste_devuelve_su_parte_al_remanente_de_la_nota(): void
    {
        $u = $this->operador();
        $nc = $this->documento(['tipo_documento' => '05', 'total' => 50.00]);
        $gasto = $this->gasto($u, [], [['100.00', null]]);

        $ajuste = app(RegistrarAjuste::class)->registrar($u, $gasto->cuotas->first(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '50.00', 'motivo' => 'Aplicada entera', 'documento_recibido_id' => $nc->id,
        ]);

        $compras = app(VincularCompra::class);
        $this->assertSame(0, $compras->creditoDelDocumento($nc)['disponible']);

        app(RegistrarAjuste::class)->revertir($u, $ajuste, 'Se aplicó a la deuda equivocada');

        $this->assertSame(5000, $compras->creditoDelDocumento($nc)['disponible'],
            'Revertir libera el crédito para volver a aplicarlo donde corresponda.');
    }

    // ───────────── Por completar: poner el monto sin crear otro registro ─────────────

    public function test_completar_el_monto_deja_la_obligacion_lista_para_pagar_sin_crear_otra(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Honorarios sin recibo', 'importe' => null], []);

        $this->assertSame(1, Gasto::count());

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '250.00', 'vence' => '2026-03-10']],
        ])->assertRedirect(route('gastos.show', $gasto));

        $this->assertSame(1, Gasto::count(), 'Se completó el registro que ya existía; no se creó otro.');

        $gasto->refresh();
        $this->assertSame('250.00', $gasto->importe);
        $this->assertSame(1, $gasto->cuotas()->count());
        $this->assertSame('2026-03-10', $gasto->cuotas->first()->vence->toDateString());

        // Y ya se puede pagar.
        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '250.00', 'fecha' => '2026-03-11',
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'sin recibo',
            'aplicar' => [$gasto->cuotas->first()->id => '250.00'],
        ])->assertRedirect();

        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($gasto->cuotas->first()->fresh()));
        $this->assertDatabaseHas('gastos_eventos', ['gasto_id' => $gasto->id, 'accion' => 'monto_completado']);
    }

    /**
     * La ficha de una obligación que todavía espera su monto tiene que ABRIR: no tiene
     * cuotas, y el formulario de ajuste —que necesita una— no puede reventar la
     * pantalla justo donde está la acción para completarla.
     */
    public function test_la_ficha_de_un_gasto_sin_monto_abre_y_ofrece_completarlo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Esperando el recibo', 'importe' => null], []);

        $r = $this->actingAs($u)->get(route('gastos.show', $gasto));

        $r->assertOk();
        $r->assertSee('Esperando el monto');
        $r->assertSee('Completar y dejar lista para pagar');
    }

    public function test_completar_admite_varias_cuotas_y_suma_el_importe(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['importe' => null], []);

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [
                ['importe' => '100.00', 'vence' => '2026-03-10'],
                ['importe' => '150.00', 'vence' => '2026-04-10'],
            ],
        ])->assertRedirect();

        $gasto->refresh();
        $this->assertSame('250.00', $gasto->importe);
        $this->assertSame(2, $gasto->cuotas()->count());
    }

    public function test_completar_dos_veces_no_duplica_cuotas(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['importe' => null], []);

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '250.00', 'vence' => null]],
        ])->assertRedirect();

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '999.00', 'vence' => null]],
        ])->assertSessionHasErrors('importe');

        $gasto->refresh();
        $this->assertSame('250.00', $gasto->importe);
        $this->assertSame(1, $gasto->cuotas()->count());
        $this->assertSame(1, Gasto::count());
    }

    public function test_completar_no_sirve_para_cambiar_un_importe_que_ya_existia(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['importe' => '100.00'], [['100.00', null]]);

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '999.00', 'vence' => null]],
        ])->assertSessionHasErrors('importe');

        $this->assertSame('100.00', $gasto->fresh()->importe);
    }

    public function test_la_exportacion_neutraliza_formulas_y_respeta_el_ambito(): void
    {
        $duenio = $this->operador();
        // Un concepto que empieza por «=» se abriría como fórmula en Excel.
        $this->gasto($duenio, ['concepto' => '=SUMA(A1:A9) proveedor raro']);
        $this->gasto($duenio, ['ambito' => 'personal', 'concepto' => 'Universidad de Andrea']);

        $csv = $this->actingAs($this->soloEmpresa())
            ->get(route('gastos.informes.exportar', ['informe' => 'pendientes']));

        $csv->assertOk();
        $contenido = $csv->streamedContent();

        $this->assertStringContainsString("'=SUMA(A1:A9)", $contenido, 'La fórmula queda neutralizada.');
        $this->assertStringNotContainsString('Universidad de Andrea', $contenido, 'Una exportación no saca lo que la pantalla oculta.');
    }

    public function test_exportar_exige_su_propio_permiso(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer->value, PermisoSistema::GastosRegistrar->value]);

        $this->actingAs($u)->get(route('gastos.informes.exportar', ['informe' => 'pendientes']))->assertForbidden();
        // Ver el informe en pantalla sí puede.
        $this->actingAs($u)->get(route('gastos.informes'))->assertOk();
    }

    // ───────────────────── Coherencia del saldo con ajustes ─────────────────────

    public function test_el_saldo_combina_pago_credito_y_debito_en_el_orden_correcto(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, [], [['100.00', '2026-01-10']]);
        $cuota = $gasto->cuotas->first();
        $saldos = app(SaldosGastos::class);

        // Factura 100, pago 30, nota de crédito 20 -> pendiente 50 (el ejemplo del diseño).
        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '30.00', 'fecha' => '2026-02-01',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cuota->id, 'importe' => '30.00']]);

        app(RegistrarAjuste::class)->registrar($u, $cuota->fresh(), [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
            'importe' => '20.00', 'motivo' => 'Nota de crédito por faltante',
        ]);

        $this->assertSame(5000, $saldos->pendienteCuota($cuota->fresh()));

        // Pagar los 50 restantes deja saldo cero, con 80 de dinero pagado y 20 de crédito.
        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-02-05',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cuota->id, 'importe' => '50.00']]);

        $resumen = $saldos->resumen($gasto->fresh(), '2026-02-06');
        $this->assertSame(0, $resumen['pendiente']);
        $this->assertSame(8000, $resumen['pagado'], 'Dinero pagado: 80. El crédito no se cuenta como pago.');
        $this->assertSame(2000, $resumen['credito']);
        $this->assertSame('saldada_mixta', $resumen['liquidacion']);
    }

    public function test_el_dinero_no_pasa_por_coma_flotante_ni_en_el_listado(): void
    {
        $u = $this->operador();
        // Importes que en float darían 0.1 + 0.2 = 0.30000000000000004.
        foreach (['0.10', '0.20', '0.30'] as $i => $importe) {
            $this->gasto($u, ['concepto' => 'Centavo '.$i], [[$importe, '2026-01-10']]);
        }

        $totales = app(ConsultaGastos::class)
            ->totales($u, [], '2026-02-01')->keyBy('ambito');

        $this->assertSame(60, (int) $totales['empresarial']->pendiente);
        $this->assertSame('0.60', Dinero::decimal((int) $totales['empresarial']->pendiente));
    }
}
