<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\CompletarMonto;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\InformeGastos;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Los cuatro candados que se revisaron antes de cerrar fase 3.
 *
 * Cada uno nació de una pregunta concreta, y tres de las cuatro encontraron un agujero
 * de verdad. Se escriben aparte del flujo feliz a propósito: esto no describe cómo se
 * usa el módulo, describe lo que el módulo tiene que impedir.
 *
 *  1. LA PUERTA DE ATRÁS. Las obligaciones de planilla son gastos normales —y tienen
 *     que serlo—, así que quien tenía permisos corrientes de Gastos podía leer los
 *     sueldos y pagarlos entrando por /gastos, sin ningún permiso laboral. Cerrar las
 *     pantallas de Planilla no cerraba nada.
 *
 *  2. EL REINTENTO DEL LOTE. Saltar a quien ya cobró del todo cubre el caso fácil.
 *     No cubre el abono parcial —el importe cambió, así que la misma clave se pedía
 *     con otra cifra y tumbaba el lote entero— ni dos peticiones a la vez.
 *
 *  3. EL ANTICIPO YA DESCONTADO. Revertir ese pago diría que el dinero nunca salió,
 *     mientras la planilla ya se lo descontó a la persona.
 *
 *  4. LA ANULACIÓN. Extingue con un ajuste interno, no con un documento fiscal; y la
 *     obligación del empleado más la del tercero son PARTES del total de ingresos, no
 *     algo que se le sume encima.
 */
class CandadosPlanillaTest extends TestCase
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

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Todos los permisos de planilla y los de Gastos que un pago necesita. */
    private function gestor(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::PlanillaDocumentos,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
    }

    /**
     * El perfil del hueco: puede TODO en Gastos y NADA en Planilla.
     *
     * Es un perfil realista —quien lleva las cuentas de proveedores— y es exactamente
     * quien no tiene por qué saber lo que cobra cada quien.
     */
    private function soloGastos(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosRegistrar, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::GastosPagosCorregir, PermisoSistema::GastosExportar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
    }

    /** Planilla confirmada: Ana cobra 260.50, Carlos 175.00 y la cooperativa 25.00. */
    private function confirmada(User $u): Planilla
    {
        $planilla = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);

        $ana = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Ana Mendoza', 'cargo' => 'Ventas']);
        $carlos = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Carlos Rivas', 'cargo' => 'Ventas']);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '35.50'],
            ]],
            ['planilla_empleado_id' => $carlos->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Cuota de préstamo', 'importe' => '25.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa La Esperanza'],
            ]],
        ]);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh()->load('detalles.conceptos'));

        return $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos');
    }

    /** Un gasto corriente, para comprobar que el candado no se lleva por delante a Gastos. */
    private function gastoCorriente(): Gasto
    {
        $responsable = User::first() ?? User::factory()->create(['activo' => true]);

        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('a', 64),
            'concepto' => 'Alquiler de bodega', 'beneficiario' => 'Inmobiliaria del Valle',
            'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '300.00', 'documentacion' => 'pendiente',
            'responsable_id' => $responsable->id, 'registrado_por' => $responsable->id,
        ]);

        $gasto->cuotas()->create(['numero' => 1, 'importe' => '300.00', 'vence' => '2026-09-20']);

        return $gasto->fresh();
    }

    // ═══════════ 1. La puerta de atrás por /gastos ═══════════

    public function test_sin_permisos_laborales_los_sueldos_no_aparecen_en_el_listado_de_gastos(): void
    {
        $gestor = $this->gestor();
        $this->confirmada($gestor);
        $corriente = $this->gastoCorriente();

        $ajeno = $this->soloGastos();
        $consulta = app(ConsultaGastos::class);

        $visibles = $consulta->base($ajeno, ['pestana' => 'todos'], '2026-09-16')->pluck('id')->all();

        // Ve el alquiler y NADA de la planilla. Tres obligaciones de sueldo existen y
        // ninguna le llega.
        $this->assertSame([$corriente->id], $visibles);

        // Y tampoco puede deducirlas del total, que es la fuga que un filtro al pintar
        // dejaría abierta.
        $totales = $consulta->totales($ajeno, [], '2026-09-16');
        $this->assertSame(1, (int) $totales->sum('cantidad'));
        $this->assertSame(30000, (int) $totales->sum('pendiente'));
    }

    public function test_con_permiso_de_salarios_los_sueldos_si_aparecen_en_gastos(): void
    {
        $gestor = $this->gestor();
        $this->confirmada($gestor);
        $this->gastoCorriente();

        // El mismo listado, con el permiso laboral puesto: cuatro obligaciones.
        $visibles = app(ConsultaGastos::class)->base($gestor, ['pestana' => 'todos'], '2026-09-16')->count();
        $this->assertSame(4, $visibles);
    }

    public function test_la_ficha_de_un_sueldo_da_403_por_la_ruta_de_gastos(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $sueldo = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza')->gasto;

        $this->actingAs($this->soloGastos())
            ->get(route('gastos.show', $sueldo))
            ->assertForbidden();

        // El mismo camino, con permiso laboral, sí abre.
        $this->actingAs($gestor)->get(route('gastos.show', $sueldo))->assertOk();
    }

    public function test_sin_permisos_laborales_no_se_puede_pagar_un_sueldo_por_gastos(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $cuota = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza')->gasto->cuotas->first();

        $this->expectException(HttpException::class);

        app(RegistrarPago::class)->registrar($this->soloGastos(), [
            'clave' => (string) Str::uuid(), 'importe' => '260.50', 'fecha' => now()->toDateString(),
            'metodo' => 'efectivo', 'pagado_por' => $gestor->id, 'sin_comprobante' => 'Por la puerta de atrás.',
        ], [['cuota_id' => $cuota->id, 'importe' => '260.50']]);
    }

    public function test_sin_permisos_laborales_no_se_puede_revertir_un_pago_de_sueldo(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $detalle = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');

        $pago = app(PagarPlanilla::class)->pagarPersona($gestor, $detalle, [
            'importe' => '260.50', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $gestor->id, 'sin_comprobante' => 'Quincena.',
        ]);

        $this->actingAs($this->soloGastos())
            ->post(route('gastos.pagos.revertir', $pago), ['motivo' => 'Prueba de la puerta de atrás.'])
            ->assertForbidden();

        $this->assertNull($pago->fresh()->revertido_at);
    }

    public function test_el_historial_de_pagos_de_gastos_no_muestra_los_pagos_de_sueldo(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $detalle = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');

        app(PagarPlanilla::class)->pagarPersona($gestor, $detalle, [
            'importe' => '260.50', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $gestor->id, 'sin_comprobante' => 'Quincena.',
        ]);

        $ajeno = $this->soloGastos();

        // Ni la fila, ni la fecha, ni el importe: el pago entero desaparece.
        $this->assertSame(0, app(ConsultaGastos::class)->pagosVisibles($ajeno, [])->total());
        $this->assertSame(0, app(ConsultaGastos::class)->totalesPagos($ajeno, [])->count());

        // Para quien sí alcanza los salarios sigue estando.
        $this->assertSame(1, app(ConsultaGastos::class)->pagosVisibles($gestor, [])->total());
    }

    public function test_los_informes_y_la_exportacion_recortan_igual_que_la_pantalla(): void
    {
        $gestor = $this->gestor();
        $this->confirmada($gestor);
        $corriente = $this->gastoCorriente();

        $ajeno = $this->soloGastos();
        $filas = app(InformeGastos::class)->pendientes($ajeno, '2026-09-16');

        // El CSV es la salida más fácil de llevarse: filtra igual o no filtra nada.
        $this->assertSame(1, $filas->count());
        $this->assertSame($corriente->id, (int) $filas->first()->id);

        $this->assertSame(4, app(InformeGastos::class)->pendientes($gestor, '2026-09-16')->count());
    }

    public function test_pagar_un_sueldo_exige_ver_los_salarios_ademas_de_poder_pagar(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $detalle = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');

        // Puede pagar planillas pero no ve los importes. Para declarar que salió dinero
        // hay que saber cuánto era: la ruta pide los dos permisos.
        $aCiegas = $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaPagar,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));

        $this->actingAs($aCiegas)
            ->post(route('planilla.pagar.persona', $detalle), [
                'clave' => (string) Str::uuid(), 'importe' => '260.50', 'fecha' => now()->toDateString(),
                'metodo' => 'efectivo', 'pagado_por' => $gestor->id, 'sin_comprobante' => 'A ciegas.',
            ])
            ->assertForbidden();

        $this->assertSame(0, Pago::count());
    }

    public function test_el_candado_no_estorba_a_gastos_cuando_no_hay_planilla(): void
    {
        // Sin una sola obligación de planilla, el recorte no toca nada y Gastos ve todo
        // lo suyo. Es la comprobación de que el candado no cobra peaje al caso normal.
        $corriente = $this->gastoCorriente();

        $visibles = app(ConsultaGastos::class)
            ->base($this->soloGastos(), ['pestana' => 'todos'], '2026-09-16')->pluck('id')->all();

        $this->assertSame([$corriente->id], $visibles);
    }

    public function test_una_planilla_confirmada_en_la_misma_peticion_ya_queda_protegida(): void
    {
        $gestor = $this->gestor();
        $corriente = $this->gastoCorriente();
        $ajeno = $this->soloGastos();
        $consulta = app(ConsultaGastos::class);

        // Se mira el listado ANTES de que exista la planilla. Si el candado guardara la
        // lista de obligaciones protegidas en memoria, esta primera consulta la dejaría
        // fijada en «no hay ninguna»…
        $this->assertSame([$corriente->id], $consulta->base($ajeno, ['pestana' => 'todos'], '2026-09-16')->pluck('id')->all());

        $this->confirmada($gestor);

        // …y los tres sueldos recién creados se colarían en la siguiente. Por eso el
        // recorte es una subconsulta y no una lista: la evalúa el motor cada vez.
        $this->assertSame([$corriente->id], $consulta->base($ajeno, ['pestana' => 'todos'], '2026-09-16')->pluck('id')->all());
        $this->assertSame(4, $consulta->base($gestor, ['pestana' => 'todos'], '2026-09-16')->count());
    }

    public function test_no_se_puede_extinguir_un_sueldo_con_un_ajuste_desde_gastos(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->confirmada($gestor);
        $cuota = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza')->gasto->cuotas->first();

        // Esto es peor que leer un sueldo: un ajuste al crédito EXTINGUE la deuda sin
        // pagarla. La persona seguiría sin cobrar y el sistema diría que no se le debe.
        // Con TODO lo de Gastos, para que el 403 solo pueda venir del candado laboral y
        // no de un permiso que le falte por otro lado.
        $administrador = $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosAdministrar,
            PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));

        try {
            app(RegistrarAjuste::class)->registrar($administrador, $cuota, [
                'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'nota_credito',
                'importe' => '260.50', 'motivo' => 'Intento de extinguir un sueldo desde Gastos.',
            ]);
            $this->fail('Tenía que rechazarse: no tiene permisos laborales.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, DB::table('gastos_ajustes')->count());

        // Todos los servicios de Gastos —ajustes, completar monto, adjuntar, repetir—
        // pasan por el mismo `AccesoGastos::ver()`. Cerrarlo ahí los cierra todos, y
        // esta prueba está para que se note si alguno deja de pasar por el embudo.
        try {
            app(CompletarMonto::class)->completar($administrador, $cuota->gasto, [
                ['importe' => '10.00', 'vence' => '2026-09-20'],
            ]);
            $this->fail('Completar monto tenía que rechazarse igual.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ═══════════ 2. El reintento del lote ═══════════

    public function test_reintentar_un_lote_tras_un_abono_parcial_no_vuelve_a_pagar(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $ids = $planilla->detalles->pluck('id')->all();

        $datos = ['clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Lote.'];

        // Ana ya había cobrado 100 por otra vía: el lote le paga los 160.50 que faltan.
        $ana = $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza');
        app(PagarPlanilla::class)->pagarPersona($u, $ana, [
            'importe' => '100.00', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Adelanto de la quincena.',
        ]);

        $primera = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);
        $this->assertSame(2, $primera['pagos']);
        $this->assertSame(3, Pago::count());

        // Y ahora la parte que fallaba: se revierte ese abono de 100, así que el saldo
        // de Ana vuelve a moverse. Reintentar el lote pedía el MISMO pago con OTRO
        // importe y Gastos lo rechazaba tumbando el lote entero —incluidos los que no
        // habían cobrado—. Preguntando por la clave, esto es simplemente un repetido.
        $abono = Pago::where('importe', '100.00')->firstOrFail();
        app(RegistrarPago::class)->revertir($u, $abono, 'Se registró por error en la caja chica.');

        $segunda = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);

        $this->assertSame(0, $segunda['pagos']);
        $this->assertSame(2, $segunda['repetidos']);
        // Ni un pago más: el lote ya había atendido a las dos personas.
        $this->assertSame(3, Pago::count());
    }

    public function test_un_pago_del_lote_que_ya_existe_se_reconoce_por_su_clave(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $ids = $planilla->detalles->pluck('id')->all();
        $datos = ['clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Lote.'];

        app(PagarPlanilla::class)->pagarLote($u, $planilla, $datos, $ids);

        // Se revierten TODOS los pagos del lote: el saldo vuelve entero y, mirando solo
        // el pendiente, el lote creería que no ha pagado a nadie. La clave dice que sí.
        foreach (Pago::all() as $pago) {
            app(RegistrarPago::class)->revertir($u, $pago, 'Se rehace la transferencia por el banco.');
        }

        $segunda = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);

        $this->assertSame(0, $segunda['pagos']);
        $this->assertSame(2, $segunda['repetidos']);
        $this->assertSame(2, Pago::count());
    }

    public function test_el_vinculo_del_lote_no_se_duplica_al_reintentar(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $ids = $planilla->detalles->pluck('id')->all();
        $datos = ['clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Lote.'];

        app(PagarPlanilla::class)->pagarLote($u, $planilla, $datos, $ids);
        app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);
        app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), $datos, $ids);

        $this->assertSame(1, DB::table('planilla_lotes')->count());
        $this->assertSame(2, DB::table('planilla_lote_pagos')->count());
    }

    public function test_dos_lotes_distintos_si_pueden_completar_lo_que_el_primero_dejo(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $ids = $planilla->detalles->pluck('id')->all();

        // Un lote con SU clave paga. Otro lote —otra tanda, otra clave— sobre la misma
        // gente no debe pagar nada, porque ya no queda saldo. La idempotencia por clave
        // no puede convertirse en un pase libre para una segunda tanda.
        app(PagarPlanilla::class)->pagarLote($u, $planilla, [
            'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Primera tanda.',
        ], $ids);

        $otro = app(PagarPlanilla::class)->pagarLote($u, $planilla->fresh()->load('detalles.gasto.cuotas'), [
            'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Segunda tanda.',
        ], $ids);

        $this->assertSame(0, $otro['pagos']);
        $this->assertSame(2, $otro['omitidos']);
        $this->assertSame(0, $otro['repetidos']);
        $this->assertSame(2, Pago::count());
    }

    // ═══════════ 3. El anticipo ya descontado ═══════════

    /**
     * @return array{planilla: Planilla, anticipo: PlanillaAnticipo, pago: Pago}
     */
    private function anticipoDescontado(User $u): array
    {
        // El anticipo salió por Gastos: hay un pago real detrás.
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('b', 64),
            'concepto' => 'Anticipo de quincena', 'beneficiario' => 'Ana Mendoza',
            'categoria' => 'Anticipos al personal', 'ambito' => 'empresarial',
            'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '50.00',
            'documentacion' => 'pendiente', 'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => '50.00', 'vence' => '2026-09-05']);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-09-05',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Entregado en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '50.00']]);

        $planilla = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);
        $ana = PlanillaEmpleado::where('nombre', 'Ana Mendoza')->first()
            ?? app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Ana Mendoza', 'cargo' => 'Ventas']);

        $anticipo = app(RegistrarAnticipo::class)->registrar($u, $ana, [
            'fecha' => '2026-09-05', 'importe' => '50.00', 'moneda' => 'USD',
            'pago_id' => $pago->id, 'referencia' => 'Recibo 148',
        ]);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo del 5', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh()->load('detalles.conceptos'));

        return [
            'planilla' => $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos'),
            'anticipo' => $anticipo->fresh(),
            'pago' => $pago->fresh(),
        ];
    }

    public function test_no_se_revierte_el_pago_de_un_anticipo_ya_descontado(): void
    {
        $u = $this->gestor();
        ['pago' => $pago, 'anticipo' => $anticipo] = $this->anticipoDescontado($u);

        // La planilla ya lo descontó: hay una aplicación viva contra este anticipo.
        $this->assertSame(1, DB::table('planilla_anticipo_aplicaciones')
            ->where('planilla_anticipo_id', $anticipo->id)->count());

        try {
            app(RegistrarPago::class)->revertir($u, $pago, 'Me equivoqué al registrar la salida de caja.');
            $this->fail('Revertir tenía que rechazarse: el anticipo ya está descontado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('anticipo', mb_strtolower($e->getMessage()));
        }

        // Y no se movió nada: el dinero sigue declarado como salido.
        $this->assertNull($pago->fresh()->revertido_at);
    }

    public function test_tras_anular_la_planilla_el_anticipo_se_libera_y_el_pago_ya_se_revierte(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla, 'pago' => $pago, 'anticipo' => $anticipo] = $this->anticipoDescontado($u);

        // Anular libera las aplicaciones: el anticipo vuelve a estar por recuperar.
        app(AnularPlanilla::class)->anular($u, $planilla, 'Se confirmó la quincena equivocada.');

        $this->assertSame(0, DB::table('planilla_anticipo_aplicaciones')
            ->where('planilla_anticipo_id', $anticipo->id)->count());

        // Y recién ahora se puede deshacer el pago, que es el orden correcto: primero
        // se desata el descuento, después se dice que el dinero no salió.
        app(RegistrarPago::class)->revertir($u, $pago, 'Me equivoqué al registrar la salida de caja.');

        $this->assertNotNull($pago->fresh()->revertido_at);
    }

    public function test_un_pago_corriente_se_revierte_sin_estorbo(): void
    {
        $u = $this->gestor();
        $gasto = $this->gastoCorriente();
        $cuota = $gasto->cuotas()->first();

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '300.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'pagado_por' => $u->id, 'sin_comprobante' => 'Alquiler.',
        ], [['cuota_id' => $cuota->id, 'importe' => '300.00']]);

        app(RegistrarPago::class)->revertir($u, $pago, 'La transferencia rebotó en el banco.');

        $this->assertNotNull($pago->fresh()->revertido_at);
    }

    // ═══════════ 4. Anular: ajuste interno y sin dinero duplicado ═══════════

    public function test_anular_usa_un_ajuste_interno_y_nunca_una_nota_de_credito(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);

        app(AnularPlanilla::class)->anular($u, $planilla, 'La quincena se confirmó con el salario viejo de Carlos.');

        $ajustes = DB::table('gastos_ajustes')->get();

        // Tres obligaciones extinguidas, tres ajustes. Y ni uno solo es un documento
        // fiscal: acá no se le emite nada a nadie, la empresa se corrige a sí misma.
        $this->assertSame(3, $ajustes->count());
        $this->assertSame(['correccion'], $ajustes->pluck('tipo')->unique()->values()->all());
        $this->assertSame(['credito'], $ajustes->pluck('direccion')->unique()->values()->all());
        $this->assertSame(0, DB::table('gastos_ajustes')->where('tipo', 'nota_credito')->count());

        // Con su motivo, que es lo que hace el rastro utilizable dentro de un mes.
        foreach ($ajustes as $ajuste) {
            $this->assertStringStartsWith('Planilla anulada: ', $ajuste->motivo);
        }

        // Nada se borró: las obligaciones siguen ahí, con saldo cero.
        $this->assertSame(3, Gasto::count());
        $this->assertSame('anulada', $planilla->fresh()->estado);
    }

    public function test_la_obligacion_del_empleado_y_la_del_tercero_no_duplican_el_gasto_salarial(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);

        $ingresos = 0;
        $aEmpleados = 0;

        foreach ($planilla->detalles as $detalle) {
            $ingresos += Dinero::centavos($detalle->total_ingresos);
            $aEmpleados += Dinero::centavos((string) $detalle->gasto->importe);
        }

        $aTerceros = PlanillaObligacionTercero::all()
            ->sum(fn ($o) => Dinero::centavos((string) $o->gasto->importe));

        // 260.50 + 175.00 al personal, 25.00 a la cooperativa. El total de ingresos de
        // la planilla es 460.50, y las obligaciones suman exactamente eso: el descuento
        // a tercero es una PARTE del sueldo que se desvía, no un gasto adicional.
        $this->assertSame(43550, $aEmpleados);
        $this->assertSame(2500, $aTerceros);
        $this->assertSame(46050, $ingresos);
        $this->assertSame($ingresos, $aEmpleados + $aTerceros);

        // Dicho de la forma en que dolería que fallara: el dinero comprometido nunca
        // puede superar lo que la planilla declara como ingresos.
        $this->assertLessThanOrEqual($ingresos, $aEmpleados + $aTerceros);
    }

    public function test_un_anticipo_descontado_no_se_vuelve_a_comprometer_como_obligacion(): void
    {
        $u = $this->gestor();
        ['planilla' => $planilla] = $this->anticipoDescontado($u);

        $detalle = $planilla->detalles->first();

        // Salario 225, anticipo descontado 50: la obligación con Ana es de 175. Los 50
        // ya salieron antes por su propio pago y NO se vuelven a deber.
        $this->assertSame('225.00', $detalle->total_ingresos);
        $this->assertSame('175.00', $detalle->a_pagar);
        $this->assertSame('175.00', $detalle->gasto->importe);

        // Y no se generó ninguna obligación de tercero por el anticipo: un anticipo no
        // es dinero que se le deba a nadie más.
        $this->assertSame(0, PlanillaObligacionTercero::count());
    }
}
