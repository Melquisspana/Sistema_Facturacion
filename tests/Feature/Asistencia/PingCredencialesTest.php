<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El bloque OPCIONAL `dispositivo` del ping (/api/asistencia/ping).
 *
 * Está en su propio archivo a propósito: {@see DispositivoPingTest} demuestra que
 * el ping SIN credenciales no toca la base —y lo demuestra no usando
 * RefreshDatabase, así que se caería si algún día la tocara—. Estas pruebas sí
 * necesitan lectores dados de alta, y meterlas allá borraría esa garantía.
 *
 * Para qué sirve el bloque: verificar el token que trae el firmware SIN generar
 * una marcación de prueba que después haya que explicar en la planilla.
 */
class PingCredencialesTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/asistencia/ping';

    /** Token FICTICIO de la suite. */
    private const TOKEN = 'token-ficticio-de-pruebas-del-lector';

    private AsistenciaDispositivo $dispositivo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->dispositivo = AsistenciaDispositivo::create([
            'codigo' => 'lector-entrada',
            'nombre' => 'Entrada principal',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN),
            'activo' => true,
        ]);
    }

    public function test_con_credencial_valida_el_ping_confirma_el_lector(): void
    {
        $this->getJson(self::RUTA, [
            'X-Dispositivo' => 'lector-entrada',
            'X-Dispositivo-Token' => self::TOKEN,
        ])->assertOk()->assertJson([
            'ok' => true,
            'dispositivo' => [
                'reconocido' => true,
                'nombre' => 'Entrada principal',
            ],
        ]);
    }

    public function test_con_credencial_invalida_el_ping_lo_dice_sin_dar_detalles(): void
    {
        $this->getJson(self::RUTA, [
            'X-Dispositivo' => 'lector-entrada',
            'X-Dispositivo-Token' => 'token-equivocado',
        ])->assertOk()->assertJson([
            'ok' => true,
            'dispositivo' => [
                'reconocido' => false,
                // Sin nombre: el nombre solo aparece cuando el token ya es válido.
                'nombre' => null,
            ],
        ]);
    }

    /** Un lector revocado deja de ser reconocido, sin que cambie su token. */
    public function test_un_lector_desactivado_no_es_reconocido(): void
    {
        $this->dispositivo->update(['activo' => false]);

        $this->getJson(self::RUTA, [
            'X-Dispositivo' => 'lector-entrada',
            'X-Dispositivo-Token' => self::TOKEN,
        ])->assertOk()->assertJsonPath('dispositivo.reconocido', false);
    }

    /** El ping sigue siendo diagnóstico: nunca devuelve el token ni su hash. */
    public function test_el_ping_nunca_devuelve_el_token(): void
    {
        $respuesta = $this->getJson(self::RUTA, [
            'X-Dispositivo' => 'lector-entrada',
            'X-Dispositivo-Token' => self::TOKEN,
        ])->assertOk();

        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());
        $this->assertStringNotContainsString($this->dispositivo->token_hash, $respuesta->getContent());
    }

    /**
     * El ping NO autentica: aunque la credencial sea mala, sigue contestando la
     * hora. Es justo lo que lo hace útil para diagnosticar (separa «no llego al
     * servidor» de «mi token está mal») y por lo que no puede escribir nada.
     */
    public function test_aun_con_credencial_invalida_el_ping_responde_la_hora(): void
    {
        $this->getJson(self::RUTA, [
            'X-Dispositivo' => 'lector-entrada',
            'X-Dispositivo-Token' => 'token-equivocado',
        ])->assertOk()->assertJsonStructure([
            'ok',
            'mensaje',
            'servidor' => ['fecha', 'hora', 'fecha_hora', 'iso8601', 'epoch', 'zona'],
        ]);
    }
}
