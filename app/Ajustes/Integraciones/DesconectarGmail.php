<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\AuditoriaAjustes;
use App\Models\GmailCuenta;

/**
 * Desconecta la cuenta de Gmail y deja rastro.
 *
 * Existe como servicio y no como dos líneas en un controlador porque hay DOS
 * puertas a la misma operación —la pantalla de Prontos Pagos y la de
 * Integraciones— y cada una devuelve al usuario a su sitio. Con la operación
 * copiada, la auditoría habría acabado en una de las dos nada más.
 *
 * BORRAR ES LO CORRECTO, no vaciar los tokens: mientras la fila exista con su
 * correo, la pantalla diría "conectado a X" de una cuenta que ya no autoriza
 * nada. Y los tokens borrados no se pueden filtrar.
 *
 * LA AUDITORÍA REGISTRA EL HECHO Y LA CUENTA, NUNCA LOS TOKENS. El correo es un
 * identificador —es lo que hace falta para saber qué se desconectó—; los tokens
 * no aparecen, ni enteros ni en fragmentos ni en hashes.
 */
class DesconectarGmail
{
    public function __construct(private readonly AuditoriaAjustes $auditoria) {}

    /** @return string|null El correo de la cuenta desconectada, o null si no había ninguna. */
    public function ejecutar(): ?string
    {
        $cuenta = GmailCuenta::actual();

        if ($cuenta === null) {
            return null;
        }

        $correo = $cuenta->email;

        GmailCuenta::query()->delete();

        $this->auditoria->integracionDesconectada(
            'Gmail',
            $correo !== null && $correo !== '' ? $correo : 'cuenta sin correo registrado',
        );

        return $correo;
    }
}
