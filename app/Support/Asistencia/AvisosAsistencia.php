<?php

namespace App\Support\Asistencia;

use App\Facades\Ajustes;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisos SUAVES de «Marcas de hoy» (issue #87, etapa 2). Solo informan: no frenan
 * nada, no salen en la hoja impresa y no tocan Planilla.
 *
 *  - Tardanzas: quien, en sus últimos días con marca, llegó varias veces después de la
 *    hora de entrada más la tolerancia. Se mira la primera marca del día, en hora local.
 *  - Horas: al cerrar la quincena (el 15 y el último día del mes), a quien le falta más
 *    de un día de horas. Se esperan las horas por día de lunes a viernes y la mitad el
 *    sábado (regla del usuario: el sábado es medio día); el domingo no se trabaja. El día
 *    de cierre se mira hasta ayer, porque el de hoy todavía no terminó; después, la
 *    quincena entera, hasta que cierra la siguiente.
 *
 * La hora de entrada, la tolerancia y las horas por día vienen de Configuración ›
 * Módulos › Asistencia. Los feriados no se conocen: en una quincena con feriado el aviso
 * puede salir de más, y por eso es solo un aviso.
 */
class AvisosAsistencia
{
    /** Cuántos días con marca se miran, y cuántos tarde hacen falta para avisar. */
    private const DIAS_MIRADOS = 10;

    private const MINIMO_TARDE = 3;

    /** Hasta dónde se buscan esos días hacia atrás. */
    private const BUSCAR_DIAS_ATRAS = 31;

    public function __construct(
        private readonly HoraOficial $horaOficial,
        private readonly ConsultaJornadas $jornadas,
    ) {}

    /**
     * @return array{
     *     limite: string,
     *     tardanzas: list<array{nombre: string, tarde: int, dias: int}>,
     *     quincena: array{desde: CarbonImmutable, hasta: CarbonImmutable},
     *     horas: list<array{nombre: string, faltan: int, exacto: bool}>
     * }
     */
    public function calcular(): array
    {
        $hoy = CarbonImmutable::parse($this->horaOficial->fechaLocal($this->horaOficial->instante()));
        $personas = AsistenciaEmpleado::query()->where('activo', true)
            ->orderBy('apellidos')->orderBy('nombres')->orderBy('id')->get();
        // En minutos y sin pasar de las 23:59: una entrada tardía más la tolerancia no
        // puede dar la vuelta a la medianoche y volver tarde a todo el mundo.
        [$h, $m] = array_map('intval', explode(':', (string) Ajustes::texto('asistencia.horario.entrada', '08:00')) + [0, 0]);
        $minutos = min(23 * 60 + 59, $h * 60 + $m + max(0, (int) Ajustes::entero('asistencia.horario.tolerancia_minutos', 10)));
        $limite = sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
        $quincena = $this->quincena($hoy);

        return [
            'limite' => $limite,
            'tardanzas' => $this->tardanzas($personas, $hoy, $limite),
            'quincena' => $quincena,
            'horas' => $this->horas($personas, $quincena['desde'], $quincena['hasta']),
        ];
    }

    /** @return list<array{nombre: string, tarde: int, dias: int}> */
    private function tardanzas(Collection $personas, CarbonImmutable $hoy, string $limite): array
    {
        $porPersona = $this->jornadasPorPersona($hoy->subDays(self::BUSCAR_DIAS_ATRAS), $hoy);

        return $personas->map(function (AsistenciaEmpleado $persona) use ($porPersona, $limite) {
            $ultimas = $porPersona->get($persona->id, collect())
                ->sortByDesc(fn (Jornada $j) => $j->fecha->format('Y-m-d'))->take(self::DIAS_MIRADOS);
            $tarde = $ultimas->filter(fn (Jornada $j) => $this->horaLocal($j) > $limite)->count();

            return ['nombre' => $persona->nombreCompleto(), 'tarde' => $tarde, 'dias' => $ultimas->count()];
        })->filter(fn (array $fila) => $fila['tarde'] >= self::MINIMO_TARDE)->values()->all();
    }

    /** @return list<array{nombre: string, faltan: int, exacto: bool}> */
    private function horas(Collection $personas, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $horasDia = max(1, (int) Ajustes::entero('asistencia.horario.horas_por_dia', 8));
        $porPersona = $this->jornadasPorPersona($desde, $hasta);

        return $personas->map(function (AsistenciaEmpleado $persona) use ($porPersona, $desde, $hasta, $horasDia) {
            $ingreso = CarbonImmutable::parse(($persona->fecha_ingreso ?? $persona->created_at)->format('Y-m-d'));
            $jornadas = $porPersona->get($persona->id, collect());
            $faltan = $this->esperadoSegundos($ingreso->max($desde), $hasta, $horasDia)
                - $jornadas->sum(fn (Jornada $j) => $j->trabajadoSegundos());

            return [
                'nombre' => $persona->nombreCompleto(),
                'faltan' => intdiv(max(0, $faltan), 3600),
                'exacto' => $jornadas->every(fn (Jornada $j) => $j->tiempoEsExacto()),
            ];
        })->filter(fn (array $fila) => $fila['faltan'] >= $horasDia)->values()->all();
    }

    /** Lunes a viernes, las horas del día; sábado, la mitad; domingo, nada. */
    private function esperadoSegundos(CarbonImmutable $desde, CarbonImmutable $hasta, int $horasDia): int
    {
        $segundos = 0;
        for ($dia = $desde; $dia->lessThanOrEqualTo($hasta); $dia = $dia->addDay()) {
            $segundos += match (true) {
                $dia->isSunday() => 0,
                $dia->isSaturday() => intdiv($horasDia * 3600, 2),
                default => $horasDia * 3600,
            };
        }

        return $segundos;
    }

    /**
     * La quincena que cierra hoy (mirada hasta ayer) o la última que ya cerró.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}
     */
    private function quincena(CarbonImmutable $hoy): array
    {
        [$desde, $fin] = match (true) {
            $hoy->day === $hoy->daysInMonth => [$hoy->setDay(16), $hoy],
            $hoy->day >= 15 => [$hoy->setDay(1), $hoy->setDay(15)],
            default => [$hoy->subMonthNoOverflow()->setDay(16), $hoy->subMonthNoOverflow()->endOfMonth()->startOfDay()],
        };

        return ['desde' => $desde, 'hasta' => $fin->min($hoy->subDay())];
    }

    /** @return Collection<int, Collection<int, Jornada>> */
    private function jornadasPorPersona(CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        return $this->jornadas->porEmpleado(FiltroAsistencia::vacio()->conRango($desde, $hasta));
    }

    /** La primera marca del día, en hora local «H:i». */
    private function horaLocal(Jornada $jornada): string
    {
        return CarbonImmutable::instance($jornada->marcaciones->first()->marcado_at)
            ->setTimezone($this->horaOficial->zona())->format('H:i');
    }
}
