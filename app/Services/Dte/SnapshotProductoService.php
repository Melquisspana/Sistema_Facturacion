<?php

namespace App\Services\Dte;

use App\Models\Producto;

/**
 * Copia los datos del producto a una línea de DTE en forma de SNAPSHOT, de modo
 * que el documento NO cambie si el producto se modifica o se elimina después.
 *
 * El producto_id queda como referencia blanda (nullOnDelete); todos los datos
 * presentables/fiscales se congelan aquí.
 */
class SnapshotProductoService
{
    /**
     * @return array<string, mixed> Campos de snapshot para crear/poblar una DteLinea.
     */
    public function paraLinea(Producto $producto): array
    {
        $unidad = $producto->unidadMedida;

        return [
            // Referencia blanda al producto vivo.
            'producto_id' => $producto->id,

            // Snapshot inmutable.
            'codigo' => $producto->codigo,
            'codigo_barra' => $producto->codigo_barra,
            'descripcion' => $producto->nombre,
            'unidad_medida_id' => $producto->unidad_medida_id,
            'unidad_codigo' => $unidad?->codigo,
            'unidad_nombre' => $unidad?->nombre,
            'tipo_producto' => $producto->tipo_producto?->value,
            'tipo_impuesto' => $producto->tipo_impuesto->value,
            'precio_unitario' => $producto->precio_unitario,
        ];
    }
}
