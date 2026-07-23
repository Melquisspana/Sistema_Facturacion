<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\PreflightEmisionProduccionFactura;
use App\Support\WorkerHeartbeat;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Preflight de READINESS (checklist, no emite) para Factura consumidor final
 * (tipo 01). Archivo nuevo, separado de PreflightEmisionProduccion (CCF), que no
 * se toca. Con todo verde debe permitir; al romper una precondición debe
 * bloquear e indicar cuál falta. SOLO LECTURA en todos los casos.
 */
class PreflightEmisionProduccionFacturaTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        WorkerHeartbeat::olvidar();
        Configuracion::olvidarCache();
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    /** Deja TODAS las precondiciones en verde (mismo patrón que PreflightEmisionProduccionTest de CCF). */
    private function todoVerde(): void
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
        Correlativo::create([
            'tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true,
        ]);
        WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200)]);
    }

    /** Factura con UNA línea cuyo precio_unitario (IVA incluido) define el total_pagar exacto. */
    private function facturaBorrador(?Cliente $cliente, string $precioUnitario): Dte
    {
        $datos = [
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($cliente) {
            $datos['cliente_id'] = $cliente;
        }
        $dte = app(DteBorradorService::class)->crearBorrador($datos);
        $producto = Producto::factory()->create(['precio_unitario' => $precioUnitario, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh()->load('lineas', 'cliente');
    }

    private function evaluar(Dte $factura): array
    {
        return app(PreflightEmisionProduccionFactura::class)->evaluar($factura);
    }

    public function test_todo_verde_permite(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    public function test_bloquea_si_no_hay_correlativo(): void
    {
        $this->todoVerde();
        Correlativo::where('tipo_dte', '01')->delete();
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Correlativo Factura producción (P002) existe', $r['faltantes']);
    }

    public function test_bloquea_si_worker_apagado(): void
    {
        $this->todoVerde();
        WorkerHeartbeat::olvidar();
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Worker/cola activo', $r['faltantes']);
    }

    public function test_bloquea_si_no_hay_backup_del_dia(): void
    {
        $this->todoVerde();
        \App\Models\RespaldoEjecucion::query()->update(['exitoso' => false]);
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Backup del día listo', $r['faltantes']);
    }

    public function test_bloquea_si_firmador_apagado(): void
    {
        $this->todoVerde();
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => fn () => throw new \Illuminate\Http\Client\ConnectionException('caido')]);
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Firmador activo', $r['faltantes']);
    }

    public function test_bloquea_si_candados_produccion_cerrados(): void
    {
        $this->todoVerde();
        config(['dte.transmision.modo_operacion' => 'paralelo']);
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Candados de producción correctos', $r['faltantes']);
    }

    public function test_bloquea_si_credenciales_no_validadas(): void
    {
        $this->todoVerde();
        Configuracion::set('produccion.auth_prod_validada', false);
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Credenciales producción validadas', $r['faltantes']);
    }

    public function test_bloquea_si_documento_sin_lineas(): void
    {
        $this->todoVerde();
        $vacio = Dte::create([
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'tipo_dte' => '01', 'estado' => 'borrador', 'ambiente' => '01',
            'total_pagar' => 0, 'fecha_emision' => now(), 'hora_emision' => '10:00:00',
        ]);
        $r = $this->evaluar($vacio->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Documento completo (productos, total > 0)', $r['faltantes']);
    }

    // --- Receptor obligatorio si total > $25,000 (umbral estricto, mismo criterio que ValidacionPreJsonService) ---

    public function test_total_mayor_a_25000_sin_receptor_falla(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->facturaBorrador(null, '25000.01'));

        $this->assertFalse($r['puede']);
        $this->assertTrue(collect($r['faltantes'])->contains(fn ($f) => str_contains($f, 'Receptor identificado')));
    }

    public function test_total_exactamente_25000_sin_receptor_no_falla_por_umbral(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->facturaBorrador(null, '25000.00'));

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    public function test_total_mayor_a_25000_con_receptor_identificado_no_falla(): void
    {
        $this->todoVerde();
        $cliente = Cliente::factory()->contribuyente()->create();
        $r = $this->evaluar($this->facturaBorrador($cliente, '30000.00'));

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    // --- Correo automático ---

    public function test_correo_automatico_activo_bloquea_con_advertencia_clara(): void
    {
        $this->todoVerde();
        Configuracion::set('correo.auto_envio', true);
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $this->assertFalse($r['puede']);
        $check = collect($r['checks'])->firstWhere('clave', 'correo_auto');
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('ADVERTENCIA', $check['detalle']);
    }

    public function test_correo_automatico_desactivado_pasa(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->facturaBorrador(null, '100.00'));

        $check = collect($r['checks'])->firstWhere('clave', 'correo_auto');
        $this->assertTrue($check['ok']);
    }
}
