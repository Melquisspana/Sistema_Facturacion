<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Fiscal\EstadoFirmador;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Models\Empresa;
use App\Models\User;
use App\Models\VerificacionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración → Facturación electrónica → Certificado y firmador.
 *
 * Dos garantías, y las dos son sobre lo que la pantalla NO hace:
 *
 *  1. la prueba de firma usa un documento inventado y nunca el certificado real;
 *  2. la contraseña del certificado no sale por ninguna respuesta HTTP.
 *
 * Y una sobre lo que sí dice: que el NIT del certificado y el del emisor
 * coinciden. Si divergen, cada documento se firma con el certificado de otro
 * contribuyente y no hay nada más en el sistema que lo detecte.
 */
class FirmadorFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'dte.firmador.url' => 'http://localhost:8080/firmardocumento',
            'dte.firma.enabled' => false,
            'dte.firma.mock' => false,
            'dte.firma.nit' => '',
            'dte.firma.cert_password' => '',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_carga_sin_hablar_con_el_firmador(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertOk()
            ->assertSee('Certificado y firmador');
    }

    /**
     * La pregunta que hace todo el mundo al abrir esta pantalla es dónde ve el
     * vencimiento del certificado. Que la respuesta esté escrita ahí no es un
     * detalle de redacción: sin ella, el hueco parece un fallo y el paso siguiente
     * es pedir un formulario para subir el .crt.
     */
    public function test_explica_por_que_no_muestra_vencimiento_ni_huella(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertOk()
            ->assertSee('El certificado no está en esta aplicación')
            ->assertSee('huella digital SHA-256', escape: false);
    }

    public function test_no_ofrece_ninguna_forma_de_subir_el_certificado(): void
    {
        Http::preventStrayRequests();

        $respuesta = $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertOk();

        $respuesta->assertDontSee('type="file"', escape: false);
        $respuesta->assertDontSee('multipart/form-data', escape: false);
    }

    public function test_no_publica_la_contrasena_del_certificado(): void
    {
        Http::preventStrayRequests();
        config(['dte.firma.cert_password' => 'clave_del_certificado_real']);

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertOk()
            ->assertDontSee('clave_del_certificado_real')
            ->assertSee('configurada');
    }

    public function test_avisa_cuando_el_nit_del_certificado_no_es_el_del_emisor(): void
    {
        Http::preventStrayRequests();
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-111111-111-1', 'activo' => true]);
        config(['dte.firma.nit' => '06142222222222']);

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertOk()
            ->assertSee('se firmaría con el certificado de otro NIT', escape: false);
    }

    public function test_solo_el_administrador_entra(): void
    {
        $this->get('/configuracion/facturacion-electronica/firmador')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/configuracion/facturacion-electronica/firmador')
            ->assertForbidden();
    }

    // --------------------------------------------------------------- prueba

    /**
     * El resultado BUENO es que el firmador rechace el documento: significa que
     * está vivo y que procesa la petición hasta darse cuenta de que no tiene ese
     * certificado.
     */
    public function test_un_rechazo_del_firmador_cuenta_como_prueba_correcta(): void
    {
        Http::fake([
            '*/firmardocumento/status' => Http::response('OK', 200),
            '*/firmardocumento/' => Http::response(['status' => 'ERROR', 'body' => ['codigo' => '811', 'mensaje' => 'Certificado no encontrado']], 200),
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/firmador/probar')
            ->assertSessionHas('status');

        $this->assertSame(ResultadoVerificacion::Exito, VerificacionConfiguracion::sole()->resultado);
        $this->assertSame(EstadoFirmador::CLAVE_VERIFICACION, VerificacionConfiguracion::sole()->clave);
    }

    /**
     * Y el resultado que parece bueno y no lo es: un firmador que firma un
     * documento inventado con un NIT de relleno y una contraseña falsa está mal
     * configurado, aunque devuelva "OK".
     */
    public function test_si_el_firmador_firma_el_documento_de_prueba_se_reporta_como_problema(): void
    {
        Http::fake([
            '*/firmardocumento/status' => Http::response('OK', 200),
            '*/firmardocumento/' => Http::response(['status' => 'OK', 'body' => 'jws.firmado.falso'], 200),
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/firmador/probar')
            ->assertSessionHas('error');

        $this->assertSame(ResultadoVerificacion::Fallo, VerificacionConfiguracion::sole()->resultado);
    }

    public function test_si_el_firmador_no_responde_se_registra_el_fallo(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/firmador/probar')
            ->assertSessionHas('error');

        $this->assertSame(ResultadoVerificacion::Fallo, VerificacionConfiguracion::sole()->resultado);
    }

    /** El corazón de la prueba: NUNCA viaja el certificado ni la contraseña reales. */
    public function test_la_prueba_nunca_manda_el_nit_ni_la_contrasena_reales(): void
    {
        config([
            'dte.firma.nit' => '06141111111111',
            'dte.firma.cert_password' => 'clave_del_certificado_real',
            'dte.firma.enabled' => true,
        ]);

        Http::fake([
            '*/firmardocumento/status' => Http::response('OK', 200),
            '*/firmardocumento/' => Http::response(['status' => 'ERROR', 'body' => ['codigo' => '811', 'mensaje' => 'no']], 200),
        ]);

        $this->actingAs($this->admin())->post('/configuracion/facturacion-electronica/firmador/probar');

        Http::assertSent(function ($peticion) {
            if (! str_ends_with($peticion->url(), '/firmardocumento/')) {
                return true; // el health check no lleva cuerpo
            }

            $cuerpo = $peticion->data();

            return $cuerpo['nit'] === '00000000000000'
                && $cuerpo['passwordPri'] !== 'clave_del_certificado_real';
        });
    }

    public function test_probar_no_crea_ni_modifica_ningun_documento(): void
    {
        Http::fake([
            '*/firmardocumento/status' => Http::response('OK', 200),
            '*/firmardocumento/' => Http::response(['status' => 'ERROR', 'body' => ['codigo' => '811', 'mensaje' => 'no']], 200),
        ]);

        $this->actingAs($this->admin())->post('/configuracion/facturacion-electronica/firmador/probar');

        $this->assertDatabaseCount('dtes', 0);
        $this->assertFalse((bool) config('dte.firma.enabled'));
    }

    public function test_sin_direccion_del_firmador_no_se_intenta_nada(): void
    {
        Http::preventStrayRequests();
        config(['dte.firmador.url' => '']);

        $this->actingAs($this->admin())
            ->post('/configuracion/facturacion-electronica/firmador/probar')
            ->assertSessionHas('error');

        $this->assertSame(0, VerificacionConfiguracion::count());
    }
}
