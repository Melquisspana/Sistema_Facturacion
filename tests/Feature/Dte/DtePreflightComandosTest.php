<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `dte:preflight-factura` y `dte:preflight-fex`: comandos de SOLO DIAGNÓSTICO.
 * Verifica que no invocan firma/transmisión real (solo el health-check GET del
 * firmador, nunca un POST de firma/recepción), no cambian el estado del
 * documento, y que los guards de producción de tipo 01/FEX siguen bloqueando
 * firmar/transmitir aunque el preflight nuevo diga "listo".
 */
class DtePreflightComandosTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        WorkerHeartbeat::olvidar();
        Configuracion::olvidarCache();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
    }

    private function todoVerdeComun(): void
    {
        config([
            'dte.ambiente' => '01',
            'dte.transmision.enabled' => true,
            'dte.transmision.mock' => true,
            'dte.transmision.test_enabled' => false,
            'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false,
            'dte.transmision.allow_production' => true,
            'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'respaldo',
            'dte.transmision.ambiente' => 'produccion',
        ]);
        Configuracion::set('produccion.auth_prod_validada', true);
        Configuracion::set('correo.auto_envio', false);
        WorkerHeartbeat::pulse();
        Storage::fake('local');
        $nombre = (string) config('backup.backup.name', config('app.name'));
        Storage::disk('local')->put($nombre.'/hoy.zip', 'x');
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200)]);
    }

    private function facturaGenerada(): Dte
    {
        Correlativo::create(['tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        Correlativo::create(['tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $borradores = app(DteBorradorService::class);
        // ambiente 00 explícito: generar el documento NO debe requerir producción real.
        $dte = $borradores->crearBorrador(['tipo_dte' => TipoDte::Factura, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00']);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function fexGenerada(): Dte
    {
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => Cliente::factory()->exportacion()->create(),
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00',
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    public function test_preflight_factura_no_cambia_estado_ni_hace_mas_que_el_healthcheck(): void
    {
        $this->todoVerdeComun();
        $factura = $this->facturaGenerada();

        $this->artisan('dte:preflight-factura', ['dte' => $factura->id])
            ->expectsOutputToContain('SOLO DIAGNÓSTICO');

        $factura->refresh();
        $this->assertSame('generado', $factura->estado->value);
        $this->assertNull($factura->json_firmado_path);
        $this->assertNull($factura->sello_recepcion);
        // Solo el GET de health-check del firmador; ningún POST de firma/recepción.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/status'));
    }

    public function test_preflight_fex_no_cambia_estado_ni_hace_mas_que_el_healthcheck(): void
    {
        $this->todoVerdeComun();
        $fex = $this->fexGenerada();

        $this->artisan('dte:preflight-fex', ['dte' => $fex->id])
            ->expectsOutputToContain('SOLO DIAGNÓSTICO');

        $fex->refresh();
        $this->assertSame('generado', $fex->estado->value);
        $this->assertNull($fex->json_firmado_path);
        $this->assertNull($fex->sello_recepcion);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/status'));
    }

    public function test_preflight_factura_dte_inexistente_falla_claro(): void
    {
        $this->artisan('dte:preflight-factura', ['dte' => 999999])
            ->expectsOutputToContain('No existe el DTE')
            ->assertExitCode(1);
    }

    public function test_preflight_fex_dte_inexistente_falla_claro(): void
    {
        $this->artisan('dte:preflight-fex', ['dte' => 999999])
            ->expectsOutputToContain('No existe el DTE')
            ->assertExitCode(1);
    }

    // --- El preflight es SOLO diagnóstico: dte:firmar sigue protegido por sus PROPIOS
    // candados generales (firma real deshabilitada por defecto en test), NO por un
    // guard especial de tipo (retirado: Factura/FEX ya no "están en revisión"). ---

    public function test_preflight_no_habilita_firmar_factura_por_si_solo(): void
    {
        $this->todoVerdeComun();
        // Candados de producción real ABIERTOS (mismo criterio que emisionRealPosible()).
        config(['dte.transmision.mock' => false, 'dte.transmision.modo_operacion' => 'principal']);
        $factura = $this->facturaGenerada();

        // Ya NO cae en el mensaje especial "en revisión" (retirado); sigue bloqueada por
        // el candado GENERAL de firma real deshabilitada (mismo que protegería a un CCF).
        $this->artisan('dte:firmar', ['dte' => $factura->id])
            ->doesntExpectOutputToContain('en revisión')
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);

        $factura->refresh();
        $this->assertNull($factura->json_firmado_path);
    }

    public function test_preflight_no_habilita_firmar_fex_por_si_solo(): void
    {
        $this->todoVerdeComun();
        config(['dte.transmision.mock' => false, 'dte.transmision.modo_operacion' => 'principal']);
        $fex = $this->fexGenerada();

        $this->artisan('dte:firmar', ['dte' => $fex->id])
            ->doesntExpectOutputToContain('en revisión')
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);

        $fex->refresh();
        $this->assertNull($fex->json_firmado_path);
    }
}
