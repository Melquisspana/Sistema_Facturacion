<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\TipoCliente;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Services\Exportaciones\ListaPreciosExportacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Perfil de EXPORTACIÓN de un cliente, dentro de su propia ficha.
 *
 * NO ES OTRO DIRECTORIO DE CLIENTES. El cliente sigue siendo uno solo, en
 * `clientes`; lo que se habilita acá es un perfil adicional (`exportacion_clientes`)
 * que cuelga de él y guarda ÚNICAMENTE lo que el directorio no tiene:
 *
 *   · el número de registro FDA del IMPORTADOR;
 *   · un contacto operativo del embarque;
 *   · una dirección de entrega o bodega, cuando difiere de la fiscal;
 *   · la lista de precios por caja.
 *
 * Nombre, dirección fiscal, documento, país y correo NO se piden ni se copian: se
 * leen del cliente. Antes se tecleaban otra vez en un formulario aparte y acababan
 * divergiendo — un cliente con dos nombres según por qué pantalla se mirara.
 *
 * HABILITAR / DESHABILITAR no crea ni borra clientes: enciende y apaga el flag
 * `activo` del perfil. Deshabilitar conserva precios e histórico, así que volver a
 * habilitarlo no obliga a recargar nada.
 */
class ClienteExportacionController extends Controller
{
    public function __construct(private readonly ListaPreciosExportacion $precios) {}

    /**
     * Habilita al cliente para exportación creando su perfil, o reactivando el que
     * ya tenía. Nunca crea un segundo perfil para el mismo cliente.
     */
    public function habilitar(Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();
        $this->exigirClienteDeExportacion($cliente);

        $perfil = $cliente->exportacionClientes()->orderBy('id')->first();

        if ($perfil !== null) {
            $perfil->update(['activo' => true]);

            return $this->volver($cliente, 'Cliente habilitado de nuevo para exportación. Sus precios y su histórico seguían guardados.');
        }

        ExportacionCliente::create([
            'cliente_id' => $cliente->id,
            // El nombre operativo se rellena desde el cliente y no se pide: la columna
            // es NOT NULL por herencia del esquema anterior, pero la fuente de verdad
            // del nombre legal es siempre el directorio (ver ExportacionCliente::nombreLegal).
            'nombre' => $cliente->nombre,
            'activo' => true,
        ]);

        return $this->volver($cliente, 'Cliente habilitado para exportación. Ya podés asignarle productos y precios.');
    }

    /** Apaga el perfil sin borrar nada. */
    public function deshabilitar(Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();

        $perfil = $this->perfil($cliente);
        $perfil->update(['activo' => false]);

        return $this->volver($cliente, 'Cliente deshabilitado para exportación. No se borró ningún precio: volver a habilitarlo lo deja como estaba.');
    }

    /**
     * Guarda los campos internacionales que el directorio NO tiene. Deliberadamente
     * cortos: todo lo demás vive en la ficha del cliente y no se duplica acá.
     */
    public function actualizar(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();

        $perfil = $this->perfil($cliente);

        $datos = $request->validate([
            'fda_reg_number' => ['nullable', 'string', 'max:50'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [], [
            'fda_reg_number' => 'FDA del importador',
            'contacto' => 'contacto del embarque',
            'direccion' => 'dirección de entrega',
        ]);

        // Guardar el campo ya es la revisión: si alguien escribe (o borra) el FDA a
        // conciencia, la marca heredada de la migración deja de tener sentido.
        $datos['fda_requiere_revision'] = false;
        // El nombre operativo se mantiene alineado con el del directorio, que es la
        // fuente de verdad; nunca se edita por separado.
        $datos['nombre'] = $cliente->nombre;

        $perfil->update($datos);

        return $this->volver($cliente, 'Perfil de exportación actualizado.');
    }

    // ------------------------------------------------------------ lista de precios

    public function agregarProducto(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();
        $perfil = $this->perfil($cliente);

        $datos = $request->validate([
            'exportacion_producto_id' => ['required', 'integer', Rule::exists('exportacion_productos', 'id')],
            'precio_caja' => ['required', 'numeric', 'min:0'],
        ], [], ['exportacion_producto_id' => 'producto', 'precio_caja' => 'precio por caja']);

        $this->precios->asignar(
            $perfil,
            (int) $datos['exportacion_producto_id'],
            (float) $datos['precio_caja'],
            $request->boolean('confirmar_cero'),
        );

        return $this->volver($cliente, 'Producto agregado a la lista de precios del cliente.');
    }

    public function actualizarProducto(Request $request, Cliente $cliente, ExportacionClienteProducto $asignacion): RedirectResponse
    {
        $this->autorizarGestion();
        $perfil = $this->perfil($cliente);
        $this->exigirPertenencia($perfil, $asignacion);

        if ($request->has('toggle_activo')) {
            $this->precios->alternarActivo($asignacion);

            return $this->volver($cliente, $asignacion->refresh()->activo
                ? 'Producto habilitado para este cliente.'
                : 'Producto deshabilitado para este cliente.');
        }

        $datos = $request->validate([
            'precio_caja' => ['required', 'numeric', 'min:0'],
        ], [], ['precio_caja' => 'precio por caja']);

        $this->precios->actualizarPrecio($asignacion, (float) $datos['precio_caja'], $request->boolean('confirmar_cero'));

        return $this->volver($cliente, 'Precio actualizado. Las listas de empaque ya creadas no cambian: guardan su propio snapshot.');
    }

    public function quitarProducto(Cliente $cliente, ExportacionClienteProducto $asignacion): RedirectResponse
    {
        $this->autorizarGestion();
        $perfil = $this->perfil($cliente);
        $this->exigirPertenencia($perfil, $asignacion);

        $this->precios->quitar($asignacion);

        return $this->volver($cliente, 'Producto quitado de la lista de precios del cliente.');
    }

    public function asignarCatalogo(Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();
        $perfil = $this->perfil($cliente);

        $resumen = $this->precios->asignarCatalogoCompleto($perfil);

        $mensaje = "Catálogo asignado: {$resumen['agregados']} producto(s) agregados con su precio base.";

        if ($resumen['sin_precio_base'] > 0) {
            $mensaje .= " {$resumen['sin_precio_base']} quedaron fuera por no tener precio base (o tenerlo en \$0): asignalos a mano con su precio.";
        }

        return $this->volver($cliente, $mensaje);
    }

    public function copiarPrecios(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarGestion();
        $perfil = $this->perfil($cliente);

        $datos = $request->validate([
            'origen_id' => ['required', 'integer', Rule::exists('exportacion_clientes', 'id'), Rule::notIn([$perfil->id])],
            'modo' => ['required', Rule::in(['conservar', 'sobrescribir'])],
        ], [
            'origen_id.not_in' => 'El cliente origen debe ser distinto al destino.',
        ], ['origen_id' => 'cliente origen', 'modo' => 'modo de copia']);

        $origen = ExportacionCliente::findOrFail($datos['origen_id']);
        $resumen = $this->precios->copiarDesde($perfil, $origen, $datos['modo']);

        $mensaje = "Precios copiados desde «{$origen->nombreLegal()}»: {$resumen['copiados']} nuevos";
        $mensaje .= $datos['modo'] === 'sobrescribir'
            ? ", {$resumen['sobrescritos']} sobrescritos."
            : ", {$resumen['omitidos']} ya existían y se conservaron.";
        $mensaje .= ' Las listas de empaque ya creadas no cambian.';

        return $this->volver($cliente, $mensaje);
    }

    // ---------------------------------------------------------------------- interno

    /**
     * Misma autorización que editar el cliente NO: la gestión del perfil de
     * exportación sigue detrás de `exportaciones.gestionar`, el permiso que ya
     * protegía estas acciones en su pantalla anterior. Mover una pantalla de sitio
     * no debe cambiar quién puede usarla.
     */
    private function autorizarGestion(): void
    {
        abort_unless(request()->user()?->can('exportaciones.gestionar'), 403);
    }

    private function exigirClienteDeExportacion(Cliente $cliente): void
    {
        if ($cliente->tipo_cliente !== TipoCliente::Exportacion) {
            throw ValidationException::withMessages([
                'tipo_cliente' => 'Solo un cliente de tipo exportación puede habilitarse para exportación. Cambiá su tipo en la ficha del cliente.',
            ]);
        }
    }

    private function perfil(Cliente $cliente): ExportacionCliente
    {
        $perfil = $cliente->exportacionClientes()->orderBy('id')->first();

        abort_if($perfil === null, 404, 'Este cliente no está habilitado para exportación.');

        return $perfil;
    }

    /** El precio pertenece a ESTE cliente, o 404. Nunca se edita el precio de otro. */
    private function exigirPertenencia(ExportacionCliente $perfil, ExportacionClienteProducto $asignacion): void
    {
        abort_unless($asignacion->exportacion_cliente_id === $perfil->id, 404);
    }

    private function volver(Cliente $cliente, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', $mensaje);
    }
}
