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
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mismo riesgo que {@see DteFacturaFirmarTransmitirBloqueadoTest} pero para Factura de
 * exportación (tipo 11): la auditoría de FEX encontró que incoterms, régimen y recinto
 * fiscal (exigidos por el schema real del MH) nunca se capturan ni se serializan —
 * SerializadorExportacionMh los envía como null. Hasta que eso se resuelva, la vía
 * genérica firmarTransmitir() no debe poder transmitir un tipo 11 REAL a producción.
 * Esta suite verifica el gate agregado (guardia 0.06, mismo patrón que la 0.05 de
 * Factura consumidor final): bloquea SOLO Factura de exportación, SOLO cuando la
 * emisión real a producción sería posible ahora mismo, y no afecta a CCF ni a Nota de
 * crédito.
 */
class DteExportacionFirmarTransmitirBloqueadoTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Abre TODOS los candados de producción real (mismo patrón que las suites de Factura). */
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

    private function fexGenerada(): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => Cliente::factory()->exportacion()->create(),
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function ccfGenerado(): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function ncGenerada(): Dte
    {
        $ccf = $this->aceptarCcf($this->ccfGenerado());
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 5, 'tipo_impuesto' => 'gravado']);
        app(DteGeneracionService::class)->generar($nc);

        return $nc->refresh();
    }

    public function test_exportacion_generada_se_bloquea_en_produccion_real_sin_invocar_firmador_ni_transmision(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $fex = $this->fexGenerada();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $fex), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertRedirect(route('facturacion.show', $fex))
            ->assertSessionHas('error', 'Factura de exportación está en revisión y no puede transmitirse en producción todavía.');

        // Ni el firmador ni la transmisión llegaron a invocarse (cero HTTP saliente).
        Http::assertNothingSent();

        $fex->refresh();
        $this->assertSame(EstadoDte::Generado, $fex->estado);
        $this->assertNull($fex->sello_recepcion);
        $this->assertNull($fex->json_firmado_path);
    }

    public function test_ccf_no_se_ve_afectado_por_el_gate_de_exportacion(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $ccf = $this->ccfGenerado();

        // Sin la frase EMITIR PRODUCCION: debe seguir cayendo en la guardia VIEJA (0.1),
        // no en la nueva (que es exclusiva de Factura de exportación).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $ccf), [])
            ->assertRedirect(route('facturacion.show', $ccf));

        Http::assertNothingSent();
        $error = session('error');
        $this->assertStringContainsString('EMITIR PRODUCCION', $error);
        $this->assertStringNotContainsString('Factura de exportación', $error);

        $ccf->refresh();
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
        $this->assertNull($ccf->sello_recepcion);
    }

    public function test_nota_credito_no_se_ve_afectada_por_el_gate_de_exportacion(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $nc = $this->ncGenerada();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $nc), [])
            ->assertRedirect(route('facturacion.show', $nc));

        Http::assertNothingSent();
        $error = session('error');
        $this->assertStringContainsString('EMITIR PRODUCCION', $error);
        $this->assertStringNotContainsString('Factura de exportación', $error);

        $nc->refresh();
        $this->assertSame(EstadoDte::Generado, $nc->estado);
        $this->assertNull($nc->sello_recepcion);
    }

    public function test_factura_consumidor_final_sigue_bloqueada_por_su_propio_gate(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $factura = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($factura, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($factura);
        $factura->refresh();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $factura), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertRedirect(route('facturacion.show', $factura))
            ->assertSessionHas('error', 'Factura consumidor final está en revisión y no puede transmitirse en producción todavía.');

        Http::assertNothingSent();
    }
}
