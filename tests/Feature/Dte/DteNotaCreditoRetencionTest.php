<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Observers\DteObserver;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorCcfMh;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * RETENCIÓN de IVA en notas de crédito. Una NC solo retiene si el CCF relacionado
 * retuvo Y su PROPIA base gravada neta supera dte.retencion_iva_umbral; el monto es el
 * 1 % de esa base (proporcional en devoluciones parciales, exacto en la reversión
 * total). Una NC bajo el umbral no retiene, aunque el original sí lo haya hecho: es lo
 * que trae el albarán real del cliente.
 *
 * La MODALIDAD no interviene: pronto pago, «otro», avería (AC02) y devolución (AC04)
 * siguen todas la misma regla —receptor gran contribuyente + CCF relacionado sujeto a
 * retención + base gravada neta propia mayor que el umbral—. Excluir las NC por monto
 * por su tipo era el defecto: dejaba sin retener notas que fiscalmente sí debían.
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
        // Líneas de 300.00 para que la mitad devuelta siga superando el umbral: lo que
        // se prueba acá es la PROPORCIONALIDAD del monto, no el umbral.
        $ccf = $this->ccfAceptado(precios: [300.00, 300.00]);
        $linea = $ccf->lineas()->orderBy('numero_linea')->first();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->acreditarLinea($nc, $linea, 1); // solo 1 de las 2 líneas
        $nc->refresh();

        // Base de la NC: 300.00 − 5 % = 285.00 → IVA 37.05 → retención 1 % = 2.85.
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('2.85', (string) $nc->iva_retenido);
        $this->assertSame('319.20', (string) $nc->total_pagar);
        // NO copia el monto completo del CCF (5.70).
        $this->assertNotSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
    }

    /**
     * Caso real de producción (Calleja): NC por avería de $0.90 sobre un CCF que sí
     * retuvo. Su base neta ($0.85) no llega al umbral, así que NO retiene y el total
     * coincide con el albarán del cliente: $0.96.
     */
    public function test_nc_bajo_el_umbral_no_retiene_aunque_el_ccf_haya_retenido(): void
    {
        $ccf = $this->ccfAceptado();
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);
        $producto = Producto::factory()->create(['precio_unitario' => 0.90, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertSame('0.90', (string) $nc->total_gravado);
        $this->assertSame('0.05', (string) $nc->total_descuento);   // 5 % de 0.90 → 0.045 → 0.05
        $this->assertSame('0.11', (string) $nc->iva);               // 13 % de 0.85
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('0.96', (string) $nc->total_pagar);
    }

    public function test_dos_notas_de_credito_no_duplican_saldo_ni_retencion(): void
    {
        $ccf = $this->ccfAceptado(precios: [300.00, 300.00]);
        $lineas = $ccf->lineas()->orderBy('numero_linea')->get();

        $nc1 = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->acreditarLinea($nc1, $lineas[0], 1);

        // La reversión posterior solo puede cubrir el saldo restante (la otra línea).
        $nc2 = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertSame(1, $nc2->lineas()->count());
        $this->assertSame('2.85', (string) $nc1->refresh()->iva_retenido);
        $this->assertSame('2.85', (string) $nc2->iva_retenido);
        // Las dos NC juntas reversan la retención del CCF (5.70), sin excederla.
        $suma = number_format((float) $nc1->iva_retenido + (float) $nc2->iva_retenido, 2, '.', '');
        $this->assertSame((string) $ccf->iva_retenido, $suma);
    }

    // ---------- Avería ----------

    public function test_averia_con_ccf_retenido_tambien_retiene(): void
    {
        $ccf = $this->ccfAceptado();
        $producto = Producto::factory()->create(['precio_unitario' => 200.00, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        // 200.00 − 5 % = 190.00 de base neta → sobre el umbral → 1.90.
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.90', (string) $nc->iva_retenido);
        $this->assertSame('5.00', (string) $nc->descuento_porcentaje_aplicado);
    }

    // ---------- Pronto pago / «otro»: retienen como cualquier otra modalidad ----------

    /**
     * EL CASO REAL que destapó el defecto (NC 14 sobre el CCF 94): NC de pronto pago con
     * base gravada $124.30 a un gran contribuyente cuyo CCF retuvo. Debe retener $1.24 y
     * totalizar $139.22. Antes salía por $140.46 —sin retención— porque la modalidad
     * «por monto» estaba excluida por su TIPO, sin llegar a evaluar las condiciones
     * fiscales.
     *
     * 124.30 × 13 % = 16.159 → 16.16 · 124.30 × 1 % = 1.243 → 1.24 · 140.46 − 1.24 = 139.22.
     */
    public function test_pronto_pago_retiene_el_caso_real_nc14_sobre_ccf94(): void
    {
        $ccf = $this->ccfAceptado();
        $this->assertTrue((bool) $ccf->aplica_retencion_iva, 'El CCF relacionado debe estar sujeto a retención.');

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Descuento por pronto pago', 'monto' => 124.30]);
        $nc->refresh();

        // La NC por monto no hereda el descuento comercial: su base neta es el concepto.
        $this->assertSame('0.00', (string) $nc->descuento_porcentaje_aplicado);
        $this->assertSame('124.30', (string) $nc->total_gravado);
        $this->assertSame('16.16', (string) $nc->iva);
        $this->assertSame('140.46', (string) $nc->total_antes_retencion);

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.24', (string) $nc->iva_retenido);
        $this->assertSame('139.22', (string) $nc->total_pagar);
    }

    /** La modalidad «otro» sigue exactamente la misma regla que pronto pago. */
    public function test_modalidad_otro_tambien_retiene_sobre_su_propia_base(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Otro->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Ajuste de concepto', 'monto' => 124.30]);
        $nc->refresh();

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.24', (string) $nc->iva_retenido);
        $this->assertSame('139.22', (string) $nc->total_pagar);
    }

    /**
     * UMBRAL, borde exacto: la comparación es ESTRICTA. Una base de exactamente $100.00
     * NO retiene; un centavo más sí. Es la misma semántica con la que se emitieron los
     * CCF aceptados, y ahora también rige a las NC por monto.
     */
    public function test_base_de_exactamente_cien_no_retiene(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 100.00]);
        $nc->refresh();

        $this->assertSame('100.00', (string) $nc->total_gravado);
        $this->assertFalse((bool) $nc->aplica_retencion_iva, 'Exactamente el umbral NO retiene.');
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('113.00', (string) $nc->total_pagar);
    }

    public function test_un_centavo_sobre_el_umbral_ya_retiene(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 100.01]);
        $nc->refresh();

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        // 100.01 × 1 % = 1.0001 → 1.00
        $this->assertSame('1.00', (string) $nc->iva_retenido);
    }

    /**
     * REDONDEO half-up sobre la base propia de la NC. 150.55 × 1 % = 1.5055 → 1.51 (no
     * 1.50): el medio centavo sube. Y el IVA: 150.55 × 13 % = 19.5715 → 19.57.
     */
    public function test_la_retencion_redondea_half_up(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 150.55]);
        $nc->refresh();

        $this->assertSame('19.57', (string) $nc->iva);
        $this->assertSame('1.51', (string) $nc->iva_retenido);
        $this->assertSame('168.61', (string) $nc->total_pagar); // 150.55 + 19.57 − 1.51
    }

    /** El medio centavo EXACTO también sube: 112.50 × 1 % = 1.125 → 1.13. */
    public function test_el_medio_centavo_exacto_sube(): void
    {
        $ccf = $this->ccfAceptado();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 112.50]);
        $nc->refresh();

        $this->assertSame('1.13', (string) $nc->iva_retenido);
    }

    // ---------- Las tres condiciones fiscales, una a una ----------

    /**
     * RECEPTOR NO GRAN CONTRIBUYENTE: aunque la base supere el umbral, no hay retención
     * posible. Acá el CCF tampoco retuvo (no podía), así que se comprueban las dos
     * ausencias juntas, que es como se dan en la realidad.
     */
    public function test_receptor_no_gran_contribuyente_no_retiene_en_pronto_pago(): void
    {
        $ccf = $this->ccfAceptado(agenteRetencion: false);
        $this->assertFalse((bool) $ccf->aplica_retencion_iva);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 124.30]);
        $nc->refresh();

        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('140.46', (string) $nc->total_pagar);
    }

    /**
     * CCF SIN RETENCIÓN pero receptor gran contribuyente: la NC NO inventa una retención
     * que el original no tuvo.
     *
     * La condición se aísla con un CCF chico (2 × $10.00, base neta $19.00): el receptor
     * sigue siendo agente de retención, pero el CCF no llegó al umbral y por eso no
     * retuvo. Así la única condición ausente es la del documento relacionado. No se fuerza
     * el estado del CCF a mano porque un DTE aceptado es inmutable —lo impide
     * {@see DteObserver}—, que es justamente la garantía que protege a la
     * NC histórica.
     */
    public function test_ccf_sin_retencion_no_contagia_retencion_a_la_nc_por_monto(): void
    {
        $ccf = $this->ccfAceptado(precios: [10.00, 10.00]);
        $this->assertFalse((bool) $ccf->aplica_retencion_iva, 'El CCF chico no debe retener.');

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 124.30]);
        $nc->refresh();

        // La base propia SÍ supera el umbral y el receptor SÍ es gran contribuyente: lo
        // único que falta es la retención del original, y con eso basta para no retener.
        $this->assertSame('124.30', (string) $nc->total_gravado);
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('140.46', (string) $nc->total_pagar);
    }

    // ---------- Modalidades de albarán AC02 (avería) y AC04 (devolución) ----------

    /**
     * AC02 = avería. Ya retenía antes del arreglo y debe seguir reteniendo IGUAL: la
     * corrección amplía las modalidades que pueden retener, no cambia las que ya podían.
     * La avería SÍ lleva el descuento comercial: 200.00 − 5 % = 190.00 → 1.90.
     */
    public function test_ac02_averia_conserva_su_retencion_sobre_la_base_con_descuento(): void
    {
        $ccf = $this->ccfAceptado();
        $producto = Producto::factory()->create(['precio_unitario' => 200.00, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertSame('5.00', (string) $nc->descuento_porcentaje_aplicado);
        $this->assertSame('200.00', (string) $nc->total_gravado);
        $this->assertSame('10.00', (string) $nc->descuento_gravado);
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.90', (string) $nc->iva_retenido);
    }

    /**
     * AC04 = devolución de producto. Retiene sobre su PROPIA base neta, no sobre la del
     * CCF: se comprueba que el monto es exactamente el 1 % de esa base.
     */
    public function test_ac04_devolucion_retiene_sobre_su_propia_base(): void
    {
        $ccf = $this->ccfAceptado(precios: [300.00, 300.00]);
        $linea = $ccf->lineas()->orderBy('numero_linea')->first();

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->acreditarLinea($nc, $linea, 1);
        $nc->refresh();

        $baseNeta = bcsub((string) $nc->total_gravado, (string) $nc->descuento_gravado, 2);
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame(
            Dinero::redondear(bcmul($baseNeta, '0.01', 6), 2),
            (string) $nc->iva_retenido,
            'La retención debe ser el 1 % de la base neta PROPIA de la NC.'
        );
        $this->assertNotSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
    }

    /**
     * DESCUENTO: cuando la modalidad hereda el descuento comercial, la retención se
     * calcula sobre la base YA DESCONTADA, nunca sobre el bruto.
     */
    public function test_la_retencion_se_calcula_despues_del_descuento_no_sobre_el_bruto(): void
    {
        $ccf = $this->ccfAceptado();
        $producto = Producto::factory()->create(['precio_unitario' => 110.00, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertSame('110.00', (string) $nc->total_gravado);
        $this->assertSame('5.50', (string) $nc->descuento_gravado);
        // 1 % de 104.50 = 1.045 → 1.05 (no 1.10, que sería el 1 % del bruto).
        $this->assertSame('1.05', (string) $nc->iva_retenido);
    }

    /**
     * DESCUENTO que hunde la base bajo el umbral: bruto 102.00 > 100, pero neto 96.90 no.
     * El umbral se juzga sobre el NETO, así que no retiene.
     */
    public function test_el_descuento_puede_dejar_la_base_bajo_el_umbral(): void
    {
        $ccf = $this->ccfAceptado();
        $producto = Producto::factory()->create(['precio_unitario' => 102.00, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertSame('5.10', (string) $nc->descuento_gravado);  // neto 96.90
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
    }

    /**
     * NO RETROACTIVIDAD: una NC ya aceptada no se recalcula nunca. El arreglo rige para
     * lo que se emita de acá en adelante; la NC histórica aceptada conserva sus montos.
     */
    public function test_una_nc_aceptada_no_se_recalcula_con_la_regla_nueva(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 124.30]);

        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh()->forceFill(['estado' => EstadoDte::Aceptado->value])->save();

        $antes = $nc->refresh()->only(['total_gravado', 'iva', 'iva_retenido', 'total_pagar', 'aplica_retencion_iva']);

        $this->expectException(DocumentoInmutableException::class);

        try {
            $this->borradores->recalcular($nc);
        } finally {
            $this->assertSame($antes, $nc->refresh()->only(array_keys($antes)));
        }
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

        $r = app(SerializadorCcfMh::class)
            ->serializar(app(MapeadorDteSalida::class)->mapear($ccf))['resumen'];

        $this->assertSame(1.22, $r['ivaRete']);
        $this->assertSame(137.55, $r['montoTotalOperacion']);          // BRUTO, sin restar
        $this->assertSame(136.33, $r['totalPagar']);                   // acá sí va neto
        $this->assertSame(round($r['montoTotalOperacion'] - $r['ivaRete'], 2), $r['totalPagar']);
    }
}
