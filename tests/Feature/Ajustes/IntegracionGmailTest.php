<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Integraciones\ConfiguracionGmail;
use App\Ajustes\Integraciones\PruebaConexionGmail;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\GmailCuenta;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integraciones → Gmail (Prontos Pagos).
 *
 * Lo que se fija: que los TOKENS no salen nunca, que el secreto de cliente se
 * comporta como cualquier otro secreto, que la comprobación no sincroniza, que
 * desconectar deja rastro — y que la lógica de PPQ recibe exactamente los mismos
 * valores que antes cuando no hay ningún override guardado.
 *
 * Ninguna prueba sale a la red: la comprobación usa la semilla del constructor y
 * `Http::fake()` demuestra que no se intentó ninguna petición.
 */
class IntegracionGmailTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS = 'ya29.token-de-acceso-secretisimo';

    private const REFRESH = '1//refresh-token-secretisimo';

    private const SECRETO = 'GOCSPX-secreto-de-cliente-9x';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function cuentaConectada(): GmailCuenta
    {
        return GmailCuenta::create([
            'email' => 'ppq@ejemplo.com',
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'scopes' => 'gmail.readonly',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'activada' => '1',
            'client_id' => '123-abc.apps.googleusercontent.com',
            'redirect_uri' => 'https://facturacion.test/ppq/gmail/callback',
            'label_albaranes' => 'Calleja_Albaranes',
            'enviados_query' => 'in:sent',
            'dte_adjunto_query' => '(filename:json OR filename:pdf)',
        ], $extra);
    }

    // ------------------------------------------------------------- pantalla

    public function test_el_administrador_ve_la_pantalla(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.integraciones.gmail'))
            ->assertOk()
            ->assertSee('Conexión con la cuenta')
            ->assertSee('Credenciales de Google')
            ->assertSee('Dónde buscar');
    }

    public function test_un_rol_sin_permiso_no_entra(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.integraciones.gmail'))->assertForbidden();
        $this->actingAs($usuario)->put(route('configuracion.integraciones.gmail.update'), $this->formulario())->assertForbidden();
        $this->actingAs($usuario)->post(route('configuracion.integraciones.gmail.probar'))->assertForbidden();
        $this->actingAs($usuario)->delete(route('configuracion.integraciones.gmail.desconectar'))->assertForbidden();
    }

    /** LA garantía de esta pantalla: publica estado, nunca credenciales. */
    public function test_la_pantalla_no_muestra_ningun_token(): void
    {
        $this->cuentaConectada();
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $html = $this->actingAs($admin)->get(route('configuracion.integraciones.gmail'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::ACCESS, $html);
        $this->assertStringNotContainsString(self::REFRESH, $html);
        $this->assertStringNotContainsString(self::SECRETO, $html);
    }

    public function test_muestra_el_estado_de_la_conexion_sin_credenciales(): void
    {
        $this->cuentaConectada();

        $this->actingAs($this->admin())
            ->get(route('configuracion.integraciones.gmail'))
            ->assertOk()
            ->assertSee('ppq@ejemplo.com')
            ->assertSee('gmail.readonly')
            ->assertSee('Activo');
    }

    public function test_sin_cuenta_conectada_lo_dice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.integraciones.gmail'))
            ->assertOk()
            ->assertSee('No hay ninguna cuenta conectada');
    }

    // ---------------------------------------------------------------- N2

    public function test_guardar_sin_confirmar_no_escribe(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.gmail.update'), $this->formulario())
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'ppq.gmail.client_id']);
    }

    public function test_confirmar_guarda_las_credenciales(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.gmail.update'), $this->formulario(['confirmacion' => '1']))
            ->assertRedirect(route('configuracion.integraciones.gmail'))
            ->assertSessionHas('status');

        $this->assertSame('123-abc.apps.googleusercontent.com', Ajustes::texto('ppq.gmail.client_id'));
        $this->assertTrue(Ajustes::bool('ppq.gmail.enabled'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('ppq.gmail.client_id'));
    }

    /** La casilla ausente significa "desactivada", no "no la toques". */
    public function test_desmarcar_la_casilla_desactiva_la_integracion(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('ppq.gmail.enabled', true);

        $formulario = $this->formulario(['confirmacion' => '1']);
        unset($formulario['activada']);

        $this->actingAs($admin)->put(route('configuracion.integraciones.gmail.update'), $formulario)->assertRedirect();

        $this->assertFalse(Ajustes::bool('ppq.gmail.enabled'));
    }

    // ------------------------------------------------------------- secreto

    public function test_el_secreto_tiene_pantalla_propia_y_se_guarda_cifrado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.gmail.secreto.update'), [
                'password' => self::SECRETO,
                'confirmacion' => '1',
            ])
            ->assertRedirect(route('configuracion.integraciones.gmail'));

        $fila = AjusteSistema::query()->where('clave', 'ppq.gmail.client_secret')->firstOrFail();

        $this->assertTrue((bool) $fila->cifrado);
        $this->assertStringNotContainsString(self::SECRETO, (string) $fila->valor);
        $this->assertSame(self::SECRETO, Ajustes::secretoParaRuntime('ppq.gmail.client_secret'));
    }

    public function test_el_secreto_vacio_no_borra_nada(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.gmail.secreto.update'), ['password' => '', 'confirmacion' => '1'])
            ->assertSessionHasErrors('password');

        $this->assertSame(self::SECRETO, Ajustes::secretoParaRuntime('ppq.gmail.client_secret'));
    }

    public function test_la_pantalla_del_secreto_no_lo_precarga(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $html = $this->actingAs($admin)->get(route('configuracion.integraciones.gmail.secreto.edit'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRETO, $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
    }

    // ---------------------------------------------------------- verificación

    /** Una comprobación correcta se registra y se audita, sin sincronizar nada. */
    public function test_probar_la_conexion_registra_la_verificacion(): void
    {
        Http::fake();
        $this->cuentaConectada();
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardarVarios([
            'ppq.gmail.enabled' => true,
            'ppq.gmail.client_id' => 'abc',
            'ppq.gmail.redirect_uri' => 'https://x/y',
        ]);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $this->app->instance(PruebaConexionGmail::class, $this->prueba(fn () => ['email' => 'ppq@ejemplo.com', 'mensajes' => 10]));

        $this->actingAs($admin)
            ->post(route('configuracion.integraciones.gmail.probar'))
            ->assertRedirect(route('configuracion.integraciones.gmail'))
            ->assertSessionHas('status');

        $verificacion = VerificacionConfiguracion::query()->de('gmail')->latest('id')->firstOrFail();
        $this->assertSame(ResultadoVerificacion::Exito, $verificacion->resultado);

        // La comprobación NO sale a la red por su cuenta en la suite.
        Http::assertNothingSent();
    }

    public function test_un_fallo_se_registra_y_no_filtra_el_secreto(): void
    {
        $this->cuentaConectada();
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardarVarios([
            'ppq.gmail.enabled' => true,
            'ppq.gmail.client_id' => 'abc',
            'ppq.gmail.redirect_uri' => 'https://x/y',
        ]);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $this->app->instance(PruebaConexionGmail::class, $this->prueba(
            fn () => throw new \RuntimeException('invalid_client con secreto '.self::SECRETO)
        ));

        $this->actingAs($admin)->post(route('configuracion.integraciones.gmail.probar'))->assertSessionHas('error');

        $verificacion = VerificacionConfiguracion::query()->de('gmail')->latest('id')->firstOrFail();

        $this->assertSame(ResultadoVerificacion::Fallo, $verificacion->resultado);
        $this->assertStringNotContainsString(self::SECRETO, (string) $verificacion->mensaje);
    }

    /** Sin credenciales no hay nada que comprobar: no es un fallo del servicio. */
    public function test_sin_credenciales_no_se_registra_verificacion(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->prueba(fn () => ['email' => null, 'mensajes' => null])->ejecutar();

        $this->assertFalse($resultado->comprobo);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
    }

    // ------------------------------------------------------------ desconectar

    public function test_desconectar_borra_la_cuenta_y_deja_rastro(): void
    {
        $this->cuentaConectada();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.integraciones.gmail.desconectar'))
            ->assertRedirect(route('configuracion.integraciones.gmail'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('gmail_cuentas', 0);

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();
        $this->assertStringContainsString('desconectó Gmail', (string) $actividad->description);
        $this->assertSame('ppq@ejemplo.com', $actividad->getExtraProperty('cuenta'));
    }

    /** Ni el hecho ni sus propiedades pueden llevar los tokens. */
    public function test_desconectar_no_registra_tokens(): void
    {
        $this->cuentaConectada();

        $this->actingAs($this->admin())->delete(route('configuracion.integraciones.gmail.desconectar'));

        foreach (Activity::all() as $actividad) {
            $volcado = $actividad->description.' '.json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::ACCESS, $volcado);
            $this->assertStringNotContainsString(self::REFRESH, $volcado);
            $this->assertStringNotContainsString(md5(self::REFRESH), $volcado);
        }
    }

    /** La otra puerta a la misma operación también audita. */
    public function test_desconectar_desde_ppq_tambien_deja_rastro(): void
    {
        $this->cuentaConectada();

        $this->actingAs($this->admin())->delete(route('ppq.gmail.desconectar'))->assertRedirect();

        $this->assertDatabaseCount('gmail_cuentas', 0);
        $this->assertTrue(
            Activity::query()->where('log_name', 'ajustes')->where('description', 'like', '%desconectó Gmail%')->exists()
        );
    }

    // --------------------------------------------------------- PPQ intacto

    /** Sin overrides, PPQ recibe exactamente los mismos valores que antes. */
    public function test_sin_overrides_ppq_recibe_los_valores_de_config(): void
    {
        config([
            'ppq.gmail.label_albaranes' => 'Calleja_Albaranes',
            'ppq.gmail.enviados_query' => 'in:sent',
            'ppq.gmail.dte_adjunto_query' => '(filename:json OR filename:pdf)',
        ]);

        $configuracion = app(ConfiguracionGmail::class);

        $this->assertSame('Calleja_Albaranes', $configuracion->labelAlbaranes());
        $this->assertSame('in:sent', $configuracion->enviadosQuery());
        $this->assertSame('(filename:json OR filename:pdf)', $configuracion->dteAdjuntoQuery());
    }

    /** Y con override, PPQ usa el nuevo sin que haya cambiado su lógica. */
    public function test_el_override_llega_al_cliente_de_gmail(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('ppq.gmail.label_albaranes', 'Otro_Label');

        $this->assertSame('Otro_Label', app(ConfiguracionGmail::class)->labelAlbaranes());
    }

    /** `configurado()` del cliente responde lo mismo que el resolver. */
    public function test_el_cliente_y_la_pantalla_responden_lo_mismo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->assertFalse(app(GmailClient::class)->configurado());

        Ajustes::guardarVarios([
            'ppq.gmail.enabled' => true,
            'ppq.gmail.client_id' => 'abc',
            'ppq.gmail.redirect_uri' => 'https://x/y',
        ]);
        Ajustes::guardar('ppq.gmail.client_secret', self::SECRETO);

        $this->assertTrue(app(ConfiguracionGmail::class)->completo());
        $this->assertTrue(app(GmailClient::class)->configurado());
    }

    // ---------------------------------------------------------------- ayuda

    private function prueba(\Closure $perfil): PruebaConexionGmail
    {
        return new PruebaConexionGmail(
            app(ConfiguracionGmail::class),
            app(GmailClient::class),
            app(RegistroVerificaciones::class),
            app(AuditoriaAjustes::class),
            $perfil,
        );
    }
}
