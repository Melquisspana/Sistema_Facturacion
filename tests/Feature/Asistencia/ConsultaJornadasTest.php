<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use App\Services\Asistencia\RegistrarMarcacion;
use App\Support\Asistencia\ConsultaJornadas;
use App\Support\Asistencia\FiltroAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LA CAPA DE JORNADAS, probada SIN pasar por HTTP.
 *
 * Igual que la de marcaciones: el punto de que exista es que el módulo de
 * Formatos la use sin ser una petición web. Si solo se probara por la pantalla,
 * nada garantizaría que sirve fuera de ella.
 *
 * ──────────────── Lo que estas pruebas fijan, y por qué ────────────────
 *
 *  1. El TIEMPO es la suma de los tramos, no «última salida − primera entrada».
 *     Con almuerzo de por medio esa resta da 9 horas donde se trabajaron 8, y ese
 *     error va directo a una planilla.
 *  2. Una entrada sin salida NO se cierra con una hora inventada. Se marca la
 *     jornada como abierta y el total se declara mínimo.
 *  3. El TURNO NOCTURNO se deja identificado, no resuelto. Hay una prueba que fija
 *     lo que el sistema hace HOY —incluido que el tipo sale invertido— para que el
 *     día que existan horarios se vea exactamente qué cambia.
 */
class ConsultaJornadasTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $ana;

    private AsistenciaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'entrada', 'nombre' => 'Entrada principal']);
        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        $this->beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
    }

    private function consulta(): ConsultaJornadas
    {
        return app(ConsultaJornadas::class);
    }

    private function zona(): string
    {
        return config('asistencia.zona_horaria');
    }

    /** Una marcación con tipo EXPLÍCITO, para poder construir casos que el lector no produce. */
    private function marcar(
        AsistenciaEmpleado $empleado,
        string $instanteLocal,
        TipoMarcacion $tipo,
        string $origen = 'dispositivo',
        ?AsistenciaHuella $huella = null,
    ): AsistenciaMarcacion {
        return AsistenciaMarcacion::factory()
            ->en(Carbon::parse($instanteLocal, $this->zona()))
            ->tipo($tipo)
            ->create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $origen === 'manual' ? null : $this->lector->id,
                'asistencia_huella_id' => $huella?->id,
                'origen' => $origen,
            ]);
    }

    /** Atajo: una jornada normal de entrada y salida. */
    private function jornadaDe(AsistenciaEmpleado $empleado, string $dia, string $entrada, string $salida): void
    {
        $this->marcar($empleado, "$dia $entrada", TipoMarcacion::Entrada);
        $this->marcar($empleado, "$dia $salida", TipoMarcacion::Salida);
    }

    private function dia(string $fecha): FiltroAsistencia
    {
        return FiltroAsistencia::vacio()->conRango(CarbonImmutable::parse($fecha), CarbonImmutable::parse($fecha));
    }

    // ───────────────────────── Los casos de una jornada ─────────────────────────

    public function test_cero_marcaciones_no_produce_jornada(): void
    {
        $this->assertCount(0, $this->consulta()->porRango($this->dia('2026-03-05')));
        $this->assertNull($this->consulta()->delDia($this->ana->id, CarbonImmutable::parse('2026-03-05')));
    }

    public function test_entrada_y_salida_dan_una_jornada_completa(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Completa, $jornada->estado);
        $this->assertSame(1, $jornada->paresCompletos());
        $this->assertSame(9 * 3600, $jornada->trabajadoSegundos());
        $this->assertSame('9 h 00 min', $jornada->trabajadoLegible());
        $this->assertTrue($jornada->tiempoEsExacto());
        $this->assertCount(0, $jornada->sinPareja());
    }

    /**
     * EL CASO QUE JUSTIFICA LOS TRAMOS. 07→12 y 13→16 son 8 horas. La resta
     * ingenua (16:00 − 07:00) daría 9 y se comería la hora de almuerzo.
     */
    public function test_varios_pares_suman_los_tramos_y_no_la_resta_ingenua(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 12:00:00', TipoMarcacion::Salida);
        $this->marcar($this->ana, '2026-03-05 13:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Completa, $jornada->estado);
        $this->assertSame(2, $jornada->paresCompletos());
        $this->assertSame(8 * 3600, $jornada->trabajadoSegundos(), 'Son 8 horas, no 9: la resta ingenua daría 9.');
        $this->assertSame('07:00', $jornada->primeraEntrada()->marcado_at->copy()->setTimezone($this->zona())->format('H:i'));
        $this->assertSame('16:00', $jornada->ultimaSalida()->marcado_at->copy()->setTimezone($this->zona())->format('H:i'));
    }

    public function test_una_entrada_sin_salida_deja_la_jornada_abierta_y_sin_tiempo(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Abierta, $jornada->estado);
        $this->assertSame(0, $jornada->paresCompletos());
        // NO se cierra con «ahora» ni con el final del día: sería inventar una hora.
        $this->assertSame(0, $jornada->trabajadoSegundos());
        $this->assertFalse($jornada->tiempoEsExacto());
        $this->assertCount(1, $jornada->sinPareja());
        $this->assertNull($jornada->ultimaSalida());
    }

    /** Cantidad impar: los pares que cerraron cuentan; el último queda abierto. */
    public function test_cantidad_impar_conserva_los_pares_cerrados(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 12:00:00', TipoMarcacion::Salida);
        $this->marcar($this->ana, '2026-03-05 13:00:00', TipoMarcacion::Entrada);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Abierta, $jornada->estado);
        $this->assertSame(1, $jornada->paresCompletos());
        $this->assertSame(5 * 3600, $jornada->trabajadoSegundos(), 'El tramo cerrado sigue contando.');
        $this->assertFalse($jornada->tiempoEsExacto());
        $this->assertSame(2, $jornada->entradas());
        $this->assertSame(1, $jornada->salidas());
    }

    /**
     * Una SALIDA sin entrada previa. Por el lector es imposible —el día siempre
     * empieza en entrada— así que solo llega de una corrección manual, y hay que
     * distinguirla de «falta cerrar».
     */
    public function test_una_salida_sin_entrada_es_irregular(): void
    {
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida, origen: 'manual');

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Irregular, $jornada->estado);
        $this->assertSame(0, $jornada->trabajadoSegundos());
        $this->assertNull($jornada->primeraEntrada());
        $this->assertCount(1, $jornada->sinPareja());
    }

    /** Dos entradas seguidas: la primera queda sin cerrar y la secuencia no alterna. */
    public function test_dos_entradas_seguidas_son_irregulares(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 09:00:00', TipoMarcacion::Entrada, origen: 'manual');
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Irregular, $jornada->estado);
        // La segunda entrada es la que cierra: 09→16 son 7 horas.
        $this->assertSame(1, $jornada->paresCompletos());
        $this->assertSame(7 * 3600, $jornada->trabajadoSegundos());
        $this->assertCount(1, $jornada->sinPareja(), 'La entrada de las 07:00 se queda sin pareja.');
    }

    /** Una salida anterior a su entrada (solo posible corrigiendo a mano) no resta. */
    public function test_un_tramo_invertido_no_produce_tiempo_negativo(): void
    {
        $entrada = $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Entrada);
        // Se fuerza una salida ANTES, sin tocar el orden de ids.
        $salida = $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida);
        $salida->forceFill(['marcado_at' => $entrada->marcado_at->copy()->subHour()])->save();

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertGreaterThanOrEqual(0, $jornada->trabajadoSegundos());
    }

    // ───────────────────────── Medianoche y turno nocturno ─────────────────────────

    /** La jornada se agrupa por `fecha_local`, no volviendo a derivar el día del UTC. */
    public function test_una_jornada_nocturna_se_agrupa_por_su_dia_local(): void
    {
        // 19:00 → 23:30 del día 5. En UTC las dos caen ya en el día 6.
        $this->jornadaDe($this->ana, '2026-03-05', '19:00:00', '23:30:00');

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame('2026-03-05', $jornada->fecha->format('Y-m-d'));
        $this->assertSame(EstadoJornada::Completa, $jornada->estado);
        $this->assertSame(4 * 3600 + 1800, $jornada->trabajadoSegundos());

        // Y el día 6 no tiene nada, aunque en UTC ambos instantes le pertenezcan.
        $this->assertCount(0, $this->consulta()->porRango($this->dia('2026-03-06')));
    }

    /**
     * EL TURNO QUE CRUZA LA MEDIANOCHE, tal como el sistema lo registra HOY.
     *
     * Esta prueba NO valida un comportamiento deseable: fija el actual, que es
     * defectuoso y no se puede arreglar sin horarios. Se ejecuta el servicio REAL
     * de marcación, no la factory, porque lo que se está documentando es
     * precisamente lo que hace la regla de alternancia.
     *
     * Quien entra a las 20:00 del 5 y sale a la 01:00 del 6 produce:
     *   día 5 -> 20:00 entrada        (jornada ABIERTA, sin tiempo)
     *   día 6 -> 01:00 **ENTRADA**    (¡no «salida»! la alternancia se reinició)
     *
     * El día que existan horarios, esta prueba tiene que cambiar — y ese cambio
     * será la señal de que el problema se resolvió.
     */
    public function test_el_turno_nocturno_queda_identificado_pero_sin_resolver(): void
    {
        $huella = app(AsignarHuella::class)($this->ana, $this->lector, 1);
        config()->set('asistencia.cooldown_segundos', 0);
        $registrar = app(RegistrarMarcacion::class);

        foreach (['2026-03-05 20:00:00', '2026-03-06 01:00:00'] as $instante) {
            Carbon::setTestNow(Carbon::parse($instante, $this->zona())->setTimezone('UTC'));
            $registrar($this->lector, 1);
        }
        Carbon::setTestNow();

        $dia5 = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();
        $dia6 = $this->consulta()->porRango($this->dia('2026-03-06'))->sole();

        // El día 5 queda abierto: entró y, para el sistema, nunca salió.
        $this->assertSame(EstadoJornada::Abierta, $dia5->estado);
        $this->assertSame(0, $dia5->trabajadoSegundos());

        // Y la marcación de la 01:00 —que era una SALIDA— quedó como ENTRADA.
        $this->assertSame(TipoMarcacion::Entrada, $dia6->marcaciones->first()->tipo,
            'La alternancia se reinicia a medianoche: el tipo sale invertido. Requiere horarios para arreglarse.');
        $this->assertSame(EstadoJornada::Abierta, $dia6->estado);

        // Ninguna de las dos jornadas inventa tiempo trabajado.
        $this->assertSame(0, $dia5->trabajadoSegundos() + $dia6->trabajadoSegundos());
        $this->assertFalse($dia5->tiempoEsExacto());
    }

    // ─────────────────────────────── Filtros ───────────────────────────────

    public function test_filtra_por_empleado_y_aisla(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->beto, '2026-03-05', '08:00:00', '17:00:00');

        $deAna = $this->consulta()->deEmpleado($this->ana->id, $this->dia('2026-03-05'));

        $this->assertCount(1, $deAna);
        $this->assertSame($this->ana->id, $deAna->first()->empleadoId);
    }

    public function test_filtra_por_rango_inclusivo(): void
    {
        $this->jornadaDe($this->ana, '2026-03-04', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-06', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-07', '07:00:00', '16:00:00');

        $jornadas = $this->consulta()->porRango(FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-05'),
            CarbonImmutable::parse('2026-03-06'),
        ));

        $this->assertSame(
            ['2026-03-05', '2026-03-06'],
            $jornadas->map(fn ($j) => $j->fecha->format('Y-m-d'))->sort()->values()->all(),
        );
    }

    /** Un rango MENSUAL: el caso de uso real del reporte. */
    public function test_un_rango_mensual_agrupa_una_jornada_por_dia_y_persona(): void
    {
        foreach (range(1, 5) as $dia) {
            $this->jornadaDe($this->ana, sprintf('2026-03-%02d', $dia), '07:00:00', '16:00:00');
            $this->jornadaDe($this->beto, sprintf('2026-03-%02d', $dia), '08:00:00', '16:00:00');
        }
        // Fuera del mes.
        $this->jornadaDe($this->ana, '2026-04-01', '07:00:00', '16:00:00');

        $marzo = FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $resumen = $this->consulta()->resumen($marzo);

        $this->assertSame(10, $resumen['jornadas'], '5 días × 2 personas.');
        $this->assertSame(2, $resumen['personas']);
        $this->assertSame(5, $resumen['dias']);
        $this->assertSame(10, $resumen['completas']);
        // Ana 9 h × 5 + Beto 8 h × 5 = 85 h
        $this->assertSame(85.0, $resumen['trabajado_horas']);
        $this->assertTrue($resumen['tiempo_exacto']);
    }

    public function test_se_puede_filtrar_por_estado(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');       // completa
        $this->marcar($this->beto, '2026-03-05 07:00:00', TipoMarcacion::Entrada); // abierta

        $rango = $this->dia('2026-03-05');

        $this->assertCount(2, $this->consulta()->porRango($rango));
        $this->assertCount(1, $this->consulta()->porRango($rango, EstadoJornada::Completa));
        $this->assertCount(1, $this->consulta()->porRango($rango, EstadoJornada::Abierta));
        $this->assertCount(0, $this->consulta()->porRango($rango, EstadoJornada::Irregular));
    }

    public function test_se_agrupan_por_empleado_para_un_formato(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-06', '07:00:00', '16:00:00');
        $this->jornadaDe($this->beto, '2026-03-05', '08:00:00', '17:00:00');

        $grupos = $this->consulta()->porEmpleado(FiltroAsistencia::vacio());

        $this->assertCount(2, $grupos);
        $this->assertCount(2, $grupos[$this->ana->id]);
        $this->assertCount(1, $grupos[$this->beto->id]);
    }

    // ─────────────────────── Datos históricos incómodos ───────────────────────

    public function test_un_empleado_inactivo_conserva_sus_jornadas(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->ana->update(['activo' => false]);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame($this->ana->id, $jornada->empleadoId);
        $this->assertFalse($jornada->empleado->activo);
        $this->assertSame(9 * 3600, $jornada->trabajadoSegundos());
    }

    /** Una huella liberada no cambia de quién fue la jornada. */
    public function test_una_jornada_hecha_con_una_huella_ya_liberada_sigue_siendo_de_su_dueño(): void
    {
        $huella = app(AsignarHuella::class)($this->ana, $this->lector, 7);
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada, huella: $huella);
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida, huella: $huella);

        app(LiberarHuella::class)($huella);
        app(AsignarHuella::class)($this->beto, $this->lector, 7);

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame($this->ana->id, $jornada->empleadoId);
        $this->assertSame($huella->id, $jornada->marcaciones->first()->asistencia_huella_id);
        $this->assertFalse($jornada->marcaciones->first()->huella->activo);
    }

    /** Marcaciones manuales, sin lector: la jornada se arma igual y no inventa nada. */
    public function test_una_jornada_de_marcaciones_manuales_se_arma_igual(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada, origen: 'manual');
        $this->marcar($this->ana, '2026-03-05 16:00:00', TipoMarcacion::Salida, origen: 'manual');

        $jornada = $this->consulta()->porRango($this->dia('2026-03-05'))->sole();

        $this->assertSame(EstadoJornada::Completa, $jornada->estado);
        $this->assertSame(9 * 3600, $jornada->trabajadoSegundos());
        $this->assertNull($jornada->marcaciones->first()->dispositivo);
        $this->assertNull($jornada->marcaciones->first()->huella);
    }

    // ────────────────────────── Orden y serialización ──────────────────────────

    public function test_por_defecto_lo_mas_reciente_va_primero(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->jornadaDe($this->ana, '2026-03-06', '07:00:00', '16:00:00');

        $fechas = $this->consulta()->porRango(FiltroAsistencia::vacio())->map(fn ($j) => $j->fecha->format('Y-m-d'))->all();

        $this->assertSame(['2026-03-06', '2026-03-05'], $fechas);
    }

    /** Dentro de un mismo día, las personas van en orden alfabético en ambas direcciones. */
    public function test_dentro_del_dia_las_personas_van_alfabeticas(): void
    {
        $this->jornadaDe($this->beto, '2026-03-05', '08:00:00', '17:00:00');
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        foreach ([false, true] as $ascendente) {
            $filtro = FiltroAsistencia::vacio()->ascendente($ascendente);
            $apellidos = $this->consulta()->porRango($filtro)->map(fn ($j) => $j->empleado->apellidos)->all();

            $this->assertSame(['Pérez', 'Ramos'], $apellidos,
                'El orden de fechas no puede invertir el de las personas.');
        }
    }

    public function test_una_jornada_se_serializa_sin_arrastrar_modelos(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $datos = $this->consulta()->porRango($this->dia('2026-03-05'))->sole()->toArray();

        $this->assertSame('2026-03-05', $datos['fecha']);
        $this->assertSame('Ana Pérez', $datos['empleado']);
        $this->assertSame(9.0, $datos['trabajado_horas']);
        $this->assertSame('completa', $datos['estado']);
        $this->assertTrue($datos['tiempo_exacto']);
        $this->assertSame(2, $datos['marcaciones']);
        $this->assertIsString(json_encode($datos));
    }

    public function test_la_consulta_puntual_de_un_dia_devuelve_su_jornada(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');

        $jornada = $this->consulta()->delDia($this->ana->id, CarbonImmutable::parse('2026-03-05'));

        $this->assertNotNull($jornada);
        $this->assertSame($this->ana->id, $jornada->empleadoId);
        $this->assertNull($this->consulta()->delDia($this->beto->id, CarbonImmutable::parse('2026-03-05')));
    }

    // ─────────────────────────── No escribe nada ───────────────────────────

    /**
     * Armar jornadas no puede tocar el libro. Se espía el SQL: si alguna de estas
     * llamadas emitiera una escritura, el módulo habría dejado de ser append-only
     * sin que ningún conteo lo delatara.
     */
    public function test_construir_jornadas_no_escribe_en_la_base(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $this->marcar($this->beto, '2026-03-05 08:00:00', TipoMarcacion::Entrada);

        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $consulta->sql)) {
                $escrituras[] = $consulta->sql;
            }
        });

        $filtro = FiltroAsistencia::vacio();

        $this->consulta()->porRango($filtro);
        $this->consulta()->porEmpleado($filtro);
        $this->consulta()->resumen($filtro);
        $this->consulta()->paginar($filtro);
        $this->consulta()->deEmpleado($this->ana->id, $filtro);
        $this->consulta()->delDia($this->ana->id, CarbonImmutable::parse('2026-03-05'));

        $this->assertSame([], $escrituras, 'La capa de jornadas emitió una escritura.');
    }

    public function test_las_marcaciones_no_cambian_al_construir_jornadas(): void
    {
        $this->jornadaDe($this->ana, '2026-03-05', '07:00:00', '16:00:00');
        $antes = AsistenciaMarcacion::query()->orderBy('id')->get()->toArray();

        $this->consulta()->porRango(FiltroAsistencia::vacio());
        $this->consulta()->resumen(FiltroAsistencia::vacio());

        $this->assertEquals($antes, AsistenciaMarcacion::query()->orderBy('id')->get()->toArray());
    }
}
