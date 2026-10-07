<?php

namespace Tests\Feature\Contabilidad;

use App\Models\GmailCuenta;
use App\Services\Contabilidad\SubidaDrivePaquete;
use App\Services\Ppq\GmailClient;
use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubidaDrivePaqueteTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_carpetas_sube_por_trozos_y_comparte_solo_con_contabilidad(): void
    {
        $this->subida(false);
    }

    public function test_reintento_actualiza_archivo_y_reutiliza_carpetas_y_permiso(): void
    {
        $this->subida(true);
    }

    /** Un correo sin cuenta de Google no se puede compartir sin el aviso de Drive. */
    public function test_un_correo_sin_cuenta_de_google_se_comparte_con_aviso_de_drive(): void
    {
        $this->subida(false, sinCuentaGoogle: true);
    }

    private function subida(bool $existente, bool $sinCuentaGoogle = false): void
    {
        GmailCuenta::create(['email' => 'emisor@example.com', 'scopes' => Drive::DRIVE_FILE]);
        $respuestas = [];
        foreach (['raiz', 'anio', 'mes'] as $id) {
            $respuestas[] = $this->respuestaJson(['files' => $existente ? [['id' => $id]] : []]);
            if (! $existente) {
                $respuestas[] = $this->respuestaJson(['id' => $id]);
            }
        }
        $respuestas[] = $this->respuestaJson(['files' => $existente ? [['id' => 'zip']] : []]);
        $respuestas[] = new Response(200, ['Location' => 'https://upload.example.com/session']);
        $respuestas[] = new Response(308, ['Range' => 'bytes=0-8388607']);
        $respuestas[] = $this->respuestaJson(['id' => 'zip']);
        $respuestas[] = $this->respuestaJson(['permissions' => $existente
            ? [['id' => 'dueno', 'role' => 'owner', 'type' => 'user'], ['id' => 'lector', 'role' => 'reader', 'type' => 'user', 'emailAddress' => 'contabilidad@example.com'], ['id' => 'publico', 'role' => 'reader', 'type' => 'anyone']]
            : [['id' => 'dueno', 'role' => 'owner', 'type' => 'user']]]);
        if ($sinCuentaGoogle) {
            $respuestas[] = new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => 400, 'message' => 'Notify people required']]));
        }
        $respuestas[] = $existente ? new Response(204) : $this->respuestaJson(['id' => 'lector']);
        $respuestas[] = $this->respuestaJson(['id' => 'zip', 'webViewLink' => 'https://drive.google.com/file/d/zip/view']);
        $historial = [];
        $stack = HandlerStack::create(new MockHandler($respuestas));
        $stack->push(Middleware::history($historial));
        $client = new Client;
        $client->setHttpClient(new HttpClient(['handler' => $stack, 'http_errors' => false]));
        $gmail = \Mockery::mock(GmailClient::class);
        $gmail->shouldReceive('clienteAutenticado')->once()->andReturn($client);
        $ruta = tempnam(sys_get_temp_dir(), 'drive-test-');
        $archivo = fopen($ruta, 'wb');
        for ($i = 0; $i < 9; $i++) {
            fwrite($archivo, str_repeat('z', 1024 * 1024));
        }
        fclose($archivo);
        try {
            $resultado = (new SubidaDrivePaquete($gmail))->subir($ruta, 'paquete.zip', 2026, 9, 'contabilidad@example.com');
        } finally {
            unlink($ruta);
        }
        $this->assertSame(['id' => 'zip', 'webViewLink' => 'https://drive.google.com/file/d/zip/view'], $resultado);
        $this->assertFalse($client->shouldDefer());
        $trozos = array_values(array_filter($historial, fn ($r) => $r['request']->getMethod() === 'PUT'));
        $this->assertCount(2, $trozos);
        $this->assertSame(8 * 1024 * 1024, $trozos[0]['request']->getBody()->getSize());
        $this->assertSame(1024 * 1024, $trozos[1]['request']->getBody()->getSize());
        $this->assertSame('bytes 8388608-9437183/9437184', $trozos[1]['request']->getHeaderLine('content-range'));
        $inicio = array_values(array_filter($historial, fn ($r) => str_contains((string) $r['request']->getUri(), '/upload/')))[0]['request'];
        $this->assertSame($existente ? 'PATCH' : 'POST', $inicio->getMethod());
        if ($existente) {
            $this->assertStringContainsString('/files/zip', (string) $inicio->getUri());
            $borrados = array_values(array_filter($historial, fn ($r) => $r['request']->getMethod() === 'DELETE'));
            $this->assertCount(1, $borrados);
            $this->assertStringContainsString('/permissions/publico', (string) $borrados[0]['request']->getUri());
        } else {
            $creados = array_values(array_filter($historial, fn ($r) => $r['request']->getMethod() === 'POST' && str_contains((string) $r['request']->getUri(), '/permissions')));
            $this->assertCount($sinCuentaGoogle ? 2 : 1, $creados);
            $this->assertEquals(['type' => 'user', 'role' => 'reader', 'emailAddress' => 'contabilidad@example.com'], json_decode((string) $creados[0]['request']->getBody(), true));
            $this->assertStringContainsString('sendNotificationEmail=false', (string) $creados[0]['request']->getUri());
            if ($sinCuentaGoogle) {
                $this->assertStringContainsString('sendNotificationEmail=true', (string) $creados[1]['request']->getUri());
            }
            $carpetas = array_values(array_filter($historial, fn ($r) => $r['request']->getMethod() === 'POST' && ! str_contains((string) $r['request']->getUri(), '/upload/') && ! str_contains((string) $r['request']->getUri(), '/permissions')));
            $this->assertSame(['Paquetes contabilidad', '2026', '09'], array_map(fn ($r) => json_decode((string) $r['request']->getBody(), true)['name'], $carpetas));
        }
    }

    private function respuestaJson(array $datos): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($datos));
    }
}
