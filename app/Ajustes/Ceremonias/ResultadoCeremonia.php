<?php

namespace App\Ajustes\Ceremonias;

/**
 * Desenlace de una ceremonia N3. Explícito por diseño: quien la invoca tiene que
 * poder distinguir "no se ejecutó porque falta el respaldo" de "no se ejecutó
 * porque la frase estaba mal", y decirlo en el campo correcto del formulario.
 *
 * `$campo` es el nombre del input al que pertenece el error ('frase', 'password')
 * o null cuando el rechazo no es culpa de un campo (una precondición).
 */
class ResultadoCeremonia
{
    private function __construct(
        public readonly bool $ejecutada,
        public readonly string $mensaje,
        public readonly ?string $campo = null,
        public readonly mixed $valor = null,
        public readonly ?string $avisoPersistente = null,
    ) {}

    public static function ejecutada(string $mensaje, mixed $valor = null, ?string $avisoPersistente = null): self
    {
        return new self(true, $mensaje, null, $valor, $avisoPersistente);
    }

    public static function rechazada(string $mensaje, ?string $campo = null): self
    {
        return new self(false, $mensaje, $campo);
    }
}
