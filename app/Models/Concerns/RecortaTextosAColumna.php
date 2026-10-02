<?php

namespace App\Models\Concerns;

/**
 * Recorta al guardar los textos libres que no caben en su columna.
 *
 * MySQL en modo estricto rechaza un VARCHAR demasiado largo con «Data too long» y revierte
 * la transacción entera: un motivo generado de más tiraba abajo la aplicación de un TXT de
 * pagos completo. SQLite, donde corren las pruebas, no controla largos, así que el error
 * solo aparecía en producción. Perder el final de un motivo es preferible a perder la
 * operación.
 *
 * Solo texto que se MUESTRA (motivos, notas, asuntos, nombres de evidencia). Nunca una
 * columna que identifica o que se usa para buscar coincidencias: recortar una clave
 * cambiaría en silencio a qué registro apunta.
 */
trait RecortaTextosAColumna
{
    /**
     * Solo texto libre: nunca identidades, rutas ni valores usados para buscar coincidencias.
     *
     * @return array<string, int>
     */
    abstract public static function largosDeTexto(): array;

    public static function bootRecortaTextosAColumna(): void
    {
        static::saving(function ($modelo) {
            $atributos = $modelo->getAttributes();
            foreach (static::recortarTextos($atributos) as $campo => $valor) {
                if ($valor !== $atributos[$campo]) {
                    $modelo->setAttribute($campo, $valor);
                }
            }
        });
    }

    /**
     * También para escrituras masivas, que no disparan saving.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    public static function recortarTextos(array $atributos): array
    {
        foreach (static::largosDeTexto() as $campo => $largo) {
            $valor = $atributos[$campo] ?? null;
            if (! is_string($valor) || mb_strlen($valor, 'UTF-8') <= $largo) {
                continue;
            }

            // VARCHAR(n) en MySQL cuenta caracteres, no bytes ni ancho visual: se corta
            // por caracteres. Los tres puntos cuentan dentro del tamaño de la columna.
            $atributos[$campo] = mb_substr($valor, 0, $largo - 3, 'UTF-8').'...';
        }

        return $atributos;
    }
}
