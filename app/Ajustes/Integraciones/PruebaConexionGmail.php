<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Models\GmailCuenta;
use App\Services\Ppq\GmailClient;
use Closure;
use Throwable;

/**
 * Comprueba que la conexión con Gmail sigue viva, SIN sincronizar nada.
 *
 * CÓMO SE COMPRUEBA. Con `users.getProfile`, la llamada de solo lectura más
 * barata de la API: devuelve la dirección y el total de mensajes. No lista
 * correos, no descarga adjuntos, no marca nada como leído y no toca ni un
 * albarán. Deliberadamente NO se dispara una búsqueda de prueba: eso sería
 * ejecutar media sincronización para responder a "¿funciona?".
 *
 * QUÉ SE GUARDA. Fecha, éxito/fallo y un mensaje corto. Nunca el token, ni un
 * fragmento, ni nada derivado de él: si Google devuelve un error que arrastra
 * material del token, se corta antes de escribirlo.
 */
class PruebaConexionGmail
{
    /** Tope del mensaje que se muestra y se guarda. */
    private const MAX_MENSAJE = 300;

    /**
     * @param  Closure|null  $resolverPerfil  Semilla de pruebas: devuelve el perfil
     *                                        sin salir a la red. Por defecto se
     *                                        consulta a Gmail de verdad.
     */
    public function __construct(
        private readonly ConfiguracionGmail $configuracion,
        private readonly GmailClient $gmail,
        private readonly RegistroVerificaciones $registro,
        private readonly AuditoriaAjustes $auditoria,
        private readonly ?Closure $resolverPerfil = null,
    ) {}

    public function ejecutar(): ResultadoPruebaIntegracion
    {
        $resultado = $this->comprobar();

        // Solo se registra lo que de verdad fue una comprobación: "todavía no hay
        // credenciales" no es un fallo de Google y guardarlo como tal ensuciaría el
        // historial que después se lee para saber desde cuándo viene fallando.
        if ($resultado->comprobo) {
            $this->registro->registrar(ConfiguracionGmail::CLAVE_VERIFICACION, $resultado->resultado(), $resultado->mensaje);
            $this->auditoria->verificacion('Conexión con Gmail', $resultado->exito, $resultado->mensaje);
        }

        return $resultado;
    }

    private function comprobar(): ResultadoPruebaIntegracion
    {
        if (! $this->configuracion->habilitado()) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'La integración con Gmail está desactivada: no hay nada que comprobar.'
            );
        }

        if (! $this->configuracion->completo()) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'Faltan credenciales de Google (identificador, secreto o URL de retorno).'
            );
        }

        if (GmailCuenta::actual()?->conectada() !== true) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'No hay ninguna cuenta conectada. Usá «Conectar» para autorizar el acceso.'
            );
        }

        try {
            $perfil = $this->perfil();

            return ResultadoPruebaIntegracion::exito(
                'La cuenta responde correctamente'
                .(filled($perfil['email'] ?? null) ? ' ('.$perfil['email'].')' : '')
                .'. No se sincronizó nada.'
            );
        } catch (Throwable $e) {
            return ResultadoPruebaIntegracion::fallo($this->sanear($e));
        }
    }

    /** @return array{email: ?string, mensajes: ?int} */
    private function perfil(): array
    {
        return $this->resolverPerfil !== null
            ? (array) ($this->resolverPerfil)()
            : $this->gmail->perfil();
    }

    /**
     * Frase corta y sin material del token.
     *
     * Se tacha el secreto de cliente si apareciera, se corta en la primera línea
     * y se acota. Los tokens no se comparan uno a uno porque no hacen falta: lo
     * que Google devuelve en un error de autorización es un código
     * (`invalid_grant`), no la credencial.
     */
    private function sanear(Throwable $e): string
    {
        $texto = $e->getMessage();

        $secreto = $this->configuracion->clientSecret();
        if (filled($secreto)) {
            $texto = str_replace($secreto, '••••••••', $texto);
        }

        $texto = trim((string) strtok($texto, "\r\n"));

        return mb_substr(
            $texto !== '' ? $texto : 'Google rechazó la conexión sin dar un motivo.',
            0,
            self::MAX_MENSAJE,
        );
    }
}
