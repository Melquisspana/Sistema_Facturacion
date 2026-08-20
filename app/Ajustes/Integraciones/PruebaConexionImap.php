<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use Closure;
use Throwable;

/**
 * Comprueba que el buzón de compras acepta la conexión y las credenciales, SIN
 * leer ni tocar un solo correo.
 *
 * CÓMO SE COMPRUEBA. Se abre el buzón con `OP_READONLY` y se cierra en el acto.
 * Eso valida servidor, puerto, seguridad, usuario y contraseña —que es todo lo
 * que puede estar mal— sin listar mensajes, sin descargar adjuntos, sin marcar
 * como leído y sin disparar ninguna sincronización. La bandera de solo lectura
 * es la misma que usa el lector: el modo de escritura no se abre nunca, ni
 * siquiera para probar.
 *
 * QUÉ SE GUARDA. Fecha, éxito/fallo y un mensaje corto y saneado. `imap_open`
 * deja sus errores en un buffer aparte que suele incluir el servidor y el
 * usuario; se recorta y se le tacha la contraseña por si el servidor la
 * devolviera en un eco.
 */
class PruebaConexionImap
{
    /** Tope del mensaje que se muestra y se guarda. */
    private const MAX_MENSAJE = 300;

    /**
     * @param  Closure|null  $abrir  Semilla de pruebas: recibe la configuración del
     *                               lector y devuelve null si conecta, o el motivo
     *                               del fallo. Por defecto se conecta de verdad.
     */
    public function __construct(
        private readonly ConfiguracionDocumentosRecibidos $configuracion,
        private readonly RegistroVerificaciones $registro,
        private readonly AuditoriaAjustes $auditoria,
        private readonly ?Closure $abrir = null,
    ) {}

    public function ejecutar(): ResultadoPruebaIntegracion
    {
        $resultado = $this->comprobar();

        if ($resultado->comprobo) {
            $this->registro->registrar(
                ConfiguracionDocumentosRecibidos::CLAVE_VERIFICACION,
                $resultado->resultado(),
                $resultado->mensaje,
            );
            $this->auditoria->verificacion('Conexión con el buzón de compras', $resultado->exito, $resultado->mensaje);
        }

        return $resultado;
    }

    private function comprobar(): ResultadoPruebaIntegracion
    {
        if (! $this->configuracion->lecturaActivada()) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'La lectura del buzón está desactivada: no hay conexión que probar.'
            );
        }

        if (! $this->configuracion->completa()) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'Faltan datos de conexión (servidor, usuario o contraseña).'
            );
        }

        if ($this->abrir === null && ! function_exists('imap_open')) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'Este servidor no tiene la extensión IMAP de PHP: no se puede comprobar la conexión desde aquí.'
            );
        }

        try {
            $motivo = $this->abrirYCerrar();
        } catch (Throwable $e) {
            return ResultadoPruebaIntegracion::fallo($this->sanear($e->getMessage()));
        }

        return $motivo === null
            ? ResultadoPruebaIntegracion::exito('El buzón respondió y aceptó las credenciales. No se leyó ningún correo.')
            : ResultadoPruebaIntegracion::fallo($this->sanear($motivo));
    }

    /** @return string|null null si conectó; el motivo si no. */
    private function abrirYCerrar(): ?string
    {
        if ($this->abrir !== null) {
            $motivo = ($this->abrir)($this->configuracion->paraLector());

            return is_string($motivo) && $motivo !== '' ? $motivo : null;
        }

        $cfg = $this->configuracion->paraLector();
        $enc = (string) $cfg['encryption'];
        // Mismo buzón que arma el lector, con la MISMA bandera de solo lectura:
        // probar no puede abrir un modo que el uso normal no abre.
        $bandera = '/imap'.($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '')).'/readonly';
        $buzon = '{'.$cfg['host'].':'.$cfg['port'].$bandera.'}'.$cfg['folder'];

        @imap_timeout(IMAP_OPENTIMEOUT, (int) $cfg['timeout']);

        // Los errores de imap_open no llegan como excepción: quedan en un buffer
        // global. Se vacía antes para no leer el de otra llamada anterior.
        @imap_errors();

        $conexion = @imap_open($buzon, (string) $cfg['username'], (string) $cfg['password'], OP_READONLY, 1);

        if ($conexion === false) {
            $errores = @imap_errors() ?: [];

            return $errores === [] ? 'El servidor rechazó la conexión.' : (string) end($errores);
        }

        @imap_close($conexion);
        @imap_errors();

        return null;
    }

    /** Frase corta y sin credenciales. */
    private function sanear(string $texto): string
    {
        $password = $this->configuracion->password();
        if (filled($password)) {
            $texto = str_replace($password, '••••••••', $texto);
        }

        $texto = trim((string) strtok($texto, "\r\n"));

        return mb_substr(
            $texto !== '' ? $texto : 'El buzón rechazó la conexión sin dar un motivo.',
            0,
            self::MAX_MENSAJE,
        );
    }
}
