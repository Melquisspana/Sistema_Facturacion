<?php

namespace App\Services\DocumentosRecibidos;

/**
 * Decide si un correo debe DESCARTARSE (no crear un DocumentoRecibido) por NO ser un DTE.
 *
 * Reglas (solo lectura de metadatos; no toca el buzón):
 *  - NUNCA descarta un correo con JSON DTE válido: actúa SOLO sobre la clasificación
 *    `no_es_dte` (sin JSON DTE válido y que no parece DTE). Así `dte_valido`,
 *    `tipo_no_soportado`, `json_invalido` y `falta_adjunto` SIEMPRE se conservan.
 *  - Reglas específicas por ASUNTO / NOMBRE DE ADJUNTO normalizados, nunca por remitente.
 *  - Descarte general de `no_es_dte` cuando `descartar_no_dte` está activo.
 *
 * Configuración en config/documentos_recibidos.php ('exclusiones'), sin variables .env.
 */
class FiltroExclusionCorreo
{
    /**
     * @param  array<int, string>  $nombresAdjuntos
     * @return array{regla: string, motivo: string}|null  null = no descartar (se importa)
     */
    public function evaluar(string $clasificacion, string $asunto, array $nombresAdjuntos): ?array
    {
        $cfg = (array) config('documentos_recibidos.exclusiones', []);

        if (! ($cfg['activo'] ?? false)) {
            return null;
        }

        // Guardia central: solo se descarta lo que NO es DTE. Cualquier documento con
        // JSON DTE válido (dte_valido / tipo_no_soportado), un JSON que falló al decodificar
        // (json_invalido) o un PDF que parece DTE (falta_adjunto) se conserva.
        if ($clasificacion !== 'no_es_dte') {
            return null;
        }

        $asuntoNorm = $this->normalizar($asunto);
        $adjuntosNorm = array_map(fn ($n) => $this->normalizar($n), $nombresAdjuntos);

        // 1) Reglas específicas (asunto / nombre de adjunto) → motivo nombrado.
        foreach ((array) ($cfg['reglas'] ?? []) as $regla) {
            $nombre = (string) ($regla['nombre'] ?? 'regla');
            $coincideAsunto = $this->contieneAlguna($asuntoNorm, (array) ($regla['asunto'] ?? []));
            $coincideAdjunto = $this->algunoContieneAlguna($adjuntosNorm, (array) ($regla['adjunto'] ?? []));
            if ($coincideAsunto || $coincideAdjunto) {
                return [
                    'regla' => $nombre,
                    'motivo' => 'Coincide con la regla "'.$nombre.'" (asunto/adjunto) y no tiene JSON DTE válido.',
                ];
            }
        }

        // 2) Descarte general de no-DTE.
        if ($cfg['descartar_no_dte'] ?? false) {
            return [
                'regla' => 'no_es_dte',
                'motivo' => 'Correo sin JSON DTE válido y que no parece un DTE.',
            ];
        }

        return null;
    }

    /** ¿Alguna aguja (normalizada) está contenida en el texto normalizado? */
    private function contieneAlguna(string $textoNorm, array $agujas): bool
    {
        foreach ($agujas as $aguja) {
            $a = $this->normalizar((string) $aguja);
            if ($a !== '' && str_contains($textoNorm, $a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $textosNorm
     */
    private function algunoContieneAlguna(array $textosNorm, array $agujas): bool
    {
        foreach ($textosNorm as $t) {
            if ($this->contieneAlguna($t, $agujas)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza para comparar: minúsculas + sin acentos + sin espacios. Quitar los
     * espacios tolera typos frecuentes ("ORDEN D ECOMPRA" → "ordendecompra", igual que
     * "orden de compra").
     */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', '', $s) ?? $s;
    }
}
