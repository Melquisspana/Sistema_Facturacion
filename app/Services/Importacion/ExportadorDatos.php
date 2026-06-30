<?php

namespace App\Services\Importacion;

use App\Models\Cliente;
use App\Models\ProductoPrecioCliente;

/**
 * Exporta a CSV (columnas limpias) las salas y los precios de un cliente.
 */
class ExportadorDatos
{
    /** CSV de salas/sucursales del cliente. */
    public function salasCsv(Cliente $cliente): string
    {
        $cliente->loadMissing(['sucursales.departamento', 'sucursales.municipio']);

        $filas = [['cliente', 'sucursal', 'codigo', 'direccion', 'departamento', 'municipio', 'activo', 'requiere_orden_compra']];

        foreach ($cliente->sucursales as $s) {
            $filas[] = [
                $cliente->nombre,
                $s->nombre,
                $s->codigo ?? '',
                $s->direccion ?? '',
                $s->departamento?->nombre ?? '',
                $s->municipio?->nombre ?? '',
                $s->activo ? 'Sí' : 'No',
                is_null($s->requiere_orden_compra) ? 'Hereda' : ($s->requiere_orden_compra ? 'Sí' : 'No'),
            ];
        }

        return $this->aCsv($filas);
    }

    /** CSV de productos/precios especiales del cliente. */
    public function preciosCsv(Cliente $cliente): string
    {
        $precios = ProductoPrecioCliente::with('producto')
            ->where('cliente_id', $cliente->id)
            ->whereNull('cliente_sucursal_id')
            ->get();

        $filas = [['codigo_interno', 'codigo_barra', 'producto', 'precio_general', 'precio_calleja', 'fecha_inicio', 'activo']];

        foreach ($precios as $p) {
            $filas[] = [
                $p->producto?->codigo ?? '',
                $p->producto?->codigo_barra ?? '',
                $p->producto?->nombre ?? '',
                $p->producto ? number_format((float) $p->producto->precio_unitario, 4, '.', '') : '',
                number_format((float) $p->precio, 4, '.', ''),
                $p->fecha_inicio?->toDateString() ?? '',
                $p->activo ? 'Sí' : 'No',
            ];
        }

        return $this->aCsv($filas);
    }

    /** Plantilla CSV de salas con encabezados y filas de ejemplo. */
    public function plantillaSalasCsv(): string
    {
        return $this->aCsv([
            ['No.', 'Nombre comercial', 'Código de sala', 'Dirección', 'Distrito', 'Municipio', 'Departamento', 'Requiere orden compra'],
            ['1', 'Súper Selectos Escalón', '0230', 'Col. Escalón, Calle 1', 'Centro', 'San Salvador', 'San Salvador', 'No'],
            ['2', 'Súper Selectos Soyapango', '0231', 'Av. Roosevelt', 'Norte', 'Soyapango', 'San Salvador', ''],
        ]);
    }

    /** Plantilla CSV de productos/precios con encabezados y filas de ejemplo. */
    public function plantillaPreciosCsv(): string
    {
        return $this->aCsv([
            ['Código interno', 'Código de barra', 'Descripción de producto', 'Factor de empaque', 'Fecha de inicio', 'Costo nuevo / unidad libra / precio'],
            ['79873', '7412201700031', 'CANILLITAS', 'BOLSA', '2026-01-01', '1.0500'],
            ['106753', '7412201700079', 'COCO RALLADO', 'BOLSA', '', '1.0000'],
        ]);
    }

    /** @param array<int, array<int, string>> $filas */
    private function aCsv(array $filas): string
    {
        $handle = fopen('php://temp', 'r+');
        // BOM para que Excel reconozca UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($filas as $fila) {
            fputcsv($handle, $fila);
        }
        rewind($handle);
        $contenido = (string) stream_get_contents($handle);
        fclose($handle);

        return $contenido;
    }
}
