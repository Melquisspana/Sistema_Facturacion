<?php

namespace Tests\Feature\Dte;

use App\Models\Configuracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * `produccion.auth_prod_validada` abre el check "Credenciales producción validadas" del
 * preflight de emisión real. Se leía en cuatro sitios y no lo escribía NADIE: la única
 * forma de encenderlo era tinker o SQL directo — sin autor, sin fecha y sin ninguna
 * evidencia de que la credencial sirviera.
 *
 * Ahora su ÚNICO escritor es `dte:auth-test --prod`, que es login-only: no transmite
 * ningún DTE, no cachea el token y no lo muestra. Estos tests no hacen ningún login
 * real: el endpoint del MH está interceptado con Http::fake().
 */
class AuthProdValidadaEscritorTest extends TestCase
{
    use RefreshDatabase;

    private const URL_AUTH_PROD = 'https://api.dtes.mh.gob.sv/seguridad/auth';

    protected function setUp(): void
    {
        parent::setUp();
        Configuracion::olvidarCache();

        // Credenciales de producción presentes y candado del login-only abierto. El
        // ambiente de transmisión queda en producción para que se evalúe ese par.
        config([
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.auth_test_prod_enabled' => true,
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => 'clave_ficticia',
            'dte.transmision.usuario_produccion_explicito' => 'usuario_ficticio',
            'dte.transmision.password_produccion_explicito' => 'clave_ficticia',
        ]);
    }

    private function fakeLogin(bool $aceptado): void
    {
        Http::fake([
            self::URL_AUTH_PROD => Http::response(
                $aceptado
                    ? ['status' => 'OK', 'body' => ['token' => 'Bearer token-ficticio']]
                    : ['status' => 'ERROR', 'message' => 'credencial no válida'],
                $aceptado ? 200 : 401,
            ),
        ]);
    }

    public function test_un_login_aceptado_persiste_la_validacion(): void
    {
        $this->fakeLogin(true);

        $this->assertFalse(Configuracion::getBool('produccion.auth_prod_validada', false));

        $this->artisan('dte:auth-test', ['--prod' => true])->assertExitCode(0);
        Configuracion::olvidarCache();

        $this->assertTrue(Configuracion::getBool('produccion.auth_prod_validada', false));
    }

    public function test_registra_cuando_y_con_que_fuente_se_valido(): void
    {
        $this->fakeLogin(true);

        $this->artisan('dte:auth-test', ['--prod' => true]);
        Configuracion::olvidarCache();

        // La fecha permite distinguir un "validadas" de hoy de uno de hace seis meses.
        $this->assertNotNull(Configuracion::get('produccion.auth_prod_validada_en'));
        // La fuente son NOMBRES de variables de entorno, nunca sus valores.
        $this->assertSame('prod', Configuracion::get('produccion.auth_prod_validada_fuente'));
    }

    public function test_un_login_rechazado_revoca_una_validacion_anterior(): void
    {
        Configuracion::set('produccion.auth_prod_validada', true);
        $this->fakeLogin(false);

        $this->artisan('dte:auth-test', ['--prod' => true]);
        Configuracion::olvidarCache();

        // Una credencial que dejó de servir no puede seguir abriendo el preflight con
        // el visto bueno de la semana pasada.
        $this->assertFalse(Configuracion::getBool('produccion.auth_prod_validada', false));
    }

    public function test_con_el_candado_cerrado_no_se_toca_el_flag(): void
    {
        Configuracion::set('produccion.auth_prod_validada', true);
        config(['dte.transmision.auth_test_prod_enabled' => false]);
        Http::fake();

        $this->artisan('dte:auth-test', ['--prod' => true])->assertExitCode(1);
        Configuracion::olvidarCache();

        // Bloqueado ≠ rechazado: sin intento no hay veredicto, así que no se cambia nada.
        $this->assertTrue(Configuracion::getBool('produccion.auth_prod_validada', false));
        Http::assertNothingSent();
    }

    public function test_sin_credenciales_no_se_toca_el_flag_ni_se_hace_http(): void
    {
        Configuracion::set('produccion.auth_prod_validada', true);
        config([
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
        ]);
        Http::fake();

        $this->artisan('dte:auth-test', ['--prod' => true])->assertExitCode(1);
        Configuracion::olvidarCache();

        $this->assertTrue(Configuracion::getBool('produccion.auth_prod_validada', false));
        Http::assertNothingSent();
    }

    public function test_el_comando_no_imprime_usuario_contrasena_ni_token(): void
    {
        $this->fakeLogin(true);

        $this->artisan('dte:auth-test', ['--prod' => true])
            ->doesntExpectOutputToContain('usuario_ficticio')
            ->doesntExpectOutputToContain('clave_ficticia')
            ->doesntExpectOutputToContain('token-ficticio')
            ->assertExitCode(0);
    }

    public function test_el_login_only_no_deja_token_en_cache(): void
    {
        $this->fakeLogin(true);

        $this->artisan('dte:auth-test', ['--prod' => true]);

        // Sigue siendo login-only: valida la credencial, no habilita transmitir.
        $this->assertNull(cache()->get('dte.transmision.token.prod'));
    }

    public function test_el_flag_queda_auditado_con_su_valor(): void
    {
        $this->fakeLogin(true);

        $this->artisan('dte:auth-test', ['--prod' => true]);

        $act = Activity::query()
            ->where('log_name', 'configuracion')
            ->where('subject_type', Configuracion::class)
            ->get()
            ->first(fn ($a) => str_contains((string) $a->description, 'auth_prod_validada»'));

        $this->assertNotNull($act, 'El cambio del flag no dejó registro de auditoría.');
        $this->assertSame('1', $act->properties['attributes']['valor']);
    }
}
