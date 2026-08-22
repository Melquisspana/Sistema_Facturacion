<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use App\Support\Asistencia\ConsultaAsistencia;
use App\Support\Asistencia\FiltroAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LA CAPA DE CONSULTA, probada SIN pasar por HTTP.
 *
 * Es deliberado que estas pruebas no toquen una sola ruta: el punto de que
 * {@see ConsultaAsistencia} exista es que el futuro módulo de Formatos la use sin
 * ser una petición web. Si solo se probara a través de la pantalla, nada
 * garantizaría que sirve fuera de ella.
 *
 * ─────────────────── El error que estas pruebas están vigilando ───────────────────
 *
 * Filtrar por `marcado_at` en vez de por `fecha_local`. En El Salvador (UTC−6) una
 * marcación de las 19:30 del día 5 se guarda como 01:30 UTC del día 6: filtrar por
 * el instante desplaza el turno de la tarde entero al día siguiente, sin error y
 * sin aviso. Hay una prueba dedicada a eso y falla si alguien cambia la columna.
 */
class ConsultaAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $entrada;

    private AsistenciaDispositivo $bodega;

    private AsistenciaEmpleado $ana;

    private AsistenciaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->entrada = AsistenciaDispositivo::factory()->create(['codigo' => 'entrada', 'nombre' => 'Entrada principal']);
        $this->bodega = AsistenciaDispositivo::factory()->create(['codigo' => 'bodega', 'nombre' => 'Bodega']);
        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        $this->beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
    }

    private function consulta(): ConsultaAsistencia
    {
        return app(ConsultaAsistencia::class);
    }

    /** Una marcación en un instante LOCAL concreto (la factory deriva el día local). */
    private function marcar(
        AsistenciaEmpleado $empleado,
        string $instanteLocal,
        TipoMarcacion $tipo = TipoMarcacion::Entrada,
        ?AsistenciaDispositivo $lector = null,
        string $origen = 'dispositivo',
    ): AsistenciaMarcacion {
        return AsistenciaMarcacion::factory()
            ->en(Carbon::parse($instanteLocal, config('asistencia.zona_horaria')))
            ->tipo($tipo)
            ->create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $lector?->id,
                'origen' => $origen,
            ]);
    }

    // ──────────────────────────── Filtros individuales ────────────────────────────

    public function test_sin_filtros_devuelve_todo(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->marcar($this->beto, '2026-04-10 08:00:00');

        $this->assertCount(2, $this->consulta()->todas(FiltroAsistencia::vacio()));
    }

    /** El aislamiento entre personas: lo de Ana no aparece en lo de Beto. */
    public function test_filtra_por_empleado_y_no_mezcla_personas(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida);
        $this->marcar($this->beto, '2026-03-05 07:05:00');

        $deAna = $this->consulta()->todas(FiltroAsistencia::vacio()->conEmpleado($this->ana->id));

        $this->assertCount(2, $deAna);
        $this->assertSame([$this->ana->id], $deAna->pluck('asistencia_empleado_id')->unique()->all());
    }

    public function test_filtra_por_dispositivo(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada);
        $this->marcar($this->ana, '2026-03-05 12:00:00', lector: $this->bodega);

        $deBodega = $this->consulta()->todas(FiltroAsistencia::vacio()->conDispositivo($this->bodega->id));

        $this->assertCount(1, $deBodega);
        $this->assertSame($this->bodega->id, $deBodega->first()->asistencia_dispositivo_id);
    }

    public function test_filtra_por_tipo(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida);

        $salidas = $this->consulta()->todas(FiltroAsistencia::vacio()->conTipo(TipoMarcacion::Salida));

        $this->assertCount(1, $salidas);
        $this->assertSame(TipoMarcacion::Salida, $salidas->first()->tipo);
    }

    public function test_filtra_por_origen(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', lector: $this->entrada);
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida, origen: 'manual');

        $manuales = $this->consulta()->todas(FiltroAsistencia::vacio()->conOrigen('manual'));

        $this->assertCount(1, $manuales);
        $this->assertSame('manual', $manuales->first()->origen);
    }

    // ─────────────────────────────── Rango de fechas ───────────────────────────────

    /** INCLUSIVO en los dos extremos: «del 5 al 7» incluye el 5 y el 7. */
    public function test_el_rango_de_fechas_incluye_los_dos_extremos(): void
    {
        $this->marcar($this->ana, '2026-03-04 08:00:00');   // fuera, antes
        $this->marcar($this->ana, '2026-03-05 08:00:00');   // borde inicial
        $this->marcar($this->ana, '2026-03-06 08:00:00');   // dentro
        $this->marcar($this->ana, '2026-03-07 08:00:00');   // borde final
        $this->marcar($this->ana, '2026-03-08 08:00:00');   // fuera, después

        $rango = $this->consulta()->todas(FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-05'),
            CarbonImmutable::parse('2026-03-07'),
        ));

        $this->assertSame(
            ['2026-03-05', '2026-03-06', '2026-03-07'],
            $rango->pluck('fecha_local')->map->format('Y-m-d')->sort()->values()->all(),
        );
    }

    public function test_un_rango_de_un_solo_dia_devuelve_ese_dia(): void
    {
        $this->marcar($this->ana, '2026-03-05 08:00:00');
        $this->marcar($this->ana, '2026-03-06 08:00:00');

        $dia = CarbonImmutable::parse('2026-03-05');
        $this->assertCount(1, $this->consulta()->todas(FiltroAsistencia::vacio()->conRango($dia, $dia)));
    }

    /**
     * EL TEST QUE VIGILA LA MEDIANOCHE.
     *
     * Una marcación de las 19:30 del 5 de marzo se guarda como 01:30 UTC del 6. Si
     * la consulta filtrara por `marcado_at` en vez de por `fecha_local`, esta
     * marcación desaparecería del día 5 y aparecería en el 6 — el turno de la
     * tarde entero, movido de día, sin ningún error visible.
     */
    public function test_una_marcacion_nocturna_pertenece_a_su_dia_local_y_no_al_utc(): void
    {
        $marcacion = $this->marcar($this->ana, '2026-03-05 19:30:00');

        // Precondición: el instante guardado en UTC ya cayó en el día siguiente.
        $this->assertSame('2026-03-06', $marcacion->marcado_at->copy()->setTimezone('UTC')->format('Y-m-d'));
        $this->assertSame('2026-03-05', $marcacion->fecha_local->format('Y-m-d'));

        $dia5 = CarbonImmutable::parse('2026-03-05');
        $dia6 = CarbonImmutable::parse('2026-03-06');

        $this->assertCount(1, $this->consulta()->todas(FiltroAsistencia::vacio()->conRango($dia5, $dia5)),
            'La marcación de las 19:30 del día 5 tiene que aparecer en el día 5.');
        $this->assertCount(0, $this->consulta()->todas(FiltroAsistencia::vacio()->conRango($dia6, $dia6)),
            'Y NO en el día 6, que es donde la pondría filtrar por el instante UTC.');
    }

    public function test_solo_desde_o_solo_hasta_tambien_funcionan(): void
    {
        $this->marcar($this->ana, '2026-03-04 08:00:00');
        $this->marcar($this->ana, '2026-03-06 08:00:00');

        $desde = FiltroAsistencia::vacio()->conRango(CarbonImmutable::parse('2026-03-05'), null);
        $hasta = FiltroAsistencia::vacio()->conRango(null, CarbonImmutable::parse('2026-03-05'));

        $this->assertCount(1, $this->consulta()->todas($desde));
        $this->assertCount(1, $this->consulta()->todas($hasta));
    }

    // ─────────────────────────────── Combinados ───────────────────────────────

    public function test_los_filtros_se_combinan(): void
    {
        // Lo que debe salir: Ana, entrada, lector de entrada, dentro del rango.
        $buscada = $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada, $this->entrada);

        // Ruido que comparte todo menos un criterio, uno por uno.
        $this->marcar($this->beto, '2026-03-05 07:00:00', TipoMarcacion::Entrada, $this->entrada);          // otra persona
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida, $this->entrada);            // otro tipo
        $this->marcar($this->ana, '2026-03-05 09:00:00', TipoMarcacion::Entrada, $this->bodega);            // otro lector
        $this->marcar($this->ana, '2026-03-20 07:00:00', TipoMarcacion::Entrada, $this->entrada);           // fuera del rango

        $filtro = FiltroAsistencia::vacio()
            ->conEmpleado($this->ana->id)
            ->conDispositivo($this->entrada->id)
            ->conTipo(TipoMarcacion::Entrada)
            ->conRango(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-10'));

        $resultado = $this->consulta()->todas($filtro);

        $this->assertCount(1, $resultado);
        $this->assertTrue($resultado->first()->is($buscada));
    }

    // ─────────────────────── Datos históricos incómodos ───────────────────────

    /**
     * Una marcación hecha con una ranura que DESPUÉS se liberó sigue apuntando a
     * su asignación original, y la consulta la devuelve con ella cargada. Es lo que
     * permite responder «con qué huella se identificó ese día».
     */
    public function test_una_marcacion_con_huella_liberada_conserva_su_asignacion(): void
    {
        $huella = AsistenciaHuella::factory()->create([
            'asistencia_empleado_id' => $this->ana->id,
            'asistencia_dispositivo_id' => $this->entrada->id,
            'fingerprint_id' => 7,
        ]);

        $marcacion = AsistenciaMarcacion::factory()
            ->en(Carbon::parse('2026-03-05 07:00:00', config('asistencia.zona_horaria')))
            ->create([
                'asistencia_empleado_id' => $this->ana->id,
                'asistencia_dispositivo_id' => $this->entrada->id,
                'asistencia_huella_id' => $huella->id,
            ]);

        // La ranura se libera y se le asigna a otra persona.
        app(LiberarHuella::class)($huella);
        app(AsignarHuella::class)($this->beto, $this->entrada, 7);

        $recuperada = $this->consulta()->todas(FiltroAsistencia::vacio())->firstWhere('id', $marcacion->id);

        $this->assertSame($huella->id, $recuperada->asistencia_huella_id);
        $this->assertSame(7, $recuperada->huella->fingerprint_id);
        $this->assertFalse($recuperada->huella->activo, 'La asignación quedó liberada, pero sigue ahí.');
        $this->assertSame($this->ana->id, $recuperada->huella->asistencia_empleado_id,
            'La marcación de Ana no puede pasar a colgar de Beto por reutilizar la ranura.');
    }

    /** Quien ya no trabaja acá sigue apareciendo en su historial. */
    public function test_un_empleado_inactivo_sigue_visible_en_el_historico(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->ana->update(['activo' => false]);

        $resultado = $this->consulta()->todas(FiltroAsistencia::vacio()->conEmpleado($this->ana->id));

        $this->assertCount(1, $resultado);
        $this->assertFalse($resultado->first()->empleado->activo);
    }

    /** Sin lector (corrección manual futura) la consulta no inventa uno. */
    public function test_una_marcacion_sin_dispositivo_no_se_rellena_con_nada(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', origen: 'manual');

        $resultado = $this->consulta()->todas(FiltroAsistencia::vacio())->first();

        $this->assertNull($resultado->asistencia_dispositivo_id);
        $this->assertNull($resultado->dispositivo);
        $this->assertNull($resultado->huella);
        $this->assertSame('manual', $resultado->origen);
    }

    // ─────────────────────────────── Orden ───────────────────────────────

    public function test_por_defecto_lo_mas_reciente_va_primero(): void
    {
        $vieja = $this->marcar($this->ana, '2026-03-05 07:00:00');
        $nueva = $this->marcar($this->ana, '2026-03-06 07:00:00');

        $orden = $this->consulta()->todas(FiltroAsistencia::vacio())->pluck('id')->all();

        $this->assertSame([$nueva->id, $vieja->id], $orden);
    }

    /** Un documento se lee de principio a fin: por eso el filtro sabe invertirlo. */
    public function test_el_orden_ascendente_sirve_para_un_documento(): void
    {
        $vieja = $this->marcar($this->ana, '2026-03-05 07:00:00');
        $nueva = $this->marcar($this->ana, '2026-03-06 07:00:00');

        $orden = $this->consulta()->todas(FiltroAsistencia::vacio()->ascendente())->pluck('id')->all();

        $this->assertSame([$vieja->id, $nueva->id], $orden);
    }

    // ────────────────────────── Agrupaciones y conteos ──────────────────────────

    public function test_se_agrupan_por_empleado_para_un_formato_por_persona(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida);
        $this->marcar($this->beto, '2026-03-05 08:00:00');

        $grupos = $this->consulta()->porEmpleado(FiltroAsistencia::vacio());

        $this->assertCount(2, $grupos);
        $this->assertCount(2, $grupos[$this->ana->id]);
        $this->assertCount(1, $grupos[$this->beto->id]);
    }

    public function test_se_agrupan_por_dia_local(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        // Las 23:30 del 5 siguen siendo del día 5, aunque en UTC ya sea el 6.
        $this->marcar($this->ana, '2026-03-05 23:30:00', TipoMarcacion::Salida);
        $this->marcar($this->ana, '2026-03-06 07:00:00');

        $dias = $this->consulta()->porDia(FiltroAsistencia::vacio());

        $this->assertSame(['2026-03-05', '2026-03-06'], $dias->keys()->sort()->values()->all());
        $this->assertCount(2, $dias['2026-03-05']);
    }

    public function test_el_resumen_cuenta_hechos_y_respeta_el_filtro(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->ana, '2026-03-05 17:00:00', TipoMarcacion::Salida);
        $this->marcar($this->beto, '2026-03-06 08:00:00', TipoMarcacion::Entrada);
        $this->marcar($this->beto, '2026-04-01 08:00:00', TipoMarcacion::Entrada);

        $marzo = FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $this->assertSame(
            ['total' => 3, 'entradas' => 2, 'salidas' => 1, 'personas' => 2, 'dias' => 2],
            $this->consulta()->resumen($marzo),
        );
    }

    public function test_las_ultimas_de_una_persona_son_suyas_y_estan_acotadas(): void
    {
        foreach (range(1, 15) as $dia) {
            $this->marcar($this->ana, sprintf('2026-03-%02d 07:00:00', $dia));
        }
        $this->marcar($this->beto, '2026-03-20 07:00:00');

        $ultimas = $this->consulta()->ultimasDe($this->ana->id, 10);

        $this->assertCount(10, $ultimas);
        $this->assertSame([$this->ana->id], $ultimas->pluck('asistencia_empleado_id')->unique()->all());
        $this->assertSame('2026-03-15', $ultimas->first()->fecha_local->format('Y-m-d'));
    }

    // ─────────────────────────── No escribe nada ───────────────────────────

    /**
     * Consultar no puede tocar el libro. Se comprueba espiando el SQL: si alguna
     * de estas llamadas emitiera un `insert`, `update` o `delete`, el módulo habría
     * dejado de ser append-only sin que ningún conteo lo delatara.
     */
    public function test_ninguna_consulta_escribe_en_la_base(): void
    {
        $this->marcar($this->ana, '2026-03-05 07:00:00');
        $this->marcar($this->beto, '2026-03-06 08:00:00', TipoMarcacion::Salida, $this->bodega);

        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $consulta->sql)) {
                $escrituras[] = $consulta->sql;
            }
        });

        $filtro = FiltroAsistencia::vacio()->conEmpleado($this->ana->id);

        $this->consulta()->todas($filtro);
        $this->consulta()->paginar($filtro);
        $this->consulta()->resumen($filtro);
        $this->consulta()->contar($filtro);
        $this->consulta()->porEmpleado($filtro);
        $this->consulta()->porDia($filtro);
        $this->consulta()->ultimasDe($this->ana->id);
        $this->consulta()->query($filtro)->get();

        $this->assertSame([], $escrituras, 'La capa de consulta emitió una escritura.');
    }

    // ────────────────────────── El objeto de criterios ──────────────────────────

    /** Inmutable: un filtro base se reutiliza por persona sin contaminarse. */
    public function test_el_filtro_es_inmutable(): void
    {
        $base = FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $deAna = $base->conEmpleado($this->ana->id);
        $deBeto = $base->conEmpleado($this->beto->id);

        $this->assertNull($base->empleadoId, 'El filtro base no puede haberse modificado.');
        $this->assertSame($this->ana->id, $deAna->empleadoId);
        $this->assertSame($this->beto->id, $deBeto->empleadoId);
        // Y el rango sobrevive a los dos.
        $this->assertSame('2026-03-31', $deBeto->hasta->format('Y-m-d'));
    }

    /**
     * Se construye desde datos sueltos —un formulario, una definición de formato
     * guardada, los argumentos de un comando— sin depender de HTTP.
     */
    public function test_el_filtro_se_construye_desde_datos_sueltos(): void
    {
        $filtro = FiltroAsistencia::desdeArray([
            'empleado_id' => (string) $this->ana->id,
            'desde' => '2026-03-01',
            'hasta' => '2026-03-31',
            'tipo' => 'salida',
            'origen' => 'dispositivo',
        ]);

        $this->assertSame($this->ana->id, $filtro->empleadoId);
        $this->assertSame('2026-03-01', $filtro->desde->format('Y-m-d'));
        $this->assertSame(TipoMarcacion::Salida, $filtro->tipo);
        $this->assertTrue($filtro->tieneFiltros());
    }

    /** Un criterio ilegible se descarta; no tumba el documento que lo llevaba. */
    public function test_los_criterios_ilegibles_se_descartan_sin_reventar(): void
    {
        $filtro = FiltroAsistencia::desdeArray([
            'empleado_id' => 'no-es-un-numero',
            'desde' => 'ayer por la tarde',
            'tipo' => 'inventado',
            'origen' => 'inventado',
        ]);

        $this->assertNull($filtro->empleadoId);
        $this->assertNull($filtro->desde);
        $this->assertNull($filtro->tipo);
        $this->assertNull($filtro->origen);
        $this->assertFalse($filtro->tieneFiltros());
    }

    public function test_el_filtro_sabe_describirse_para_un_documento(): void
    {
        $filtro = FiltroAsistencia::vacio()
            ->conEmpleado($this->ana->id)
            ->conRango(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(
            'Empleado: Ana Pérez · Del 01/03/2026 al 31/03/2026',
            $filtro->descripcion(['empleado' => 'Ana Pérez']),
        );

        $this->assertSame('Todas las marcaciones', FiltroAsistencia::vacio()->descripcion());
    }

    public function test_el_filtro_detecta_un_rango_al_reves_sin_corregirlo(): void
    {
        $invertido = FiltroAsistencia::vacio()->conRango(
            CarbonImmutable::parse('2026-03-31'),
            CarbonImmutable::parse('2026-03-01'),
        );

        $this->assertTrue($invertido->rangoInvertido());
        // No lo arregla solo: quien tenga a alguien delante lo valida y lo dice.
        $this->assertSame('2026-03-31', $invertido->desde->format('Y-m-d'));
    }
}
