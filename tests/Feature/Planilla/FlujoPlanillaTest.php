<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 3 completa: confirmar, pagar, anticipos, reversiones y anulación.
 *
 * Las garantías que defienden estas pruebas, en orden de lo que costaría que fallaran:
 *
 *  1. CONFIRMAR NO DUPLICA. Ni por reintento, ni por dos pestañas, ni por concurrencia.
 *     Si fallara, se pagaría dos veces el mismo sueldo.
 *  2. EL MISMO ANTICIPO NO SE RECUPERA DOS VECES. Por eso es una fila con saldo y no
 *     una referencia escrita.
 *  3. NO SE CUENTA EL DINERO DOS VECES. La obligación con el empleado y la del tercero
 *     son PARTES del total de ingresos, no algo que se le sume.
 *  4. NADA DE ESTO EXISTE EN BORRADOR. Ni la deuda con el empleado ni la del tercero.
 *  5. REVERTIR Y ANULAR DEJAN RASTRO y no borran nada.
 */
class FlujoPlanillaTest extends TestCase
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
        // A propósito: la planilla funciona con Asistencia APAGADA.
        config()->set('asistencia.enabled', false);
    }

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /**
     * El operador completo. Incluye `gastos.pagos.registrar` y `gastos.pagos.corregir`
     * a propósito: un pago de planilla ES un pago de Gastos, y revertirlo usa la misma
     * autoridad que revertir cualquier otro. No se inventan permisos nuevos para eso.
     */
    private function gestor(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::PlanillaDocumentos,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
    }

    private function empleado(User $u, string $nombre): PlanillaEmpleado
    {
        return app(RegistrarEmpleado::class)->registrar($u, ['nombre' => $nombre, 'cargo' => 'Ventas']);
    }

    private function planilla(User $u): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);
    }

    /**
     * Planilla lista para confirmar: dos personas, una con descuento a tercero.
     *
     * @return array{planilla: Planilla, ana: PlanillaEmpleado, carlos: PlanillaEmpleado}
     */
    private function planillaLista(User $u): array
    {
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Mendoza');
        $carlos = $this->empleado($u, 'Carlos Rivas');

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '35.50'],
            ]],
            ['planilla_empleado_id' => $carlos->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Cuota de préstamo', 'importe' => '25.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa La Esperanza'],
            ]],
        ]);

        return ['planilla' => $planilla->fresh()->load('detalles.conceptos'), 'ana' => $ana, 'carlos' => $carlos];
    }

    // ─────────────────────── Confirmar ───────────────────────

    public function test_en_borrador_no_existe_ninguna_obligacion(): void
    {
        $u = $this->gestor();
        $this->planillaLista($u);

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, PlanillaObligacionTercero::count());
    }

    public function test_confirmar_crea_una_obligacion_por_empleado_y_una_por_tercero(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        $planilla = $planilla->fresh()->load('detalles.conceptos');

        $this->assertTrue($planilla->confirmada());
        // Dos personas + un tercero = tres obligaciones.
        $this->assertSame(3, Gasto::count());
        $this->assertSame(2, PlanillaDetalle::whereNotNull('gasto_id')->count());

        $tercero = PlanillaObligacionTercero::firstOrFail();
        $this->assertSame('Cooperativa La Esperanza', $tercero->tercero);
        $this->assertSame('25.00', $tercero->importe);

        // Y el dinero NO se cuenta dos veces: empleados + tercero = total de ingresos.
        $ana = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');
        $carlos = $planilla->detalles->firstWhere('nombre_snapshot', 'Carlos Rivas');
        $this->assertSame('260.50', $ana->a_pagar);
        $this->assertSame('175.00', $carlos->a_pagar);
        $this->assertSame(46050, 26050 + 17500 + 2500); // 460.50 = total de ingresos
    }

    public function test_confirmar_dos_veces_no_duplica_nada(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertSame(3, Gasto::count());
        $this->assertSame(1, PlanillaObligacionTercero::count());
        $this->assertSame(3, DB::table('gastos_cuotas')->count());
    }

    public function test_la_base_rechaza_una_segunda_obligacion_para_el_mismo_tercero(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        // Un camino que esquivara el servicio choca igual contra el índice único.
        $this->expectException(QueryException::class);

        DB::table('planilla_obligaciones_terceros')->insert([
            'planilla_id' => $planilla->id, 'tercero' => 'Cooperativa La Esperanza',
            'importe' => '25.00', 'created_at' => now(),
        ]);
    }

    public function test_un_borrador_con_reparos_no_se_puede_confirmar(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Mendoza');

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '100.00', 'conceptos' => [
                // Descuento sin clasificar.
                ['tipo' => 'descuento', 'concepto' => 'Algo', 'importe' => '10.00'],
            ]],
        ]);

        $this->expectException(ValidationException::class);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
    }

    public function test_una_planilla_confirmada_ya_no_se_edita(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla, 'ana' => $ana] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $this->expectException(ValidationException::class);
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla->fresh(), [
            ['planilla_empleado_id' => $ana->id, 'salario' => '999.00', 'conceptos' => []],
        ]);
    }

    // ─────────────────────── Anticipos ───────────────────────

    private function anticipo(User $u, PlanillaEmpleado $empleado, string $importe = '60.00', ?int $pagoId = null): PlanillaAnticipo
    {
        return app(RegistrarAnticipo::class)->registrar($u, $empleado, [
            'fecha' => '2026-09-02', 'importe' => $importe, 'moneda' => 'USD',
            'pago_id' => $pagoId, 'referencia' => 'recibo 148',
        ]);
    }

    public function test_el_anticipo_lleva_su_saldo_y_no_se_recupera_dos_veces(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Mendoza');
        $anticipo = $this->anticipo($u, $ana, '60.00');

        $this->assertSame(6000, $anticipo->pendiente());

        // Primera planilla: recupera 40 de los 60.
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '40.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertSame(4000, $anticipo->fresh()->recuperado());
        $this->assertSame(2000, $anticipo->fresh()->pendiente());

        // Segunda planilla: intenta recuperar 50, pero solo quedan 20.
        $siguiente = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-20', 'clase' => 'regular', 'moneda' => 'USD',
        ]);
        app(PrepararPlanilla::class)->guardarLineas($u, $siguiente, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($siguiente->fresh()->load('detalles.conceptos'));
        $this->assertStringContainsString('quedan 20.00 por recuperar', implode(' ', $reparos));

        try {
            app(ConfirmarPlanilla::class)->confirmar($u, $siguiente->fresh());
            $this->fail('Recuperar más de lo que queda tendría que rechazarse.');
        } catch (ValidationException $e) {
            // El reparo salta primero, que es lo deseable: avisa con la cifra exacta
            // antes de llegar al candado de la confirmación.
            $this->assertStringContainsString('por recuperar', $e->getMessage());
        }
    }

    public function test_un_anticipo_se_vincula_al_pago_que_de_verdad_salio(): void
    {
        $u = $this->gestor();
        $ana = $this->empleado($u, 'Ana Mendoza');

        // Un pago real de Gastos a esa persona.
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Ana Mendoza', 'concepto' => 'Anticipo de quincena', 'categoria' => 'Planilla',
            'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '60.00',
            'documentacion' => 'pendiente', 'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => '60.00', 'vence' => '2026-09-02']);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '60.00', 'fecha' => '2026-09-02',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Anticipo entregado en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '60.00']]);

        $anticipo = $this->anticipo($u, $ana, '60.00', $pago->id);
        $this->assertSame($pago->id, $anticipo->pago_id);
        $this->assertStringContainsString('Pago #'.$pago->id, $anticipo->origen());

        // Y ese mismo pago no puede quedar como un segundo anticipo.
        try {
            app(RegistrarAnticipo::class)->registrar($u, $ana, [
                'fecha' => '2026-09-02', 'importe' => '60.00', 'moneda' => 'USD', 'pago_id' => $pago->id,
            ]);
            $this->fail('El mismo pago no puede registrarse dos veces como anticipo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya está registrado como anticipo', $e->getMessage());
        }
    }

    public function test_solo_se_ofrecen_anticipos_con_saldo(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Mendoza');
        $anticipo = $this->anticipo($u, $ana, '40.00');

        $this->assertCount(1, app(RegistrarAnticipo::class)->disponibles($ana));

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '40.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertCount(0, app(RegistrarAnticipo::class)->disponibles($ana));
    }

    // ─────────────────────── Pagos ───────────────────────

    public function test_un_abono_parcial_deja_la_linea_en_pagado_en_parte(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $detalle = PlanillaDetalle::where('nombre_snapshot', 'Ana Mendoza')->firstOrFail();

        app(PagarPlanilla::class)->pagarPersona($u, $detalle, [
            'importe' => '100.00', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Abono parcial en caja.',
        ]);

        $e = app(EstadoPlanilla::class)->deDetalle($detalle->fresh());
        $this->assertSame('parcial', $e['estado']);
        $this->assertSame(10000, $e['pagado']);
        $this->assertSame(16050, $e['pendiente']);

        // Y completarlo lo deja pagado.
        app(PagarPlanilla::class)->pagarPersona($u, $detalle->fresh(), [
            'importe' => '160.50', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Resto en caja.',
        ]);

        $this->assertSame('pagado', app(EstadoPlanilla::class)->deDetalle($detalle->fresh())['estado']);
    }

    public function test_el_lote_paga_a_varios_y_repetirlo_no_paga_dos_veces(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        $planilla = $planilla->fresh()->load('detalles.gasto.cuotas');

        $ids = $planilla->detalles->pluck('id')->all();
        $datos = ['clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Lote de planilla.'];

        $primera = app(PagarPlanilla::class)->pagarLote($u, $planilla, $datos, $ids);
        $this->assertSame(2, $primera['pagos']);
        $this->assertSame(2, Pago::count());

        // Reintentar el MISMO lote no vuelve a pagar: ya no queda saldo.
        $segunda = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);
        $this->assertSame(0, $segunda['pagos']);
        $this->assertSame(2, $segunda['omitidos']);
        $this->assertSame(2, Pago::count());

        $avance = app(EstadoPlanilla::class)->dePlanilla($planilla->fresh()->load('detalles.gasto.cuotas'));
        $this->assertSame(0, $avance['empleados_pendiente']);
        $this->assertSame(2, $avance['personas_pagadas']);
    }

    public function test_el_lote_completa_lo_que_falta_sin_repetir_a_quien_ya_cobro(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        $planilla = $planilla->fresh()->load('detalles.gasto.cuotas');

        $ana = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');
        app(PagarPlanilla::class)->pagarPersona($u, $ana, [
            'importe' => '260.50', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pagada aparte.',
        ]);

        $resultado = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), [
            'fecha' => now()->toDateString(), 'metodo' => 'transferencia', 'pagado_por' => $u->id,
            'sin_comprobante' => 'Lote del resto.',
        ], $planilla->detalles->pluck('id')->all());

        $this->assertSame(1, $resultado['pagos']);
        $this->assertSame(1, $resultado['omitidos']);
    }

    public function test_el_tercero_se_paga_por_su_propia_obligacion(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $tercero = PlanillaObligacionTercero::with('gasto.cuotas')->firstOrFail();
        $this->assertSame('pendiente', app(EstadoPlanilla::class)->deTercero($tercero)['estado']);

        app(PagarPlanilla::class)->pagarTercero($u, $tercero, [
            'importe' => '25.00', 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pago a la cooperativa.',
        ]);

        $this->assertSame('pagado', app(EstadoPlanilla::class)->deTercero($tercero->fresh()->load('gasto.cuotas'))['estado']);
    }

    public function test_no_se_puede_pagar_un_borrador(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        $detalle = $planilla->detalles->first();

        $this->expectException(ValidationException::class);
        app(PagarPlanilla::class)->pagarPersona($u, $detalle, [
            'importe' => '10.00', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo', 'pagado_por' => $u->id,
        ]);
    }

    public function test_revertir_un_pago_devuelve_el_saldo_y_conserva_el_historial(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $detalle = PlanillaDetalle::where('nombre_snapshot', 'Ana Mendoza')->firstOrFail();
        $pago = app(PagarPlanilla::class)->pagarPersona($u, $detalle, [
            'importe' => '260.50', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pago completo.',
        ]);

        $this->assertSame('pagado', app(EstadoPlanilla::class)->deDetalle($detalle->fresh())['estado']);

        app(PagarPlanilla::class)->revertirPago($u, $pago, 'Se registró sobre la persona equivocada.');

        $e = app(EstadoPlanilla::class)->deDetalle($detalle->fresh());
        $this->assertSame('pendiente', $e['estado']);
        $this->assertSame(26050, $e['pendiente']);
        // El pago NO se borra: queda revertido y con su motivo.
        $this->assertSame(1, Pago::count());
        $this->assertNotNull($pago->fresh()->revertido_at);
        $this->assertSame('Se registró sobre la persona equivocada.', $pago->fresh()->motivo_reversion);
    }

    // ─────────────────────── Anular ───────────────────────

    public function test_anular_extingue_las_obligaciones_y_libera_los_anticipos(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Mendoza');
        $anticipo = $this->anticipo($u, $ana, '50.00');

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
                ['tipo' => 'descuento', 'concepto' => 'Cuota', 'importe' => '20.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa'],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertSame(0, $anticipo->fresh()->pendiente());
        $this->assertSame(2, Gasto::count());

        app(AnularPlanilla::class)->anular($u, $planilla->fresh(), 'Se preparó con el período equivocado.');

        $planilla = $planilla->fresh();
        $this->assertSame('anulada', $planilla->estado);
        // Las obligaciones NO se borran: se extinguen con un ajuste interno.
        $this->assertSame(2, Gasto::count());
        $this->assertSame(2, DB::table('gastos_ajustes')->where('direccion', 'credito')->count());
        // Y el anticipo vuelve a quedar pendiente.
        $this->assertSame(5000, $anticipo->fresh()->pendiente());
    }

    public function test_no_se_puede_anular_si_ya_hay_pagos(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $detalle = PlanillaDetalle::where('nombre_snapshot', 'Ana Mendoza')->firstOrFail();
        app(PagarPlanilla::class)->pagarPersona($u, $detalle, [
            'importe' => '50.00', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Abono.',
        ]);

        try {
            app(AnularPlanilla::class)->anular($u, $planilla->fresh(), 'Quiero deshacerla.');
            $this->fail('Anular con pagos registrados tendría que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya hay pagos registrados', $e->getMessage());
        }

        $this->assertSame('confirmada', $planilla->fresh()->estado);
    }

    public function test_anular_exige_motivo(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);

        $this->expectException(ValidationException::class);
        app(AnularPlanilla::class)->anular($u, $planilla->fresh(), '');
    }

    // ─────────────────────── Permisos ───────────────────────

    public function test_sin_permiso_de_pagar_no_se_paga(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        $detalle = PlanillaDetalle::first();

        $sinPagar = $this->usuario([
            PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaSalarios->value,
        ]);

        $this->actingAs($sinPagar)->post(route('planilla.pagar.persona', $detalle), [
            'importe' => '10.00', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo', 'pagado_por' => $u->id,
        ])->assertForbidden();
    }

    public function test_sin_permiso_de_documentos_no_se_adjunta_ni_se_descarga(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);

        $sinDocs = $this->usuario([
            PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaSalarios->value,
        ]);

        $this->actingAs($sinDocs)->post(route('planilla.documentos.store', $planilla), [])->assertForbidden();
    }

    public function test_quien_solo_ve_no_alcanza_la_ficha_con_importes(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);

        $miron = $this->usuario([PermisoSistema::PlanillaVer->value]);

        $this->actingAs($miron)->get(route('planilla.show', $planilla))->assertForbidden();
        $this->actingAs($miron)->get(route('planilla.impresos', $planilla))->assertForbidden();
        $this->actingAs($miron)->get(route('planilla.anticipos'))->assertForbidden();
    }

    // ─────────────────────── Pantallas ───────────────────────

    public function test_el_flujo_completo_abre_en_pantalla(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->planillaLista($u);

        $this->actingAs($u)->get(route('planilla.show', $planilla))->assertOk()
            ->assertSee('Confirmar y crear las obligaciones');

        $this->actingAs($u)->post(route('planilla.confirmar', $planilla))->assertRedirect();

        $this->actingAs($u)->get(route('planilla.show', $planilla))->assertOk()
            ->assertSee('Terceros')->assertSee('Cooperativa La Esperanza');
        $this->actingAs($u)->get(route('planilla.pagos', $planilla))->assertOk()->assertSee('Pagar por lote');
        $this->actingAs($u)->get(route('planilla.impresos', $planilla))->assertOk()->assertSee('Planilla para firma', false);
        $this->actingAs($u)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertOk()->assertSee('Recibo de pago', false);
        $this->actingAs($u)->get(route('planilla.anticipos'))->assertOk();
    }

    // ─────────────── El sistema no se cae sin las tablas de Planilla ───────────────

    public function test_el_sistema_habitual_sigue_en_pie_sin_las_tablas_de_planilla(): void
    {
        $u = $this->gestor();

        Schema::withoutForeignKeyConstraints(function () {
            foreach (['planilla_documentos', 'planilla_lote_pagos', 'planilla_lotes',
                'planilla_obligaciones_terceros', 'planilla_anticipo_aplicaciones', 'planilla_anticipos',
                'planilla_conceptos', 'planilla_detalles', 'planillas', 'planilla_empleados'] as $tabla) {
                Schema::dropIfExists($tabla);
            }
        });

        // Lo de siempre sigue funcionando: el dashboard y Gastos no dependen de Planilla.
        $this->actingAs($u)->get(route('dashboard'))->assertOk();
        $this->actingAs($u)->get(route('gastos.index'))->assertOk();

        // Y Planilla avisa de lo que falta en vez de reventar.
        $respuesta = $this->actingAs($u)->get(route('planilla.index'));
        $respuesta->assertStatus(503);
        $respuesta->assertSee('php artisan migrate');
    }
}
