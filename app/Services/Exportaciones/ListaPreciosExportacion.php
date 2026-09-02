<?php

namespace App\Services\Exportaciones;

use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
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

        $asignacion->update(['precio_caja' => $precio]);

        return $asignacion->refresh();
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
