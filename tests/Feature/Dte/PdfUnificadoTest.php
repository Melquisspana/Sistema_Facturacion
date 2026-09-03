<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
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
use App\Support\Dte\CorreoReceptorDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

/**
 * PDF UNIFICADO: una sola representación para los cuatro tipos, y la MISMA para ver,
 * descargar, imprimir y adjuntar al correo.
 *
 * Lo que fija esta suite:
 *
 *   · el correo EFECTIVO del receptor (sala → cliente, o la leyenda si falta) se imprime
 *     en los cuatro tipos, no solo en la exportación;
 *   · el «N° interno» ya no se imprime, y los identificadores fiscales —número de
 *     control, código de generación y sello— siguen intactos;
 *   · en CCF (03) y NC (05) la columna final «Total» es la base descontada y SIN IVA, no
 *     hay columna de IVA por línea, y no se repiten «Gravado» y «Total» cuando serían el
 *     mismo número;
 *   · la Factura (01) conserva sus precios con IVA incluido y la FEX (11) su tratamiento
 *     de exportación: comparten el diseño, no la fórmula.
 *
 * Todo es PRESENTACIÓN: ninguna prueba de acá altera montos persistidos, JSON ni estados.
 */
class PdfUnificadoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        $this->seedCatalogosDte();

        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
                'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(float $precio = 100.00): Producto
    {
        return Producto::factory()->create([
            'nombre' => 'Dulce de leche artesanal',
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    private function borradorConLinea(TipoDte $tipo, ?Cliente $cliente, array $extra = [], float $precio = 100.00, int $cantidad = 2): Dte
    {
        $base = [
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente?->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];

        if ($tipo === TipoDte::FacturaExportacion) {
            $base += [
                'tipo_item_expor' => 1,
                'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1',
                'regimen' => '1000.000',
                'cod_incoterms' => '09',
            ];
        }

        $dte = $this->borradores->crearBorrador(array_merge($base, $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto($precio), cantidad: $cantidad);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        $this->generacion->generar($dte);

        return $dte->refresh();
    }

    /** CCF aceptado, base para la NC. */
    private function ccfAceptado(?Cliente $cliente = null): Dte
    {
        $cliente ??= Cliente::factory()->contribuyente()->create();

        return $this->aceptarCcf($this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente)));
    }

    // ================================================================
    // Correo efectivo del receptor
    // ================================================================

    public function test_el_pdf_muestra_el_correo_del_cliente_en_los_cuatro_tipos(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => 'contabilidad@calleja.com']);

        foreach ([TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::FacturaExportacion] as $tipo) {
            $receptor = $tipo === TipoDte::FacturaExportacion
                ? Cliente::factory()->exportacion()->create(['correo' => 'contabilidad@calleja.com'])
                : $cliente;

            $dte = $this->generar($this->borradorConLinea($tipo, $receptor));

            $this->assertStringContainsString(
                'contabilidad@calleja.com',
                $this->htmlDelPdf($dte),
                "Falta el correo del receptor en {$tipo->label()}."
            );
        }

        // Y la nota de crédito, que necesita un CCF aceptado detrás.
        $ccf = $this->ccfAceptado($cliente);
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago'], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 50]);

        $this->assertStringContainsString('contabilidad@calleja.com', $this->htmlDelPdf($nc->refresh()));
    }

    /** La SALA manda sobre el cliente: es la dirección que recibe los documentos de esa tienda. */
    public function test_el_correo_de_la_sala_tiene_prioridad_sobre_el_del_cliente(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => 'general@calleja.com']);
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'correo' => 'sala.ilobasco@calleja.com',
        ]);

        $ccf = $this->generar($this->borradorConLinea(
            TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sala->id]
        ));

        $html = $this->htmlDelPdf($ccf);
        $this->assertStringContainsString('sala.ilobasco@calleja.com', $html);
        $this->assertStringNotContainsString('general@calleja.com', $html);
    }

    /** Sin correo en la sala, se cae al del cliente. */
    public function test_sin_correo_en_la_sala_se_usa_el_del_cliente(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => 'general@calleja.com']);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'correo' => null]);

        $ccf = $this->generar($this->borradorConLinea(
            TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sala->id]
        ));

        $this->assertStringContainsString('general@calleja.com', $this->htmlDelPdf($ccf));
    }

    /** Sin correo en ninguno de los dos, el PDF lo DICE en vez de dejar el hueco. */
    public function test_sin_correo_configurado_se_imprime_la_leyenda(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => null]);
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->assertStringContainsString(CorreoReceptorDte::SIN_CORREO, $this->htmlDelPdf($ccf));
        $this->assertSame('Sin correo configurado', CorreoReceptorDte::SIN_CORREO);
    }

    // ================================================================
    // Identificadores: fuera el interno, intactos los fiscales
    // ================================================================

    public function test_el_pdf_no_imprime_el_numero_interno_pero_conserva_los_datos_fiscales(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente)));

        $html = $this->htmlDelPdf($ccf);

        // Ni la etiqueta ni el valor del consecutivo interno. («interno» a secas sigue
        // apareciendo en «Estado técnico (interno)», que es otra cosa y sí se conserva.)
        $this->assertStringNotContainsString('N° interno', $html);
        $this->assertNotNull($ccf->numero_interno, 'El fixture debe tener número interno para que la prueba signifique algo.');
        $this->assertStringNotContainsString((string) $ccf->numero_interno, $html);

        // Lo fiscal sigue completo.
        $this->assertStringContainsString($ccf->numero_control, $html);
        $this->assertStringContainsString($ccf->codigo_generacion, $html);
        $this->assertStringContainsString($ccf->sello_recepcion, $html);
        $this->assertStringContainsString('N° control', $html);
        $this->assertStringContainsString('Cód. gen.', $html);
    }

    // ================================================================
    // CCF 03 y NC 05: totales de línea sin IVA
    // ================================================================

    /**
     * Línea de 2 × $100.00 con 5 % de descuento global: la columna final debe traer la
     * base de la línea SIN IVA ($200.00), no $226.00. El IVA solo baja al resumen.
     */
    public function test_ccf_el_total_de_linea_va_sin_iva(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $html = $this->htmlDelPdf($ccf);
        $filas = $this->filasDeLineas($html);

        $this->assertStringContainsString('$200.00', $filas, 'La línea debe mostrar su base sin IVA.');
        $this->assertStringNotContainsString('$226.00', $filas, 'La línea NO debe mostrar un total con IVA.');
    }

    public function test_nc_el_total_de_linea_va_sin_iva(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'devolucion_producto'], $this->usuario());
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), 2);

        $filas = $this->filasDeLineas($this->htmlDelPdf($nc->refresh()));

        $this->assertStringContainsString('$200.00', $filas);
        $this->assertStringNotContainsString('$226.00', $filas);
    }

    /**
     * Sin exentos ni no sujetos, «Gravado» y «Total» serían la misma columna repetida:
     * se imprime una sola.
     */
    public function test_ccf_no_duplica_gravado_y_total_ni_desglosa_iva_por_linea(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $cabecera = $this->cabeceraDeLineas($this->htmlDelPdf($ccf));

        $this->assertStringContainsString('<th class="r">Total</th>', $cabecera);
        $this->assertStringNotContainsString('<th class="r">Gravado</th>', $cabecera);
        $this->assertStringNotContainsString('<th class="r">IVA</th>', $cabecera);
    }

    /**
     * El resumen del CCF/NC lleva exactamente los conceptos que el cliente concilia:
     * ventas gravadas, descuento, subtotal gravado, IVA 13 %, retención 1 % y el total.
     */
    public function test_el_resumen_del_ccf_desglosa_el_iva_y_la_retencion(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => true,
            'descuento_global_default' => 5,
        ]);
        // 2 × $100 = $200 bruto; −5 % = $190 neto → supera el umbral y retiene.
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $html = $this->htmlDelPdf($ccf);

        $this->assertStringContainsString('Ventas gravadas', $html);
        $this->assertStringContainsString('Descuento global', $html);
        $this->assertStringContainsString('Subtotal gravado', $html);
        $this->assertStringContainsString('Impuesto al Valor Agregado 13% (IVA)', $html);
        $this->assertStringContainsString('Retención IVA 1%', $html);
        $this->assertStringContainsString('Total a pagar', $html);

        // Y los intermedios que solo repetían información quedaron fuera.
        $this->assertStringNotContainsString('Sumas de ventas', $html);
        $this->assertStringNotContainsString('Monto total de la operación', $html);
    }

    public function test_la_nc_rotula_el_total_como_a_acreditar(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'devolucion_producto'], $this->usuario());
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), 1);

        $html = $this->htmlDelPdf($nc->refresh());

        $this->assertStringContainsString('Total a acreditar', $html);
        $this->assertStringContainsString('Subtotal gravado', $html);
        $this->assertStringNotContainsString('Total a pagar', $html);
    }

    // ================================================================
    // Factura 01 y FEX 11: mismo diseño, su propia fórmula
    // ================================================================

    /** La Factura conserva precios con IVA incluido y su total de línea con IVA dentro. */
    public function test_la_factura_conserva_los_precios_con_iva_incluido(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $html = $this->htmlDelPdf($factura);

        $this->assertStringContainsString('Precios con IVA incluido.', $html);
        // Su resumen sí conserva los intermedios propios del tipo.
        $this->assertStringContainsString('Sumas de ventas', $html);
        $this->assertStringContainsString('Monto total de la operación', $html);
    }

    /** La FEX conserva su columna de exportación y el IVA al 0 %. */
    public function test_la_fex_conserva_su_tratamiento_de_exportacion(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $html = $this->htmlDelPdf($fex);

        $this->assertStringContainsString('Exportación', $html);
        $this->assertStringContainsString('IVA (0%)', $html);
        $this->assertStringContainsString('Ventas exportación', $html);
        $this->assertStringNotContainsString('Subtotal gravado', $html);
    }

    // ================================================================
    // Una sola salida para ver, descargar e imprimir
    // ================================================================

    public function test_ver_descargar_e_imprimir_entregan_la_misma_representacion(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));
        $usuario = $this->usuario();

        foreach (['facturacion.pdf', 'facturacion.pdf.descargar', 'facturacion.imprimir'] as $ruta) {
            $r = $this->actingAs($usuario)->get(route($ruta, $ccf));
            $r->assertOk();
            $this->assertSame(
                'application/pdf',
                strtok((string) $r->headers->get('content-type'), ';'),
                "La ruta {$ruta} debe entregar la representación PDF."
            );
        }
    }

    // ---------------------------------------------------------------- recortes

    /** Encabezado (<thead>) de la tabla de líneas. */
    private function cabeceraDeLineas(string $html): string
    {
        $inicio = strpos($html, '<thead>');
        $this->assertNotFalse($inicio, 'No se encontró el encabezado de la tabla de líneas.');
        $fin = strpos($html, '</thead>', $inicio);

        return substr($html, $inicio, $fin - $inicio);
    }

    /** Cuerpo (<tbody>) de la tabla de líneas, sin el resumen inferior. */
    private function filasDeLineas(string $html): string
    {
        $inicio = strpos($html, '<tbody>');
        $this->assertNotFalse($inicio, 'No se encontró el cuerpo de la tabla de líneas.');
        $fin = strpos($html, '</tbody>', $inicio);

        return substr($html, $inicio, $fin - $inicio);
    }
}
