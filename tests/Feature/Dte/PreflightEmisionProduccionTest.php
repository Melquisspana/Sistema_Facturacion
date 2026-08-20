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
use Illuminate\Support\Carbon;
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
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200)]);
    }

    private function evaluar(Dte $ccf): array
    {
        return app(PreflightEmisionProduccion::class)->evaluar($ccf);
    }

    private function resumen(Dte $ccf): array
    {
        return app(PreflightEmisionProduccion::class)->resumen($ccf);
    }

    public function test_todo_verde_permite(): void
    {
        $this->todoVerde();
        $r = $this->evaluar($this->ccf());

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    // --- Coherencia de la configuración fiscal: BLOQUEA, no solo avisa -------------

    /**
     * Ambientes cruzados: el JSON se marcaría como PRODUCCIÓN pero se enviaría con las
     * credenciales de apitest. Todo lo demás está en verde a propósito — lo que se
     * prueba es que esta incoherencia sola alcanza para cerrar el preflight.
     */
    public function test_bloquea_si_el_ambiente_del_json_no_concuerda_con_el_de_las_credenciales(): void
    {
        $this->todoVerde();
        config(['dte.transmision.ambiente' => 'testing']); // dte.ambiente sigue en '01'

        $r = $this->evaluar($this->ccf());

        $this->assertFalse($r['puede']);
        $this->assertContains('Ambientes coherentes', $r['faltantes']);
    }

    public function test_bloquea_si_el_nit_de_firma_no_coincide_con_el_del_emisor(): void
    {
        $this->todoVerde();
        Empresa::first()->update(['nit' => '0614-000000-000-0']);
        config(['dte.firma.nit' => '06149999999999']); // certificado de otro NIT

        $r = $this->evaluar($this->ccf());

        $this->assertFalse($r['puede']);
        $this->assertContains('NIT de firma coincide con el emisor', $r['faltantes']);
    }

    public function test_bloquea_si_hay_un_mock_activo_con_app_env_production(): void
    {
        $this->todoVerde();
        config(['app.env' => 'production']); // todoVerde() ya deja dte.transmision.mock=true

        $r = $this->evaluar($this->ccf());

        $this->assertFalse($r['puede']);
        $this->assertContains('Sin mocks en producción', $r['faltantes']);
    }

    public function test_los_checks_de_coherencia_no_estorban_cuando_todo_concuerda(): void
    {
        // Contracara de los tres anteriores: con la configuración coherente, ninguno de
        // los checks nuevos aparece como faltante.
        $this->todoVerde();

        $r = $this->evaluar($this->ccf());

        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
        foreach (['Ambientes coherentes', 'NIT de firma coincide con el emisor', 'Sin mocks en producción'] as $label) {
            $this->assertNotContains($label, $r['faltantes']);
        }
    }

    public function test_bloquea_si_no_es_ambiente_produccion(): void
    {
        $this->todoVerde();
        config(['dte.ambiente' => '00']);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Ambiente producción (01) activo', $r['faltantes']);
    }

    public function test_no_exige_alineacion_con_conta_solo_que_exista_correlativo_de_p002(): void
    {
        // El correlativo de P002 (sistema nuevo) es independiente de Conta Portable
        // (P001): ya no se compara contra "produccion.ultimo_ccf_externo" ni se exige
        // "alinear". Un número interno bajo (ej. 1078) YA NO bloquea nada por sí solo.
        $this->todoVerde();
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1078]);
        $r = $this->evaluar($this->ccf());
        $this->assertTrue($r['puede'], 'Faltantes: '.implode(', ', $r['faltantes']));
    }

    public function test_bloquea_si_no_hay_correlativo_activo_de_produccion(): void
    {
        $this->todoVerde();
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['activo' => false]);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Correlativo CCF producción (sistema nuevo)', $r['faltantes']);
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
        // todoVerde() ya registró un backup válido de hoy: lo marcamos fallido para
        // dejar el check en rojo, sin tocar archivos (fuente de verdad = BD).
        $this->todoVerde();
        \App\Models\RespaldoEjecucion::query()->update(['exitoso' => false]);
        $r = $this->evaluar($this->ccf());
        $this->assertFalse($r['puede']);
        $this->assertContains('Backup del día listo', $r['faltantes']);
    }

    public function test_backup_valido_de_ayer_no_cuenta_como_de_hoy(): void
    {
        // Regresión: la fuente de verdad es el registro en BD (respaldo_ejecuciones),
        // no un escaneo de archivos por fecha de modificación (ver RespaldoEjecucionTest
        // para la cobertura de zona horaria de app.timezone).
        $this->todoVerde();
        \App\Models\RespaldoEjecucion::query()->update(['terminado_en' => now()->subDay()]);

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

    public function test_resumen_de_ccf_ya_generado_muestra_su_propio_numero_no_operativo_mas_uno(): void
    {
        // Caso real: CCF ya generado localmente como 1120 (el correlativo interno ya
        // reservó ese número), pero el último real confirmado de Conta es 1119. El
        // resumen NO debe decir "este CCF será el 1121" (operativo+1): 1121 es el
        // próximo FUTURO, después de aceptar este; el documento actual es 1120.
        $this->todoVerde();
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1120]);
        Configuracion::set('produccion.ultimo_ccf_externo', '1119');

        $ccf = $this->ccf();
        $ccf->forceFill(['numero_control' => 'DTE-03-M001P001-000000000001120', 'estado' => 'generado'])->save();

        $r = $this->resumen($ccf->fresh()->load('lineas', 'cliente', 'clienteSucursal'));

        $this->assertSame(1120, $r['documento_actual']);
        $this->assertSame(1119, $r['externo_ultimo']);
        $this->assertSame(1121, $r['proximo_futuro']);
        // Compat: el campo viejo ahora también refleja el documento actual, no operativo+1.
        $this->assertSame(1120, $r['proximo_numero']);
    }

    /**
     * El default histórico de 1093 se eliminó. Es un dato PURAMENTE INFORMATIVO de
     * Conta Portable (no participa en documento_actual ni en proximo_futuro, así que no
     * puede alterar numeración fiscal), pero inventarlo lo hacía indistinguible de un
     * número confirmado por el contador. Sin la clave, es null y la pantalla lo dice.
     */
    public function test_sin_la_clave_configurada_el_ultimo_externo_es_null_y_no_se_inventa_1093(): void
    {
        $this->todoVerde();
        Configuracion::query()->where('clave', 'produccion.ultimo_ccf_externo')->delete();
        Configuracion::olvidarCache();
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1093]);

        $r = $this->resumen($this->ccf());

        $this->assertNull($r['externo_ultimo']);
        $this->assertNotSame(1093, $r['externo_ultimo']);
        // La numeración real del documento no depende de ese dato y no se movió.
        $this->assertSame(1094, $r['documento_actual']);
        $this->assertSame(1095, $r['proximo_futuro']);
    }

    public function test_una_clave_vacia_tampoco_se_convierte_en_cero(): void
    {
        $this->todoVerde();
        Configuracion::set('produccion.ultimo_ccf_externo', '');
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1093]);

        // '' -> (int) daría 0, que se leería como "Conta va en cero". Mejor null.
        $this->assertNull($this->resumen($this->ccf())['externo_ultimo']);
    }

    public function test_resumen_de_borrador_sigue_usando_operativo_mas_uno(): void
    {
        // Sin numeroControl todavía (borrador puro): el documento a emitir SÍ es el
        // próximo que el correlativo asignará al generarlo — comportamiento sin cambios.
        $this->todoVerde();
        Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->update(['ultimo_numero' => 1093]);
        Configuracion::set('produccion.ultimo_ccf_externo', '1093');

        $r = $this->resumen($this->ccf()); // borrador recién creado, sin numero_control

        $this->assertSame(1094, $r['documento_actual']);
        $this->assertSame(1093, $r['externo_ultimo']);
        $this->assertSame(1095, $r['proximo_futuro']);
    }
}
