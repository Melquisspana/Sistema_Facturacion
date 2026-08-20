<?php

namespace App\Ajustes\Correo;

use App\Ajustes\Ajustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Comprueba que el servidor SMTP configurado responde y acepta las credenciales
 * — SIN ENVIAR NINGÚN CORREO.
 *
 * CÓMO SE PRUEBA SIN ENVIAR
 * ------------------------------------------------------------------
 * `SmtpTransport::start()` abre la conexión, saluda (EHLO), negocia STARTTLS si
 * corresponde y AUTENTICA. Ahí termina: el mensaje solo viaja en `send()`, que
 * no se llama nunca. Es la comprobación completa de "servidor + puerto +
 * seguridad + usuario + contraseña" sin que salga nada hacia ningún cliente.
 *
 * Por eso NO existe la alternativa de "mandarse un correo de prueba a sí mismo":
 * no hace falta, y una dirección de prueba inventada es justo la clase de cosa
 * que un día acaba en la bandeja de un cliente.
 *
 * QUÉ SE GUARDA
 * ------------------------------------------------------------------
 * Fecha, éxito/fallo y un mensaje SANEADO. La excepción de Symfony no se guarda
 * ni se muestra tal cual: en un fallo de autenticación incluye el usuario, el
 * host y la respuesta cruda del servidor, y ese texto acabaría en la pantalla, en
 * la auditoría y en la tabla de verificaciones. Además, si la contraseña actual
 * apareciera por cualquier vía en el texto, se sustituye antes de escribir nada.
 */
class PruebaConexionSmtp
{
    /** Nombre del servicio en la tabla de verificaciones. */
    public const CLAVE = 'smtp';

    /** Tope del mensaje que se muestra y se guarda. */
    private const MAX_MENSAJE = 300;

    /**
     * @param  Closure|null  $resolverTransporte  Semilla de pruebas: devuelve el
     *                                            transporte a usar. Por defecto se
     *                                            pide al MailManager real.
     */
    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly RegistroVerificaciones $registro,
        private readonly AuditoriaAjustes $auditoria,
        private readonly ConfiguracionCorreoRuntime $runtime,
        private readonly Application $app,
        private readonly ?Closure $resolverTransporte = null,
    ) {}

    public function ejecutar(): ResultadoPruebaSmtp
    {
        // La prueba tiene que probar lo que el envío VA A USAR, no lo que había en
        // config al arrancar el proceso: se aplica primero y se construye después.
        $this->runtime->aplicar();

        $resultado = $this->comprobar();

        // Solo se registra lo que de verdad fue una comprobación. Un "no llegué a
        // conectar" por falta de configuración no es un fallo del servidor, y
        // guardarlo como tal ensuciaría el historial que después se lee para saber
        // desde cuándo viene fallando.
        if ($resultado->conecto) {
            $this->registro->registrar(self::CLAVE, $resultado->resultado(), $resultado->mensaje);
            $this->auditoria->verificacion('Conexión SMTP', $resultado->exito, $resultado->mensaje);
        }

        return $resultado;
    }

    private function comprobar(): ResultadoPruebaSmtp
    {
        $host = $this->ajustes->texto('mail.smtp.host');

        if (blank($host)) {
            return ResultadoPruebaSmtp::sinConexion(
                'No hay un servidor SMTP configurado: no hay nada a lo que conectarse.'
            );
        }

        $transporte = $this->transporte();

        if (! $transporte instanceof SmtpTransport) {
            return ResultadoPruebaSmtp::sinConexion(
                'El medio de envío activo no es un servidor SMTP ('.($this->ajustes->texto('mail.mailer') ?? 'sin definir')
                .'), así que no hay conexión que probar. Los datos del servidor sí quedan guardados para cuando se active.'
            );
        }

        try {
            // Conecta, saluda, negocia cifrado y autentica. No envía.
            $transporte->start();

            return ResultadoPruebaSmtp::exito(
                'El servidor respondió y aceptó las credenciales. No se envió ningún correo.'
            );
        } catch (Throwable $e) {
            return ResultadoPruebaSmtp::fallo($this->sanear($e));
        } finally {
            $this->cerrar($transporte);
        }
    }

    /** Transporte a comprobar, o null si no se puede obtener. */
    private function transporte(): mixed
    {
        if ($this->resolverTransporte !== null) {
            return ($this->resolverTransporte)();
        }

        // Se pide al manager del contenedor y no a la fachada Mail: con Mail::fake()
        // la fachada devuelve un doble que no tiene transporte.
        if (! $this->app->bound('mail.manager')) {
            return null;
        }

        try {
            return $this->app->make('mail.manager')->mailer('smtp')->getSymfonyTransport();
        } catch (Throwable) {
            return null;
        }
    }

    private function cerrar(SmtpTransport $transporte): void
    {
        try {
            $transporte->stop();
        } catch (Throwable) {
            // Cerrar una conexión que nunca llegó a abrirse no es un error que
            // interese a nadie: el desenlace de la prueba ya está decidido.
        }
    }

    /**
     * Convierte la excepción en una frase corta y sin credenciales.
     *
     * Tres pasadas, de la más importante a la menos: se tacha la contraseña actual
     * si aparece literalmente, se corta en la primera línea (Symfony añade el
     * diálogo completo con el servidor en las siguientes) y se acota la longitud.
     */
    private function sanear(Throwable $e): string
    {
        $texto = $e->getMessage();

        $password = $this->ajustes->secretoParaRuntime('mail.smtp.password');
        if (filled($password)) {
            $texto = str_replace($password, '••••••••', $texto);
        }

        $texto = trim((string) strtok($texto, "\r\n"));

        if ($texto === '') {
            $texto = 'El servidor de correo rechazó la conexión sin dar un motivo.';
        }

        return mb_substr($texto, 0, self::MAX_MENSAJE);
    }
}
