<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ImapMailboxClient;
use App\Services\DocumentosRecibidos\NullMailboxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integraciones → Buzón de compras (IMAP).
 *
 * Lo que se fija: que la contraseña no sale nunca, que el usuario se publica
 * tapado, que la comprobación NO lee correos ni sincroniza, y que el lector sigue
 * recibiendo la misma configuración de siempre cuando no hay overrides.
 *
 * Ninguna prueba abre un socket: la comprobación usa la semilla del constructor.
 */
class IntegracionDocumentosRecibidosTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'contrasena-de-aplicacion-yahoo';

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

    /** @param  array<string, mixed>  $extra */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'lectura' => 'imap',
            'servidor' => 'imap.mail.yahoo.com',
            'puerto' => '993',
            'seguridad' => 'ssl',
            'usuario' => 'compras@ejemplo.com',
            'carpeta' => 'INBOX',
            'busqueda' => 'ALL',
            'espera' => '15',
            'limite' => '30',
        ], $extra);
    }

    /** Deja el buzón completamente configurado. */
    private function buzonConfigurado(User $admin): void
    {
        $this->actingAs($admin);
        Ajustes::guardarVarios([
            'documentos_recibidos.driver' => 'imap',
            'documentos_recibidos.host' => 'imap.mail.yahoo.com',
            'documentos_recibidos.username' => 'compras@ejemplo.com',
        ]);
        Ajustes::guardar('documentos_recibidos.password', self::PASSWORD);
    }

    // ------------------------------------------------------------- pantalla

    public function test_el_administrador_ve_la_pantalla(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.integraciones.documentos-recibidos'))
            ->assertOk()
            ->assertSee('Estado de la conexión')
            ->assertSee('Datos de conexión');
    }

    public function test_un_rol_sin_permiso_no_entra(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.integraciones.documentos-recibidos'))->assertForbidden();
        $this->actingAs($usuario)->put(route('configuracion.integraciones.documentos-recibidos.update'), $this->formulario())->assertForbidden();
        $this->actingAs($usuario)->post(route('configuracion.integraciones.documentos-recibidos.probar'))->assertForbidden();
    }

    public function test_la_pantalla_no_muestra_la_contrasena(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);

        $this->actingAs($admin)
            ->get(route('configuracion.integraciones.documentos-recibidos'))
            ->assertOk()
            ->assertDontSee(self::PASSWORD)
            ->assertSee('configurada');
    }

    /** El usuario se publica reconocible pero incompleto. */
    public function test_el_usuario_se_muestra_parcialmente_tapado(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);

        $this->actingAs($admin)
            ->get(route('configuracion.integraciones.documentos-recibidos'))
            ->assertOk()
            ->assertSee('co••••@ejemplo.com');
    }

    public function test_tapar_correo_conserva_el_dominio(): void
    {
        $this->assertSame('bu••••@yahoo.com', ConfiguracionDocumentosRecibidos::taparCorreo('buzoncompras@yahoo.com'));
        $this->assertSame('', ConfiguracionDocumentosRecibidos::taparCorreo(''));
    }

    // ---------------------------------------------------------------- N2

    public function test_guardar_sin_confirmar_no_escribe(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.documentos-recibidos.update'), $this->formulario())
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'documentos_recibidos.host']);
    }

    public function test_confirmar_guarda_la_conexion(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.documentos-recibidos.update'), $this->formulario(['confirmacion' => '1']))
            ->assertRedirect(route('configuracion.integraciones.documentos-recibidos'))
            ->assertSessionHas('status');

        $this->assertSame('imap.mail.yahoo.com', Ajustes::texto('documentos_recibidos.host'));
        $this->assertSame(993, Ajustes::entero('documentos_recibidos.port'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('documentos_recibidos.host'));
    }

    public function test_la_seguridad_solo_admite_los_valores_soportados(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.documentos-recibidos.update'), $this->formulario(['seguridad' => 'starttls', 'confirmacion' => '1']))
            ->assertSessionHasErrors('seguridad');
    }

    public function test_un_puerto_fuera_de_rango_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.integraciones.documentos-recibidos.update'), $this->formulario(['puerto' => '99999', 'confirmacion' => '1']))
            ->assertSessionHasErrors('puerto');
    }

    // ------------------------------------------------------------- secreto

    public function test_la_contrasena_se_guarda_cifrada(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.documentos-recibidos.secreto.update'), [
                'password' => self::PASSWORD,
                'confirmacion' => '1',
            ])
            ->assertRedirect(route('configuracion.integraciones.documentos-recibidos'));

        $fila = AjusteSistema::query()->where('clave', 'documentos_recibidos.password')->firstOrFail();

        $this->assertTrue((bool) $fila->cifrado);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $fila->valor);
        $this->assertSame(self::PASSWORD, Ajustes::secretoParaRuntime('documentos_recibidos.password'));
    }

    // ---------------------------------------------------------- verificación

    /**
     * LA garantía de la prueba: NO lee correos.
     *
     * La semilla recibe la configuración del lector y devuelve null (conectó). Se
     * comprueba que llega con la contraseña resuelta —es lo que hace falta para
     * autenticar— y que la prueba no toca ningún documento guardado.
     */
    public function test_probar_la_conexion_no_lee_correos(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);
        $this->actingAs($admin);

        $recibida = null;
        $resultado = $this->prueba(function (array $cfg) use (&$recibida) {
            $recibida = $cfg;

            return null;
        })->ejecutar();

        $this->assertTrue($resultado->exito);
        $this->assertStringContainsString('No se leyó ningún correo', $resultado->mensaje);
        $this->assertSame('imap.mail.yahoo.com', $recibida['host']);
        $this->assertSame(self::PASSWORD, $recibida['password']);
        $this->assertDatabaseCount('documentos_recibidos', 0);
    }

    public function test_una_conexion_correcta_se_registra(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);

        $this->app->instance(PruebaConexionImap::class, $this->prueba(fn () => null));

        $this->actingAs($admin)
            ->post(route('configuracion.integraciones.documentos-recibidos.probar'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $verificacion = VerificacionConfiguracion::query()->de('imap')->latest('id')->firstOrFail();
        $this->assertSame(ResultadoVerificacion::Exito, $verificacion->resultado);
    }

    public function test_un_fallo_no_filtra_la_contrasena(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);
        $this->actingAs($admin);

        $resultado = $this->prueba(fn () => 'AUTHENTICATE failed for compras@ejemplo.com con '.self::PASSWORD)->ejecutar();

        $this->assertFalse($resultado->exito);
        $this->assertStringNotContainsString(self::PASSWORD, $resultado->mensaje);

        $verificacion = VerificacionConfiguracion::query()->de('imap')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, (string) $verificacion->mensaje);
    }

    /** Con la lectura apagada no hay conexión que probar: no es un fallo. */
    public function test_con_la_lectura_apagada_no_se_registra_nada(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('documentos_recibidos.driver', 'none');

        $resultado = $this->prueba(fn () => null)->ejecutar();

        $this->assertFalse($resultado->comprobo);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
    }

    // ------------------------------------------------------- lector intacto

    /** Apagar la lectura desde la pantalla deja al módulo sin buzón, como el .env. */
    public function test_apagar_la_lectura_cambia_el_lector(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Ajustes::guardar('documentos_recibidos.driver', 'imap');
        $this->assertInstanceOf(ImapMailboxClient::class, app(MailboxClient::class));

        Ajustes::guardar('documentos_recibidos.driver', 'none');
        $this->assertInstanceOf(NullMailboxClient::class, app(MailboxClient::class));
    }

    /** Sin overrides, el lector recibe exactamente la configuración de siempre. */
    public function test_sin_overrides_el_lector_recibe_lo_de_config(): void
    {
        config([
            'documentos_recibidos.mail.host' => 'imap.delenv.com',
            'documentos_recibidos.mail.port' => 993,
            'documentos_recibidos.mail.folder' => 'INBOX',
        ]);

        $cfg = app(ConfiguracionDocumentosRecibidos::class)->paraLector();

        $this->assertSame('imap.delenv.com', $cfg['host']);
        $this->assertSame(993, $cfg['port']);
        $this->assertSame('INBOX', $cfg['folder']);
    }

    /** «Sin cifrado» se traduce a lo que el lector entiende: cadena vacía. */
    public function test_sin_cifrado_se_traduce_para_el_lector(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('documentos_recibidos.encryption', 'ninguna');

        $this->assertSame('', app(ConfiguracionDocumentosRecibidos::class)->paraLector()['encryption']);
    }

    /** El estado publicable nunca lleva la contraseña. */
    public function test_el_estado_para_pantalla_no_lleva_la_contrasena(): void
    {
        $admin = $this->admin();
        $this->buzonConfigurado($admin);

        $estado = app(ConfiguracionDocumentosRecibidos::class)->paraPantalla();

        $this->assertTrue($estado['password_configurada']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($estado, JSON_UNESCAPED_UNICODE));
    }

    /** El tope de correos por sincronización ya es un ajuste y llega al lector. */
    public function test_el_limite_es_administrable(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('documentos_recibidos.limite', 75);

        $this->assertSame(75, app(ConfiguracionDocumentosRecibidos::class)->limite());
    }

    // ---------------------------------------------------------------- ayuda

    private function prueba(\Closure $abrir): PruebaConexionImap
    {
        return new PruebaConexionImap(
            app(ConfiguracionDocumentosRecibidos::class),
            app(RegistroVerificaciones::class),
            app(AuditoriaAjustes::class),
            $abrir,
        );
    }
}
