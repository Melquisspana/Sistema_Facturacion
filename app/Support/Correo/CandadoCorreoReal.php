<?php

namespace App\Support\Correo;

/**
 * CANDADO ÚNICO de correo real: decide si un envío puede salir DE VERDAD por el
 * transporte configurado, o si debe registrarse como SIMULADO.
 *
 * Regla: solo se envía real cuando el entorno es `production`. En local/testing/
 * staging NO se toca el transporte aunque MAIL_MAILER sea smtp — sin excepciones y sin
 * variable de escape: la única forma de enviar es que app()->environment() sea
 * 'production'. Además, un mailer de prueba (log/array) tampoco cuenta como envío real
 * aun en producción.
 *
 * Lo consultan TODOS los emisores justo antes del transporte (jobs de correo de DTE y
 * de compras, y el paquete mensual). Se evalúa en TIEMPO DE EJECUCIÓN, dentro del
 * proceso que envía: así protege también al worker de colas, que corre aparte de la
 * petición web. Como segunda barrera, AppServiceProvider fuerza mail.default=log fuera
 * de producción, para que ni un flujo que se olvide de este candado llegue al SMTP.
 *
 * No envía, no encola y no escribe nada: solo responde preguntas.
 */
class CandadoCorreoReal
{
    /** Mailers que NO entregan: escriben o descartan el mensaje. */
    private const TRANSPORTES_DE_PRUEBA = ['log', 'array'];

    /** ¿El ENTORNO permite enviar correo real? Solo producción. */
    public function permiteEnvioReal(): bool
    {
        return app()->environment('production');
    }

    /** ¿El transporte configurado entrega de verdad (no es log/array)? */
    public function transporteEsReal(): bool
    {
        return ! in_array($this->transporte(), self::TRANSPORTES_DE_PRUEBA, true);
    }

    /**
     * ¿Este envío debe registrarse como SIMULADO en vez de salir? Si devuelve true, el
     * emisor NO debe llamar al transporte: ni Mail::send, ni Mail::queue.
     */
    public function debeSimular(): bool
    {
        return ! $this->permiteEnvioReal() || ! $this->transporteEsReal();
    }

    /** Motivo (para el historial y la auditoría) por el que el correo no salió. */
    public function motivo(): string
    {
        if (! $this->permiteEnvioReal()) {
            return 'Correo NO enviado: entorno "'.$this->entorno().'". El envío real solo está permitido en production; '
                .'se registró como simulado y no se llamó al servidor de correo.';
        }

        return 'Correo NO enviado realmente: MAIL_MAILER='.config('mail.default')
            .' (driver de prueba '.$this->transporte().', no entrega por SMTP).';
    }

    /** Aviso corto para la interfaz (solo se muestra fuera de producción). */
    public function avisoInterfaz(): string
    {
        return 'Entorno '.$this->entorno().': el correo se registrará como simulado y no se enviará realmente.';
    }

    public function entorno(): string
    {
        return (string) app()->environment();
    }

    /** Transporte efectivo del mailer activo. */
    private function transporte(): string
    {
        $mailer = (string) config('mail.default');

        return (string) config("mail.mailers.$mailer.transport", $mailer);
    }
}
