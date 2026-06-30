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
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Checkpoint de seguridad: el diagnóstico no filtra secretos, el .env.example no
 * tiene valores reales, y firma/transmisión siguen bloqueadas por defecto.
 */
class DteSeguridadCheckTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'SECRETO_QUE_NO_DEBE_APARECER';

    private const TOKEN = 'TOKEN_QUE_NO_DEBE_APARECER';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CatalogosMhSeeder::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
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
        $rutaJson = 'dte/json/dte-03-'.$dte->id.'-'.$cg.'.json';
        $rutaJws = 'dte/firmados/dte-03-'.$dte->id.'-'.$cg.'.jws';
        Storage::disk('local')->put($rutaJson, '{"ok":true}');
        Storage::disk('local')->put($rutaJws, 'eyJ.fake.jws');
        $dte->numero_control = 'DTE-03-M001P001-000000000000012';
        $dte->codigo_generacion = $cg;
        $dte->json_generado_path = $rutaJson;
        $dte->json_firmado_path = $rutaJws;
        // Flujo alineado: un documento firmado está en estado Firmado.
        $dte->estado = \App\Enums\EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    // --- El comando de seguridad no filtra secretos ---

    public function test_seguridad_check_no_imprime_password_ni_token(): void
    {
        config()->set('dte.firma.cert_password', self::PW);
        config()->set('dte.transmision.password', self::PW);
        config()->set('dte.transmision.token', self::TOKEN);

        $this->artisan('dte:seguridad-check')
            ->doesntExpectOutputToContain(self::PW)
            ->doesntExpectOutputToContain(self::TOKEN)
            ->expectsOutputToContain('NO se imprime ningún secreto')
            ->assertExitCode(0);
    }

    public function test_seguridad_check_muestra_estado_sin_valores(): void
    {
        config()->set('dte.firma.cert_password', self::PW);

        $this->artisan('dte:seguridad-check')
            ->expectsOutputToContain('configurada (oculta)') // estado, nunca el valor
            ->assertExitCode(0);
    }

    // --- .env.example sin valores reales ---

    public function test_env_example_no_tiene_valores_reales(): void
    {
        $contenido = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DTE_FIRMA_ENABLED=false\s*$/m', $contenido);
        $this->assertMatchesRegularExpression('/^DTE_TRANSMISION_ENABLED=false\s*$/m', $contenido);
        // Claves de secretos: presentes pero VACÍAS.
        $this->assertMatchesRegularExpression('/^DTE_CERT_PASSWORD=\s*$/m', $contenido);
        $this->assertMatchesRegularExpression('/^DTE_FIRMA_NIT=\s*$/m', $contenido);
        $this->assertMatchesRegularExpression('/^DTE_TRANSMISION_PASSWORD=\s*$/m', $contenido);
        $this->assertMatchesRegularExpression('/^DTE_TRANSMISION_TOKEN=\s*$/m', $contenido);
    }

    // --- Firma/transmisión siguen bloqueadas ---

    public function test_transmitir_sigue_bloqueado_si_deshabilitado(): void
    {
        config()->set('dte.transmision.enabled', false);
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:transmitir', ['dte' => $ccf->id])
            ->expectsOutputToContain('Transmisión deshabilitada. No se envió nada a Hacienda.')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull($ccf->refresh()->sello_recepcion);
    }

    public function test_firmar_sigue_bloqueado_si_deshabilitado(): void
    {
        config()->set('dte.firma.enabled', false);
        Http::fake();
        $ccf = $this->ccfFirmado();
        // Volver a Generado y quitar el firmado para llegar al bloqueo de firma.
        $ccf->json_firmado_path = null;
        $ccf->estado = \App\Enums\EstadoDte::Generado;
        $ccf->save();

        $this->artisan('dte:firmar', ['dte' => $ccf->id])
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull($ccf->refresh()->json_firmado_path);
    }
}
