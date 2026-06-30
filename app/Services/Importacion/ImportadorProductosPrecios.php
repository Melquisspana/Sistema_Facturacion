<?php

namespace App\Services\Importacion;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\UnidadMedida;
use Illuminate\Support\Carbon;

/**
 * Importa productos y su PRECIO ESPECIAL para un cliente desde CSV.
 *
 * - Crea/actualiza el producto por código interno (producto.codigo).
 * - Guarda el precio del archivo como precio especial del cliente en
 *   producto_precios_cliente (sin duplicar el activo).
 * - Solo importa filas con precio numérico; las demás se ignoran.
 * - El precio general del producto solo se fija al CREARLO (no se pisa si ya existe).
 * Idempotente.
 */
class ImportadorProductosPrecios
{
    private const ALIAS = [
        'codigo_interno' => 'codigo',
        'codigo' => 'codigo',
        'codigo_de_barra' => 'codigo_barra',
        'codigo_barra' => 'codigo_barra',
        'descripcion_de_producto' => 'descripcion',
        'descripcion' => 'descripcion',
        'producto' => 'descripcion',
        'factor_de_empaque' => 'empaque',
        'empaque' => 'empaque',
        'fecha_de_inicio' => 'fecha_inicio',
        'fecha_inicio' => 'fecha_inicio',
        'costo_nuevo_unidad_libra_precio' => 'precio',
        'precio' => 'precio',
        'precio_calleja' => 'precio',
        'costo' => 'precio',
    ];

    public function __construct(private readonly LectorCsv $lector) {}

    public function importar(Cliente $cliente, string $ruta): ResultadoImportacion
    {
        $resultado = new ResultadoImportacion();
        $filas = $this->lector->leer($ruta, self::ALIAS);

        $unidadId = UnidadMedida::where('codigo', '59')->value('id')
            ?? UnidadMedida::whereNotNull('codigo')->value('id')
            ?? UnidadMedida::query()->value('id');

        foreach ($filas as $registro) {
            $resultado->leidas++;
            $numero = $resultado->leidas;

            $codigo = $registro['codigo'] ?? '';
            $descripcion = $registro['descripcion'] ?? '';
            $barra = $registro['codigo_barra'] ?? null;
            $precio = $this->parsearPrecio($registro['precio'] ?? '');
            $etiqueta = $descripcion !== '' ? $descripcion : ($codigo !== '' ? $codigo : '(sin nombre)');

            if ($precio === null) {
                $resultado->ignorada($numero, $etiqueta, 'Precio vacío o no numérico.');

                continue;
            }
            if ($codigo === '' && $barra === null) {
                $resultado->ignorada($numero, $etiqueta, 'Sin código interno ni código de barra.');

                continue;
            }

            // Producto por código interno (o por barra si no hay código).
            $producto = $codigo !== ''
                ? Producto::firstOrNew(['codigo' => $codigo])
                : (Producto::where('codigo_barra', $barra)->first() ?? new Producto());

            $creado = ! $producto->exists;

            $producto->codigo = $codigo !== '' ? $codigo : ($producto->codigo ?? $barra);
            $producto->nombre = $descripcion !== '' ? $descripcion : ($producto->nombre ?? $codigo);
            $producto->codigo_barra = $barra ?: $producto->codigo_barra;
            $producto->tipo_producto = TipoProducto::Bien->value;
            $producto->tipo_impuesto = TipoImpuesto::Gravado->value;
            $producto->unidad_medida_id = $producto->unidad_medida_id ?? $unidadId;
            $producto->maneja_inventario = false;
            $producto->activo = true;
            if (! empty($registro['empaque'])) {
                $producto->observaciones = 'Empaque: '.$registro['empaque'];
            }
            if ($creado) {
                $producto->precio_unitario = $precio; // general solo al crear
            }
            $producto->save();

            // Precio especial del cliente (sin duplicar el activo). Conserva el
            // precio anterior para mostrarlo en el reporte.
            $precioRegistro = ProductoPrecioCliente::firstOrNew([
                'producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'cliente_sucursal_id' => null,
            ]);
            $precioAnterior = $precioRegistro->exists ? (float) $precioRegistro->precio : null;

            $precioRegistro->fill([
                'precio' => $precio,
                'activo' => true,
                'fecha_inicio' => $this->parsearFecha($registro['fecha_inicio'] ?? null),
            ])->save();

            $detallePrecio = $precioAnterior !== null && abs($precioAnterior - $precio) > 0.00001
                ? sprintf('Precio %s: $%.4f → $%.4f', $cliente->nombre, $precioAnterior, $precio)
                : sprintf('Precio %s: $%.4f', $cliente->nombre, $precio);

            $creado
                ? $resultado->creada($numero, $producto->nombre, $detallePrecio)
                : $resultado->actualizada($numero, $producto->nombre, $detallePrecio);
        }

        return $resultado;
    }

    private function parsearPrecio(string $valor): ?float
    {
        $valor = str_replace([',', '$', ' '], ['.', '', ''], trim($valor));
        if ($valor === '' || ! is_numeric($valor) || (float) $valor <= 0) {
            return null;
        }

        return round((float) $valor, 4);
    }

    private function parsearFecha(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, $valor)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
