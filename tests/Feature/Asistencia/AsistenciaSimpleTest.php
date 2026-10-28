<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use App\Support\Asistencia\PanelAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AsistenciaSimpleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Guardado en UTC y día de negocio en El Salvador, sin depender del .env. La zona de
        // guardado se cambia ENTERA (config y la de PHP): con solo la config, Eloquent escribe
        // en una zona y lee en otra, y las horas salen corridas según la máquina.
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    private string $zonaPhp;

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zonaPhp);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['asistencia.ver']): User
    {
        return User::factory()->create(['activo' => true])->givePermissionTo($permisos);
    }

    private function marcar(AsistenciaEmpleado $persona, string $local, TipoMarcacion $tipo): void
    {
        AsistenciaMarcacion::factory()->en(Carbon::parse($local, 'America/El_Salvador'))->tipo($tipo)
            ->create(['asistencia_empleado_id' => $persona->id]);
    }

    public function test_hoy_usa_el_dia_local_y_distingue_salida_y_personas_sin_marcar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 05:30:00', 'UTC'));
        $ana = AsistenciaEmpleado::factory()->create(['nombres' => 'María de los Ángeles', 'apellidos' => 'Hernández Villalobos']);
        $adentro = AsistenciaEmpleado::factory()->create();
        $falta = AsistenciaEmpleado::factory()->create();
        AsistenciaEmpleado::factory()->inactivo()->create();
        $this->marcar($ana, '2026-10-28 18:00:00', TipoMarcacion::Entrada);
        $this->marcar($ana, '2026-10-28 23:00:00', TipoMarcacion::Salida);
        $this->marcar($adentro, '2026-10-28 23:15:00', TipoMarcacion::Entrada);
        $this->marcar($falta, '2026-10-27 23:00:00', TipoMarcacion::Entrada);

        $hoy = app(PanelAsistencia::class)->hoy();
        $this->assertSame('2026-10-28', $hoy['fecha_hoy']);
        $this->assertSame(2, $hoy['conteos']['marcaron']);
        $this->assertSame(1, $hoy['conteos']['faltan']);
        $this->assertSame(3, $hoy['conteos']['activos']);
        $this->assertSame([$falta->id], $hoy['faltan']->modelKeys());
        $this->actingAs($this->usuario())->get(route('asistencia.dashboard'))->assertOk()
            ->assertSee($ana->nombreCompleto())->assertSee('18:00')->assertSee('23:00')->assertSee('5 h 00 min')
            ->assertSee('sigue adentro')->assertSee('Todavía no marcaron');
    }

    public function test_reporte_cuenta_dias_suma_tramos_incluye_inactivos_y_advierte_minimo(): void
    {
        $ana = AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'María de los Ángeles', 'apellidos' => 'Hernández Villalobos']);
        foreach ([['07:00:00', TipoMarcacion::Entrada], ['12:00:00', TipoMarcacion::Salida], ['13:00:00', TipoMarcacion::Entrada], ['16:00:00', TipoMarcacion::Salida]] as [$hora, $tipo]) {
            $this->marcar($ana, '2026-10-01 '.$hora, $tipo);
        }
        $this->marcar($ana, '2026-10-02 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($ana, '2026-09-30 07:00:00', TipoMarcacion::Entrada);
        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()->assertSee($ana->nombreCompleto())->assertSee('8.00')->assertSee('mínimo');
        $fila = $respuesta->viewData('filas')->sole();
        $this->assertSame(2, $fila['dias']);
        $this->assertSame(8.0, $fila['horas']);
        $this->assertFalse($fila['exacto']);
        $respuesta->assertSee(route('asistencia.jornadas.index', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']));
        $respuesta->assertSee(route('asistencia.marcaciones.index', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']));
    }

    public function test_excel_descarga_el_mismo_reporte_con_texto_y_horas_numericas(): void
    {
        $ana = AsistenciaEmpleado::factory()->create(['nombres' => 'María de los Ángeles', 'apellidos' => 'Hernández Villalobos']);
        $this->marcar($ana, '2026-10-01 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($ana, '2026-10-01 15:30:00', TipoMarcacion::Salida);
        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.reporte.excel', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()->assertDownload('asistencia_2026-10-01_2026-10-31.xlsx');
        $archivo = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $libro = IOFactory::load($archivo);
            $hoja = $libro->getActiveSheet();
            $this->assertSame(['Persona', 'Días trabajados', 'Horas', 'Tiempo'], $hoja->rangeToArray('A1:D1')[0]);
            $this->assertSame($ana->nombreCompleto(), $hoja->getCell('A2')->getValue());
            $this->assertSame('s', $hoja->getCell('A2')->getDataType());
            $this->assertSame(1, $hoja->getCell('B2')->getValue());
            $this->assertEquals(8.5, $hoja->getCell('C2')->getValue());
            $this->assertSame('n', $hoja->getCell('C2')->getDataType());
            $libro->disconnectWorksheets();
        } finally {
            @unlink($archivo);
        }
    }

    public function test_reporte_valida_formato_orden_y_maximo_inclusivo_de_366_dias(): void
    {
        $this->actingAs($this->usuario());
        foreach (['asistencia.reporte', 'asistencia.reporte.excel'] as $ruta) {
            foreach ([['2026-10-01', '2026-09-30'], ['2025-01-01', '2026-01-02'], ['01/10/2026', '2026-10-31'], ['2026-02-30', '2026-03-01']] as [$desde, $hasta]) {
                $this->getJson(route($ruta, compact('desde', 'hasta')))->assertUnprocessable();
            }
        }
        $this->get(route('asistencia.reporte', ['desde' => '2025-01-01', 'hasta' => '2026-01-01']))->assertOk();
    }

    public function test_el_mes_por_defecto_es_el_local_incluso_en_el_borde_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 05:30:00', 'UTC'));
        $this->actingAs($this->usuario())->get(route('asistencia.reporte'))->assertOk()
            ->assertViewHas('filtro', fn ($f) => $f->desde->format('Y-m-d') === '2026-10-01' && $f->hasta->format('Y-m-d') === '2026-10-31');
    }

    public function test_ficha_muestra_huellas_numeradas_y_encierra_los_controles_tecnicos(): void
    {
        $ana = AsistenciaEmpleado::factory()->create();
        AsistenciaHuella::factory()->count(2)->create(['asistencia_empleado_id' => $ana->id]);
        $html = $this->actingAs($this->usuario(['asistencia.ver', 'asistencia.gestionar']))
            ->get(route('asistencia.empleados.show', $ana))->assertOk()->assertSee('Huella 1')->assertSee('Huella 2')
            ->assertSee('Guardar huella')->assertSee('Quitar')->assertSee('Avanzado')->getContent();
        $this->assertStringNotContainsString('ranura', mb_strtolower(preg_replace('/<details\b[^>]*>.*?<\/details>/si', '', $html)));
        $this->assertDoesNotMatchRegularExpression('/<details\b[^>]*\bopen\b/i', $html);
    }

    public function test_orden_viva_muestra_el_aviso_de_cancelacion_sin_datos_tecnicos(): void
    {
        $ana = AsistenciaEmpleado::factory()->create();
        $lector = AsistenciaDispositivo::factory()->create(['capacidad_sensor' => 300, 'ranuras_ocupadas' => [], 'indice_sincronizado_at' => now()]);
        $this->actingAs($this->usuario(['asistencia.ver', 'asistencia.gestionar']))
            ->post(route('asistencia.empleados.enrolamiento.store', $ana), ['asistencia_dispositivo_id' => $lector->id])->assertSessionHasNoErrors();
        $html = $this->get(route('asistencia.empleados.show', $ana))->assertOk()
            ->assertSee('No canceles mientras el lector pide el dedo')->assertSee('sin dueño')->assertSee('Cancelar')->getContent();
        $this->assertStringNotContainsString('ranura', mb_strtolower(preg_replace('/<details\b[^>]*>.*?<\/details>/si', '', $html)));
    }

    public function test_los_avisos_tecnicos_de_la_ficha_quedan_en_avanzado(): void
    {
        $ana = AsistenciaEmpleado::factory()->create();
        $html = $this->actingAs($this->usuario(['asistencia.ver', 'asistencia.gestionar']))
            ->withSession(['error' => 'La ranura está ocupada.'])
            ->get(route('asistencia.empleados.show', $ana))->assertOk()->assertSee('La ranura está ocupada.')->getContent();
        $this->assertStringNotContainsString('ranura', mb_strtolower(preg_replace('/<details\b[^>]*>.*?<\/details>/si', '', $html)));
        // El error no queda escondido: Avanzado se abre solo.
        $this->assertMatchesRegularExpression('/<details\b[^>]*\bopen\b/i', $html);
    }

    public function test_reporte_con_un_solo_extremo_completa_el_mes(): void
    {
        $this->actingAs($this->usuario())->get(route('asistencia.reporte', ['hasta' => '2026-02-10']))->assertOk()
            ->assertViewHas('filtro', fn ($f) => $f->desde->format('Y-m-d') === '2026-02-01' && $f->hasta->format('Y-m-d') === '2026-02-10');
        $this->get(route('asistencia.reporte', ['desde' => '2026-02-10']))->assertOk()
            ->assertViewHas('filtro', fn ($f) => $f->desde->format('Y-m-d') === '2026-02-10' && $f->hasta->format('Y-m-d') === '2026-02-28');
    }

    public function test_una_nueva_entrada_despues_del_almuerzo_muestra_sigue_adentro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 20:00:00', 'UTC'));
        $ana = AsistenciaEmpleado::factory()->create();
        $this->marcar($ana, '2026-10-28 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($ana, '2026-10-28 12:00:00', TipoMarcacion::Salida);
        $this->marcar($ana, '2026-10-28 13:00:00', TipoMarcacion::Entrada);
        $fila = app(PanelAsistencia::class)->hoy()['marcaron']->sole();
        $this->assertNull($fila['ultima_salida']);
        $this->assertSame('5 h 00 min', $fila['horas']);
    }

    public function test_el_enlace_de_lectores_en_configuracion_respeta_permiso_e_interruptor(): void
    {
        $this->actingAs($this->usuario(['configuracion.gestionar']))
            ->get(route('configuracion.resumen'))->assertOk()->assertDontSee(route('configuracion.lectores.index'));
        $this->actingAs($this->usuario(['configuracion.gestionar', 'asistencia.dispositivos.gestionar']))
            ->get(route('configuracion.resumen'))->assertOk()->assertSee(route('configuracion.lectores.index'));
        config(['asistencia.enabled' => false]);
        $this->get(route('configuracion.resumen'))->assertOk()->assertDontSee(route('configuracion.lectores.index'));
    }

    public function test_las_pantallas_de_consulta_exigen_ver_y_se_apagan_con_el_modulo(): void
    {
        $ana = AsistenciaEmpleado::factory()->create();
        $urls = [route('asistencia.dashboard'), route('asistencia.empleados.index'), route('asistencia.empleados.show', $ana), route('asistencia.reporte'), route('asistencia.reporte.excel')];
        $this->actingAs($this->usuario([]));
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs($this->usuario());
        config(['asistencia.enabled' => false]);
        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_lectores_exigen_ambos_permisos_en_todas_sus_acciones(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $acciones = [['get', 'index'], ['get', 'create'], ['post', 'store'], ['get', 'edit'], ['put', 'update'], ['patch', 'toggle-activo'], ['get', 'rotar-token'], ['post', 'rotar-token.ejecutar']];
        foreach ([['asistencia.dispositivos.gestionar'], ['configuracion.gestionar']] as $permisos) {
            $this->actingAs($this->usuario($permisos));
            foreach ($acciones as [$verbo, $accion]) {
                $this->$verbo(route('configuracion.lectores.'.$accion, in_array($accion, ['index', 'create', 'store']) ? [] : $lector))->assertForbidden();
            }
        }
        $this->actingAs($this->usuario(['configuracion.gestionar', 'asistencia.dispositivos.gestionar']))
            ->get(route('configuracion.lectores.index'))->assertOk()->assertSee('Lectores de huella');
        config(['asistencia.enabled' => false]);
        $this->get(route('configuracion.lectores.index'))->assertNotFound();
    }

    public function test_las_get_antiguas_redirigen_permanentemente_y_las_escrituras_desaparecen(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $this->actingAs($this->usuario());
        $this->get('/asistencia/dispositivos')->assertStatus(301)->assertRedirect(route('configuracion.lectores.index'));
        $this->get('/asistencia/dispositivos/'.$lector->id.'/editar')->assertStatus(301)->assertRedirect(route('configuracion.lectores.edit', $lector));
        foreach (Route::getRoutes() as $ruta) {
            if (str_starts_with($ruta->uri(), 'asistencia/dispositivos')) {
                $this->assertEmpty(array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $ruta->methods()));
            }
        }
    }

    public function test_el_contrato_http_del_lector_permanece_fijo(): void
    {
        $esperadas = [
            'ping' => ['api/asistencia/ping', ['GET', 'POST', 'HEAD']],
            'marcar' => ['api/asistencia/marcar', ['POST']],
            'enrolamiento.pendiente' => ['api/asistencia/enrolamiento/pendiente', ['GET', 'HEAD']],
            'enrolamiento.progreso' => ['api/asistencia/enrolamiento/{orden}/progreso', ['POST']],
            'enrolamiento.resultado' => ['api/asistencia/enrolamiento/{orden}/resultado', ['POST']],
            'enrolamiento.indice-sensor' => ['api/asistencia/enrolamiento/indice-sensor', ['POST']],
        ];
        $actuales = [];
        foreach (Route::getRoutes() as $ruta) {
            if (str_starts_with($ruta->getName() ?? '', 'api.asistencia.')) {
                $actuales[substr($ruta->getName(), strlen('api.asistencia.'))] = [$ruta->uri(), $ruta->methods()];
                $this->assertContains('modulo.asistencia', $ruta->gatherMiddleware());
                if ($ruta->getName() !== 'api.asistencia.ping') {
                    $this->assertContains('dispositivo.asistencia', $ruta->gatherMiddleware());
                }
            }
        }
        $this->assertSame($esperadas, $actuales);
    }
}
