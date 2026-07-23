<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\CloudflareAccessJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSO con Cloudflare Access. NUNCA hace HTTP real (Http::fake +
 * preventStrayRequests): el JWT se firma en el propio test con un par RSA
 * generado al vuelo y el endpoint de certificados se simula con el certificado
 * autofirmado correspondiente. Cubre validación criptográfica (firma, issuer,
 * audience, exp, nbf, algoritmo), gating por host/HTTPS/config, mapeo a usuario
 * local (existente/activo, sin auto-creación), fail closed ante fallos de red,
 * caché de certificados, ruta raíz y logout completo.
 */
class CloudflareAccessSsoTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'facturacion.dulceslanegrita.com';
    private const TEAM = 'equipo-test.cloudflareaccess.com';
    private const AUD = 'aud-de-prueba-1234567890abcdef';
    private const KID = 'kid-de-prueba';

    /** @var \OpenSSLAsymmetricKey */
    private $clavePrivada;

    private string $certificadoPem;

    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de esta clase debe salir a Internet jamás.
        Http::preventStrayRequests();

        [$this->clavePrivada, $this->certificadoPem] = $this->generarClaveYCertificado();

        config([
            'cloudflare_access.enabled' => true,
            'cloudflare_access.team_domain' => self::TEAM,
            'cloudflare_access.aud' => self::AUD,
            'cloudflare_access.auto_login' => true,
            'cloudflare_access.allowed_host' => self::HOST,
        ]);

        $this->fakeCerts();
    }

    // ---------- Infraestructura de firma (solo en tests) ----------

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function generarClaveYCertificado(): array
    {
        // PHP-OpenSSL en Windows exige un openssl.cnf para generar claves/CSR;
        // se crea uno MÍNIMO temporal solo para el test (no toca configuración real).
        $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cf-sso-test-openssl.cnf';
        if (! is_file($cnf)) {
            file_put_contents($cnf, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
        }
        $opciones = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $clave = openssl_pkey_new($opciones);
        $this->assertNotFalse($clave, 'No se pudo generar la clave RSA de prueba: '.(string) openssl_error_string());
        $csr = openssl_csr_new(['commonName' => 'cloudflare-access-test'], $clave, $opciones);
        $this->assertNotFalse($csr, 'No se pudo generar el CSR de prueba: '.(string) openssl_error_string());
        $x509 = openssl_csr_sign($csr, null, $clave, 1, $opciones);
        $this->assertNotFalse($x509, 'No se pudo firmar el certificado de prueba: '.(string) openssl_error_string());
        openssl_x509_export($x509, $certPem);

        return [$clave, $certPem];
    }

    /**
     * Cola de respuestas simuladas para el endpoint de certs. null = responder
     * siempre con el certificado bueno; con elementos, se consumen en orden (y al
     * agotarse responde 500). Permite que un test simule caída/recuperación sin
     * apilar stubs de Http::fake (los stubs apilados no se reemplazan).
     *
     * @var array<int, mixed>|null
     */
    private ?array $certsCola = null;

    private function fakeCerts(): void
    {
        Http::fake([
            'https://'.self::TEAM.'/cdn-cgi/access/certs' => function () {
                if ($this->certsCola !== null) {
                    return array_shift($this->certsCola) ?? Http::response(null, 500);
                }

                return Http::response([
                    'public_certs' => [['kid' => self::KID, 'cert' => $this->certificadoPem]],
                ]);
            },
        ]);
    }

    private function b64(string $dato): string
    {
        return rtrim(strtr(base64_encode($dato), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $overrideClaims */
    private function jwt(array $overrideClaims = [], ?array $overrideHeader = null, mixed $clave = null): string
    {
        $header = $overrideHeader ?? ['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'];
        $claims = array_merge([
            'iss' => 'https://'.self::TEAM,
            'aud' => [self::AUD],
            'email' => 'sso@dulceslanegrita.test',
            'exp' => time() + 300,
            'iat' => time() - 10,
            'sub' => 'usuario-cf-1',
        ], $overrideClaims);

        $h = $this->b64((string) json_encode($header));
        $p = $this->b64((string) json_encode($claims));
        openssl_sign($h.'.'.$p, $firma, $clave ?? $this->clavePrivada, OPENSSL_ALGO_SHA256);

        return $h.'.'.$p.'.'.$this->b64($firma);
    }

    private function usuarioSso(array $override = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'sso@dulceslanegrita.test',
            'activo' => true,
        ], $override));
    }

    /** GET al host público (HTTPS) con el header de Access. */
    private function entrarConJwt(string $jwt, string $ruta = '/')
    {
        return $this->withHeaders(['Cf-Access-Jwt-Assertion' => $jwt])
            ->get('https://'.self::HOST.$ruta);
    }

    // ---------- Ruta raíz ----------

    public function test_invitado_local_en_raiz_va_al_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_autenticado_local_en_raiz_va_al_dashboard(): void
    {
        $this->actingAs($this->usuarioSso())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_welcome_ya_no_se_muestra_nunca(): void
    {
        $this->get('/')->assertDontSee('Laravel has wonderful documentation');
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));
    }

    // ---------- Login automático (camino feliz) ----------

    public function test_jwt_valido_con_usuario_activo_inicia_sesion_y_va_al_dashboard(): void
    {
        $usuario = $this->usuarioSso();

        $this->entrarConJwt($this->jwt())->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_acceso_sso_queda_auditado_sin_jwt(): void
    {
        $usuario = $this->usuarioSso();

        $this->entrarConJwt($this->jwt());

        $registro = \Spatie\Activitylog\Models\Activity::query()
            ->where('description', 'Inicio de sesión SSO (Cloudflare Access)')->first();
        $this->assertNotNull($registro);
        $this->assertSame($usuario->id, (int) $registro->causer_id);
        // Nunca se guarda el JWT ni fragmentos de él.
        $this->assertStringNotContainsString('eyJ', (string) json_encode($registro->properties));
    }

    // ---------- Identidad válida sin usuario habilitado ----------

    public function test_jwt_valido_con_usuario_inexistente_da_403_neutro(): void
    {
        $this->entrarConJwt($this->jwt(['email' => 'noexiste@dulceslanegrita.test']))
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_jwt_valido_con_usuario_inactivo_da_403(): void
    {
        $this->usuarioSso(['activo' => false]);

        $this->entrarConJwt($this->jwt())->assertForbidden();
        $this->assertGuest();
    }

    public function test_no_se_crean_usuarios_automaticamente(): void
    {
        $antes = User::count();

        $this->entrarConJwt($this->jwt(['email' => 'nuevo@dulceslanegrita.test']))->assertForbidden();

        $this->assertSame($antes, User::count());
    }

    public function test_email_se_compara_normalizado(): void
    {
        $usuario = $this->usuarioSso(['email' => 'sso@dulceslanegrita.test']);

        $this->entrarConJwt($this->jwt(['email' => 'SSO@DulcesLaNegrita.test']))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    // ---------- Validación criptográfica: cada rechazo es fail closed ----------

    public function test_firma_invalida_da_403(): void
    {
        $this->usuarioSso();
        [$otraClave] = $this->generarClaveYCertificado(); // clave distinta a la publicada

        $this->entrarConJwt($this->jwt(clave: $otraClave))->assertForbidden();
        $this->assertGuest();
    }

    public function test_issuer_invalido_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['iss' => 'https://otro-team.cloudflareaccess.com']))
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_audience_invalido_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['aud' => ['otra-aplicacion-access']]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_jwt_expirado_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['exp' => time() - 60]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_nbf_futuro_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['nbf' => time() + 300]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_algoritmo_distinto_de_rs256_da_403(): void
    {
        $this->usuarioSso();

        // "alg: none" y familia HMAC: rechazados sin siquiera mirar la firma.
        $jwtNone = $this->b64((string) json_encode(['alg' => 'none', 'kid' => self::KID]))
            .'.'.$this->b64((string) json_encode(['iss' => 'https://'.self::TEAM, 'aud' => [self::AUD], 'email' => 'sso@dulceslanegrita.test', 'exp' => time() + 300]))
            .'.'.$this->b64('firma-falsa');

        $this->entrarConJwt($jwtNone)->assertForbidden();
        $this->assertGuest();
    }

    public function test_email_malformado_en_el_jwt_da_403(): void
    {
        $this->entrarConJwt($this->jwt(['email' => 'esto-no-es-un-email']))->assertForbidden();
        $this->assertGuest();
    }

    public function test_certs_inaccesibles_falla_cerrado(): void
    {
        $this->usuarioSso();
        $this->certsCola = [Http::response(null, 500)];

        $this->entrarConJwt($this->jwt())->assertForbidden();
        $this->assertGuest();
    }

    // ---------- Adversariales: manipulación del token ----------

    public function test_payload_manipulado_despues_de_firmar_da_403(): void
    {
        $this->usuarioSso();
        [$h, $p, $s] = explode('.', $this->jwt());
        // Se altera el email DENTRO del payload conservando la firma original.
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['email'] = 'atacante@dulceslanegrita.test';
        $pManipulado = $this->b64((string) json_encode($claims));

        $this->entrarConJwt($h.'.'.$pManipulado.'.'.$s)->assertForbidden();
        $this->assertGuest();
    }

    public function test_issuer_con_puerto_extra_da_403(): void
    {
        $this->usuarioSso();

        // "https://team:443" != "https://team" (comparación EXACTA, sin normalizar).
        $this->entrarConJwt($this->jwt(['iss' => 'https://'.self::TEAM.':443']))->assertForbidden();
        $this->assertGuest();
    }

    public function test_aud_multiple_con_uno_correcto_si_autentica(): void
    {
        // Semántica JWT correcta: basta que el AUD exacto esté entre los audience.
        $usuario = $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['aud' => ['otra-app-access', self::AUD]]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_aud_vacio_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['aud' => []]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_exp_no_numerico_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt(['exp' => 'mañana']))->assertForbidden();
        $this->assertGuest();
    }

    public function test_nbf_no_numerico_da_403(): void
    {
        $this->usuarioSso();

        // Un claim temporal que no se puede evaluar jamás cuenta como válido.
        $this->entrarConJwt($this->jwt(['nbf' => 'no-numerico']))->assertForbidden();
        $this->assertGuest();
    }

    public function test_jwt_con_cantidad_incorrecta_de_segmentos_da_403(): void
    {
        $this->usuarioSso();

        $this->entrarConJwt('solo.dos')->assertForbidden();
        $this->entrarConJwt('a.b.c.d')->assertForbidden();
        $this->assertGuest();
    }

    public function test_payload_que_no_es_json_da_403(): void
    {
        $this->usuarioSso();
        $h = $this->b64((string) json_encode(['alg' => 'RS256', 'kid' => self::KID]));
        $p = $this->b64('esto no es json');
        openssl_sign($h.'.'.$p, $firma, $this->clavePrivada, OPENSSL_ALGO_SHA256);

        $this->entrarConJwt($h.'.'.$p.'.'.$this->b64($firma))->assertForbidden();
        $this->assertGuest();
    }

    public function test_certificado_pem_corrupto_falla_cerrado(): void
    {
        $this->usuarioSso();
        $this->certsCola = [Http::response([
            'public_certs' => [['kid' => self::KID, 'cert' => "-----BEGIN CERTIFICATE-----\nbasura-no-decodificable\n-----END CERTIFICATE-----"]],
        ])];

        $this->entrarConJwt($this->jwt())->assertForbidden();
        $this->assertGuest();
    }

    public function test_timeout_del_endpoint_de_certs_falla_cerrado(): void
    {
        $this->usuarioSso();
        $this->certsCola = [Http::failedConnection()];

        $this->entrarConJwt($this->jwt())->assertForbidden();
        $this->assertGuest();
    }

    // ---------- Adversariales: rotación de claves ----------

    public function test_kid_nuevo_con_cache_vieja_refresca_y_autentica(): void
    {
        $usuario = $this->usuarioSso();

        // 1) Se cachea el set con el kid viejo.
        $this->assertNotNull(app(CloudflareAccessJwtService::class)->validar($this->jwt()));

        // 2) Cloudflare rota: clave/cert nuevos con kid nuevo; el endpoint ya sirve el set nuevo.
        [$claveNueva, $certNuevo] = $this->generarClaveYCertificado();
        $this->certsCola = [Http::response(['public_certs' => [['kid' => 'kid-rotado', 'cert' => $certNuevo]]])];

        $jwtRotado = $this->jwt(overrideHeader: ['alg' => 'RS256', 'kid' => 'kid-rotado'], clave: $claveNueva);
        $this->entrarConJwt($jwtRotado)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
        Http::assertSentCount(2); // set inicial + refresco por rotación
    }

    public function test_kids_basura_no_fuerzan_refrescos_repetidos(): void
    {
        $this->usuarioSso();

        // Prima la caché con el set bueno.
        $this->assertNotNull(app(CloudflareAccessJwtService::class)->validar($this->jwt()));

        // Dos JWT con kid inexistente: el primero dispara UN refresco; el segundo,
        // dentro de la ventana de 60s, ya no golpea el endpoint.
        $jwtBasura = $this->jwt(overrideHeader: ['alg' => 'RS256', 'kid' => 'kid-que-no-existe']);
        $this->entrarConJwt($jwtBasura)->assertForbidden();
        $this->entrarConJwt($jwtBasura)->assertForbidden();

        Http::assertSentCount(2); // 1 set inicial + 1 único refresco
        $this->assertGuest();
    }

    // ---------- Adversariales: host y proxy ----------

    public function test_host_parecido_con_sufijo_malicioso_no_ejecuta_sso(): void
    {
        $this->usuarioSso();

        $this->withHeaders(['Cf-Access-Jwt-Assertion' => $this->jwt()])
            ->get('https://facturacion.dulceslanegrita.com.evil.com/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_host_con_puerto_no_evita_la_validacion_criptografica(): void
    {
        // getHost() ignora el puerto: el SSO corre igual y exige el JWT firmado.
        $usuario = $this->usuarioSso();

        $this->withHeaders(['Cf-Access-Jwt-Assertion' => $this->jwt()])
            ->get('https://'.self::HOST.':8443/')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_x_forwarded_proto_desde_origen_no_confiable_no_activa_sso(): void
    {
        $this->usuarioSso();

        // Cliente directo (IP LAN, no 127.0.0.1) inyectando X-Forwarded-Proto: el
        // proxy no es confiable, isSecure() es false y el SSO ni se evalúa.
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'Cf-Access-Jwt-Assertion' => $this->jwt(),
            ])
            ->get('http://'.self::HOST.'/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ---------- Gating: dónde NO corre el SSO ----------

    public function test_header_de_email_sin_jwt_no_autentica(): void
    {
        $this->usuarioSso();

        // El header de email de Cloudflare NUNCA autentica por sí solo.
        $this->withHeaders(['Cf-Access-Authenticated-User-Email' => 'sso@dulceslanegrita.test'])
            ->get('https://'.self::HOST.'/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_host_no_autorizado_no_ejecuta_sso(): void
    {
        $this->usuarioSso();

        // JWT perfectamente válido, pero en facturacion.test: el SSO ni se evalúa.
        $this->withHeaders(['Cf-Access-Jwt-Assertion' => $this->jwt()])
            ->get('http://facturacion.test/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_http_sin_tls_en_el_host_publico_no_ejecuta_sso(): void
    {
        $this->usuarioSso();

        $this->withHeaders(['Cf-Access-Jwt-Assertion' => $this->jwt()])
            ->get('http://'.self::HOST.'/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_sso_deshabilitado_por_defecto_no_hace_nada(): void
    {
        config(['cloudflare_access.enabled' => false]);
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt())->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_configuracion_incompleta_apaga_el_sso(): void
    {
        config(['cloudflare_access.aud' => '']);
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt())->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_auto_login_apagado_valida_pero_no_inicia_sesion(): void
    {
        config(['cloudflare_access.auto_login' => false]);
        $this->usuarioSso();

        $this->entrarConJwt($this->jwt())->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_local_en_facturacion_test_sigue_funcionando(): void
    {
        $usuario = $this->usuarioSso(['password' => 'Password#Fuerte1']);

        $this->post('http://facturacion.test/login', [
            'email' => 'sso@dulceslanegrita.test',
            'password' => 'Password#Fuerte1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    // ---------- Caché de certificados ----------

    public function test_los_certificados_se_cachean_y_no_se_piden_en_cada_validacion(): void
    {
        $servicio = app(CloudflareAccessJwtService::class);

        $this->assertNotNull($servicio->validar($this->jwt()));
        $this->assertNotNull($servicio->validar($this->jwt()));

        Http::assertSentCount(1); // segunda validación: certs desde caché
    }

    public function test_un_fallo_de_red_no_se_cachea(): void
    {
        $this->certsCola = [
            Http::response(null, 500),
            Http::response(['public_certs' => [['kid' => self::KID, 'cert' => $this->certificadoPem]]]),
        ];
        $servicio = app(CloudflareAccessJwtService::class);

        $this->assertNull($servicio->validar($this->jwt()));    // endpoint caído: fail closed
        $this->assertNotNull($servicio->validar($this->jwt())); // recuperado: valida (el 500 no quedó cacheado)
    }

    // ---------- Sesiones y logout ----------

    public function test_sesion_ya_iniciada_no_revalida_el_jwt(): void
    {
        $usuario = $this->usuarioSso();

        $this->actingAs($usuario)
            ->withHeaders(['Cf-Access-Jwt-Assertion' => 'jwt-basura-que-seria-403'])
            ->get('https://'.self::HOST.'/')
            ->assertRedirect(route('dashboard'));

        Http::assertSentCount(0); // ni siquiera pidió los certificados
    }

    public function test_logout_local_sigue_funcionando(): void
    {
        $this->actingAs($this->usuarioSso())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_completo_cierra_laravel_y_redirige_al_logout_de_access(): void
    {
        $this->actingAs($this->usuarioSso())
            ->post('https://'.self::HOST.'/logout-completo')
            ->assertRedirect('/cdn-cgi/access/logout');

        $this->assertGuest();
    }

    public function test_logout_completo_requiere_sesion(): void
    {
        $this->post('/logout-completo')->assertRedirect(route('login'));
    }
}
