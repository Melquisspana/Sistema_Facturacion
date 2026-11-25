<?php

namespace App\Services\Alertas;

interface Detector
{
    /** Clave estable del tipo de aviso: agrupa los repetidos y lleva su tope. */
    public function tipo(): string;

    /** Nombre corto, para el «ya se normalizó». */
    public function nombre(): string;

    /**
     * false = ahora no opina (módulo apagado, fuera de horario). El aviso abierto
     * queda como está: ni se repite ni se da por normalizado.
     *
     * Si no puede ver su dato (viejo, ausente o módulo apagado), devuelve false; nunca
     * null desde revisar(). Un null cierra el aviso y manda un «Ya se normalizó» falso.
     */
    public function aplica(): bool;

    /** null = todo bien. */
    public function revisar(): ?Hallazgo;
}
