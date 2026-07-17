<?php

namespace App\Services\Importacion;

use App\Models\ActividadEconomica;
use App\Models\CatalogoMh;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza `actividades_economicas` (CAT-019, la tabla que usa
 * `Cliente.actividad_economica_id`) desde `catalogos_mh` (cat='019'), que ya
 * tiene el catálogo oficial completo del MH importado desde el Excel oficial.
 * No vuelve a leer el Excel: reutiliza la fuente ya importada y verificada.
 *
 * Nunca borra actividades existentes ni cambia su `id`: usa `updateOrCreate`
 * por `codigo`. Los códigos que no aparezcan en la fuente simplemente no se
 * tocan. Si la fuente tiene códigos duplicados o filas inválidas (código o
 * descripción vacíos), NO aplica ningún cambio (todo o nada).
 */
class SincronizadorActividadesEconomicas
{
    private const CATALOGO = '019';

    /**
     * @return array{total_fuente: int, nuevos: int, actualizados: int, sin_cambios: int, invalidos: int, duplicados: int, aplicado: bool}
     */
    public function sincronizar(bool $aplicar = false): array
    {
        $filas = CatalogoMh::where('cat', self::CATALOGO)->get(['codigo', 'valor']);

        $porCodigo = [];
        $duplicados = 0;
        $invalidos = 0;

        foreach ($filas as $fila) {
            $codigo = trim((string) $fila->codigo);
            $descripcion = trim((string) $fila->valor);

            if ($codigo === '' || $descripcion === '') {
                $invalidos++;

                continue;
            }

            if (array_key_exists($codigo, $porCodigo)) {
                $duplicados++;

                continue;
            }

            $porCodigo[$codigo] = $descripcion;
        }

        // Fuente con problemas: no se aplica nada (todo o nada), aunque se
        // haya pedido --apply. El llamador decide qué hacer con el reporte.
        if ($duplicados > 0 || $invalidos > 0) {
            return [
                'total_fuente' => count($porCodigo),
                'nuevos' => 0,
                'actualizados' => 0,
                'sin_cambios' => 0,
                'invalidos' => $invalidos,
                'duplicados' => $duplicados,
                'aplicado' => false,
            ];
        }

        $existentes = ActividadEconomica::pluck('nombre', 'codigo');

        $nuevos = 0;
        $actualizados = 0;
        $sinCambios = 0;

        $procesar = function () use ($porCodigo, $existentes, &$nuevos, &$actualizados, &$sinCambios, $aplicar): void {
            foreach ($porCodigo as $codigo => $descripcion) {
                if (! $existentes->has($codigo)) {
                    $nuevos++;
                    if ($aplicar) {
                        ActividadEconomica::create(['codigo' => $codigo, 'nombre' => $descripcion, 'activo' => true]);
                    }

                    continue;
                }

                if ($existentes[$codigo] !== $descripcion) {
                    $actualizados++;
                    if ($aplicar) {
                        // updateOrCreate por codigo: conserva el mismo id, solo cambia nombre/activo.
                        ActividadEconomica::where('codigo', $codigo)->update(['nombre' => $descripcion, 'activo' => true]);
                    }
                } else {
                    $sinCambios++;
                }
            }
        };

        if ($aplicar) {
            DB::transaction($procesar);
        } else {
            $procesar();
        }

        return [
            'total_fuente' => count($porCodigo),
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'sin_cambios' => $sinCambios,
            'invalidos' => $invalidos,
            'duplicados' => $duplicados,
            'aplicado' => $aplicar,
        ];
    }
}
