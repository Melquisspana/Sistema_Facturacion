<?php

namespace App\Ajustes\Ceremonias;

use Closure;

/**
 * Descripción COMPLETA de una acción de nivel N3: qué hace, qué hay que escribir
 * para confirmarla, qué tiene que ser cierto antes, y qué ejecutar si todo pasa.
 *
 * La acción se declara como un objeto y no como un método de un controlador para
 * que la ceremonia sea una sola —{@see CeremoniaN3}— y no una versión copiada por
 * cada pantalla. Cuando se abra el cambio de ambiente fiscal, lo que se escribirá
 * es una `AccionCriticaN3`, no otro flujo de confirmación.
 *
 * `frase` es literal y se compara sin margen: nada de "escriba CONFIRMAR" y
 * aceptar "confirmar". El objetivo de la frase no es demostrar que el usuario
 * sabe teclear, es obligarle a leer qué está a punto de hacer.
 *
 * `precondiciones` son cierres que devuelven `null` si todo está en orden o el
 * MOTIVO si no. Se prefiere el motivo a un booleano porque lo que necesita ver el
 * administrador es por qué no puede seguir, no que no puede.
 */
class AccionCriticaN3
{
    /**
     * @param  string  $clave  Identificador estable para la auditoría.
     * @param  string  $titulo  Nombre legible de la acción.
     * @param  string  $consecuencia  Qué pasa si se ejecuta. Se muestra antes de confirmar.
     * @param  string  $frase  Texto EXACTO que el usuario debe escribir.
     * @param  array<int, Closure>  $precondiciones  fn(): ?string — null si se cumple, motivo si no.
     * @param  Closure  $ejecutar  Lo que se hace cuando la ceremonia pasa entera.
     * @param  string|null  $avisoPersistente  Aviso que la aplicación debería seguir
     *                                         mostrando después (ver CeremoniaN3).
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $titulo,
        public readonly string $consecuencia,
        public readonly string $frase,
        public readonly Closure $ejecutar,
        public readonly array $precondiciones = [],
        public readonly ?string $avisoPersistente = null,
    ) {}

    /**
     * Primera precondición incumplida, o null si todas pasan.
     *
     * Se corta en la primera a propósito: si falta el respaldo del día, da igual
     * qué más falte — el administrador tiene una cosa que resolver, no una lista.
     */
    public function precondicionIncumplida(): ?string
    {
        foreach ($this->precondiciones as $precondicion) {
            $motivo = $precondicion();

            if (is_string($motivo) && $motivo !== '') {
                return $motivo;
            }
        }

        return null;
    }

    /**
     * ¿El texto escrito coincide EXACTAMENTE con la frase exigida?
     *
     * Se recortan los espacios de los extremos y nada más: pegar la frase desde
     * el aviso arrastra un espacio final y rechazarla por eso sería castigar al
     * usuario por un detalle que no cambia lo que entendió. Mayúsculas, acentos y
     * puntuación sí se exigen tal cual.
     */
    public function fraseCoincide(?string $escrito): bool
    {
        return trim((string) $escrito) === $this->frase;
    }
}
