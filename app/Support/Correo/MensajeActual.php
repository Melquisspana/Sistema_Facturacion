<?php

namespace App\Support\Correo;

/** Extrae la respuesta actual para interpretar; el mensaje completo se conserva aparte. */
final class MensajeActual
{
    public static function texto(?string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);
        $patron = '/^\s*(?:_{5,}|-{5,}\s*(?:Original Message|Mensaje original|Forwarded message)|(?:El|On)\s+[^\n]{0,400}(?:escribi[oó]|wrote)\s*:|(?:De|From)\s*:|>)/imu';
        $partes = preg_split($patron, $texto, 2);
        $texto = $partes[0] ?? '';
        // Outlook incluye [cid:UUID] en text/plain. No son códigos fiscales.
        $texto = (string) preg_replace('/\[?cid:[^\s\]<>]+\]?/iu', ' ', $texto);

        return trim($texto);
    }

    public static function desdePartes(?string $plano, ?string $html): string
    {
        // Separar ANTES de convertir HTML, cuando las marcas de cita aún existen.
        $html = preg_split('/<(?:blockquote\b|(?:div|section)\b[^>]*(?:gmail_quote|yahoo_quoted|divRplyFwdMsg)[^>]*>)/iu', (string) $html, 2)[0] ?? '';
        // El HTML de Outlook trae CRLF también ENTRE las celdas. Son formato del
        // código fuente, no fin de documento: solo </tr> debe terminar una fila.
        $html = (string) preg_replace_callback('#<tr\b[^>]*>.*?</tr\s*>#isu',
            fn ($m) => preg_replace('/[\r\n]+/', ' ', $m[0]), $html);
        // Una celda puede contener párrafos y saltos visuales. Solo el fin de TR
        // separa documentos; el ajuste de línea del control/UUID no crea otra fila.
        $html = (string) preg_replace_callback('#<t[dh]\b[^>]*>.*?</t[dh]>#isu',
            fn ($m) => preg_replace('#(?:<br\s*/?>|</(?:p|div)>|[\r\n]+)#iu', ' ', $m[0]), $html);
        $actualHtml = self::texto(CuerpoHtml::aTexto($html));
        $actualPlano = self::texto($plano);

        // Si hay HTML conserva filas/celdas; no duplica las mismas identidades con la
        // versión plana que ha perdido la estructura de la tabla.
        return $actualHtml !== '' ? $actualHtml : $actualPlano;
    }
}
