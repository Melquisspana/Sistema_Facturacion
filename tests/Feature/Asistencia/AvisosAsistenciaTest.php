<?php

namespace Tests\Feature\Asistencia;

use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Enums\Asistencia\TipoMarcacion;
use App\Facades\Ajustes;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los avisos suaves de «Marcas de hoy» (issue #87, etapa 2): quién llega tarde seguido y
 * a quién le faltan horas al cierre de la quincena, con la hora de entrada, la tolerancia
 * y las horas por día de Configuración › Módulos › Asistencia. Solo informan: no frenan
 * nada ni tocan Planilla. Guardado en UTC y día de negocio en El Salvador. Datos ficticios.
 */
class AvisosAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private string $zonaPhp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zonaPhp);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hoyEs(string $fechaLocal): void
    {
        // En UTC: un «ahora» fijado en otra zona hace que Carbon lea las fechas en esa zona.
        Carbon::setTestNow(Carbon::parse($fechaLocal.' 15:00:00', 'America/El_Salvador')->utc());
    }

    private function persona(string $nombre, string $ingreso = '2026-01-05', bool $activo = true): AsistenciaEmpleado
    {
        return AsistenciaEmpleado::factory()->create([
            'nombres' => $nombre, 'apellidos' => 'Prueba', 'fecha_ingreso' => $ingreso, 'activo' => $activo,
        ]);
    }

    private function jornada(AsistenciaEmpleado $persona, string $dia, string $entra, ?string $sale = null): void
    {
        AsistenciaMarcacion::factory()->en(Carbon::parse("$dia $entra", 'America/El_Salvador'))->tipo(TipoMarcacion::Entrada)
            ->create(['asistencia_empleado_id' => $persona->id]);
        if ($sale !== null) {
            AsistenciaMarcacion::factory()->en(Carbon::parse("$dia $sale", 'America/El_Salvador'))->tipo(TipoMarcacion::Salida)
                ->create(['asistencia_empleado_id' => $persona->id]);
        }
    }

    private function avisos(): array
    {
        $usuario = User::factory()->create(['activo' => true])->givePermissionTo('asistencia.ver');

        return $this->actingAs($usuario)->get(route('asistencia.dashboard'))->assertOk()->viewData('avisos');
    }

    /** Los diez días hábiles del 26/10 al 06/11 de 2026 (lunes a viernes). */
    private const DIEZ_DIAS = ['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30',
        '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'];

    public function test_avisa_a_quien_llego_tarde_seguido_con_la_hora_y_la_tolerancia_por_defecto(): void
    {
        $this->hoyEs('2026-11-06');
        $ana = $this->persona('Ana');
        $beto = $this->persona('Beto');
        // Ana, cuatro de diez después de las 08:10. Beto, dos y una justo a las 08:10, que no es tarde.
        foreach (self::DIEZ_DIAS as $i => $dia) {
            $this->jornada($ana, $dia, $i < 4 ? '08:25:00' : '07:58:00', '17:00:00');
            $this->jornada($beto, $dia, match ($i) {
                0, 1 => '08:40:00', 2 => '08:10:59', default => '08:00:00'
            }, '17:00:00');
        }
        // Un día viejo de Ana, tarde: queda fuera de sus últimos diez.
        $this->jornada($ana, '2026-10-23', '09:30:00', '17:00:00');

        $avisos = $this->avisos();

        $this->assertEquals([['nombre' => 'Ana Prueba', 'tarde' => 4, 'dias' => 10]], $avisos['tardanzas']);
        $this->assertSame('08:10', $avisos['limite']);
        $this->actingAs(User::factory()->create(['activo' => true])->givePermissionTo('asistencia.ver'))
            ->get(route('asistencia.dashboard'))
            ->assertSee('Ana Prueba llegó después de las 08:10 en 4 de los últimos 10 días que marcó.');
    }

    public function test_cambiar_la_hora_de_entrada_o_la_tolerancia_cambia_el_aviso_de_tardanzas(): void
    {
        $this->hoyEs('2026-11-06');
        $ana = $this->persona('Ana');
        foreach (self::DIEZ_DIAS as $i => $dia) {
            $this->jornada($ana, $dia, $i < 4 ? '08:25:00' : '07:58:00', '17:00:00');
        }

        Ajustes::guardarComoSistema('asistencia.horario.tolerancia_minutos', 30);
        $this->assertSame([], $this->avisos()['tardanzas']);

        Ajustes::guardarComoSistema('asistencia.horario.entrada', '07:30');
        $avisos = $this->avisos();
        $this->assertSame('08:00', $avisos['limite']);
        $this->assertEquals([['nombre' => 'Ana Prueba', 'tarde' => 4, 'dias' => 10]], $avisos['tardanzas']);

        Ajustes::guardarComoSistema('asistencia.horario.entrada', '07:00');
        Ajustes::guardarComoSistema('asistencia.horario.tolerancia_minutos', 0);
        $this->assertEquals([['nombre' => 'Ana Prueba', 'tarde' => 10, 'dias' => 10]], $this->avisos()['tardanzas']);
    }

    /**
     * Noviembre de 2026: el 1 es domingo. Del 1 al 15 hay diez días de lunes a viernes y
     * dos sábados, que son medio día: con 8 h por día se esperan 10 × 8 + 2 × 4 = 88 h.
     */
    public function test_al_cerrar_la_quincena_avisa_a_quien_le_faltan_horas(): void
    {
        $this->hoyEs('2026-11-16');
        $ana = $this->persona('Ana');
        $beto = $this->persona('Beto');
        $carla = $this->persona('Carla', '2026-11-12');
        $this->persona('Dario', '2026-01-05', activo: false);
        $habiles = ['02', '03', '04', '05', '06', '09', '10', '11', '12', '13'];
        foreach ($habiles as $i => $d) {
            // Ana faltó un día y trabajó 8 h los demás: 72 h de 88.
            if ($i !== 4) {
                $this->jornada($ana, "2026-11-$d", '08:00:00', '16:00:00');
            }
            $this->jornada($beto, "2026-11-$d", '08:00:00', '16:00:00');
        }
        foreach (['07', '14'] as $sabado) {
            $this->jornada($beto, "2026-11-$sabado", '08:00:00', '12:00:00');
        }
        // Carla entró el jueves 12: se le esperan 8 + 8 + 4 = 20 h, y las hizo.
        $this->jornada($carla, '2026-11-12', '08:00:00', '16:00:00');
        $this->jornada($carla, '2026-11-13', '08:00:00', '16:00:00');
        $this->jornada($carla, '2026-11-14', '08:00:00', '12:00:00');

        $avisos = $this->avisos();

        $this->assertSame(['2026-11-01', '2026-11-15'], [$avisos['quincena']['desde']->format('Y-m-d'), $avisos['quincena']['hasta']->format('Y-m-d')]);
        $this->assertEquals([['nombre' => 'Ana Prueba', 'faltan' => 16, 'exacto' => true]], $avisos['horas']);
        $this->actingAs(User::factory()->create(['activo' => true])->givePermissionTo('asistencia.ver'))
            ->get(route('asistencia.dashboard'))
            ->assertSee('Del 01/11/2026 al 15/11/2026, Ana Prueba tiene 16 h menos de lo esperado.');

        // Con 7 h por día se esperan 77 h: a Ana le faltan 5, menos de un día, y no se avisa.
        Ajustes::guardarComoSistema('asistencia.horario.horas_por_dia', 7);
        $this->assertSame([], $this->avisos()['horas']);
    }

    public function test_la_quincena_que_se_mira_es_la_que_cierra_hoy_o_la_ultima_cerrada(): void
    {
        // Sin una sola marca: siempre le faltan horas, y el aviso dice qué días se miraron.
        $this->persona('Ana');
        $rango = function (string $hoy): array {
            $this->hoyEs($hoy);
            $q = $this->avisos()['quincena'];

            return [$q['desde']->format('Y-m-d'), $q['hasta']->format('Y-m-d')];
        };

        // El día de cierre se mira hasta ayer: el día de hoy todavía no terminó.
        $this->assertSame(['2026-11-01', '2026-11-14'], $rango('2026-11-15'));
        $this->assertSame(['2026-11-01', '2026-11-15'], $rango('2026-11-20'));
        $this->assertSame(['2026-11-16', '2026-11-29'], $rango('2026-11-30'));
        $this->assertSame(['2026-10-16', '2026-10-31'], $rango('2026-11-10'));
    }

    public function test_sin_nada_que_avisar_no_aparece_la_seccion(): void
    {
        $this->hoyEs('2026-11-06');

        $usuario = User::factory()->create(['activo' => true])->givePermissionTo('asistencia.ver');
        $this->actingAs($usuario)->get(route('asistencia.dashboard'))->assertOk()->assertDontSee('Para tener en cuenta');
    }

    public function test_los_tres_ajustes_estan_en_configuracion_con_sus_valores_por_defecto(): void
    {
        $this->assertSame('08:00', Ajustes::texto('asistencia.horario.entrada'));
        $this->assertSame(10, Ajustes::entero('asistencia.horario.tolerancia_minutos'));
        $this->assertSame(8, Ajustes::entero('asistencia.horario.horas_por_dia'));

        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($admin)->get(route('configuracion.opciones.asistencia'))->assertOk()
            ->assertSee('Hora de entrada')->assertSee('Minutos de tolerancia')->assertSee('Horas de trabajo por día');
    }

    public function test_el_servidor_rechaza_un_horario_que_no_tiene_sentido(): void
    {
        foreach ([['asistencia.horario.entrada', '8 de la mañana'], ['asistencia.horario.entrada', '25:00'],
            ['asistencia.horario.tolerancia_minutos', 61], ['asistencia.horario.horas_por_dia', 0],
            ['asistencia.horario.horas_por_dia', 13]] as [$clave, $valor]) {
            try {
                Ajustes::guardarComoSistema($clave, $valor);
                $this->fail("Se aceptó {$valor} en {$clave}.");
            } catch (ValorAjusteInvalidoException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('08:00', Ajustes::texto('asistencia.horario.entrada'));
    }
}
