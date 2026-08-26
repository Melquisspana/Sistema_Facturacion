<?php

namespace Tests\Feature\Dte;

use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QUÉ variables de entorno alimentan el login del MH, sin revelar ningún valor.
 *
 * config/dte.php declaraba un fallback: `usuario_produccion` = DTE_PROD_USER, y si no
 * está, DTE_TRANSMISION_USER (legacy). Tenía un punto ciego: el valor llegaba RESUELTO,
 * así que mirándolo era imposible saber cuál de las dos fuentes estaba en uso. Un
 * DTE_PROD_USER mal escrito no fallaba — se transmitía en silencio con la credencial
 * vieja.
 *
 * ESE FALLBACK SE ELIMINÓ. Producción exige DTE_PROD_USER / DTE_PROD_PASSWORD y falla
 * explícito si faltan. Estos tests fijan las dos mitades del cambio: que el fallback no
 * vuelva a aparecer en la configuración, y que el diagnóstico siga nombrando el caso de
 * quien viene de la configuración vieja ('legacy' = solo hay legacy puestas, así que
 * producción NO puede autenticar — no que las esté usando).
 */
class FuenteCredencialesTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): DteTransmisionAuthService
    {
        return app(DteTransmisionAuthService::class);
    }

    public function test_produccion_con_credenciales_explicitas_reporta_prod(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => 'clave_ficticia',
        ]);

        $this->assertSame('prod', $this->auth()->fuenteCredenciales());
    }

    /**
     * Produccion SIN sus credenciales propias pero CON las legacy puestas. Antes esto
     * autenticaba con la credencial vieja; ahora no autentica, y el diagnostico lo
     * nombra 'legacy' en vez de 'ninguna' para que quien venga de la configuracion
     * anterior entienda por que dejo de funcionar.
     */
    public function test_produccion_sin_credenciales_propias_pero_con_legacy_reporta_legacy(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
            'dte.transmision.usuario_api' => 'usuario_legacy',
            'dte.transmision.password' => 'clave_legacy',
        ]);

        $this->assertSame('legacy', $this->auth()->fuenteCredenciales());
    }

    /** Sin legacy ni propias, produccion no tiene nada: 'ninguna', no 'legacy'. */
    public function test_produccion_sin_ninguna_credencial_reporta_ninguna(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
            'dte.transmision.usuario_api' => '',
            'dte.transmision.password' => '',
        ]);

        $this->assertSame('ninguna', $this->auth()->fuenteCredenciales());
    }

    public function test_testing_nunca_reporta_legacy(): void
    {
        // El par de apitest no tiene fallback, por diseño: son cuentas distintas.
        config([
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.usuario_testing' => 'usuario_apitest',
            'dte.transmision.password_testing' => 'clave_apitest',
        ]);

        $this->assertSame('testing', $this->auth()->fuenteCredenciales());
    }

    public function test_sin_credenciales_reporta_ninguna(): void
    {
        config([
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.usuario_testing' => '',
            'dte.transmision.password_testing' => '',
        ]);

        $this->assertSame('ninguna', $this->auth()->fuenteCredenciales());
    }

    public function test_solo_usuario_sin_contrasena_reporta_parcial(): void
    {
        config([
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.usuario_testing' => 'usuario_apitest',
            'dte.transmision.password_testing' => '',
        ]);

        $this->assertSame('parcial', $this->auth()->fuenteCredenciales());
    }

    public function test_el_diagnostico_incluye_la_fuente_y_su_descripcion(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
            'dte.transmision.usuario_api' => 'usuario_legacy',
            'dte.transmision.password' => 'clave_legacy',
        ]);

        $d = $this->auth()->diagnostico();

        $this->assertSame('legacy', $d['fuente_credenciales']);
        $this->assertStringContainsString('DTE_PROD_USER', $d['fuente_credenciales_detalle']);
        $this->assertStringContainsString('LEGACY', $d['fuente_credenciales_detalle']);
    }

    public function test_el_diagnostico_nunca_expone_usuarios_ni_contrasenas(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => 'clave_ficticia',
        ]);

        $json = json_encode($this->auth()->diagnostico());

        $this->assertStringNotContainsString('usuario_ficticio', $json);
        $this->assertStringNotContainsString('clave_ficticia', $json);
    }

    public function test_el_comando_auth_check_muestra_la_fuente_sin_valores(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => 'clave_ficticia',
        ]);

        // Una sola expectativa de contenido para esa línea: `expectsOutputToContain`
        // consume UNA expectativa por escritura, y la etiqueta y el detalle salen en
        // la MISMA línea. Pidiendo las dos, la segunda no llegaría a cumplirse nunca
        // y el test fallaría por cómo mide, no por lo que mide. 'DTE_PROD_USER' solo
        // aparece en la línea de la fuente, así que verla implica que se imprimió.
        $this->artisan('dte:auth-check')
            ->expectsOutputToContain('DTE_PROD_USER')
            ->doesntExpectOutputToContain('usuario_ficticio')
            ->doesntExpectOutputToContain('clave_ficticia')
            ->assertExitCode(0);
    }

    /**
     * El fallback ya NO esta en la configuracion. Se comprueba sobre el TEXTO del
     * archivo a proposito: config() devuelve el valor ya resuelto, asi que leerlo no
     * distingue "no hay fallback" de "el fallback no se disparo en esta maquina".
     */
    public function test_config_ya_no_declara_el_fallback_legacy_de_produccion(): void
    {
        $fuente = (string) file_get_contents(config_path('dte.php'));

        $this->assertStringNotContainsString(
            "env('DTE_PROD_USER', env('DTE_TRANSMISION_USER', ''))",
            $fuente
        );
        $this->assertStringNotContainsString(
            "env('DTE_PROD_PASSWORD', env('DTE_TRANSMISION_PASSWORD', ''))",
            $fuente
        );

        // Produccion lee SOLO sus propias variables.
        $this->assertStringContainsString("'usuario_produccion' => env('DTE_PROD_USER', '')", $fuente);
        $this->assertStringContainsString("'password_produccion' => env('DTE_PROD_PASSWORD', '')", $fuente);
    }

    public function test_el_bloque_de_credenciales_muerto_ya_no_existe(): void
    {
        // `dte.credenciales` (DTE_API_USER / DTE_API_PASSWORD / password_certificado)
        // no tenía ningún consumidor. La contraseña del certificado queda con una sola
        // fuente: dte.firma.cert_password.
        $this->assertNull(config('dte.credenciales'));
        $this->assertNotNull(config('dte.firma.cert_password'));
    }
}
