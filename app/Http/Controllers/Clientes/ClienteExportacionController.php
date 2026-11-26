<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\TipoCliente;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ExportacionCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Perfil de EXPORTACIÓN de un cliente, dentro de su propia ficha.
 *
 * NO ES OTRO DIRECTORIO DE CLIENTES. El cliente sigue siendo uno solo, en
 * `clientes`; lo que se habilita acá es un perfil adicional (`exportacion_clientes`)
 * que cuelga de él y guarda ÚNICAMENTE lo que el directorio no tiene: la lista de
 * precios por caja. (Ya no el FDA —el de la lista es uno solo, el de la empresa—
 * ni el contacto del embarque o la dirección de entrega: eran los mismos datos de la
 * ficha del cliente, tecleados otra vez. Sus columnas quedan en la tabla, sin uso.)
 *
 * Nombre, dirección, documento, país, contacto y correo NO se piden ni se copian: se
 * leen del cliente. Antes se tecleaban otra vez en un formulario aparte y acababan
 * divergiendo — un cliente con dos nombres según por qué pantalla se mirara.
 *
 * HABILITAR / DESHABILITAR no crea ni borra clientes: enciende y apaga el flag
 * `activo` del perfil. Deshabilitar conserva precios e histórico, así que volver a
 * habilitarlo no obliga a recargar nada.
 *
 * La lista de precios se ve en la ficha, pero se asigna en Productos › «De
 * exportación» (botón «Clientes») y al finalizar cada lista de empaque. Sus rutas
 * de escritura de esta ficha responden 409 (routes/web.php).
 */
class ClienteExportacionController extends Controller
{
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

    private function volver(Cliente $cliente, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', $mensaje);
    }
}
