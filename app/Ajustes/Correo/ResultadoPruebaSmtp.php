<?php

namespace App\Ajustes\Correo;

use App\Ajustes\Verificaciones\ResultadoVerificacion;

/**
 * Desenlace de una prueba de conexión SMTP, listo para mostrarse.
 *
 * `$mensaje` viene YA SANEADO por {@see PruebaConexionSmtp}: es una frase corta,
 * nunca la excepción completa. Un fallo de autenticación de Symfony arrastra el
 * usuario, el host y la respuesta cruda del servidor, y esa cadena termina en la
 * pantalla, en la auditoría y en la tabla de verificaciones — tres sitios donde
 * no debe haber nada parecido a una credencial.
 *
 * `$conecto` distingue dos cosas que un simple booleano de éxito confunde:
 * "probé y falló" de "no llegué a probar". Si el transporte configurado no es
 * SMTP (por ejemplo `log`, que es lo normal fuera de producción), no hay nada que
 * conectar: informarlo como fallo sería mentir.
 */
class ResultadoPruebaSmtp
{
    private function __construct(
        public readonly bool $exito,
        public readonly bool $conecto,
        public readonly string $mensaje,
    ) {}

    public static function exito(string $mensaje): self
    {
        return new self(exito: true, conecto: true, mensaje: $mensaje);
    }

    public static function fallo(string $mensaje): self
    {
        return new self(exito: false, conecto: true, mensaje: $mensaje);
    }

    /** No se llegó a conectar: falta configuración o el transporte no es SMTP. */
    public static function sinConexion(string $mensaje): self
    {
        return new self(exito: false, conecto: false, mensaje: $mensaje);
    }

    public function resultado(): ResultadoVerificacion
    {
        return $this->exito ? ResultadoVerificacion::Exito : ResultadoVerificacion::Fallo;
    }
}
