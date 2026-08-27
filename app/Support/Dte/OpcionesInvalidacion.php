<?php

namespace App\Support\Dte;

use App\Enums\TipoAnulacionMh;

/**
 * Vocabulario HUMANO del primer paso del asistente de invalidación. Es una capa de
 * PRESENTACIÓN pura sobre {@see TipoAnulacionMh}: le pone a cada valor de CAT-024 un
 * título en lenguaje de oficina y una explicación, sin añadir, quitar ni reordenar
 * opciones.
 *
 * Lo que NO hace, deliberadamente:
 *  - no define qué tipos existen (los toma de `TipoAnulacionMh::cases()`);
 *  - no decide qué campos son obligatorios (eso lo dicen `requiereDocumentoReemplazo()`
 *    y `requiereMotivoTexto()`, que aquí solo se leen para pintar la UI);
 *  - no valida nada: el Form Request y el serializador siguen siendo la autoridad.
 *
 * Si mañana el MH añade un valor a CAT-024, aparece automáticamente en la UI con su
 * etiqueta oficial como título; solo faltaría redactarle la descripción amable.
 */
class OpcionesInvalidacion
{
    /**
     * Título y explicación en lenguaje de usuario para cada valor de CAT-024. El código
     * técnico NO se muestra como encabezado: viaja como texto secundario.
     *
     * @var array<int, array{titulo: string, descripcion: string}>
     */
    private const TEXTOS = [
        1 => [
            'titulo' => 'Reemplazar el documento',
            'descripcion' => 'El documento salió con un error y ya existe (o vas a indicar) otro documento que lo sustituye ante Hacienda.',
        ],
        2 => [
            'titulo' => 'Rescindir la operación',
            'descripcion' => 'La venta u operación no se realizó y no habrá documento que la reemplace.',
        ],
        3 => [
            'titulo' => 'Otro motivo permitido',
            'descripcion' => 'Ninguno de los anteriores describe el caso. Vas a explicar el motivo con tus palabras.',
        ],
    ];

    /**
     * Opciones del paso 1, en el orden de CAT-024. Cada una arrastra su mapeo al valor
     * oficial y las banderas de campos condicionales que la UI necesita para decidir
     * qué mostrar en el paso 2.
     *
     * @return array<int, array{
     *     valor: int,
     *     titulo: string,
     *     descripcion: string,
     *     etiqueta_oficial: string,
     *     requiere_reemplazo: bool,
     *     requiere_motivo: bool
     * }>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (TipoAnulacionMh::cases() as $tipo) {
            $textos = self::TEXTOS[$tipo->value] ?? null;

            $opciones[] = [
                'valor' => $tipo->value,
                // Sin redacción propia, el título es la etiqueta oficial: preferible a
                // inventar un texto para un valor de catálogo que no conocemos.
                'titulo' => $textos['titulo'] ?? $tipo->label(),
                'descripcion' => $textos['descripcion'] ?? '',
                'etiqueta_oficial' => $tipo->label(),
                'requiere_reemplazo' => $tipo->requiereDocumentoReemplazo(),
                'requiere_motivo' => $tipo->requiereMotivoTexto(),
            ];
        }

        return $opciones;
    }
}
