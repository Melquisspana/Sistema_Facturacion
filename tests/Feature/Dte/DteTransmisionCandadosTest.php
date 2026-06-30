<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Candados de seguridad ANTES de cualquier transmisión real. Ningún test transmite
 * a Hacienda: se valida que los candados bloqueen antes de cualquier HTTP, que el
 * dry-run no haga HTTP y que el preflight no muestre secretos.
 */
class DteTransmisionCandadosTest extends TestCase
{
    use RefreshDatabase;

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

    private function servicio(): DteTransmisionService
    {
        return app(DteTransmisionService::class);
    }

    /** Abre TODOS los candados (situación que en esta fase NO debe ocurrir en .env). */
    private function abrirCandados(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://recepcion.test');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.token', 'TOKEN_FAKE_NO_REAL');
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
        Storage::disk('local')->put('dte/json/dte-03-'.$dte->id.'-'.$cg.'.json', '{"ok":true}');
        Storage::disk('local')->put('dte/firmados/dte-03-'.$dte->id.'-'.$cg.'.jws', 'eyJ.fake.jws.compacta');
        $dte->numero_control = 'DTE-03-M001P001-000000000000012';
        $dte->codigo_generacion = $cg;
        $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'-'.$cg.'.json';
        $dte->json_firmado_path = 'dte/firmados/dte-03-'.$dte->id.'-'.$cg.'.jws';
        // El flujo alineado exige estado Firmado para transmitir.
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    /** Ejecuta transmitir() esperando bloqueo, y verifica que NO hubo HTTP. */
    private function assertBloqueaSinHttp(Dte $ccf, string $fragmentoMensaje): void
    {
        Http::fake();
        try {
            $this->servicio()->transmitir($ccf);
            $this->fail('Debió bloquear con DteTransmisionDeshabilitadaException.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString($fragmentoMensaje, $e->getMessage());
        }
        Http::assertNothingSent();
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Firmado, $ccf->estado); // bloqueado: sigue Firmado, no avanza
    }

    // --- Candados sobre transmitir() ---

    public function test_bloquea_si_falta_real_confirmation(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.real_confirmation', false);
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'confirmación de transmisión real');
    }

    public function test_bloquea_si_dry_run(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.dry_run', true);
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'dry-run');
    }

    public function test_bloquea_si_produccion_sin_allow(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', false);
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'producción sin autorización');
    }

    public function test_transmitir_sigue_bloqueado_aunque_auth_test_preparado(): void
    {
        // Auth test real habilitado (solo login), pero transmisión sigue bloqueada.
        config()->set('dte.transmision.auth_test_real_enabled', true);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'x');
        // Candados de transmisión SIN abrir (enabled=false, dry_run=true, modo paralelo).
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->assertBloqueaSinHttp($ccf, 'No se envió nada a Hacienda.');
        // Jamás se hizo POST a recepción.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/fesv/recepciondte'));
    }

    public function test_modo_paralelo_bloquea_transmision_real(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'Modo paralelo');
    }

    public function test_modo_respaldo_bloquea_sin_confirmacion(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.modo_operacion', 'respaldo');
        config()->set('dte.transmision.real_confirmation', false);
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'Modo respaldo');
    }

    public function test_modo_principal_sigue_bloqueado_si_falta_otro_candado(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.dry_run', true); // falta un candado
        $this->assertBloqueaSinHttp($this->ccfFirmado(), 'dry-run');
    }

    // --- Dry-run ---

    public function test_dry_run_no_hace_http(): void
    {
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:transmitir-dry-run', ['dte' => $ccf->id])
            ->expectsOutputToContain('NO SE HIZO HTTP / NO SE GUARDÓ SELLO')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_run_muestra_endpoint_configurado(): void
    {
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        // Forzamos credenciales vacías para no depender del .env local del desarrollador.
        config()->set('dte.transmision.usuario_api', '');
        config()->set('dte.transmision.password', '');
        config()->set('dte.transmision.token', ''); // sin token manual: auth_configurado debe ser false
        Http::fake();
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->dryRun($ccf);

        $this->assertSame('https://apitest.dtes.mh.gob.sv/fesv/recepciondte', $r['endpoint']);
        $this->assertSame('testing', $r['ambiente_transmision']);
        $this->assertFalse($r['auth_configurado']); // sin user/pwd configurados
        Http::assertNothingSent();
    }

    public function test_dry_run_command_muestra_endpoint(): void
    {
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:transmitir-dry-run', ['dte' => $ccf->id])
            ->expectsOutputToContain('https://apitest.dtes.mh.gob.sv/fesv/recepciondte')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_run_no_guarda_sello_ni_cambia_estado(): void
    {
        Http::fake();
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->dryRun($ccf);

        $this->assertSame('03', $r['tipoDte']);
        $this->assertFalse(str_contains($r['jws_preview'], 'compacta')); // JWS NO completo
        Http::assertNothingSent();
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Firmado, $ccf->estado); // dry-run: no cambia estado (sigue Firmado)
    }

    // --- Preflight ---

    public function test_preflight_no_imprime_secretos(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:preflight-real', ['dte' => $ccf->id])
            ->doesntExpectOutputToContain('PASSWORD_SECRETO_X')
            ->doesntExpectOutputToContain('TOKEN_SECRETO_X')
            ->doesntExpectOutputToContain('facturador01')
            ->assertExitCode(1); // bloqueado por candados por defecto
    }

    public function test_preflight_bloqueado_por_defecto(): void
    {
        $ccf = $this->ccfFirmado(); // candados por defecto: enabled false, dry_run true, viejo true

        $this->artisan('dte:preflight-real', ['dte' => $ccf->id])
            ->expectsOutputToContain('BLOQUEADO')
            ->expectsOutputToContain('NO SE TRANSMITIÓ NADA')
            ->assertExitCode(1);
    }

    public function test_preflight_listo_con_todos_los_candados_abiertos(): void
    {
        $this->abrirCandados();
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'x');
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:preflight-real', ['dte' => $ccf->id])
            ->expectsOutputToContain('LISTO')
            ->assertExitCode(0);
    }

    public function test_preflight_refleja_modo_y_sistema_actual(): void
    {
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        config()->set('dte.transmision.sistema_actual_activo', true);
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:preflight-real', ['dte' => $ccf->id])
            ->expectsOutputToContain('Sistema actual en uso')
            ->expectsOutputToContain('Riesgo de correlativos')
            ->expectsOutputToContain('BLOQUEADO') // modo paralelo
            ->assertExitCode(1);
    }

    // --- Modo de operación ---

    public function test_modo_operacion_command_paralelo_seguro(): void
    {
        config()->set('dte.transmision.modo_operacion', 'paralelo');

        $this->artisan('dte:modo-operacion')
            ->expectsOutputToContain('PARALELO SEGURO')
            ->assertExitCode(0);
    }

    public function test_modo_operacion_command_no_imprime_secretos(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $this->artisan('dte:modo-operacion')
            ->doesntExpectOutputToContain('PASSWORD_SECRETO_X')
            ->doesntExpectOutputToContain('TOKEN_SECRETO_X')
            ->doesntExpectOutputToContain('facturador01')
            ->assertExitCode(0);
    }

    public function test_no_quedan_referencias_a_sistema_viejo(): void
    {
        $dirs = [app_path(), config_path(), base_path('.env.example')];
        $hits = [];
        foreach ($dirs as $path) {
            $archivos = is_dir($path)
                ? iterator_to_array((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))))
                : [new \SplFileInfo($path)];
            foreach ($archivos as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $contenido = (string) file_get_contents($f->getPathname());
                if (stripos($contenido, 'SISTEMA_VIEJO') !== false || stripos($contenido, 'sistema_viejo') !== false) {
                    $hits[] = $f->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'Quedaron referencias a "sistema viejo": '.implode(', ', $hits));
    }
}
