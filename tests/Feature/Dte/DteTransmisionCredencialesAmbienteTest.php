<?php

namespace Tests\Feature\Dte;

use App\Exceptions\Dte\DteTransmisionException;
use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Producción y apitest/homologación son cuentas DISTINTAS en Hacienda. Este
 * archivo cubre específicamente la selección de credenciales por ambiente en
 * DteTransmisionAuthService: producción usa DTE_PROD_* (con respaldo temporal a
 * DTE_TRANSMISION_USER/PASSWORD), testing usa EXCLUSIVAMENTE DTE_TEST_* sin
 * respaldo nunca. NUNCA se hace login real (siempre Http::fake) y NUNCA se
 * imprime usuario/contraseña/token.
 */
class DteTransmisionCredencialesAmbienteTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzUxMiJ9.TOKEN_FAKE_NO_REAL.firma';

    private const PW_PROD = 'PASSWORD_PRODUCCION_QUE_NO_DEBE_APARECER';

    private const PW_TEST = 'PASSWORD_TESTING_QUE_NO_DEBE_APARECER';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.token', ''); // sin override → fuerza login por credenciales
    }

    private function auth(): DteTransmisionAuthService
    {
        return app(DteTransmisionAuthService::class);
    }

    private function fakeAuthOk(): void
    {
        Http::fake(['*/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer '.self::TOKEN]], 200)]);
    }

    // --- Producción usa credenciales productivas nuevas ---

    public function test_produccion_usa_credenciales_dte_prod(): void
    {
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        config()->set('dte.transmision.usuario_testing', ''); // irrelevante en produccion
        config()->set('dte.transmision.password_testing', '');
        $this->fakeAuthOk();

        $this->auth()->obtenerToken();

        Http::assertSent(fn ($r) => ($r->data()['user'] ?? null) === 'usuario_prod_real'
            && ($r->data()['pwd'] ?? null) === self::PW_PROD);
    }

    // --- Producción mantiene el fallback legacy temporal ---

    /**
     * El fallback vive en la CONSTRUCCIÓN de config/dte.php (env('DTE_PROD_USER',
     * env('DTE_TRANSMISION_USER', ''))): config()->set() pisaría el valor ya resuelto
     * y no probaría nada real, y putenv() no altera lo que env() ya resolvió en el
     * arranque (Laravel cachea el snapshot de .env). Se verifica directamente que el
     * archivo de config declara el fallback tal como se pidió.
     */
    public function test_config_produccion_declara_fallback_a_legacy(): void
    {
        $fuente = file_get_contents(config_path('dte.php'));

        $this->assertStringContainsString(
            "'usuario_produccion' => env('DTE_PROD_USER', env('DTE_TRANSMISION_USER', ''))",
            $fuente
        );
        $this->assertStringContainsString(
            "'password_produccion' => env('DTE_PROD_PASSWORD', env('DTE_TRANSMISION_PASSWORD', ''))",
            $fuente
        );
        $this->assertStringContainsString(
            "'usuario_testing' => env('DTE_TEST_USER', '')",
            $fuente
        );
        $this->assertStringContainsString(
            "'password_testing' => env('DTE_TEST_PASSWORD', '')",
            $fuente
        );
    }

    /** El valor YA RESUELTO en el proceso actual de test coincide con el legacy (ambos vienen del mismo .env real, sin DTE_PROD_* definido ahí). */
    public function test_produccion_config_resuelto_coincide_con_legacy_cuando_dte_prod_no_esta_en_env(): void
    {
        $this->assertSame(config('dte.transmision.usuario_api'), config('dte.transmision.usuario_produccion'));
        $this->assertSame(config('dte.transmision.password'), config('dte.transmision.password_produccion'));
    }

    // --- Testing usa solo credenciales testing ---

    public function test_testing_usa_credenciales_dte_test(): void
    {
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.usuario_testing', 'usuario_apitest');
        config()->set('dte.transmision.password_testing', self::PW_TEST);
        // Credenciales de producción configuradas también, para probar que NO se usan.
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        $this->fakeAuthOk();

        $this->auth()->obtenerToken();

        Http::assertSent(fn ($r) => ($r->data()['user'] ?? null) === 'usuario_apitest'
            && ($r->data()['pwd'] ?? null) === self::PW_TEST);
    }

    // --- Testing vacío falla antes de HTTP, con el mensaje exacto ---

    public function test_testing_sin_credenciales_falla_antes_de_http_con_mensaje_exacto(): void
    {
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.usuario_testing', '');
        config()->set('dte.transmision.password_testing', '');
        Http::fake();

        try {
            $this->auth()->obtenerToken();
            $this->fail('Debió lanzar DteTransmisionException.');
        } catch (DteTransmisionException $e) {
            $this->assertSame('Credenciales de apitest/homologación no configuradas.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // --- Testing JAMÁS cae a credenciales productivas, aunque estén configuradas ---

    public function test_testing_con_solo_usuario_vacio_no_cae_a_produccion(): void
    {
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.usuario_testing', '');
        config()->set('dte.transmision.password_testing', self::PW_TEST);
        // Producción SÍ configurada — no debe usarse.
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        Http::fake();

        try {
            $this->auth()->obtenerToken();
            $this->fail('Debió lanzar DteTransmisionException.');
        } catch (DteTransmisionException $e) {
            $this->assertSame('Credenciales de apitest/homologación no configuradas.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_pruebaAuthTesting_reporta_bloqueado_sin_credenciales_con_mensaje_exacto(): void
    {
        config()->set('dte.transmision.auth_test_real_enabled', true);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.usuario_testing', '');
        config()->set('dte.transmision.password_testing', '');
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        Http::fake();

        $r = $this->auth()->pruebaAuthTesting();

        $this->assertTrue($r['bloqueado']);
        $this->assertSame('Credenciales de apitest/homologación no configuradas.', $r['razon']);
        $this->assertFalse($r['usuario_configurado']);
        $this->assertFalse($r['password_configurado']);
        Http::assertNothingSent();
    }

    // --- Diagnóstico / inspección: reflejan el par correcto por ambiente ---

    public function test_diagnostico_refleja_credenciales_testing_en_ambiente_testing(): void
    {
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.usuario_testing', 'usuario_apitest');
        config()->set('dte.transmision.password_testing', self::PW_TEST);
        config()->set('dte.transmision.usuario_produccion', '');
        config()->set('dte.transmision.password_produccion', '');

        $d = $this->auth()->diagnostico();

        $this->assertSame('testing', $d['ambiente']);
        $this->assertTrue($d['usuario_configurado']);
        $this->assertTrue($d['password_configurado']);
    }

    public function test_diagnostico_refleja_credenciales_produccion_en_ambiente_produccion(): void
    {
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        config()->set('dte.transmision.usuario_testing', '');
        config()->set('dte.transmision.password_testing', '');

        $d = $this->auth()->diagnostico();

        $this->assertSame('produccion', $d['ambiente']);
        $this->assertTrue($d['usuario_configurado']);
        $this->assertTrue($d['password_configurado']);
    }

    // --- Nunca se imprimen secretos, en ningún comando ---

    public function test_comandos_no_imprimen_usuario_password_ni_token(): void
    {
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.usuario_produccion', 'usuario_prod_real');
        config()->set('dte.transmision.password_produccion', self::PW_PROD);
        config()->set('dte.transmision.usuario_testing', 'usuario_apitest');
        config()->set('dte.transmision.password_testing', self::PW_TEST);

        $this->artisan('dte:auth-check')
            ->doesntExpectOutputToContain('usuario_prod_real')
            ->doesntExpectOutputToContain(self::PW_PROD)
            ->doesntExpectOutputToContain('usuario_apitest')
            ->doesntExpectOutputToContain(self::PW_TEST);

        $this->artisan('dte:auth-inspect')
            ->doesntExpectOutputToContain('usuario_prod_real')
            ->doesntExpectOutputToContain(self::PW_PROD);
    }
}
