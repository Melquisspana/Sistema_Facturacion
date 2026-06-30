<?php

namespace App\Services\Ppq;

use App\Support\OrdenCompra;

/**
 * Extrae los datos de un CCF/NC desde el JSON adjunto en el correo enviado.
 *
 * Es tolerante a distintas estructuras (el sistema propio y ContaPortable):
 *  - DTE "pelado" (identificacion/resumen/apendice),
 *  - envuelto en {documento|dte|json: {...}} con selloRecibido afuera,
 *  - el sello puede venir como selloRecibido / sello / respuestaMH.selloRecibido.
 * Nunca asume; si un campo no está, queda en null.
 */
class DteCorreoParser
{
    /**
     * @param  array<string, mixed>  $json
     * @return array{numeroControl: ?string, codigoGeneracion: ?string, sello: ?string, tipoDte: ?string, ordenCompra: ?string, sala: ?string, salaNombre: ?string, monto: ?float, fecha: ?string}
     */
    public function desdeJson(array $json): array
    {
        $sello = $this->primero($json, ['selloRecibido', 'sello', 'selloRecepcion'])
            ?? data_get($json, 'respuestaMH.selloRecibido')
            ?? data_get($json, 'respuesta.selloRecibido');

        // El DTE puede estar pelado o envuelto.
        $dte = $json;
        foreach (['documento', 'dte', 'json', 'dteJson'] as $envoltorio) {
            if (is_array($json[$envoltorio] ?? null)) {
                $dte = $json[$envoltorio];
                break;
            }
        }

        $ident = is_array($dte['identificacion'] ?? null) ? $dte['identificacion'] : [];
        $resumen = is_array($dte['resumen'] ?? null) ? $dte['resumen'] : [];
        $receptor = is_array($dte['receptor'] ?? null) ? $dte['receptor'] : [];

        $monto = $this->primero($resumen, ['totalPagar', 'montoTotalOperacion', 'totalPagarOperacion']);

        return [
            'numeroControl' => $this->primero($ident, ['numeroControl']) ?? $this->primero($dte, ['numeroControl']),
            'codigoGeneracion' => $this->primero($ident, ['codigoGeneracion']) ?? $this->primero($dte, ['codigoGeneracion']),
            'sello' => $sello !== null ? (string) $sello : null,
            'tipoDte' => $this->primero($ident, ['tipoDte']) ?? $this->primero($dte, ['tipoDte']),
            'ordenCompra' => $this->ordenCompra($dte),
            'sala' => OrdenCompra::salaDesde($this->ordenCompra($dte)),
            // El NOMBRE DE SALA es el nombre comercial del receptor en el propio DTE
            // (ej. "Súper Selectos Ilobasco"). Es la fuente directa, sin lookups.
            'salaNombre' => $this->primero($receptor, ['nombreComercial']),
            'monto' => $monto !== null ? (float) $monto : null,
            'fecha' => $this->primero($ident, ['fecEmi', 'fechaEmision']),
        ];
    }

    /** Busca la orden de compra en apendice (campo ordenCompra) o en la raíz. */
    private function ordenCompra(array $dte): ?string
    {
        foreach ((array) ($dte['apendice'] ?? []) as $item) {
            $campo = strtolower((string) ($item['campo'] ?? $item['etiqueta'] ?? ''));
            if (str_contains($campo, 'orden') && filled($item['valor'] ?? null)) {
                return (string) $item['valor'];
            }
        }

        return $this->primero($dte, ['ordenCompra', 'numeroOrdenCompra', 'numOrdenCompra']);
    }

    /**
     * Primer valor no vacío de una lista de claves.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<int, string>  $claves
     */
    private function primero(array $datos, array $claves): ?string
    {
        foreach ($claves as $clave) {
            if (filled($datos[$clave] ?? null)) {
                return (string) $datos[$clave];
            }
        }

        return null;
    }
}
