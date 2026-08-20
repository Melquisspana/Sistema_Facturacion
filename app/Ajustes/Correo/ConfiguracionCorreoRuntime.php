<?php

namespace App\Ajustes\Correo;

use App\Ajustes\Ajustes;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Vuelca la configuración de correo del Centro de Configuración sobre la
 * configuración VIVA del proceso, y tira los mailers ya construidos para que el
 * siguiente envío use la nueva.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------------------------------------------------
 * Laravel lee `config/mail.php` una vez al arrancar el proceso y el MailManager
 * CACHEA cada mailer que construye. En una petición web da igual: el proceso
 * muere al terminar. Pero el worker de colas vive horas — construye el mailer
 * con el primer correo del día y se queda con él. Sin esto, un administrador
 * cambia el servidor SMTP, la pantalla dice "guardado", y todos los DTE de la
 * tarde siguen saliendo (o fallando) por el servidor viejo hasta que alguien
 * reinicie el worker. Nadie relacionaría una cosa con la otra.
 *
 * Se aplica JUSTO ANTES de enviar, no al arrancar: es la única forma de que el
 * proceso largo se entere sin reiniciarse. `forgetMailers()` es barato (olvida
 * un array); el coste real sería reconstruir el transporte, y eso pasa una vez
 * por envío de todos modos.
 *
 * QUÉ NO HACE, A PROPÓSITO
 * ------------------------------------------------------------------
 * NO toca `mail.default`. Qué transporte se usa lo deciden el .env, la segunda
 * barrera de AppServiceProvider (fuera de producción, `log`) y
 * {@see CandadoCorreoReal}. Meter una cuarta autoridad sobre
 * ese interruptor es exactamente como se termina enviando correo real desde una
 * máquina de pruebas. Acá solo se configuran los PARÁMETROS del mailer smtp y el
 * remitente; el candado sigue mandando sobre si ese mailer llega a usarse.
 *
 * Tampoco escribe nada: solo lee ajustes ya resueltos (override → config/.env →
 * defecto) y los copia a la configuración del proceso.
 */
class ConfiguracionCorreoRuntime
{
    /**
     * Ajustes del servidor SMTP y su destino en la configuración de Laravel.
     * `mail.smtp.scheme` va aparte porque su valor «auto» significa "no fijar
     * nada" y no puede copiarse tal cual.
     */
    private const MAPA = [
        'mail.smtp.host' => 'mail.mailers.smtp.host',
        'mail.smtp.port' => 'mail.mailers.smtp.port',
        'mail.smtp.username' => 'mail.mailers.smtp.username',
        'mail.from.address' => 'mail.from.address',
        'mail.from.name' => 'mail.from.name',
    ];

    /** Valor de `mail.smtp.scheme` que significa "que lo derive Laravel del puerto". */
    public const SCHEME_AUTOMATICO = 'auto';

    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly Application $app,
    ) {}

    /**
     * Deja la configuración de correo del proceso alineada con los ajustes y
     * fuerza a que el próximo envío construya el transporte de nuevo.
     *
     * Es idempotente y barato: sin overrides guardados, cada ajuste se resuelve a
     * su fallback de config, así que copiar el valor no cambia nada.
     */
    public function aplicar(): void
    {
        $nuevos = [];

        foreach (self::MAPA as $ajuste => $destino) {
            $nuevos[$destino] = $this->ajustes->get($ajuste);
        }

        // El secreto se pide por su vía explícita. Va a la configuración en memoria,
        // que es exactamente donde ya vivía cuando salía del .env: no se amplía su
        // exposición, solo cambia de dónde se leyó.
        $nuevos['mail.mailers.smtp.password'] = $this->ajustes->secretoParaRuntime('mail.smtp.password');

        $scheme = $this->ajustes->texto('mail.smtp.scheme');
        // null (y no cadena vacía): así `$config['scheme'] ?? null` de
        // MailManager::createSmtpTransport cae en la derivación por puerto.
        $nuevos['mail.mailers.smtp.scheme'] = $scheme === self::SCHEME_AUTOMATICO || $scheme === '' ? null : $scheme;

        config($nuevos);

        $this->olvidarMailers();
    }

    /**
     * Estado ACTUAL del transporte, ya resuelto, para pantallas y diagnóstico.
     * Nunca incluye la contraseña: solo si hay una.
     *
     * @return array{mailer: ?string, host: ?string, port: ?int, scheme: ?string, username: ?string, from_address: ?string, from_name: ?string, password_configurada: bool}
     */
    public function estadoActual(): array
    {
        return [
            'mailer' => $this->ajustes->texto('mail.mailer'),
            'host' => $this->ajustes->texto('mail.smtp.host'),
            'port' => $this->ajustes->entero('mail.smtp.port'),
            'scheme' => $this->ajustes->texto('mail.smtp.scheme'),
            'username' => $this->ajustes->texto('mail.smtp.username'),
            'from_address' => $this->ajustes->texto('mail.from.address'),
            'from_name' => $this->ajustes->texto('mail.from.name'),
            'password_configurada' => $this->ajustes->estaConfigurado('mail.smtp.password'),
        ];
    }

    /**
     * Tira los mailers ya construidos.
     *
     * Se pide al manager del contenedor y no a la fachada `Mail` porque la fachada
     * puede estar intervenida: `Mail::fake()` y `Mail::shouldReceive()` sustituyen
     * el binding `mail.manager` por un doble. El doble de `fake()` sí implementa
     * `forgetMailers()`; un mock de Mockery sin expectativas, no — de ahí el
     * try/catch de abajo.
     */
    private function olvidarMailers(): void
    {
        if (! $this->app->bound('mail.manager')) {
            return;
        }

        try {
            $this->app->make('mail.manager')->forgetMailers();
        } catch (Throwable) {
            // REFRESCAR LA CONFIGURACIÓN NUNCA PUEDE ROMPER UN ENVÍO.
            //
            // Esto corre antes de cada correo y de cada trabajo de la cola. Si
            // olvidar los mailers fallara, lo peor que pasa es que el transporte
            // siga siendo el anterior —el correo sale, con la configuración vieja—;
            // dejar escapar la excepción convertiría un detalle de refresco en un
            // documento que no le llega al cliente.
            //
            // En el MailManager real `forgetMailers()` solo vacía un array y no
            // puede fallar. Quien entra por aquí es un doble de pruebas que
            // reemplaza el manager entero y no declara este método.
        }
    }
}
