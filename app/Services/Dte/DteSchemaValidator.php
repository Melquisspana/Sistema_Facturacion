<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;

/**
 * Valida un array PHP contra el JSON Schema OFICIAL del MH (el que está en
 * resources/dte/schemas/<tipo>/, localizado por DteSchemaRepository).
 *
 * Los schemas del MH son draft-07 y usan exclusiveMinimum/Maximum NUMÉRICOS, por
 * lo que se valida preferentemente con opis/json-schema (soporte draft-07 completo);
 * justinrainbow/json-schema queda como respaldo (interpreta esos campos con reglas
 * draft-04, generando falsos positivos en bounds numéricos).
 *
 * Si NO hay ninguna librería instalada, NO falla feo: devuelve disponible=false con
 * un mensaje claro. No modifica el DTE, no inventa schema, no firma, no transmite.
 */
class DteSchemaValidator
{
    private const OPIS = 'Opis\\JsonSchema\\Validator';

    private const JUSTINRAINBOW = 'JsonSchema\\Validator';

    public function __construct(private readonly DteSchemaRepository $repo) {}

    /** ¿Está instalada alguna librería de validación de JSON Schema? */
    public function disponible(): bool
    {
        return class_exists(self::OPIS) || class_exists(self::JUSTINRAINBOW);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{estado: string, valido: bool, disponible: bool, errores: array<int, string>, mensaje: string}
     */
    public function validar(array $datos, TipoDte $tipo): array
    {
        $info = $this->repo->paraTipo($tipo);
        if ($info === null) {
            return $this->resultado('sin_schema', false, true, [], 'No hay schema oficial colocado para '.$tipo->label().'.');
        }

        if (! $this->disponible()) {
            return $this->resultado(
                'sin_libreria',
                false,
                false,
                [],
                'Validación contra schema pendiente: falta la librería de JSON Schema. '
                .'Instalá opis/json-schema (draft-07): composer require opis/json-schema'
            );
        }

        $schemaJson = (string) $this->repo->leer($tipo);
        // Convierte arrays asociativos a stdClass y conserva tipos (number/integer).
        $payload = json_decode(json_encode($datos));

        $errores = class_exists(self::OPIS)
            ? $this->validarConOpis($payload, $schemaJson)
            : $this->validarConJustinrainbow($payload, $schemaJson);

        if ($errores === []) {
            return $this->resultado('valido', true, true, [], 'El documento es válido contra el schema oficial.');
        }

        return $this->resultado('invalido', false, true, $errores, 'El documento NO es válido contra el schema oficial.');
    }

    /**
     * @return array<int, string>  errores (vacío = válido)
     */
    private function validarConOpis(mixed $payload, string $schemaJson): array
    {
        $clase = self::OPIS;

        // multipleOf con la escala por defecto (14 decimales) rechaza valores
        // monetarios legítimos: el double de "120.68" es 120.68000000000001 y opis,
        // al comparar con 14 decimales, ve esa basura binaria como "no múltiplo".
        // La escala 8 absorbe el error de representación del float en los montos
        // (multipleOf 0.01) y a la vez respeta los campos de línea de los schemas MH
        // (cantidad/precioUni/ventaGravada usan multipleOf 1e-8, por eso no puede
        // bajar de 8). No recalcula nada ni toca los montos; solo afina la tolerancia
        // del validador, sin dejar pasar violaciones reales (0.015, 0.001, etc. fallan).
        \Opis\JsonSchema\Helper::$numberScale = 8;

        $validator = new $clase();
        $validator->setMaxErrors(250); // por defecto opis se detiene en el primer error
        $resultado = $validator->validate($payload, json_decode($schemaJson));

        if ($resultado->isValid()) {
            return [];
        }

        $errores = [];
        $formateador = new \Opis\JsonSchema\Errors\ErrorFormatter();
        foreach ($formateador->formatKeyed($resultado->error()) as $puntero => $mensajes) {
            foreach ((array) $mensajes as $mensaje) {
                $errores[] = ($puntero !== '' ? $puntero : '/').': '.$mensaje;
            }
        }

        return $errores;
    }

    /**
     * @return array<int, string>
     */
    private function validarConJustinrainbow(mixed $payload, string $schemaJson): array
    {
        $clase = self::JUSTINRAINBOW;
        $validador = new $clase();
        $validador->validate($payload, json_decode($schemaJson));

        if ($validador->isValid()) {
            return [];
        }

        $errores = [];
        foreach ($validador->getErrors() as $e) {
            $prop = $e['property'] ?? '';
            $errores[] = ($prop !== '' ? $prop.': ' : '').($e['message'] ?? 'error');
        }

        return $errores;
    }

    /**
     * @param  array<int, string>  $errores
     * @return array{estado: string, valido: bool, disponible: bool, errores: array<int, string>, mensaje: string}
     */
    private function resultado(string $estado, bool $valido, bool $disponible, array $errores, string $mensaje): array
    {
        return compact('estado', 'valido', 'disponible', 'errores', 'mensaje');
    }
}
