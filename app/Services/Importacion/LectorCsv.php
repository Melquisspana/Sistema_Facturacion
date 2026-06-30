<?php

namespace App\Services\Importacion;

use Illuminate\Support\Str;

/**
 * Lee un CSV a filas asociativas mapeando los encabezados a claves canónicas.
 *
 * - Detecta el delimitador (coma o punto y coma) y quita el BOM UTF-8.
 * - Normaliza los encabezados (sin acentos, minúsculas) y los traduce vía alias.
 * - Si se pasan claves $requeridas, BUSCA la fila de encabezados real entre las
 *   primeras filas (saltando títulos/filas decorativas como las que agrega Excel).
 * - Las columnas no mapeadas se ignoran; las filas vacías se descartan.
 */
class LectorCsv
{
    /** Cuántas filas iniciales se inspeccionan buscando el encabezado. */
    private const MAX_FILAS_ENCABEZADO = 10;

    /**
     * @param  array<string, string>  $alias  encabezado_normalizado => clave_canónica
     * @param  array<int, string>  $requeridas  claves canónicas que debe tener el encabezado
     * @return array<int, array<string, string>>
     *
     * @throws EncabezadoNoEncontradoException
     */
    public function leer(string $ruta, array $alias, array $requeridas = []): array
    {
        $delimitador = $this->detectarDelimitador($ruta);

        $crudas = [];
        $handle = fopen($ruta, 'r');
        if ($handle === false) {
            return [];
        }
        while (($cols = fgetcsv($handle, 0, $delimitador)) !== false) {
            $crudas[] = $cols;
        }
        fclose($handle);

        if ($crudas === []) {
            return [];
        }
        // Quita el BOM del primer valor.
        $crudas[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $crudas[0][0]);

        $indiceEncabezado = $this->ubicarEncabezado($crudas, $alias, $requeridas);
        if ($indiceEncabezado === null) {
            if ($requeridas !== []) {
                throw new EncabezadoNoEncontradoException();
            }

            return []; // sin requeridas y sin filas con datos
        }

        // Mapea los encabezados de la fila localizada a claves canónicas.
        $canonicas = [];
        foreach ($crudas[$indiceEncabezado] as $i => $titulo) {
            $canonicas[$i] = $alias[$this->normalizar((string) $titulo)] ?? null;
        }

        $filas = [];
        for ($i = $indiceEncabezado + 1; $i < count($crudas); $i++) {
            $cols = $crudas[$i];
            if ($this->filaVacia($cols)) {
                continue;
            }

            $fila = [];
            foreach ($cols as $j => $valor) {
                $clave = $canonicas[$j] ?? null;
                if ($clave !== null) {
                    $fila[$clave] = trim((string) $valor);
                }
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * Devuelve el índice de la fila de encabezados.
     * - Con $requeridas: la primera fila (entre las primeras MAX) cuyas columnas
     *   mapeadas contengan TODAS las claves requeridas.
     * - Sin $requeridas: la primera fila no vacía (compatibilidad).
     *
     * @param  array<int, array<int, string>>  $crudas
     * @param  array<string, string>  $alias
     * @param  array<int, string>  $requeridas
     */
    private function ubicarEncabezado(array $crudas, array $alias, array $requeridas): ?int
    {
        $limite = min(self::MAX_FILAS_ENCABEZADO, count($crudas));

        for ($i = 0; $i < $limite; $i++) {
            if ($this->filaVacia($crudas[$i])) {
                continue;
            }

            if ($requeridas === []) {
                return $i; // primera fila con datos
            }

            $mapeadas = [];
            foreach ($crudas[$i] as $titulo) {
                $clave = $alias[$this->normalizar((string) $titulo)] ?? null;
                if ($clave !== null) {
                    $mapeadas[$clave] = true;
                }
            }

            $tieneTodas = true;
            foreach ($requeridas as $req) {
                if (! isset($mapeadas[$req])) {
                    $tieneTodas = false;
                    break;
                }
            }
            if ($tieneTodas) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<int, string> $cols */
    private function filaVacia(array $cols): bool
    {
        return count(array_filter($cols, fn ($v) => trim((string) $v) !== '')) === 0;
    }

    private function detectarDelimitador(string $ruta): string
    {
        $primera = '';
        $handle = fopen($ruta, 'r');
        if ($handle !== false) {
            $primera = (string) fgets($handle);
            fclose($handle);
        }

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    private function normalizar(string $texto): string
    {
        $texto = Str::ascii($texto);
        $texto = strtolower(trim($texto));
        $texto = preg_replace('/[^a-z0-9]+/', '_', $texto);

        return trim((string) $texto, '_');
    }
}
