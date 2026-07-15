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
 * Segunda puerta del riesgo #2 de la auditoría de Factura consumidor final: los
 * comandos de consola `dte:firmar` / `dte:transmitir` llaman DIRECTO a los servicios
 * genéricos (DteFirmaService::firmar() / DteTransmisionService::transmitir()) y no
 * pasan por el gate web de DteController::firmarTransmitir(). Esta suite verifica el
 * gate agregado en ambos comandos: bloquea SOLO Factura consumidor final (01), SOLO
 * cuando la emisión real a producción sería posible ahora mismo (mismo criterio que
 * el gate web), y no afecta a CCF ni a Nota de crédito.
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

    public function test_dte_firmar_bloquea_factura_con_candados_reales_abiertos_y_no_genera_jws(): void
    {
        $this->abrirCandadosProduccionReal();
        $factura = $this->facturaGenerada();

        $this->artisan('dte:firmar', ['dte' => $factura->id])
            ->expectsOutputToContain(self::MENSAJE_FIRMAR)
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

    public function test_dte_transmitir_bloquea_factura_con_candados_reales_abiertos_sin_http_ni_cambiar_sello(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $factura = $this->marcarFirmado($this->facturaGenerada());

        $this->artisan('dte:transmitir', ['dte' => $factura->id])
            ->expectsOutputToContain(self::MENSAJE_TRANSMITIR)
            ->assertExitCode(1);

        Http::assertNothingSent();
        $factura->refresh();
        $this->assertSame(EstadoDte::Firmado, $factura->estado);
        $this->assertNull($factura->sello_recepcion);
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
