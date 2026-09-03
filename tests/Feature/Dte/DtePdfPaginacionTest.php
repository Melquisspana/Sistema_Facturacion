<?php

namespace Tests\Feature\Dte;

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
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DtePdfService;
use App\Support\Dte\DatosExportacionPresentacion;
use App\Support\Dte\ReceptorExportacionPresentacion;
use Dompdf\Adapter\CPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * PAGINACIÓN del PDF de los DTE: Factura (01), CCF (03), Nota de crédito (05) y
 * Factura de exportación (11).
 *
 * Antes la plantilla partía las líneas en bloques FIJOS de 10 (`chunk(10)` +
 * `page-break-before: always`), así que se gastaban páginas casi vacías: un CCF de 12
 * líneas ocupaba 2 páginas (10 + 2) y uno de 30 ocupaba 3 (10/10/10), aunque sobrara
 * espacio. Ahora TODOS los tipos usan la paginación NATURAL de Dompdf sobre una sola
 * tabla: `<thead>` repetido (`table-header-group`) y filas indivisibles.
 *
 * Estas pruebas fijan ese comportamiento y, sobre todo, que NO vuelva a aparecer un
 * límite fijo de líneas por página en ningún tipo de documento.
 *
 * Solo presentación: no genera JSON, no firma, no transmite, no cambia estado.
 */
class DtePdfPaginacionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** Descripción de varias líneas: sirve para probar que una fila no se corte. */
    private const DESC_LARGA = 'CARAMELO SURTIDO DE FRUTAS TROPICALES RELLENO DE PULPA NATURAL '
        .'PRESENTACION DISPLAY DE CARTON IMPRESO A CUATRO TINTAS CON 24 BOLSAS DE 12 UNIDADES '
        .'CADA UNA SABORES MANGO MARACUYA PIÑA Y TAMARINDO EDICION ESPECIAL 2026';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
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
    }

    /** Los cuatro tipos con representación gráfica. */
    public static function tiposProvider(): array
    {
        return [
            'Factura 01' => [TipoDte::Factura],
            'CCF 03' => [TipoDte::CreditoFiscal],
            'Nota de crédito 05' => [TipoDte::NotaCredito],
            'Exportación 11' => [TipoDte::FacturaExportacion],
        ];
    }

    /**
     * Documento del tipo indicado con $n líneas. Cada producto lleva un código de barras
     * único cuyos últimos 5 dígitos son el número de línea: es el marcador que permite
     * saber en qué página del PDF quedó cada línea.
     */
    private function documento(TipoDte $tipo, int $n, ?int $lineaLarga = null): Dte
    {
        $slug = $tipo->value.'-'.$n.'-'.($lineaLarga ?? 0);

        // La NC (05) no se crea sola: cuelga de un CCF aceptado y usa conceptos manuales.
        if ($tipo === TipoDte::NotaCredito) {
            return $this->notaCredito($slug, $n, $lineaLarga);
        }

        $borradores = app(DteBorradorService::class);
        $datos = [
            'tipo_dte' => $tipo,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];

        if ($tipo === TipoDte::FacturaExportacion) {
            $datos['cliente_id'] = Cliente::factory()->exportacion()->create()->id;
            $datos += [
                'tipo_item_expor' => 1, 'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
            ];
        } else {
            // Factura (01) y CCF (03): cliente contribuyente con sala de entrega.
            $cliente = Cliente::factory()->contribuyente()->create();
            $datos['cliente_id'] = $cliente->id;
            $datos['cliente_sucursal_id'] = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id])->id;
        }

        $dte = $borradores->crearBorrador($datos);
        $this->agregarLineas($dte, $slug, $n, $lineaLarga);

        return $dte->refresh();
    }

    /** Agrega $n líneas de producto con cantidades y precios variados. */
    private function agregarLineas(Dte $dte, string $slug, int $n, ?int $lineaLarga): void
    {
        $borradores = app(DteBorradorService::class);

        for ($i = 1; $i <= $n; $i++) {
            $producto = Producto::factory()->create([
                'codigo' => sprintf('%s-%03d', $slug, $i),
                'codigo_barra' => sprintf('%s%05d', substr(md5($slug), 0, 8), $i),
                'nombre' => $i === $lineaLarga ? self::DESC_LARGA : sprintf('PRODUCTO NUMERO %03d', $i),
                'precio_unitario' => round(3.5 + ($i % 7) * 1.37, 2),
                'tipo_impuesto' => TipoImpuesto::Gravado->value,
            ]);
            // Cantidades variadas a propósito (filas de distinta altura de contenido).
            $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1 + ($i % 9) * 3);
        }
    }

    /** Nota de crédito por monto con $n conceptos manuales, sobre un CCF aceptado. */
    private function notaCredito(string $slug, int $n, ?int $lineaLarga): Dte
    {
        $borradores = app(DteBorradorService::class);
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);

        $ccf = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $this->agregarLineas($ccf, $slug.'-base', max(1, $n), null);

        $nc = $borradores->crearNotaCredito(
            $this->aceptarCcf($ccf->refresh()),
            ['tipo' => TipoNotaCredito::ProntoPago->value]
        );

        for ($i = 1; $i <= $n; $i++) {
            $borradores->agregarConceptoNotaCredito($nc, [
                'descripcion' => $i === $lineaLarga ? self::DESC_LARGA : sprintf('CONCEPTO NUMERO %03d', $i),
                'monto' => round(2 + ($i % 5) * 1.1, 2),
            ]);
        }

        return $nc->refresh();
    }

    /**
     * Renderiza el PDF de verdad y devuelve, por página, los textos dibujados. Se envuelve
     * el canvas de Dompdf para saber la página real de cada texto (no se intenta leer el
     * PDF ya comprimido).
     *
     * @return array<int, string[]>
     */
    private function textosPorPagina(Dte $dte): array
    {
        CanvasEspiaPaginacion::reset();

        $pdf = app(DtePdfService::class)->pdf($dte);
        $dompdf = $pdf->getDomPDF();
        $dompdf->setCanvas(new CanvasEspiaPaginacion('letter', 'portrait', $dompdf));
        $pdf->render();

        $porPagina = CanvasEspiaPaginacion::$porPagina;
        ksort($porPagina);

        return $porPagina;
    }

    /**
     * Número de página en que quedó cada línea.
     *
     * Con productos se usa el código de barras. La NC por monto usa conceptos manuales
     * (sin producto ni código), así que ahí el marcador es el número de línea de la
     * primera columna.
     *
     * @param  array<int, string[]>  $porPagina
     * @return array<int, int> numero_linea => pagina
     */
    private function paginaPorLinea(array $porPagina, Dte $dte): array
    {
        $prefijo = substr((string) $dte->lineas->first()?->codigo_barra, 0, 8);
        $total = $dte->lineas->count();
        $paginas = [];

        foreach ($porPagina as $pagina => $textos) {
            foreach ($textos as $texto) {
                $texto = trim($texto);
                if ($prefijo !== '' && preg_match('/^'.preg_quote($prefijo, '/').'(\d{5})$/', $texto, $m)) {
                    $paginas[(int) $m[1]] = $pagina;
                } elseif ($prefijo === '' && preg_match('/^(\d{1,3})$/', $texto, $m)
                    && (int) $m[1] >= 1 && (int) $m[1] <= $total) {
                    $paginas[(int) $m[1]] ??= $pagina;
                }
            }
        }
        ksort($paginas);

        return $paginas;
    }

    /** Cuántas líneas quedaron en cada página. @return array<int, int> */
    private function lineasPorPagina(array $porPagina, Dte $dte): array
    {
        $conteo = array_count_values(array_values($this->paginaPorLinea($porPagina, $dte)));
        ksort($conteo);

        return $conteo;
    }

    /**
     * La plantilla aplica `text-transform: uppercase` a varias etiquetas y Dompdf dibuja
     * el texto ya transformado: se compara sin distinguir mayúsculas.
     *
     * @param  string[]  $textos
     */
    private function contiene(array $textos, string $aguja): bool
    {
        return str_contains(mb_strtolower(implode('|', $textos)), mb_strtolower($aguja));
    }

    // --- El defecto corregido: ya no hay bloques fijos de 10 en NINGÚN tipo ---

    #[DataProvider('tiposProvider')]
    public function test_doce_lineas_caben_en_una_sola_pagina(TipoDte $tipo): void
    {
        // El caso reportado: 12 líneas ocupaban 2 páginas (10 + 2) con la primera a medio
        // llenar. Ahora entran en una sola.
        $dte = $this->documento($tipo, 12);

        $porPagina = $this->textosPorPagina($dte);

        $this->assertCount(1, $porPagina,
            $tipo->label().' de 12 líneas debería ocupar 1 página, no '.count($porPagina).'.');
        $this->assertSame([1 => 12], $this->lineasPorPagina($porPagina, $dte));
    }

    #[DataProvider('tiposProvider')]
    public function test_treinta_lineas_no_se_parten_en_bloques_de_diez(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($dte), $dte);

        // El síntoma del defecto era exactamente 10/10/10 en 3 páginas.
        $this->assertNotSame([1 => 10, 2 => 10, 3 => 10], $porPagina,
            $tipo->label().' volvió a partirse en bloques fijos de 10 líneas.');
        // Y la primera página debe aprovechar la altura disponible.
        $this->assertGreaterThan(10, $porPagina[1],
            $tipo->label().' sigue limitado a 10 líneas en la primera página.');
        $this->assertLessThanOrEqual(2, count($porPagina),
            $tipo->label().' de 30 líneas debería ocupar 2 páginas.');
    }

    #[DataProvider('tiposProvider')]
    public function test_incluye_todas_las_lineas_una_sola_vez(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30);

        $paginaPorLinea = $this->paginaPorLinea($this->textosPorPagina($dte), $dte);

        $this->assertSame(range(1, 30), array_keys($paginaPorLinea),
            'El PDF de '.$tipo->label().' no contiene todas las líneas del documento.');
    }

    #[DataProvider('tiposProvider')]
    public function test_una_sola_linea_genera_una_pagina(TipoDte $tipo): void
    {
        $this->assertCount(1, $this->textosPorPagina($this->documento($tipo, 1)));
    }

    #[DataProvider('tiposProvider')]
    public function test_veinte_lineas_caben_en_dos_paginas(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 20);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($dte), $dte);

        // Antes: 10 + 10. Ahora las 20 entran en la primera página (el cierre va aparte).
        $this->assertSame([1 => 20], $porPagina);
    }

    // --- Encabezado repetido y filas indivisibles ---

    #[DataProvider('tiposProvider')]
    public function test_encabezado_de_tabla_se_repite_en_cada_pagina_con_lineas(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30);
        $porPagina = $this->textosPorPagina($dte);
        $paginasConLineas = array_unique(array_values($this->paginaPorLinea($porPagina, $dte)));

        $this->assertGreaterThan(1, count($paginasConLineas), 'El caso debe abarcar varias páginas.');

        foreach ($paginasConLineas as $pagina) {
            $this->assertTrue($this->contiene($porPagina[$pagina], 'Cant.'),
                "Falta el encabezado de columnas en la página {$pagina} de ".$tipo->label().'.');
            $this->assertTrue($this->contiene($porPagina[$pagina], 'Present.'),
                "Falta el encabezado de columnas en la página {$pagina} de ".$tipo->label().'.');
        }
    }

    #[DataProvider('tiposProvider')]
    public function test_una_fila_con_descripcion_larga_no_se_corta_entre_paginas(TipoDte $tipo): void
    {
        // La línea 23 cae junto al límite de la primera página: si la fila fuera divisible,
        // su descripción quedaría repartida entre dos páginas.
        $dte = $this->documento($tipo, 30, lineaLarga: 23);
        $porPagina = $this->textosPorPagina($dte);

        $paginasConFragmentos = [];
        foreach ($porPagina as $pagina => $textos) {
            foreach ($textos as $texto) {
                foreach (['CARAMELO', 'MARACUYA', 'TAMARINDO', 'DISPLAY'] as $token) {
                    if (str_contains($texto, $token)) {
                        $paginasConFragmentos[$pagina] = true;
                    }
                }
            }
        }

        $this->assertCount(1, $paginasConFragmentos,
            'En '.$tipo->label().' la descripción larga quedó repartida entre varias páginas: la fila se partió.');
    }

    #[DataProvider('tiposProvider')]
    public function test_no_oculta_ni_trunca_la_descripcion_larga(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30, lineaLarga: 23);

        $todo = [];
        foreach ($this->textosPorPagina($dte) as $textos) {
            $todo = array_merge($todo, $textos);
        }

        // Primer y ÚLTIMO trozo: si estuviera truncada, faltaría el final.
        $this->assertTrue($this->contiene($todo, 'CARAMELO'));
        $this->assertTrue($this->contiene($todo, '2026'));
        $this->assertFalse($this->contiene($todo, '...'), 'La descripción aparece truncada.');
    }

    // --- Bloque de cierre ---

    #[DataProvider('tiposProvider')]
    public function test_el_bloque_de_cierre_va_completo_en_una_sola_pagina(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30);
        $porPagina = $this->textosPorPagina($dte);

        $paginasConCierre = [];
        foreach ($porPagina as $pagina => $textos) {
            if ($this->contiene($textos, 'Valor en letras')
                || $this->contiene($textos, $tipo === TipoDte::NotaCredito ? 'Total a acreditar' : 'Total a pagar')) {
                $paginasConCierre[$pagina] = true;
            }
        }

        $this->assertCount(1, $paginasConCierre,
            'En '.$tipo->label().' el bloque de cierre quedó partido entre páginas.');
        $this->assertSame(max(array_keys($porPagina)), array_key_first($paginasConCierre),
            'En '.$tipo->label().' el bloque de cierre no está en la última página.');

        // Y con todas sus filas fiscales (no se pierde nada al repaginar).
        $todo = [];
        foreach ($porPagina as $textos) {
            $todo = array_merge($todo, $textos);
        }
        // El resumen NO es el mismo en los cuatro tipos, y no debe serlo: el CCF y la NC
        // llevan el resumen corto —ventas gravadas, descuento, subtotal gravado, IVA y
        // retención— sin los intermedios «Sumas de ventas» ni «Monto total de la
        // operación», que ahí solo repetían cifras. La Factura y la FEX sí los conservan.
        $esCcfONc = in_array($tipo, [TipoDte::CreditoFiscal, TipoDte::NotaCredito], true);

        $etiquetas = $esCcfONc
            ? ['Subtotal gravado', 'Descuento global', 'Retención IVA 1%', 'Valor en letras']
            : ['Sumas de ventas', 'Sub-total', 'Monto total de la operación', 'Valor en letras'];
        $etiquetas[] = $tipo === TipoDte::FacturaExportacion ? 'Ventas exportación' : 'Ventas gravadas';
        $etiquetas[] = $tipo === TipoDte::NotaCredito ? 'Total a acreditar' : 'Total a pagar';
        foreach ($etiquetas as $etiqueta) {
            $this->assertTrue($this->contiene($todo, $etiqueta),
                "Falta «{$etiqueta}» en los totales de ".$tipo->label().'.');
        }
    }

    /**
     * El estado técnico y el pie viajan CON el cierre: si quedaran sueltos podían caer
     * solos en una página final casi vacía (le pasaba a la Factura de 12 líneas).
     */
    #[DataProvider('tiposProvider')]
    public function test_no_deja_una_pagina_final_solo_con_el_pie(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 12, lineaLarga: 1);
        $porPagina = $this->textosPorPagina($dte);
        $ultima = max(array_keys($porPagina));

        if ($ultima === 1) {
            $this->assertTrue(true, 'Todo cupo en una página.');

            return;
        }

        // Si hubo más de una página, la última debe llevar el cierre, no solo el pie.
        $this->assertTrue(
            $this->contiene($porPagina[$ultima], 'Valor en letras')
            || $this->contiene($porPagina[$ultima], 'Total a pagar')
            || $this->contiene($porPagina[$ultima], 'Total a acreditar'),
            'En '.$tipo->label().' la última página no lleva el cierre: quedó casi vacía.'
        );
    }

    // --- La plantilla no debe reintroducir saltos manuales ---

    #[DataProvider('tiposProvider')]
    public function test_el_html_no_usa_saltos_manuales_ni_encabezado_de_continuacion(TipoDte $tipo): void
    {
        $html = $this->htmlPdf($this->documento($tipo, 30));

        // UNA sola tabla de líneas, marcada para paginación natural, con thead repetible.
        $this->assertSame(1, substr_count($html, 'class="items items-nat"'));
        $this->assertStringContainsString('display: table-header-group', $html);
        // Sin bloques manuales: ni salto forzado por cada 10 líneas ni cintas de continuación.
        $this->assertStringNotContainsString('items-blk', $html);
        $this->assertStringNotContainsString('items-cont', $html);
        $this->assertStringNotContainsString('Continuación ·', $html);
    }

    // --- El PDF sigue siendo solo presentación ---

    #[DataProvider('tiposProvider')]
    public function test_generar_el_pdf_no_cambia_montos_ni_estado(TipoDte $tipo): void
    {
        $dte = $this->documento($tipo, 30);
        $campos = ['estado', 'total_gravado', 'total_exportacion', 'subtotal', 'iva', 'monto_total_operacion', 'total_pagar'];
        $antes = $dte->only($campos);

        app(DtePdfService::class)->bytes($dte);

        $this->assertSame($antes, $dte->refresh()->only($campos));
        $this->assertNull($dte->sello_recepcion);
    }

    /** Un documento sin líneas no debe romper el render. */
    public function test_documento_sin_lineas_no_rompe_el_pdf(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => ClienteSucursal::factory()->create(['cliente_id' => $cliente->id])->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);

        $porPagina = $this->textosPorPagina($dte->refresh());

        $this->assertCount(1, $porPagina);
        $todo = [];
        foreach ($porPagina as $textos) {
            $todo = array_merge($todo, $textos);
        }
        $this->assertTrue($this->contiene($todo, 'Sin líneas.'));
    }

    /** HTML de la plantilla del PDF (mismos datos que DtePdfService). */
    private function htmlPdf(Dte $dte): string
    {
        $servicio = app(DtePdfService::class);
        $dte->load(['cliente.pais', 'cliente.departamento', 'cliente.municipio', 'cliente.distrito',
            'cliente.actividadEconomica', 'clienteSucursal', 'lineas', 'establecimiento.empresa',
            'puntoVenta', 'dteRelacionado']);

        return view('facturacion.pdf', [
            'dte' => $dte,
            'emisor' => $servicio->emisor($dte),
            'logoSrc' => null,
            'qrDataUri' => null,
            'datosExportacion' => DatosExportacionPresentacion::resolver($dte),
            'datosReceptor' => ReceptorExportacionPresentacion::resolver($dte),
        ])->render();
    }
}

/**
 * Canvas de Dompdf que recuerda en qué página se dibujó cada texto. Permite medir la
 * paginación real (con la fuente y métricas de verdad) sin parsear el PDF comprimido.
 */
class CanvasEspiaPaginacion extends CPDF
{
    /** @var array<int, string[]> pagina => textos dibujados */
    public static array $porPagina = [];

    public static function reset(): void
    {
        self::$porPagina = [];
    }

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        self::$porPagina[$this->get_page_number()][] = $text;

        parent::text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
    }
}
