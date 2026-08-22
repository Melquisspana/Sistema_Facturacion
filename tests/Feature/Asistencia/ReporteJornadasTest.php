<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Enums\Asistencia\TipoMarcacion;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use App\Support\Asistencia\ConsultaJornadas;
use App\Support\Asistencia\FiltroAsistencia;
use App\Support\Asistencia\Jornada;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La PANTALLA de jornadas.
 *
 * Las reglas ya están probadas sin HTTP en {@see ConsultaJornadasTest}. Acá se
 * comprueba lo que solo se rompe por la vía web, y sobre todo LA GARANTÍA DE ESTA
 * FASE: que la pantalla y la capa reutilizable devuelven **exactamente lo mismo**.
 * Ese es el contrato que hace posible que el módulo de Formatos consuma la capa en
 * vez de reimplementar el cálculo — si divergieran, el documento y la pantalla
 * dirían cosas distintas de la misma semana.
 */
class ReporteJornadasTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $ana;

    private AsistenciaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'entrada', 'nombre' => 'Entrada principal']);
        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        $this->beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function soloVer(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    private function marcar(AsistenciaEmpleado $empleado, string $instanteLocal, TipoMarcacion $tipo): AsistenciaMarcacion
    {
        return AsistenciaMarcacion::factory()
            ->en(Carbon::parse($instanteLocal, config('asistencia.zona_horaria')))
            ->tipo($tipo)
            ->create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $this->lector->id,
            ]);
    }

    private function jornadaDe(AsistenciaEmpleado $empleado, string $dia, string $entrada, string $salida): void
    {
        $this->marcar($empleado, "$dia $entrada", TipoMarcacion::Entrada);
        $this->marcar($empleado, "$dia $salida", TipoMarcacion::Salida);
    }

    // ─────────────────────────────── Pantalla ───────────────────────────────

    public function test_la_pantalla_muestra_una_fila_por_jornada(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('05/03/2026')
            ->assertSee('07:00')
            ->assertSee('16:00')
            ->assertSee('9 h 00 min')
            ->assertSee('Completa');
    }

    /** El caso del almuerzo, visto desde la pantalla: 8 h, no 9. */
    public function test_la_pantalla_suma_los_tramos_y_no_la_resta_ingenua(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 12:00:00', TipoMarcacion::Salida);
        $this->marcar($this->ana, '2026-03-05 13:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida);

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('8 h 00 min')
            ->assertDontSee('9 h 00 min');
    }

    /** Una jornada abierta declara que el tiempo mostrado es un mínimo. */
    public function test_una_jornada_abierta_avisa_de_que_el_tiempo_es_un_minimo(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('Abierta')
            ->assertSee('al menos')
            ->assertSee('mínimo: hay jornadas sin cerrar', escape: false);
    }

    /** Sin rango se ofrece el mes en curso: nunca «toda la historia». */
    public function test_sin_rango_la_pantalla_usa_el_mes_en_curso(): void
    {
        $hoy = CarbonImmutable::now(config('asistencia.zona_horaria'));

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index'))
            ->assertOk()
            ->assertViewHas('filtro', fn (FiltroAsistencia $f) => $f->desde->format('Y-m-d') === $hoy->startOfMonth()->format('Y-m-d')
                && $f->hasta->format('Y-m-d') === $hoy->endOfMonth()->format('Y-m-d')
            );
    }

    public function test_el_estado_vacio_explica_por_que_no_hay_filas(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('no hay jornadas que mostrar', escape: false)
            // Y aclara que un día sin marcaciones NO es una ausencia.
            ->assertSee('todavía no sabe qué días', escape: false);
    }

    /** La pantalla no inventa las métricas que harían falta horarios. */
    public function test_la_pantalla_no_muestra_indicadores_que_no_se_pueden_calcular(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $respuesta = $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk();

        foreach (['Tardanza', 'Horas extra', 'Ausente', 'Puntualidad', 'Feriado'] as $inventado) {
            $respuesta->assertDontSee($inventado);
        }
    }

    // ─────────────────────────────── Filtros ───────────────────────────────

    public function test_filtra_por_empleado(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->beto, '2026-03-05', '08:00:00', '17:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', [
                'empleado_id' => $this->ana->id, 'desde' => '2026-03-01', 'hasta' => '2026-03-31',
            ]))
            ->assertOk()
            ->assertViewHas('jornadas', fn ($jornadas) => $jornadas->pluck('empleadoId')->unique()->all() === [$this->ana->id]
            );
    }

    public function test_filtra_por_rango_mensual(): void
    {
        $this->jornadaDe($this->ana, '2026-02-28', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-15', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-04-01', '07:00:00', '16:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertViewHas('jornadas', fn ($jornadas) => $jornadas->count() === 1
                && $jornadas->first()->fecha->format('Y-m-d') === '2026-03-15'
            );
    }

    public function test_filtra_por_un_solo_dia(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-06', '07:00:00', '16:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-05', 'hasta' => '2026-03-05']))
            ->assertOk()
            ->assertViewHas('jornadas', fn ($jornadas) => $jornadas->count() === 1);
    }

    public function test_filtra_por_estado(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->marcar($this->beto, '2026-03-05 07:00:00', TipoMarcacion::Entrada);

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', [
                'estado' => 'abierta', 'desde' => '2026-03-01', 'hasta' => '2026-03-31',
            ]))
            ->assertOk()
            ->assertViewHas('jornadas', fn ($jornadas) => $jornadas->count() === 1
                && $jornadas->first()->empleadoId === $this->beto->id
            );
    }

    public function test_un_rango_al_reves_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-31', 'hasta' => '2026-03-01']))
            ->assertSessionHasErrors('hasta');
    }

    public function test_un_empleado_inactivo_sigue_apareciendo(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->ana->update(['activo' => false]);

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('(inactivo)');
    }

    /** El detalle de una jornada son sus marcaciones: se enlaza a la pantalla que ya existe. */
    public function test_cada_jornada_enlaza_a_sus_marcaciones_de_ese_dia(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee(route('asistencia.marcaciones.index', [
                'empleado_id' => $this->ana->id,
                'desde' => '2026-03-05',
                'hasta' => '2026-03-05',
            ]));
    }

    // ──────────────── LA GARANTÍA: pantalla y capa coinciden ────────────────

    /**
     * Lo que la pantalla pinta tiene que ser IDÉNTICO a lo que la capa reutilizable
     * devuelve. Es el contrato que permite que Formatos consuma
     * {@see ConsultaJornadas} en vez de reimplementar el cálculo: si divergieran,
     * el documento y la pantalla dirían cosas distintas de la misma semana.
     */
    public function test_la_pantalla_y_la_capa_reutilizable_producen_lo_mismo(): void
    {
        // Un conjunto con los tres estados y dos personas.
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');           // completa
        $this->marcar($this->ana, '2026-03-06 07:00:00', TipoMarcacion::Entrada);      // abierta
        $this->marcar($this->beto, '2026-03-05 16:00:00', TipoMarcacion::Salida);      // irregular
        $this->jornadaDe($this->beto, '2026-03-06', '08:00:00', '17:00:00');           // completa

        $filtros = ['desde' => '2026-03-01', 'hasta' => '2026-03-31'];

        $respuesta = $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', $filtros))
            ->assertOk();

        $deLaCapa = app(ConsultaJornadas::class)
            ->porRango(FiltroAsistencia::desdeArray($filtros))
            ->map(fn (Jornada $j) => $j->toArray())
            ->all();

        $deLaPantalla = collect($respuesta->viewData('jornadas')->items())
            ->map(fn (Jornada $j) => $j->toArray())
            ->all();

        $this->assertSame($deLaCapa, $deLaPantalla);

        // Y el resumen de la cabecera sale del mismo sitio.
        $this->assertSame(
            app(ConsultaJornadas::class)->resumen(FiltroAsistencia::desdeArray($filtros)),
            $respuesta->viewData('resumen'),
        );
    }

    /** Lo mismo para una persona concreta: `deEmpleado()` y la pantalla filtrada. */
    public function test_la_capa_por_empleado_coincide_con_la_pantalla_filtrada(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->beto, '2026-03-05', '08:00:00', '17:00:00');

        $filtros = ['empleado_id' => $this->ana->id, 'desde' => '2026-03-01', 'hasta' => '2026-03-31'];

        $respuesta = $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', $filtros))
            ->assertOk();

        $deLaCapa = app(ConsultaJornadas::class)
            ->deEmpleado($this->ana->id, FiltroAsistencia::desdeArray($filtros))
            ->map(fn (Jornada $j) => $j->toArray())
            ->all();

        $this->assertSame(
            $deLaCapa,
            collect($respuesta->viewData('jornadas')->items())->map(fn (Jornada $j) => $j->toArray())->all(),
        );
    }

    // ─────────────────────────── No escribe nada ───────────────────────────

    public function test_ver_el_reporte_no_modifica_ninguna_marcacion(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->marcar($this->beto, '2026-03-05 08:00:00', TipoMarcacion::Entrada);

        $antes = AsistenciaMarcacion::query()->orderBy('id')->get()->toArray();

        $admin = $this->admin();
        foreach ([
            ['desde' => '2026-03-01', 'hasta' => '2026-03-31'],
            ['empleado_id' => $this->ana->id],
            ['estado' => 'abierta'],
            [],
        ] as $filtros) {
            $this->actingAs($admin)->get(route('asistencia.jornadas.index', $filtros))->assertOk();
        }

        $this->assertEquals($antes, AsistenciaMarcacion::query()->orderBy('id')->get()->toArray());
    }

    /** Una jornada es DERIVADA: no hay ruta que la escriba. */
    public function test_no_existe_ninguna_ruta_que_modifique_una_jornada(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $verbo) {
            $this->actingAs($this->admin())
                ->$verbo('/asistencia/jornadas')
                ->assertStatus(405);
        }
    }

    // ──────────────────────────── Autorización ────────────────────────────

    public function test_un_invitado_va_al_login(): void
    {
        $this->get(route('asistencia.jornadas.index'))->assertRedirect(route('login'));
    }

    public function test_sin_permiso_de_entrada_no_se_consulta(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true]))
            ->get(route('asistencia.jornadas.index'))
            ->assertForbidden();
    }

    /** `asistencia.ver` alcanza: el reporte no escribe nada. */
    public function test_quien_solo_ve_puede_consultar_el_reporte(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $this->actingAs($this->soloVer())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
            ->assertOk()
            ->assertSee('Ana Pérez');
    }

    public function test_con_el_modulo_apagado_el_reporte_no_existe(): void
    {
        config()->set('asistencia.enabled', false);

        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index'))
            ->assertNotFound();
    }

    public function test_el_estado_filtrado_se_conserva_en_la_vista(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.jornadas.index', ['estado' => 'irregular']))
            ->assertOk()
            ->assertViewHas('estado', EstadoJornada::Irregular);
    }
}
