<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Sistema\SaludPublica;

/**
 * Lo mismo que mira /salud, para que el correo nombre qué cayó mientras el
 * servidor sigue prendido. Si se apaga entero, avisa el vigilante de afuera.
 */
class SaludDelSistema implements Detector
{
    public function __construct(private readonly SaludPublica $salud) {}

    public function tipo(): string
    {
        return 'salud';
    }

    public function nombre(): string
    {
        return 'Salud del sistema';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        // «avisos» es este mismo comando: si corre, el planificador anda.
        $fallan = array_filter(
            array_diff_key($this->salud->evaluar(), ['avisos' => true]),
            fn (?string $motivo) => $motivo !== null,
        );

        if ($fallan === []) {
            return null;
        }

        return new Hallazgo(
            titulo: 'Falla en el sistema: '.str_replace('_', ' ', implode(', ', array_keys($fallan))),
            detalle: implode(' ', $fallan),
            queHacer: 'Abrí Salud del sistema para ver el detalle. Si es la cola o un firmador, reiniciá su tarea en el servidor; si es el disco, liberá espacio.',
            conteo: count($fallan),
            enlace: 'admin.salud-sistema',
        );
    }
}
