<?php

namespace App\Services\Gastos;

use Illuminate\Validation\Rule;

/**
 * A dónde se vuelve después de registrar algo, resuelto desde una LISTA CERRADA.
 *
 * ───────────────────────── Por qué no se manda la URL ─────────────────────────
 *
 * La forma cómoda de hacer esto es que el formulario lleve un campo con la dirección
 * de vuelta y el servidor redirija ahí. Es también la forma de abrir un redirect
 * abierto: basta con que alguien fabrique un enlace a nuestro propio formulario con
 * una dirección ajena dentro para que el sistema, después de una operación legítima y
 * ya autenticada, deposite a la persona en un sitio que no es el nuestro. La página
 * de destino puede imitar la pantalla de acceso, y quien llegó confió en el camino.
 *
 * Así que acá NO ENTRA NINGUNA URL. Entra una PALABRA de un conjunto fijo —«panel»,
 * «cuenta»— y esta clase la traduce a una ruta nuestra. Una palabra que no esté en la
 * lista no produce un error ni una redirección rara: cae en el panel, que es el
 * destino por defecto y siempre es correcto. El peor caso de un valor manipulado es
 * volver a la pantalla principal.
 *
 * Los datos que acompañan al destino —proveedor y moneda— no salen del formulario
 * para esto: los pone quien llama, tomándolos de la operación que acaba de registrar.
 */
final class DestinoVuelta
{
    /**
     * Los únicos orígenes que existen. Si alguna pantalla nueva necesita volver a un
     * sitio distinto, se agrega acá y en {@see deAbono()}: no hay forma de nombrar un
     * destino que no esté en esta lista.
     */
    public const ORIGENES = ['panel', 'cuenta'];

    /** La regla de validación para el campo `origen` de un formulario. */
    public static function regla(): array
    {
        return ['nullable', 'string', Rule::in(self::ORIGENES)];
    }

    /**
     * A dónde vuelve un abono o una compra a cuenta.
     *
     * `cuenta` devuelve a la libreta del proveedor, que es donde se estaba cuando se
     * abrió el formulario: quien abona tres compras seguidas del mismo proveedor no
     * quiere salir y volver a entrar cada vez.
     *
     * Cualquier otra cosa —«panel», vacío, o un valor que alguien haya manipulado—
     * termina en el panel.
     */
    public static function deAbono(?string $origen, string $proveedor, string $moneda): string
    {
        return $origen === 'cuenta'
            ? route('gastos.cuentas.show', ['proveedor' => $proveedor, 'moneda' => $moneda])
            : route('gastos.panel');
    }
}
