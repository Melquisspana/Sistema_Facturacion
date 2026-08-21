<?php

namespace Tests\Feature\Asistencia;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PING de diagnóstico del lector de huella (/api/asistencia/ping).
 *
 * Lo que este archivo fija —y que es justo lo que se quiere comprobar ANTES de
 * escribir una sola línea del módulo de asistencia—:
 *
 *  1. El endpoint contesta JSON a un cliente que NO es un navegador: sin sesión,
 *     sin cookie y sin token CSRF. Ese es el cliente real (un ESP32) y es lo que
 *     rompería si estas rutas colgaran de routes/web.php.
 *  2. Contesta igual por GET y por POST. El firmware definitivo marcará con POST;
 *     que POST pase sin token es exactamente lo que hay que demostrar hoy.
 *  3. La HORA la pone el SERVIDOR, en la zona oficial del módulo. El reloj del
 *     ESP32 nunca es fuente de verdad.
 *  4. Con el módulo apagado el endpoint no existe (404), que es el estado por
 *     defecto en cualquier servidor sin lector.
 *  5. No escribe nada: no hay tablas del módulo y el ping no las necesita. Por eso
 *     este test NO usa RefreshDatabase — si algún día el ping tocara la base, se
 *     caería acá.
 */
class DispositivoPingTest extends TestCase
{
    private const RUTA = '/api/asistencia/ping';

    private function encenderModulo(): void
    {
        config()->set('asistencia.enabled', true);
    }

    /**
     * Cada prueba fija el interruptor que necesita en vez de heredarlo del
     * ambiente, para dar el mismo resultado con ASISTENCIA_ENABLED=false (el valor
     * de phpunit.xml) y con el flag encendido en el .env de la máquina.
     */
    private function apagarModulo(): void
    {
        config()->set('asistencia.enabled', false);
    }

    public function test_el_dispositivo_recibe_json_de_diagnostico_sin_sesion(): void
    {
        $this->encenderModulo();

        $this->get(self::RUTA)
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJson([
                'ok' => true,
                'mensaje' => 'ESP32 conectado',
            ])
            ->assertJsonStructure([
                'ok',
                'mensaje',
                'servidor' => ['fecha', 'hora', 'fecha_hora', 'iso8601', 'epoch', 'zona'],
            ]);
    }

    /**
     * El firmware definitivo marcará con POST. Sin token CSRF: un dispositivo no
     * puede tenerlo. Si estas rutas se movieran al grupo web, esto daría 419.
     */
    public function test_el_mismo_ping_responde_por_post_sin_token_csrf(): void
    {
        $this->encenderModulo();

        $this->post(self::RUTA)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    /** La hora es la del SERVIDOR, en la zona oficial del módulo. */
    public function test_la_hora_la_pone_el_servidor_en_la_zona_oficial(): void
    {
        $this->encenderModulo();
        config()->set('asistencia.zona_horaria', 'America/El_Salvador');

        Carbon::setTestNow(Carbon::parse('2026-08-20 18:30:45', 'UTC'));

        $respuesta = $this->get(self::RUTA)->assertOk();

        // 18:30:45 UTC son las 12:30:45 en El Salvador (UTC-6).
        $respuesta->assertJsonPath('servidor.fecha', '2026-08-20');
        $respuesta->assertJsonPath('servidor.hora', '12:30:45');
        $respuesta->assertJsonPath('servidor.fecha_hora', '2026-08-20 12:30:45');
        $respuesta->assertJsonPath('servidor.iso8601', '2026-08-20T12:30:45-06:00');
        $respuesta->assertJsonPath('servidor.zona', 'America/El_Salvador');
        $respuesta->assertJsonPath('servidor.epoch', Carbon::getTestNow()->getTimestamp());

        Carbon::setTestNow();
    }

    /** El ping NO cuenta nada del sistema a quien lo toque desde la red. */
    public function test_la_respuesta_no_expone_configuracion_del_sistema(): void
    {
        $this->encenderModulo();

        $datos = $this->get(self::RUTA)->assertOk()->json();

        $this->assertSame(['ok', 'mensaje', 'servidor'], array_keys($datos),
            'El ping solo debe devolver ok, mensaje y la hora del servidor.');
    }

    /** Estado por defecto: sin ASISTENCIA_ENABLED el módulo no existe. */
    public function test_con_el_modulo_apagado_el_endpoint_responde_404(): void
    {
        $this->apagarModulo();

        $this->get(self::RUTA)->assertNotFound();
        $this->post(self::RUTA)->assertNotFound();
    }

    /**
     * Cableado: la suite debe arrancar con el módulo APAGADO (phpunit.xml), igual
     * que un servidor recién desplegado. Sin esto, un test que se olvide de fijar
     * el interruptor pasaría o fallaría según el .env de la máquina.
     */
    public function test_la_suite_arranca_con_el_modulo_apagado(): void
    {
        $this->assertFalse((bool) env('ASISTENCIA_ENABLED'),
            'phpunit.xml debe fijar ASISTENCIA_ENABLED=false para toda la suite.');
    }
}
