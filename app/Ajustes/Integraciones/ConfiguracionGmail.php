<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\Ajustes;

/**
 * Configuración de la integración con Gmail, resuelta por el Centro de
 * Configuración (override en base de datos → config/.env → valor por defecto).
 *
 * EXISTE PARA NO TOCAR LA LÓGICA DE PPQ. `GmailClient` seguía llamando a
 * `config('ppq.gmail.*')` en ocho sitios; cambiar cada uno por una llamada
 * distinta habría sido reescribir el cliente. Con esto, el cliente cambia de
 * FUENTE y no de comportamiento: la búsqueda, el parseo, la conciliación y la
 * asociación de documentos siguen recibiendo exactamente los mismos valores.
 *
 * EL SECRETO SE PIDE APARTE. `clientSecret()` es la única vía y su nombre lo
 * declara; el resto del objeto se puede volcar a una pantalla sin pensarlo dos
 * veces porque no tiene forma de llevarlo.
 */
class ConfiguracionGmail
{
    /** Servicio comprobado, para el historial de verificaciones. */
    public const CLAVE_VERIFICACION = 'gmail';

    public function __construct(private readonly Ajustes $ajustes) {}

    public function habilitado(): bool
    {
        return $this->ajustes->bool('ppq.gmail.enabled', false);
    }

    public function clientId(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.client_id', '');
    }

    /**
     * Secreto de cliente. SOLO para el flujo OAuth: nunca a una vista, a un JSON
     * ni a un log. Para pantalla existe {@see secretoConfigurado()}.
     */
    public function clientSecret(): string
    {
        return (string) $this->ajustes->secretoParaRuntime('ppq.gmail.client_secret');
    }

    public function secretoConfigurado(): bool
    {
        return $this->ajustes->estaConfigurado('ppq.gmail.client_secret');
    }

    public function redirectUri(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.redirect_uri', '');
    }

    public function labelAlbaranes(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.label_albaranes', 'Calleja_Albaranes');
    }

    public function enviadosQuery(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.enviados_query', 'in:sent');
    }

    public function dteAdjuntoQuery(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.dte_adjunto_query', '(filename:json OR filename:pdf)');
    }

    /** Ruta del servidor: se lee, no se edita desde la aplicación. */
    public function storageDir(): string
    {
        return (string) $this->ajustes->texto('ppq.gmail.storage_dir', 'ppq/gmail');
    }

    /**
     * ¿Están las cuatro piezas que hacen falta para pedirle permiso a Google?
     *
     * Es la MISMA condición que `GmailClient::configurado()` comprobaba contra
     * config; se centraliza acá para que la pantalla y el cliente no puedan
     * responder cosas distintas a la misma pregunta.
     */
    public function completo(): bool
    {
        return $this->habilitado()
            && filled($this->clientId())
            && $this->secretoConfigurado()
            && filled($this->redirectUri());
    }
}
