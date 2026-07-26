<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * RETENCIÓN de IVA en notas de crédito. Una NC relacionada a un CCF que retuvo debe
 * reversar esa retención: espeja la DECISIÓN del original, pero recalcula el MONTO
 * sobre su propia base gravada neta (proporcional en devoluciones parciales, exacto en
 * la reversión total). Las NC por MONTO (pronto pago/concepto) siguen sin retención.
 *
 * Caso de oro (CCF real de producción #145): gravado neto 121.73 · IVA 15.82 ·
 * total antes de retención 137.55 · retención 1.22 · total 136.33.
 *
 * Nada de esto emite, firma ni transmite: todo queda en borradores locales.
 */
class DteNotaCreditoRetencionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.ambiente' => '01']); // producción: la aceptación debe ser real
        Storage::fake('local'); // los JSON generados no tocan el disco real
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    /**
     * CCF ACEPTADO REAL del caso de oro: 2 líneas de 64.07 (subtotal 128.14), cliente
     * agente de retención con 5 % de descuento global.
     *
     * @param  array<int, float>  $precios
     */
    private function ccfAceptado(bool $agenteRetencion = true, array $precios = [64.07, 64.07], bool $aceptar = true): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => $agenteRetencion,
            'descuento_global_default' => 5,
        ]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        foreach ($precios as $precio) {
            $producto = Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 1);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $aceptar ? $this->aceptarCcf($ccf) : $ccf->refresh();
    }

    // ---------- El CCF de referencia ----------

    public function test_el_ccf_de_referencia_reproduce_el_caso_real(): void
    {
        $ccf = $this->ccfAceptado();

        $this->assertSame('128.14', (string) $ccf->subtotal);
        $this->assertSame('6.41', (string) $ccf->descuento_gravado);   // 5 %
        $this->assertSame('15.82', (string) $ccf->iva);
        $this->assertSame('137.55', (string) $ccf->total_antes_retencion);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);
        $this->assertSame('1.22', (string) $ccf->iva_retenido);
        $this->assertSame('136.33', (string) $ccf->total_pagar);
    }

    // ---------- Reversión total (caso obligatorio) ----------

    public function test_reversion_total_con_retencion_coincide_al_centavo_con_el_ccf(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.22', (string) $nc->iva_retenido);
        $this->assertSame('136.33', (string) $nc->total_pagar);
        // El saldo reversible del CCF queda cubierto exactamente.
        $this->assertSame((string) $ccf->total_pagar, (string) $nc->total_pagar);
        $this->assertSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
        $this->assertSame((string) $ccf->iva, (string) $nc->iva);
        $this->assertSame('5.00', (string) $nc->descuento_porcentaje_aplicado);
    }

    public function test_la_reversion_no_toca_el_ccf_original(): void
    {
        $ccf = $this->ccfAceptado();
        $antes = $ccf->only(['estado', 'sello_recepcion', 'iva_retenido', 'total_pagar', 'aplica_retencion_iva']);

        $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertSame($antes, $ccf->refresh()->only(array_keys($antes)));
    }

    public function test_reversion_total_sin_retencion_sigue_sin_retener(): void
    {
        $ccf = $this->ccfAceptado(agenteRetencion: false);
        $this->assertFalse((bool) $ccf->aplica_retencion_iva);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame((string) $ccf->total_pagar, (string) $nc->total_pagar);
    }

    // ---------- Devolución parcial: proporcional ----------

    public function test_devolucion_parcial_retiene_proporcionalmente(): void
    {
        $ccf = $this->ccfAceptado();
        $linea = $ccf->lineas()->orderBy('numero_linea')->first();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->acreditarLinea($nc, $linea, 1); // solo 1 de las 2 líneas
        $nc->refresh();

        // Base de la NC: 64.07 − 5 % = 60.87 → IVA 7.91 → retención 1 % = 0.61.
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.61', (string) $nc->iva_retenido);
        $this->assertSame('68.17', (string) $nc->total_pagar);
        // NO copia el monto completo del CCF.
        $this->assertNotSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
    }

    public function test_dos_notas_de_credito_no_duplican_saldo_ni_retencion(): void
    {
        $ccf = $this->ccfAceptado();
        $lineas = $ccf->lineas()->orderBy('numero_linea')->get();

        $nc1 = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->acreditarLinea($nc1, $lineas[0], 1);

        // La reversión posterior solo puede cubrir el saldo restante (la otra línea).
        $nc2 = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertSame(1, $nc2->lineas()->count());
        $this->assertSame('0.61', (string) $nc1->refresh()->iva_retenido);
        $this->assertSame('0.61', (string) $nc2->iva_retenido);
        // Las dos NC juntas reversan la retención del CCF (1.22), sin excederla.
        $suma = number_format((float) $nc1->iva_retenido + (float) $nc2->iva_retenido, 2, '.', '');
        $this->assertSame((string) $ccf->iva_retenido, $suma);
    }

    // ---------- Avería ----------

    public function test_averia_con_ccf_retenido_tambien_retiene(): void
    {
        $ccf = $this->ccfAceptado();
        $producto = Producto::factory()->create(['precio_unitario' => 64.07, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.61', (string) $nc->iva_retenido);
        $this->assertSame('5.00', (string) $nc->descuento_porcentaje_aplicado);
    }

    // ---------- Pronto pago / por monto: sin retención ----------

    public function test_pronto_pago_no_retiene(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Descuento por pronto pago', 'monto' => 50]);
        $nc->refresh();

        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('0.00', (string) $nc->descuento_porcentaje_aplicado); // tampoco hereda descuento
    }

    // ---------- JSON del MH ----------

    public function test_el_json_lleva_iva_rete1_con_la_retencion_y_valida_contra_el_schema(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());
        app(DteGeneracionService::class)->generar($nc); // numeración local; NO firma ni transmite

        $salida = app(MapeadorDteSalida::class)->mapear($nc->refresh());
        $json = app(SerializadorNotaCreditoMh::class)->serializar($salida);

        $this->assertSame(1.22, $json['resumen']['ivaRete1']);
        $this->assertSame(121.73, $json['resumen']['subTotal']);
        $this->assertSame(15.82, $json['resumen']['tributos'][0]['valor']);
        // La NC v3 no lleva totalPagar: montoTotalOperacion es el ÚNICO total y va NETO
        // de retenciones. Enviarlo bruto (137.55) fue el rechazo del DTE #150.
        $this->assertArrayNotHasKey('totalPagar', $json['resumen']);
        $this->assertSame(136.33, $json['resumen']['montoTotalOperacion']);
        $this->assertNotSame(137.55, $json['resumen']['montoTotalOperacion']);
        // Coherente con el total del documento y con las letras del propio JSON.
        $this->assertSame(136.33, (float) $nc->total_pagar);
        $this->assertStringContainsString('CIENTO TREINTA Y SEIS 33/100', (string) $json['resumen']['totalLetras']);

        $res = app(DteSchemaValidator::class)->validar($json, TipoDte::NotaCredito);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_el_json_deja_iva_rete1_en_cero_cuando_el_ccf_no_retuvo(): void
    {
        $ccf = $this->ccfAceptado(agenteRetencion: false);
        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());
        app(DteGeneracionService::class)->generar($nc);

        $json = app(SerializadorNotaCreditoMh::class)->serializar(app(MapeadorDteSalida::class)->mapear($nc->refresh()));
        $r = $json['resumen'];

        $this->assertSame(0.0, $r['ivaRete1']);
        // SIN retención la fórmula da exactamente lo de siempre (subTotal + IVA): las NC
        // ya aceptadas por Hacienda conservan su forma byte a byte.
        $this->assertSame(round($r['subTotal'] + $r['tributos'][0]['valor'], 2), $r['montoTotalOperacion']);
        $this->assertSame(137.55, $r['montoTotalOperacion']);

        $res = app(DteSchemaValidator::class)->validar($json, TipoDte::NotaCredito);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_el_monto_total_de_la_nc_respeta_la_formula_completa_con_rete_renta(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());
        app(DteGeneracionService::class)->generar($nc);

        $r = app(SerializadorNotaCreditoMh::class)
            ->serializar(app(MapeadorDteSalida::class)->mapear($nc->refresh()))['resumen'];

        // reteRenta existe en la v3 y participa en la fórmula aunque este módulo aún no
        // la maneje (viaja en 0.00): el total es invariante respecto de su propio JSON.
        $this->assertSame(0.0, $r['reteRenta']);
        $this->assertSame(0.0, $r['ivaPerci1']);
        $this->assertSame(
            round($r['subTotal'] + $r['tributos'][0]['valor'] - $r['ivaRete1'] - $r['reteRenta'], 2),
            $r['montoTotalOperacion'],
            'montoTotalOperacion debe ser subTotal + tributos − ivaRete1 − reteRenta.'
        );
    }

    public function test_la_formula_del_ccf_no_cambia(): void
    {
        // El CCF conserva su forma ACEPTADA: monto BRUTO y la retención restada en
        // totalPagar. La corrección es exclusiva de la NC. (Se serializa en estado
        // GENERADO, que es cuando se arma el JSON que viaja a Hacienda.)
        $ccf = $this->ccfAceptado(aceptar: false);

        $r = app(\App\Services\Dte\Serializadores\SerializadorCcfMh::class)
            ->serializar(app(MapeadorDteSalida::class)->mapear($ccf))['resumen'];

        $this->assertSame(1.22, $r['ivaRete']);
        $this->assertSame(137.55, $r['montoTotalOperacion']);          // BRUTO, sin restar
        $this->assertSame(136.33, $r['totalPagar']);                   // acá sí va neto
        $this->assertSame(round($r['montoTotalOperacion'] - $r['ivaRete'], 2), $r['totalPagar']);
    }
}
