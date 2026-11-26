<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lista de precios de exportación de un cliente: qué productos puede comprar y a
 * qué precio por caja.
 *
 * Se cambia en DOS sitios, y ambos pasan por acá: el botón «Clientes» de cada
 * presentación en Productos › «De exportación» ({@see sincronizarClientes()}) y la
 * lista de empaque al finalizarse ({@see actualizarDesdeLista()}). Las rutas de la
 * ficha del cliente que también la escribían se retiraron (responden 409).
 *
 * REGLA DE NEGOCIO que este servicio protege: si entre dos clientes solo cambia
 * el PRECIO, es el mismo producto del catálogo con precio propio. Si cambia el
 * empaque, las unidades por caja o los pesos, es otra presentación y va como
 * producto aparte. Por eso acá solo se toca el precio.
 */
class ListaPreciosExportacion
{
    /**
     * El precio vigente de cada cliente sale de su última lista de empaque: al
     * finalizarla, cada item deja su precio como el del cliente para esa
     * presentación (crea la asignación si no existía y la reactiva si estaba
     * apagada).
     *
     * No cuenta un precio fijado por una lista o a mano con fecha POSTERIOR a esta lista:
     *     finalizar tarde una lista vieja no pisa un precio más nuevo.
     *
     * @return list<array{producto: string, antes: ?float, ahora: float}> los precios que cambiaron
     */
    public function actualizarDesdeLista(Exportacion $lista): array
    {
        if ($lista->exportacion_cliente_id === null) {
            return [];
        }

        $fecha = $lista->fecha->toDateString();
        $asignaciones = ExportacionClienteProducto::where('exportacion_cliente_id', $lista->exportacion_cliente_id)
            ->get()
            ->keyBy('exportacion_producto_id');
        $cambios = [];

        $items = $lista->items()
            ->whereNotNull('exportacion_producto_id')
            ->where('precio_caja', '>', 0)
            ->get();

        foreach ($items as $item) {
            $precio = round((float) $item->precio_caja, 2);
            $asignacion = $asignaciones->get($item->exportacion_producto_id);

            if ($asignacion?->precio_fijado_en !== null && $asignacion->precio_fijado_en->toDateString() > $fecha) {
                continue;
            }

            $antes = $asignacion !== null ? round((float) $asignacion->precio_caja, 2) : null;
            $datos = [
                'precio_caja' => $precio,
                'precio_fijado_en' => $fecha,
                'precio_desde_exportacion_id' => $lista->id,
                'activo' => true,
            ];

            if ($asignacion === null) {
                $asignaciones->put($item->exportacion_producto_id, ExportacionClienteProducto::create($datos + [
                    'exportacion_cliente_id' => $lista->exportacion_cliente_id,
                    'exportacion_producto_id' => $item->exportacion_producto_id,
                ]));
            } else {
                $asignacion->update($datos);
            }

            if ($antes !== $precio) {
                $cambios[] = ['producto' => trim($item->nombre_es.' '.$item->unidades_por_caja.' u'), 'antes' => $antes, 'ahora' => $precio];
            }
        }

        return $cambios;
    }

    /** Clientes de exportación activos, en el orden en que se muestran al asignar. */
    public function clientesActivos(): Collection
    {
        return ExportacionCliente::where('activo', true)
            ->with('cliente:id,nombre')
            ->get()
            ->sortBy(fn (ExportacionCliente $c) => $c->nombreLegal())
            ->values();
    }

    /**
     * Asigna, desde el PRODUCTO, a qué clientes se le vende esta presentación y a
     * qué precio. `$clientes` es [cliente_id => ['activo' => bool, 'precio' => ?]].
     *
     *   · Marcado sin precio toma el precio base de la presentación; sin ninguno, error.
     *   · Desmarcado apaga la asignación (no la borra): el precio queda guardado por
     *     si se le vuelve a vender.
     *   · Un precio cambiado a mano queda fijado hoy, así que una lista más vieja
     *     que se finalice después no lo pisa.
     *
     * @param  array<int|string, array{activo?: mixed, precio?: mixed}>  $clientes
     * @return int cuántas asignaciones cambiaron
     *
     * @throws ValidationException
     */
    public function sincronizarClientes(ExportacionProducto $producto, array $clientes): int
    {
        $validos = ExportacionCliente::where('activo', true)->pluck('nombre', 'id');
        $existentes = ExportacionClienteProducto::where('exportacion_producto_id', $producto->id)->get()->keyBy('exportacion_cliente_id');
        $cambios = 0;

        foreach ($clientes as $clienteId => $datos) {
            $clienteId = (int) $clienteId;
            if (! $validos->has($clienteId)) {
                continue;
            }

            $asignacion = $existentes->get($clienteId);
            $marcado = ! empty($datos['activo']);

            if (! $marcado) {
                if ($asignacion?->activo) {
                    $asignacion->update(['activo' => false]);
                    $cambios++;
                }

                continue;
            }

            $precio = isset($datos['precio']) && $datos['precio'] !== '' ? round((float) $datos['precio'], 2)
                : ($producto->precio_caja !== null ? round((float) $producto->precio_caja, 2) : null);

            if ($precio === null || $precio <= 0) {
                throw ValidationException::withMessages([
                    "clientes.{$clienteId}.precio" => "Falta el precio para {$validos[$clienteId]}.",
                ]);
            }

            if ($asignacion === null) {
                ExportacionClienteProducto::create([
                    'exportacion_cliente_id' => $clienteId,
                    'exportacion_producto_id' => $producto->id,
                    'precio_caja' => $precio,
                    'precio_fijado_en' => now()->toDateString(),
                    'activo' => true,
                ]);
                $cambios++;

                continue;
            }

            $cambioPrecio = round((float) $asignacion->precio_caja, 2) !== $precio;
            if ($cambioPrecio || ! $asignacion->activo) {
                $asignacion->update(['activo' => true] + ($cambioPrecio ? [
                    'precio_caja' => $precio,
                    'precio_fijado_en' => now()->toDateString(),
                    'precio_desde_exportacion_id' => null,
                ] : []));
                $cambios++;
            }
        }

        return $cambios;
    }
}
