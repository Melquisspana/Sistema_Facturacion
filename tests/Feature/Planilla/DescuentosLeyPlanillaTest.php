<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DescuentosLeyPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function persona(string $nombre, bool $conDescuentos, string $dui, string $sueldo = '408.80'): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $conDescuentos, 'alta_el' => '2026-01-01', 'sueldo_mensual' => $sueldo,
        ]);
    }

    private function abrir(string $tipo = 'quincenal', string $clase = 'regular', string $fecha = '2026-11-02'): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => $tipo, 'fecha' => $fecha, 'clase' => $clase, 'moneda' => 'USD', 'fecha_pago' => '2026-11-16',
        ]);
    }

    private function detalleDe(Planilla $planilla, PlanillaEmpleado $e): PlanillaDetalle
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $e->id)->firstOrFail();
    }

    /** Lo que mandaría el formulario, tal como está guardado. */
    private function linea(PlanillaDetalle $detalle, array $cambios = []): array
    {
        return array_replace([
            'planilla_empleado_id' => $detalle->planilla_empleado_id,
            'salario' => (string) $detalle->salario,
            'conceptos' => $detalle->conceptos()->get()->map(fn ($c) => [
                'tipo' => $c->tipo, 'ley' => $c->ley, 'concepto' => $c->concepto, 'importe' => (string) $c->importe,
                'destino' => $c->destino, 'tercero' => $c->tercero,
            ])->all(),
        ], $cambios);
    }

    private function importes(PlanillaDetalle $detalle): array
    {
        return $detalle->conceptos()->whereNotNull('ley')->pluck('importe', 'ley')->map(fn ($v) => (string) $v)->all();
    }

    public function test_al_abrir_solo_quien_va_con_descuentos_los_lleva(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $sin = $this->persona('Bea', false, 'FICTICIO-2');

        $planilla = $this->abrir();

        $this->assertSame(['isss' => '6.13', 'afp' => '14.82', 'renta' => '0.00'], $this->importes($this->detalleDe($planilla, $con)));
        $this->assertSame([], $this->importes($this->detalleDe($planilla, $sin)));
        $this->assertSame('183.45', (string) $this->detalleDe($planilla, $con)->a_pagar);
        $this->assertSame('204.40', (string) $this->detalleDe($planilla, $sin)->a_pagar);
    }

    public function test_el_formulario_no_le_puede_meter_descuentos_a_quien_va_sin_ellos(): void
    {
        $sin = $this->persona('Bea', false, 'FICTICIO-2');
        $planilla = $this->abrir();

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [[
            'planilla_empleado_id' => $sin->id, 'salario' => '204.40', 'conceptos' => [
                ['tipo' => 'descuento', 'ley' => 'isss', 'concepto' => 'ISSS', 'importe' => '6.13'],
            ],
        ]]);

        $this->assertSame([], $this->importes($this->detalleDe($planilla, $sin)));
    }

    public function test_cambiar_el_salario_recalcula_lo_que_nadie_toco(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            $this->linea($this->detalleDe($planilla, $con), ['salario' => '300.00']),
        ]);

        // 3 % de 300 = 9.00; 7.25 % = 21.75.
        $this->assertSame(['isss' => '9.00', 'afp' => '21.75', 'renta' => '0.00'], $this->importes($this->detalleDe($planilla, $con)));
    }

    public function test_una_correccion_a_mano_se_respeta_y_queda_constancia_sin_importes(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();
        $detalle = $this->detalleDe($planilla, $con);
        $linea = $this->linea($detalle);
        $linea['conceptos'][0]['importe'] = '7.77';

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [$linea]);

        $isss = $this->detalleDe($planilla, $con)->conceptos()->where('ley', 'isss')->first();
        $this->assertSame('7.77', (string) $isss->importe);
        $this->assertSame('6.13', (string) $isss->importe_calculado);
        $this->assertTrue($isss->corregidoAMano());

        $actividad = Activity::where('description', 'corrigió a mano un descuento de ley')->sole();
        $this->assertStringNotContainsString('7.77', json_encode($actividad->properties));

        // Guardar otra vez sin tocar nada no vuelve a registrarlo, y un salario nuevo no la pisa.
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [$this->linea($this->detalleDe($planilla, $con), ['salario' => '300.00'])]);

        $isss = $this->detalleDe($planilla, $con)->conceptos()->where('ley', 'isss')->first();
        $this->assertSame('7.77', (string) $isss->importe);
        $this->assertSame('9.00', (string) $isss->importe_calculado);
        $this->assertSame(1, Activity::where('description', 'corrigió a mano un descuento de ley')->count());
    }

    public function test_viaticos_no_suman_a_la_base_y_comision_si(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();
        $detalle = $this->detalleDe($planilla, $con);
        $linea = $this->linea($detalle, ['salario' => '200.00']);
        $linea['conceptos'][] = ['tipo' => 'ingreso', 'concepto' => 'Viáticos', 'importe' => '50.00'];
        $linea['conceptos'][] = ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '100.00'];

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [$linea]);

        // Base 300.00: 3 % = 9.00.
        $this->assertSame('9.00', $this->importes($this->detalleDe($planilla, $con))['isss']);
    }

    public function test_confirmar_crea_las_obligaciones_de_ley_sin_renta_en_cero(): void
    {
        $this->persona('Ana', true, 'FICTICIO-1');
        $this->persona('Cruz', true, 'FICTICIO-3');
        $planilla = $this->abrir();

        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());

        $terceros = PlanillaObligacionTercero::where('planilla_id', $planilla->id)->pluck('importe', 'tercero')->map(fn ($v) => (string) $v)->all();
        $this->assertSame(['AFP' => '29.64', 'ISSS' => '12.26'], collect($terceros)->sortKeys()->all());
    }

    public function test_sueldo_alto_confirma_la_renta_al_ministerio(): void
    {
        $this->persona('Ana', true, 'FICTICIO-1', '2000.00');
        $planilla = $this->abrir();

        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());

        // Quincena 1000.00: renta 122.98 (ver DescuentosLeyTest).
        $this->assertDatabaseHas('planilla_obligaciones_terceros', ['tercero' => 'Ministerio de Hacienda', 'importe' => '122.98']);
    }

    public function test_isss_en_cero_no_deja_confirmar(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();
        $linea = $this->linea($this->detalleDe($planilla, $con));
        $linea['conceptos'][0]['importe'] = '0.00';

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [$linea]);

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh());
        $this->assertStringContainsString('falta el importe de ISSS', implode(' ', $reparos));
    }

    public function test_sin_tasas_vigentes_frena_y_no_calcula_con_cero(): void
    {
        config(['planilla_descuentos.versiones' => []]);
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();

        $this->assertSame([], $this->importes($this->detalleDe($planilla, $con)));
        $this->assertStringContainsString('No hay tasas', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh())));

        $this->expectException(ValidationException::class);
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());
    }

    public function test_planilla_semanal_y_extraordinaria_no_calculan(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');

        $this->assertSame([], $this->importes($this->detalleDe($this->abrir('semanal'), $con)));
        $this->assertSame([], $this->importes($this->detalleDe($this->abrir('quincenal', 'extraordinaria'), $con)));
    }

    public function test_la_planilla_mensual_paga_el_sueldo_completo_con_sus_descuentos(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1', '1200.00');
        $sin = $this->persona('Bea', false, 'FICTICIO-2', '450.00');

        $mes = $this->abrir('mensual');

        // El sueldo del mes entero, no la mitad.
        $this->assertSame('1200.00', (string) $this->detalleDe($mes, $con)->salario);
        // ISSS: 3 % del tope mensual entero, 1,000.00 = 30.00. AFP: 7.25 % de 1,200.00 = 87.00.
        // Renta, tabla mensual: 1,200 − 30 − 87 = 1,083.00 en el tramo III:
        // 60.00 + 20 % × (1,083.00 − 895.24) = 60.00 + 37.552 = 97.55.
        $this->assertSame(['isss' => '30.00', 'afp' => '87.00', 'renta' => '97.55'], $this->importes($this->detalleDe($mes, $con)));
        $this->assertSame('985.45', (string) $this->detalleDe($mes, $con)->a_pagar);
        // Sin descuentos: el mes entero en bruto.
        $this->assertSame([], $this->importes($this->detalleDe($mes, $sin)));
        $this->assertSame('450.00', (string) $this->detalleDe($mes, $sin)->a_pagar);
    }

    public function test_la_mensual_de_junio_recalcula_la_renta_con_las_mensuales_del_ano(): void
    {
        $con = app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '1200.00', 'sueldo_desde' => '2026-01-01',
        ]);

        foreach (['01', '02', '03', '04', '05'] as $mes) {
            app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'mensual', 'fecha' => '2026-'.$mes.'-01', 'clase' => 'regular', 'moneda' => 'USD']);
        }

        $junio = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'mensual', 'fecha' => '2026-06-01', 'clase' => 'regular', 'moneda' => 'USD']);

        // Gravado de cada mes 1,083.00; en seis meses 6,498.00. Tabla semestral, tramo III:
        // 360.00 + 20 % × (6,498.00 − 5,371.44) = 585.31. Ya retenido: 5 × 97.55 = 487.75.
        // En junio: 585.31 − 487.75 = 97.56.
        $this->assertSame('confirmada', $junio->fresh()->estado);
        $this->assertSame('97.56', $this->importes($this->detalleDe($junio, $con))['renta']);
    }

    public function test_la_misma_persona_no_cobra_dos_veces_en_planillas_regulares(): void
    {
        $this->persona('Ana', true, 'FICTICIO-1');
        $quincena = $this->abrir();
        $mes = $this->abrir('mensual');

        $this->assertStringContainsString('fechas que se cruzan', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($quincena->fresh())));
        $this->assertStringContainsString('fechas que se cruzan', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($mes->fresh())));
    }

    public function test_una_extraordinaria_con_las_mismas_fechas_solo_avisa(): void
    {
        $this->persona('Bea', false, 'FICTICIO-2');
        $this->abrir();
        $extra = $this->abrir('quincenal', 'extraordinaria');

        $this->assertStringNotContainsString('fechas que se cruzan', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($extra->fresh())));
        $this->assertStringContainsString('ya está en la planilla', implode(' ', app(PrepararPlanilla::class)->avisosParaConfirmar($extra->fresh(), true)));
    }

    public function test_una_planilla_anulada_no_cuenta_como_cruce(): void
    {
        $this->persona('Bea', false, 'FICTICIO-2');
        $this->abrir()->update(['estado' => 'anulada']);
        $mes = $this->abrir('mensual');

        $this->assertStringNotContainsString('fechas que se cruzan', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($mes->fresh())));
    }

    public function test_avisos_de_correccion_recortan_importes_sin_permiso(): void
    {
        $con = $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();
        $linea = $this->linea($this->detalleDe($planilla, $con));
        $linea['conceptos'][0]['importe'] = '7.77';
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [$linea]);

        $preparar = app(PrepararPlanilla::class);
        $this->assertStringContainsString('7.77', implode(' ', $preparar->avisosParaConfirmar($planilla->fresh(), true)));

        $sinImportes = implode(' ', $preparar->avisosParaConfirmar($planilla->fresh(), false));
        $this->assertStringContainsString('ISSS corregido a mano', $sinImportes);
        $this->assertStringNotContainsString('7.77', $sinImportes);
    }

    public function test_el_recibo_no_imprime_una_renta_en_cero(): void
    {
        $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();

        $respuesta = $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']));

        $respuesta->assertOk()->assertSee('ISSS')->assertDontSee('Renta');
    }

    public function test_la_pantalla_de_preparar_muestra_la_propuesta(): void
    {
        $this->persona('Ana', true, 'FICTICIO-1');
        $planilla = $this->abrir();

        $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))
            ->assertOk()->assertSee('Propuesta del sistema')->assertSee('ISSS');
    }
}
