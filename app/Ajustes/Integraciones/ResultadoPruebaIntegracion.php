<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\Verificaciones\ResultadoVerificacion;

/**
 * Desenlace de comprobar una integración (Gmail, buzón IMAP), listo para
 * mostrarse.
 *
 * `$comprobo` distingue dos cosas que un solo booleano de éxito confunde: "probé
 * y falló" de "no llegué a probar". Que la integración esté apagada o sin
 * credenciales no es un fallo del servicio, y contarlo como tal llenaría el
 * historial de errores que no lo son y taparía los que sí.
 *
 * `$mensaje` llega YA SANEADO por quien conoce la excepción original: nunca la
 * excepción completa, que en un fallo de autenticación arrastra usuario, servidor
 * y la respuesta cruda.
 */
class ResultadoPruebaIntegracion
{
    private function __construct(
        public readonly bool $exito,
        public readonly bool $comprobo,
        public readonly string $mensaje,
    ) {}

    public static function exito(string $mensaje): self
    {
        return new self(exito: true, comprobo: true, mensaje: $mensaje);
    }

    public static function fallo(string $mensaje): self
    {
        return new self(exito: false, comprobo: true, mensaje: $mensaje);
    }

    /** No se llegó a probar: falta configuración o la integración está apagada. */
    public static function sinComprobar(string $mensaje): self
    {
        return new self(exito: false, comprobo: false, mensaje: $mensaje);
    }

    public function resultado(): ResultadoVerificacion
    {
        return $this->exito ? ResultadoVerificacion::Exito : ResultadoVerificacion::Fallo;
    }
}
