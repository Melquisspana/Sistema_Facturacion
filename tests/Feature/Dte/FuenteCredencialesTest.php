<?php

namespace Tests\Feature\Dte;

use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QUÉ variables de entorno alimentan el login del MH, sin revelar ningún valor.
 *
 * config/dte.php declara un fallback: `usuario_produccion` = DTE_PROD_USER, y si no
 * está, DTE_TRANSMISION_USER (legacy). Ese fallback se conserva a propósito para no
 * romper lo que hoy funciona, pero tenía un punto ciego: el valor ya llega RESUELTO,
 * así que mirándolo es imposible saber cuál de las dos fuentes está en uso. Un
 * DTE_PROD_USER mal escrito no falla — se transmite en silencio con la credencial
 * vieja. Este diagnóstico lo hace visible.
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
            'dte.transmision.usuario_produccion_explicito' => 'usuario_ficticio',
            'dte.transmision.password_produccion_explicito' => 'clave_ficticia',
        ]);

        $this->assertSame('prod', $this->auth()->fuenteCredenciales());
    }

    public function test_produccion_cayendo_al_respaldo_legacy_lo_reporta(): void
    {
        // DTE_PROD_* sin definir: el valor efectivo viene de DTE_TRANSMISION_*.
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => 'usuario_legacy',
            'dte.transmision.password_produccion' => 'clave_legacy',
            'dte.transmision.usuario_produccion_explicito' => '',
            'dte.transmision.password_produccion_explicito' => '',
        ]);

        $this->assertSame('legacy', $this->auth()->fuenteCredenciales());
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
            'dte.transmision.usuario_produccion' => 'usuario_legacy',
            'dte.transmision.password_produccion' => 'clave_legacy',
            'dte.transmision.usuario_produccion_explicito' => '',
            'dte.transmision.password_produccion_explicito' => '',
        ]);

        $d = $this->auth()->diagnostico();

        $this->assertSame('legacy', $d['fuente_credenciales']);
        $this->assertStringContainsString('DTE_TRANSMISION_USER', $d['fuente_credenciales_detalle']);
        $this->assertStringContainsString('LEGACY', $d['fuente_credenciales_detalle']);
    }

    public function test_el_diagnostico_nunca_expone_usuarios_ni_contrasenas(): void
    {
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => 'clave_ficticia',
            'dte.transmision.usuario_produccion_explicito' => 'usuario_ficticio',
            'dte.transmision.password_produccion_explicito' => 'clave_ficticia',
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
            'dte.transmision.usuario_produccion_explicito' => '',
            'dte.transmision.password_produccion_explicito' => '',
        ]);

        $this->artisan('dte:auth-check')
            ->expectsOutputToContain('Fuente de credenciales')
            ->expectsOutputToContain('DTE_PROD_USER')
            ->doesntExpectOutputToContain('usuario_ficticio')
            ->doesntExpectOutputToContain('clave_ficticia')
            ->assertExitCode(0);
    }

    public function test_config_sigue_declarando_el_fallback_legacy_de_produccion(): void
    {
        // El fallback NO se elimina en esta fase: producción podría depender de él.
        // Se deprecó en la documentación y se hizo visible en el diagnóstico, nada más.
        $fuente = (string) file_get_contents(config_path('dte.php'));

        $this->assertStringContainsString(
            "'usuario_produccion' => env('DTE_PROD_USER', env('DTE_TRANSMISION_USER', ''))",
            $fuente
        );
        // Y las claves de diagnóstico leen SOLO las explícitas, sin fallback.
        $this->assertStringContainsString("'usuario_produccion_explicito' => env('DTE_PROD_USER', '')", $fuente);
        $this->assertStringContainsString("'password_produccion_explicito' => env('DTE_PROD_PASSWORD', '')", $fuente);
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
