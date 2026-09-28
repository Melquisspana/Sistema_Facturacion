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
 * Existe como servicio —y no como método de un controlador— porque ahora hay DOS
 * pantallas que la manejan: la ficha del cliente (el sitio nuevo) y la pantalla
 * de Exportaciones (que sigue en pie mientras no se compruebe en producción que
 * nadie la usa). Duplicar estas reglas entre ambas es exactamente la clase de
 * duplicación que produce que un precio en cero se acepte en una pantalla y se
 * rechace en la otra.
 *
 * REGLA DE NEGOCIO que este servicio protege: si entre dos clientes solo cambia
 * el PRECIO, es el mismo producto del catálogo con precio propio. Si cambia el
 * empaque, las unidades por caja o los pesos, es otra presentación y va como
 * producto aparte. Por eso acá solo se toca el precio.
 */
class ListaPreciosExportacion
{
    /**
     * Asigna un producto del catálogo al cliente con su precio.
     *
     * @throws ValidationException si ya está asignado, o si el precio es 0 sin confirmar
     */
    public function asignar(
        ExportacionCliente $cliente,
        int $productoId,
        float $precio,
        bool $confirmarCero = false,
    ): ExportacionClienteProducto {
        $producto = ExportacionProducto::find($productoId);

        if ($producto === null) {
            throw ValidationException::withMessages([
                'exportacion_producto_id' => 'Ese producto de exportación no existe.',
            ]);
        }

        $duplicado = $cliente->productos()
            ->where('exportacion_producto_id', $producto->id)
            ->exists();

        if ($duplicado) {
            throw ValidationException::withMessages([
                'exportacion_producto_id' => 'Ese producto ya está asignado a este cliente.',
            ]);
        }

        $this->validarPrecio($precio, $confirmarCero);

        return $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => $precio,
            'precio_fijado_en' => now()->toDateString(),
            'activo' => true,
        ]);
    }

    /**
     * Cambia el precio de una asignación existente. NO toca ninguna lista de empaque
     * ya creada: sus items guardan el precio como snapshot precisamente para que
     * cambiar la lista de precios no reescriba documentos pasados.
     *
     * @throws ValidationException si el precio es 0 sin confirmar
     */
    public function actualizarPrecio(
        ExportacionClienteProducto $asignacion,
        float $precio,
        bool $confirmarCero = false,
    ): ExportacionClienteProducto {
        $this->validarPrecio($precio, $confirmarCero);

        // Un precio puesto a mano hoy manda sobre una lista más vieja que se
        // finalice después (ver actualizarDesdeLista()).
        $asignacion->update([
            'precio_caja' => $precio,
            'precio_fijado_en' => now()->toDateString(),
            'precio_desde_exportacion_id' => null,
        ]);

        return $asignacion->refresh();
    }

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

    /** Habilita o deshabilita el producto para ese cliente sin perder su precio. */
    public function alternarActivo(ExportacionClienteProducto $asignacion): ExportacionClienteProducto
    {
        $asignacion->update(['activo' => ! $asignacion->activo]);

        return $asignacion->refresh();
    }

    public function quitar(ExportacionClienteProducto $asignacion): void
    {
        $asignacion->delete();
    }

    /**
     * Asigna de golpe todo el catálogo activo que falte, usando el precio base.
     *
     * Los productos sin precio base (o con base $0) quedan FUERA a propósito: crear
     * precios en cero en masa es la forma más rápida de facturar un embarque entero
     * a cero sin que nadie lo note. Se informan para asignarlos a mano.
     *
     * @return array{agregados: int, sin_precio_base: int}
     */
    public function asignarCatalogoCompleto(ExportacionCliente $cliente): array
    {
        $asignados = $cliente->productos()->pluck('exportacion_producto_id');

        $faltantes = ExportacionProducto::where('activo', true)
            ->whereNotIn('id', $asignados)
            ->get(['id', 'precio_caja']);

        $agregados = 0;
        $sinPrecioBase = 0;

        foreach ($faltantes as $producto) {
            if ($producto->precio_caja === null || (float) $producto->precio_caja <= 0) {
                $sinPrecioBase++;

                continue;
            }

            $cliente->productos()->create([
                'exportacion_producto_id' => $producto->id,
                'precio_caja' => $producto->precio_caja,
                'activo' => true,
            ]);
            $agregados++;
        }

        return ['agregados' => $agregados, 'sin_precio_base' => $sinPrecioBase];
    }

    /**
     * Copia los productos y precios ACTIVOS de otro cliente.
     *
     * @param  'conservar'|'sobrescribir'  $modo  qué hacer con los que ya existen
     * @return array{copiados: int, sobrescritos: int, omitidos: int}
     */
    public function copiarDesde(ExportacionCliente $destino, ExportacionCliente $origen, string $modo): array
    {
        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages([
                'origen_id' => 'El cliente origen debe ser distinto al destino.',
            ]);
        }

        $existentes = $destino->productos()->get()->keyBy('exportacion_producto_id');

        $copiados = $sobrescritos = $omitidos = 0;

        foreach ($origen->productos()->where('activo', true)->get() as $asignacion) {
            $existente = $existentes->get($asignacion->exportacion_producto_id);

            if ($existente === null) {
                $destino->productos()->create([
                    'exportacion_producto_id' => $asignacion->exportacion_producto_id,
                    'precio_caja' => $asignacion->precio_caja,
                    'activo' => true,
                ]);
                $copiados++;

                continue;
            }

            if ($modo === 'sobrescribir') {
                $existente->update(['precio_caja' => $asignacion->precio_caja]);
                $sobrescritos++;

                continue;
            }

            $omitidos++;
        }

        return ['copiados' => $copiados, 'sobrescritos' => $sobrescritos, 'omitidos' => $omitidos];
    }

    /**
     * Un precio de $0.00 solo pasa con confirmación explícita. Los negativos ya los
     * bloquea la regla `min:0` de cada formulario; acá se atrapa el dedazo que deja
     * un producto regalado sin que nadie lo advierta.
     *
     * @throws ValidationException
     */
    private function validarPrecio(float $precio, bool $confirmarCero): void
    {
        if ($precio < 0) {
            throw ValidationException::withMessages([
                'precio_caja' => 'El precio por caja no puede ser negativo.',
            ]);
        }

        if ($precio == 0.0 && ! $confirmarCero) {
            throw ValidationException::withMessages([
                'precio_caja' => 'El precio quedó en $0.00: confirmá que es intencional para guardarlo.',
            ]);
        }
    }
}
