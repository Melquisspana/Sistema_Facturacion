<?php

namespace Tests\Feature\Dte;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Autenticación de transmisión: bloqueada por defecto y probada solo con Http::fake.
 * NUNCA se autentica contra Hacienda real ni se imprime usuario/contraseña/token.
 */
class DteTransmisionAuthTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzUxMiJ9.TOKEN_FAKE_NO_REAL.firma';

    private const PW = 'PASSWORD_QUE_NO_DEBE_APARECER';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function auth(): DteTransmisionAuthService
    {
        return app(DteTransmisionAuthService::class);
    }

    /** Habilita auth con credenciales de PRUEBA (no reales) vía config (no .env). */
    private function habilitar(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://auth.test');
        config()->set('dte.transmision.token', '');       // sin override → fuerza login
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', self::PW);
    }

    private function fakeAuthOk(): void
    {
        Http::fake(['*/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer '.self::TOKEN]], 200)]);
    }

    // --- Bloqueo ---

    public function test_bloquea_con_enabled_false_antes_de_http(): void
    {
        config()->set('dte.transmision.enabled', false);
        Http::fake();

        try {
            $this->auth()->obtenerToken();
            $this->fail('Debió lanzar DteTransmisionDeshabilitadaException.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString('No se autenticó', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // --- Login OK ---

    public function test_auth_ok_con_http_fake(): void
    {
        $this->habilitar();
        $this->fakeAuthOk();

        $token = $this->auth()->obtenerToken();

        $this->assertSame('Bearer '.self::TOKEN, $token);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/seguridad/auth')
            && ($r->data()['user'] ?? null) === 'facturador01');
    }

    public function test_normaliza_token_sin_bearer(): void
    {
        $this->habilitar();
        Http::fake(['*/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => self::TOKEN]], 200)]);

        $token = $this->auth()->obtenerToken();

        $this->assertSame('Bearer '.self::TOKEN, $token); // se antepone Bearer
    }

    // --- Fallos controlados ---

    public function test_falla_si_falta_user(): void
    {
        $this->habilitar();
        config()->set('dte.transmision.usuario_api', '');
        Http::fake();

        $this->expectException(DteTransmisionException::class);
        $this->auth()->obtenerToken();
    }

    public function test_falla_si_falta_password(): void
    {
        $this->habilitar();
        config()->set('dte.transmision.password', '');
        Http::fake();

        $this->expectException(DteTransmisionException::class);
        $this->auth()->obtenerToken();
    }

    public function test_falla_si_respuesta_sin_token(): void
    {
        $this->habilitar();
        Http::fake(['*/seguridad/auth' => Http::response(['status' => 'OK', 'body' => []], 200)]);

        $this->expectException(DteTransmisionException::class);
        $this->auth()->obtenerToken();
    }

    public function test_maneja_http_401(): void
    {
        $this->habilitar();
        Http::fake(['*/seguridad/auth' => Http::response(['status' => 'ERROR', 'message' => 'Usuario no valido'], 401)]);

        $this->expectException(DteTransmisionException::class);
        $this->auth()->obtenerToken();
    }

    public function test_maneja_respuesta_malformada(): void
    {
        $this->habilitar();
        Http::fake(['*/seguridad/auth' => Http::response('no es json', 200)]);

        $this->expectException(DteTransmisionException::class);
        $this->auth()->obtenerToken();
    }

    // --- Cache ---

    public function test_cachea_token_sin_imprimirlo(): void
    {
        $this->habilitar();
        $this->fakeAuthOk();

        $t1 = $this->auth()->obtenerToken();
        $t2 = $this->auth()->obtenerToken(); // segundo desde cache

        $this->assertSame($t1, $t2);
        Http::assertSentCount(1); // solo una autenticación real
        $this->assertIsString(Cache::get('dte.transmision.token.test'));
    }

    // --- Comandos (no muestran secretos) ---

    public function test_auth_check_no_muestra_secretos(): void
    {
        config()->set('dte.transmision.enabled', false);
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', self::PW);

        $this->artisan('dte:auth-check')
            ->doesntExpectOutputToContain(self::PW)
            ->doesntExpectOutputToContain('facturador01')
            ->expectsOutputToContain('NO AUTENTICA / SOLO DIAGNÓSTICO')
            ->assertExitCode(0);
    }

    /** Habilita la prueba de auth real SOLO testing (apitest), con credenciales fake. */
    private function habilitarAuthTesting(): void
    {
        config()->set('dte.transmision.auth_test_real_enabled', true);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', self::PW);
        config()->set('dte.transmision.token', ''); // sin override → login
    }

    public function test_auth_test_bloqueado_si_flag_false(): void
    {
        config()->set('dte.transmision.auth_test_real_enabled', false);
        Http::fake();

        $this->artisan('dte:auth-test')
            ->expectsOutputToContain('DTE_AUTH_TEST_REAL_ENABLED=false')
            ->expectsOutputToContain('NO SE TRANSMITIÓ NINGÚN DTE')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_auth_test_bloqueado_si_produccion(): void
    {
        $this->habilitarAuthTesting();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.url_base', 'https://api.dtes.mh.gob.sv');
        Http::fake();

        $this->artisan('dte:auth-test')
            ->expectsOutputToContain('producción no permitido')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_auth_test_bloqueado_si_url_no_es_apitest(): void
    {
        $this->habilitarAuthTesting();
        config()->set('dte.transmision.url_base', 'https://otro-host.test');
        Http::fake();

        $this->artisan('dte:auth-test')
            ->expectsOutputToContain('no es el ambiente de pruebas')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_auth_test_obtiene_token_fake_y_no_lo_imprime(): void
    {
        $this->habilitarAuthTesting();
        $this->fakeAuthOk();

        $this->artisan('dte:auth-test')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->doesntExpectOutputToContain(self::PW)
            ->expectsOutputToContain('Token obtenido correctamente')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/seguridad/auth'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/fesv/recepciondte'));
    }

    public function test_auth_check_muestra_flag_auth_test_real(): void
    {
        config()->set('dte.transmision.auth_test_real_enabled', false);

        $this->artisan('dte:auth-check')
            ->expectsOutputToContain('DTE_AUTH_TEST_REAL_ENABLED')
            ->assertExitCode(0);
    }
}
