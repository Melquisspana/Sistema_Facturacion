<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reversión TOTAL de un CCF con una Nota de Crédito por devolución
 * ({@see DteBorradorService::revertirCcfCompleto()} y la ruta
 * facturacion.nota-credito.revertir). Verifica que copie el saldo acreditable de TODAS
 * las líneas, respete NC parciales previas, sea atómica (rollback total sin saldo),
 * herede los datos del CCF, deje la NC en Borrador, no toque el CCF original y respete
 * la autorización (permiso operativo + CCF aceptado REAL por Hacienda).
 */
class DteNotaCreditoReversionTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        // Producción (ambiente 01): así la aceptación debe ser REAL (no mock) para revertir.
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        Correlativo::create(['tipo_dte' => '05', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    /**
     * CCF ACEPTADO REAL por Hacienda con las líneas indicadas (cantidad => precio).
     *
     * @param  array<int, array{cantidad: float|int, precio: float|int}>  $lineas
     */
    private function ccfAceptado(array $lineas = [['cantidad' => 10, 'precio' => 10]], array $overrides = []): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $ccf = $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ], $overrides));

        foreach ($lineas as $l) {
            $producto = Producto::factory()->create(['precio_unitario' => $l['precio'], 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: $l['cantidad']);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    /** Marca el CCF como Aceptado pero con sello MOCK (no real): no debe permitir reversión. */
    private function marcarAceptadoMock(Dte $ccf): Dte
    {
        $ccf->sello_recepcion = 'MOCK-SIMULADO-'.$ccf->id;
        $ccf->fecha_procesamiento_mh = null;
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->save();

        return $ccf->refresh();
    }

    public function test_reversion_copia_todas_las_lineas_disponibles(): void
    {
        $ccf = $this->ccfAceptado([['cantidad' => 10, 'precio' => 10], ['cantidad' => 5, 'precio' => 4]]);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));
        $nc->load('lineas');

        $this->assertCount(2, $nc->lineas);
        $cantidades = $nc->lineas->map(fn ($l) => (float) $l->cantidad)->sort()->values()->all();
        $this->assertSame([5.0, 10.0], $cantidades);
        // Snapshot copiado: precio y vínculo a la línea original.
        foreach ($nc->lineas as $linea) {
            $this->assertNotNull($linea->dte_linea_original_id);
            $this->assertSame(TipoImpuesto::Gravado, $linea->tipo_impuesto);
        }
    }

    public function test_reversion_respeta_una_nc_parcial_previa(): void
    {
        $ccf = $this->ccfAceptado([['cantidad' => 10, 'precio' => 10]]);
        $lineaOriginal = $ccf->lineas->first();

        // NC parcial previa que acredita 4 de 10 (borrador, cuenta para el saldo).
        $ncParcial = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
        $this->borradores->acreditarLinea($ncParcial, $lineaOriginal, '4');

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));
        $nc->load('lineas');

        $this->assertCount(1, $nc->lineas);
        $this->assertSame(6.0, (float) $nc->lineas->first()->cantidad);
    }

    public function test_sin_saldo_hace_rollback_total_sin_borrador_parcial(): void
    {
        $ccf = $this->ccfAceptado([['cantidad' => 10, 'precio' => 10]]);
        $lineaOriginal = $ccf->lineas->first();

        // Acredita TODO el saldo con una NC previa.
        $ncPrevia = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
        $this->borradores->acreditarLinea($ncPrevia, $lineaOriginal, '10');

        $ncAntes = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count();

        try {
            $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));
            $this->fail('Se esperaba ValidationException por falta de saldo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }

        // Rollback total: no se creó ningún borrador de NC nuevo.
        $this->assertSame($ncAntes, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    public function test_reversion_hereda_cliente_sala_y_orden_de_compra(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Sala Central']);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sucursal->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'numero_orden_compra' => 'OC-REV-777',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 3);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));

        $this->assertSame($cliente->id, $nc->cliente_id);
        $this->assertSame($sucursal->id, $nc->cliente_sucursal_id);
        $this->assertSame('OC-REV-777', $nc->numero_orden_compra);
        $this->assertSame($estab->id, $nc->establecimiento_id);
        $this->assertSame($pv->id, $nc->punto_venta_id);
        $this->assertSame($ccf->ambiente->value, $nc->ambiente->value);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
    }

    public function test_reversion_queda_en_borrador(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));

        $this->assertSame(EstadoDte::Borrador, $nc->estado);
        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertSame(TipoNotaCredito::DevolucionProducto, $nc->tipo_nota_credito);
    }

    public function test_ccf_original_queda_intacto(): void
    {
        $ccf = $this->ccfAceptado([['cantidad' => 10, 'precio' => 10]]);
        $selloAntes = $ccf->sello_recepcion;
        $lineasAntes = $ccf->lineas->count();
        $totalAntes = (string) $ccf->total_pagar;

        $this->borradores->revertirCcfCompleto($ccf, $this->usuario('facturacion'));
        $ccf->refresh()->load('lineas');

        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $this->assertSame($selloAntes, $ccf->sello_recepcion);
        $this->assertSame($lineasAntes, $ccf->lineas->count());
        $this->assertSame($totalAntes, (string) $ccf->total_pagar);
    }

    public function test_usuario_de_solo_lectura_recibe_403(): void
    {
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->usuario('jefatura'))
            ->post(route('facturacion.nota-credito.revertir', $ccf))
            ->assertForbidden();

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    public function test_ccf_no_aceptado_realmente_queda_bloqueado(): void
    {
        // Aceptado en la app pero con sello MOCK: no es aceptación real por Hacienda.
        $ccf = $this->marcarAceptadoMock($this->ccfAceptado());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.revertir', $ccf))
            ->assertForbidden();

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }
}
