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

        $dte = $this->desenvolver($json);

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

    /**
     * Identificador fiscal y nombre del receptor. Acepta `nit` (formato propio, ya
     * usado por CCF/NC generados por este sistema) o `numDocumento` (variantes tipo
     * ContaPortable), en ese orden. NUNCA se deriva del nombre: si ninguna de las dos
     * claves trae valor, queda en null y quien filtre por esto debe tratarlo como
     * "no identificable", no como "distinto".
     *
     * @param  array<string, mixed>  $json
     * @return array{nit: ?string, nombre: ?string}
     */
    public function receptor(array $json): array
    {
        $dte = $this->desenvolver($json);
        $receptor = is_array($dte['receptor'] ?? null) ? $dte['receptor'] : [];

        return [
            'nit' => $this->primero($receptor, ['nit', 'numDocumento']),
            'nombre' => $this->primero($receptor, ['nombre']),
        ];
    }

    /**
     * Las DOS claves de identificación del receptor por separado. {@see receptor()} se
     * queda con la primera que tenga valor; quien incorpora documentos necesita saber
     * además si el JSON trae dos identificadores distintos, porque entonces no hay un
     * receptor único y no se elige uno.
     *
     * @param  array<string, mixed>  $json
     * @return array{nit: ?string, numDocumento: ?string}
     */
    public function identificadoresReceptor(array $json): array
    {
        $dte = $this->desenvolver($json);
        $receptor = is_array($dte['receptor'] ?? null) ? $dte['receptor'] : [];

        return [
            'nit' => $this->primero($receptor, ['nit']),
            'numDocumento' => $this->primero($receptor, ['numDocumento']),
        ];
    }

    /**
     * Documento(s) relacionado(s) declarados en el propio JSON (el CCF original de una
     * NC). `numeroDocumento` es, en el esquema oficial del MH, el código de generación
     * del documento referenciado — no un número de control. No se asume ningún vínculo
     * que el JSON no declare explícitamente (nada de emparejar por importe, fecha u OC).
     *
     * @param  array<string, mixed>  $json
     * @return array<int, array{tipoDocumento: ?string, numeroDocumento: ?string, fechaEmision: ?string}>
     */
    public function documentoRelacionado(array $json): array
    {
        $items = $this->desenvolver($json)['documentoRelacionado'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $salida = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $salida[] = [
                'tipoDocumento' => $this->primero($item, ['tipoDocumento']),
                'numeroDocumento' => $this->primero($item, ['numeroDocumento']),
                'fechaEmision' => $this->primero($item, ['fechaEmision']),
            ];
        }

        return $salida;
    }

    /**
     * El DTE puede venir "pelado" (identificacion/receptor/... en la raíz) o envuelto
     * en {documento|dte|json|dteJson: {...}} con el sello afuera (estilo ContaPortable).
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function desenvolver(array $json): array
    {
        foreach (['documento', 'dte', 'json', 'dteJson'] as $envoltorio) {
            if (is_array($json[$envoltorio] ?? null)) {
                return $json[$envoltorio];
            }
        }

        return $json;
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
