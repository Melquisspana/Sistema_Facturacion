<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use App\Support\Asistencia\ConsultaAsistencia;
use App\Support\Asistencia\FiltroAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La PANTALLA de historial.
 *
 * Las reglas de la consulta ya están probadas sin HTTP en
 * {@see ConsultaAsistenciaTest}; acá se comprueba lo que solo se rompe por la vía
 * web: que los filtros del formulario llegan a la capa, que la pantalla dice el
 * estado REAL de los datos incómodos, que consultar no escribe nada y que
 * APPEND-ONLY no es una promesa sino la ausencia de rutas.
 */
class HistorialMarcacionesTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $entrada;

    private AsistenciaDispositivo $bodega;

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

        $this->entrada = AsistenciaDispositivo::factory()->create(['codigo' => 'entrada', 'nombre' => 'Entrada principal']);
        $this->bodega = AsistenciaDispositivo::factory()->create(['codigo' => 'bodega', 'nombre' => 'Bodega']);
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

    private function marcar(
        AsistenciaEmpleado $empleado,
        string $instanteLocal,
        TipoMarcacion $tipo = TipoMarcacion::Entrada,
        ?AsistenciaDispositivo $lector = null,
        string $origen = 'dispositivo',
        ?AsistenciaHuella $huella = null,
    ): AsistenciaMarcacion {
        return AsistenciaMarcacion::factory()
            ->en(Carbon::parse($instanteLocal, config('asistencia.zona_horaria')))
            ->tipo($tipo)
            ->create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $lector?->id,
                'asistencia_huella_id' => $huella?->id,
                'origen' => $origen,
            ]);
    }

    // ─────────────────────────────── Pantalla ───────────────────────────────

    public function test_la_pantalla_lista_las_marcaciones(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:02:10', lector: $this->entrada);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('05/03/2026')
            ->assertSee('07:02:10')
            ->assertSee('Entrada principal');
    }

    public function test_el_estado_vacio_se_explica(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Todavía no hay marcaciones registradas', escape: false);
    }

    public function test_con_filtros_y_sin_resultados_lo_dice_distinto(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', ['tipo' => 'salida']))
            ->assertOk()
            ->assertSee('Ninguna marcación coincide con estos filtros.');
    }

    // ──────────────────────── Los filtros llegan a la capa ────────────────────────

    /**
     * Se comprueba sobre las FILAS que la vista va a pintar, no sobre el HTML de la
     * página: el desplegable de filtro lista a todo el mundo —a propósito, para
     * poder consultar a quien ya no trabaja acá— así que buscar un nombre en la
     * respuesta entera siempre lo encontraría y la prueba no probaría nada.
     */
    public function test_el_filtro_de_empleado_aisla(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->marcar($this->beto, '2026-03-05 08:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', ['empleado_id' => $this->ana->id]))
            ->assertOk()
            ->assertViewHas('marcaciones', fn ($marcaciones) => $marcaciones->pluck('asistencia_empleado_id')->unique()->all() === [$this->ana->id]
            );
    }

    public function test_el_filtro_de_rango_es_inclusivo_en_la_pantalla(): void
    {
        $this->marcar($this->ana, '2026-03-04 08:00:00');
        $this->marcar($this->beto, '2026-03-05 08:00:00');

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', ['desde' => '2026-03-05', 'hasta' => '2026-03-05']))
            ->assertOk()
            ->assertViewHas('marcaciones', fn ($marcaciones) => $marcaciones->count() === 1
                && $marcaciones->first()->asistencia_empleado_id === $this->beto->id
            );
    }

    public function test_los_filtros_se_combinan_en_la_pantalla(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada, $this->entrada);
        $this->marcar($this->beto, '2026-03-05 07:00:00', TipoMarcacion::Entrada, $this->entrada);
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida, $this->entrada);
        $this->marcar($this->ana, '2026-03-05 09:00:00', TipoMarcacion::Entrada, $this->bodega);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', [
                'empleado_id' => $this->ana->id,
                'dispositivo_id' => $this->entrada->id,
                'tipo' => 'entrada',
                'desde' => '2026-03-01',
                'hasta' => '2026-03-31',
            ]))
            ->assertOk()
            // Una sola fila: el resumen lo dice sin tener que contar filas de HTML.
            ->assertSee('Empleado: Ana Pérez')
            ->assertSee('Solo entradas');

        // Y el conteo cuadra.
        $this->assertSame(1, app(ConsultaAsistencia::class)->contar(
            FiltroAsistencia::desdeArray([
                'empleado_id' => $this->ana->id,
                'dispositivo_id' => $this->entrada->id,
                'tipo' => 'entrada',
                'desde' => '2026-03-01',
                'hasta' => '2026-03-31',
            ])
        ));
    }

    public function test_un_rango_al_reves_se_rechaza_con_un_mensaje(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', ['desde' => '2026-03-31', 'hasta' => '2026-03-01']))
            ->assertSessionHasErrors('hasta');
    }

    public function test_un_empleado_inexistente_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index', ['empleado_id' => 99999]))
            ->assertSessionHasErrors('empleado_id');
    }

    // ─────────────────────── Datos incómodos, estado real ───────────────────────

    /** Quien ya no trabaja acá aparece en el historial, y marcado como inactivo. */
    public function test_un_empleado_inactivo_sigue_en_el_historial_y_se_señala(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada);
        $this->ana->update(['activo' => false]);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('(inactivo)');
    }

    /** Y se puede filtrar por él: el desplegable no esconde a los inactivos. */
    public function test_el_desplegable_incluye_a_los_empleados_inactivos(): void
    {
        $this->ana->update(['activo' => false]);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Ana Pérez (inactivo)', escape: false);
    }

    /**
     * Una marcación hecha con una ranura que después se liberó y se le dio a otra
     * persona: la pantalla muestra la ranura con la que se identificó ESE día y
     * avisa de que la asignación ya no está vigente. No se reasigna al nuevo dueño.
     */
    public function test_una_marcacion_con_huella_liberada_muestra_el_estado_real(): void
    {
        $huella = app(AsignarHuella::class)($this->ana, $this->entrada, 7);
        $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada, huella: $huella);

        app(LiberarHuella::class)($huella);
        app(AsignarHuella::class)($this->beto, $this->entrada, 7);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('asignación liberada')
            // La marcación sigue colgando de la asignación de Ana, no de la de Beto,
            // aunque la ranura 7 sea de Beto hoy.
            ->assertViewHas('marcaciones', function ($marcaciones) use ($huella) {
                $fila = $marcaciones->first();

                return $fila->asistencia_empleado_id === $this->ana->id
                    && $fila->asistencia_huella_id === $huella->id
                    && $fila->huella->fingerprint_id === 7
                    && $fila->huella->activo === false;
            });
    }

    /** Sin lector no se inventa uno: se dice que fue una corrección manual. */
    public function test_una_marcacion_manual_se_identifica_como_tal(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', origen: 'manual');

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Manual');
    }

    /**
     * Si el lector se borrara de la base (`nullOnDelete`), la marcación sigue
     * siendo de un aparato aunque ya no se pueda decir cuál. No es lo mismo que
     * una corrección manual y la pantalla no los confunde.
     */
    public function test_una_marcacion_de_un_lector_desaparecido_no_se_confunde_con_una_manual(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', origen: 'dispositivo');

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Lector no disponible')
            ->assertDontSee('Manual');
    }

    // ──────────────────────────── Append-only ────────────────────────────

    /**
     * No hay forma de editar ni borrar una marcación: no existe la ruta. 404 y no
     * 405 porque ni siquiera hay una URL registrada para ese recurso.
     */
    #[DataProvider('verbosDeEscritura')]
    public function test_no_existe_ninguna_ruta_que_modifique_una_marcacion(string $verbo): void
    {
        $marcacion = $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada);

        $this->actingAs($this->admin())
            ->$verbo('/asistencia/marcaciones/'.$marcacion->id)
            ->assertNotFound();

        $this->assertDatabaseHas('asistencia_marcaciones', [
            'id' => $marcacion->id,
            'tipo' => TipoMarcacion::Entrada->value,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function verbosDeEscritura(): array
    {
        return [
            'editar' => ['put'],
            'editar parcialmente' => ['patch'],
            'borrar' => ['delete'],
        ];
    }

    /**
     * Consultar no toca el libro. Se compara la tabla ENTERA antes y después de una
     * ronda de consultas con filtros: si alguna escribiera —una marca de «visto»,
     * un contador, lo que sea— la comparación lo delata.
     */
    public function test_consultar_el_historial_no_modifica_ningun_registro(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada, $this->entrada);
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida, $this->entrada);
        $this->marcar($this->beto, '2026-03-06 08:00:00', TipoMarcacion::Entrada, $this->bodega);

        $antes = AsistenciaMarcacion::query()->orderBy('id')->get()->toArray();

        $admin = $this->admin();
        foreach ([
            [],
            ['empleado_id' => $this->ana->id],
            ['desde' => '2026-03-01', 'hasta' => '2026-03-31'],
            ['dispositivo_id' => $this->entrada->id, 'tipo' => 'salida'],
            ['origen' => 'dispositivo'],
        ] as $filtros) {
            $this->actingAs($admin)->get(route('asistencia.marcaciones.index', $filtros))->assertOk();
        }

        $this->assertEquals($antes, AsistenciaMarcacion::query()->orderBy('id')->get()->toArray());
    }

    // ──────────────────────── Desde la ficha del empleado ────────────────────────

    public function test_la_ficha_muestra_las_ultimas_marcaciones_de_la_persona(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:02:10', lector: $this->entrada);
        $this->marcar($this->beto, '2026-03-05 08:00:00', lector: $this->entrada);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertSee('Últimas marcaciones')
            ->assertSee('07:02:10')
            // Solo las suyas.
            ->assertDontSee('08:00:00');
    }

    public function test_la_ficha_enlaza_al_historial_ya_filtrado_por_esa_persona(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertSee(route('asistencia.marcaciones.index', ['empleado_id' => $this->ana->id]));
    }

    public function test_la_ficha_de_quien_nunca_marco_lo_dice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertSee('todavía no ha marcado ninguna vez', escape: false);
    }

    // ──────────────────────────── Autorización ────────────────────────────

    public function test_un_invitado_va_al_login(): void
    {
        $this->get(route('asistencia.marcaciones.index'))->assertRedirect(route('login'));
    }

    public function test_sin_permiso_de_entrada_no_se_consulta(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true]))
            ->get(route('asistencia.marcaciones.index'))
            ->assertForbidden();
    }

    /** `asistencia.ver` alcanza: consultar el historial no escribe nada. */
    public function test_quien_solo_ve_puede_consultar_el_historial(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada);

        $this->actingAs($this->soloVer())
            ->get(route('asistencia.marcaciones.index'))
            ->assertOk()
            ->assertSee('Ana Pérez');
    }

    public function test_con_el_modulo_apagado_el_historial_no_existe(): void
    {
        config()->set('asistencia.enabled', false);

        $this->actingAs($this->admin())
            ->get(route('asistencia.marcaciones.index'))
            ->assertNotFound();
    }
}
