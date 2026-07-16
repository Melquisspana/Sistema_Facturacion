<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Factura consumidor final (01) quedó VALIDADA en APITEST y el guard temporal de
 * consola ("en revisión", en dte:firmar/dte:transmitir) fue RETIRADO deliberadamente:
 * ahora se comporta EXACTAMENTE igual que CCF frente a estos comandos, protegida
 * únicamente por los candados generales (firmador real deshabilitado en este entorno
 * de test, candados de transmisión, etc.). Esta suite confirma que:
 *  - Factura YA NO recibe el mensaje especial "está en revisión";
 *  - Factura cae en los MISMOS motivos genéricos que CCF (p. ej. firmador deshabilitado);
 *  - CCF y Nota de crédito no se vieron afectados por este cambio.
 */
class DteFacturaComandosBloqueadosTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private const MENSAJE_FIRMAR = 'Factura consumidor final está en revisión y no puede firmarse en producción todavía.';

    private const MENSAJE_TRANSMITIR = 'Factura consumidor final está en revisión y no puede transmitirse en producción todavía.';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
        $this->borradores = app(DteBorradorService::class);
    }

    /** Abre TODOS los candados de producción real (misma combinación validada en el gate web). */
    private function abrirCandadosProduccionReal(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.test_enabled', false);
        config()->set('dte.transmision.url_base', 'https://recepcion.test');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.token', 'TOKEN_FAKE_NO_REAL');
    }

    private function generarDte(TipoDte $tipo, ?Cliente $cliente = null): Dte
    {
        $datos = [
            'tipo_dte' => $tipo,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($cliente) {
            $datos['cliente_id'] = $cliente->id;
        }
        $dte = $this->borradores->crearBorrador($datos);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function facturaGenerada(): Dte
    {
        return $this->generarDte(TipoDte::Factura);
    }

    private function ccfGenerado(): Dte
    {
        return $this->generarDte(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create());
    }

    private function ncGenerada(): Dte
    {
        $ccf = $this->aceptarCcf($this->ccfGenerado());
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 5, 'tipo_impuesto' => 'gravado']);
        app(DteGeneracionService::class)->generar($nc);

        return $nc->refresh();
    }

    /** Simula un DTE ya FIRMADO (sin invocar el firmador real) para probar dte:transmitir. */
    private function marcarFirmado(Dte $dte): Dte
    {
        $ruta = 'dte/firmados/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.jws';
        Storage::disk('local')->put($ruta, 'eyJ.fake.jws.compacta');
        $dte->json_firmado_path = $ruta;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    // --- dte:firmar ---

    public function test_dte_firmar_ya_no_bloquea_factura_por_tipo_se_comporta_como_ccf(): void
    {
        $this->abrirCandadosProduccionReal();
        $factura = $this->facturaGenerada();

        // El firmador real está deshabilitado por defecto en este entorno de test (regla
        // general, NO relacionada con el tipo de documento): Factura ahora cae en el
        // MISMO motivo que CCF, no en el mensaje especial "en revisión" (retirado).
        $this->artisan('dte:firmar', ['dte' => $factura->id])
            ->doesntExpectOutputToContain(self::MENSAJE_FIRMAR)
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);

        $factura->refresh();
        $this->assertNull($factura->json_firmado_path);
        $this->assertSame(EstadoDte::Generado, $factura->estado);
        Storage::disk('local')->assertMissing('dte/firmados/dte-01-'.$factura->id.'-'.$factura->codigo_generacion.'.jws');
    }

    public function test_dte_firmar_no_bloquea_ccf_por_este_gate(): void
    {
        $this->abrirCandadosProduccionReal();
        $ccf = $this->ccfGenerado();

        // El firmador real está deshabilitado por defecto en este entorno de test (regla
        // existente, no relacionada con el gate nuevo): cae en DteFirmaDeshabilitadaException,
        // NO en el mensaje de Factura (que es exclusivo de ese tipo).
        $this->artisan('dte:firmar', ['dte' => $ccf->id])
            ->doesntExpectOutputToContain(self::MENSAJE_FIRMAR)
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);
    }

    public function test_dte_firmar_no_bloquea_nc_por_este_gate(): void
    {
        $this->abrirCandadosProduccionReal();
        $nc = $this->ncGenerada();

        $this->artisan('dte:firmar', ['dte' => $nc->id])
            ->doesntExpectOutputToContain(self::MENSAJE_FIRMAR)
            ->expectsOutputToContain('deshabilitada')
            ->assertExitCode(1);
    }

    // --- dte:transmitir ---

    public function test_dte_transmitir_ya_no_bloquea_factura_por_tipo_se_comporta_como_ccf(): void
    {
        Http::fake(); // respuesta 200 vacía por defecto: Factura ahora SÍ llega a intentar el HTTP, igual que CCF.
        $this->abrirCandadosProduccionReal();
        $factura = $this->marcarFirmado($this->facturaGenerada());

        $this->artisan('dte:transmitir', ['dte' => $factura->id])
            ->doesntExpectOutputToContain(self::MENSAJE_TRANSMITIR);

        // A diferencia del guard retirado (que bloqueaba sin HTTP), Factura ahora
        // intenta transmitir exactamente igual que CCF (Http::fake() lo intercepta:
        // sigue sin salir NINGÚN HTTP real a Hacienda).
        Http::assertSent(fn ($request) => true);
    }

    public function test_dte_transmitir_no_bloquea_ccf_por_este_gate(): void
    {
        Http::fake(); // respuesta 200 vacía por defecto: el CCF SÍ llega a intentar el HTTP real.
        $this->abrirCandadosProduccionReal();
        $ccf = $this->marcarFirmado($this->ccfGenerado());

        $this->artisan('dte:transmitir', ['dte' => $ccf->id])
            ->doesntExpectOutputToContain(self::MENSAJE_TRANSMITIR);

        // A diferencia de la Factura bloqueada, el CCF SÍ llegó a intentar transmitir.
        Http::assertSent(fn ($request) => true);
    }

    public function test_dte_transmitir_no_bloquea_nc_por_este_gate(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $nc = $this->marcarFirmado($this->ncGenerada());

        $this->artisan('dte:transmitir', ['dte' => $nc->id])
            ->doesntExpectOutputToContain(self::MENSAJE_TRANSMITIR);

        Http::assertSent(fn ($request) => true);
    }
}
