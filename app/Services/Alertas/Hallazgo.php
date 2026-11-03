<?php

namespace App\Services\Alertas;

use Carbon\CarbonInterface;

/**
 * Lo que encontró un detector. Todo lo que va acá termina en un correo, así que
 * son textos armados a propósito: tipo de falla, cantidades, nombres de tareas o de
 * lectores. Nunca datos de clientes, montos, rutas del servidor ni mensajes de
 * excepción.
 */
final class Hallazgo
{
    public function __construct(
        public readonly string $titulo,
        public readonly string $detalle,
        public readonly string $queHacer,
        // Para decidir si empeoró: más trabajos fallidos, más lectores callados…
        public readonly int $conteo = 1,
        public readonly ?CarbonInterface $desde = null,
        public readonly ?string $enlace = null,
    ) {}
}
