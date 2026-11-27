<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Alertas\RegistroEventos;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;

/**
 * Fallas anotadas en {@see RegistroEventos}: errores 500, tareas programadas y tareas
 * que fallaron sin conexión a internet. Siguen abiertas mientras aparezcan nuevas;
 * pasada la ventana sin ninguna, se dan por normalizadas y el contador vuelve a cero.
 */
class EventosRecientes implements Detector
{
    private function __construct(
        private readonly string $tipo,
        private readonly string $nombre,
        private readonly string $queHacer,
        // null = el nombre con la cantidad («Tareas programadas con error: 3»).
        private readonly ?string $titulo = null,
        private readonly string $donde = 'Dónde',
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

    /**
     * Un corte de red hace fallar juntas todas las tareas que usan internet: un solo
     * aviso, sin la cantidad de fallas en el título, que no son problemas distintos.
     */
    public static function sinConexion(): self
    {
        return new self(RegistroEventos::SIN_CONEXION, 'Conexión a internet',
            'El servidor no llegaba a internet. Revisá el router, el switch y la luz. Las tareas se ponen al día solas cuando vuelve la conexión; no hace falta correrlas a mano.',
            titulo: 'Sin conexión a internet',
            donde: 'Tareas que no pudieron correr');
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
            titulo: $this->titulo ?? $this->nombre.': '.$evento['cantidad'],
            detalle: 'Último a las '.HoraNegocio::aLocal(Carbon::createFromTimestamp($evento['ultima']))->format('H:i')
                .'. '.$this->donde.': '.$detalle.'.',
            queHacer: $this->queHacer,
            conteo: $evento['cantidad'],
            desde: Carbon::createFromTimestamp($evento['primera']),
        );
    }
}
