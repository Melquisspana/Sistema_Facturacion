<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;

/**
 * Decide POR QUÉ un documento recibido tiene (o no) datos de DTE, y arma un
 * diagnóstico breve y NO sensible cuando algo falló. Compartida por el
 * sincronizador (correos nuevos) y el comando de backfill (registros ya
 * guardados), para que ambos clasifiquen exactamente igual.
 *
 * NUNCA decide `estado` (pendiente/enviado/ignorado): eso sigue siendo
 * triage manual del usuario, un campo totalmente independiente.
 */
class ClasificadorDocumentoRecibido
{
    /**
     * @param  array<string, mixed>  $datos  Salida de ParserDocumentoRecibido::extraer(), o [] si no se pudo leer.
     * @param  array<string, mixed>|null  $decodeFallido  Diagnóstico de diagnosticoDecode() si el .json no decodificó.
     * @return array{0: string, 1: ?array<string, mixed>}
     */
    public function clasificar(bool $tieneJson, array $datos, ?array $decodeFallido, string $asunto, string $pdfAdjunto): array
    {
        if ($tieneJson) {
            if ($datos === [] && $decodeFallido !== null) {
                return ['json_invalido', $decodeFallido];
            }

            $tipo = $datos['tipo_documento'] ?? null;
            if ($tipo === null) {
                // El adjunto decodificó como JSON, pero no tiene forma de DTE
                // (sin identificación reconocible): no es un DTE.
                return ['no_es_dte', null];
            }

            if (in_array($tipo, DocumentoRecibido::TIPOS_SOPORTADOS, true)) {
                return ['dte_valido', null];
            }

            // Es un DTE reconocible (tiene identificación), pero el tipo no tiene
            // mapeo de total en este módulo todavía. Se conservan los datos extraídos.
            return ['tipo_no_soportado', ['tipo_documento' => $tipo]];
        }

        // Solo PDF: ¿el nombre/asunto sugiere fuertemente que es un DTE al que le
        // falta el JSON, o es evidentemente otra cosa (estado de cuenta, orden de
        // compra, etc.)?
        return $this->pareceDte($asunto, $pdfAdjunto)
            ? ['falta_adjunto', null]
            : ['no_es_dte', null];
    }

    /**
     * Heurística de evidencia (asunto + nombre del PDF) para distinguir un DTE al
     * que solo le falta el JSON de un correo que claramente no es un DTE. No
     * infalible: ante la duda, clasifica como 'no_es_dte' (el caso conservador,
     * ya que no hay ningún dato de DTE que perder).
     */
    public function pareceDte(string $asunto, string $nombreArchivo): bool
    {
        $texto = $this->sinAcentos(mb_strtolower($asunto.' '.$nombreArchivo));

        if (preg_match('/dte-\d{2}-/', $texto) || str_contains($texto, 'codigo de generacion')) {
            return true;
        }

        foreach ([
            'credito fiscal', 'comprobante de retencion', 'comprobante de credito',
            'factura electronica', 'nota de credito', 'nota de debito',
            'sujeto excluido', 'factura de exportacion', 'comprobante de donacion',
            'nota de remision', 'comprobante de liquidacion',
        ] as $frase) {
            if (str_contains($texto, $frase)) {
                return true;
            }
        }

        return false;
    }

    private function sinAcentos(string $s): string
    {
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /**
     * Diagnóstico SANO (nunca contenido del correo/DTE) de por qué un .json no se
     * pudo decodificar: tamaño, mime, codificación probada y el mensaje de error.
     * Deliberadamente NO guarda `info.primeros_500` (vista previa del contenido).
     *
     * @param  array{ok: bool, info: array<string, mixed>, intentos: array<string, string>, error: ?string}  $dec
     * @return array<string, mixed>
     */
    public function diagnosticoDecode(array $dec, string $nombreArchivo): array
    {
        $info = (array) ($dec['info'] ?? []);

        return [
            'archivo' => $nombreArchivo,
            'error' => $dec['error'] ?? null,
            'size' => $info['size'] ?? null,
            'mime' => $info['mime'] ?? null,
            'bom' => $info['bom'] ?? null,
            'gzip' => $info['gzip'] ?? null,
            'parece_base64' => $info['parece_base64'] ?? null,
            'encoding_detectado' => $info['encoding_detectado'] ?? null,
            'intentos' => $dec['intentos'] ?? null,
        ];
    }
}
