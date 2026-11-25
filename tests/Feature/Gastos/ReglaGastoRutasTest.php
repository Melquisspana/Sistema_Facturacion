<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Aviso;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\Gastos\ReglaVersion;
use App\Models\User;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rutas de las reglas que se repiten (Gastos › Se repite) y de la bandeja de avisos,
 * miradas por su efecto en la base.
 *
 * Todo entra por la RUTA, como el botón de la pantalla. Una regla no es una deuda: crea
 * deudas. Por eso lo que importa acá es qué obligaciones nacen después de cada acción
 * (crear, cambiar el monto, pausar, reanudar, cancelar y saltar un período) y que lo ya
 * generado nunca cambie por la puerta de atrás. Datos ficticios.
 */
class ReglaGastoRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['gastos.enabled' => true]);
    }

    /** @param  list<PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function operador(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosRecurrencias,
        ]);
    }

    /** Maneja reglas, pero solo de la empresa. */
    private function soloEmpresa(): User
    {
        return $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosRecurrencias]);
    }

    private function en(string $fecha): void
    {
        $this->travelTo(CarbonImmutable::parse($fecha.' 10:00'));
    }

    /** Lo que manda el formulario de alta. */
    private function datosRegla(User $u, array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(),
            'nombre' => 'Alquiler de prueba',
            'beneficiario' => 'Proveedor de prueba',
            'concepto' => 'Alquiler mensual',
            'categoria' => 'Alquiler',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'monto_modo' => 'fijo',
            'importe' => '400.00',
            'frecuencia' => 'mensual',
            'dia_mes' => 5,
            'dias_generar_antes' => 0,
            'vigente_desde' => '2026-03-01',
        ], $extra);
    }

    private function crear(User $u, array $extra = []): Regla
    {
        $datos = $this->datosRegla($u, $extra);
        $this->actingAs($u)->post(route('gastos.reglas.store', $datos))->assertSessionHasNoErrors();

        return Regla::where('clave', $datos['clave'])->sole();
    }

    private function generar(User $u, Regla $regla): void
    {
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors();
    }

    /** @return list<string> */
    private function periodosGenerados(Regla $regla): array
    {
        return Ocurrencia::where('regla_id', $regla->id)->where('estado', 'generada')->orderBy('periodo')->pluck('periodo')->all();
    }

    private function importeDe(Regla $regla, string $periodo): string
    {
        $ocurrencia = Ocurrencia::where('regla_id', $regla->id)->where('periodo', $periodo)->sole();

        return (string) Gasto::findOrFail($ocurrencia->gasto_id)->importe;
    }

    // ─────────────────────────── gastos.reglas.store ───────────────────────────

    public function test_una_regla_mensual_creada_desde_el_formulario_queda_activa_y_sin_obligaciones(): void
    {
        $u = $this->operador();
        $datos = $this->datosRegla($u);

        $respuesta = $this->actingAs($u)->post(route('gastos.reglas.store'), $datos);

        $regla = Regla::sole();
        $respuesta->assertSessionHasNoErrors()->assertRedirect(route('gastos.reglas.show', $regla));
        $this->assertSame('activa', $regla->estado);
        $this->assertSame('400.00', (string) $regla->importe);
        $this->assertSame('mensual', $regla->frecuencia);
        $this->assertSame($u->id, (int) $regla->registrado_por);
        $this->assertSame(1, ReglaVersion::where('regla_id', $regla->id)->count());
        $this->assertSame(1, DB::table('gastos_eventos')->where('regla_id', $regla->id)->where('accion', 'regla_creada')->count());
        // Crear la regla no crea ninguna deuda: eso lo hace generar.
        $this->assertSame(0, Ocurrencia::count());
        $this->assertSame(0, Gasto::count());
    }

    public function test_reenviar_el_alta_no_crea_dos_reglas(): void
    {
        $u = $this->operador();
        $datos = $this->datosRegla($u);

        $this->actingAs($u)->post(route('gastos.reglas.store'), $datos)->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('gastos.reglas.store'), $datos)->assertSessionHasNoErrors();

        $this->assertSame(1, Regla::count());
    }

    public function test_una_regla_personal_sin_alcance_personal_da_error_y_no_se_crea(): void
    {
        $u = $this->soloEmpresa();

        $this->actingAs($u)->post(route('gastos.reglas.store'), $this->datosRegla($u, ['ambito' => 'personal', 'persona' => 'Persona de prueba']))
            ->assertSessionHasErrors('ambito');

        $this->assertSame(0, Regla::count());
    }

    public function test_sin_permiso_de_recurrencias_no_se_crea_una_regla(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar]);

        $this->actingAs($u)->post(route('gastos.reglas.store'), $this->datosRegla($u))->assertForbidden();

        $this->assertSame(0, Regla::count());
    }

    // ─────────────────────────── gastos.reglas.update ───────────────────────────

    public function test_cambiar_el_monto_con_motivo_rige_de_ahi_en_adelante_y_no_toca_lo_generado(): void
    {
        $u = $this->operador();
        $this->en('2026-03-05');
        $regla = $this->crear($u);
        $this->generar($u, $regla);

        $this->actingAs($u)->put(route('gastos.reglas.update', $regla), $this->datosRegla($u, [
            'clave' => $regla->clave, 'importe' => '450.00', 'motivo' => 'Subió el alquiler desde abril.',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('gastos.reglas.show', $regla));

        $this->assertSame('450.00', (string) $regla->fresh()->importe);
        $this->assertSame(2, (int) $regla->fresh()->version);
        $this->assertSame(2, ReglaVersion::where('regla_id', $regla->id)->count());
        // Marzo ya estaba generado y sigue igual.
        $this->assertSame('400.00', $this->importeDe($regla, '2026-03'));

        $this->en('2026-04-05');
        $this->generar($u, $regla->fresh());

        $this->assertSame('450.00', $this->importeDe($regla, '2026-04'));
        $this->assertSame('400.00', $this->importeDe($regla, '2026-03'));
    }

    public function test_editar_sin_motivo_no_cambia_nada(): void
    {
        $u = $this->operador();
        $regla = $this->crear($u);

        $this->actingAs($u)->put(route('gastos.reglas.update', $regla), $this->datosRegla($u, [
            'clave' => $regla->clave, 'importe' => '450.00', 'motivo' => '',
        ]))->assertSessionHasErrors('motivo');

        $this->assertSame('400.00', (string) $regla->fresh()->importe);
        $this->assertSame(1, (int) $regla->fresh()->version);
    }

    public function test_una_regla_personal_no_se_edita_sin_alcance_personal(): void
    {
        $regla = $this->crear($this->operador(), ['ambito' => 'personal', 'persona' => 'Persona de prueba']);
        $u = $this->soloEmpresa();

        $this->actingAs($u)->put(route('gastos.reglas.update', $regla), $this->datosRegla($u, [
            'clave' => $regla->clave, 'ambito' => 'empresarial', 'importe' => '1.00', 'motivo' => 'Cambio sin alcance.',
        ]))->assertForbidden();
        $this->actingAs($u)->put(route('gastos.reglas.update', 999999), $this->datosRegla($u, ['motivo' => 'No existe.']))
            ->assertNotFound();

        $this->assertSame('400.00', (string) $regla->fresh()->importe);
        $this->assertSame('personal', $regla->fresh()->ambito);
    }

    public function test_una_regla_cancelada_no_se_edita(): void
    {
        $u = $this->operador();
        $regla = $this->crear($u);
        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Se terminó el contrato.'])->assertSessionHasNoErrors();

        $this->actingAs($u)->put(route('gastos.reglas.update', $regla), $this->datosRegla($u, [
            'clave' => $regla->clave, 'importe' => '450.00', 'motivo' => 'Quiero reabrirla.',
        ]))->assertSessionHasErrors('estado');

        $this->assertSame('400.00', (string) $regla->fresh()->importe);
        $this->assertSame('cancelada', $regla->fresh()->estado);
    }

    // ─────────────────────────── gastos.reglas.pausar ───────────────────────────

    public function test_pausar_con_motivo_frena_la_generacion_y_no_toca_lo_generado(): void
    {
        $u = $this->operador();
        $this->en('2026-03-05');
        $regla = $this->crear($u);
        $this->generar($u, $regla);

        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'El local está cerrado por remodelación.'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $regla->refresh();
        $this->assertSame('pausada', $regla->estado);
        $this->assertSame('El local está cerrado por remodelación.', $regla->motivo_pausa);
        $this->assertSame($u->id, (int) $regla->pausada_por);

        $this->en('2026-04-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasErrors('regla');

        $this->assertSame(['2026-03'], $this->periodosGenerados($regla));
        $marzo = Gasto::sole();
        $this->assertSame(40000, app(SaldosGastos::class)->resumen($marzo, '2026-04-05')['pendiente'], 'Lo de marzo se sigue debiendo.');
    }

    public function test_pausar_sin_motivo_o_una_regla_ya_pausada_avisa_y_no_cambia(): void
    {
        $u = $this->operador();
        $regla = $this->crear($u);

        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->assertSame('activa', $regla->fresh()->estado);

        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Pausa de prueba.'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Otra pausa de prueba.'])->assertSessionHasErrors('estado');

        $this->assertSame('Pausa de prueba.', $regla->fresh()->motivo_pausa);
        $this->assertSame(1, DB::table('gastos_eventos')->where('regla_id', $regla->id)->where('accion', 'regla_pausada')->count());
    }

    public function test_una_regla_personal_no_se_pausa_sin_alcance_personal(): void
    {
        $regla = $this->crear($this->operador(), ['ambito' => 'personal', 'persona' => 'Persona de prueba']);

        $this->actingAs($this->soloEmpresa())->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Pausa sin alcance.'])
            ->assertForbidden();

        $this->assertSame('activa', $regla->fresh()->estado);
    }

    // ─────────────────────────── gastos.reglas.reanudar ───────────────────────────

    /**
     * Pausada en marzo y reanudada en mayo: genera mayo y lo que sigue, pero no crea de
     * golpe abril, que venció mientras estaba en pausa. Abril queda a la vista para que una
     * persona decida si se debe.
     */
    public function test_reanudar_vuelve_a_generar_desde_el_periodo_siguiente_sin_crear_los_de_la_pausa(): void
    {
        $u = $this->operador();
        $this->en('2026-03-05');
        $regla = $this->crear($u);
        $this->generar($u, $regla);

        $this->en('2026-03-10');
        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'El local está cerrado por remodelación.'])
            ->assertSessionHasNoErrors();

        $this->en('2026-05-01');
        $this->actingAs($u)->post(route('gastos.reglas.reanudar', $regla), ['motivo' => 'Volvimos a ocupar el local.'])
            ->assertSessionHasNoErrors();

        $regla->refresh();
        $this->assertSame('activa', $regla->estado);
        $this->assertNull($regla->pausada_at);
        // Reanudar no genera nada por su cuenta.
        $this->assertSame(['2026-03'], $this->periodosGenerados($regla));

        $this->en('2026-05-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('gastos.aviso', fn ($aviso) => str_contains($aviso, '2026-04'));

        $this->assertSame(['2026-03', '2026-05'], $this->periodosGenerados($regla));
        $this->assertSame(0, Ocurrencia::where('regla_id', $regla->id)->where('periodo', '2026-04')->count());
    }

    /**
     * La tarea programada sigue la misma regla, en la simulación y al aplicar. La
     * simulación avisa lo mismo que después avisa la corrida de verdad.
     */
    public function test_la_tarea_programada_tampoco_crea_lo_que_vencio_en_la_pausa(): void
    {
        $u = $this->operador();
        $this->en('2026-03-10');
        $regla = $this->crear($u);
        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Pausa de prueba.'])->assertSessionHasNoErrors();
        $this->en('2026-05-01');
        $this->actingAs($u)->post(route('gastos.reglas.reanudar', $regla), ['motivo' => 'Reanudada de prueba.'])->assertSessionHasNoErrors();
        config(['gastos.recurrencias.generacion_automatica' => true]);

        $this->artisan('gastos:generar-recurrentes --hoy=2026-05-05')
            ->expectsOutputToContain('crearía 2 — 2026-03 (vence 2026-03-05), 2026-05 (vence 2026-05-05)')
            ->expectsOutputToContain('vencieron en pausa, SIN generar — 2026-04')
            ->assertSuccessful();
        $this->assertSame(0, Ocurrencia::count());

        $this->artisan('gastos:generar-recurrentes --aplicar --hoy=2026-05-05')
            ->expectsOutputToContain('vencieron en pausa, SIN generar — 2026-04')
            ->assertSuccessful();

        // Marzo venció el 5, antes de la pausa del 10: ese sí se debe y se crea.
        $this->assertSame(['2026-03', '2026-05'], $this->periodosGenerados($regla));
    }

    public function test_reanudar_una_regla_activa_o_sin_motivo_avisa(): void
    {
        $u = $this->operador();
        $regla = $this->crear($u);

        $this->actingAs($u)->post(route('gastos.reglas.reanudar', $regla), ['motivo' => 'No estaba pausada.'])->assertSessionHasErrors('estado');

        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Pausa de prueba.'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('gastos.reglas.reanudar', $regla), ['motivo' => ''])->assertSessionHasErrors('motivo');

        $this->assertSame('pausada', $regla->fresh()->estado);
    }

    // ─────────────────────────── gastos.reglas.cancelar ───────────────────────────

    public function test_cancelar_con_motivo_ya_no_genera_y_lo_generado_sigue_vigente(): void
    {
        $u = $this->operador();
        $this->en('2026-03-05');
        $regla = $this->crear($u);
        $this->generar($u, $regla);

        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Se terminó el contrato.'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $regla->refresh();
        $this->assertSame('cancelada', $regla->estado);
        $this->assertSame('Se terminó el contrato.', $regla->motivo_cancelacion);
        $this->assertSame($u->id, (int) $regla->cancelada_por);

        $this->en('2026-05-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasErrors('regla');

        $this->assertSame(['2026-03'], $this->periodosGenerados($regla));
        $marzo = Gasto::sole();
        $this->assertNull($marzo->anulado_at ?? null);
        $this->assertSame(40000, app(SaldosGastos::class)->resumen($marzo, '2026-05-05')['pendiente'], 'Lo de marzo se sigue debiendo.');
        $this->assertSame(0, Pago::count());
    }

    public function test_cancelar_sin_motivo_o_dos_veces_avisa(): void
    {
        $u = $this->operador();
        $regla = $this->crear($u);

        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->assertSame('activa', $regla->fresh()->estado);

        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Se terminó el contrato.'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Otra vez.'])->assertSessionHasErrors('estado');

        $this->assertSame('Se terminó el contrato.', $regla->fresh()->motivo_cancelacion);
    }

    // ─────────────────────────── gastos.reglas.omitir ───────────────────────────

    public function test_saltar_un_periodo_desde_el_formulario_hace_que_la_generacion_no_lo_cree(): void
    {
        $u = $this->operador();
        $this->en('2026-03-01');
        $regla = $this->crear($u);

        $this->actingAs($u)->post(route('gastos.reglas.omitir', $regla), ['periodo' => '2026-03', 'motivo' => 'Este mes lo cubrió el propietario.'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $omitida = Ocurrencia::where('regla_id', $regla->id)->sole();
        $this->assertSame('omitida', $omitida->estado);
        $this->assertNull($omitida->gasto_id);
        $this->assertSame('2026-03-05', $omitida->vence->format('Y-m-d'));

        $this->en('2026-03-05');
        $this->generar($u, $regla);
        $this->assertSame(0, Gasto::count());

        $this->en('2026-04-05');
        $this->generar($u, $regla);
        $this->assertSame(['2026-04'], $this->periodosGenerados($regla));
    }

    /** Un período mal escrito no queda «saltado»: si se aceptara, el mes de verdad se generaría igual. */
    public function test_un_periodo_mal_escrito_da_error_y_no_se_guarda(): void
    {
        $u = $this->operador();
        $this->en('2026-03-01');
        $regla = $this->crear($u);

        foreach (['2026-13', 'marzo', '2026-3', '2026-W10'] as $periodo) {
            $this->actingAs($u)->post(route('gastos.reglas.omitir', $regla), ['periodo' => $periodo, 'motivo' => 'Motivo de prueba.'])
                ->assertSessionHasErrors('periodo');
        }
        $this->actingAs($u)->post(route('gastos.reglas.omitir', $regla), ['periodo' => '2026-03', 'motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, Ocurrencia::count());
    }

    /**
     * Una regla anual muestra seis años en «Lo que viene», cada uno con su «Saltar». El
     * último también se tiene que poder saltar: daba «Ese período no le toca a esta regla».
     */
    public function test_en_una_regla_anual_se_salta_el_ultimo_anio_que_ofrece_lo_que_viene(): void
    {
        $u = $this->operador();
        $this->en('2026-11-25');
        $regla = $this->crear($u, ['nombre' => 'Permiso de prueba', 'frecuencia' => 'anual', 'mes' => 3, 'dia_mes' => 15]);

        $ultimo = collect($this->actingAs($u)->get(route('gastos.reglas.show', $regla))->assertOk()->viewData('proximos'))->last();
        $this->assertSame('2032', $ultimo['periodo']);

        $this->actingAs($u)->post(route('gastos.reglas.omitir', $regla), ['periodo' => '2032', 'motivo' => 'Ese año el permiso ya no se renueva.'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $omitida = Ocurrencia::where('regla_id', $regla->id)->sole();
        $this->assertSame(['2032', 'omitida', '2032-03-15'], [$omitida->periodo, $omitida->estado, $omitida->vence->format('Y-m-d')]);
        $this->assertNull($omitida->gasto_id);
    }

    // ─────────────────────────── gastos.avisos.leer-todos ───────────────────────────

    private function aviso(User $u, Gasto $gasto, ?string $leido = null): Aviso
    {
        return Aviso::create([
            'clave' => (string) Str::uuid(), 'usuario_id' => $u->id, 'tipo' => 'vencido', 'gasto_id' => $gasto->id,
            'titulo' => 'Aviso de prueba', 'detalle' => 'Detalle de prueba', 'vence' => '2026-03-05',
            'importe' => '100.00', 'moneda' => 'USD', 'leido_at' => $leido, 'created_at' => now(),
        ]);
    }

    public function test_marcar_todos_como_leidos_solo_toca_los_avisos_propios(): void
    {
        $yo = $this->operador();
        $otra = $this->operador();
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor de prueba', 'concepto' => 'Concepto de prueba', 'categoria' => 'Servicios',
            'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '100.00',
            'documentacion' => 'pendiente', 'responsable_id' => $yo->id, 'registrado_por' => $yo->id,
        ]);
        $gasto->cuotas()->create(['numero' => 1, 'importe' => '100.00', 'vence' => '2026-03-05']);
        $mios = [$this->aviso($yo, $gasto), $this->aviso($yo, $gasto)];
        $yaLeido = $this->aviso($yo, $gasto, '2026-03-06 08:00:00');
        $deOtra = $this->aviso($otra, $gasto);

        $this->en('2026-03-10');
        $this->actingAs($yo)->post(route('gastos.avisos.leer-todos'))
            ->assertRedirect()->assertSessionHas('gastos.aviso');

        foreach ($mios as $aviso) {
            $this->assertNotNull($aviso->fresh()->leido_at);
        }
        $this->assertSame('2026-03-06 08:00:00', $yaLeido->fresh()->leido_at->format('Y-m-d H:i:s'), 'Lo ya leído conserva cuándo se leyó.');
        $this->assertNull($deOtra->fresh()->leido_at, 'El aviso de otra persona no se toca.');
        // Marcar leído no cambia ninguna deuda.
        $this->assertSame(10000, app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-03-10')['pendiente']);
    }

    public function test_leer_todos_sin_permiso_de_gastos_da_403(): void
    {
        $sinGastos = $this->usuario([PermisoSistema::DteVer]);

        $this->actingAs($sinGastos)->post(route('gastos.avisos.leer-todos'))->assertForbidden();
    }
}
