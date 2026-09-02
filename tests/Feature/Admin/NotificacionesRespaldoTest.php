<?php

namespace Tests\Feature\Admin;

use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\Sistema\NotificacionesRespaldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\Backup\Config\NotificationMailConfig;
use Spatie\Backup\Notifications\Notifiable as SpatieNotifiable;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Tests\TestCase;

/**
 * El destinatario de los avisos de respaldo venía con el placeholder de la plantilla de
 * spatie (`your@example.com`), que se lee como si fuera un destinatario ya puesto. Si un
 * backup falla, nadie se entera — y el día que falla es justo el día en que nadie mira
 * el panel.
 *
 * No se puede señalarlo dejando el valor vacío: spatie VALIDA el formato del correo y
 * lanza InvalidConfig, lo que rompería `backup:run` y `backup:clean` enteros. De ahí el
 * centinela con formato válido en el TLD reservado `.invalid`.
 */
class NotificacionesRespaldoTest extends TestCase
{
    use RefreshDatabase;

    private function checkRespaldos(): array
    {
        $checks = app(DiagnosticoSistemaService::class)->evaluar()['checks'];

        foreach ($checks as $check) {
            if ($check['clave'] === 'notificaciones_respaldo') {
                return $check;
            }
        }

        $this->fail('El diagnóstico no incluye el check "notificaciones_respaldo".');
    }

    public function test_el_centinela_cuenta_como_no_configurado(): void
    {
        config(['backup.notifications.mail.to' => NotificacionesRespaldo::SIN_CONFIGURAR]);

        $this->assertFalse(NotificacionesRespaldo::configurado());
        $this->assertSame([], NotificacionesRespaldo::destinatarios());
    }

    public function test_el_placeholder_historico_de_spatie_tambien_cuenta_como_no_configurado(): void
    {
        // Instalaciones anteriores siguen teniendo este valor en su config publicada.
        config(['backup.notifications.mail.to' => 'your@example.com']);

        $this->assertFalse(NotificacionesRespaldo::configurado());
    }

    public function test_un_correo_real_cuenta_como_configurado(): void
    {
        config(['backup.notifications.mail.to' => 'avisos@ejemplo.com']);

        $this->assertTrue(NotificacionesRespaldo::configurado());
        $this->assertSame(['avisos@ejemplo.com'], NotificacionesRespaldo::destinatarios());
    }

    public function test_soporta_varios_destinatarios_y_descarta_los_placeholders(): void
    {
        config(['backup.notifications.mail.to' => ['your@example.com', 'Avisos@Ejemplo.com']]);

        $this->assertTrue(NotificacionesRespaldo::configurado());
        $this->assertSame(['avisos@ejemplo.com'], NotificacionesRespaldo::destinatarios());
    }

    public function test_el_centinela_sigue_siendo_un_correo_valido_para_spatie(): void
    {
        // Si esto falla, `backup:run` y `backup:clean` revientan al arrancar: spatie
        // valida el formato del destinatario antes de hacer nada.
        $config = NotificationMailConfig::fromArray([
            'to' => NotificacionesRespaldo::SIN_CONFIGURAR,
            'from' => ['address' => 'app@ejemplo.com', 'name' => 'App'],
        ]);

        $this->assertSame(NotificacionesRespaldo::SIN_CONFIGURAR, $config->to);
    }

    /*
    |---------------------------------------------------------------------------
    | Los tres estados de BACKUP_NOTIFICACIONES_CORREO
    |---------------------------------------------------------------------------
    |
    | El ensayo de despliegue tropezó con el estado del medio: `.env.example` trae la
    | clave DECLARADA Y VACÍA —que es como se dice «esto se rellena en cada servidor»—
    | y el default de `env()` no cubre ese caso, porque la clave sí existe. Spatie
    | recibía '' y lo validaba como correo: `InvalidConfig: is not a valid email
    | address`. Eso ocurre al construir la configuración, o sea en el ARRANQUE, así que
    | tumbaba `package:discover` y con él cualquier instalación hecha copiando la
    | plantilla. Las tres pruebas siguientes fijan los tres estados de la variable.
    */

    public function test_variable_ausente_equivale_a_sin_configurar(): void
    {
        $this->assertSame(
            NotificacionesRespaldo::SIN_CONFIGURAR,
            NotificacionesRespaldo::destinatarioConfigurado(null)
        );
    }

    public function test_variable_vacia_no_rompe_el_arranque_y_equivale_a_ausente(): void
    {
        foreach (['', '   '] as $vacio) {
            $valor = NotificacionesRespaldo::destinatarioConfigurado($vacio);

            $this->assertSame(NotificacionesRespaldo::SIN_CONFIGURAR, $valor, 'valor: «'.$vacio.'»');

            // Y lo que sale de ahí lo acepta spatie. Con '' esto lanzaba InvalidConfig,
            // que es literalmente lo que rompía `php artisan` entero.
            $config = NotificationMailConfig::fromArray([
                'to' => $valor,
                'from' => ['address' => 'app@ejemplo.com', 'name' => 'App'],
            ]);

            $this->assertSame(NotificacionesRespaldo::SIN_CONFIGURAR, $config->to);
        }

        // Sigue contando como NO configurado: la corrección no inventa un destinatario.
        config(['backup.notifications.mail.to' => NotificacionesRespaldo::destinatarioConfigurado('')]);
        $this->assertFalse(NotificacionesRespaldo::configurado());
    }

    public function test_un_correo_valido_se_respeta_tal_cual(): void
    {
        $this->assertSame('avisos@ejemplo.com', NotificacionesRespaldo::destinatarioConfigurado('avisos@ejemplo.com'));
        // Con espacios de sobra alrededor, que es lo que deja un .env editado a mano.
        $this->assertSame('avisos@ejemplo.com', NotificacionesRespaldo::destinatarioConfigurado('  avisos@ejemplo.com  '));

        config(['backup.notifications.mail.to' => NotificacionesRespaldo::destinatarioConfigurado('avisos@ejemplo.com')]);
        $this->assertTrue(NotificacionesRespaldo::configurado());
        $this->assertSame(['avisos@ejemplo.com'], NotificacionesRespaldo::destinatarios());
    }

    /** La configuración real de la app resuelve a algo que spatie acepta, siempre. */
    public function test_la_configuracion_publicada_siempre_es_valida_para_spatie(): void
    {
        $config = NotificationMailConfig::fromArray([
            'to' => config('backup.notifications.mail.to'),
            'from' => config('backup.notifications.mail.from'),
        ]);

        $this->assertNotSame('', $config->to);
    }

    /*
    |---------------------------------------------------------------------------
    | Con MAIL_MAILER=smtp, que es lo que hay en PRODUCCIÓN
    |---------------------------------------------------------------------------
    |
    | El centinela sirve para que spatie ARRANQUE —valida que `to` tenga forma de correo
    | y sin eso lanza InvalidConfig—, pero no es un destinatario: nadie lo recibe.
    | Mientras el correo del sistema fue `log` eso no se notaba. Con `smtp`, dejar el
    | canal abierto significa un intento de entrega real a un dominio inexistente en cada
    | respaldo y en cada limpieza, todas las noches.
    |
    | La corrección no es cambiar el destinatario: es APAGAR EL CANAL cuando no hay
    | ninguno. `via()` de spatie hace `array_filter` sobre la lista de canales, así que
    | con la lista vacía la notificación no sale por ningún medio.
    */

    /** Pone la suite en el estado de producción: correo por SMTP de verdad. */
    private function correoSmtp(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Notification::fake();
    }

    /**
     * Reconstruye el objeto de configuración de spatie a partir de `config('backup')`.
     *
     * Hace falta porque la librería lo resuelve con `scoped()`: se arma UNA vez por
     * petición y no vuelve a mirar la configuración. Sin esto, lo que cambia la prueba
     * con `config([...])` no llega a `via()`, y la prueba mediría el estado de arranque
     * en lugar del que quiere ejercitar.
     */
    private function recargarConfigDeSpatie(): void
    {
        $this->app->forgetInstance(BackupConfig::class);
        $this->app->instance(BackupConfig::class, BackupConfig::fromArray(config('backup')));
    }

    /** Las seis notificaciones de spatie, tal como las declara la configuración. */
    private function canalesDeclarados(): array
    {
        return array_values(config('backup.notifications.notifications'));
    }

    public function test_sin_destinatario_no_hay_ningun_canal_de_aviso(): void
    {
        foreach ([null, '', '   '] as $sinDestinatario) {
            $this->assertSame(
                [],
                NotificacionesRespaldo::canalesDeAviso($sinDestinatario),
                'valor: '.var_export($sinDestinatario, true)
            );
        }
    }

    public function test_con_destinatario_valido_el_canal_de_correo_sigue_activo(): void
    {
        $this->assertSame(['mail'], NotificacionesRespaldo::canalesDeAviso('avisos@ejemplo.com'));
        $this->assertSame(['mail'], NotificacionesRespaldo::canalesDeAviso('  avisos@ejemplo.com  '));
    }

    /**
     * Ausente, vacía y solo-espacios: spatie arranca, la limpieza corre y NO sale ni un
     * correo. Se ejercita `backup:clean` de verdad —no un doble— porque es el camino
     * corto que atraviesa la construcción de la config de spatie y su notificación de
     * éxito, que es donde estaba el problema.
     */
    public function test_sin_destinatario_el_respaldo_corre_y_no_intenta_enviar_nada(): void
    {
        foreach ([null, '', '   '] as $sinDestinatario) {
            $etiqueta = 'valor: '.var_export($sinDestinatario, true);

            $this->correoSmtp();
            Storage::fake('local');

            config([
                'backup.notifications.mail.to' => NotificacionesRespaldo::destinatarioConfigurado($sinDestinatario),
                'backup.notifications.notifications' => array_map(
                    fn () => NotificacionesRespaldo::canalesDeAviso($sinDestinatario),
                    config('backup.notifications.notifications')
                ),
            ]);

            $this->recargarConfigDeSpatie();

            // 1. Spatie arranca: construir su configuración es lo que antes reventaba.
            $config = NotificationMailConfig::fromArray([
                'to' => config('backup.notifications.mail.to'),
                'from' => config('backup.notifications.mail.from'),
            ]);
            $this->assertSame(NotificacionesRespaldo::SIN_CONFIGURAR, $config->to, $etiqueta);

            // 2. El canal está cerrado en las seis notificaciones.
            foreach ($this->canalesDeclarados() as $canales) {
                $this->assertSame([], $canales, $etiqueta);
            }

            // 3. El respaldo se puede ejecutar.
            $this->artisan('backup:clean')->assertSuccessful();

            // 4. Y no hubo NI UN intento de envío, ni por correo ni por notificación.
            Mail::assertNothingSent();
            Notification::assertNothingSent();
        }
    }

    /**
     * Con un correo válido, el comportamiento no cambia: el canal sigue abierto y el
     * aviso se manda al destinatario de verdad.
     */
    public function test_con_correo_valido_el_aviso_se_sigue_enviando(): void
    {
        $this->correoSmtp();
        Storage::fake('local');

        config([
            'backup.notifications.mail.to' => NotificacionesRespaldo::destinatarioConfigurado('avisos@ejemplo.com'),
            'backup.notifications.notifications' => array_map(
                fn () => NotificacionesRespaldo::canalesDeAviso('avisos@ejemplo.com'),
                config('backup.notifications.notifications')
            ),
        ]);

        $this->recargarConfigDeSpatie();

        $this->assertSame('avisos@ejemplo.com', config('backup.notifications.mail.to'));

        foreach ($this->canalesDeclarados() as $canales) {
            $this->assertSame(['mail'], $canales);
        }

        $this->artisan('backup:clean')->assertSuccessful();

        // El destinatario de spatie es su propia clase Notifiable, que enruta el correo
        // desde la configuración: es la que hay que interrogar.
        Notification::assertSentTo(
            new SpatieNotifiable,
            CleanupWasSuccessfulNotification::class,
            fn ($notificacion, array $canales, $notifiable) => $canales === ['mail']
                && $notifiable->routeNotificationForMail() === 'avisos@ejemplo.com'
        );
    }

    /** Y el diagnóstico sigue diciendo la verdad: sin configurar. */
    public function test_apagar_el_canal_no_cambia_lo_que_dice_el_diagnostico(): void
    {
        config(['backup.notifications.mail.to' => NotificacionesRespaldo::destinatarioConfigurado('')]);

        $check = $this->checkRespaldos();

        $this->assertSame('advertencia', $check['nivel']);
        $this->assertStringContainsString('Notificaciones de backup no configuradas', $check['detalle']);
    }

    public function test_el_diagnostico_avisa_cuando_no_hay_destinatario(): void
    {
        config(['backup.notifications.mail.to' => NotificacionesRespaldo::SIN_CONFIGURAR]);

        $check = $this->checkRespaldos();

        $this->assertSame('advertencia', $check['nivel']);
        $this->assertStringContainsString('Notificaciones de backup no configuradas', $check['detalle']);
    }

    public function test_el_diagnostico_queda_en_verde_con_destinatario(): void
    {
        config(['backup.notifications.mail.to' => 'avisos@ejemplo.com']);

        $this->assertSame('correcto', $this->checkRespaldos()['nivel']);
    }

    public function test_no_avisa_como_critico_para_no_tapar_un_backup_faltante(): void
    {
        // El respaldo se sigue haciendo; lo que falta es el aviso cuando falla. Marcarlo
        // crítico pondría el panel en rojo todos los días y entrenaría a ignorarlo.
        config(['backup.notifications.mail.to' => NotificacionesRespaldo::SIN_CONFIGURAR]);

        $this->assertNotSame('critico', $this->checkRespaldos()['nivel']);
    }
}
