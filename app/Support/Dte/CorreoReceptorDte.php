<?php

namespace App\Support\Dte;

use App\Models\Dte;

/**
 * FUENTE ÚNICA del correo EFECTIVO del receptor de un DTE.
 *
 * La regla es «la sala manda sobre el cliente»: una cadena tiene un correo general en el
 * cliente y, por sala, la dirección que de verdad recibe los documentos de esa tienda. Si
 * la sala declara correo, ese es el que vale; si no, se cae al del cliente.
 *
 * Esa misma regla ya decidía a quién se le envía el documento ({@see \App\Http\Controllers\Facturacion\DteController})
 * y qué destinatario se propone en la ficha, pero estaba escrita por separado en cada
 * lugar. Al mostrarla además en el PDF hacían falta tres copias de la misma condición, y
 * tres copias es como se llega a que el PDF diga un correo y el envío use otro. Acá vive
 * una sola vez.
 *
 * Solo lectura: no toca el DTE, no recalcula nada fiscal, no envía.
 */
final class CorreoReceptorDte
{
    /** Texto que se imprime cuando no hay ningún correo configurado. */
    public const SIN_CORREO = 'Sin correo configurado';

    /** Correo efectivo, o null si ni la sala ni el cliente tienen uno. */
    public static function resolver(Dte $dte): ?string
    {
        $dte->loadMissing(['cliente', 'clienteSucursal']);

        $correo = trim((string) ($dte->clienteSucursal?->correo ?? ''));

        if ($correo === '') {
            $correo = trim((string) ($dte->cliente?->correo ?? ''));
        }

        return $correo !== '' ? $correo : null;
    }

    /**
     * Correo efectivo listo para IMPRIMIR: nunca vacío.
     *
     * El PDF no deja el campo en blanco ni con un guion: dice explícitamente que no hay
     * correo configurado, porque un hueco se lee como «no aplica» y acá sí aplica —es un
     * dato que falta y que alguien tiene que cargar.
     */
    public static function paraMostrar(Dte $dte): string
    {
        return self::resolver($dte) ?? self::SIN_CORREO;
    }
}
