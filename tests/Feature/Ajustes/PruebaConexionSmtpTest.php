<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Ajustes\Correo\PruebaConexionSmtp;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Prueba de conexión SMTP: comprueba servidor, puerto, seguridad, usuario y
 * contraseña SIN ENVIAR NINGÚN CORREO.
 *
 * `SmtpTransport::start()` conecta, saluda, negocia el cifrado y autentica; el
 * mensaje solo viaja en `send()`, que no se llama nunca. Por eso no hace falta —ni
 * se hace— mandarse un correo de prueba a una dirección inventada, que es como se
 * acaba escribiendo a un cliente por accidente.
 *
 * EN LA SUITE NO SE ABRE NINGÚN SOCKET: se inyecta un transporte de mentira por la
 * semilla del constructor. Lo que se prueba es la lógica alrededor —qué se
 * registra, qué se audita y qué se sanea—, no que Symfony sepa hablar SMTP.
 */
class PruebaConexionSmtpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-smtp-secretisima';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['mail.mailers.smtp.host' => 'smtp.ejemplo.com']);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Transporte que finge conectar bien. No abre socket: `start()` está vacío. */
    private function transporteQueConecta(): EsmtpTransport
    {
        return new class('smtp.ejemplo.com', 587) extends EsmtpTransport
        {
            public function start(): void {}

            public function stop(): void {}
        };
    }

    /** Transporte que falla como falla Symfony de verdad: con el usuario en el mensaje. */
    private function transporteQueFalla(string $mensaje): EsmtpTransport
    {
        return new class('smtp.ejemplo.com', 587, false, null, null, $mensaje) extends EsmtpTransport
        {
            private string $motivo;

            public function __construct($host, $port, $tls, $dispatcher, $logger, string $motivo)
            {
                parent::__construct($host, $port, $tls, $dispatcher, $logger);
                $this->motivo = $motivo;
            }

            public function start(): void
            {
                throw new TransportException($this->motivo);
            }

            public function stop(): void {}
        };
    }

    private function prueba(?Closure $transporte): PruebaConexionSmtp
    {
        return new PruebaConexionSmtp(
            app(ServicioAjustes::class),
            app(RegistroVerificaciones::class),
            app(AuditoriaAjustes::class),
            app(ConfiguracionCorreoRuntime::class),
            app(),
            $transporte,
        );
    }

    // ---------------------------------------------------------------- éxito

    public function test_una_conexion_correcta_se_registra_como_exito(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->prueba(fn () => $this->transporteQueConecta())->ejecutar();

        $this->assertTrue($resultado->exito);
        $this->assertStringContainsString('No se envió ningún correo', $resultado->mensaje);

        $verificacion = VerificacionConfiguracion::query()->de('smtp')->latest('id')->firstOrFail();
        $this->assertSame(ResultadoVerificacion::Exito, $verificacion->resultado);
    }

    public function test_la_prueba_no_envia_ningun_correo(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $this->prueba(fn () => $this->transporteQueConecta())->ejecutar();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_el_exito_queda_auditado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->prueba(fn () => $this->transporteQueConecta())->ejecutar();

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();

        $this->assertStringContainsString('probó Conexión SMTP: éxito', (string) $actividad->description);
        $this->assertSame('verificacion', $actividad->getExtraProperty('accion'));
        $this->assertSame($admin->id, $actividad->causer_id);
    }

    // ---------------------------------------------------------------- fallo

    public function test_un_fallo_se_registra_con_su_mensaje(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->prueba(fn () => $this->transporteQueFalla('Connection refused'))->ejecutar();

        $this->assertFalse($resultado->exito);
        $this->assertStringContainsString('Connection refused', $resultado->mensaje);

        $verificacion = VerificacionConfiguracion::query()->de('smtp')->latest('id')->firstOrFail();
        $this->assertSame(ResultadoVerificacion::Fallo, $verificacion->resultado);
    }

    /** Aunque la contraseña apareciera en el texto del error, no se guarda. */
    public function test_la_contrasena_nunca_llega_al_mensaje_guardado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::PASSWORD);

        $this->actingAs($admin);
        $resultado = $this->prueba(
            fn () => $this->transporteQueFalla('Auth failed with password '.self::PASSWORD.' for user x@y.com')
        )->ejecutar();

        $this->assertStringNotContainsString(self::PASSWORD, $resultado->mensaje);

        $verificacion = VerificacionConfiguracion::query()->de('smtp')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, (string) $verificacion->mensaje);

        foreach (Activity::all() as $actividad) {
            $this->assertStringNotContainsString(
                self::PASSWORD,
                $actividad->description.' '.json_encode($actividad->properties, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    /** Symfony añade el diálogo completo con el servidor: solo se guarda la primera línea. */
    public function test_el_mensaje_se_recorta_a_la_primera_linea(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->prueba(
            fn () => $this->transporteQueFalla("535 Authentication failed\nEHLO: ...\nAUTH LOGIN: ...")
        )->ejecutar();

        $this->assertSame('535 Authentication failed', $resultado->mensaje);
    }

    // ---------------------------------------------------------- sin conexión

    /** Sin servidor configurado no hay nada que probar: no es un fallo del servidor. */
    public function test_sin_servidor_configurado_no_se_registra_nada(): void
    {
        config(['mail.mailers.smtp.host' => null]);
        $this->actingAs($this->admin());

        $resultado = $this->prueba(fn () => $this->transporteQueConecta())->ejecutar();

        $this->assertFalse($resultado->exito);
        $this->assertFalse($resultado->conecto);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
    }

    /** Con el medio de envío en «log» tampoco: no hay servidor al que conectarse. */
    public function test_con_un_transporte_que_no_es_smtp_no_se_registra_nada(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->prueba(fn () => null)->ejecutar();

        $this->assertFalse($resultado->conecto);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }

    // ------------------------------------------------------------- pantalla

    public function test_el_boton_de_la_pantalla_dispara_la_prueba(): void
    {
        Mail::fake();

        $this->app->instance(PruebaConexionSmtp::class, $this->prueba(fn () => $this->transporteQueConecta()));

        $this->actingAs($this->admin())
            ->post(route('configuracion.correo.smtp.probar'))
            ->assertRedirect(route('configuracion.correo.edit'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('verificaciones_configuracion', 1);
        Mail::assertNothingSent();
    }

    public function test_un_fallo_se_muestra_como_error_en_la_pantalla(): void
    {
        $this->app->instance(
            PruebaConexionSmtp::class,
            $this->prueba(fn () => $this->transporteQueFalla('Connection refused'))
        );

        $this->actingAs($this->admin())
            ->post(route('configuracion.correo.smtp.probar'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** La última verificación se muestra en la pantalla de Correo. */
    public function test_la_pantalla_muestra_la_ultima_verificacion(): void
    {
        $this->app->instance(PruebaConexionSmtp::class, $this->prueba(fn () => $this->transporteQueConecta()));
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('configuracion.correo.smtp.probar'));

        $this->actingAs($admin)
            ->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertSee('Última verificación');
    }

    // ------------------------------------------------------------ retención

    /** El historial no crece sin límite por pulsar el botón muchas veces. */
    public function test_el_historial_se_poda(): void
    {
        $this->actingAs($this->admin());
        $prueba = $this->prueba(fn () => $this->transporteQueConecta());

        for ($i = 0; $i < RegistroVerificaciones::MAXIMO_POR_CLAVE + 5; $i++) {
            $prueba->ejecutar();
        }

        $this->assertSame(
            RegistroVerificaciones::MAXIMO_POR_CLAVE,
            VerificacionConfiguracion::query()->de('smtp')->count(),
        );
    }
}
