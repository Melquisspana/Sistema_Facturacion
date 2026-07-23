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
use App\Services\Dte\PreflightEmisionProduccionExportacion;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Preflight de READINESS (checklist, no emite) para Factura de exportación
 * (tipo 11). Archivo nuevo, separado de PreflightEmisionProduccion (CCF), que no
 * se toca. Con todo verde debe permitir; al faltar cualquier campo fiscal FEX
 * (recinto/régimen/incoterm) debe bloquear e indicar cuál. SOLO LECTURA.
 */
class PreflightEmisionProduccionExportacionTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte(); // incluye catalogos_mh CAT-027/028/031/033
        WorkerHeartbeat::olvidar();
        Configuracion::olvidarCache();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
    }

    /** Deja TODAS las precondiciones en verde, incluido el correlativo tipo 11 producción. */
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
        Correlativo::create([
            'tipo_dte' => '11', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
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

    /** FEX con cliente de exportación (país + actividad) y los 5 campos fiscales completos. */
    private function fexBorrador(): Dte
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return $dte->refresh()->load('lineas', 'cliente');
    }

    private function evaluar(Dte $fex): array
    {
        return app(PreflightEmisionProduccionExportacion::class)->evaluar($fex);
    }

    public function test_todo_verde_permite(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->fexBorrador());

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    public function test_bloquea_si_no_hay_correlativo(): void
    {
        $this->todoVerde();
        Correlativo::where('tipo_dte', '11')->delete();
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Correlativo Exportación producción (P002) existe', $r['faltantes']);
    }

    public function test_bloquea_si_worker_apagado(): void
    {
        $this->todoVerde();
        WorkerHeartbeat::olvidar();
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Worker/cola activo', $r['faltantes']);
    }

    public function test_bloquea_si_no_hay_backup_del_dia(): void
    {
        $this->todoVerde();
        \App\Models\RespaldoEjecucion::query()->update(['exitoso' => false]);
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Backup del día listo', $r['faltantes']);
    }

    public function test_bloquea_si_firmador_apagado(): void
    {
        $this->todoVerde();
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => fn () => throw new \Illuminate\Http\Client\ConnectionException('caido')]);
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Firmador activo', $r['faltantes']);
    }

    public function test_bloquea_si_candados_produccion_cerrados(): void
    {
        $this->todoVerde();
        config(['dte.transmision.modo_operacion' => 'paralelo']);
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Candados de producción correctos', $r['faltantes']);
    }

    public function test_bloquea_si_credenciales_no_validadas(): void
    {
        $this->todoVerde();
        Configuracion::set('produccion.auth_prod_validada', false);
        $r = $this->evaluar($this->fexBorrador());

        $this->assertFalse($r['puede']);
        $this->assertContains('Credenciales producción validadas', $r['faltantes']);
    }

    public function test_bloquea_si_cliente_no_es_exportacion(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->cliente->update(['tipo_cliente' => \App\Enums\TipoCliente::Contribuyente]);
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Cliente de tipo exportación', $r['faltantes']);
    }

    public function test_bloquea_si_falta_pais_o_actividad_del_receptor(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->cliente->update(['pais_id' => null]);
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('País y actividad económica del receptor', $r['faltantes']);
    }

    public function test_bloquea_si_tipo_item_expor_invalido(): void
    {
        // La columna es NOT NULL (default 1); el caso real de "falta" es un valor que no
        // corresponde a ningún caso de TipoItemExportacion (1=Bienes, 2=Servicios).
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['tipo_item_expor' => 9])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Tipo de ítem exportación (bienes/servicios)', $r['faltantes']);
    }

    public function test_bloquea_si_falta_recinto_fiscal(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['recinto_fiscal' => null])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Recinto fiscal (CAT-027)', $r['faltantes']);
    }

    public function test_bloquea_si_falta_tipo_regimen(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['tipo_regimen' => null])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Tipo de régimen (CAT-033)', $r['faltantes']);
    }

    public function test_bloquea_si_falta_regimen(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['regimen' => null])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Régimen (CAT-028)', $r['faltantes']);
    }

    public function test_bloquea_si_falta_incoterm(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['cod_incoterms' => null])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Incoterm (CAT-031)', $r['faltantes']);
    }

    public function test_bloquea_si_codigo_de_catalogo_no_existe(): void
    {
        $this->todoVerde();
        $fex = $this->fexBorrador();
        $fex->forceFill(['recinto_fiscal' => 'ZZ'])->save();
        $r = $this->evaluar($fex->fresh()->load('lineas', 'cliente'));

        $this->assertFalse($r['puede']);
        $this->assertContains('Recinto fiscal (CAT-027)', $r['faltantes']);
    }

    public function test_pasa_con_cliente_exportacion_pais_actividad_y_campos_fiscales_completos(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->fexBorrador());

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
        $this->assertTrue(collect($r['checks'])->every(fn ($c) => $c['ok']));
    }
}
