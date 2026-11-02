<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\PrestacionAnual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Valores esperados escritos a mano, con la cuenta al lado. Datos ficticios. */
class PrestacionAnualTest extends TestCase
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
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function persona(string $nombre, string $dui, ?string $ingreso, ?string $sueldo = '408.80', bool $descuentos = true): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $descuentos, 'alta_el' => $ingreso, 'sueldo_mensual' => $sueldo,
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
    }

    private function abrir(string $clase, string $pago): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => $clase, 'moneda' => 'USD', 'fecha_pago' => $pago,
        ]);
    }

    private function calcular(string $clase, string $ingreso, int $mensual, string $pago): array
    {
        $empleado = new PlanillaEmpleado(['alta_el' => $ingreso]);

        return app(PrestacionAnual::class)->calcular($clase, $empleado, $mensual, CarbonImmutable::parse($pago));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function aguinaldos(): array
    {
        return [
            // 408.80 ÷ 30 = 13.6267 al día. Con el corte al 12/12/2026.
            'un año exacto: 15 días' => ['2025-12-12', 20440],
            'casi tres años: 15 días' => ['2023-12-13', 20440],
            // 408.80 × 19 ÷ 30 = 258.9067.
            'tres años exactos: 19 días' => ['2023-12-12', 25891],
            'casi diez años: 19 días' => ['2016-12-13', 25891],
            // 408.80 × 21 ÷ 30 = 286.16.
            'diez años exactos: 21 días' => ['2016-12-12', 28616],
            // 180 días entre fechas, más el del ingreso: 204.40 × 181 ÷ 365 = 101.36.
            'menos de un año: proporcional' => ['2026-06-15', 10136],
            // 204.40 × 1 ÷ 365 = 0.56.
            'entró el día del corte' => ['2026-12-12', 56],
        ];
    }

    #[DataProvider('aguinaldos')]
    public function test_aguinaldo_segun_antiguedad(string $ingreso, int $esperado): void
    {
        $this->assertSame($esperado, $this->calcular('aguinaldo', $ingreso, 40880, '2026-12-15')['centavos']);
    }

    public function test_aguinaldo_de_quien_entro_despues_del_corte_no_corresponde(): void
    {
        $this->assertNotNull($this->calcular('aguinaldo', '2026-12-13', 40880, '2026-12-15')['fuera']);
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function quincenas(): array
    {
        return [
            'un año exacto: 50 %' => ['2026-01-14', 40880, 20440],
            'sueldo en el tope' => ['2020-01-01', 150000, 75000],
            // Del 14/07 al 14/01 son 184 días, más el del ingreso: 204.40 × 185 ÷ 365 = 103.60.
            'menos de un año: proporcional' => ['2026-07-14', 40880, 10360],
        ];
    }

    #[DataProvider('quincenas')]
    public function test_quincena_25(string $ingreso, int $mensual, int $esperado): void
    {
        $this->assertSame($esperado, $this->calcular('quincena25', $ingreso, $mensual, '2027-01-20')['centavos']);
    }

    public function test_quincena_25_deja_fuera_a_quien_pasa_del_tope(): void
    {
        $this->assertNotNull($this->calcular('quincena25', '2020-01-01', 150001, '2027-01-20')['fuera']);
    }

    public function test_abrir_aguinaldo_incluye_con_y_sin_descuentos_y_no_les_quita_nada(): void
    {
        $con = $this->persona('Ana', 'FICTICIO-1', '2020-01-01');
        $sin = $this->persona('Bea', 'FICTICIO-2', '2026-06-15', '300.00', false);

        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        $this->assertSame('2026-12', $planilla->periodo);
        $this->assertSame('mensual', $planilla->tipo_periodo);

        $ana = PlanillaDetalle::where('planilla_empleado_id', $con->id)->sole();
        $this->assertSame('258.91', (string) $ana->a_pagar);
        $this->assertSame('Aguinaldo 2026', $ana->conceptos()->sole()->concepto);

        // 300 × 15 ÷ 30 = 150; 150 × 181 ÷ 365 = 74.38.
        $this->assertSame('74.38', (string) PlanillaDetalle::where('planilla_empleado_id', $sin->id)->sole()->a_pagar);
    }

    public function test_el_aguinaldo_se_confirma_y_se_imprime_sin_importe_del_periodo(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01');
        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertOk()->assertSee('Aguinaldo 2026')->assertDontSee('Importe del período');
    }

    public function test_el_monto_se_puede_corregir_a_mano(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2020-01-01');
        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [[
            'planilla_empleado_id' => $e->id, 'salario' => '0.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => '300.00'],
            ],
        ]]);

        $this->assertSame('300.00', (string) PlanillaDetalle::where('planilla_id', $planilla->id)->sole()->a_pagar);
    }

    public function test_sin_fecha_de_ingreso_frena_y_dice_que_falta(): void
    {
        $this->persona('Ana', 'FICTICIO-1', null);
        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        $this->assertStringContainsString('falta la fecha de ingreso', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh())));
    }

    public function test_sin_sueldo_frena_y_dice_que_falta(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01', null);
        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        $this->assertStringContainsString('falta el sueldo', implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh())));
    }

    public function test_fuera_del_plazo_no_abre(): void
    {
        foreach ([['aguinaldo', '2026-09-30'], ['aguinaldo', '2026-12-21'], ['quincena25', '2027-01-14'], ['quincena25', '2027-01-26']] as [$clase, $fecha]) {
            try {
                $this->abrir($clase, $fecha);
                $this->fail('Tenía que rechazar '.$fecha);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('fecha_pago', $e->errors());
            }
        }

        $this->assertSame(0, Planilla::count());
    }

    public function test_los_bordes_del_plazo_abren(): void
    {
        foreach ([['aguinaldo', '2026-10-01'], ['aguinaldo', '2026-12-20'], ['quincena25', '2027-01-15'], ['quincena25', '2027-01-25']] as [$clase, $fecha]) {
            $this->assertInstanceOf(Planilla::class, $this->abrir($clase, $fecha));
        }
    }

    public function test_sin_parametros_para_el_ano_no_abre(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No hay parámetros de Aguinaldo para 2027');

        $this->abrir('aguinaldo', '2027-12-15');
    }

    public function test_quincena_25_no_existe_antes_de_2027(): void
    {
        $this->expectException(ValidationException::class);

        $this->abrir('quincena25', '2026-01-20');
    }

    public function test_exige_fecha_de_pago(): void
    {
        $this->expectException(ValidationException::class);

        app(PrepararPlanilla::class)->abrir($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'aguinaldo', 'moneda' => 'USD']);
    }

    public function test_abrir_dos_veces_devuelve_la_misma(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01');

        $this->assertSame($this->abrir('aguinaldo', '2026-12-15')->id, $this->abrir('aguinaldo', '2026-12-18')->id);
    }

    public function test_el_aguinaldo_no_cruza_con_la_quincena_del_mismo_mes(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01');
        $quincena = $this->abrir('regular', '2026-11-16');
        $aguinaldo = $this->abrir('aguinaldo', '2026-11-16');

        $preparar = app(PrepararPlanilla::class);
        $this->assertStringNotContainsString('se cruzan', implode(' ', $preparar->reparosParaConfirmar($quincena->fresh())));
        $this->assertStringNotContainsString('se cruzan', implode(' ', $preparar->reparosParaConfirmar($aguinaldo->fresh())));
    }

    public function test_quincena_25_avisa_quien_queda_fuera_y_recorta_el_motivo_sin_permiso(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01', '2000.00');
        $this->persona('Bea', 'FICTICIO-2', '2020-01-01');

        $planilla = $this->abrir('quincena25', '2027-01-20');

        $this->assertSame(1, $planilla->detalles()->count());

        $preparar = app(PrepararPlanilla::class);
        $this->assertStringContainsString('gana más de', implode(' ', $preparar->avisosParaConfirmar($planilla->fresh(), true)));

        $sinImportes = implode(' ', $preparar->avisosParaConfirmar($planilla->fresh(), false));
        $this->assertStringContainsString('queda fuera', $sinImportes);
        $this->assertStringNotContainsString('gana más de', $sinImportes);
    }

    public function test_avisa_si_el_aguinaldo_pasa_de_lo_exento(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-01-01', '10000.00');
        $planilla = $this->abrir('aguinaldo', '2026-12-15');

        $this->assertStringContainsString('exentos de renta', implode(' ', app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh(), true)));
    }

    public function test_quien_esta_de_baja_no_entra(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2020-01-01');
        $e->update(['activo' => false, 'baja_el' => '2026-11-01']);

        $this->assertSame(0, $this->abrir('aguinaldo', '2026-12-15')->detalles()->count());
    }

    public function test_la_pantalla_abre_y_ofrece_las_dos_clases(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.create'))
            ->assertOk()->assertSee('Aguinaldo')->assertSee('Quincena 25');
    }
}
