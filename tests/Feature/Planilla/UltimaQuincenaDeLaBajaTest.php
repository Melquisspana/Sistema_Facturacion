<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La quincena de quien entra o se va a mitad del período.
 *
 * La baja apaga a la persona en el acto, y la quincena solo proponía a las personas
 * activas: quien se iba el día 5 no aparecía en la quincena del 1 al 15, y esos días no
 * quedaban en ninguna planilla. Ahora entra quien estuvo activo algún día del período.
 *
 * Se le pagan SOLO los días trabajados: sueldo mensual ÷ 30 × días, con el día de ingreso
 * y el de la baja como trabajados (Melqui, 12/11/2026). Los descuentos de ley van sobre
 * lo que cobra, si la persona va con ellos. El aviso es informativo: no deja la planilla
 * en borrador. Datos ficticios.
 */
class UltimaQuincenaDeLaBajaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

    private PlanillaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'planilla.pagos_enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());

        $this->ana = $this->persona('Ana', 'FICTICIO-1');
        $this->beto = $this->persona('Beto', 'FICTICIO-2');
    }

    /** 450.00 al mes son 225.00 por quincena y 15.00 por día. Sin descuentos de ley, salvo que se pida. */
    private function persona(string $nombre, string $dui, string $alta = '2026-01-01', bool $conDescuentos = false): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticio', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $conDescuentos, 'alta_el' => $alta, 'sueldo_mensual' => '450.00',
            'sueldo_desde' => $alta, 'confirmar_bajo_minimo' => true,
        ]);
    }

    private function lineaDe(Planilla $planilla, PlanillaEmpleado $empleado): PlanillaDetalle
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $empleado->id)->sole();
    }

    /** @return array<string, string> */
    private function deLey(PlanillaDetalle $detalle): array
    {
        return $detalle->conceptos()->whereNotNull('ley')->pluck('importe', 'ley')->map(fn ($v) => (string) $v)->all();
    }

    private function baja(PlanillaEmpleado $empleado, string $fecha): void
    {
        app(BajaEmpleado::class)->dar($this->actor, $empleado, $fecha, 'otro');
    }

    /** @return array<string, string> */
    private function periodo(string $fecha, string $clase = 'regular'): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => $clase, 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function abrir(string $fecha): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo($fecha));
    }

    /** @return array<int, int> */
    private function quienes(Planilla $planilla): array
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->orderBy('planilla_empleado_id')->pluck('planilla_empleado_id')->all();
    }

    /** @return array<int, string> */
    private function avisos(Planilla $planilla): array
    {
        return app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh()->load('detalles.conceptos', 'detalles.empleado'), true);
    }

    public function test_quien_se_fue_dentro_de_la_quincena_cobra_solo_sus_dias(): void
    {
        $this->baja($this->beto, '2026-10-10');

        $planilla = $this->abrir('2026-10-01');

        $this->assertSame([$this->ana->id, $this->beto->id], $this->quienes($planilla));
        // 450.00 ÷ 30 × 10 días, con el día de la baja como trabajado.
        $beto = $this->lineaDe($planilla, $this->beto);
        $this->assertSame('150.00', (string) $beto->salario);
        $this->assertSame('150.00', (string) $beto->a_pagar);
        // El recibo dice qué días se pagan.
        $this->assertSame(['2026-10-01', '2026-10-10'], [$beto->periodo_desde->toDateString(), $beto->periodo_hasta->toDateString()]);
        $this->assertSame('225.00', (string) $this->lineaDe($planilla, $this->ana)->a_pagar);
        $this->assertContains('Beto Ficticio: tiene baja el 10/10/2026: se pagan 10 días.', $this->avisos($planilla));
        $this->assertStringNotContainsString('Ana Ficticio', implode(' ', $this->avisos($planilla)));
    }

    public function test_quien_entro_a_mitad_de_quincena_cobra_solo_sus_dias(): void
    {
        $cata = $this->persona('Cata', 'FICTICIO-3', '2026-10-06');

        $planilla = $this->abrir('2026-10-01');

        // Del 6 al 15, con el día de ingreso: 10 días.
        $linea = $this->lineaDe($planilla, $cata);
        $this->assertSame('150.00', (string) $linea->a_pagar);
        $this->assertSame(['2026-10-06', '2026-10-15'], [$linea->periodo_desde->toDateString(), $linea->periodo_hasta->toDateString()]);
        $this->assertContains('Cata Ficticio: entró el 06/10/2026: se pagan 10 días.', $this->avisos($planilla));
        $this->assertSame([], app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos', 'detalles.empleado')));
    }

    public function test_quien_entra_despues_de_la_quincena_no_aparece_en_ella(): void
    {
        $this->persona('Cata', 'FICTICIO-3', '2026-10-20');

        $this->assertSame([$this->ana->id, $this->beto->id], $this->quienes($this->abrir('2026-10-01')));
    }

    public function test_en_un_mes_de_31_se_pagan_los_dias_reales_trabajados(): void
    {
        $this->baja($this->beto, '2026-10-20');
        $cata = $this->persona('Cata', 'FICTICIO-3', '2026-10-20');
        $dani = $this->persona('Dani', 'FICTICIO-4', '2026-10-20');
        $this->baja($dani, '2026-10-31');

        $planilla = $this->abrir('2026-10-16');

        // El 31 cuenta como un día más (Melqui, 12/11/2026). Del 16 al 20: 5 días = 75.00.
        $this->assertSame('75.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);
        // Del 20 al 31: 12 días = 180.00, se vaya o no el 31.
        $this->assertSame('180.00', (string) $this->lineaDe($planilla, $cata)->a_pagar);
        $this->assertSame('180.00', (string) $this->lineaDe($planilla, $dani)->a_pagar);
        // Quien trabajó la quincena entera cobra la mitad del sueldo, sin contar días.
        $this->assertSame('225.00', (string) $this->lineaDe($planilla, $this->ana)->a_pagar);
    }

    public function test_en_febrero_se_pagan_sus_dias_reales(): void
    {
        $cata = $this->persona('Cata', 'FICTICIO-3', '2026-02-27');

        $planilla = $this->abrir('2026-02-16');

        // El 27 y el 28: 2 días = 30.00. La quincena entera sigue siendo 225.00.
        $this->assertSame('30.00', (string) $this->lineaDe($planilla, $cata)->a_pagar);
        $this->assertSame('225.00', (string) $this->lineaDe($planilla, $this->ana)->a_pagar);
    }

    public function test_una_baja_registrada_despues_de_preparar_frena_hasta_revisar_la_linea(): void
    {
        $planilla = $this->abrir('2026-10-01');
        $this->assertSame('225.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);

        $this->baja($this->beto, '2026-10-05');

        $reparo = 'Beto Ficticio: tiene baja el 05/10/2026 y su línea no lo tiene en cuenta: le corresponden 5 días (75.00). Revisá su importe y sus fechas.';
        $this->assertContains($reparo, app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos', 'detalles.empleado')));

        try {
            app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());
            $this->fail('Tenía que frenar.');
        } catch (ValidationException $e) {
            $this->assertContains($reparo, $e->errors()['reparos']);
        }

        $this->assertSame('borrador', $planilla->fresh()->estado);
        $this->assertSame('225.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);

        // Revisada: sus 5 días con sus fechas. Se genera.
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
            ['planilla_empleado_id' => $this->beto->id, 'salario' => '75.00', 'periodo_desde' => '2026-10-01', 'periodo_hasta' => '2026-10-05'],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame('75.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);
    }

    public function test_quien_va_con_descuentos_los_paga_sobre_sus_dias(): void
    {
        $dani = $this->persona('Dani', 'FICTICIO-4', '2026-10-06', true);

        $linea = $this->lineaDe($this->abrir('2026-10-01'), $dani);

        // Sobre 150.00: ISSS 3 % = 4.50; AFP 7.25 % = 10.875, al centavo 10.88; renta, nada.
        $this->assertSame('150.00', (string) $linea->salario);
        $this->assertSame(['isss' => '4.50', 'afp' => '10.88', 'renta' => '0.00'], $this->deLey($linea));
        $this->assertSame('134.62', (string) $linea->a_pagar);
    }

    public function test_quien_va_sin_descuentos_cobra_sus_dias_en_bruto(): void
    {
        $this->baja($this->beto, '2026-10-10');

        $linea = $this->lineaDe($this->abrir('2026-10-01'), $this->beto);

        $this->assertSame([], $this->deLey($linea));
        $this->assertSame('150.00', (string) $linea->a_pagar);
    }

    public function test_una_baja_con_fecha_posterior_a_la_quincena_entra_sin_aviso(): void
    {
        // Preaviso: se registra hoy una baja que rige después de que termine el período.
        $this->baja($this->beto, '2026-10-20');

        $planilla = $this->abrir('2026-10-01');

        $this->assertSame([$this->ana->id, $this->beto->id], $this->quienes($planilla));
        $this->assertSame([], $this->avisos($planilla));
    }

    public function test_quien_se_fue_antes_de_que_empezara_la_quincena_sigue_fuera(): void
    {
        $this->baja($this->beto, '2026-09-30');

        $this->assertSame([$this->ana->id], $this->quienes($this->abrir('2026-10-01')));
    }

    public function test_quien_esta_inactivo_sin_fecha_de_baja_sigue_fuera(): void
    {
        $this->beto->forceFill(['activo' => false, 'baja_el' => null])->save();

        $this->assertSame([$this->ana->id], $this->quienes($this->abrir('2026-10-01')));
    }

    public function test_el_aguinaldo_sigue_siendo_solo_de_quien_esta_activo(): void
    {
        $this->baja($this->beto, '2026-12-05');

        $aguinaldo = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'mensual', 'fecha' => '2026-12-15', 'clase' => 'aguinaldo', 'moneda' => 'USD', 'fecha_pago' => '2026-12-15',
        ]);

        $this->assertSame([$this->ana->id], $this->quienes($aguinaldo));
    }

    public function test_la_revision_avisa_y_generar_confirma_sin_frenar(): void
    {
        $this->baja($this->beto, '2026-10-05');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()
            ->assertSee('Beto Ficticio')
            ->assertSee('tiene baja el 05/10/2026: se pagan 5 días')
            ->assertSee('Sí, generar la planilla')
            ->assertDontSee('Seguir a revisar la planilla');

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', $planilla));
        $this->assertSame('confirmada', $planilla->estado);
        $this->assertSame('75.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);
        $this->assertSame(2, Gasto::count());
    }

    public function test_el_importe_de_sus_dias_se_puede_corregir_a_mano_en_el_borrador(): void
    {
        $this->baja($this->beto, '2026-10-05');
        $planilla = $this->abrir('2026-10-01');

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
            ['planilla_empleado_id' => $this->beto->id, 'salario' => '80.00'],
        ]);

        $this->assertSame('80.00', (string) $this->lineaDe($planilla, $this->beto)->a_pagar);
    }

    public function test_quien_se_fue_y_se_quito_del_borrador_se_puede_volver_a_agregar(): void
    {
        $this->baja($this->beto, '2026-10-05');
        $planilla = $this->abrir('2026-10-01');
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
        ]);

        $pantalla = $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))->assertOk();

        $this->assertSame([$this->beto->id], $pantalla->viewData('disponibles')->pluck('id')->all());
        // Al volver a agregarlo se le proponen sus días, no la quincena entera.
        $this->assertSame(['salario' => '75.00', 'periodo_desde' => '2026-10-01', 'periodo_hasta' => '2026-10-05'], $pantalla->viewData('propuestos')[$this->beto->id]);
    }
}
