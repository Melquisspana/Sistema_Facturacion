<?php

namespace App\Services\Exportaciones;

use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Altas y cambios del catálogo de exportación: producto base y presentaciones.
 *
 * Reglas que viven acá y no en los formularios (ver docs/DISENO_CATALOGO_EXPORTACION.md):
 *
 *   · Onzas y libras SIEMPRE se calculan de gramos y kilos. Se capturaban a mano
 *     y había presentaciones con 0 oz o con las libras de otro producto.
 *   · El peso bruto propone neto + 1 kg (la caja), que es lo que pesan todas.
 *   · Una presentación no se repite dentro de su producto: mismas unidades por
 *     caja y mismos gramos es la misma presentación. Así nació el desorden anterior.
 *   · Los nombres son del producto base; las presentaciones llevan una copia, que
 *     se actualiza al renombrar. Los items de listas ya hechas no se tocan.
 */
class CatalogoExportacion
{
    public const GRAMOS_A_ONZAS = 0.035274;

    public const KILOS_A_LIBRAS = 2.20462;

    /** Campos de presentación que acepta el servicio; el resto se calcula. */
    private const CAMPOS_PRESENTACION = [
        'codigo', 'unidad', 'unidades_por_caja', 'gramos_por_unidad',
        'peso_neto_caja_kg', 'peso_bruto_caja_kg', 'precio_caja', 'activo',
    ];

    public function crearProducto(array $base, array $presentacion): ExportacionProductoBase
    {
        return DB::transaction(function () use ($base, $presentacion) {
            $producto = ExportacionProductoBase::create([
                'nombre_es' => trim($base['nombre_es']),
                'nombre_en' => trim($base['nombre_en']),
                'categoria' => $base['categoria'] ?? null,
                'activo' => true,
            ]);

            $this->agregarPresentacion($producto, $presentacion);

            return $producto;
        });
    }

    public function agregarPresentacion(ExportacionProductoBase $base, array $datos): ExportacionProducto
    {
        $datos = $this->completar($datos);
        $this->exigirUnica($base, $datos);

        return $base->presentaciones()->create($datos + [
            'nombre_es' => $base->nombre_es,
            'nombre_en' => $base->nombre_en,
            'activo' => $datos['activo'] ?? true,
        ]);
    }

    public function actualizarPresentacion(ExportacionProducto $presentacion, array $datos): ExportacionProducto
    {
        $datos = $this->completar($datos);

        if ($presentacion->base !== null) {
            $this->exigirUnica($presentacion->base, $datos, $presentacion->id);
        }

        $presentacion->update($datos);

        return $presentacion;
    }

    public function actualizarBase(ExportacionProductoBase $base, array $datos): ExportacionProductoBase
    {
        return DB::transaction(function () use ($base, $datos) {
            $base->update([
                'nombre_es' => trim($datos['nombre_es']),
                'nombre_en' => trim($datos['nombre_en']),
                'categoria' => $datos['categoria'] ?? null,
            ]);

            $base->presentaciones()->update([
                'nombre_es' => $base->nombre_es,
                'nombre_en' => $base->nombre_en,
            ]);

            return $base;
        });
    }

    /**
     * Archivar el producto archiva todas sus presentaciones; reactivarlo las
     * reactiva. Nada se borra: precios e histórico quedan intactos.
     */
    public function alternarActivo(ExportacionProductoBase $base): ExportacionProductoBase
    {
        return DB::transaction(function () use ($base) {
            $base->update(['activo' => ! $base->activo]);
            $base->presentaciones()->update(['activo' => $base->activo]);

            return $base;
        });
    }

    /** @return array{onzas:float, neto_lb:float, bruto_kg:float, bruto_lb:float} */
    public static function pesos(float $gramos, float $netoKg, ?float $brutoKg = null): array
    {
        $bruto = $brutoKg ?? $netoKg + 1;

        return [
            'onzas' => round($gramos * self::GRAMOS_A_ONZAS, 2),
            'neto_lb' => round($netoKg * self::KILOS_A_LIBRAS, 2),
            'bruto_kg' => round($bruto, 2),
            'bruto_lb' => round($bruto * self::KILOS_A_LIBRAS, 2),
        ];
    }

    private function completar(array $datos): array
    {
        $datos = array_intersect_key($datos, array_flip(self::CAMPOS_PRESENTACION));
        $bruto = isset($datos['peso_bruto_caja_kg']) && $datos['peso_bruto_caja_kg'] !== ''
            ? (float) $datos['peso_bruto_caja_kg']
            : null;
        $pesos = self::pesos((float) $datos['gramos_por_unidad'], (float) $datos['peso_neto_caja_kg'], $bruto);

        $datos['unidad'] = isset($datos['unidad']) ? trim(preg_replace('/\s+/', ' ', $datos['unidad'])) : null;
        $datos['precio_caja'] = isset($datos['precio_caja']) && $datos['precio_caja'] !== '' ? $datos['precio_caja'] : null;

        return [
            ...$datos,
            'onzas_por_unidad' => $pesos['onzas'],
            'peso_bruto_caja_kg' => $pesos['bruto_kg'],
            'peso_neto_caja_lb' => $pesos['neto_lb'],
            'peso_bruto_caja_lb' => $pesos['bruto_lb'],
        ];
    }

    private function exigirUnica(ExportacionProductoBase $base, array $datos, ?int $ignorar = null): void
    {
        $repetida = $base->presentaciones()
            ->where('unidades_por_caja', (int) $datos['unidades_por_caja'])
            ->where('gramos_por_unidad', round((float) $datos['gramos_por_unidad'], 2))
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))
            ->first();

        if ($repetida !== null) {
            throw ValidationException::withMessages([
                'unidades_por_caja' => "«{$base->nombre_es}» ya tiene esa presentación ({$repetida->etiquetaEmpaque()}, "
                    .(float) $repetida->gramos_por_unidad.' g)'.($repetida->activo ? '.' : ', archivada: reactivala en vez de crearla de nuevo.'),
            ]);
        }
    }
}
