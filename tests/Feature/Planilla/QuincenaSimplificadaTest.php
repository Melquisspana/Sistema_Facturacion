<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\ComprobanteAdelanto;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La quincena simplificada: sueldo habitual con vigencia, precarga automática, período
 * particular y la resta del comprobante de adelanto.
 *
 * Lo que defiende este archivo es que **no haya que volver a escribir lo mismo cada
 * quince días**, y que ahorrarse ese trabajo no cueste ninguna de estas cuatro cosas:
 *
 *  1. Cambiar un sueldo NO reescribe el pasado. La quincena de marzo se reimprime con
 *     el sueldo de marzo.
 *  2. Ajustar una quincena NO cambia el sueldo habitual. Pagar un día de más en
 *     diciembre no puede subir el sueldo para siempre.
 *  3. Extras y descuentos NO se arrastran. Un extra copiado se paga dos veces.
 *  4. El saldo de adelantos es el PENDIENTE, no la suma de lo adelantado alguna vez.
 *     Confundirlos le reclama a la persona dinero que ya devolvió.
 */
class QuincenaSimplificadaTest extends TestCase
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
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Una persona con su DUI y su importe habitual desde una fecha. */
    private function persona(User $u, string $nombre, string $dui, string $habitual, string $desde): PlanillaEmpleado
    {
        $empleado = app(RegistrarEmpleado::class)->registrar($u, [
            'nombre' => $nombre, 'dui' => $dui, 'cargo' => 'Producción', 'alta_el' => $desde,
        ]);

        app(SueldoHabitual::class)->registrar($u, $empleado, [
            'importe' => $habitual, 'vigente_desde' => $desde,
        ]);

        return $empleado->fresh();
    }

    private function quincena(User $u, string $fecha): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => $fecha,
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => $fecha,
        ])->fresh()->load('detalles.conceptos');
    }

    // ═══════════ 1. El sueldo habitual, guardado una vez ═══════════

    public function test_abrir_una_quincena_carga_sola_a_las_personas_activas_con_su_importe(): void
    {
        $u = $this->gestor();
        $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');
        $this->persona($u, 'Carlos Rivas', '0234 5678-9', '200.00', '2026-01-01');
        $this->persona($u, 'Rosa Alvarenga', '0567 8901-2', '180.00', '2026-01-01');

        $planilla = $this->quincena($u, '2026-09-10');

        // Las tres ya están puestas, con su importe, sin haber escrito nada.
        $this->assertSame(3, $planilla->detalles->count());
        $this->assertSame(
            ['Ana Mendoza' => '225.00', 'Carlos Rivas' => '200.00', 'Rosa Alvarenga' => '180.00'],
            $planilla->detalles->pluck('salario', 'nombre_snapshot')->all()
        );

        // Y el DUI viajó a la foto de la línea, para que el recibo no dependa de la ficha.
        $this->assertSame('0123 4567-8', $planilla->detalles->firstWhere('nombre_snapshot', 'Ana Mendoza')->dui_snapshot);

        // Extras y descuentos, vacíos. Es lo que impide pagar dos veces un extra viejo.
        $this->assertSame(0, DB::table('planilla_conceptos')->count());
    }

    public function test_el_dui_conserva_sus_ceros_de_la_izquierda(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');

        // Guardado como texto: si fuese número, esto sería 12345678.
        $this->assertSame('0123 4567-8', $ana->dui);
        $this->assertSame('0123 4567-8', PlanillaEmpleado::find($ana->id)->dui);
    }

    public function test_cambiar_el_sueldo_no_reescribe_las_quincenas_anteriores(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');

        // Agosto se prepara y se confirma con 225.
        $agosto = $this->quincena($u, '2026-08-10');
        app(ConfirmarPlanilla::class)->confirmar($u, $agosto);
        $this->assertSame('225.00', $agosto->fresh()->detalles->first()->salario);

        // Sube de sueldo a partir del 1 de septiembre.
        app(SueldoHabitual::class)->registrar($u, $ana, [
            'importe' => '250.00', 'vigente_desde' => '2026-09-01', 'motivo' => 'Aumento acordado',
        ]);

        // Septiembre se abre con el nuevo…
        $septiembre = $this->quincena($u, '2026-09-10');
        $this->assertSame('250.00', $septiembre->detalles->first()->salario);

        // …y agosto SIGUE diciendo 225. El papel que firmó en agosto no cambia.
        $this->assertSame('225.00', $agosto->fresh()->detalles->first()->salario);
    }

    public function test_el_importe_vigente_es_el_del_inicio_del_periodo(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');

        // El aumento arranca el 10, a mitad de la quincena del 1 al 15.
        app(SueldoHabitual::class)->registrar($u, $ana, ['importe' => '250.00', 'vigente_desde' => '2026-09-10']);

        $sueldos = app(SueldoHabitual::class);
        $this->assertSame(22500, $sueldos->vigenteEn($ana, '2026-09-01'));
        $this->assertSame(25000, $sueldos->vigenteEn($ana, '2026-09-10'));

        // La quincena del 1 al 15 propone 225: es lo vigente cuando empezó. Si hay que
        // pagar la diferencia, se ajusta esa quincena a mano —que es una excepción— en
        // vez de que el sistema decida por su cuenta partir el período.
        $this->assertSame('225.00', $this->quincena($u, '2026-09-10')->detalles->first()->salario);
    }

    public function test_registrar_dos_veces_el_mismo_dia_corrige_en_vez_de_duplicar(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');

        app(SueldoHabitual::class)->registrar($u, $ana, ['importe' => '250.00', 'vigente_desde' => '2026-09-01']);
        app(SueldoHabitual::class)->registrar($u, $ana, ['importe' => '260.00', 'vigente_desde' => '2026-09-01']);

        // Una sola verdad para el 1 de septiembre.
        $this->assertSame(2, DB::table('planilla_sueldos')->where('planilla_empleado_id', $ana->id)->count());
        $this->assertSame(26000, app(SueldoHabitual::class)->vigenteEn($ana, '2026-09-01'));
    }

    public function test_ajustar_una_quincena_no_cambia_el_sueldo_habitual(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');
        $planilla = $this->quincena($u, '2026-09-10');

        // Esta quincena se le paga menos por una ausencia. Es una excepción del período.
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '190.00', 'conceptos' => []],
        ]);

        $this->assertSame('190.00', $planilla->fresh()->detalles->first()->salario);

        // El habitual no se movió: la quincena siguiente vuelve a proponer 225.
        $this->assertSame(22500, app(SueldoHabitual::class)->vigenteEn($ana, '2026-09-16'));
        $this->assertSame('225.00', $this->quincena($u, '2026-09-20')->detalles->first()->salario);
    }

    // ═══════════ 2. Altas y bajas ═══════════

    public function test_quien_causa_baja_sale_de_las_quincenas_nuevas_y_queda_en_las_viejas(): void
    {
        $u = $this->gestor();
        $ana = $this->persona($u, 'Ana Mendoza', '0123 4567-8', '225.00', '2026-01-01');
        $luis = $this->persona($u, 'Luis Portillo', '0456 7890-1', '180.00', '2026-01-01');

        $agosto = $this->quincena($u, '2026-08-10');
        $this->assertSame(2, $agosto->detalles->count());

        // Luis se va el 5 de septiembre.
        $luis->update(['activo' => false, 'baja_el' => '2026-09-05']);

        // La quincena del 16 al 30 ya no lo propone.
        $septiembreB = $this->quincena($u, '2026-09-20');
        $this->assertSame(['Ana Mendoza'], $septiembreB->detalles->pluck('nombre_snapshot')->all());

        // Y agosto lo conserva entero.
        $this->assertSame(2, $agosto->fresh()->detalles->count());
    }

    public function test_volver_a_dar_de_alta_trae_a_la_misma_persona_no_una_segunda_ficha(): void
    {
        $u = $this->gestor();
        $ana = app(RegistrarEmpleado::class)->registrar($u, [
            'nombre' => 'Ana Mendoza', 'dui' => '0123 4567-8', 'cargo' => 'Ventas',
            'origen_tipo' => 'usuario', 'origen_id' => $u->id,
        ]);
        $ana->update(['activo' => false, 'baja_el' => '2026-09-05']);

        $devuelta = app(RegistrarEmpleado::class)->registrar($u, [
            'nombre' => 'Ana Mendoza', 'origen_tipo' => 'usuario', 'origen_id' => $u->id,
        ]);

        $this->assertSame($ana->id, $devuelta->id);
        $this->assertSame(1, PlanillaEmpleado::count());
        $this->assertTrue($devuelta->activo);
        $this->assertNull($devuelta->baja_el);
    }

    // ═══════════ 3. El período particular ═══════════

    public function test_el_periodo_particular_se_guarda_y_no_calcula_el_importe(): void
    {
        $u = $this->gestor();
        $luis = $this->persona($u, 'Luis Portillo', '0456 7890-1', '180.00', '2026-01-01');
        $planilla = $this->quincena($u, '2026-09-10');

        // Entró el 8: se indican sus fechas y se escribe el importe a mano.
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $luis->id, 'salario' => '96.00',
                'periodo_desde' => '2026-09-08', 'periodo_hasta' => '2026-09-15', 'conceptos' => []],
        ]);

        $detalle = $planilla->fresh()->detalles->first();
        $this->assertSame('2026-09-08', $detalle->periodo_desde->toDateString());
        $this->assertSame('2026-09-15', $detalle->periodo_hasta->toDateString());

        // EL IMPORTE ES EL QUE SE ESCRIBIÓ. Ni 180, ni 180×8/15 = 96 por casualidad:
        // el sistema no reparte nada. Si mañana se acuerda una regla, se agrega como
        // sugerencia; hoy no existe.
        $this->assertSame('96.00', $detalle->salario);
    }

    // ═══════════ 4. La resta del comprobante de adelanto ═══════════

    /** Entrega un adelanto de verdad: un pago de Gastos y su ficha, enlazados. */
    private function adelanto(User $u, PlanillaEmpleado $empleado, string $importe, string $fecha)
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => hash('sha256', $importe.$fecha.$empleado->id),
            'concepto' => 'Adelanto', 'beneficiario' => $empleado->nombre,
            'categoria' => 'Anticipos al personal', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => $importe, 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => $fecha]);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => $importe, 'fecha' => $fecha,
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Adelanto en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => $importe]]);

        return app(RegistrarAnticipo::class)->registrar($u, $empleado, [
            'fecha' => $fecha, 'importe' => $importe, 'moneda' => 'USD', 'pago_id' => $pago->id,
        ]);
    }

    public function test_el_comprobante_suma_el_saldo_pendiente_no_lo_adelantado_alguna_vez(): void
    {
        $u = $this->gestor();
        $nubia = $this->persona($u, 'Nubia Flores', '1012 3456-7', '165.00', '2026-01-01');

        // Se le adelantaron 60 en agosto…
        $viejo = $this->adelanto($u, $nubia, '60.00', '2026-08-05');

        // …y se le descontaron 20 en una quincena confirmada.
        $agosto = $this->quincena($u, '2026-08-20');
        app(PrepararPlanilla::class)->guardarLineas($u, $agosto, [
            ['planilla_empleado_id' => $nubia->id, 'salario' => '165.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '20.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $viejo->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $agosto->fresh()->load('detalles.conceptos'));

        $this->assertSame(4000, $viejo->fresh()->pendiente(), 'Del viejo quedan 40 por recuperar.');

        // Hoy se le entregan 40 más.
        $nuevo = $this->adelanto($u, $nubia, '40.00', '2026-09-12');

        $resta = app(ComprobanteAdelanto::class)->resta($nubia, $nuevo);

        // LA CIFRA DEL ACUERDO: pendiente anterior 40 + este 40 = 80.
        // Por suma de adelantos habría dado 60 + 40 = 100, y le estaría reclamando a
        // Nubia los 20 que ya devolvió.
        $this->assertSame(4000, $resta['pendiente_anterior']);
        $this->assertSame(4000, $resta['este_adelanto']);
        $this->assertSame(8000, $resta['nuevo_saldo']);
        $this->assertNotSame(10000, $resta['nuevo_saldo']);
    }

    public function test_un_anticipo_ya_recuperado_del_todo_no_entra_en_la_cuenta(): void
    {
        $u = $this->gestor();
        $nubia = $this->persona($u, 'Nubia Flores', '1012 3456-7', '165.00', '2026-01-01');

        $saldado = $this->adelanto($u, $nubia, '30.00', '2026-08-05');

        $agosto = $this->quincena($u, '2026-08-20');
        app(PrepararPlanilla::class)->guardarLineas($u, $agosto, [
            ['planilla_empleado_id' => $nubia->id, 'salario' => '165.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '30.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $saldado->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $agosto->fresh()->load('detalles.conceptos'));

        $this->assertSame(0, $saldado->fresh()->pendiente());

        $nuevo = $this->adelanto($u, $nubia, '40.00', '2026-09-12');
        $resta = app(ComprobanteAdelanto::class)->resta($nubia, $nuevo);

        // El saldado desapareció de la cuenta: 0 + 40 = 40.
        $this->assertSame(0, $resta['pendiente_anterior']);
        $this->assertSame(4000, $resta['nuevo_saldo']);
        $this->assertSame(0, $resta['vigentes']->count());
    }

    public function test_entregar_un_adelanto_registra_el_dinero_una_sola_vez(): void
    {
        $u = $this->gestor();
        $nubia = $this->persona($u, 'Nubia Flores', '1012 3456-7', '165.00', '2026-01-01');

        $anticipo = $this->adelanto($u, $nubia, '40.00', '2026-09-12');

        // Un pago y un gasto: el dinero que salió hoy.
        $this->assertSame(1, DB::table('gastos_pagos')->count());
        $this->assertSame(1, Gasto::count());
        $this->assertNotNull($anticipo->pago_id);

        // Descontarlo en la quincena NO crea un segundo movimiento: consume la ficha.
        $septiembre = $this->quincena($u, '2026-09-25');
        app(PrepararPlanilla::class)->guardarLineas($u, $septiembre, [
            ['planilla_empleado_id' => $nubia->id, 'salario' => '165.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '40.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $septiembre->fresh()->load('detalles.conceptos'));

        // Sigue habiendo UN pago. El gasto nuevo es la obligación del sueldo (125), no
        // otro adelanto.
        $this->assertSame(1, DB::table('gastos_pagos')->count());
        $this->assertSame('125.00', $septiembre->fresh()->detalles->first()->a_pagar);
        $this->assertSame(0, $anticipo->fresh()->pendiente());
    }

    public function test_el_folio_del_comprobante_no_se_repite_entre_personas(): void
    {
        $u = $this->gestor();
        $a = $this->persona($u, 'Nubia Flores', '1012 3456-7', '165.00', '2026-01-01');
        $b = $this->persona($u, 'Rafael Sosa', '1123 4567-8', '210.00', '2026-01-01');

        $folios = app(ComprobanteAdelanto::class);
        $uno = $folios->folio($this->adelanto($u, $a, '40.00', '2026-09-12'));
        $dos = $folios->folio($this->adelanto($u, $b, '25.00', '2026-09-12'));

        $this->assertNotSame($uno, $dos);
        $this->assertStringStartsWith('2026-ADEL-', $uno);
    }
}
