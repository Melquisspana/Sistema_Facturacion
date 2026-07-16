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
 * Factura consumidor final (tipo 01) quedó VALIDADA en APITEST (DTE #127 aceptado
 * por Hacienda real) y el guard temporal "en revisión" (guardia 0.05 de
 * DteController::firmarTransmitir) fue RETIRADO deliberadamente: Factura ahora sigue
 * EXACTAMENTE el mismo camino que CCF hacia producción, protegida únicamente por los
 * candados generales (guardia de frase EMITIR PRODUCCION, máquina de estados,
 * credenciales/ambiente/certificado). Esta suite confirma que:
 *  - Factura YA NO cae en el mensaje especial "está en revisión";
 *  - sin la frase EMITIR PRODUCCION, cae en la MISMA guardia genérica que CCF;
 *  - CCF y Nota de crédito no se vieron afectados por este cambio.
 */
class DteFacturaFirmarTransmitirBloqueadoTest extends TestCase
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
        foreach (['01', '03', '05'] as $t) {
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

    /**
     * Abre TODOS los candados de producción real (mismo patrón que
     * DteTransmisionCandadosTest::abrirCandados(), pero apuntando a que
     * emisionRealPosible() dé true: ambiente de transmisión "produccion").
     */
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

    private function facturaGenerada(): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
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

    public function test_factura_ya_no_cae_en_el_mensaje_especial_de_en_revision(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $factura = $this->facturaGenerada();

        // Sin la frase EMITIR PRODUCCION: debe caer en la MISMA guardia genérica que
        // protege a CCF (0.1), no en un mensaje especial de "en revisión" (retirado).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $factura), [])
            ->assertRedirect(route('facturacion.show', $factura));

        // Cero HTTP saliente: la guardia de frase bloquea ANTES de firmar/transmitir.
        Http::assertNothingSent();

        $error = session('error');
        $this->assertStringContainsString('EMITIR PRODUCCION', $error);
        $this->assertStringNotContainsString('en revisión', $error);
        $this->assertStringNotContainsString('está en revisión y no puede transmitirse', $error);

        $factura->refresh();
        $this->assertSame(EstadoDte::Generado, $factura->estado);
        $this->assertNull($factura->sello_recepcion);
        $this->assertNull($factura->json_firmado_path);
    }

    public function test_ccf_no_se_ve_afectado_por_el_gate_nuevo(): void
    {
        Http::fake();
        $this->abrirCandadosProduccionReal();
        $ccf = $this->ccfGenerado();

        // Sin la frase EMITIR PRODUCCION: debe seguir cayendo en la guardia VIEJA (0.1),
        // no en la nueva (que es exclusiva de Factura).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.firmar-transmitir', $ccf), [])
            ->assertRedirect(route('facturacion.show', $ccf));

        Http::assertNothingSent();
        $error = session('error');
        $this->assertStringContainsString('EMITIR PRODUCCION', $error);
        $this->assertStringNotContainsString('Factura consumidor final', $error);

        $ccf->refresh();
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
        $this->assertNull($ccf->sello_recepcion);
    }

    public function test_nota_credito_no_se_ve_afectada_por_el_gate_nuevo(): void
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
        $this->assertStringNotContainsString('Factura consumidor final', $error);

        $nc->refresh();
        $this->assertSame(EstadoDte::Generado, $nc->estado);
        $this->assertNull($nc->sello_recepcion);
    }
}
