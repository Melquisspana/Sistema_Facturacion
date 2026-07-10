<?php

namespace App\Services\DocumentosRecibidos;

use App\Services\Ppq\DteCorreoParser;

/**
 * Extrae los datos de un DTE RECIBIDO desde el JSON adjunto. Reutiliza el
 * DteCorreoParser de PPQ para los campos comunes (numeroControl, codigoGeneracion,
 * sello, tipoDte, monto, fecha) y añade el EMISOR (nombre/NIT/NRC), que es el
 * proveedor que nos envió el documento. No asume: lo que falta queda en null.
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

        return [
            'tipo_documento' => $base['tipoDte'],
            'numero_control' => $base['numeroControl'],
            'codigo_generacion' => $base['codigoGeneracion'],
            'sello_recepcion' => $base['sello'],
            'emisor_nombre' => $this->primero($emisor, ['nombre', 'nombreComercial']),
            'emisor_nit' => $this->primero($emisor, ['nit', 'numDocumento']),
            'emisor_nrc' => $this->primero($emisor, ['nrc']),
            'total' => $base['monto'],
            'fecha' => $base['fecha'],
        ];
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
