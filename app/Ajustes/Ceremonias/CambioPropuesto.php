<?php

namespace App\Ajustes\Ceremonias;

use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\NivelConfirmacion;

/**
 * Un cambio que el usuario propone y todavía NO se ha guardado. Es lo que se le
 * enseña antes de confirmar.
 *
 * Para un ajuste normal lleva el antes y el después, porque ver "587 → 465" es
 * justo lo que evita el error. Para un secreto lleva `antes` y `despues` en null
 * y la frase se limita a decir que será reemplazado: mostrar el valor anterior
 * "para que compare" convertiría la pantalla de confirmación en el sitio más
 * cómodo del sistema para leer una contraseña.
 */
class CambioPropuesto
{
    private function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly NivelConfirmacion $nivel,
        public readonly bool $esSecreto,
        public readonly ?string $antes,
        public readonly ?string $despues,
        public readonly string $descripcion,
    ) {}

    public static function deValor(DefinicionAjuste $definicion, mixed $antes, mixed $despues): self
    {
        return new self(
            clave: $definicion->clave,
            etiqueta: $definicion->etiqueta,
            nivel: $definicion->nivel,
            esSecreto: false,
            antes: self::legible($antes),
            despues: self::legible($despues),
            descripcion: $definicion->etiqueta.': «'.self::legible($antes).'» → «'.self::legible($despues).'»',
        );
    }

    public static function deSecreto(DefinicionAjuste $definicion): self
    {
        return new self(
            clave: $definicion->clave,
            etiqueta: $definicion->etiqueta,
            nivel: $definicion->nivel,
            esSecreto: true,
            antes: null,
            despues: null,
            descripcion: $definicion->etiqueta.' será reemplazada.',
        );
    }

    /** Se quita el override y el ajuste vuelve a su fallback. */
    public static function deOverrideQuitado(DefinicionAjuste $definicion): self
    {
        return new self(
            clave: $definicion->clave,
            etiqueta: $definicion->etiqueta,
            nivel: $definicion->nivel,
            esSecreto: $definicion->tipo->esSecreto(),
            antes: null,
            despues: null,
            descripcion: $definicion->etiqueta.' dejará de estar guardada en la aplicación y volverá al valor del archivo de configuración.',
        );
    }

    private static function legible(mixed $valor): string
    {
        return match (true) {
            $valor === null, $valor === '' => 'sin definir',
            is_bool($valor) => $valor ? 'Sí' : 'No',
            default => (string) $valor,
        };
    }
}
