<?php

namespace App\Services\Importacion;

use App\Models\CatalogoMh;
use App\Support\Importacion\LectorXlsx;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa los catálogos oficiales del MH (CAT-001..CAT-033) desde el Excel oficial
 * a la tabla genérica catalogos_mh.
 *
 * El Excel tiene UNA hoja con secciones apiladas:
 *   "CAT-NNN <Nombre>"   (encabezado de sección)
 *   "Código | Valores"   (encabezado de columnas)
 *   <código> | <descripción>   (filas de datos)
 *
 * Idempotente (updateOrCreate por cat+código). NO inventa códigos: solo copia lo
 * que está en el Excel. NO toca facturación, enums ni otros catálogos de la app.
 */
class ImportadorCatalogosMh
{
    public function __construct(private readonly LectorXlsx $lector) {}

    /** Ubica el .xlsx de catálogos en resources/dte/catalogos (cualquier nombre). */
    public function archivoPorDefecto(): ?string
    {
        $archivos = glob(resource_path('dte/catalogos').DIRECTORY_SEPARATOR.'*.xlsx') ?: [];

        return $archivos[0] ?? null;
    }

    /**
     * Importa todos los catálogos del Excel.
     *
     * @return array{cats: array<string, int>, nombres: array<string, string>, secciones: int, total: int}
     *
     * @throws RuntimeException
     */
    public function importar(?string $ruta = null): array
    {
        $ruta ??= $this->archivoPorDefecto();
        if ($ruta === null) {
            throw new RuntimeException('No se encontró ningún .xlsx en resources/dte/catalogos.');
        }

        $filas = $this->lector->filas($ruta);

        $catActual = null;
        $nombres = [];
        $conteo = [];
        $lote = [];
        $ahora = now();

        foreach ($filas as $celdas) {
            $a = $celdas['A'] ?? '';
            $b = $celdas['B'] ?? '';

            // Encabezado de sección: "CAT-014 Unidad de Medida".
            if (preg_match('/^CAT-(\d{2,3})\b\s*(.*)$/u', $a, $m)) {
                $catActual = str_pad($m[1], 3, '0', STR_PAD_LEFT);
                $nombres[$catActual] = trim($m[2]);
                $conteo[$catActual] ??= 0;

                continue;
            }

            if ($catActual === null) {
                continue; // títulos/encabezado del libro antes del primer CAT
            }

            // No es dato si: vacío, encabezado de columnas, sin descripción (B vacío)
            // o el "código" es demasiado largo (notas al pie del catálogo).
            $aLower = mb_strtolower($a);
            if ($a === '' || $b === '' || $aLower === 'código' || $aLower === 'codigo' || $aLower === 'valores') {
                continue;
            }
            if (mb_strlen($a) > 50) {
                continue; // nota/observación, no un código real
            }

            $lote[] = [
                'cat' => $catActual,
                'codigo' => $a,
                'valor' => $b,
                'nombre_catalogo' => $nombres[$catActual] ?? null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            $conteo[$catActual]++;
        }

        // Recarga completa (idempotente por reemplazo): limpia y reinserta todo.
        DB::transaction(function () use ($lote) {
            CatalogoMh::query()->delete();
            foreach (array_chunk($lote, 500) as $chunk) {
                CatalogoMh::insert($chunk);
            }
        });

        return [
            'cats' => $conteo,
            'nombres' => $nombres,
            'secciones' => count($conteo),
            'total' => array_sum($conteo),
        ];
    }
}
