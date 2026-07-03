<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
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
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase de PREPARACIÓN de firma. Verifica diagnóstico (dte:firma-check) y las
 * precondiciones del DteFirmaService. NADIE firma ni transmite: la firma real
 * está deshabilitada (dte.firma.enabled=false).
 */
class DteFirmaPreparacionTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Por defecto: el firmador responde OK al status. Ningún test hace red real;
        // los casos específicos sobreescriben este fake.
        Http::fake(['*/firmardocumento/status' => Http::response('Application is running...!!!', 200)]);
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function servicio(): DteFirmaService
    {
        return app(DteFirmaService::class);
    }

    /** CCF generado SIN JSON (documento viejo): la generación ya crea el JSON, se quita a propósito. */
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

        $dte->refresh();
        $dte->json_generado_path = null;
        $dte->saveQuietly();

        return $dte->refresh();
    }

    /** CCF generado con JSON generado (numeración + archivo en disco). */
    private function ccfConJson(): Dte
    {
        $ccf = $this->ccfGenerado();
        $cg = '16323C76-AAAA-44AE-912C-AE8CBF880D5D';
        $ruta = 'dte/json/dte-03-'.$ccf->id.'-'.$cg.'.json';
        Storage::disk('local')->put($ruta, '{"identificacion":{"codigoGeneracion":"'.$cg.'"}}');

        $ccf->numero_control = 'DTE-03-M001P001-000000000000001';
        $ccf->codigo_generacion = $cg;
        $ccf->json_generado_path = $ruta;
        $ccf->save();

        return $ccf->refresh();
    }

    // --- Comando dte:firma-check ---

    public function test_firma_check_pasa_si_generado_con_json(): void
    {
        $ccf = $this->ccfConJson();

        $this->artisan('dte:firma-check', ['dte' => $ccf->id])
            ->expectsOutputToContain('LISTO para firmar')
            ->expectsOutputToContain('NO SE FIRMÓ / NO SE TRANSMITIÓ')
            ->assertExitCode(0);
    }

    public function test_firma_check_falla_claro_si_no_tiene_json(): void
    {
        $ccf = $this->ccfGenerado(); // sin json_generado_path

        $this->artisan('dte:firma-check', ['dte' => $ccf->id])
            ->expectsOutputToContain('NO está listo para firmar')
            ->assertExitCode(1);
    }

    public function test_firma_check_falla_claro_si_es_borrador(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores = app(DteBorradorService::class);
        $borrador = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($borrador, $producto, cantidad: 10);

        $this->artisan('dte:firma-check', ['dte' => $borrador->id])
            ->expectsOutputToContain('NO está listo para firmar')
            ->assertExitCode(1);
    }

    public function test_firma_check_no_existe_dte(): void
    {
        $this->artisan('dte:firma-check', ['dte' => 999999])
            ->expectsOutputToContain('No existe el DTE')
            ->assertExitCode(1);
    }

    public function test_firma_check_no_modifica_la_bd(): void
    {
        $ccf = $this->ccfConJson();
        $antes = $ccf->only(['estado', 'numero_control', 'codigo_generacion', 'json_generado_path', 'json_firmado_path', 'sello_recepcion', 'updated_at']);

        $this->artisan('dte:firma-check', ['dte' => $ccf->id])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame($antes['estado'], $ccf->estado);
        $this->assertSame($antes['json_generado_path'], $ccf->json_generado_path);
        $this->assertNull($ccf->json_firmado_path); // sigue sin firmar
        $this->assertNull($ccf->sello_recepcion);    // sin sello
        $this->assertEquals($antes['updated_at'], $ccf->updated_at);
    }

    // --- DteFirmaService: precondiciones ---

    public function test_servicio_no_firma_sin_json_generado(): void
    {
        $ccf = $this->ccfGenerado(); // sin json_generado_path

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_servicio_no_refirma_si_ya_existe_json_firmado(): void
    {
        $ccf = $this->ccfConJson();
        $ccf->json_firmado_path = 'dte/firmados/dte-03-'.$ccf->id.'.json';
        $ccf->save();

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf->refresh());
    }

    public function test_servicio_no_firma_si_falta_el_archivo(): void
    {
        $ccf = $this->ccfConJson();
        Storage::disk('local')->delete($ccf->json_generado_path); // path en BD, archivo no

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_servicio_no_firma_borrador(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $borrador = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($borrador);
    }

    public function test_servicio_listo_pero_firma_deshabilitada_se_detiene_seguro(): void
    {
        config()->set('dte.firma.enabled', false);
        $ccf = $this->ccfConJson();

        // Pasa todas las precondiciones, pero NO firma: parada segura.
        $this->expectException(DteFirmaDeshabilitadaException::class);
        $this->servicio()->firmar($ccf);

        // No quedó nada firmado.
        $ccf->refresh();
        $this->assertNull($ccf->json_firmado_path);
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
    }

    public function test_diagnostico_no_toca_bd_y_reporta_listo(): void
    {
        $ccf = $this->ccfConJson();
        $r = $this->servicio()->diagnosticar($ccf);

        $this->assertTrue($r['listo']);
        $this->assertSame([], $r['problemas']);
        $ccf->refresh();
        $this->assertNull($ccf->json_firmado_path);
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
    }

    // --- Health check del firmador (sin firmar) ---

    public function test_health_check_ok_mockeado(): void
    {
        Http::fake(['*/firmardocumento/status' => Http::response('Application is running...!!!', 200)]);

        $h = $this->servicio()->healthCheck();

        $this->assertTrue($h['disponible']);
        $this->assertSame(200, $h['status']);
        $this->assertStringContainsString('Application is running', $h['mensaje']);
        $this->assertStringContainsString('/firmardocumento/status', $h['url']);
    }

    public function test_health_check_falla_si_conexion_rechazada(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $h = $this->servicio()->healthCheck();

        $this->assertFalse($h['disponible']);
        $this->assertNull($h['status']);
        $this->assertStringContainsString('No se pudo conectar', $h['mensaje']);
    }

    public function test_health_check_no_hace_post_ni_firma(): void
    {
        Http::fake(['*/firmardocumento/status' => Http::response('Application is running...!!!', 200)]);

        $this->servicio()->healthCheck();

        // Solo se hizo el GET del status: ningún POST al endpoint de firma.
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/firmardocumento/status'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_firma_check_muestra_firmador_disponible(): void
    {
        Http::fake(['*/firmardocumento/status' => Http::response('Application is running...!!!', 200)]);
        $ccf = $this->ccfConJson();

        $this->artisan('dte:firma-check', ['dte' => $ccf->id])
            ->expectsOutputToContain('Firmador local (health check)')
            ->expectsOutputToContain('El firmador local responde')
            ->expectsOutputToContain('/firmardocumento/status')
            ->assertExitCode(0);
    }

    public function test_firma_check_muestra_firmador_no_disponible(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });
        $ccf = $this->ccfConJson();

        $this->artisan('dte:firma-check', ['dte' => $ccf->id])
            ->expectsOutputToContain('El firmador local NO responde')
            ->assertExitCode(0); // las precondiciones del DTE siguen OK

        // No se modificó la BD ni se firmó.
        $ccf->refresh();
        $this->assertNull($ccf->json_firmado_path);
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
    }
}
