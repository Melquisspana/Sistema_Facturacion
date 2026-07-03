<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\ActividadEconomica;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteJsonService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Botón "Generar JSON oficial preliminar" desde la vista del DTE. Usa el mismo
 * DteJsonService que el comando dte:generar-json. NO firma, NO transmite, NO guarda
 * sello, NO cambia estado a aceptado.
 */
class DteGenerarJsonUiTest extends TestCase
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
        Storage::fake('local');
        $this->seed(CatalogosMhSeeder::class);
        CatalogoMh::insert([
            ['cat' => '014', 'codigo' => '59', 'valor' => 'Unidad'],
            ['cat' => '015', 'codigo' => '20', 'valor' => 'Impuesto al Valor Agregado 13%'],
            ['cat' => '019', 'codigo' => '10730', 'valor' => 'Elaboración de cacao, chocolate y confitería'],
        ]);

        $actividad = ActividadEconomica::where('codigo', '10730')->first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();

        $empresa = Empresa::create([
            'razon_social' => 'Dulces La Negrita', 'nombre_comercial' => 'La Negrita',
            'nit' => '0614-000000-000-0', 'nrc' => '111111-1',
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'direccion' => 'Calle Principal', 'telefono' => '2200-0000', 'correo' => 'fact@negrita.sv',
            'ambiente' => '00', 'activo' => true,
        ]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'tipo_establecimiento' => '01', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ccfBorrador(): Dte
    {
        $actividad = ActividadEconomica::where('codigo', '10730')->first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();

        $cliente = Cliente::factory()->contribuyente()->create([
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'nrc' => '1234', 'direccion' => 'Av Cliente 123', 'telefono' => null, 'correo' => null,
        ]);
        $unidad = UnidadMedida::whereNotNull('codigo')->first();
        $producto = Producto::factory()->create(['unidad_medida_id' => $unidad->id, 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        return $dte->refresh();
    }

    private function ccfGenerado(): Dte
    {
        $dte = $this->ccfBorrador();
        app(DteGeneracionService::class)->generar($dte);

        // Documento "viejo" SIN JSON ni numeración (la generación ahora los crea de forma
        // atómica): el botón de la UI existe justo para ese backfill (policy generarJson).
        $dte->refresh();
        if ($dte->json_generado_path) {
            Storage::disk('local')->delete($dte->json_generado_path);
        }
        $dte->json_generado_path = null;
        $dte->numero_control = null;
        $dte->codigo_generacion = null;
        $dte->saveQuietly();

        return $dte->refresh();
    }

    // --- Visibilidad del botón ---

    public function test_boton_aparece_si_generado_y_sin_json(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('JSON oficial pendiente')
            ->assertSee('Generar JSON oficial');
    }

    public function test_boton_no_aparece_si_ya_tiene_json(): void
    {
        $ccf = $this->ccfGenerado();
        app(DteJsonService::class)->generar($ccf);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf->refresh()))
            ->assertOk()
            ->assertDontSee('Generar JSON oficial preliminar')
            ->assertSee('Ver JSON generado'); // ahora aparecen los de ver/descargar
    }

    public function test_boton_no_aparece_en_borrador(): void
    {
        $ccf = $this->ccfBorrador();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Generar JSON oficial preliminar');
    }

    public function test_consulta_no_ve_el_boton(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Generar JSON oficial preliminar');
    }

    // --- Autorización del POST ---

    public function test_consulta_no_puede_generar(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.json.generar', $ccf))
            ->assertForbidden();

        $this->assertNull($ccf->refresh()->json_generado_path);
    }

    public function test_contador_no_puede_generar(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('contador'))
            ->post(route('facturacion.json.generar', $ccf))
            ->assertForbidden();

        $this->assertNull($ccf->refresh()->json_generado_path);
    }

    // --- Generación feliz ---

    public function test_facturacion_genera_json_valido_y_redirige(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.json.generar', $ccf))
            ->assertRedirect(route('facturacion.show', $ccf))
            ->assertSessionHas('status');

        $ccf->refresh();
        $this->assertNotNull($ccf->numero_control);
        $this->assertNotNull($ccf->codigo_generacion);
        $this->assertNotNull($ccf->json_generado_path);
        Storage::disk('local')->assertExists($ccf->json_generado_path);
    }

    public function test_mensaje_indica_sin_firma_sin_transmision(): void
    {
        $ccf = $this->ccfGenerado();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.json.generar', $ccf));

        $resp->assertRedirect(route('facturacion.show', $ccf));
        $this->assertStringContainsString('SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA', session('status'));
    }

    public function test_no_firma_no_transmite_no_cambia_estado(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.json.generar', $ccf));

        $ccf->refresh();
        $this->assertSame(EstadoDte::Generado, $ccf->estado); // NO pasa a aceptado
        $this->assertNull($ccf->json_firmado_path);           // NO se firmó
        $this->assertNull($ccf->sello_recepcion);             // NO se transmitió
    }

    // --- No regenera ---

    public function test_no_regenera_si_ya_existe_json(): void
    {
        $ccf = $this->ccfGenerado();
        app(DteJsonService::class)->generar($ccf);
        $ccf->refresh();
        $pathOriginal = $ccf->json_generado_path;
        $cgOriginal = $ccf->codigo_generacion;

        // La policy bloquea generar de nuevo desde la UI (blank(json_generado_path) es falso).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.json.generar', $ccf))
            ->assertForbidden();

        $ccf->refresh();
        $this->assertSame($pathOriginal, $ccf->json_generado_path);
        $this->assertSame($cgOriginal, $ccf->codigo_generacion);
    }

    public function test_no_permite_generar_en_borrador(): void
    {
        $ccf = $this->ccfBorrador();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.json.generar', $ccf))
            ->assertForbidden();

        $this->assertNull($ccf->refresh()->json_generado_path);
    }
}
