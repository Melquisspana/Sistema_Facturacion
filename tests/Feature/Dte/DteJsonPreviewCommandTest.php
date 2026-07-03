<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\ActividadEconomica;
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
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DteJsonPreviewCommandTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Catálogos completos, incluida la tabla catalogos_mh (CAT-014/019...) que el
        // serializador exige para unidad y descActividad.
        $this->seedCatalogosDte();
    }

    private function ccfGenerado(): Dte
    {
        $actividad = ActividadEconomica::first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();

        $empresa = Empresa::create([
            'razon_social' => 'Dulces La Negrita', 'nombre_comercial' => 'La Negrita',
            'nit' => '0614-000000-000-0', 'nrc' => '111111-1',
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'direccion' => 'Calle Principal', 'telefono' => '2200-0000', 'correo' => 'fact@negrita.sv',
            'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'tipo_establecimiento' => '01', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $cliente = Cliente::factory()->contribuyente()->create([
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
        ]);
        $unidad = UnidadMedida::whereNotNull('codigo')->first();
        $producto = Producto::factory()->create(['unidad_medida_id' => $unidad->id, 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        // Documento "viejo" SIN JSON ni numeración (la generación ahora los crea de forma
        // atómica): el preview prueba la numeración fake y los errores de numeración vacía.
        $dte->refresh();
        if ($dte->json_generado_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($dte->json_generado_path);
        }
        $dte->json_generado_path = null;
        $dte->numero_control = null;
        $dte->codigo_generacion = null;
        $dte->saveQuietly();

        return $dte->refresh();
    }

    public function test_preview_no_modifica_el_dte_ni_cambia_estado(): void
    {
        $ccf = $this->ccfGenerado();
        $estadoAntes = $ccf->estado;

        $this->artisan('dte:json-preview', ['dte' => $ccf->id])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame($estadoAntes, $ccf->estado);          // sigue generado
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
        $this->assertNull($ccf->json_generado_path);            // no se marcó
        $this->assertNull($ccf->numero_control);                // no se inventó numeración oficial
        $this->assertNull($ccf->sello_recepcion);
    }

    public function test_preview_con_guardar_no_escribe_json_generado_path(): void
    {
        Storage::fake('local');
        $ccf = $this->ccfGenerado();

        $this->artisan('dte:json-preview', ['dte' => $ccf->id, '--guardar' => true])->assertExitCode(0);

        // El preview se guarda como archivo aparte; NO toca json_generado_path ni estado.
        Storage::disk('local')->assertExists('dte/previews/ccf-'.$ccf->id.'-preview.json');
        $ccf->refresh();
        $this->assertNull($ccf->json_generado_path);
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
    }

    public function test_preview_normal_reporta_errores_de_numeracion(): void
    {
        Storage::fake('local');
        $ccf = $this->ccfGenerado(); // sin numeración oficial

        $this->artisan('dte:json-preview', ['dte' => $ccf->id, '--guardar' => true])
            ->expectsOutputToContain('numeroControl')      // el schema lo marca (null)
            ->expectsOutputToContain('codigoGeneracion')
            ->assertExitCode(0);
    }

    public function test_fake_identificacion_elimina_los_errores_de_numeracion(): void
    {
        Storage::fake('local');
        $ccf = $this->ccfGenerado();

        $this->artisan('dte:json-preview', ['dte' => $ccf->id, '--fake-identificacion' => true, '--guardar' => true])
            ->expectsOutputToContain('SOLO PREVIEW')
            ->doesntExpectOutputToContain('numeroControl')   // ya no aparecen como error
            ->doesntExpectOutputToContain('codigoGeneracion')
            ->assertExitCode(0);
    }

    public function test_fake_identificacion_no_persiste_nada_en_el_dte(): void
    {
        $ccf = $this->ccfGenerado();
        $estadoAntes = $ccf->estado;

        $this->artisan('dte:json-preview', ['dte' => $ccf->id, '--fake-identificacion' => true])->assertExitCode(0);

        $ccf->refresh();
        $this->assertNull($ccf->numero_control);        // NO se persiste la numeración temporal
        $this->assertNull($ccf->codigo_generacion);
        $this->assertNull($ccf->json_generado_path);
        $this->assertSame($estadoAntes, $ccf->estado);  // sigue generado
    }

    public function test_preview_rechaza_documento_no_ccf_o_no_generado(): void
    {
        // Un borrador CCF (no generado) no debe procesarse.
        $borradores = app(DteBorradorService::class);
        $actividad = ActividadEconomica::first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();
        $empresa = Empresa::create(['razon_social' => 'X', 'nit' => '0614-000000-000-0', 'nrc' => '111111-1', 'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id, 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'M', 'tipo_establecimiento' => '01', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'C', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create(['actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id]);
        $borrador = $borradores->crearBorrador(['tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id]);

        $this->artisan('dte:json-preview', ['dte' => $borrador->id])->assertExitCode(1);
    }
}
