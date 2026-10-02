<?php

namespace Tests\Feature\Ppq;

use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Exceptions\Ppq\GmailNoDisponibleException;
use App\Models\GmailCuenta;
use App\Services\Ppq\GmailClient;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cuándo se desconecta la cuenta de Gmail y cuándo no.
 *
 * Borrar el refresh_token obliga a reconectar a mano, así que solo debe pasar
 * cuando Google responde `invalid_grant`. Una red caída, un `invalid_client`, un
 * 403 por cuota o un 401 de un token que el reloj creía vigente dejan la cuenta
 * intacta y se informan con GmailNoDisponibleException.
 *
 * Ninguna prueba sale a la red: el cliente de Google usa un Guzzle con
 * respuestas encoladas.
 */
class GmailRenovacionTokenTest extends TestCase
{
    use RefreshDatabase;

    private const REFRESH = '1//refresh-de-prueba';

    private MockHandler $respuestas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->respuestas = new MockHandler;
    }

    public function test_red_caida_al_renovar_no_desconecta_la_cuenta(): void
    {
        $this->cuenta(vencido: true);
        $this->respuestas->append(new ConnectException('sin red', new Request('POST', 'https://oauth2.googleapis.com/token')));

        $this->assertLanza(GmailNoDisponibleException::class, fn () => $this->cliente()->perfil());
        $this->assertSame(self::REFRESH, GmailCuenta::actual()->refresh_token);
    }

    public function test_invalid_grant_al_renovar_desconecta_la_cuenta(): void
    {
        $this->cuenta(vencido: true);
        $this->respuestas->append($this->respuestaJson(400, ['error' => 'invalid_grant']));

        $e = $this->assertLanza(GmailDesconectadoException::class, fn () => $this->cliente()->perfil());
        $this->assertNotInstanceOf(GmailNoDisponibleException::class, $e);
        $this->assertFalse(GmailCuenta::actual()->conectada());
    }

    public function test_otro_error_al_renovar_no_desconecta_la_cuenta(): void
    {
        $this->cuenta(vencido: true);
        $this->respuestas->append($this->respuestaJson(401, ['error' => 'invalid_client']));

        $this->assertLanza(GmailNoDisponibleException::class, fn () => $this->cliente()->perfil());
        $this->assertSame(self::REFRESH, GmailCuenta::actual()->refresh_token);
    }

    public function test_un_401_de_la_api_renueva_y_reintenta_una_vez(): void
    {
        $this->cuenta(vencido: false);
        $this->respuestas->append(
            $this->respuestaJson(401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials']]),
            $this->respuestaJson(200, ['access_token' => 'ya29.nuevo', 'expires_in' => 3600]),
            $this->respuestaJson(200, ['emailAddress' => 'ppq@ejemplo.com', 'messagesTotal' => 7]),
        );

        $perfil = $this->cliente()->perfil();

        $this->assertSame('ppq@ejemplo.com', $perfil['email']);
        $cuenta = GmailCuenta::actual();
        $this->assertSame(self::REFRESH, $cuenta->refresh_token);
        $this->assertSame('ya29.nuevo', json_decode($cuenta->access_token, true)['access_token']);
    }

    public function test_un_403_de_la_api_no_desconecta_la_cuenta(): void
    {
        $this->cuenta(vencido: false);
        $this->respuestas->append($this->respuestaJson(403, ['error' => [
            'code' => 403,
            'message' => 'Quota exceeded',
            'errors' => [['reason' => 'rateLimitExceeded']],
        ]]));

        $e = $this->assertLanza(GmailNoDisponibleException::class, fn () => $this->cliente()->perfil());
        $this->assertStringContainsString('rateLimitExceeded', $e->getMessage());
        $this->assertSame(self::REFRESH, GmailCuenta::actual()->refresh_token);
    }

    private function cuenta(bool $vencido): void
    {
        GmailCuenta::create([
            'email' => 'ppq@ejemplo.com',
            'access_token' => json_encode([
                'access_token' => 'ya29.viejo',
                'expires_in' => 3600,
                'created' => $vencido ? time() - 7200 : time(),
            ]),
            'refresh_token' => self::REFRESH,
            'scopes' => 'gmail.readonly',
        ]);
    }

    private function cliente(): GmailClient
    {
        $http = new GuzzleClient([
            'handler' => HandlerStack::create($this->respuestas),
            'http_errors' => false, // igual que el cliente por defecto de Google
        ]);

        return new class($http) extends GmailClient
        {
            public function __construct(private GuzzleClient $http)
            {
                parent::__construct();
            }

            protected function clienteBase(): GoogleClient
            {
                $client = parent::clienteBase();
                $client->setClientId('123-abc.apps.googleusercontent.com');
                $client->setRedirectUri('https://facturacion.test/ppq/gmail/callback');
                $client->setHttpClient($this->http);

                return $client;
            }
        };
    }

    /** @param  array<string, mixed>  $cuerpo */
    private function respuestaJson(int $estado, array $cuerpo): Response
    {
        return new Response($estado, ['Content-Type' => 'application/json'], json_encode($cuerpo));
    }

    /**
     * @template T of \Throwable
     *
     * @param  class-string<T>  $clase
     * @return T
     */
    private function assertLanza(string $clase, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($clase, $e);

            return $e;
        }
        $this->fail("Se esperaba {$clase}.");
    }
}
