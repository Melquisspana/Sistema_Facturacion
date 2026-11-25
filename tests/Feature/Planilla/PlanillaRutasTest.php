<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaLote;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rutas de Planilla que mueven dinero, miradas por su efecto en la base: anular, registrar
 * un anticipo, pagarle a un tercero y pagar por lote.
 *
 * Todo entra por la RUTA, como el botón de la pantalla, y no por el servicio: así se
 * prueban también los candados de la ruta (permisos, el interruptor de los pagos de
 * planilla) y lo que el controlador le pasa al servicio. Datos ficticios.
 */
class PlanillaRutasTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Se preparó con el período equivocado.';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'planilla.enabled' => true, 'planilla.pagos_enabled' => true,
            'gastos.enabled' => true, 'asistencia.enabled' => false,
        ]);
    }

    /** @param  list<PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Quien prepara, confirma, paga y anula: un pago de planilla ES un pago de Gastos. */
    private function gestor(): User
    {
        return $this->usuario([
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::GastosPagosCorregir,
        ]);
    }

    private function empleado(User $u, string $nombre): PlanillaEmpleado
    {
        return app(RegistrarEmpleado::class)->registrar($u, ['nombre' => $nombre, 'cargo' => 'Ventas']);
    }

    private function abrir(User $u, string $fecha = '2026-09-10'): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => null,
        ]);
    }

    /**
     * Una planilla confirmada: dos personas y un descuento para un tercero.
     *
     * La primera persona cobra 260.50 (225.00 + 35.50 de comisión); la segunda, 175.00
     * (200.00 menos 25.00 que van a la cooperativa).
     */
    private function confirmada(User $u, string $fecha = '2026-09-10'): Planilla
    {
        $planilla = $this->abrir($u, $fecha);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $this->empleado($u, 'Empleado de prueba '.$fecha)->id, 'salario' => '225.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '35.50'],
            ]],
            ['planilla_empleado_id' => $this->empleado($u, 'Otro empleado de prueba '.$fecha)->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Cuota de préstamo', 'importe' => '25.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa de prueba'],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        return $planilla->fresh();
    }

    /** @return array<string, mixed> */
    private function datosPago(User $u, array $extra = []): array
    {
        return $extra + [
            'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pago de prueba en caja.',
        ];
    }

    /** @return array{estado: string, pendiente: int, pagado: int} */
    private function estadoDe(PlanillaDetalle $detalle): array
    {
        return app(EstadoPlanilla::class)->deDetalle($detalle->fresh());
    }

    // ─────────────────────────── planilla.anular ───────────────────────────

    public function test_anular_por_la_ruta_extingue_lo_que_se_debia_y_libera_el_anticipo(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Empleado de prueba');
        $anticipo = app(RegistrarAnticipo::class)->registrar($u, $empleado, [
            'fecha' => '2026-09-02', 'importe' => '50.00', 'moneda' => 'USD',
        ]);
        $planilla = $this->abrir($u);
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $empleado->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
                ['tipo' => 'descuento', 'concepto' => 'Cuota', 'importe' => '20.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa de prueba'],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
        $this->assertSame(0, $anticipo->fresh()->pendiente(), 'Confirmada, el anticipo quedó descontado.');

        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planilla.show', $planilla));

        $planilla->refresh();
        $this->assertSame('anulada', $planilla->estado);
        $this->assertSame($u->id, (int) $planilla->anulada_por);
        $this->assertSame(self::MOTIVO, $planilla->motivo_anulacion);
        // Lo que se debía (a la persona y a la cooperativa) se extingue con un ajuste
        // interno, nunca con una nota de crédito, y sin borrar las obligaciones.
        $this->assertSame(2, Gasto::count());
        $this->assertSame(2, DB::table('gastos_ajustes')->where('direccion', 'credito')->where('tipo', 'correccion')->count());
        $this->assertSame(0, DB::table('gastos_ajustes')->where('tipo', 'nota_credito')->count());
        // El anticipo ya no queda descontado en una planilla que no vale: vuelve a estar por recuperar.
        $this->assertSame(5000, $anticipo->fresh()->pendiente());
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'planilla_anulada')->count());

        // La ficha lo dice.
        $this->actingAs($u)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Planilla anulada');
    }

    public function test_anular_sin_motivo_avisa_y_no_toca_nada(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);

        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => '  '])
            ->assertSessionHasErrors('motivo');

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame(0, DB::table('gastos_ajustes')->count());
    }

    public function test_con_un_pago_registrado_no_se_anula(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $detalle = PlanillaDetalle::where('planilla_id', $planilla->id)->orderBy('id')->firstOrFail();

        $this->actingAs($u)->post(route('planilla.pagar.persona', $detalle), $this->datosPago($u, ['importe' => '50.00']))
            ->assertSessionHasNoErrors();

        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])
            ->assertSessionHasErrors('estado');

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame(0, DB::table('gastos_ajustes')->count());
        $this->assertNull(Pago::sole()->revertido_at, 'El pago sigue vigente: primero se revierte, después se anula.');
    }

    public function test_un_borrador_no_se_anula(): void
    {
        $u = $this->gestor();
        $planilla = $this->abrir($u);

        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])
            ->assertSessionHasErrors('estado');

        $this->assertSame('borrador', $planilla->fresh()->estado);
    }

    public function test_anular_dos_veces_no_vuelve_a_ajustar(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);

        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();

        // Tres obligaciones (dos personas y la cooperativa), un ajuste cada una, una sola vez.
        $this->assertSame(3, DB::table('gastos_ajustes')->count());
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'planilla_anulada')->count());
    }

    /**
     * Con `planilla.gestionar` pero sin `planilla.salarios`, anular da 403 y la planilla
     * queda igual. El botón no queda a la vista de esa persona: vive en la ficha, y la
     * ficha ya exige `planilla.salarios`.
     */
    public function test_sin_permiso_de_importes_no_se_anula_ni_se_ve_el_boton(): void
    {
        $planilla = $this->confirmada($this->gestor());
        $sinImportes = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaGestionar]);
        $sinGestionar = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios]);

        $this->actingAs($sinImportes)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])->assertForbidden();
        $this->actingAs($sinImportes)->get(route('planilla.show', $planilla))->assertForbidden();
        $this->actingAs($sinGestionar)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])->assertForbidden();

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame(0, DB::table('gastos_ajustes')->count());
    }

    public function test_anular_una_planilla_que_no_existe_da_404(): void
    {
        $this->actingAs($this->gestor())->post(route('planilla.anular', 999999), ['motivo' => self::MOTIVO])->assertNotFound();
    }

    // ─────────────────────── planilla.anticipos.store ───────────────────────

    /** @return array<string, mixed> */
    private function datosAnticipo(array $extra = []): array
    {
        return $extra + [
            'clave' => (string) Str::uuid(), 'fecha' => '2026-09-02', 'importe' => '60.00', 'moneda' => 'USD',
            'referencia' => 'Recibo de prueba 148',
        ];
    }

    public function test_un_anticipo_por_la_ruta_queda_registrado_y_la_planilla_siguiente_lo_descuenta_una_sola_vez(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Empleado de prueba');

        $this->actingAs($u)->post(route('planilla.anticipos.store', $empleado), $this->datosAnticipo())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planilla.anticipos'))
            ->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso, 'Quedan 60.00 por recuperar'));

        $anticipo = PlanillaAnticipo::sole();
        $this->assertSame($empleado->id, (int) $anticipo->planilla_empleado_id);
        $this->assertSame('60.00', (string) $anticipo->importe);
        $this->assertSame($u->id, (int) $anticipo->registrado_por);
        $this->assertSame(6000, $anticipo->pendiente());

        // La quincena siguiente lo descuenta…
        $primera = $this->abrir($u);
        app(PrepararPlanilla::class)->guardarLineas($u, $primera, [
            ['planilla_empleado_id' => $empleado->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '60.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $primera->fresh());
        $this->assertSame(0, $anticipo->fresh()->pendiente());
        $this->assertSame('140.00', (string) PlanillaDetalle::where('planilla_id', $primera->id)->sole()->a_pagar);

        // …y la otra ya no puede descontarlo de nuevo.
        $segunda = $this->abrir($u, '2026-09-20');
        app(PrepararPlanilla::class)->guardarLineas($u, $segunda, [
            ['planilla_empleado_id' => $empleado->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '60.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]);

        try {
            app(ConfirmarPlanilla::class)->confirmar($u, $segunda->fresh());
            $this->fail('El mismo anticipo no puede descontarse dos veces.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('por recuperar', $e->getMessage());
        }
        $this->assertSame('borrador', $segunda->fresh()->estado);
    }

    public function test_reenviar_el_mismo_formulario_no_registra_dos_anticipos(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Empleado de prueba');
        $datos = $this->datosAnticipo();

        $this->actingAs($u)->post(route('planilla.anticipos.store', $empleado), $datos)->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('planilla.anticipos.store', $empleado), $datos)->assertSessionHasNoErrors();

        $this->assertSame(1, PlanillaAnticipo::count());
        $this->assertSame(6000, app(RegistrarAnticipo::class)->disponibles($empleado)->sum(fn ($a) => $a->pendiente()));
    }

    public function test_sin_permiso_no_se_registra_un_anticipo(): void
    {
        $empleado = $this->empleado($this->gestor(), 'Empleado de prueba');
        $sinGestionar = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios]);
        $sinImportes = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaGestionar]);

        $this->actingAs($sinGestionar)->post(route('planilla.anticipos.store', $empleado), $this->datosAnticipo())->assertForbidden();
        $this->actingAs($sinImportes)->post(route('planilla.anticipos.store', $empleado), $this->datosAnticipo())->assertForbidden();

        $this->assertSame(0, PlanillaAnticipo::count());
    }

    public function test_un_anticipo_para_alguien_que_no_existe_da_404(): void
    {
        $this->actingAs($this->gestor())->post(route('planilla.anticipos.store', 999999), $this->datosAnticipo())->assertNotFound();

        $this->assertSame(0, PlanillaAnticipo::count());
    }

    /**
     * El pago que se elige en «¿Salió por un pago ya registrado?» tiene que ser un pago a
     * esa persona. Si no, se le descuenta del sueldo dinero que se le entregó a otro. La
     * pantalla solo ofrece los pagos a su nombre; la ruta tampoco acepta otro.
     */
    public function test_un_pago_hecho_a_otro_no_se_registra_como_su_anticipo(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Empleado de prueba');
        $pagoAjeno = $this->pagoA($u, 'Proveedor de prueba');

        $this->actingAs($u)->post(route('planilla.anticipos.store', $empleado), $this->datosAnticipo(['pago_id' => $pagoAjeno->id]))
            ->assertSessionHasErrors(['pago_id' => 'Ese pago no se le hizo a Empleado de prueba: no puede ser su anticipo.']);

        $this->assertSame(0, PlanillaAnticipo::count());
    }

    /**
     * La pantalla ofrece solo lo que la ruta acepta. Un pago con el nombre escrito a mano
     * de otra forma («empleado de prueba») no pasa el control de la ruta, así que tampoco
     * se ofrece. En MySQL la lista lo traía (no distingue mayúsculas ni tildes) y al
     * guardar salía «Ese pago no se le hizo a…»; SQLite compara exacto, así que acá esta
     * prueba solo se pone roja en la CI con MySQL.
     */
    public function test_la_pantalla_no_ofrece_un_pago_que_la_ruta_rechaza_por_el_nombre(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Empleado de prueba');
        $suyo = $this->pagoA($u, 'Empleado de prueba');
        $aMano = $this->pagoA($u, 'empleado de prueba');

        $this->actingAs($u)->get(route('planilla.anticipos'))->assertOk()
            ->assertViewHas('candidatosPorEmpleado', fn ($c) => collect($c[$empleado->id])->pluck('id')->all() === [$suyo->id]);

        $this->actingAs($u)->post(route('planilla.anticipos.store', $empleado), $this->datosAnticipo(['pago_id' => $aMano->id]))
            ->assertSessionHasErrors('pago_id');
        $this->assertSame(0, PlanillaAnticipo::count());
    }

    /** Un pago de Gastos, ya registrado, a `$beneficiario`. */
    private function pagoA(User $u, string $beneficiario, string $ambito = 'empresarial', string $nota = 'Pago de prueba en caja.'): Pago
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => $beneficiario, 'concepto' => 'Compra de prueba', 'categoria' => 'Compras',
            'ambito' => $ambito, 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '60.00',
            'documentacion' => 'pendiente', 'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => '60.00', 'vence' => '2026-09-02']);

        return app(RegistrarPago::class)->registrar($u, $this->datosPago($u, [
            'importe' => '60.00', 'fecha' => '2026-09-02', 'sin_comprobante' => $nota,
        ]), [['cuota_id' => $cuota->id, 'importe' => '60.00']]);
    }

    // ─────────────────────── planilla.pagar.tercero ───────────────────────

    public function test_pagarle_al_tercero_por_la_ruta_deja_el_pago_y_la_obligacion_saldada(): void
    {
        $u = $this->gestor();
        $this->confirmada($u);
        $tercero = PlanillaObligacionTercero::sole();

        $this->actingAs($u)->post(route('planilla.pagar.tercero', $tercero), $this->datosPago($u, ['importe' => '25.00']))
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertSessionHas('planilla.aviso', 'Pago registrado para Cooperativa de prueba.');

        $pago = Pago::sole();
        $this->assertSame('25.00', (string) $pago->importe);
        $this->assertSame(
            $tercero->gasto->cuotas()->first()->id,
            (int) DB::table('gastos_pago_aplicaciones')->where('pago_id', $pago->id)->value('cuota_id'),
        );
        $estado = app(EstadoPlanilla::class)->deTercero($tercero->fresh()->load('gasto.cuotas'));
        $this->assertSame('pagado', $estado['estado']);
        $this->assertSame(0, $estado['pendiente']);
    }

    public function test_reenviar_el_pago_al_tercero_no_paga_dos_veces(): void
    {
        $u = $this->gestor();
        $this->confirmada($u);
        $tercero = PlanillaObligacionTercero::sole();
        // Un abono: la segunda vez todavía habría saldo para pagarlo de nuevo.
        $datos = $this->datosPago($u, ['importe' => '10.00']);

        $this->actingAs($u)->post(route('planilla.pagar.tercero', $tercero), $datos)->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('planilla.pagar.tercero', $tercero), $datos)->assertSessionHasNoErrors();

        $this->assertSame(1, Pago::count());
        $this->assertSame(1500, app(EstadoPlanilla::class)->deTercero($tercero->fresh()->load('gasto.cuotas'))['pendiente']);
    }

    public function test_al_tercero_de_una_planilla_anulada_no_se_le_paga(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $this->actingAs($u)->post(route('planilla.anular', $planilla), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();

        $this->actingAs($u)->post(route('planilla.pagar.tercero', PlanillaObligacionTercero::sole()), $this->datosPago($u, ['importe' => '25.00']))
            ->assertSessionHasErrors();

        $this->assertSame(0, Pago::count());
    }

    public function test_con_los_pagos_de_planilla_apagados_el_tercero_da_404(): void
    {
        $u = $this->gestor();
        $this->confirmada($u);
        config(['planilla.pagos_enabled' => false]);

        $this->actingAs($u)->post(route('planilla.pagar.tercero', PlanillaObligacionTercero::sole()), $this->datosPago($u, ['importe' => '25.00']))
            ->assertNotFound();

        $this->assertSame(0, Pago::count());
    }

    public function test_sin_permiso_de_pagar_no_se_le_paga_al_tercero(): void
    {
        $u = $this->gestor();
        $this->confirmada($u);
        $sinPagar = $this->usuario([
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::GastosPagosRegistrar,
        ]);

        $this->actingAs($sinPagar)->post(route('planilla.pagar.tercero', PlanillaObligacionTercero::sole()), $this->datosPago($u, ['importe' => '25.00']))
            ->assertForbidden();
        $this->actingAs($u)->post(route('planilla.pagar.tercero', 999999), $this->datosPago($u, ['importe' => '25.00']))
            ->assertNotFound();

        $this->assertSame(0, Pago::count());
    }

    // ─────────────────────── planilla.pagar.lote ───────────────────────

    public function test_el_lote_por_la_ruta_paga_a_cada_persona_lo_suyo_y_avisa_los_omitidos(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        [$primero, $segundo] = PlanillaDetalle::where('planilla_id', $planilla->id)->orderBy('id')->get()->all();

        // La primera persona ya cobró aparte.
        $this->actingAs($u)->post(route('planilla.pagar.persona', $primero), $this->datosPago($u, ['importe' => '260.50']))
            ->assertSessionHasNoErrors();

        $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $this->datosPago($u, [
            'detalles' => [$primero->id, $segundo->id],
        ]))->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', '1 pago(s) registrados en el lote; 1 se omitieron porque ya no tenían saldo.');

        $this->assertSame(2, Pago::count(), 'Un pago por persona: el de aparte y el del lote.');
        $this->assertSame(1, PlanillaLote::count());
        $this->assertSame(1, DB::table('planilla_lote_pagos')->count());
        $this->assertSame('175.00', (string) Pago::orderByDesc('id')->first()->importe);
        $this->assertSame('pagado', $this->estadoDe($primero)['estado']);
        $this->assertSame('pagado', $this->estadoDe($segundo)['estado']);
    }

    public function test_reenviar_el_mismo_lote_no_paga_dos_veces(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $datos = $this->datosPago($u, ['detalles' => PlanillaDetalle::where('planilla_id', $planilla->id)->pluck('id')->all()]);

        $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $datos)
            ->assertSessionHas('planilla.aviso', '2 pago(s) registrados en el lote.');
        $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $datos)
            ->assertSessionHas('planilla.aviso', '0 pago(s) registrados en el lote; 2 se omitieron porque ya no tenían saldo.');

        $this->assertSame(2, Pago::count());
        $this->assertSame(1, PlanillaLote::count());
        $this->assertSame(['260.50', '175.00'], Pago::orderBy('id')->pluck('importe')->map(fn ($i) => (string) $i)->all());
    }

    /** Una persona de OTRA planilla en `detalles[]` no se paga con este lote. */
    public function test_el_lote_no_paga_a_alguien_de_otra_planilla(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $otra = $this->confirmada($u, '2026-09-20');
        $ajeno = PlanillaDetalle::where('planilla_id', $otra->id)->orderBy('id')->firstOrFail();

        $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $this->datosPago($u, ['detalles' => [$ajeno->id]]))
            ->assertSessionHas('planilla.aviso', '0 pago(s) registrados en el lote; 1 se omitieron porque ya no tenían saldo.');

        $this->assertSame(0, Pago::count());
        $this->assertSame('pendiente', $this->estadoDe($ajeno)['estado']);
    }

    public function test_el_lote_no_paga_un_borrador_ni_una_planilla_anulada(): void
    {
        $u = $this->gestor();
        $anulada = $this->confirmada($u);
        $this->actingAs($u)->post(route('planilla.anular', $anulada), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $borrador = $this->abrir($u, '2026-09-20');
        $detalles = PlanillaDetalle::where('planilla_id', $anulada->id)->pluck('id')->all();

        foreach ([$borrador, $anulada] as $planilla) {
            $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $this->datosPago($u, ['detalles' => $detalles]))
                ->assertSessionHasErrors('lote');
        }

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, PlanillaLote::count());
    }

    public function test_el_lote_sin_permiso_o_con_los_pagos_apagados_no_paga(): void
    {
        $u = $this->gestor();
        $planilla = $this->confirmada($u);
        $datos = $this->datosPago($u, ['detalles' => PlanillaDetalle::where('planilla_id', $planilla->id)->pluck('id')->all()]);
        $sinImportes = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaPagar, PermisoSistema::GastosPagosRegistrar]);

        $this->actingAs($sinImportes)->post(route('planilla.pagar.lote', $planilla), $datos)->assertForbidden();

        config(['planilla.pagos_enabled' => false]);
        $this->actingAs($u)->post(route('planilla.pagar.lote', $planilla), $datos)->assertNotFound();

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, PlanillaLote::count());
    }
}
