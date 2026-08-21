<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Fiscal\EstadoHaciendaApi;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración → Facturación electrónica → Hacienda / API.
 *
 * Lo que estos tests fijan no es que la pantalla "se vea": es que NO PUEDE hacer
 * de más. La pantalla existe para mirar la conexión con el Ministerio de
 * Hacienda, y la única acción que ofrece —probar el acceso— tiene que quedar
 * encerrada en el ambiente de pruebas por tres caminos distintos: el candado del
 * servidor, el ambiente activo y la dirección de destino.
 */
class HaciendaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Punto de partida seguro: pruebas, todo cerrado. Cada test abre solo lo suyo.
        config([
            'dte.ambiente' => '00',
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.auth_test_real_enabled' => false,
            'dte.transmision.usuario_testing' => '',
            'dte.transmision.password_testing' => '',
            'dte.transmision.url_base' => '',
            'dte.transmision.endpoint_auth' => '/seguridad/auth',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Candado abierto + credenciales de pruebas: la única combinación que permite probar. */
    private function pruebaHabilitada(): void
    {
        config([
            'dte.transmision.auth_test_real_enabled' => true,
            'dte.transmision.usuario_testing' => 'usuario_apitest',
            'dte.transmision.password_testing' => 'clave_apitest',
        ]);
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_carga_y_no_hace_ninguna_peticion(): void
    {
        // Sin `Http::fake()` una petición saliente reventaría el test. Es la forma
        // de fijar que la pantalla NO habla con Hacienda al abrirse.
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertOk()
            ->assertSee('Hacienda / API')
            ->assertSee('Candados fiscales');
    }

    public function test_distingue_el_ambiente_del_documento_del_ambiente_de_las_credenciales(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertOk()
            ->assertSee('Dentro del documento')
            ->assertSee('Credenciales');
    }

    /**
     * El peor error posible de esta configuración: un documento marcado como
     * producción enviado con credenciales de pruebas. La pantalla tiene que
     * gritarlo, no listar los dos valores y dejar que alguien los compare.
     */
    public function test_avisa_cuando_los_dos_ambientes_no_concuerdan(): void
    {
        Http::preventStrayRequests();
        config(['dte.ambiente' => '01', 'dte.transmision.ambiente' => 'testing']);

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertOk()
            ->assertSee('El ambiente del documento y el de las credenciales no concuerdan.');
    }

    public function test_no_hay_ninguna_ruta_de_escritura_de_configuracion_fiscal(): void
    {
        // Un PUT a la propia pantalla no puede existir: es de solo lectura.
        $this->actingAs($this->admin())
            ->put('/configuracion/facturacion-electronica/hacienda', ['dte.ambiente' => '01'])
            ->assertStatus(405);

        $this->assertSame('00', (string) config('dte.ambiente'));
    }

    public function test_la_pantalla_no_publica_ninguna_credencial(): void
    {
        Http::preventStrayRequests();
        config([
            'dte.transmision.usuario_testing' => 'usuario_secreto_apitest',
            'dte.transmision.password_testing' => 'clave_secreta_apitest',
            'dte.transmision.token' => 'Bearer token_secreto',
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertOk();

        $respuesta->assertDontSee('usuario_secreto_apitest');
        $respuesta->assertDontSee('clave_secreta_apitest');
        $respuesta->assertDontSee('token_secreto');
        // Lo que sí se dice: que están.
        $respuesta->assertSee('Configuradas');
    }

    public function test_solo_el_administrador_entra(): void
    {
        $this->get('/configuracion/facturacion-electronica/hacienda')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertForbidden();
    }

    // --------------------------------------------------------------- prueba

    public function test_sin_el_candado_del_servidor_no_se_intenta_ningun_acceso(): void
    {
        Http::preventStrayRequests();
        config([
            'dte.transmision.usuario_testing' => 'usuario_apitest',
            'dte.transmision.password_testing' => 'clave_apitest',
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/hacienda/probar')
            ->assertRedirect(route('configuracion.fiscal.hacienda'))
            ->assertSessionHas('error');

        // Ni petición ni línea en el historial: no hubo comprobación que anotar.
        $this->assertSame(0, VerificacionConfiguracion::count());
    }

    public function test_en_produccion_la_prueba_no_toca_la_cuenta_real(): void
    {
        Http::preventStrayRequests();
        config([
            'dte.ambiente' => '01',
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.auth_test_real_enabled' => true,
            'dte.transmision.usuario_produccion' => 'usuario_real',
            'dte.transmision.password_produccion' => 'clave_real',
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/hacienda/probar')
            ->assertSessionHas('error');

        $this->assertSame(0, VerificacionConfiguracion::count());
    }

    public function test_el_boton_no_aparece_cuando_la_prueba_esta_bloqueada(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/hacienda')
            ->assertOk()
            ->assertDontSee(route('configuracion.fiscal.hacienda.probar'))
            ->assertSee('La prueba de acceso a pruebas está cerrada en el servidor');
    }

    public function test_con_todo_en_orden_la_prueba_inicia_sesion_contra_apitest_y_queda_registrada(): void
    {
        $this->pruebaHabilitada();
        Http::fake([
            'apitest.dtes.mh.gob.sv/*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer abc']], 200),
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/hacienda/probar')
            ->assertSessionHas('status');

        // Se pidió el token al ambiente de PRUEBAS, y nada más: ni recepción, ni
        // anulación, ni un segundo intento contra producción.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'apitest.dtes.mh.gob.sv'));

        $verificacion = VerificacionConfiguracion::sole();
        $this->assertSame(EstadoHaciendaApi::CLAVE_VERIFICACION, $verificacion->clave);
        $this->assertSame(ResultadoVerificacion::Exito, $verificacion->resultado);
    }

    /**
     * Un acceso RECHAZADO no es lo mismo que un acceso que no se intentó. El
     * historial tiene que poder distinguirlos, o se llena de errores que no lo son.
     */
    public function test_un_acceso_rechazado_se_registra_como_fallo(): void
    {
        $this->pruebaHabilitada();
        Http::fake([
            'apitest.dtes.mh.gob.sv/*' => Http::response(['status' => 'ERROR', 'message' => 'credenciales inválidas'], 401),
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/hacienda/probar')
            ->assertSessionHas('error');

        $verificacion = VerificacionConfiguracion::sole();
        $this->assertSame(ResultadoVerificacion::Fallo, $verificacion->resultado);
    }

    public function test_la_prueba_no_deja_rastro_de_la_contrasena_en_el_historial(): void
    {
        $this->pruebaHabilitada();
        Http::fake([
            'apitest.dtes.mh.gob.sv/*' => Http::response(['status' => 'ERROR', 'message' => 'no'], 401),
        ]);

        $this->actingAs($this->admin())->post('/configuracion/facturacion-electronica/hacienda/probar');

        $mensaje = (string) VerificacionConfiguracion::sole()->mensaje;
        $this->assertStringNotContainsString('clave_apitest', $mensaje);
        $this->assertStringNotContainsString('usuario_apitest', $mensaje);
    }

    public function test_probar_no_crea_ni_modifica_ningun_documento(): void
    {
        $this->pruebaHabilitada();
        Http::fake([
            'apitest.dtes.mh.gob.sv/*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer abc']], 200),
        ]);

        $this->actingAs($this->admin())->post('/configuracion/facturacion-electronica/hacienda/probar');

        $this->assertDatabaseCount('dtes', 0);
        // Y no se abrió ningún candado de transmisión por el camino.
        $this->assertFalse((bool) config('dte.transmision.enabled'));
        $this->assertTrue((bool) config('dte.transmision.dry_run'));
    }
}
