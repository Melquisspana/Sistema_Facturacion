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
 * Segunda puerta del riesgo de Factura de exportación (tipo 11), análoga a
 * {@see DteFacturaComandosBloqueadosTest}: los comandos de consola `dte:firmar` /
 * `dte:transmitir` llaman DIRECTO a los servicios genéricos y no pasan por el gate web
 * de DteController::firmarTransmitir(). Esta suite verifica el gate agregado en ambos
 * comandos: bloquea SOLO Factura de exportación (11), SOLO cuando la emisión real a
 * producción sería posible ahora mismo, y no afecta a CCF ni a Nota de crédito.
 */
class DteExportacionComandosBloqueadosTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private const MENSAJE_FIRMAR = 'Factura de exportación está en revisión y no puede firmarse en producción todavía.';

    private const MENSAJE_TRANSMITIR = 'Factura de exportación está en revisión y no puede transmitirse en producción todavía.';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
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
        if ($tipo === TipoDte::FacturaExportacion) {
            // Campos FEX exigidos desde la implementación de recinto/régimen/incoterms;
            // códigos reales del catálogo importado por seedCatalogosDte().
            $datos['tipo_item_expor'] = 1;
            $datos['recinto_fiscal'] = '01';
            $datos['tipo_regimen'] = 'EX-1';
            $datos['regimen'] = '1000.000';
            $datos['cod_incoterms'] = '09';
        }
        $dte = $this->borradores->crearBorrador($datos);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function fexGenerada(): Dte
    {
        return $this->generarDte(TipoDte::FacturaExportacion, Cliente::factory()->exportacion()->create());
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

    public function test_dte_firmar_bloquea_exportacion_con_candados_reales_abiertos_y_no_genera_jws(): void
    {
        $this->abrirCandadosProduccionReal();
        $fex = $this->fexGenerada();

        $this->artisan('dte:firmar', ['dte' => $fex->id])
            ->expectsOutputToContain(self::MENSAJE_FIRMAR)
            ->assertExitCode(1);

        $fex->refresh();
        $this->assertNull($fex->json_firmado_path);
        $this->assertSame(EstadoDte::Generado, $fex->estado);
        Storage::disk('local')->assertMissing('dte/firmados/dte-11-'.$fex->id.'-'.$fex->codigo_generacion.'.jws');
    }

    public function test_dte_firmar_no_bloquea_ccf_por_este_gate(): void
    {
        $this->abrirCandadosProduccionReal();
        $ccf = $this->ccfGenerado();

        // El firmador real está deshabilitado por defecto en este entorno de test (regla
        // existente, no relacionada con el gate nuevo): cae en DteFirmaDeshabilitadaException,
        // NO en el mensaje de exportación (que es exclusivo de ese tipo).
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

    public function test_dte_transmitir_bloquea_exportacion_con_candados_reales_abiertos_sin_http_ni_cambiar_sello(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $fex = $this->marcarFirmado($this->fexGenerada());

        $this->artisan('dte:transmitir', ['dte' => $fex->id])
            ->expectsOutputToContain(self::MENSAJE_TRANSMITIR)
            ->assertExitCode(1);

        Http::assertNothingSent();
        $fex->refresh();
        $this->assertSame(EstadoDte::Firmado, $fex->estado);
        $this->assertNull($fex->sello_recepcion);
    }

    public function test_dte_transmitir_no_bloquea_ccf_por_este_gate(): void
    {
        Http::fake(); // respuesta 200 vacía por defecto: el CCF SÍ llega a intentar el HTTP real.
        $this->abrirCandadosProduccionReal();
        $ccf = $this->marcarFirmado($this->ccfGenerado());

        $this->artisan('dte:transmitir', ['dte' => $ccf->id])
            ->doesntExpectOutputToContain(self::MENSAJE_TRANSMITIR);

        // A diferencia de la exportación bloqueada, el CCF SÍ llegó a intentar transmitir.
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

    public function test_dte_firmar_no_bloquea_factura_consumidor_final_por_este_gate_nuevo(): void
    {
        $this->abrirCandadosProduccionReal();
        $factura = $this->generarDte(TipoDte::Factura);

        // Factura consumidor final tiene su PROPIO mensaje (guardia distinta, ya existente);
        // este test confirma que el gate nuevo de exportación no lo pisa ni lo duplica.
        $this->artisan('dte:firmar', ['dte' => $factura->id])
            ->doesntExpectOutputToContain(self::MENSAJE_FIRMAR)
            ->expectsOutputToContain('Factura consumidor final está en revisión')
            ->assertExitCode(1);
    }
}
