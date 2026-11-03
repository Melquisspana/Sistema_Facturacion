<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Alertas\RegistroEventos;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;

/**
 * Fallas anotadas en {@see RegistroEventos}: errores 500 y tareas programadas.
 * Siguen abiertas mientras aparezcan nuevas; pasada la ventana sin ninguna, se dan
 * por normalizadas y el contador vuelve a cero.
 */
class EventosRecientes implements Detector
{
    private function __construct(
        private readonly string $tipo,
        private readonly string $nombre,
        private readonly string $queHacer,
    ) {}

    public static function errores500(): self
    {
        return new self(RegistroEventos::ERRORES_500, 'Errores 500 en el sistema',
            'Revisá storage/logs/laravel.log en el servidor a esa hora. Si se repite en la misma pantalla, avisá con la ruta que aparece acá.');
    }

    public static function tareasProgramadas(): self
    {
        return new self(RegistroEventos::TAREAS, 'Tareas programadas con error',
            'Revisá el registro de esa tarea en storage/logs y corréla a mano en el servidor para ver el error.');
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $evento = RegistroEventos::leer($this->tipo);

        if ($evento === null) {
            return null;
        }

        $ventana = (int) config('alertas.ventana_eventos_minutos', 30);

        if ($evento['ultima'] < now()->subMinutes($ventana)->getTimestamp()) {
            RegistroEventos::olvidar($this->tipo);

            return null;
        }

        $detalle = collect($evento['etiquetas'])
            ->map(fn (int $n, string $etiqueta) => $etiqueta.' ('.$n.')')
            ->implode(', ');

        return new Hallazgo(
            titulo: $this->nombre.': '.$evento['cantidad'],
            detalle: 'Último a las '.HoraNegocio::aLocal(Carbon::createFromTimestamp($evento['ultima']))->format('H:i')
                .'. Dónde: '.$detalle.'.',
            queHacer: $this->queHacer,
            conteo: $evento['cantidad'],
            desde: Carbon::createFromTimestamp($evento['primera']),
        );
    }
}
