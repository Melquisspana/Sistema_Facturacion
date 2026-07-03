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
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Modo MOCK del flujo (solo local): firma y transmisión SIMULADAS, sin firmador
 * real, sin certificado, sin credenciales y SIN HTTP. Los artefactos son ficticios
 * y marcados; no valen ante Hacienda.
 */
class DteFlujoMockTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function ccfConJson(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $cg = '16323C76-AAAA-44AE-912C-AE8CBF880D5D';
        $ruta = 'dte/json/dte-03-'.$dte->id.'-'.$cg.'.json';
        Storage::disk('local')->put($ruta, '{"identificacion":{"codigoGeneracion":"'.$cg.'"}}');
        // Asignación directa: json_generado_path no está en $fillable.
        $dte->numero_control = 'DTE-03-M001P001-000000000000001';
        $dte->codigo_generacion = $cg;
        $dte->json_generado_path = $ruta;
        $dte->save();

        return $dte->refresh();
    }

    public function test_firma_mock_sin_firmador_ni_claves(): void
    {
        // Mock activo; firma real deshabilitada y SIN NIT/contraseña: igual debe firmar (simulado).
        config()->set('dte.firma.mock', true);
        config()->set('dte.firma.enabled', false);
        config()->set('dte.firma.nit', '');
        config()->set('dte.firma.cert_password', '');
        Http::preventStrayRequests(); // ningún HTTP al firmador

        $ccf = $this->ccfConJson();
        $r = app(DteFirmaService::class)->firmar($ccf);
        $ccf->refresh();

        $this->assertTrue($r['mock']);
        $this->assertNotNull($ccf->json_firmado_path);
        $this->assertStringEndsWith('.mock.jws', $ccf->json_firmado_path);
        $this->assertStringContainsString('MOCK', Storage::disk('local')->get($ccf->json_firmado_path));
        // El mock respeta el flujo real: firmar avanza Generado → Firmado.
        $this->assertSame(EstadoDte::Firmado, $ccf->estado);
        $this->assertNull($ccf->sello_recepcion); // aún sin transmitir
    }

    public function test_transmision_mock_simula_aceptado_sin_credenciales(): void
    {
        config()->set('dte.firma.mock', true);
        config()->set('dte.transmision.mock', true);
        Http::preventStrayRequests(); // ningún HTTP a Hacienda ni auth

        $ccf = $this->ccfConJson();
        app(DteFirmaService::class)->firmar($ccf); // firma mock
        $ccf->refresh();

        $r = app(DteTransmisionService::class)->transmitir($ccf);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertStringStartsWith('MOCK-SIMULADO-', (string) $r['sello']);
        // El mock respeta el flujo real: persiste el sello (ficticio) y avanza a Aceptado.
        $ccf->refresh();
        $this->assertStringStartsWith('MOCK-SIMULADO-', (string) $ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
    }
}
