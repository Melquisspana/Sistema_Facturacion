<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaConcepto;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaDocumento;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una planilla se genera de una vez —calcula, confirma y lleva a la hoja para firmar— y
 * la última se puede borrar con todo lo que creó, como si nunca se hubiera generado.
 */
class GenerarYBorrarPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

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

        // Con descuentos: genera, además de su línea, lo que se le debe al ISSS y a la AFP.
        $this->ana = app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
        ]);
    }

    /** @return array<string, string> */
    private function periodo(string $fecha): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function generar(string $fecha): Planilla
    {
        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo($fecha))->assertSessionHasNoErrors();

        return Planilla::latest('id')->firstOrFail();
    }

    // ─────────────────────── Generar ───────────────────────

    public function test_revisar_dice_cuantas_personas_y_el_total_sin_crear_nada(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()
            ->assertSee('Se va a generar')
            ->assertSee('Ana Ficticia')
            ->assertSee('183.45')
            ->assertSee('Sí, generar la planilla');

        $this->assertSame(0, Planilla::count());
        $this->assertSame(0, Gasto::count());
    }

    public function test_generar_confirma_en_un_paso_y_lleva_a_la_hoja(): void
    {
        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', $planilla));
        $this->assertSame('confirmada', $planilla->estado);
        // Una deuda con Ana y una con cada tercero de ley.
        $this->assertSame(1, PlanillaDetalle::whereNotNull('gasto_id')->count());
        $this->assertSame(2, PlanillaObligacionTercero::count());

        $this->actingAs($this->actor)->get(route('planilla.impresos', $planilla))->assertOk()->assertSee('generada');
    }

    public function test_con_algo_que_resolver_no_se_ofrece_generar_y_no_queda_nada(): void
    {
        // Sin sueldo registrado: entra en cero, y una línea en cero frena la confirmación.
        app(RegistrarEmpleado::class)->registrar($this->actor, ['nombre' => 'Beto Sinsueldo', 'cargo' => 'Bodega']);

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()->assertSee('Antes de generar hay que resolver')->assertDontSee('Sí, generar la planilla');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasErrors('reparos');

        $this->assertSame(0, Planilla::count(), 'Ni siquiera queda un borrador.');
        $this->assertSame(0, Gasto::count());
    }

    public function test_un_periodo_ya_generado_no_se_genera_dos_veces(): void
    {
        $planilla = $this->generar('2026-10-01');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasErrors('periodo');
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))->assertRedirect(route('planilla.show', $planilla));

        $this->assertSame(1, Planilla::count());
        $this->assertSame(3, Gasto::count());
    }

    public function test_un_borrador_de_antes_se_termina_generandolo(): void
    {
        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));

        $this->actingAs($this->actor)->get(route('planilla.show', $borrador))->assertOk()->assertSee('Terminar y generar');

        $this->actingAs($this->actor)->put(route('planilla.guardar', $borrador), ['lineas' => [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '204.40'],
        ]])->assertRedirect(route('planilla.impresos', $borrador));

        $this->assertSame('confirmada', $borrador->fresh()->estado);
    }

    // ─────────────────────── Borrar ───────────────────────

    public function test_borrar_la_ultima_deshace_todo_lo_que_creo(): void
    {
        $planilla = $this->generar('2026-10-01');
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'planilla_confirmada')->count());

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Borrar la planilla');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))
            ->assertSessionHasNoErrors()->assertRedirect(route('planilla.index'));

        $this->assertSame(0, Planilla::count());
        $this->assertSame(0, PlanillaDetalle::count());
        $this->assertSame(0, PlanillaConcepto::count());
        $this->assertSame(0, PlanillaObligacionTercero::count());
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, DB::table('gastos_eventos')->count());

        $constancia = Activity::where('description', 'borró la planilla')->sole();
        $this->assertSame($this->actor->id, $constancia->causer_id);
        $this->assertSame(3, $constancia->properties['gastos']);

        // Y el período queda libre para generarlo de nuevo.
        $this->generar('2026-10-01');
        $this->assertSame(1, Planilla::count());
    }

    public function test_no_se_borra_una_planilla_que_tiene_otra_despues(): void
    {
        $primera = $this->generar('2026-10-01');
        $segunda = $this->generar('2026-10-16');

        $this->actingAs($this->actor)->get(route('planilla.show', $primera))->assertOk()->assertDontSee('Borrar la planilla');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $primera))->assertSessionHasErrors('borrar');
        $this->assertNotNull($primera->fresh());

        // En orden, de la última hacia atrás, sí.
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $segunda))->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $primera))->assertSessionHasNoErrors();
        $this->assertSame(0, Planilla::count());
    }

    public function test_un_borrador_de_antes_se_borra_aunque_no_sea_el_ultimo(): void
    {
        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));
        $this->generar('2026-10-16');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $borrador))->assertSessionHasNoErrors();

        $this->assertNull($borrador->fresh());
        $this->assertSame(1, Planilla::count());
    }

    public function test_al_borrar_el_anticipo_vuelve_a_tener_su_saldo(): void
    {
        $anticipo = app(RegistrarAnticipo::class)->registrar($this->actor, $this->ana, [
            'fecha' => '2026-10-02', 'importe' => '50.00', 'moneda' => 'USD', 'referencia' => 'vale 1',
        ]);

        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));
        $this->actingAs($this->actor)->put(route('planilla.guardar', $borrador), ['lineas' => [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '204.40', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '30.00', 'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(2000, $anticipo->fresh()->pendiente());

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $borrador))->assertSessionHasNoErrors();

        $this->assertSame(5000, $anticipo->fresh()->pendiente());
        $this->assertSame(0, DB::table('planilla_anticipo_aplicaciones')->count());
    }

    public function test_una_anulada_que_es_la_ultima_se_borra_con_su_ajuste(): void
    {
        $planilla = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con el período equivocado');
        $this->assertGreaterThan(0, DB::table('gastos_ajustes')->count());

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('gastos_ajustes')->count());
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, DB::table('gastos_eventos')->count());
    }

    public function test_con_un_pago_registrado_no_se_borra_y_se_dice_por_que(): void
    {
        $planilla = $this->generar('2026-10-01');

        app(PagarPlanilla::class)->pagarPersona($this->actor, PlanillaDetalle::sole(), [
            'importe' => '50.00', 'fecha' => '2026-10-16', 'metodo' => 'efectivo',
            'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Abono en caja.',
        ]);

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Tiene pagos registrados');
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))->assertSessionHasErrors('borrar');

        $this->assertNotNull($planilla->fresh());
        $this->assertSame(3, Gasto::count());
    }

    public function test_con_un_documento_firmado_subido_no_se_borra(): void
    {
        $planilla = $this->generar('2026-10-01');

        PlanillaDocumento::create([
            'planilla_id' => $planilla->id, 'tipo' => 'hoja_firmada', 'ruta' => 'planilla/firmados/ficticio.pdf',
            'nombre' => 'hoja.pdf', 'mime' => 'application/pdf', 'bytes' => 10, 'sha256' => str_repeat('a', 64),
            'registrado_por' => $this->actor->id, 'created_at' => now(),
        ]);

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))
            ->assertSessionHasErrors(['borrar' => 'Tiene documentos firmados subidos. Para dejarla sin efecto, anulala.']);

        $this->assertNotNull($planilla->fresh());
    }

    public function test_borrar_exige_gestionar_la_planilla(): void
    {
        $planilla = $this->generar('2026-10-01');

        $mirón = User::factory()->create(['activo' => true]);
        $mirón->syncPermissions([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaSalarios->value]);

        $this->actingAs($mirón)->get(route('planilla.show', $planilla))->assertOk()->assertDontSee('Borrar la planilla');
        $this->actingAs($mirón)->delete(route('planilla.destroy', $planilla))->assertForbidden();

        $this->assertNotNull($planilla->fresh());
    }

    // ─────────────────────── Volver a hacer una anulada ───────────────────────

    public function test_una_quincena_anulada_se_vuelve_a_generar_y_conserva_su_historia(): void
    {
        $anulada = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');
        $ajustes = DB::table('gastos_ajustes')->count();

        // La revisión ya no lleva a la anulada: ofrece generarla de nuevo.
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()->assertSee('Sí, generar la planilla');

        $nueva = $this->generar('2026-10-01');

        $this->assertNotSame($anulada->id, $nueva->id);
        $this->assertSame('confirmada', $nueva->estado);
        $this->assertSame('2026-10-Q1', $nueva->periodo);

        // La nueva no pisa la anulada: queda con su motivo, su línea, sus deudas
        // extinguidas y su ajuste, y en pantalla sigue siendo la misma quincena.
        $anulada->refresh();
        $this->assertSame('anulada', $anulada->estado);
        $this->assertSame('Se generó con el sueldo equivocado', $anulada->motivo_anulacion);
        $this->assertSame(1, PlanillaDetalle::where('planilla_id', $anulada->id)->count());
        $this->assertSame(2, PlanillaObligacionTercero::where('planilla_id', $anulada->id)->count());
        $this->assertSame($ajustes, DB::table('gastos_ajustes')->count());
        $this->assertSame(6, Gasto::count());
        $this->assertSame('2026-10-Q1', $anulada->periodo);
        $this->assertSame(1, Activity::where('description', 'dejó libre su período para volver a hacerla')->where('subject_id', $anulada->id)->count());

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('2026-10-Q1-A');
        $this->actingAs($this->actor)->get(route('planilla.show', $anulada))->assertOk()
            ->assertSee('2026-10-Q1')->assertDontSee('2026-10-Q1-A')->assertSee('Anulada');

        // Y se puede repetir: anular también la nueva y hacerla otra vez.
        app(AnularPlanilla::class)->anular($this->actor, $nueva, 'Otra vez el sueldo equivocado');
        $this->generar('2026-10-01');

        $this->assertSame(['anulada', 'anulada', 'confirmada'], Planilla::orderBy('id')->pluck('estado')->all());
    }

    public function test_papeles_firmados_y_una_planilla_posterior_no_impiden_volver_a_hacerla(): void
    {
        $anulada = $this->generar('2026-10-01');
        PlanillaDocumento::create([
            'planilla_id' => $anulada->id, 'tipo' => 'hoja_firmada', 'ruta' => 'planilla/firmados/ficticio.pdf',
            'nombre' => 'hoja.pdf', 'mime' => 'application/pdf', 'bytes' => 10, 'sha256' => str_repeat('a', 64),
            'registrado_por' => $this->actor->id, 'created_at' => now(),
        ]);
        $this->generar('2026-10-16');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'La hoja firmada tenía un error');

        $nueva = $this->generar('2026-10-01');

        $this->assertSame('confirmada', $nueva->estado);
        $this->assertSame(1, PlanillaDocumento::where('planilla_id', $anulada->id)->count());
        $this->assertSame(3, Planilla::count());
    }

    public function test_una_anulada_con_pagos_revertidos_se_vuelve_a_generar(): void
    {
        $anulada = $this->generar('2026-10-01');
        $pago = app(PagarPlanilla::class)->pagarPersona($this->actor, PlanillaDetalle::sole(), [
            'importe' => '50.00', 'fecha' => '2026-10-16', 'metodo' => 'efectivo',
            'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Abono en caja.',
        ]);
        app(PagarPlanilla::class)->revertirPago($this->actor, $pago, 'Se registró en la quincena equivocada');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');

        $nueva = $this->generar('2026-10-01');

        $this->assertSame('confirmada', $nueva->estado);
        // El pago revertido sigue en la historia de la anulada.
        $this->assertNotNull($pago->fresh()->revertido_at);
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
    }

    public function test_una_anulada_con_un_pago_vigente_no_se_vuelve_a_hacer_y_dice_que_revertir(): void
    {
        $anulada = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');

        // La única forma de pagar una anulada: revertir en Gastos el ajuste que extinguió
        // la deuda y pagarla desde ahí.
        $cuota = PlanillaDetalle::sole()->gasto->cuotas()->sole();
        app(RegistrarAjuste::class)->revertir($this->actor, Ajuste::where('cuota_id', $cuota->id)->sole(), 'Se anuló sin querer');
        app(RegistrarPago::class)->registrar($this->actor, [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-10-16',
            'metodo' => 'efectivo', 'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Abono en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '50.00']]);

        $mensaje = 'No se puede volver a hacer: la planilla 2026-10-Q1 anulada tiene pagos sin revertir (Ana Ficticia). '
            .'Revertí primero esos pagos, cada uno con su motivo, y en Gastos dejá esas deudas otra vez en cero con un ajuste, '
            .'como quedaron al anularla. Después volvé a generarla.';

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))
            ->assertSessionHasErrors(['periodo' => $mensaje]);

        // Nada cambió: la anulada sigue en su lugar y no hay otra.
        $this->assertSame(1, Planilla::count());
        $this->assertSame('2026-10-Q1', $anulada->fresh()->getRawOriginal('periodo'));
    }

    public function test_revertir_el_pago_de_una_anulada_no_basta_si_su_deuda_quedo_pendiente(): void
    {
        $anulada = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');

        $cuota = PlanillaDetalle::sole()->gasto->cuotas()->sole();
        app(RegistrarAjuste::class)->revertir($this->actor, Ajuste::where('cuota_id', $cuota->id)->sole(), 'Se anuló sin querer');
        $pago = app(RegistrarPago::class)->registrar($this->actor, [
            'clave' => (string) Str::uuid(), 'importe' => '50.00', 'fecha' => '2026-10-16',
            'metodo' => 'efectivo', 'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Abono en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '50.00']]);

        // Se sigue el mensaje: se revierte el pago. Pero el ajuste de la anulación sigue
        // revertido, así que la deuda de la anulada vuelve a estar pendiente entera.
        app(RegistrarPago::class)->revertir($this->actor, $pago, 'Se pagó una planilla anulada');
        $this->assertSame(1, $this->deudasVivasDe('Ana Ficticia'));

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))
            ->assertSessionHasErrors(['periodo' => $this->mensajeDeudaPendiente()]);
        $this->assertSame(1, Planilla::count());

        // Con la deuda otra vez en cero, sí: y queda una sola deuda viva con Ana.
        $this->dejarEnCero($cuota);
        $this->generar('2026-10-01');

        $this->assertSame(2, Planilla::count());
        $this->assertSame(1, $this->deudasVivasDe('Ana Ficticia'));
    }

    public function test_una_anulada_con_el_ajuste_revertido_no_se_vuelve_a_hacer(): void
    {
        $anulada = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');

        // Sin pagar nada: basta con revertir el ajuste para que la deuda vuelva a vivir.
        $cuota = PlanillaDetalle::sole()->gasto->cuotas()->sole();
        app(RegistrarAjuste::class)->revertir($this->actor, Ajuste::where('cuota_id', $cuota->id)->sole(), 'Se anuló sin querer');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))
            ->assertSessionHasErrors(['periodo' => $this->mensajeDeudaPendiente()]);

        $this->assertSame(1, Planilla::count());
        $this->assertSame('2026-10-Q1', $anulada->fresh()->getRawOriginal('periodo'));
        $this->assertSame(1, $this->deudasVivasDe('Ana Ficticia'));
    }

    public function test_generar_no_bloquea_planillas_por_periodo_sino_por_id(): void
    {
        // En MySQL, un candado por período sobre filas que todavía no existen toma un hueco
        // del índice que dos generaciones simultáneas comparten: al insertar, cada una espera
        // a la otra y MySQL corta una con un error 500. SQLite no tiene candados y Laravel no
        // los escribe; con esta gramática quedan a la vista en la consulta.
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->setQueryGrammar(new class(DB::connection()) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value): string
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }

        $candados = [];
        DB::listen(function (QueryExecuted $consulta) use (&$candados) {
            if (str_contains($consulta->sql, 'for update')) {
                $candados[] = $consulta->sql;
            }
        });

        // La primera vez, sin anuladas; después, con una anulada que deja su lugar.
        $anulada = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $anulada, 'Se generó con el sueldo equivocado');
        $this->generar('2026-10-01');

        $this->assertNotEmpty($candados, 'La prueba tiene que ver los candados.');
        $this->assertSame([], array_values(array_filter($candados,
            fn (string $sql) => str_contains($sql, 'planillas') && str_contains($sql, 'periodo'))));
    }

    private function mensajeDeudaPendiente(): string
    {
        return 'No se puede volver a hacer: la planilla 2026-10-Q1 anulada tiene deudas pendientes en Gastos (Ana Ficticia), '
            .'porque se revirtió el ajuste que las dejó en cero al anularla. '
            .'Dejalas otra vez en cero con un ajuste, con su motivo, y después volvé a generarla.';
    }

    private function dejarEnCero(Cuota $cuota): void
    {
        app(RegistrarAjuste::class)->registrar($this->actor, $cuota, [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'correccion',
            'importe' => Dinero::decimal(app(SaldosGastos::class)->pendienteCuota($cuota->fresh())),
            'motivo' => 'La planilla está anulada',
        ]);
    }

    /** Cuántas deudas con esa persona o ese tercero tienen algo pendiente en Gastos. */
    private function deudasVivasDe(string $beneficiario): int
    {
        return Cuota::whereHas('gasto', fn ($q) => $q->where('beneficiario', $beneficiario))->get()
            ->filter(fn (Cuota $c) => app(SaldosGastos::class)->pendienteCuota($c) > 0)
            ->count();
    }
}
