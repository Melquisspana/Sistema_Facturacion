<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\InformeGastos;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\InformePlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El ejemplo del cierre de fase 3, con números redondos a propósito:
 *
 *   Ingresos de planilla     100
 *   Anticipo ya pagado      − 40
 *   ──────────────────────────────
 *   Pago final                60
 *
 * **El total desembolsado son 100**, y tiene que seguir siendo 100 sin importar en qué
 * categoría se archivó el gasto del anticipo.
 *
 * Lo que se defiende acá no es una suma: es que las DOS PREGUNTAS no se mezclen.
 *
 *  - «¿Cuánto debe esta planilla?» → 60. Los 40 ya no se deben: salieron antes.
 *  - «¿Cuánto salió por este período?» → 100. Los 40 cuentan, aunque su pago sea
 *    anterior y viva en su propio gasto.
 *
 * Y el error que esto impide: sumar los 100 de ingresos con los 40 del gasto del
 * anticipo y reportar 140. Los 100 YA CONTIENEN los 40.
 */
class DesembolsoPlanillaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('planilla.enabled', true);
        config()->set('gastos.enabled', true);
        config()->set('asistencia.enabled', false);
    }

    private function gestor(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::PlanillaDocumentos,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::GastosExportar, PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /**
     * Monta el ejemplo completo. `$categoria` es lo único que cambia entre corridas.
     *
     * @return array{planilla: Planilla, empleado: PlanillaEmpleado, usuario: User, pagoAnticipo: Pago}
     */
    private function ejemplo(string $categoria): array
    {
        $u = $this->gestor();
        $empleado = app(RegistrarEmpleado::class)->registrar($u, [
            'nombre' => 'Rosa Alvarenga', 'cargo' => 'Producción',
        ]);

        // ── 1. El anticipo: 40 que salieron ANTES, por Gastos, con su pago real ──
        $gastoAnticipo = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('c', 64),
            'concepto' => 'Anticipo de quincena', 'beneficiario' => 'Rosa Alvarenga',
            'categoria' => $categoria, 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '40.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gastoAnticipo->cuotas()->create(['numero' => 1, 'importe' => '40.00', 'vence' => '2026-09-05']);

        $pagoAnticipo = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '40.00', 'fecha' => '2026-09-05',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Entregado en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '40.00']]);

        $anticipo = app(RegistrarAnticipo::class)->registrar($u, $empleado, [
            'fecha' => '2026-09-05', 'importe' => '40.00', 'moneda' => 'USD',
            'pago_id' => $pagoAnticipo->id, 'referencia' => 'Recibo 148',
        ]);

        // ── 2. La planilla: 100 de ingresos, 40 de anticipo, 60 a pagar ──
        $planilla = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $empleado->id, 'salario' => '100.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo del 5', 'importe' => '40.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh()->load('detalles.conceptos'));

        return [
            'planilla' => $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos'),
            'empleado' => $empleado, 'usuario' => $u, 'pagoAnticipo' => $pagoAnticipo,
        ];
    }

    public function test_la_obligacion_es_60_y_no_100_porque_40_ya_habian_salido(): void
    {
        ['planilla' => $planilla] = $this->ejemplo('Anticipos al personal');

        $detalle = $planilla->detalles->first();
        $this->assertSame('100.00', $detalle->total_ingresos);
        $this->assertSame('60.00', $detalle->a_pagar);
        $this->assertSame('60.00', $detalle->gasto->importe);

        $obligaciones = app(InformePlanilla::class)->obligaciones($planilla);

        // Lo que ESTA planilla comprometió: 60. Ni un centavo más.
        $this->assertSame(6000, $obligaciones['empleados']);
        $this->assertSame(0, $obligaciones['terceros']);
        $this->assertSame(6000, $obligaciones['total']);

        // Los ingresos y los anticipos viajan al lado, marcados como informativos, y
        // JAMÁS dentro de `total`. Sumarlos ahí es exactamente el error de 140.
        $this->assertSame(10000, $obligaciones['total_ingresos']);
        $this->assertSame(4000, $obligaciones['anticipos_aplicados']);
        $this->assertNotSame(
            $obligaciones['total_ingresos'] + $obligaciones['anticipos_aplicados'],
            $obligaciones['total'],
            'El total de obligaciones nunca puede ser ingresos + anticipos: eso cuenta el mismo dinero dos veces.'
        );
    }

    /**
     * La categoría del gasto del anticipo entra por proveedor de datos, no por un bucle
     * dentro de la prueba: cada categoría necesita su base limpia, y eso lo da PHPUnit
     * montando el caso de cero. Rehacer la base a mano dentro del bucle rompe la
     * transacción de `RefreshDatabase` y envenena las pruebas siguientes.
     *
     * @dataProvider categoriasDelAnticipo
     */
    public function test_el_total_desembolsado_es_100_sea_cual_sea_la_categoria_del_anticipo(string $categoria): void
    {
        ['planilla' => $planilla, 'usuario' => $u] = $this->ejemplo($categoria);

        // Todavía no se pagó el sueldo: salieron solo los 40 del anticipo.
        $antes = app(InformePlanilla::class)->desembolsos($planilla);
        $this->assertSame(0, $antes['a_empleados']);
        $this->assertSame(4000, $antes['anticipos']);
        $this->assertSame(4000, $antes['total']);
        $this->assertSame(6000, $antes['pendiente']);

        // ── 3. El pago final: 60 ──
        app(PagarPlanilla::class)->pagarPersona($u, $planilla->detalles->first(), [
            'importe' => '60.00', 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Quincena.',
        ]);

        $despues = app(InformePlanilla::class)->desembolsos(
            $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos')
        );

        // LA CIFRA DEL EJEMPLO: 40 + 60 = 100, con la categoría que sea.
        $this->assertSame(6000, $despues['a_empleados']);
        $this->assertSame(4000, $despues['anticipos']);
        $this->assertSame(10000, $despues['total'], "Desembolsado con categoría {$categoria}");
        $this->assertSame(0, $despues['pendiente']);

        // Y contado por el otro lado —los pagos de verdad de Gastos—, lo mismo.
        $this->assertSame(2, Pago::whereNull('revertido_at')->count());
        $this->assertSame(10000, Pago::whereNull('revertido_at')->get()
            ->sum(fn ($p) => (int) round(((float) $p->importe) * 100)));
    }

    /** @return array<string, array{string}> */
    public static function categoriasDelAnticipo(): array
    {
        return [
            // La correcta, la que se recomienda.
            'archivado como anticipo' => ['Anticipos al personal'],
            // La que parecería duplicar el sueldo en un informe por categoría. No lo
            // hace: el desembolso cuenta el dinero que salió, no la etiqueta.
            'archivado como salario' => ['Salarios'],
        ];
    }

    public function test_el_cuadre_confirma_que_no_falta_ni_sobra_dinero(): void
    {
        ['planilla' => $planilla, 'usuario' => $u] = $this->ejemplo('Anticipos al personal');

        // A medio pagar ya cuadra: lo pendiente está comprometido aunque no haya salido.
        $this->assertTrue(app(InformePlanilla::class)->cuadre($planilla)['cuadra']);

        app(PagarPlanilla::class)->pagarPersona($u, $planilla->detalles->first(), [
            'importe' => '60.00', 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Quincena.',
        ]);

        $cuadre = app(InformePlanilla::class)->cuadre(
            $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos')
        );

        $this->assertTrue($cuadre['cuadra']);
        $this->assertSame(10000, $cuadre['desembolsado']);
        $this->assertSame(10000, $cuadre['total_ingresos']);
        $this->assertSame(0, $cuadre['retenido']);
        $this->assertSame(0, $cuadre['diferencia']);
    }

    public function test_el_informe_de_pagos_y_el_de_obligaciones_no_se_suman(): void
    {
        ['planilla' => $planilla, 'usuario' => $u] = $this->ejemplo('Anticipos al personal');

        app(PagarPlanilla::class)->pagarPersona($u, $planilla->detalles->first(), [
            'importe' => '60.00', 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Quincena.',
        ]);

        $informe = app(InformeGastos::class);

        // INFORME DE PAGOS: el dinero que salió en el período. Los dos pagos, 100.
        $pagos = $informe->pagos($u, '2026-09-01', now()->toDateString());
        $this->assertSame(10000, (int) $pagos->sum(fn ($f) => (int) round(((float) $f->aplicado) * 100)));

        // INFORME DE OBLIGACIONES a fecha de corte: no queda nada pendiente, porque las
        // dos obligaciones —la del anticipo y la de la planilla— están saldadas.
        $pendientes = $informe->pendientes($u, now()->toDateString());
        $this->assertSame(0, (int) $pendientes->sum(fn ($f) => (int) $f->pendiente));

        // Y las obligaciones que EXISTIERON suman 100, no 140: el gasto del anticipo
        // (40) más la obligación de la planilla (60). El total de ingresos de la
        // planilla NO es una tercera obligación.
        $this->assertSame(10000, Gasto::all()->sum(fn ($g) => (int) round(((float) $g->importe) * 100)));
        $this->assertSame(2, Gasto::count());
    }
}
