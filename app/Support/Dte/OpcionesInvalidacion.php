<?php

namespace App\Support\Dte;

use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;

/**
 * Vocabulario HUMANO del primer paso del asistente de invalidación, y el ÚNICO canal por
 * el que la UI conoce los requisitos de cada motivo.
 *
 * Es una capa de PRESENTACIÓN sobre {@see TipoAnulacionMh} + {@see PoliticaInvalidacion}:
 * le pone a cada valor de CAT-024 un título en lenguaje de oficina, una explicación, y
 * las banderas de campos condicionales YA RESUELTAS PARA EL DOCUMENTO CONCRETO que se va
 * a invalidar. Por eso recibe el {@see TipoDte}: las mismas tres opciones piden cosas
 * distintas según se invalide un CCF o una nota de crédito.
 *
 * Lo que NO hace, deliberadamente:
 *  - no define qué motivos existen (los toma de `TipoAnulacionMh::cases()`);
 *  - no decide la regla: la consulta a {@see PoliticaInvalidacion::requisitos()};
 *  - no valida nada: el Form Request, {@see \App\Services\Dte\ValidadorReglasInvalidacion}
 *    y el serializador siguen siendo la autoridad, y revalidan justo antes de firmar.
 *
 * El asistente de Alpine se limita a pintar estas banderas. NO existe una segunda matriz
 * en JavaScript: si la política cambia, la pantalla cambia con ella sin tocar la vista.
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
            'titulo' => 'Error en el documento',
            'descripcion' => 'El documento salió con un error y hay que dejarlo sin efecto.',
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
     * Opciones del paso 1, en el orden de CAT-024, resueltas para `$documento`. Cada una
     * arrastra su mapeo al valor oficial y las banderas de campos condicionales que la UI
     * necesita para decidir qué mostrar en el paso 2.
     *
     * @return array<int, array{
     *     valor: int,
     *     titulo: string,
     *     descripcion: string,
     *     etiqueta_oficial: string,
     *     soportado: bool,
     *     requiere_reemplazo: bool,
     *     requiere_motivo: bool,
     *     razon_no_soportado: ?string,
     *     nota_operativa: ?string
     * }>
     */
    public static function opciones(?TipoDte $documento): array
    {
        $opciones = [];

        foreach (TipoAnulacionMh::cases() as $tipo) {
            $textos = self::TEXTOS[$tipo->value] ?? null;
            $requisitos = PoliticaInvalidacion::requisitos($documento, $tipo);

            $opciones[] = array_merge([
                'valor' => $tipo->value,
                // Sin redacción propia, el título es la etiqueta oficial: preferible a
                // inventar un texto para un valor de catálogo que no conocemos.
                'titulo' => $textos['titulo'] ?? $tipo->label(),
                'descripcion' => $textos['descripcion'] ?? '',
                'etiqueta_oficial' => $tipo->label(),
            ], $requisitos->paraUi());
        }

        return $opciones;
    }
}
