<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConsultasConCandado;
use Tests\TestCase;

/**
 * El aguinaldo se paga una vez por año.
 *
 * El período de un aguinaldo es el MES de su fecha de pago, y se puede pagar de octubre a
 * diciembre: el índice único no frenaba un segundo aguinaldo en otro mes del mismo año.
 * Ahora quien ya está en un aguinaldo NO anulado de ese año queda fuera del siguiente,
 * como ya pasaba con las vacaciones, y la pantalla dice por qué. Datos ficticios.
 */
class AguinaldoUnaVezPorAnioTest extends TestCase
{
    use ConsultasConCandado;
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

    private function persona(string $nombre, string $dui, string $ingreso = '2020-01-01'): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => false, 'alta_el' => $ingreso, 'sueldo_mensual' => '408.80',
            // Vigente también para un aguinaldo de 2025.
            'sueldo_desde' => max($ingreso, '2025-06-01'), 'confirmar_bajo_minimo' => true,
        ]);
    }

    /** @return array<string, string> */
    private function aguinaldo(string $pago): array
    {
        return ['tipo_periodo' => 'mensual', 'fecha' => $pago, 'clase' => 'aguinaldo', 'moneda' => 'USD', 'fecha_pago' => $pago];
    }

    private function abrir(string $pago): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->aguinaldo($pago));
    }

    public function test_quien_ya_cobro_el_aguinaldo_del_anio_no_entra_en_otro_y_la_revision_dice_por_que(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        // Bea entra a la empresa después de ese pago: a ella sí le falta.
        $bea = $this->persona('Bea', 'FICTICIO-2', '2026-06-15');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->aguinaldo('2026-12-15')))
            ->assertOk()
            ->assertSee('Ana Ficticia queda fuera')
            ->assertSee('ya cobró el aguinaldo de 2026, en el pago del 30/11/2026');

        $segundo = $this->abrir('2026-12-15');

        $this->assertSame('2026-12', $segundo->periodo);
        $this->assertSame([$bea->id], PlanillaDetalle::where('planilla_id', $segundo->id)->pluck('planilla_empleado_id')->all());
        $this->assertSame(1, PlanillaDetalle::where('planilla_empleado_id', $ana->id)->count());
    }

    public function test_un_aguinaldo_todavia_en_borrador_tambien_cuenta(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $this->abrir('2026-11-30');

        $segundo = $this->abrir('2026-12-15');

        $this->assertSame(0, PlanillaDetalle::where('planilla_id', $segundo->id)->count());
    }

    public function test_un_aguinaldo_anulado_no_cuenta(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        $primero = app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        app(AnularPlanilla::class)->anular($this->actor, $primero, 'Se pagó con la fecha equivocada.');

        $segundo = $this->abrir('2026-12-15');

        $detalle = PlanillaDetalle::where('planilla_id', $segundo->id)->sole();
        $this->assertSame($ana->id, (int) $detalle->planilla_empleado_id);
        // 408.80 × 19 ÷ 30 = 258.91: el mismo monto de siempre.
        $this->assertSame('258.91', (string) $detalle->a_pagar);
    }

    public function test_el_aguinaldo_de_otro_anio_no_cuenta(): void
    {
        config(['planilla_prestaciones.aguinaldo.2025' => config('planilla_prestaciones.aguinaldo.2026')]);
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2025-12-15'));

        $deEsteAnio = $this->abrir('2026-12-15');

        $this->assertSame([$ana->id], PlanillaDetalle::where('planilla_id', $deEsteAnio->id)->pluck('planilla_empleado_id')->all());
    }

    public function test_agregar_a_mano_a_quien_ya_cobro_frena_la_confirmacion(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        $this->persona('Bea', 'FICTICIO-2', '2026-06-15');
        $segundo = $this->abrir('2026-12-15');

        $lineas = PlanillaDetalle::where('planilla_id', $segundo->id)->get()
            ->map(fn (PlanillaDetalle $d) => ['planilla_empleado_id' => $d->planilla_empleado_id, 'salario' => '0.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => (string) $d->a_pagar],
            ]])->all();
        $lineas[] = ['planilla_empleado_id' => $ana->id, 'salario' => '0.00', 'conceptos' => [
            ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => '258.91'],
        ]];
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $segundo, $lineas);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($segundo->fresh()->load('detalles.conceptos', 'detalles.empleado')));

        $this->assertStringContainsString('Ana Ficticia: ya cobró el aguinaldo de 2026', $reparos);
    }

    // ─────────── Dos aguinaldos generados a la vez ───────────
    //
    // Cada generación comprobaba «¿ya está en otro aguinaldo?» con una lectura común y
    // después confirmaba la suya: dos a la vez, en meses distintos, no se veían. Ahora las
    // dos se ponen en fila tomando el candado de las personas —como vacaciones e
    // indemnización con la suya— y la comprobación se hace con el candado puesto.

    /** Otro aguinaldo del año con Ana, que «todavía no existe»: quedó anulado. */
    private function aguinaldoDeOtroProceso(string $pago): Planilla
    {
        $otro = app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir($pago));

        return app(AnularPlanilla::class)->anular($this->actor, $otro, 'Lo confirma el otro proceso más abajo.');
    }

    /**
     * El otro proceso confirma su aguinaldo justo cuando este toma el candado de las
     * personas: es el momento en que, en MySQL, este se quedaría esperándolo.
     */
    private function elOtroConfirmaMientrasEsteEspera(Planilla $otro): \ArrayObject
    {
        $this->consultasConCandado();
        $paso = new \ArrayObject;

        DB::listen(function ($q) use ($otro, $paso) {
            $sql = mb_strtolower($q->sql);
            if (count($paso) > 0 || ! $this->nombra($sql, 'planilla_empleados') || ! str_contains($sql, 'for update')) {
                return;
            }
            $paso->append(true);
            DB::table('planillas')->where('id', $otro->id)->update(['estado' => 'confirmada']);
        });

        return $paso;
    }

    public function test_generar_el_aguinaldo_toma_primero_el_candado_de_las_personas_en_orden(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $this->persona('Bea', 'FICTICIO-2');
        $consultas = $this->consultasEnTransaccion();

        app(GenerarPlanilla::class)->generar($this->actor, $this->aguinaldo('2026-12-15'));

        $lecturas = array_values(array_filter($consultas->getArrayCopy(), fn (array $c) => str_starts_with($c['sql'], 'select')));
        $primera = $lecturas[0]['sql'];
        // Lo primero de la transacción: en MySQL, leer antes fija la foto y ya no se ve al otro.
        $this->assertTrue($this->nombra($primera, 'planilla_empleados') && str_contains($primera, 'for update'), $primera);
        $this->assertMatchesRegularExpression('/order by [`"]id[`"] asc/', $primera);
        $this->assertSame('confirmada', Planilla::sole()->estado);
    }

    public function test_si_otro_aguinaldo_se_confirma_mientras_este_esperaba_este_no_vuelve_a_pagar_a_la_misma_persona(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $otro = $this->aguinaldoDeOtroProceso('2026-11-30');
        $paso = $this->elOtroConfirmaMientrasEsteEspera($otro);

        try {
            app(GenerarPlanilla::class)->generar($this->actor, $this->aguinaldo('2026-12-15'));
            $this->fail('El segundo aguinaldo del año no debió generarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene ni una persona', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertCount(1, $paso, 'Generar el aguinaldo no tomó el candado de las personas.');
        $this->assertSame(1, Planilla::count(), 'Del segundo no queda ni el borrador.');
    }

    public function test_confirmar_un_aguinaldo_vuelve_a_mirar_con_el_candado_puesto(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        $otro = $this->aguinaldoDeOtroProceso('2026-11-30');
        // Un borrador de aguinaldo de diciembre que quedó de antes, con Ana adentro.
        $borrador = $this->abrir('2026-12-15');
        $this->assertSame([$ana->id], PlanillaDetalle::where('planilla_id', $borrador->id)->pluck('planilla_empleado_id')->all());
        $paso = $this->elOtroConfirmaMientrasEsteEspera($otro);

        try {
            app(ConfirmarPlanilla::class)->confirmar($this->actor, $borrador->fresh());
            $this->fail('Ana ya cobró el aguinaldo del año en el otro pago.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Ana Ficticia: ya cobró el aguinaldo de 2026', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertCount(1, $paso, 'Confirmar el aguinaldo no tomó el candado de las personas.');
        $this->assertSame('borrador', $borrador->fresh()->estado);
    }

    public function test_confirmar_un_aguinaldo_toma_el_candado_de_las_personas_antes_de_leer_nada(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $borrador = $this->abrir('2026-12-15');
        $consultas = $this->consultasEnTransaccion();

        app(ConfirmarPlanilla::class)->confirmar($this->actor, $borrador->fresh());

        $lecturas = array_values(array_filter($consultas->getArrayCopy(), fn (array $c) => str_starts_with($c['sql'], 'select')));
        // Primero las personas y después su planilla, como al generarlo; recién ahí lo demás.
        $this->assertTrue($this->nombra($lecturas[0]['sql'], 'planilla_empleados') && str_contains($lecturas[0]['sql'], 'for update'), $lecturas[0]['sql']);
        $this->assertTrue($this->nombra($lecturas[1]['sql'], 'planillas') && str_contains($lecturas[1]['sql'], 'for update'), $lecturas[1]['sql']);
        $this->assertSame('confirmada', $borrador->fresh()->estado);
    }

    public function test_guardar_las_lineas_de_un_aguinaldo_toma_los_candados_en_el_mismo_orden_que_confirmarlo(): void
    {
        // Guardar bloqueaba la planilla y, al escribir cada línea, esperaba la ficha de la
        // persona; confirmar bloquea las fichas y después la planilla. Cruzados sobre el
        // mismo aguinaldo, cada uno podía quedar esperando al otro.
        $ana = $this->persona('Ana', 'FICTICIO-1');
        $borrador = $this->abrir('2026-12-15');
        $consultas = $this->consultasEnTransaccion();

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $borrador, [[
            'planilla_empleado_id' => $ana->id, 'salario' => '0.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => '300.00'],
            ],
        ]]);

        $lecturas = array_values(array_filter($consultas->getArrayCopy(), fn (array $c) => str_starts_with($c['sql'], 'select')));
        $this->assertTrue($this->nombra($lecturas[0]['sql'], 'planilla_empleados') && str_contains($lecturas[0]['sql'], 'for update'), $lecturas[0]['sql']);
        $this->assertTrue($this->nombra($lecturas[1]['sql'], 'planillas') && str_contains($lecturas[1]['sql'], 'for update'), $lecturas[1]['sql']);
        $this->assertSame('300.00', (string) PlanillaDetalle::where('planilla_id', $borrador->id)->sole()->a_pagar);
    }

    public function test_guardar_las_lineas_de_una_quincena_no_toma_el_candado_de_todas_las_personas(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        $quincena = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-10-01', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '',
        ]);
        $consultas = $this->consultasEnTransaccion();

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $quincena, [['planilla_empleado_id' => $ana->id, 'salario' => '200.00']]);

        // Las fichas de SUS personas, por id y antes que la planilla (el orden de
        // PrepararPlanilla::bloquearFichas()). Nunca la tabla entera, como el aguinaldo.
        $conCandado = array_filter($consultas->getArrayCopy(),
            fn (array $c) => str_starts_with($c['sql'], 'select') && preg_match('/from [`"]planilla_empleados[`"]/', $c['sql']) === 1 && str_contains($c['sql'], 'for update'));
        $this->assertNotSame([], $conCandado);

        foreach ($conCandado as $consulta) {
            $this->assertMatchesRegularExpression('/where [`"]planilla_empleados[`"]\.[`"]id[`"] in \(/', $consulta['sql']);
        }
    }

    public function test_una_quincena_no_toma_el_candado_de_todas_las_personas(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $consultas = $this->consultasEnTransaccion();

        app(GenerarPlanilla::class)->generar($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-10-01', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '',
        ]);

        // Confirmar bloquea las fichas de SUS personas, por id, para que dos pagos de la misma
        // persona en el mes no se pasen del tope del ISSS. Nunca la tabla entera, como el aguinaldo.
        $conCandado = array_filter($consultas->getArrayCopy(),
            fn (array $c) => $this->nombra($c['sql'], 'planilla_empleados') && str_contains($c['sql'], 'for update'));
        $this->assertNotSame([], $conCandado);

        foreach ($conCandado as $consulta) {
            $this->assertMatchesRegularExpression('/where [`"]planilla_empleados[`"]\.[`"]id[`"] in \(/', $consulta['sql']);
        }
    }
}
