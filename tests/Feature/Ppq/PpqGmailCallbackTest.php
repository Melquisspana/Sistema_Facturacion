<?php

namespace Tests\Feature\Ppq;

use App\Models\GmailCuenta;
use App\Models\User;
use App\Services\Ppq\GmailClient;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El regreso de Google al conectar Gmail (`ppq.gmail.callback`), con el GmailClient de
 * verdad: solo se reemplaza la red por respuestas guardadas. Se mira lo que queda en
 * `gmail_cuentas`, que el token quede cifrado y que con un `state` ajeno no se guarde nada.
 */
class PpqGmailCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS = 'ya29.token-de-prueba';

    private const REFRESH = '1//refresh-de-prueba';

    private MockHandler $google;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ppq.gmail.enabled' => true,
            'ppq.gmail.client_id' => 'client-prueba',
            'ppq.gmail.client_secret' => 'secret-prueba',
            'ppq.gmail.redirect_uri' => 'https://facturacion.test/ppq/gmail/callback',
        ]);

        $this->google = new MockHandler($this->respuestasDeGoogle(self::ACCESS));
        $this->app->instance(GmailClient::class, new class($this->google) extends GmailClient
        {
            public function __construct(private readonly MockHandler $respuestas)
            {
                parent::__construct();
            }

            protected function clienteBase(): GoogleClient
            {
                $cliente = parent::clienteBase();
                $cliente->setHttpClient(new GuzzleClient(['handler' => HandlerStack::create($this->respuestas)]));

                return $cliente;
            }
        });
    }

    /** Google contesta el canje del código y después el perfil de la cuenta. */
    private function respuestasDeGoogle(string $access): array
    {
        return [
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => $access, 'expires_in' => 3599, 'refresh_token' => self::REFRESH,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/drive.file',
                'token_type' => 'Bearer',
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'emailAddress' => 'cuenta@example.com', 'messagesTotal' => 10,
            ])),
        ];
    }

    private function administrador(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function volverDeGoogle(User $usuario, array $query, string $stateDeLaSesion = 'state-de-esta-sesion')
    {
        return $this->actingAs($usuario)->withSession(['ppq_gmail_oauth_state' => $stateDeLaSesion])
            ->get(route('ppq.gmail.callback', $query));
    }

    public function test_con_un_codigo_valido_guarda_la_cuenta_y_el_token_cifrado(): void
    {
        $admin = $this->administrador();

        $respuesta = $this->volverDeGoogle($admin, ['code' => 'codigo-de-prueba', 'state' => 'state-de-esta-sesion'])
            ->assertRedirect(route('ppq.index'))
            ->assertSessionHas('status', 'Gmail conectado (cuenta@example.com). Ya podés buscar CCF.');

        $cuenta = GmailCuenta::sole();
        $this->assertSame('cuenta@example.com', $cuenta->email);
        $this->assertSame($admin->id, $cuenta->conectado_por);
        $this->assertSame(self::REFRESH, $cuenta->refresh_token);
        $this->assertSame(self::ACCESS, json_decode($cuenta->access_token, true)['access_token']);
        $this->assertTrue($cuenta->tienePermisoDrive());
        $this->assertTrue($cuenta->expires_at->isFuture());

        // En la base no queda ningún token legible.
        $crudo = (array) DB::table('gmail_cuentas')->where('id', $cuenta->id)->first(['access_token', 'refresh_token']);
        $this->assertStringNotContainsString(self::ACCESS, $crudo['access_token']);
        $this->assertStringNotContainsString(self::REFRESH, $crudo['refresh_token']);
        $this->assertStringNotContainsString(self::ACCESS, (string) $respuesta->getContent());
        $this->assertSame(0, $this->google->count(), 'Se usaron las dos respuestas de Google.');
    }

    /**
     * Desconectar ya dejaba rastro; conectar no. Al reconectar con otra cuenta, la fila se
     * pisa y se perdía qué buzón tuvo acceso antes y quién lo autorizó.
     */
    public function test_conectar_queda_en_la_auditoria_con_la_cuenta_y_sin_los_tokens(): void
    {
        $admin = $this->administrador();

        $this->volverDeGoogle($admin, ['code' => 'codigo-de-prueba', 'state' => 'state-de-esta-sesion'])->assertSessionHas('status');

        $registro = Activity::where('log_name', 'ajustes')->sole();
        $this->assertSame('conectó Gmail (cuenta@example.com)', $registro->description);
        $this->assertSame($admin->id, $registro->causer_id);
        $this->assertSame('integracion_conectada', $registro->properties['accion']);
        $this->assertSame('cuenta@example.com', $registro->properties['cuenta']);
        $todo = json_encode($registro->getAttributes());
        $this->assertStringNotContainsString(self::ACCESS, $todo);
        $this->assertStringNotContainsString(self::REFRESH, $todo);
    }

    public function test_con_un_state_ajeno_no_se_guarda_nada_ni_se_llama_a_google(): void
    {
        $this->volverDeGoogle($this->administrador(), ['code' => 'codigo-ajeno', 'state' => 'state-de-otro'])
            ->assertRedirect(route('ppq.index'))->assertSessionHas('error');

        $this->assertSame(0, GmailCuenta::count());
        $this->assertSame(2, $this->google->count(), 'El código ajeno nunca se canjea.');
        $this->assertSame(0, Activity::where('log_name', 'ajustes')->count());
    }

    public function test_sin_state_en_la_sesion_no_se_guarda_nada(): void
    {
        $this->actingAs($this->administrador())
            ->get(route('ppq.gmail.callback', ['code' => 'codigo-de-prueba', 'state' => '']))
            ->assertRedirect(route('ppq.index'))->assertSessionHas('error');

        $this->assertSame(0, GmailCuenta::count());
    }

    public function test_el_mismo_regreso_no_sirve_dos_veces(): void
    {
        $admin = $this->administrador();
        $this->volverDeGoogle($admin, ['code' => 'codigo-de-prueba', 'state' => 'state-de-esta-sesion'])->assertSessionHas('status');

        // El state se consumió: repetir la URL no vuelve a canjear nada, aunque Google respondiera.
        $this->google->append(...$this->respuestasDeGoogle('ya29.otro-token'));
        $this->actingAs($admin)->get(route('ppq.gmail.callback', ['code' => 'codigo-de-prueba', 'state' => 'state-de-esta-sesion']))
            ->assertSessionHas('error');

        $this->assertSame(2, $this->google->count());
        $this->assertSame(self::ACCESS, json_decode(GmailCuenta::sole()->access_token, true)['access_token']);
    }

    public function test_si_google_rechaza_el_codigo_no_se_guarda_nada(): void
    {
        $this->google->reset();
        $this->google->append(new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant'])));

        $this->volverDeGoogle($this->administrador(), ['code' => 'codigo-vencido', 'state' => 'state-de-esta-sesion'])
            ->assertRedirect(route('ppq.index'))
            ->assertSessionHas('error', 'No se pudo conectar Gmail y Drive. Revisá la configuración de Google y volvé a autorizar.');

        $this->assertSame(0, GmailCuenta::count());
    }

    public function test_si_el_usuario_cancela_en_google_no_se_guarda_nada(): void
    {
        $this->volverDeGoogle($this->administrador(), ['error' => 'access_denied', 'state' => 'state-de-esta-sesion'])
            ->assertSessionHas('error', 'Autorización de Gmail cancelada.');

        $this->assertSame(0, GmailCuenta::count());
        $this->assertSame(2, $this->google->count());
    }

    public function test_un_rol_sin_permiso_de_gmail_recibe_403_y_no_se_guarda_nada(): void
    {
        foreach (['facturacion', 'jefatura', 'contabilidad'] as $rol) {
            $this->volverDeGoogle(User::factory()->create()->assignRole($rol), ['code' => 'codigo-de-prueba', 'state' => 'state-de-esta-sesion'])
                ->assertForbidden();
        }

        $this->assertSame(0, GmailCuenta::count());
        $this->assertSame(2, $this->google->count());
    }
}
