<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Aviso;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Gastos\PreferenciaAvisos;
use App\Models\User;
use App\Services\Gastos\AnularGasto;
use App\Services\Gastos\Avisos\ArmarAvisos;
use App\Services\Gastos\Avisos\EnviarResumenes;
use App\Services\Gastos\CompletarMonto;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\InformeGastos;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarEmpleado;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Anular un gasto.
 *
 * Lo que estas pruebas defienden:
 *
 *  1. ANULAR NO BORRA. El gasto queda con quién, cuándo y por qué, y sigue abriendo
 *     su ficha. Lo que cambia es que deja de ser deuda.
 *  2. NO SE ANULA DINERO DECLARADO. Con un pago vigente se frena y se nombra cuál hay
 *     que revertir primero. Revertir y anular son operaciones distintas.
 *  3. DEJA DE CONTAR EN TODO LO DE HOY: saldo, listado, panel, avisos, resúmenes.
 *  4. EL PASADO NO SE REESCRIBE. Un corte anterior a la anulación lo sigue mostrando.
 *  5. LO DE PLANILLA SE ANULA EN PLANILLA, no desde Gastos.
 */
class AnularGastoTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_11_10_120000_agregar_anulacion_a_gastos.php';

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
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function administrador(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::GastosAdministrar, PermisoSistema::GastosExportar,
        ]));
    }

    private function gasto(User $u, array $extra = [], array $cuotas = [['100.00', '2026-09-10']]): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor de prueba', 'concepto' => 'ConceptoAnulable',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '100.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ], $extra));

        foreach ($cuotas as $i => [$importe, $vence]) {
            $gasto->cuotas()->create(['numero' => $i + 1, 'importe' => $importe, 'vence' => $vence]);
        }

        return $gasto->fresh();
    }

    private function pagar(User $u, Gasto $gasto, string $importe, string $fecha = '2026-09-05'): Pago
    {
        return app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => $importe, 'fecha' => $fecha,
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => $importe]]);
    }

    private function anular(User $u, Gasto $gasto, string $motivo = 'Se cargó dos veces por error.')
    {
        return $this->actingAs($u)->post(route('gastos.anular', $gasto), ['motivo' => $motivo]);
    }

    // ─────────────────────────── Anular sin pagos ───────────────────────────

    public function test_anular_sin_pagos_lo_marca_sin_borrarlo(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        $u = $this->administrador();
        $gasto = $this->gasto($u);

        $this->anular($u, $gasto)
            ->assertRedirect(route('gastos.show', $gasto))
            ->assertSessionHas('gastos.aviso');

        $gasto->refresh();
        $this->assertTrue($gasto->anulado());
        $this->assertSame($u->id, $gasto->anulado_por);
        $this->assertSame('Se cargó dos veces por error.', $gasto->motivo_anulacion);
        $this->assertSame('2026-09-15 10:00:00', $gasto->anulado_at->format('Y-m-d H:i:s'));

        // No se borró nada: el gasto y su cuota siguen ahí.
        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, DB::table('gastos_cuotas')->count());

        $resumen = app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-09-15');
        $this->assertSame(0, $resumen['pendiente']);
        $this->assertSame(0, $resumen['vencido']);
        $this->assertSame('anulada', $resumen['liquidacion']);
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($gasto->cuotas->first()));
    }

    public function test_anular_queda_en_las_bitacoras_sin_importes(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u);

        $this->anular($u, $gasto, 'Lo registró otra persona antes.');

        $evento = DB::table('gastos_eventos')->where('accion', 'gasto_anulado')->sole();
        $this->assertSame($gasto->id, (int) $evento->gasto_id);
        $this->assertSame($u->id, (int) $evento->usuario_id);
        $this->assertEquals(['motivo' => 'Lo registró otra persona antes.'], json_decode($evento->datos, true));

        $actividad = Activity::where('log_name', 'gastos')->sole();
        $this->assertSame('anuló el gasto', $actividad->description);
        $this->assertSame($gasto->id, (int) $actividad->subject_id);
        $this->assertSame($u->id, (int) $actividad->causer_id);
        $this->assertEquals(['motivo' => 'Lo registró otra persona antes.'], $actividad->properties->toArray());
        $this->assertStringNotContainsString('100', json_encode($actividad->properties->toArray()));
    }

    public function test_anular_dos_veces_no_duplica_ni_cambia_el_primer_registro(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u);

        $this->anular($u, $gasto, 'Primer motivo de anulación.');
        $this->anular($u, $gasto, 'Segundo motivo, no debería quedar.');

        $this->assertSame('Primer motivo de anulación.', $gasto->fresh()->motivo_anulacion);
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'gasto_anulado')->count());
    }

    // ─────────────────────────── Pagos vigentes ───────────────────────────

    public function test_con_un_pago_vigente_no_se_anula_y_dice_cual_revertir(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        $u = $this->administrador();
        $gasto = $this->gasto($u);
        $pago = $this->pagar($u, $gasto, '40.00');

        $this->anular($u, $gasto)->assertSessionHasErrors('anular');

        $mensaje = session('errors')->first('anular');
        $this->assertStringContainsString('Pago #'.$pago->id, $mensaje);
        $this->assertStringContainsString('05/09/2026', $mensaje);
        $this->assertStringContainsString('Revertí', $mensaje);
        $this->assertFalse($gasto->fresh()->anulado());
        $this->assertSame(0, DB::table('gastos_eventos')->where('accion', 'gasto_anulado')->count());
    }

    public function test_con_el_pago_ya_revertido_si_se_anula_y_el_pago_queda_revertido(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        $u = $this->administrador();
        $gasto = $this->gasto($u);
        $pago = $this->pagar($u, $gasto, '40.00');
        app(RegistrarPago::class)->revertir($u, $pago, 'Se registró con el importe equivocado.');

        $this->anular($u, $gasto)->assertSessionHasNoErrors();

        $this->assertTrue($gasto->fresh()->anulado());
        // Revertido no es eliminado: el pago sigue existiendo, marcado.
        $this->assertFalse($pago->fresh()->vigente());
        $this->assertSame(1, Pago::count());

        // En el historial de pagos el pago sigue, revertido, y dice que su gasto se anuló.
        $this->actingAs($u)->get(route('gastos.pagos.index'))
            ->assertOk()->assertSee('Revertido')->assertSee('Gasto anulado');
    }

    // ─────────────────────────── Planilla ───────────────────────────

    public function test_un_gasto_de_planilla_no_se_anula_desde_gastos(): void
    {
        config()->set('planilla.enabled', true);
        config()->set('asistencia.enabled', false);

        $u = $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::GastosVer, PermisoSistema::GastosAdministrar,
            PermisoSistema::GastosPersonales, PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));

        $planilla = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);
        $persona = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Persona de prueba', 'cargo' => 'Ventas']);
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $persona->id, 'salario' => '225.00', 'conceptos' => []],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh()->load('detalles.conceptos'));
        $gasto = $planilla->fresh()->detalles->first()->gasto;

        try {
            app(AnularGasto::class)->anular($u, $gasto, 'Intento de anular un sueldo desde Gastos.');
            $this->fail('Tenía que rechazarse: se anula la planilla, no el gasto.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('planilla', $e->getMessage());
            $this->assertStringContainsString('anulá la planilla', $e->getMessage());
        }

        $this->assertFalse($gasto->fresh()->anulado());
    }

    // ─────────────────────────── Permiso y motivo ───────────────────────────

    public function test_sin_permiso_de_administrar_da_403(): void
    {
        $dueno = $this->administrador();
        $gasto = $this->gasto($dueno);
        $sinPermiso = $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
        ]));

        $this->anular($sinPermiso, $gasto)->assertForbidden();

        $this->assertFalse($gasto->fresh()->anulado());
        // Ni el botón: quien no puede anular no lo ve.
        $this->actingAs($sinPermiso)->get(route('gastos.show', $gasto))->assertOk()->assertDontSee('Anular gasto');
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u);

        $this->anular($u, $gasto, '')->assertSessionHasErrors('motivo');
        $this->anular($u, $gasto, 'abc')->assertSessionHasErrors('motivo');
        $this->anular($u, $gasto, str_repeat('x', 501))->assertSessionHasErrors('motivo');

        $this->assertFalse($gasto->fresh()->anulado());
    }

    // ─────────────────────────── Sale de todo lo de hoy ───────────────────────────

    public function test_sale_del_listado_de_los_totales_y_del_panel(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        $u = $this->administrador();
        $anulado = $this->gasto($u, ['concepto' => 'ConceptoAnulable', 'importe' => '100.00'], [['100.00', '2026-09-10']]);
        $this->gasto($u, ['concepto' => 'ConceptoVigente', 'importe' => '30.00'], [['30.00', '2026-09-10']]);

        $this->anular($u, $anulado);

        $this->actingAs($u)->get(route('gastos.index'))
            ->assertSee('ConceptoVigente')->assertDontSee('ConceptoAnulable');
        $this->actingAs($u)->get(route('gastos.index', ['pestana' => 'pagados']))->assertDontSee('ConceptoAnulable');
        // Sigue a mano, en su propia pestaña y con la insignia.
        $this->actingAs($u)->get(route('gastos.index', ['pestana' => 'anulados']))
            ->assertSee('ConceptoAnulable')->assertSee('Anulado')->assertDontSee('ConceptoVigente');

        $totales = app(ConsultaGastos::class)->totales($u, [], '2026-09-15');
        $this->assertSame(1, $totales->count());
        $this->assertSame(3000, (int) $totales->first()->pendiente);
        $this->assertSame(3000, (int) $totales->first()->vencido);
        $this->assertSame(1, (int) $totales->first()->cantidad);

        $this->assertSame([], app(ConsultaGastos::class)->cuotasPagables($u, 'Proveedor de prueba', 'USD', '2026-09-15')
            ->where('gasto_id', $anulado->id)->values()->all());
        $this->assertSame(3000, (int) app(ConsultaGastos::class)->beneficiariosConSaldo($u, '2026-09-15')->sole()->pendiente);

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()->assertSee('ConceptoVigente')->assertDontSee('ConceptoAnulable');
    }

    public function test_un_gasto_sin_monto_anulado_sale_de_por_completar(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u, ['concepto' => 'ConceptoSinMonto', 'importe' => null], []);

        $this->anular($u, $gasto)->assertSessionHasNoErrors();

        $this->actingAs($u)->get(route('gastos.index', ['pestana' => 'por_completar']))->assertDontSee('ConceptoSinMonto');
        $this->assertSame(0, app(ConsultaGastos::class)->esperandoMonto($u, []));
        $this->assertSame(0, app(ConsultaGastos::class)->esperandoMontoAbiertos(['empresarial'])->count());
    }

    public function test_sale_de_los_avisos_y_de_los_resumenes_por_correo(): void
    {
        $u = $this->administrador();
        PreferenciaAvisos::updateOrCreate(['usuario_id' => $u->id], PreferenciaAvisos::PREDETERMINADAS);
        $anulado = $this->gasto($u, ['concepto' => 'ConceptoAnulable'], [['100.00', '2026-03-01']]);
        $this->gasto($u, ['concepto' => 'ConceptoVigente', 'importe' => '30.00'], [['30.00', '2026-03-01']]);

        // Un aviso que ya estaba en la bandeja antes de anular.
        app(ArmarAvisos::class)->armar(CarbonImmutable::parse('2026-03-10'));
        $this->assertSame(1, Aviso::where('gasto_id', $anulado->id)->whereNull('leido_at')->count());

        $this->anular($u, $anulado);

        // El que ya estaba deja de pedir atención: la deuda ya no existe.
        $this->assertSame(0, Aviso::where('gasto_id', $anulado->id)->whereNull('leido_at')->count());
        $this->assertSame(1, Aviso::where('gasto_id', $anulado->id)->count());

        // Y no se arman avisos nuevos de él, aunque cambie la ventana.
        app(ArmarAvisos::class)->armar(CarbonImmutable::parse('2026-03-20'));
        $this->assertSame(1, Aviso::where('gasto_id', $anulado->id)->count());

        $contenido = app(EnviarResumenes::class)->contenido(['empresarial'], CarbonImmutable::parse('2026-03-20'));
        $conceptos = array_column(array_merge($contenido['vencidos'], $contenido['proximos']), 'concepto');
        $this->assertSame(['ConceptoVigente'], $conceptos);
        $this->assertEquals(['USD' => '30.00'], $contenido['totales']);
    }

    // ─────────────────────────── El pasado no se reescribe ───────────────────────────

    public function test_el_corte_a_una_fecha_anterior_a_la_anulacion_lo_sigue_mostrando(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $u = $this->administrador();
        $gasto = $this->gasto($u, [], [['100.00', '2026-09-10']]);

        $this->travelTo('2026-09-20 15:00:00');
        $this->anular($u, $gasto)->assertSessionHasNoErrors();

        $informe = app(InformeGastos::class);
        $antes = $informe->pendientes($u, '2026-09-15');
        $this->assertSame([$gasto->id], $antes->pluck('id')->all());
        $this->assertSame(10000, (int) $antes->first()->pendiente);

        // El mismo día de la anulación ya no estaba pendiente al cerrar.
        $this->assertSame([], $informe->pendientes($u, '2026-09-20')->pluck('id')->all());
        $this->assertSame([], $informe->pendientes($u, '2026-09-25')->pluck('id')->all());
    }

    // ─────────────────────────── Un anulado no se toca ───────────────────────────

    public function test_a_un_gasto_anulado_no_se_le_paga_ni_se_le_ajusta(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u);
        $this->anular($u, $gasto);

        try {
            $this->pagar($u, $gasto->fresh(), '10.00');
            $this->fail('No se paga un gasto anulado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('anulado', $e->getMessage());
        }

        try {
            app(RegistrarAjuste::class)->registrar($u, $gasto->cuotas->first(), [
                'clave' => (string) Str::uuid(), 'direccion' => 'debito', 'tipo' => 'correccion',
                'importe' => '10.00', 'motivo' => 'Intento de ajustar un anulado.',
            ]);
            $this->fail('No se ajusta un gasto anulado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('anulado', $e->getMessage());
        }

        $sinMonto = $this->gasto($u, ['importe' => null], []);
        $this->anular($u, $sinMonto);
        try {
            app(CompletarMonto::class)->completar($u, $sinMonto->fresh(), [['importe' => '10.00', 'vence' => null]]);
            $this->fail('No se completa un gasto anulado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('anulado', $e->getMessage());
        }

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, DB::table('gastos_ajustes')->count());
    }

    // ─────────────────────────── La ficha ───────────────────────────

    public function test_la_ficha_ofrece_anular_y_despues_muestra_la_insignia_y_el_motivo(): void
    {
        $u = $this->administrador();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->get(route('gastos.show', $gasto))
            ->assertOk()->assertSee('Anular gasto')->assertSee(route('gastos.anular', $gasto));

        $this->anular($u, $gasto, 'Proveedor equivocado.');

        $this->actingAs($u)->get(route('gastos.show', $gasto))
            ->assertOk()
            ->assertSee('Anulado')
            ->assertSee('Proveedor equivocado.')
            ->assertSee($u->name)
            ->assertSee('Gasto anulado')
            ->assertDontSee(route('gastos.anular', $gasto))
            ->assertDontSee('Registrar ajuste');
    }

    public function test_la_ficha_con_un_pago_vigente_dice_que_revertir_en_vez_de_ofrecer_el_formulario(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        $u = $this->administrador();
        $gasto = $this->gasto($u);
        $pago = $this->pagar($u, $gasto, '40.00');

        $this->actingAs($u)->get(route('gastos.show', $gasto))
            ->assertOk()
            ->assertSee('Pago #'.$pago->id.' del 05/09/2026')
            ->assertSee('Revertí primero ese pago')
            ->assertDontSee(route('gastos.anular', $gasto));
    }

    // ─────────────────────────── La migración ───────────────────────────

    public function test_la_migracion_sube_y_baja_y_no_baja_si_hay_anulados(): void
    {
        $migracion = require database_path(self::MIGRACION);

        $migracion->down();
        $this->assertFalse(Schema::hasColumn('gastos', 'anulado_at'));
        $this->assertFalse(Schema::hasColumn('gastos', 'anulado_por'));
        $this->assertFalse(Schema::hasColumn('gastos', 'motivo_anulacion'));

        $migracion->up();
        $this->assertTrue(Schema::hasColumn('gastos', 'anulado_at'));
        $this->assertTrue(Schema::hasColumn('gastos', 'anulado_por'));
        $this->assertTrue(Schema::hasColumn('gastos', 'motivo_anulacion'));

        $u = $this->administrador();
        $gasto = $this->gasto($u);
        $this->anular($u, $gasto);

        try {
            $migracion->down();
            $this->fail('Bajar con gastos anulados los volvería deuda sin aviso.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('anulados', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('gastos', 'anulado_at'));
        $this->assertTrue($gasto->fresh()->anulado());
    }
}
