<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Integraciones, por sus RUTAS: «Guardar» de la tienda web
 * (`configuracion.integraciones.tienda.update`) y «Probar conexión» del segundo buzón
 * de compras (`configuracion.integraciones.buzon-adicional.probar`).
 *
 * La tienda: una URL HTTPS válida queda guardada con la confirmación de dos pasos y en
 * la auditoría; una que no lo es no se guarda. El segundo buzón: la prueba abre el
 * SEGUNDO buzón —no el principal—, deja su resultado en su propio historial y no trae
 * ni un correo. Nada sale a internet: la conexión IMAP es un doble. Datos y secretos
 * inventados.
 */
class IntegracionesTiendaBuzonRutasTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://tienda.ejemplo.com/api';

    private const SECRETO_BUZON_1 = 'secreto-de-prueba-buzon-1';

    private const SECRETO_BUZON_2 = 'secreto-de-prueba-buzon-2';

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function facturacion(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('facturacion');
    }

    /** Actividades de auditoría de una clave de ajuste. */
    private function cambiosDe(string $clave)
    {
        return Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => ($a->properties['clave'] ?? null) === $clave)
            ->values();
    }

    // ------------------------------------------------------------ tienda web

    public function test_una_url_https_valida_queda_guardada_con_la_confirmacion_y_en_la_auditoria(): void
    {
        Http::fake();
        $admin = $this->admin();

        // Primer envío: solo muestra qué cambia, no guarda.
        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.tienda.update'), ['url' => self::URL])
            ->assertOk()
            ->assertSee('Vas a cambiar la integración con la tienda web');
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'tienda.url']);

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.tienda.update'), ['url' => self::URL, 'confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.integraciones.tienda'))
            ->assertSessionHas('status', 'Integración con la tienda web guardada.');

        $this->assertSame(self::URL, Ajustes::texto('tienda.url'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('tienda.url'));

        $cambio = $this->cambiosDe('tienda.url')->sole();
        $this->assertSame($admin->id, (int) $cambio->causer_id);
        $this->assertSame(self::URL, $cambio->properties['valor_despues']);

        // Guardar la integración no llama a la tienda.
        Http::assertNothingSent();
    }

    public function test_encender_la_lectura_automatica_queda_guardado(): void
    {
        config(['tienda.url' => self::URL]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.tienda.update'), ['url' => self::URL, 'automatica' => '1', 'confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.integraciones.tienda'));

        $this->assertTrue(Ajustes::bool('tienda.pedidos.automatica'));
    }

    public function test_una_url_que_no_es_https_limpia_no_se_guarda(): void
    {
        $admin = $this->admin();

        foreach ([
            'http://tienda.ejemplo.com/api',
            'https://usuario:clave@tienda.ejemplo.com/api',
            'https://tienda.ejemplo.com/api?token=algo',
            'https://tienda.ejemplo.com/api#arriba',
            'tienda.ejemplo.com',
        ] as $mala) {
            $this->actingAs($admin)
                ->from(route('configuracion.integraciones.tienda'))
                ->put(route('configuracion.integraciones.tienda.update'), ['url' => $mala, 'confirmacion' => '1'])
                ->assertSessionHasErrors('url');
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertCount(0, $this->cambiosDe('tienda.url'));
    }

    /**
     * Guardar la pantalla sin cambiar la URL no toca el secreto, que vive en su propia
     * pantalla. (Si la URL cambia, el secreto se quita: ver ClaveAtadaAlServidorTest.)
     */
    public function test_guardar_la_url_no_toca_el_secreto(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('tienda.url', self::URL);
        Ajustes::guardar('tienda.secreto', 'secreto-de-prueba-tienda');

        $this->actingAs($admin)
            ->put(route('configuracion.integraciones.tienda.update'), ['url' => self::URL, 'automatica' => '1', 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.integraciones.tienda'));

        $this->assertSame('secreto-de-prueba-tienda', Ajustes::secretoParaRuntime('tienda.secreto'));
        $this->assertStringNotContainsString(
            'secreto-de-prueba-tienda',
            (string) json_encode(Activity::query()->pluck('properties')->all()),
        );
    }

    public function test_el_doble_envio_no_duplica_la_url_ni_la_auditoria(): void
    {
        $admin = $this->admin();
        $datos = ['url' => self::URL, 'confirmacion' => '1'];

        $this->actingAs($admin)->put(route('configuracion.integraciones.tienda.update'), $datos)
            ->assertRedirect(route('configuracion.integraciones.tienda'));
        $this->actingAs($admin)->put(route('configuracion.integraciones.tienda.update'), $datos)
            ->assertRedirect(route('configuracion.integraciones.tienda'))
            ->assertSessionHas('status', 'No había nada que cambiar en la tienda web.');

        $this->assertSame(1, AjusteSistema::query()->where('clave', 'tienda.url')->count());
        $this->assertCount(1, $this->cambiosDe('tienda.url'));
    }

    public function test_la_tienda_sin_permiso_da_403_y_no_guarda(): void
    {
        $this->actingAs($this->facturacion())
            ->put(route('configuracion.integraciones.tienda.update'), ['url' => self::URL, 'confirmacion' => '1'])
            ->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // --------------------------------------------------------- segundo buzón

    /** Los dos buzones configurados, con servidores y contraseñas distintas. */
    private function dosBuzones(): void
    {
        config([
            'documentos_recibidos.driver' => 'imap',
            'documentos_recibidos.mail.host' => 'imap.principal.ejemplo.com',
            'documentos_recibidos.mail.username' => 'compras@ejemplo.com',
            'documentos_recibidos.mail.password' => self::SECRETO_BUZON_1,
            'documentos_recibidos.mail.folder' => 'INBOX',
            'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
            'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
            'documentos_recibidos.buzon_adicional.password' => self::SECRETO_BUZON_2,
            'documentos_recibidos.buzon_adicional.folder' => 'CCF',
        ]);
    }

    /**
     * Doble de la conexión IMAP: el controlador pide la prueba con el buzón que le toca
     * y acá se le cambia solo la forma de abrirlo. Guarda lo que recibió.
     *
     * @param  array<int, array<string, mixed>>  $recibidos
     */
    private function conexionSimulada(array &$recibidos, ?string $falla = null): void
    {
        $this->app->bind(PruebaConexionImap::class, function ($app, array $parametros) use (&$recibidos, $falla) {
            return new PruebaConexionImap(
                $parametros['configuracion'],
                $app->make(RegistroVerificaciones::class),
                $app->make(AuditoriaAjustes::class),
                function (array $cfg) use (&$recibidos, $falla) {
                    $recibidos[] = $cfg;

                    return $falla;
                },
            );
        });
    }

    public function test_probar_abre_el_segundo_buzon_y_no_trae_ningun_correo(): void
    {
        Queue::fake();
        $this->dosBuzones();
        $recibidos = [];
        $this->conexionSimulada($recibidos);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('configuracion.integraciones.buzon-adicional.probar'))
            ->assertRedirect(route('configuracion.integraciones.buzon-adicional'))
            ->assertSessionHas('status', 'El buzón respondió y aceptó las credenciales. No se leyó ningún correo.');

        // Una sola conexión, y al SEGUNDO buzón.
        $this->assertCount(1, $recibidos);
        $this->assertSame('imap.segundo.ejemplo.com', $recibidos[0]['host']);
        $this->assertSame('compras2@ejemplo.com', $recibidos[0]['username']);
        $this->assertSame(self::SECRETO_BUZON_2, $recibidos[0]['password']);
        $this->assertSame('CCF', $recibidos[0]['folder']);

        // Su resultado va a su propio historial, no al del principal.
        $verificacion = app(RegistroVerificaciones::class)->ultima(ConfiguracionBuzonAdicional::CLAVE_VERIFICACION);
        $this->assertSame(ResultadoVerificacion::Exito, $verificacion->resultado);
        $this->assertNull(app(RegistroVerificaciones::class)->ultima('imap'));

        $probo = Activity::query()->where('log_name', 'ajustes')->sole();
        $this->assertSame($admin->id, (int) $probo->causer_id);
        $this->assertSame('verificacion', $probo->properties['accion']);
        $this->assertStringContainsString('segundo buzón', (string) $probo->description);

        // Probar no sincroniza: ni documentos, ni trabajos en cola, ni ajustes cambiados.
        $this->assertDatabaseCount('documentos_recibidos', 0);
        $this->assertDatabaseCount('ajustes_sistema', 0);
        Queue::assertNothingPushed();
    }

    public function test_si_el_segundo_buzon_rechaza_la_conexion_se_avisa_sin_la_contrasena(): void
    {
        $this->dosBuzones();
        $recibidos = [];
        $this->conexionSimulada($recibidos, 'AUTHENTICATE failed con '.self::SECRETO_BUZON_2);

        $this->actingAs($this->admin())
            ->post(route('configuracion.integraciones.buzon-adicional.probar'))
            ->assertRedirect(route('configuracion.integraciones.buzon-adicional'))
            ->assertSessionHas('error');

        $this->assertStringNotContainsString(self::SECRETO_BUZON_2, (string) session('error'));

        $verificacion = VerificacionConfiguracion::query()->de(ConfiguracionBuzonAdicional::CLAVE_VERIFICACION)->sole();
        $this->assertSame(ResultadoVerificacion::Fallo, $verificacion->resultado);
        $this->assertStringNotContainsString(self::SECRETO_BUZON_2, (string) $verificacion->mensaje);
        $this->assertStringNotContainsString(
            self::SECRETO_BUZON_2,
            (string) json_encode(Activity::query()->get(['description', 'properties'])->toArray()),
        );
    }

    /** Sin segundo buzón no hay conexión que probar: no se intenta ni queda un fallo. */
    public function test_sin_segundo_buzon_no_se_conecta_ni_registra_nada(): void
    {
        $this->dosBuzones();
        config(['documentos_recibidos.buzon_adicional.host' => '']);
        $recibidos = [];
        $this->conexionSimulada($recibidos);

        $this->actingAs($this->admin())
            ->post(route('configuracion.integraciones.buzon-adicional.probar'))
            ->assertRedirect(route('configuracion.integraciones.buzon-adicional'))
            ->assertSessionHas('error');

        $this->assertSame([], $recibidos);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
    }

    public function test_probar_el_segundo_buzon_sin_permiso_da_403_y_no_se_conecta(): void
    {
        $this->dosBuzones();
        $recibidos = [];
        $this->conexionSimulada($recibidos);

        $this->actingAs($this->facturacion())
            ->post(route('configuracion.integraciones.buzon-adicional.probar'))
            ->assertForbidden();

        $this->assertSame([], $recibidos);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
    }
}
