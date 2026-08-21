<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Resumen\EstadoTarjeta;
use App\Ajustes\Resumen\ResumenConfiguracion;
use App\Ajustes\Resumen\TarjetaResumen;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pantalla Resumen: estado de la configuración del sistema, de un vistazo.
 *
 * Lo que se fija acá es lo que la hace confiable: que NO muestra secretos, que NO
 * sale a la red para pintarse, que no ofrece enlaces a pantallas inexistentes y
 * que no la ve quien no administra configuración.
 */
class ResumenConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'clave-smtp-de-prueba-9x';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------- acceso

    public function test_el_administrador_ve_el_resumen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.resumen'))
            ->assertOk()
            ->assertSee('Estado de la configuración');
    }

    public function test_un_rol_sin_configuracion_gestionar_no_entra(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.resumen'))->assertForbidden();
    }

    public function test_un_invitado_no_entra(): void
    {
        $this->get(route('configuracion.resumen'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ secretos

    /** LA garantía de esta pantalla: publica estado, nunca contenido. */
    public function test_el_resumen_no_muestra_ningun_secreto(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Ajustes::guardar('mail.smtp.password', self::SECRETO);
        config([
            'dte.firma.cert_password' => 'clave-del-certificado',
            'documentos_recibidos.mail.password' => 'clave-imap',
            'dte.transmision.password_testing' => 'clave-mh',
        ]);

        $html = $this->get(route('configuracion.resumen'))->assertOk()->getContent();

        foreach ([self::SECRETO, 'clave-del-certificado', 'clave-imap', 'clave-mh'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $html, 'Se filtró un secreto en el Resumen.');
        }
    }

    /** De un secreto se dice si está, y esa es toda la información publicable. */
    public function test_de_los_secretos_solo_se_publica_si_estan_configurados(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $this->get(route('configuracion.resumen'))
            ->assertOk()
            ->assertSee('Contraseña: configurada');
    }

    /** Tampoco se filtra por el DTO, que es lo que un JSON futuro serializaría. */
    public function test_las_tarjetas_no_llevan_secretos_al_serializarse(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $json = json_encode(
            array_map(static fn (TarjetaResumen $t) => $t->toArray(), app(ResumenConfiguracion::class)->tarjetas()),
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringNotContainsString(self::SECRETO, (string) $json);
    }

    // ----------------------------------------------------------------- red

    /**
     * El Resumen no puede colgarse esperando a Hacienda o al firmador. `Http::fake()`
     * sin rutas hace que CUALQUIER petición saliente devuelva 200 vacío, y
     * `assertNothingSent` demuestra que no se intentó ninguna.
     */
    public function test_el_resumen_no_hace_ninguna_llamada_de_red(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->get(route('configuracion.resumen'))->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ contenido

    public function test_muestra_una_tarjeta_por_area_del_sistema(): void
    {
        $claves = array_map(
            static fn (TarjetaResumen $t) => $t->clave,
            app(ResumenConfiguracion::class)->tarjetas(),
        );

        foreach (['ambiente_fiscal', 'modo_dte', 'firmador', 'hacienda', 'smtp', 'gmail', 'imap', 'respaldos', 'cola', 'planta', 'rutas'] as $esperada) {
            $this->assertContains($esperada, $claves, "Falta la tarjeta «{$esperada}» en el Resumen.");
        }
    }

    /**
     * El ambiente fiscal se informa, pero esta pantalla no lo cambia — y la que hay
     * al otro lado del enlace, tampoco.
     *
     * La tarjeta pasó de no tener enlace a tener uno. La garantía NO cambió: sigue
     * siendo que ningún botón invite a cambiar el ambiente. Lo que cambió es que ya
     * existe una pantalla donde MIRARLO en detalle, y esconderla no protegía nada;
     * lo que protege es que el rótulo diga «Ver detalle» y no «Configurar», y que
     * el destino sea de solo lectura.
     */
    public function test_el_ambiente_fiscal_aparece_como_solo_lectura(): void
    {
        config(['dte.ambiente' => '00']);

        $tarjeta = $this->tarjeta('ambiente_fiscal');

        $this->assertSame(EstadoTarjeta::SoloLectura, $tarjeta->estado);
        $this->assertStringContainsString('PRUEBAS', $tarjeta->detalle);
        $this->assertSame(route('configuracion.fiscal.hacienda'), $tarjeta->ruta);
        $this->assertSame('Ver detalle', $tarjeta->etiquetaRuta,
            'El ambiente fiscal no debe ofrecer un botón que insinúe que se cambia desde ahí.');
    }

    /**
     * Y el destino de ese enlace no acepta escritura por ningún verbo. Sin esto, el
     * test de arriba solo comprobaría el rótulo del botón.
     */
    public function test_la_pantalla_enlazada_desde_el_ambiente_fiscal_no_acepta_escritura(): void
    {
        foreach (['put', 'patch', 'delete'] as $verbo) {
            $this->actingAs($this->admin())
                ->$verbo(route('configuracion.fiscal.hacienda'))
                ->assertStatus(405);
        }
    }

    public function test_la_tarjeta_de_smtp_enlaza_a_la_pantalla_de_correo(): void
    {
        $this->assertSame(route('configuracion.correo.edit'), $this->tarjeta('smtp')->ruta);
    }

    /** Nada de enlaces muertos: si hay ruta, existe. */
    public function test_ninguna_tarjeta_enlaza_a_una_pantalla_inexistente(): void
    {
        $html = $this->actingAs($this->admin())->get(route('configuracion.resumen'))->assertOk()->getContent();

        foreach (app(ResumenConfiguracion::class)->tarjetas() as $tarjeta) {
            if ($tarjeta->ruta === null) {
                continue;
            }

            $this->assertStringContainsString($tarjeta->ruta, $html);

            // "Enlace muerto" es 404, no "no es 200": conectar Gmail responde con
            // una redirección al consentimiento de Google y eso es correcto.
            $respuesta = $this->actingAs($this->admin())->get($tarjeta->ruta);

            $this->assertNotSame(
                404,
                $respuesta->getStatusCode(),
                "El enlace de la tarjeta «{$tarjeta->clave}» no lleva a ninguna parte.",
            );
        }
    }

    /** El timestamp real viaja aparte del texto "hace un rato". */
    public function test_la_ultima_verificacion_conserva_la_fecha_exacta(): void
    {
        $this->actingAs($this->admin());

        app(RegistroVerificaciones::class)->registrar(
            'smtp',
            ResultadoVerificacion::Exito,
            'Todo correcto.',
        );

        $tarjeta = $this->tarjeta('smtp');

        $this->assertNotNull($tarjeta->ultimaVerificacion);
        $this->assertNotNull($tarjeta->verificacionRelativa());
        $this->assertSame($tarjeta->ultimaVerificacion->format('d/m/Y H:i'), $tarjeta->verificacionExacta());
    }

    private function tarjeta(string $clave): TarjetaResumen
    {
        foreach (app(ResumenConfiguracion::class)->tarjetas() as $tarjeta) {
            if ($tarjeta->clave === $clave) {
                return $tarjeta;
            }
        }

        $this->fail("No existe la tarjeta «{$clave}».");
    }
}
