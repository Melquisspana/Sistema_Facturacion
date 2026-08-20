<?php

namespace Tests\Feature\Admin;

use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\Sistema\NotificacionesRespaldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Backup\Config\NotificationMailConfig;
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
