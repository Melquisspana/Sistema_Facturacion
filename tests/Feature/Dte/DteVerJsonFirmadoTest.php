<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ver/descargar el JWS firmado localmente desde la vista del DTE. SOLO LECTURA del
 * archivo apuntado por json_firmado_path: no transmite, no cambia estado, no guarda
 * sello, no toca BD. Verifica autorización (gestores) y seguridad de ruta.
 */
class DteVerJsonFirmadoTest extends TestCase
{
    use RefreshDatabase;

    private const JWS = 'eyJhbGciOiJSUzI1NiJ9.eyJkdGUiOiJmYWtlIn0.firma-falsa-de-prueba';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
        Storage::fake('local');

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ccfGenerado(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function ccfFirmado(): Dte
    {
        $ccf = $this->ccfGenerado();
        $cg = 'B58C589F-F27A-43EE-8EE8-A6E9B4C968BF';
        $ruta = 'dte/firmados/dte-03-'.$ccf->id.'-'.$cg.'.jws';
        Storage::disk('local')->put($ruta, self::JWS);

        $ccf->numero_control = 'DTE-03-M001P001-000000000000012';
        $ccf->codigo_generacion = $cg;
        $ccf->json_firmado_path = $ruta;
        $ccf->save();

        return $ccf->refresh();
    }

    // --- Autorización / lectura ---

    public function test_gestor_ve_el_jws_firmado(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.firmado', $ccf))
            ->assertOk()
            ->assertSee(self::JWS);
    }

    public function test_descarga_el_jws_firmado(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.firmado.descargar', $ccf))
            ->assertOk()
            ->assertDownload('dte-03-'.$ccf->id.'-B58C589F-F27A-43EE-8EE8-A6E9B4C968BF.jws');
    }

    public function test_consulta_no_puede_ver_el_jws(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.firmado', $ccf))
            ->assertForbidden();
    }

    public function test_invitado_no_puede_ver_el_jws(): void
    {
        $ccf = $this->ccfFirmado();

        $this->get(route('facturacion.firmado', $ccf))->assertRedirect('/login');
        $this->get(route('facturacion.firmado.descargar', $ccf))->assertRedirect('/login');
    }

    // --- Vista show ---

    public function test_show_muestra_panel_firmado_con_botones(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('DTE firmado localmente')
            ->assertSee('FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA')
            ->assertSee($ccf->numero_control)
            ->assertSee($ccf->codigo_generacion)
            ->assertSee('Ver JWS firmado')
            ->assertSee('Descargar JWS firmado');
    }

    public function test_show_no_muestra_panel_firmado_sin_jws(): void
    {
        $ccf = $this->ccfGenerado(); // sin json_firmado_path

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('DTE firmado localmente')
            ->assertDontSee('Ver JWS firmado');
    }

    // --- Seguridad ---

    public function test_no_permite_leer_archivos_fuera_de_storage(): void
    {
        $ccf = $this->ccfFirmado();
        $ccf->forceFill(['json_firmado_path' => 'dte/firmados/../../../.env'])->saveQuietly();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.firmado', $ccf->refresh()))
            ->assertNotFound();
    }

    public function test_archivo_inexistente_da_error_claro(): void
    {
        $ccf = $this->ccfFirmado();
        Storage::disk('local')->delete($ccf->json_firmado_path);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.firmado', $ccf))
            ->assertNotFound();
    }

    public function test_ver_y_descargar_no_modifican_el_dte(): void
    {
        $ccf = $this->ccfFirmado();
        $antes = $ccf->only(['estado', 'json_firmado_path', 'sello_recepcion', 'updated_at']);

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->get(route('facturacion.firmado', $ccf))->assertOk();
        $this->actingAs($admin)->get(route('facturacion.firmado.descargar', $ccf))->assertOk();

        $ccf->refresh();
        $this->assertSame($antes['estado'], $ccf->estado);
        $this->assertSame($antes['json_firmado_path'], $ccf->json_firmado_path);
        $this->assertNull($ccf->sello_recepcion);   // NO se transmitió
        $this->assertEquals($antes['updated_at'], $ccf->updated_at);
    }
}
