<?php

namespace App\Support\Correo;

/**
 * Convierte el cuerpo HTML de un correo en texto CONSERVANDO la estructura de las tablas.
 *
 * ═══════════════════════ Por qué no basta con `strip_tags` ═══════════════════════
 *
 * Calleja manda las observaciones en una tabla: una fila por documento observado, con el
 * número de control en una celda y el motivo en otra. `strip_tags` a secas pega todas las
 * celdas de una fila sin separador, así que
 *
 *     <td>DTE-03-M001P002-000000000000119</td><td>77.74</td>
 *
 * se convierte en `DTE-03-M001P002-00000000000011977.74`, y ahí ya no hay número de control
 * que reconocer: el importe quedó soldado al final. Los documentos observados se perdían
 * enteros, en silencio.
 *
 * Por eso la estructura se pasa a caracteres ANTES de quitar las etiquetas: fin de celda es
 * un tabulador, fin de fila un salto de línea.
 *
 * ═══════════════════════════ No interpreta nada ═══════════════════════════
 *
 * Acá no se ejecuta HTML, no se resuelven recursos remotos y no se sigue ningún enlace: son
 * sustituciones de texto sobre una cadena. Y el tamaño se acota, porque una firma
 * corporativa con imágenes incrustadas puede pesar megas sin aportar una palabra.
 */
final class CuerpoHtml
{
    /** Tope de HTML que se convierte por mensaje. Ver la nota de la clase. */
    public const MAX_BYTES = 262144;

    /** Bloques cuyo contenido no es texto del mensaje y solo estorba. */
    private const SIN_TEXTO = '#<(script|style|head|title)\b[^>]*>.*?</\1>#is';

    /** Fin de celda: separador dentro de la fila. */
    private const FIN_CELDA = '#</(td|th)\s*>#i';

    /** Fin de fila o de bloque: salto de línea. */
    private const FIN_BLOQUE = '#</(tr|p|div|li|h[1-6]|table|blockquote)\s*>#i';

    private function __construct() {}

    /** Texto legible de un cuerpo HTML. Cadena vacía si no queda nada. */
    public static function aTexto(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $html = mb_strcut($html, 0, self::MAX_BYTES);

        $html = (string) preg_replace(self::SIN_TEXTO, ' ', $html);
        $html = (string) preg_replace(self::FIN_CELDA, "\t", $html);
        $html = (string) preg_replace(self::FIN_BLOQUE, "\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);

        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // El espacio duro de los correos HTML es un espacio, no un carácter raro que rompa
        // las expresiones que después buscan números de control.
        $texto = str_replace("\xC2\xA0", ' ', $texto);

        // Los espacios se compactan; los TABULADORES no se tocan, porque son el separador de
        // celda que acabamos de poner. Colapsar «[ \t]+» a un espacio los borraría y con
        // ellos la única marca de que dos valores venían en columnas distintas.
        $texto = (string) preg_replace('/ {2,}/', ' ', $texto);
        $texto = (string) preg_replace('/ *\t[ \t]*/', "\t", $texto);
        $texto = (string) preg_replace('/\n\s*\n\s*\n+/', "\n\n", $texto);
        $texto = (string) preg_replace('/\t+\n/', "\n", $texto);

        return trim((string) $texto);
    }

    /**
     * Junta el texto plano del correo con el derivado del HTML, sin repetir.
     *
     * Se usan los DOS a propósito: el acuse llega como texto, pero las observaciones vienen
     * en la tabla HTML, y la parte `text/plain` que genera el cliente de correo a veces
     * pierde justamente esas filas. Buscar en la unión no puede encontrar de menos.
     */
    public static function unir(?string $plano, ?string $html): string
    {
        $plano = trim((string) $plano);
        $delHtml = self::aTexto($html);

        if ($delHtml === '') {
            return $plano;
        }

        if ($plano === '' || str_contains($plano, $delHtml)) {
            return $plano !== '' ? $plano : $delHtml;
        }

        return trim($plano."\n".$delHtml);
    }
}
