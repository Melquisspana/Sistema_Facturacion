<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pantalla admin de Estado técnico / Preflight DTE. SOLO DIAGNÓSTICO: no transmite,
 * no autentica contra Hacienda, no cambia estado, no guarda sello, no muestra secretos.
 */
class DteEstadoTecnicoTest extends TestCase
{
    use RefreshDatabase;

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

    private function ccfFirmado(): Dte
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
        $dte->refresh();

        $cg = 'B58C589F-F27A-43EE-8EE8-A6E9B4C968BF';
        Storage::disk('local')->put('dte/json/dte-03-'.$dte->id.'-'.$cg.'.json', '{"ok":true}');
        Storage::disk('local')->put('dte/firmados/dte-03-'.$dte->id.'-'.$cg.'.jws', 'eyJhbGciOiJSUzUxMiJ9.cuerpo.firma-falsa-larga');
        $dte->numero_control = 'DTE-03-M001P001-000000000000012';
        $dte->codigo_generacion = $cg;
        $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'-'.$cg.'.json';
        $dte->json_firmado_path = 'dte/firmados/dte-03-'.$dte->id.'-'.$cg.'.jws';
        // El dry-run / transmisión exigen estado Firmado (flujo alineado).
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    // --- Visibilidad por rol ---

    public function test_admin_ve_panel_tecnico(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Estado técnico DTE')
            ->assertSee('Preflight de transmisión')
            ->assertSee('DTE_MODO_OPERACION');
    }

    public function test_facturacion_ve_panel_tecnico(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Estado técnico DTE')
            ->assertSee('Ejecutar dry-run visual');
    }

    public function test_consulta_no_ve_panel_ni_rutas_ni_botones(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Estado técnico DTE')
            ->assertDontSee('Ejecutar dry-run visual')
            ->assertDontSee($ccf->json_generado_path)   // ruta cruda oculta
            ->assertDontSee($ccf->json_firmado_path)
            ->assertDontSee('Ver JSON generado');
    }

    public function test_contador_no_ve_panel_tecnico(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('contador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Estado técnico DTE');
    }

    public function test_invitado_redirige_a_login(): void
    {
        $ccf = $this->ccfFirmado();

        $this->get(route('facturacion.show', $ccf))->assertRedirect('/login');
        $this->post(route('facturacion.dry-run', $ccf))->assertRedirect('/login');
    }

    // --- Modo paralelo → BLOQUEADO ---

    public function test_modo_paralelo_muestra_bloqueado(): void
    {
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('BLOQUEADO');
    }

    // --- Dry-run visual ---

    public function test_dry_run_no_hace_http_ni_cambia_estado_ni_sello(): void
    {
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.dry-run', $ccf))
            ->assertRedirect(route('facturacion.show', $ccf))
            ->assertSessionHas('dry_run');

        Http::assertNothingSent();
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Firmado, $ccf->estado); // dry-run no cambia estado (sigue Firmado)
    }

    public function test_consulta_no_puede_ejecutar_dry_run(): void
    {
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.dry-run', $ccf))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // --- Seguridad: sin secretos ---

    public function test_panel_no_imprime_secretos(): void
    {
        config()->set('dte.firma.cert_password', 'CERT_PW_SECRETO');
        config()->set('dte.transmision.password', 'TRANS_PW_SECRETO');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');
        $ccf = $this->ccfFirmado();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk();

        $resp->assertDontSee('CERT_PW_SECRETO');
        $resp->assertDontSee('TRANS_PW_SECRETO');
        $resp->assertDontSee('TOKEN_SECRETO_X');
    }
}
