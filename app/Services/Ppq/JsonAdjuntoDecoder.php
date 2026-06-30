<?php

namespace App\Services\Ppq;

/**
 * Decodifica el JSON de un adjunto de correo de forma robusta. `json_decode` exige
 * UTF-8, pero los DTE de ContaPortable suelen venir en ISO-8859-1 / Windows-1252,
 * con BOM, o (raro) gzip/base64. Esta clase inspecciona el contenido y prueba
 * varias codificaciones, devolviendo cuál funcionó + info de diagnóstico.
 */
class JsonAdjuntoDecoder
{
    private const ENCODINGS = ['UTF-8', 'ISO-8859-1', 'Windows-1252'];

    /**
     * @return array{ok: bool, data: ?array, encoding_usado: ?string, info: array<string, mixed>, intentos: array<string, string>, error: ?string}
     */
    public function decodificar(string $raw, string $mime = '', string $filename = ''): array
    {
        $info = [
            'size' => strlen($raw),
            'mime' => $mime,
            'filename' => $filename,
            'bom' => str_starts_with($raw, "\xEF\xBB\xBF"),
            'gzip' => str_starts_with($raw, "\x1f\x8b"),
            'parece_base64' => false,
        ];

        $contenido = $raw;

        // ¿gzip?
        if ($info['gzip']) {
            $d = @gzdecode($raw);
            if ($d !== false) {
                $contenido = $d;
            }
        }

        // ¿base64 (texto)? Solo si decodifica a algo que contiene una llave JSON.
        $trim = trim($contenido);
        if ($trim !== '' && strlen($trim) % 4 === 0 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $trim)) {
            $maybe = base64_decode($trim, true);
            if ($maybe !== false && str_contains($maybe, '{')) {
                $info['parece_base64'] = true;
                $contenido = $maybe;
            }
        }

        // Quitar BOM UTF-8 si está.
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        $info['encoding_detectado'] = mb_detect_encoding($contenido, self::ENCODINGS, true) ?: 'desconocido';
        $info['primeros_500'] = mb_substr($this->aUtf8Visible($contenido), 0, 500);

        $intentos = [];
        foreach (self::ENCODINGS as $enc) {
            $candidato = $enc === 'UTF-8' ? $contenido : mb_convert_encoding($contenido, 'UTF-8', $enc);
            $data = json_decode($candidato, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $intentos[$enc] = 'ok';

                return ['ok' => true, 'data' => $data, 'encoding_usado' => $enc, 'info' => $info, 'intentos' => $intentos, 'error' => null];
            }
            $intentos[$enc] = json_last_error_msg();
        }

        return [
            'ok' => false,
            'data' => null,
            'encoding_usado' => null,
            'info' => $info,
            'intentos' => $intentos,
            'error' => 'No se pudo decodificar el JSON con ninguna codificación (UTF-8/ISO-8859-1/Windows-1252).',
        ];
    }

    /** Convierte a UTF-8 solo para MOSTRAR los primeros caracteres sin romper. */
    private function aUtf8Visible(string $s): string
    {
        $enc = mb_detect_encoding($s, self::ENCODINGS, true) ?: 'Windows-1252';

        return $enc === 'UTF-8' ? $s : mb_convert_encoding($s, 'UTF-8', $enc);
    }
}
