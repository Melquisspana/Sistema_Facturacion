<?php

namespace App\Support\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * LO QUE OCURRIÓ con una persona en un día local, según sus marcaciones.
 *
 * Es un objeto derivado: no se guarda, no tiene tabla y no se puede editar. Se
 * arma cada vez a partir del libro append-only, así que nunca puede quedar
 * desincronizado de los hechos.
 *
 * ─────────────────── Qué describe, y qué NO ───────────────────
 *
 * Describe la FORMA de los datos: cuántas entradas, cuántas salidas, qué tramos
 * se cerraron y cuánto duraron. No dice si alguien llegó tarde, si cumplió su
 * jornada o si faltó: eso exigiría una hora oficial de entrada, una jornada
 * esperada y un calendario laboral, y **ninguna de las tres existe todavía**.
 *
 * En particular, un día SIN marcaciones no produce una jornada. No es «ausencia»
 * —eso presupone saber que ese día se trabajaba— sino sencillamente un día del
 * que el sistema no tiene nada que decir.
 *
 * ──────────────── Cómo se emparejan las marcaciones ────────────────
 *
 * Se recorren en orden cronológico con una entrada «abierta» a lo sumo:
 *
 *   entrada + nada abierto  -> queda abierta
 *   entrada + ya hay una abierta -> la anterior se cierra SIN salida (queda
 *                                   abierta como tramo) y esta pasa a ser la
 *                                   abierta
 *   salida  + hay una abierta -> se cierra el par
 *   salida  + nada abierto   -> salida huérfana
 *
 * Es determinista y explicable. Vía dispositivo los dos casos raros no pueden
 * ocurrir —la regla de alternancia lo impide y el día siempre empieza en
 * entrada—, así que solo aparecen con correcciones manuales.
 *
 * ─────────────── TURNOS QUE CRUZAN LA MEDIANOCHE ───────────────
 *
 * Esta clase NO los une, y es una decisión, no una carencia.
 *
 * Hoy, quien entra a las 20:00 del día 5 y sale a la 01:00 del día 6 produce
 * esto (comprobado ejecutando el servicio real):
 *
 *     día 5 -> 20:00 entrada          <- jornada ABIERTA
 *     día 6 -> 01:00 **entrada**      <- ¡no «salida»!
 *
 * La marcación de la 01:00 se registra como ENTRADA porque la alternancia se
 * reinicia a medianoche local ({@see TipoMarcacion::siguienteTras()} con día
 * vacío). El tipo queda invertido y sigue invertido mientras dure el patrón.
 *
 * Unirlas exigiría saber que esa persona hace turno de noche, y eso es
 * exactamente lo que un horario declara. Adivinarlo —«si el día abre y el
 * siguiente empieza de madrugada, unilos»— sería una heurística silenciosa que
 * acertaría casi siempre y fallaría en la planilla de alguien. Se deja
 * IDENTIFICADO como `Abierta` y documentado; se resuelve cuando existan horarios.
 */
final class Jornada
{
    /**
     * @param  Collection<int, AsistenciaMarcacion>  $marcaciones  cronológicas
     * @param  array<int, TramoJornada>  $tramos
     */
    private function __construct(
        public readonly int $empleadoId,
        public readonly ?AsistenciaEmpleado $empleado,
        public readonly CarbonImmutable $fecha,
        public readonly Collection $marcaciones,
        public readonly array $tramos,
        public readonly EstadoJornada $estado,
    ) {}

    /**
     * Arma la jornada de un día. Las marcaciones tienen que ser de UNA persona y
     * de UN día local; ordenarlas es responsabilidad de esta clase.
     *
     * @param  Collection<int, AsistenciaMarcacion>  $marcaciones
     */
    public static function de(int $empleadoId, CarbonImmutable $fecha, Collection $marcaciones): self
    {
        // COMPARADOR EXPLÍCITO, no `sortBy([fn, fn])`. Esa forma con varias
        // closures devuelve el orden INVERTIDO sobre una colección de Eloquent
        // —comprobado— y acá el orden no es cosmético: emparejar entradas con
        // salidas al revés convierte una jornada completa en una irregular y el
        // tiempo trabajado en cero.
        $ordenadas = $marcaciones
            ->sort(fn (AsistenciaMarcacion $a, AsistenciaMarcacion $b) => [$a->marcado_at->getTimestamp(), $a->id]
                <=> [$b->marcado_at->getTimestamp(), $b->id])
            ->values();

        $tramos = self::emparejar($ordenadas);

        return new self(
            empleadoId: $empleadoId,
            empleado: $ordenadas->first()?->empleado,
            fecha: $fecha->startOfDay(),
            marcaciones: $ordenadas,
            tramos: $tramos,
            estado: self::estadoDe($tramos),
        );
    }

    // ------------------------------------------------------------- lo básico

    /** La primera vez que entró. `null` si el día solo tiene salidas huérfanas. */
    public function primeraEntrada(): ?AsistenciaMarcacion
    {
        return $this->marcaciones->first(fn (AsistenciaMarcacion $m) => $m->tipo === TipoMarcacion::Entrada);
    }

    /** La última vez que salió. `null` si nunca cerró. */
    public function ultimaSalida(): ?AsistenciaMarcacion
    {
        return $this->marcaciones->last(fn (AsistenciaMarcacion $m) => $m->tipo === TipoMarcacion::Salida);
    }

    public function entradas(): int
    {
        return $this->marcaciones->where('tipo', TipoMarcacion::Entrada)->count();
    }

    public function salidas(): int
    {
        return $this->marcaciones->where('tipo', TipoMarcacion::Salida)->count();
    }

    public function totalMarcaciones(): int
    {
        return $this->marcaciones->count();
    }

    /** @return array<int, TramoJornada> Tramos con entrada y salida. */
    public function tramosCerrados(): array
    {
        return array_values(array_filter($this->tramos, fn (TramoJornada $t) => $t->estaCerrado()));
    }

    public function paresCompletos(): int
    {
        return count($this->tramosCerrados());
    }

    /**
     * Marcaciones que quedaron sin su contraparte. Es lo que hay que mirar cuando
     * el estado no es `Completa`.
     *
     * @return Collection<int, AsistenciaMarcacion>
     */
    public function sinPareja(): Collection
    {
        $sueltas = [];

        foreach ($this->tramos as $tramo) {
            if ($tramo->estaCerrado()) {
                continue;
            }
            $sueltas[] = $tramo->entrada ?? $tramo->salida;
        }

        return collect($sueltas)->filter()->values();
    }

    // ------------------------------------------------------------- el tiempo

    /**
     * Segundos de presencia: la SUMA de los tramos cerrados.
     *
     * No es «última salida menos primera entrada». Con almuerzo de por medio esa
     * resta daría 9 horas donde se trabajaron 8, y ese error va directo a una
     * planilla.
     */
    public function trabajadoSegundos(): int
    {
        $total = 0;

        foreach ($this->tramosCerrados() as $tramo) {
            $total += (int) $tramo->segundos();
        }

        return $total;
    }

    /**
     * ¿El tiempo de arriba es el total real? `false` cuando hay tramos sin cerrar:
     * lo que se muestra es un MÍNIMO, y la pantalla tiene que decirlo.
     */
    public function tiempoEsExacto(): bool
    {
        return $this->estado->tiempoEsExacto();
    }

    /** «8 h 00 min». Formato legible, sin decimales que nadie sabe interpretar. */
    public function trabajadoLegible(): string
    {
        $segundos = $this->trabajadoSegundos();

        return sprintf('%d h %02d min', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
    }

    /** Horas decimales, para quien tenga que sumarlas (un formato, una planilla). */
    public function trabajadoHorasDecimales(): float
    {
        return round($this->trabajadoSegundos() / 3600, 2);
    }

    // ------------------------------------------------------------- serialización

    /**
     * Para un documento, un JSON o un reporte, sin arrastrar modelos.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'empleado_id' => $this->empleadoId,
            'empleado' => $this->empleado?->nombreCompleto(),
            'fecha' => $this->fecha->format('Y-m-d'),
            'primera_entrada' => $this->primeraEntrada()?->marcado_at?->toIso8601String(),
            'ultima_salida' => $this->ultimaSalida()?->marcado_at?->toIso8601String(),
            'entradas' => $this->entradas(),
            'salidas' => $this->salidas(),
            'pares_completos' => $this->paresCompletos(),
            'sin_pareja' => $this->sinPareja()->count(),
            'trabajado_segundos' => $this->trabajadoSegundos(),
            'trabajado_horas' => $this->trabajadoHorasDecimales(),
            'tiempo_exacto' => $this->tiempoEsExacto(),
            'estado' => $this->estado->value,
            'marcaciones' => $this->totalMarcaciones(),
        ];
    }

    // ---------------------------------------------------------------- interno

    /**
     * @param  Collection<int, AsistenciaMarcacion>  $ordenadas
     * @return array<int, TramoJornada>
     */
    private static function emparejar(Collection $ordenadas): array
    {
        $tramos = [];
        $abierta = null;

        foreach ($ordenadas as $marcacion) {
            if ($marcacion->tipo === TipoMarcacion::Entrada) {
                // Dos entradas seguidas: la primera se queda sin cerrar. No se
                // descarta ni se fusiona — se muestra como lo que es.
                if ($abierta !== null) {
                    $tramos[] = TramoJornada::abierto($abierta);
                }
                $abierta = $marcacion;

                continue;
            }

            if ($abierta !== null) {
                $tramos[] = TramoJornada::cerrado($abierta, $marcacion);
                $abierta = null;

                continue;
            }

            $tramos[] = TramoJornada::salidaHuerfana($marcacion);
        }

        if ($abierta !== null) {
            $tramos[] = TramoJornada::abierto($abierta);
        }

        return $tramos;
    }

    /**
     * `Irregular` gana sobre `Abierta` cuando conviven: «esto no cuadra» es más
     * informativo que «falta cerrar», y quien revise tiene que ver primero lo raro.
     *
     * @param  array<int, TramoJornada>  $tramos
     */
    private static function estadoDe(array $tramos): EstadoJornada
    {
        if ($tramos === []) {
            return EstadoJornada::Completa;
        }

        $ultimo = array_key_last($tramos);

        foreach ($tramos as $posicion => $tramo) {
            if ($tramo->estaCerrado()) {
                continue;
            }

            // Una salida sin entrada: la secuencia no alterna, esté donde esté.
            if ($tramo->entrada === null) {
                return EstadoJornada::Irregular;
            }

            // Una entrada sin cerrar que NO es la última: hubo otra marcación
            // después, así que no es «se le olvidó salir» — es una entrada
            // duplicada. La POSICIÓN es lo que distingue los dos casos, y por eso
            // no basta con contar cuántos tramos quedaron abiertos.
            if ($posicion !== $ultimo) {
                return EstadoJornada::Irregular;
            }
        }

        return $tramos[$ultimo]->estaCerrado()
            ? EstadoJornada::Completa
            : EstadoJornada::Abierta;
    }
}
