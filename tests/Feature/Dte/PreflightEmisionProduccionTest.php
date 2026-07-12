<?php

namespace Tests\Feature\Dte;

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
use App\Services\Dte\PreflightEmisionProduccion;
use App\Support\WorkerHeartbeat;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Preflight de emisión a producción: gate por gate. SOLO LECTURA (no emite, no
 * firma, no transmite). Con todos los inputs verdes debe permitir; al romper una
 * precondición debe bloquear e indicar cuál falta.
 */
class PreflightEmisionProduccionTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        WorkerHeartbeat::olvidar();
        Configuracion::olvidarCache();
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    private function ccf(): Dte
    {
        $service = app(DteBorradorService::class);
        $ccf = $service->crearBorrador([
            'tipo_dte' => \App\Enums\TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['correo' => 'cliente@ejemplo.com']),
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => PuntoVenta::first()->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $service->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);

        return $ccf->fresh()->load('lineas', 'cliente', 'clienteSucursal');
    }

    /** Deja TODAS las precondiciones en verde. */
    private function todoVerde(): void
    {
        config([
            'dte.ambiente' => '01',
            'dte.transmision.enabled' => true,
            'dte.transmision.mock' => true, // no HTTP en transmisión, pero candados abiertos igual
            'dte.transmision.test_enabled' => false,
            'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false,
            'dte.transmision.allow_production' => true,
            'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'respaldo',
            'dte.transmision.ambiente' => 'produccion',
        ]);
        Configuracion::set('produccion.ultimo_ccf_externo', '1093');
        Configuracion::set('produccion.auth_prod_validada', true);
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => null,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 1093, 'activo' => true,
        ]);
        WorkerHeartbeat::pulse();
        Storage::fake('local');
        $nombre = (string) config('backup.backup.name', config('app.name'));
        Storage::disk('local')->put($nombre.'/hoy.zip', 'x');
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200)]);
    }

    private function evaluar(Dte $ccf): array
    {
        return app(PreflightEmisionProduccion::class)->evaluar($ccf);
    }

    public function test_todo_verde_permite(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->ccf());

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    public function test_bloquea_si_no_es_ambiente_produccion(): void
    {
        $this->todoVerde();
        config(['dte.ambiente' => '00']);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Ambiente producción (01) activo', $r['faltantes']);
    }

    public function test_bloquea_si_correlativo_no_es_1094(): void
    {
        $this->todoVerde();
        // Correlativo desalineado (último 1078 → próximo 1079, no 1094).
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1078]);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Próximo correlativo CCF producción = 1094', $r['faltantes']);
    }

    public function test_bloquea_si_worker_apagado(): void
    {
        $this->todoVerde();
        WorkerHeartbeat::olvidar(); // sin pulso → inactivo
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Worker/cola activo', $r['faltantes']);
    }

    public function test_bloquea_si_no_hay_backup_del_dia(): void
    {
        $this->todoVerde();
        Storage::fake('local'); // borra el zip de hoy
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Backup del día listo', $r['faltantes']);
    }

    public function test_bloquea_si_firmador_apagado(): void
    {
        $this->todoVerde();
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => fn () => throw new \Illuminate\Http\Client\ConnectionException('caido')]);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Firmador activo', $r['faltantes']);
    }

    public function test_bloquea_si_candados_produccion_cerrados(): void
    {
        $this->todoVerde();
        config(['dte.transmision.modo_operacion' => 'paralelo']); // paralelo => transmision real NO posible
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Candados de producción correctos', $r['faltantes']);
    }

    public function test_bloquea_si_credenciales_no_validadas(): void
    {
        $this->todoVerde();
        Configuracion::set('produccion.auth_prod_validada', false);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Credenciales producción validadas', $r['faltantes']);
    }

    public function test_bloquea_si_documento_incompleto(): void
    {
        $this->todoVerde();
        // CCF sin líneas ni total.
        $vacio = Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'borrador',
            'ambiente' => '01', 'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'total_pagar' => 0, 'fecha_emision' => now(), 'hora_emision' => '10:00:00',
        ]);
        $r = $this->evaluar($vacio->load('lineas', 'cliente'));
        $this->assertFalse($r['puede']);
        $this->assertContains('Documento completo (cliente, productos, total)', $r['faltantes']);
    }
}
