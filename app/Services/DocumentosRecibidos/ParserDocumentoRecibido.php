<?php

namespace App\Services\DocumentosRecibidos;

use App\Services\Ppq\DteCorreoParser;

/**
 * Extrae los datos de un DTE RECIBIDO desde el JSON adjunto. Reutiliza el
 * DteCorreoParser de PPQ para los campos comunes (numeroControl, codigoGeneracion,
 * sello, tipoDte, monto, fecha) y añade el EMISOR (nombre/NIT/NRC), que es el
 * proveedor que nos envió el documento. No asume: lo que falta queda en null.
 *
 * El Comprobante de Retención (tipo 07) no tiene "total a pagar": su resumen
 * usa otros campos (totalSujetoRetencion / totalIVAretenido), así que DteCorreoParser
 * (compartido con PPQ, que no maneja el 07) nunca lo encuentra. Este parser
 * corrige el total SOLO para ese tipo, sin tocar el común.
 */
class ParserDocumentoRecibido
{
    public function __construct(private readonly DteCorreoParser $comun) {}

    /**
     * @param  array<string, mixed>  $json  JSON ya decodificado del adjunto
     * @return array{tipo_documento: ?string, numero_control: ?string, codigo_generacion: ?string, sello_recepcion: ?string, emisor_nombre: ?string, emisor_nit: ?string, emisor_nrc: ?string, total: ?float, fecha: ?string}
     */
    public function extraer(array $json): array
    {
        $base = $this->comun->desdeJson($json);

        // El DTE puede venir "pelado" o envuelto: mismo criterio que DteCorreoParser.
        $dte = $json;
        foreach (['documento', 'dte', 'json', 'dteJson'] as $envoltorio) {
            if (is_array($json[$envoltorio] ?? null)) {
                $dte = $json[$envoltorio];
                break;
            }
        }
        $emisor = is_array($dte['emisor'] ?? null) ? $dte['emisor'] : [];
        $resumen = is_array($dte['resumen'] ?? null) ? $dte['resumen'] : [];

        return [
            'tipo_documento' => $base['tipoDte'],
            'numero_control' => $base['numeroControl'],
            'codigo_generacion' => $base['codigoGeneracion'],
            'sello_recepcion' => $base['sello'],
            'emisor_nombre' => $this->primero($emisor, ['nombre', 'nombreComercial']),
            'emisor_nit' => $this->primero($emisor, ['nit', 'numDocumento']),
            'emisor_nrc' => $this->primero($emisor, ['nrc']),
            'total' => $this->total($base['tipoDte'], $base['monto'], $resumen),
            'fecha' => $base['fecha'],
        ];
    }

    /**
     * Monto a mostrar como "total" según el tipo de DTE. Por defecto, el que ya
     * resolvió DteCorreoParser (totalPagar/montoTotalOperacion/totalPagarOperacion).
     * Para el 07 (Comprobante de Retención), ese campo no existe en el JSON oficial:
     * se usa resumen.totalSujetoRetencion (monto sujeto a retención), confirmado
     * contra los Comprobantes de Retención reales recibidos hasta ahora.
     *
     * @param  array<string, mixed>  $resumen
     */
    private function total(?string $tipoDte, ?float $montoComun, array $resumen): ?float
    {
        if ($tipoDte !== '07') {
            return $montoComun;
        }

        $sujeto = $this->primero($resumen, ['totalSujetoRetencion']);

        return $sujeto !== null ? (float) $sujeto : null;
    }

    /**
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
