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
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un mes de planilla como lo hace la oficina, todo por las rutas de los botones: el
 * anticipo, generar la quincena, pagar el lote, pagarle al ISSS y a la AFP, anular con y
 * sin pagos y volver a armarla. Con dobles clics y con quien no tiene los permisos.
 * Datos ficticios.
 */
class MesDePlanillaPorLasRutasTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Se preparó con el período equivocado.';

    private User $oficina;

    private PlanillaEmpleado $conDescuentos;

    private PlanillaEmpleado $sinDescuentos;

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

        $this->oficina = $this->usuario([
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::GastosPagosCorregir,
        ]);

        $this->conDescuentos = app(AltaEmpleado::class)->registrar($this->oficina, [
            'nombres' => 'Empleado', 'apellidos' => 'de prueba', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
        ]);
        $this->sinDescuentos = app(AltaEmpleado::class)->registrar($this->oficina, [
            'nombres' => 'Otra', 'apellidos' => 'empleada de prueba', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
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

    /** @return array<string, string> */
    private function periodo(string $fecha): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    /** @return array<string, mixed> */
    private function datosPago(array $extra = []): array
    {
        return $extra + [
            'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $this->oficina->id, 'sin_comprobante' => 'Pago de prueba en caja.',
        ];
    }

    /** «Generar» y, como se frena por el anticipo, «Preparar» con el anticipo elegido en la línea. */
    private function generarConAnticipo(string $fecha, PlanillaAnticipo $anticipo): Planilla
    {
        $this->actingAs($this->oficina)->post(route('planilla.store'), $this->periodo($fecha))->assertSessionHasNoErrors();
        $planilla = Planilla::latest('id')->firstOrFail();
        $this->assertSame('borrador', $planilla->estado, 'Con un anticipo por descontar, la planilla espera a que alguien lo elija.');

        $this->actingAs($this->oficina)->put(route('planilla.guardar', $planilla), $this->lineasConAnticipo($anticipo))
            ->assertSessionHasNoErrors()->assertRedirect(route('planilla.impresos', $planilla));

        return $planilla->fresh();
    }

    /** @return array{lineas: list<array<string, mixed>>} lo que manda «Preparar» con el anticipo elegido */
    private function lineasConAnticipo(PlanillaAnticipo $anticipo): array
    {
        return ['lineas' => [
            ['planilla_empleado_id' => $this->conDescuentos->id, 'salario' => '204.40', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
            ['planilla_empleado_id' => $this->sinDescuentos->id, 'salario' => '204.40'],
        ]];
    }

    private function detalleDe(Planilla $planilla, PlanillaEmpleado $empleado): PlanillaDetalle
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $empleado->id)->sole();
    }

    /** @return array<string, int> lo que todavía se le debe a cada uno, en centavos */
    private function pendientes(Planilla $planilla): array
    {
        $estado = app(EstadoPlanilla::class);
        $salida = [];

        foreach (PlanillaDetalle::where('planilla_id', $planilla->id)->get() as $detalle) {
            $salida[$detalle->nombre_snapshot] = $estado->deDetalle($detalle)['pendiente'];
        }
        foreach (PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto.cuotas')->get() as $tercero) {
            $salida[$tercero->tercero] = $estado->deTercero($tercero)['pendiente'];
        }
        ksort($salida);

        return $salida;
    }

    public function test_el_mes_de_la_oficina_anticipo_generar_pagar_anular_y_volver_a_armar(): void
    {
        $nombre = $this->conDescuentos->nombre;
        $otra = $this->sinDescuentos->nombre;

        // El anticipo, con doble clic: un solo registro.
        $datosAnticipo = ['clave' => (string) Str::uuid(), 'fecha' => '2026-10-05', 'importe' => '50.00', 'moneda' => 'USD', 'referencia' => 'Vale de prueba 1'];
        $this->actingAs($this->oficina)->post(route('planilla.anticipos.store', $this->conDescuentos), $datosAnticipo)->assertSessionHasNoErrors();
        $this->actingAs($this->oficina)->post(route('planilla.anticipos.store', $this->conDescuentos), $datosAnticipo)->assertSessionHasNoErrors();
        $anticipo = PlanillaAnticipo::sole();

        // Primera quincena de octubre: el anticipo se descuenta y nacen el ISSS y la AFP.
        $primera = $this->generarConAnticipo('2026-10-01', $anticipo);
        $this->assertSame('confirmada', $primera->estado);
        $this->assertSame(0, $anticipo->fresh()->pendiente());
        $this->assertSame(['AFP' => 1482, $nombre => 13345, 'ISSS' => 613, $otra => 20440], $this->pendientes($primera));
        // Doble clic en «Generar» desde Preparar: la confirmada ya no se toca.
        $this->actingAs($this->oficina)->put(route('planilla.guardar', $primera), $this->lineasConAnticipo($anticipo))->assertSessionHasErrors();
        $this->assertSame(['AFP' => 1482, $nombre => 13345, 'ISSS' => 613, $otra => 20440], $this->pendientes($primera));
        $this->assertSame(4, Gasto::count());

        // El lote, con doble clic: cada persona cobra una sola vez lo suyo.
        $lote = $this->datosPago(['detalles' => PlanillaDetalle::where('planilla_id', $primera->id)->pluck('id')->all()]);
        $this->actingAs($this->oficina)->post(route('planilla.pagar.lote', $primera), $lote)
            ->assertSessionHas('planilla.aviso', '2 pago(s) registrados en el lote.');
        $this->actingAs($this->oficina)->post(route('planilla.pagar.lote', $primera), $lote)
            ->assertSessionHas('planilla.aviso', '0 pago(s) registrados en el lote; 2 se omitieron porque ya no tenían saldo.');
        $this->assertSame(['133.45', '204.40'], Pago::orderBy('importe')->pluck('importe')->map(fn ($i) => (string) $i)->all());

        // El ISSS y la AFP, cada uno con doble clic.
        foreach (PlanillaObligacionTercero::where('planilla_id', $primera->id)->get() as $tercero) {
            $datos = $this->datosPago(['importe' => $tercero->tercero === 'ISSS' ? '6.13' : '14.82']);
            $this->actingAs($this->oficina)->post(route('planilla.pagar.tercero', $tercero), $datos)->assertSessionHasNoErrors();
            $this->actingAs($this->oficina)->post(route('planilla.pagar.tercero', $tercero), $datos)->assertSessionHasNoErrors();
        }
        $this->assertSame(4, Pago::count());
        $this->assertSame(['AFP' => 0, $nombre => 0, 'ISSS' => 0, $otra => 0], $this->pendientes($primera), 'Todo pagado, una sola vez.');

        // Con pagos no se anula, ni con doble clic: nada cambia.
        $this->actingAs($this->oficina)->post(route('planilla.anular', $primera), ['motivo' => self::MOTIVO])->assertSessionHasErrors('estado');
        $this->actingAs($this->oficina)->post(route('planilla.anular', $primera), ['motivo' => self::MOTIVO])->assertSessionHasErrors('estado');
        $this->assertSame('confirmada', $primera->fresh()->estado);
        $this->assertSame(0, DB::table('gastos_ajustes')->count());
        $this->assertSame(0, $anticipo->fresh()->pendiente());

        // Se revierten los cuatro pagos, con su motivo, y recién ahí se anula (con doble clic).
        foreach (Pago::all() as $pago) {
            $this->actingAs($this->oficina)->post(route('planilla.pagar.revertir', $pago), ['motivo' => 'Se pagó la quincena equivocada.'])
                ->assertSessionHasNoErrors();
        }
        $this->actingAs($this->oficina)->post(route('planilla.anular', $primera), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->actingAs($this->oficina)->post(route('planilla.anular', $primera), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->assertSame('anulada', $primera->fresh()->estado);
        $this->assertSame(4, DB::table('gastos_ajustes')->count(), 'Un ajuste por obligación, una sola vez.');
        $this->assertSame(5000, $anticipo->fresh()->pendiente(), 'El anticipo vuelve a quedar por recuperar.');
        $this->assertSame(4, Pago::whereNotNull('revertido_at')->count(), 'Los pagos quedan revertidos, no borrados.');

        // Volver a armarla: la misma quincena, con el mismo anticipo, otra vez por pagar.
        $rehecha = $this->generarConAnticipo('2026-10-01', $anticipo);
        $this->assertNotSame($primera->id, $rehecha->id);
        $this->assertSame('confirmada', $rehecha->estado);
        $this->assertSame('anulada', $primera->fresh()->estado);
        $this->assertSame(0, $anticipo->fresh()->pendiente());
        $this->assertSame(['AFP' => 1482, $nombre => 13345, 'ISSS' => 613, $otra => 20440], $this->pendientes($rehecha));

        // Segunda quincena, sin anticipo: sale sola. Sin pagos se anula, una sola vez.
        $this->actingAs($this->oficina)->post(route('planilla.store'), $this->periodo('2026-10-16'))->assertSessionHasNoErrors();
        $segunda = Planilla::latest('id')->firstOrFail();
        $this->assertSame('confirmada', $segunda->estado);
        $ajustesAntes = DB::table('gastos_ajustes')->count();
        $this->actingAs($this->oficina)->post(route('planilla.anular', $segunda), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->actingAs($this->oficina)->post(route('planilla.anular', $segunda), ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->assertSame('anulada', $segunda->fresh()->estado);
        $this->assertSame($ajustesAntes + 4, DB::table('gastos_ajustes')->count());
        $this->assertSame('confirmada', $rehecha->fresh()->estado, 'Anular la segunda no toca la primera.');
        $this->assertSame(0, $anticipo->fresh()->pendiente(), 'Ni el anticipo que descontó la primera.');
    }

    /**
     * Un pago de la quincena es SU SUELDO, no un adelanto. Si se pudiera enlazar como
     * anticipo, la quincena siguiente le descontaría a la persona lo que ya cobró por
     * trabajar. La pantalla lo ofrecía en «¿Salió por un pago ya registrado?».
     */
    public function test_el_pago_de_una_quincena_no_se_registra_como_anticipo(): void
    {
        $this->actingAs($this->oficina)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasNoErrors();
        $detalle = $this->detalleDe(Planilla::sole(), $this->conDescuentos);
        $this->actingAs($this->oficina)->post(route('planilla.pagar.persona', $detalle), $this->datosPago(['importe' => '183.45']))
            ->assertSessionHasNoErrors();
        $sueldo = Pago::sole();
        $this->assertSame($this->conDescuentos->nombre, $sueldo->beneficiario);

        $this->assertFalse(
            app(RegistrarAnticipo::class)->pagosCandidatos($this->oficina, $this->conDescuentos)->contains('id', $sueldo->id),
            'La pantalla no lo ofrece como el pago de un anticipo.',
        );

        $this->actingAs($this->oficina)->post(route('planilla.anticipos.store', $this->conDescuentos), [
            'clave' => (string) Str::uuid(), 'fecha' => '2026-10-15', 'importe' => '183.45', 'moneda' => 'USD', 'pago_id' => $sueldo->id,
        ])->assertSessionHasErrors('pago_id');

        $this->assertSame(0, PlanillaAnticipo::count());
    }

    /**
     * Enlazado a un pago, el anticipo no puede ser mayor que lo que salió en ese pago: un
     * cero de más al escribirlo («500» por «50») se le descontaría del sueldo entero.
     */
    public function test_el_anticipo_no_pasa_del_pago_con_el_que_se_entrego(): void
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => $this->conDescuentos->nombre, 'concepto' => 'Adelanto de prueba', 'categoria' => 'Anticipos',
            'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '50.00',
            'documentacion' => 'pendiente', 'responsable_id' => $this->oficina->id, 'registrado_por' => $this->oficina->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => '50.00', 'vence' => '2026-10-05']);
        $adelanto = app(RegistrarPago::class)->registrar($this->oficina,
            $this->datosPago(['importe' => '50.00', 'fecha' => '2026-10-05']), [['cuota_id' => $cuota->id, 'importe' => '50.00']]);
        $this->assertTrue(app(RegistrarAnticipo::class)->pagosCandidatos($this->oficina, $this->conDescuentos)->contains('id', $adelanto->id));

        $this->actingAs($this->oficina)->post(route('planilla.anticipos.store', $this->conDescuentos), [
            'clave' => (string) Str::uuid(), 'fecha' => '2026-10-05', 'importe' => '500.00', 'moneda' => 'USD', 'pago_id' => $adelanto->id,
        ])->assertSessionHasErrors('importe');
        $this->assertSame(0, PlanillaAnticipo::count());

        // Lo que de verdad salió, o una parte, sí.
        $this->actingAs($this->oficina)->post(route('planilla.anticipos.store', $this->conDescuentos), [
            'clave' => (string) Str::uuid(), 'fecha' => '2026-10-05', 'importe' => '50.00', 'moneda' => 'USD', 'pago_id' => $adelanto->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(5000, PlanillaAnticipo::sole()->pendiente());
    }

    /** El anticipo de una persona no se descuenta en la línea de otra, ni por la ruta. */
    public function test_el_anticipo_de_otra_persona_no_se_descuenta(): void
    {
        $anticipo = app(RegistrarAnticipo::class)->registrar($this->oficina, $this->conDescuentos, [
            'fecha' => '2026-10-05', 'importe' => '50.00', 'moneda' => 'USD',
        ]);
        $this->actingAs($this->oficina)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasNoErrors();
        $planilla = Planilla::sole();

        $this->actingAs($this->oficina)->put(route('planilla.guardar', $planilla), ['lineas' => [
            ['planilla_empleado_id' => $this->conDescuentos->id, 'salario' => '204.40'],
            ['planilla_empleado_id' => $this->sinDescuentos->id, 'salario' => '204.40', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]])->assertSessionHasErrors();

        $this->assertSame('borrador', $planilla->fresh()->estado);
        $this->assertSame(5000, $anticipo->fresh()->pendiente());
        $this->assertSame(0, Gasto::count());
    }

    /**
     * Quien solo tiene `planilla.gestionar` (con o sin `planilla.ver`) y quien no tiene
     * ningún permiso: cada botón que mueve dinero da 403 y nada cambia.
     */
    public function test_sin_los_permisos_de_importes_y_de_pagar_ningun_boton_mueve_dinero(): void
    {
        $anticipo = app(RegistrarAnticipo::class)->registrar($this->oficina, $this->conDescuentos, [
            'fecha' => '2026-10-05', 'importe' => '50.00', 'moneda' => 'USD',
        ]);
        $confirmada = $this->generarConAnticipo('2026-10-01', $anticipo);
        $detalle = $this->detalleDe($confirmada, $this->sinDescuentos);
        $this->actingAs($this->oficina)->post(route('planilla.pagar.persona', $detalle), $this->datosPago(['importe' => '100.00']))
            ->assertSessionHasNoErrors();
        $pago = Pago::sole();
        $tercero = PlanillaObligacionTercero::where('planilla_id', $confirmada->id)->where('tercero', 'ISSS')->sole();
        $borrador = app(PrepararPlanilla::class)->abrir($this->oficina, $this->periodo('2026-10-16'));
        $foto = fn () => [
            Planilla::orderBy('id')->pluck('estado', 'id')->all(), PlanillaDetalle::count(), Gasto::count(), Pago::count(),
            Pago::whereNotNull('revertido_at')->count(), PlanillaAnticipo::count(), DB::table('gastos_ajustes')->count(),
            DB::table('planilla_lotes')->count(),
        ];
        $antes = $foto();

        $soloGestionar = $this->usuario([PermisoSistema::PlanillaGestionar]);
        $verYGestionar = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::PlanillaGestionar]);
        $nada = $this->usuario([]);

        foreach ([$soloGestionar, $verYGestionar, $nada] as $quien) {
            $this->actingAs($quien);
            $this->post(route('planilla.store'), $this->periodo('2026-11-01'))->assertForbidden();
            $this->put(route('planilla.guardar', $borrador), ['lineas' => [['planilla_empleado_id' => $this->sinDescuentos->id, 'salario' => '204.40']]])->assertForbidden();
            $this->post(route('planilla.confirmar', $borrador))->assertForbidden();
            $this->delete(route('planilla.destroy', $borrador))->assertForbidden();
            $this->post(route('planilla.anular', $confirmada), ['motivo' => self::MOTIVO])->assertForbidden();
            $this->post(route('planilla.anticipos.store', $this->conDescuentos), [
                'clave' => (string) Str::uuid(), 'fecha' => '2026-10-20', 'importe' => '20.00', 'moneda' => 'USD',
            ])->assertForbidden();
            $this->post(route('planilla.pagar.persona', $detalle), $this->datosPago(['importe' => '10.00']))->assertForbidden();
            $this->post(route('planilla.pagar.tercero', $tercero), $this->datosPago(['importe' => '6.13']))->assertForbidden();
            $this->post(route('planilla.pagar.lote', $confirmada), $this->datosPago(['detalles' => [$detalle->id]]))->assertForbidden();
            $this->post(route('planilla.pagar.revertir', $pago), ['motivo' => 'Revertir sin permiso.'])->assertForbidden();
            $this->get(route('planilla.show', $confirmada))->assertForbidden();
            $this->get(route('planilla.pagos', $confirmada))->assertForbidden();
            $this->get(route('planilla.anticipos'))->assertForbidden();
        }

        $this->assertSame($antes, $foto(), 'Nada cambió.');
        $this->assertSame(0, $anticipo->fresh()->pendiente());
        $this->assertSame(10440, app(EstadoPlanilla::class)->deDetalle($detalle->fresh())['pendiente']);
    }
}
