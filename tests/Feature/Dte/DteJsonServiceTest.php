<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteJsonException;
use App\Exceptions\Dte\DteJsonInvalidoException;
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
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteJsonService;
use App\Services\Dte\DteSchemaValidator;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DteJsonServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CatalogosMhSeeder::class);
        // Catálogos MH que usa el serializador (mínimos para que el CCF valide).
        CatalogoMh::insert([
            ['cat' => '014', 'codigo' => '59', 'valor' => 'Unidad'],
            ['cat' => '015', 'codigo' => '20', 'valor' => 'Impuesto al Valor Agregado 13%'],
            ['cat' => '019', 'codigo' => '10730', 'valor' => 'Elaboración de cacao, chocolate y confitería'],
        ]);
    }

    private function servicio(): DteJsonService
    {
        return app(DteJsonService::class);
    }

    private function ccfGenerado(): Dte
    {
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
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'tipo_establecimiento' => '01', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $cliente = Cliente::factory()->contribuyente()->create([
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'nrc' => '1234', 'direccion' => 'Av Cliente 123', 'telefono' => null, 'correo' => null,
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
        // atómica): estos tests prueban justamente el servicio que los asigna.
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

    // --- Generación feliz ---

    public function test_genera_numeracion_y_guarda_path(): void
    {
        $ccf = $this->ccfGenerado();

        $r = $this->servicio()->generar($ccf);
        $ccf->refresh();

        $this->assertNotNull($ccf->numero_control);
        $this->assertNotNull($ccf->codigo_generacion);
        $this->assertSame($r['numeroControl'], $ccf->numero_control);
        $this->assertStringStartsWith('DTE-03-M001P001-', $ccf->numero_control);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', $ccf->codigo_generacion);
        $this->assertSame($r['ruta'], $ccf->json_generado_path);
        Storage::disk('local')->assertExists($ccf->json_generado_path);
    }

    public function test_el_json_guardado_valida_contra_el_schema(): void
    {
        $ccf = $this->ccfGenerado();
        $r = $this->servicio()->generar($ccf);

        $contenido = json_decode(Storage::disk('local')->get($r['ruta']), true);
        $res = app(DteSchemaValidator::class)->validar($contenido, TipoDte::CreditoFiscal);

        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_no_firma_ni_transmite_ni_cambia_estado(): void
    {
        $ccf = $this->ccfGenerado();
        $this->servicio()->generar($ccf);
        $ccf->refresh();

        $this->assertSame(EstadoDte::Generado, $ccf->estado);   // NO pasa a aceptado/firmado/enviado
        $this->assertNull($ccf->sello_recepcion);               // NO hay sello
        $this->assertNull($ccf->json_firmado_path);             // NO se firmó
    }

    // --- Idempotencia / freeze ---

    public function test_no_regenera_si_ya_existe_sin_force(): void
    {
        $ccf = $this->ccfGenerado();
        $this->servicio()->generar($ccf);

        $this->expectException(DteJsonException::class);
        $this->servicio()->generar($ccf->refresh());
    }

    public function test_force_regenera_pero_congela_la_numeracion(): void
    {
        $ccf = $this->ccfGenerado();
        $primera = $this->servicio()->generar($ccf);

        $segunda = $this->servicio()->generar($ccf->refresh(), force: true);

        // La numeración asignada una sola vez se conserva.
        $this->assertSame($primera['numeroControl'], $segunda['numeroControl']);
        $this->assertSame($primera['codigoGeneracion'], $segunda['codigoGeneracion']);
    }

    // --- Validación fallida → transacción no deja nada a medias ---

    public function test_si_el_schema_falla_no_guarda_numeracion_ni_path(): void
    {
        $ccf = $this->ccfGenerado();
        // Sin CAT-019, descActividad queda vacío → el schema rechaza (minLength).
        CatalogoMh::where('cat', '019')->delete();

        try {
            $this->servicio()->generar($ccf);
            $this->fail('Debió lanzar DteJsonInvalidoException.');
        } catch (DteJsonInvalidoException $e) {
            $this->assertNotEmpty($e->errores);
        }

        $ccf->refresh();
        $this->assertNull($ccf->numero_control);        // rollback: no quedó numeración a medias
        $this->assertNull($ccf->codigo_generacion);
        $this->assertNull($ccf->json_generado_path);
    }

    // --- Precondiciones ---

    public function test_no_permite_borrador(): void
    {
        $actividad = ActividadEconomica::where('codigo', '10730')->first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();
        $empresa = Empresa::create(['razon_social' => 'X', 'nit' => '0614-000000-000-0', 'nrc' => '111111-1', 'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id, 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'M', 'tipo_establecimiento' => '01', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'C', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create(['actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id]);
        $borrador = app(DteBorradorService::class)->crearBorrador(['tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id]);

        $this->expectException(DteJsonException::class);
        $this->servicio()->generar($borrador);
    }

    // --- Comando ---

    public function test_comando_muestra_ruta_y_aviso_sin_firma(): void
    {
        $ccf = $this->ccfGenerado();

        $this->artisan('dte:generar-json', ['dte' => $ccf->id])
            ->expectsOutputToContain('numeroControl')
            ->expectsOutputToContain('SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA')
            ->assertExitCode(0);

        $this->assertNotNull($ccf->refresh()->json_generado_path);
    }

    public function test_comando_bloquea_si_ya_existe_json(): void
    {
        $ccf = $this->ccfGenerado();
        $this->servicio()->generar($ccf);

        $this->artisan('dte:generar-json', ['dte' => $ccf->id])
            ->expectsOutputToContain('ya tiene JSON generado')
            ->assertExitCode(1);
    }
}
