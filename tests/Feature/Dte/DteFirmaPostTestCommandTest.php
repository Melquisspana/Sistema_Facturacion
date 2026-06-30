<?php

namespace Tests\Feature\Dte;

use App\Models\Dte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prueba CONTROLADA del POST del firmador con payload FAKE. No firma ningún DTE
 * real, no toca BD, no usa certificados ni contraseñas reales. Un error
 * controlado del firmador se interpreta como "endpoint disponible".
 */
class DteFirmaPostTestCommandTest extends TestCase
{
    use RefreshDatabase;

    /** El firmador responde con un error controlado (sin certificado para el NIT). */
    private function fakeErrorControlado(): void
    {
        Http::fake([
            '*/firmardocumento/' => Http::response([
                'status' => 'ERROR',
                'body' => ['codigo' => '803', 'mensaje' => 'No existe llave publica para este nit'],
            ], 200),
        ]);
    }

    public function test_comando_hace_post_fake_con_http_fake(): void
    {
        $this->fakeErrorControlado();

        $this->artisan('dte:firma-post-test')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $d = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/firmardocumento/')
                && ($d['nit'] ?? null) === '00000000000000'
                && ($d['passwordPri'] ?? null) === 'FAKE_PASSWORD_NO_REAL'
                && is_array($d['dteJson'] ?? null);
        });
    }

    public function test_error_controlado_se_interpreta_como_endpoint_disponible(): void
    {
        $this->fakeErrorControlado();

        $this->artisan('dte:firma-post-test')
            ->expectsOutputToContain('Endpoint VIVO: procesó el POST y devolvió un error controlado')
            ->expectsOutputToContain('NO SE FIRMÓ NINGÚN DTE')
            ->assertExitCode(0);
    }

    public function test_no_modifica_bd_ni_toca_dte(): void
    {
        $this->fakeErrorControlado();

        $this->artisan('dte:firma-post-test')->assertExitCode(0);

        // No creó ni leyó ningún DTE.
        $this->assertSame(0, Dte::count());
    }

    public function test_no_firma_ni_envia_datos_reales(): void
    {
        $this->fakeErrorControlado();

        $this->artisan('dte:firma-post-test')->assertExitCode(0);

        // El payload es claramente de prueba: nada real, password fake, dteJson inventado.
        Http::assertSent(function ($request) {
            $d = $request->data();

            return ($d['passwordPri'] ?? null) === 'FAKE_PASSWORD_NO_REAL'
                && str_contains(($d['dteJson']['_prueba'] ?? ''), 'NO ES UN DTE REAL');
        });
        // El firmador NO devolvió OK → no se firmó nada.
        Http::assertNotSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_conexion_rechazada_reporta_no_disponible(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('dte:firma-post-test')
            ->expectsOutputToContain('NO respondió')
            ->assertExitCode(1);
    }

    public function test_firmador_ok_inesperado_advierte(): void
    {
        // Si por error el firmador firmara con datos fake, el comando lo advierte.
        Http::fake([
            '*/firmardocumento/' => Http::response(['status' => 'OK', 'body' => 'eyJ.fake.jws'], 200),
        ]);

        $this->artisan('dte:firma-post-test')
            ->expectsOutputToContain('inesperado')
            ->assertExitCode(0);
    }
}
