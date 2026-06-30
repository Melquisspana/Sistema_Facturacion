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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Firma LOCAL real (sin transmisión). El firmador se mockea con Http::fake; nunca
 * se transmite a Hacienda, no se guarda sello, no se cambia estado a aceptado, y
 * la contraseña del certificado nunca se imprime.
 */
class DteFirmaRealTest extends TestCase
{
    use RefreshDatabase;

    private const PW_SENTINELA = 'SUPER_SECRETA_NO_DEBE_APARECER';

    private const JWS_FALSO = 'eyJhbGciOiJSUzI1NiJ9.eyJkdGUiOiJmYWtlIn0.firma-falsa-para-test';

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

    private function servicio(): DteFirmaService
    {
        return app(DteFirmaService::class);
    }

    /** Habilita la firma con NIT y contraseña (de prueba) en config (no .env). */
    private function habilitarFirma(): void
    {
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.nit', '06140000000011');
        config()->set('dte.firma.cert_password', self::PW_SENTINELA);
    }

    private function fakeFirmadorOk(): void
    {
        Http::fake(['*/firmardocumento/' => Http::response(['status' => 'OK', 'body' => self::JWS_FALSO], 200)]);
    }

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

        return $dte->refresh();
    }

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

    // --- Precondiciones / configuración ---

    public function test_no_firma_si_enabled_false(): void
    {
        config()->set('dte.firma.enabled', false);
        $ccf = $this->ccfConJson();

        $this->expectException(DteFirmaDeshabilitadaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_no_firma_sin_json_generado(): void
    {
        $this->habilitarFirma();
        $ccf = $this->ccfGenerado(); // sin json_generado_path

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_no_firma_si_archivo_no_existe(): void
    {
        $this->habilitarFirma();
        $ccf = $this->ccfConJson();
        Storage::disk('local')->delete($ccf->json_generado_path);

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_no_firma_si_falta_nit(): void
    {
        $this->habilitarFirma();
        config()->set('dte.firma.nit', '');
        $ccf = $this->ccfConJson();

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_no_firma_si_falta_password(): void
    {
        $this->habilitarFirma();
        config()->set('dte.firma.cert_password', '');
        $ccf = $this->ccfConJson();

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf);
    }

    public function test_no_refirma_sin_force(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();
        $ccf->json_firmado_path = 'dte/firmados/ya-firmado.jws';
        $ccf->save();

        $this->expectException(DteFirmaException::class);
        $this->servicio()->firmar($ccf->refresh());
    }

    // --- Firma feliz ---

    public function test_firma_correctamente_y_guarda_jws(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();

        $r = $this->servicio()->firmar($ccf);
        $ccf->refresh();

        $this->assertSame($r['ruta'], $ccf->json_firmado_path);
        $this->assertStringStartsWith('dte/firmados/', $ccf->json_firmado_path);
        Storage::disk('local')->assertExists($ccf->json_firmado_path);
        $this->assertSame(self::JWS_FALSO, Storage::disk('local')->get($ccf->json_firmado_path));
    }

    public function test_envia_el_payload_correcto_al_firmador(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();

        $this->servicio()->firmar($ccf);

        Http::assertSent(function ($request) {
            $d = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/firmardocumento/')
                && ($d['nit'] ?? null) === '06140000000011'
                && ($d['activo'] ?? null) === true
                && ($d['passwordPri'] ?? null) === self::PW_SENTINELA
                && is_array($d['dteJson'] ?? null);
        });
        // Solo se contactó al firmador: ninguna otra llamada (no transmisión).
        Http::assertSentCount(1);
    }

    public function test_pasa_a_firmado_sin_sello_ni_transmision(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();

        $this->servicio()->firmar($ccf);
        $ccf->refresh();

        $this->assertSame(EstadoDte::Firmado, $ccf->estado);  // Generado → Firmado por la máquina
        $this->assertNull($ccf->sello_recepcion);             // NO hay sello (no se transmitió)
        $this->assertNotNull($ccf->json_firmado_path);        // evidencia de firma local
    }

    // --- Rollback ---

    public function test_rollback_si_firmador_responde_error(): void
    {
        $this->habilitarFirma();
        Http::fake(['*/firmardocumento/' => Http::response(['status' => 'ERROR', 'body' => ['codigo' => '803', 'mensaje' => 'No existe llave publica para este nit']], 200)]);
        $ccf = $this->ccfConJson();

        try {
            $this->servicio()->firmar($ccf);
            $this->fail('Debió lanzar DteFirmaException.');
        } catch (DteFirmaException $e) {
            $this->assertStringContainsString('803', $e->getMessage());
        }

        $ccf->refresh();
        $this->assertNull($ccf->json_firmado_path); // rollback: nada a medias
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
    }

    public function test_rollback_si_jws_vacio(): void
    {
        $this->habilitarFirma();
        Http::fake(['*/firmardocumento/' => Http::response(['status' => 'OK', 'body' => ''], 200)]);
        $ccf = $this->ccfConJson();

        $this->expectException(DteFirmaException::class);
        try {
            $this->servicio()->firmar($ccf);
        } finally {
            $this->assertNull($ccf->refresh()->json_firmado_path);
        }
    }

    // --- Comando ---

    public function test_comando_firma_y_muestra_aviso(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();

        $this->artisan('dte:firmar', ['dte' => $ccf->id])
            ->expectsOutputToContain('DTE firmado localmente')
            ->expectsOutputToContain('FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA')
            ->assertExitCode(0);

        $this->assertNotNull($ccf->refresh()->json_firmado_path);
    }

    public function test_comando_no_imprime_la_contrasena(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();

        $this->artisan('dte:firmar', ['dte' => $ccf->id])
            ->doesntExpectOutputToContain(self::PW_SENTINELA)
            ->assertExitCode(0);
    }

    public function test_comando_bloquea_si_ya_firmado_sin_force(): void
    {
        $this->habilitarFirma();
        $this->fakeFirmadorOk();
        $ccf = $this->ccfConJson();
        $ccf->json_firmado_path = 'dte/firmados/ya.jws';
        $ccf->save();

        $this->artisan('dte:firmar', ['dte' => $ccf->id])
            ->expectsOutputToContain('ya está firmado')
            ->assertExitCode(1);
    }
}
