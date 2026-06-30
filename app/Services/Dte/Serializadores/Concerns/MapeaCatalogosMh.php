<?php

namespace App\Services\Dte\Serializadores\Concerns;

use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\Models\CatalogoMh;

/**
 * Helpers compartidos por los serializadores oficiales: mapeo de catálogos MH
 * (CAT-014 unidad, CAT-015 tributo, CAT-019 actividad, CAT-020 país) e
 * identificación/apéndice comunes. Solo lectura; no recalcula nada.
 */
trait MapeaCatalogosMh
{
    /** Descripción de actividad económica (CAT-019) por código. */
    protected function descActividad(?string $codigo): string
    {
        if (! $codigo) {
            return '';
        }

        return (string) (CatalogoMh::where('cat', '019')->where('codigo', $codigo)->value('valor') ?? '');
    }

    /** Descripción del tributo (CAT-015) por código. */
    protected function descTributo(string $codigo): string
    {
        return (string) (CatalogoMh::where('cat', '015')->where('codigo', $codigo)->value('valor')
            ?? 'Impuesto al Valor Agregado 13%');
    }

    /** Nombre del país (CAT-020) por código. */
    protected function nombrePais(?string $codigo): string
    {
        if (! $codigo) {
            return '';
        }

        return (string) (CatalogoMh::where('cat', '020')->where('codigo', $codigo)->value('valor') ?? '');
    }

    /**
     * Valida/mapea la unidad de medida (CAT-014) a entero 1..99 existente.
     *
     * @param  array<int, string>  $problemas
     */
    protected function uniMedida(LineaDteData $l, array &$problemas): int
    {
        $cod = (string) ($l->unidadMedida ?? '');
        if ($cod === '' || ! ctype_digit($cod)) {
            $problemas[] = "Línea {$l->numeroLinea}: unidad de medida CAT-014 inválida o ausente ('{$cod}').";

            return 0;
        }
        $n = (int) $cod;
        if ($n < 1 || $n > 99 || ! CatalogoMh::where('cat', '014')->where('codigo', $cod)->exists()) {
            $problemas[] = "Línea {$l->numeroLinea}: código de unidad de medida CAT-014 no reconocido ('{$cod}').";

            return 0;
        }

        return $n;
    }

    /** @return array<string, mixed> Campos comunes de "identificacion". */
    protected function identificacionComun(IdentificacionDteData $i): array
    {
        return [
            'version' => (int) $i->version,
            'ambiente' => (string) $i->ambiente,
            'tipoDte' => (string) $i->tipoDte,
            'numeroControl' => $i->numeroControl,
            'codigoGeneracion' => $i->codigoGeneracion,
            'tipoModelo' => (int) $i->tipoModelo,
            'tipoOperacion' => (int) $i->tipoOperacion,
            'tipoContingencia' => $i->tipoContingencia !== null ? (int) $i->tipoContingencia : null,
            'motivoContin' => $i->motivoContingencia,
            'fecEmi' => (string) $i->fechaEmision,
            'horEmi' => (string) $i->horaEmision,
            'tipoMoneda' => $i->tipoMoneda ?: 'USD',
        ];
    }

    /**
     * Dirección {departamento, municipio, distrito, complemento}. `distrito` es el
     * código CAT-008 (mismo dato/formato que usa el CCF). Si no se pasa, queda vacío.
     *
     * @return array<string, string>
     */
    protected function direccion(?string $departamento, ?string $municipio, ?string $complemento, ?string $distrito = null): array
    {
        return [
            'departamento' => (string) ($departamento ?? ''),
            'municipio' => (string) ($municipio ?? ''),
            'distrito' => (string) ($distrito ?? ''), // CAT-008
            'complemento' => (string) ($complemento ?? ''),
        ];
    }

    /**
     * @param  array<int, \App\DataTransferObjects\Dte\Salida\ApendiceDteData>  $apendice
     * @return array<int, array<string, string>>|null
     */
    protected function apendiceComun(array $apendice): ?array
    {
        if ($apendice === []) {
            return null;
        }

        $items = [];
        foreach ($apendice as $a) {
            $items[] = [
                'campo' => mb_substr((string) $a->campo, 0, 25),
                'etiqueta' => mb_substr((string) $a->etiqueta, 0, 50),
                'valor' => mb_substr((string) $a->valor, 0, 150),
            ];
        }

        return $items;
    }
}
