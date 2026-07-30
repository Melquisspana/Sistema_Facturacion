<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DtePdfService;
use Dompdf\Adapter\CPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PAGINACIÓN del PDF de la Factura de Exportación (tipo 11).
 *
 * Antes, la plantilla partía las líneas en bloques FIJOS de 10 (`chunk(10)` +
 * `page-break-before: always`), así que una FEX de 30 líneas gastaba 3 páginas con
 * páginas casi vacías. Ahora la FEX usa la paginación NATURAL de Dompdf sobre una
 * sola tabla: `<thead>` repetido (`table-header-group`) y filas indivisibles.
 *
 * Estas pruebas fijan ese comportamiento y, sobre todo, que NO vuelva a aparecer un
 * límite fijo de 10 líneas por página. Además protegen la maquetación histórica de
 * CCF / Factura / Nota de crédito, que NO cambió.
 *
 * Solo presentación: no genera JSON, no firma, no transmite, no cambia estado.
 */
class DtePdfPaginacionExportacionTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

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
        foreach (['01', '03', '11'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    /**
     * Borrador con $n líneas. Cada producto lleva un código de barras único cuyos
     * últimos 5 dígitos son el número de línea: sirve de marcador para saber en qué
     * página del PDF quedó cada línea.
     */
    private function documento(TipoDte $tipo, int $n, ?int $lineaLarga = null): Dte
    {
        $slug = $tipo->value.'-'.$n.'-'.($lineaLarga ?? 0);
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
        } elseif ($tipo === TipoDte::CreditoFiscal) {
            $datos['cliente_id'] = Cliente::factory()->contribuyente()->create()->id;
        }

        $dte = $borradores->crearBorrador($datos);

        for ($i = 1; $i <= $n; $i++) {
            $producto = Producto::factory()->create([
                'codigo' => sprintf('%s-%03d', $slug, $i),
                'codigo_barra' => sprintf('%s%05d', substr(md5($slug), 0, 8), $i),
                'nombre' => $i === $lineaLarga
                    ? 'CARAMELO SURTIDO DE FRUTAS TROPICALES RELLENO DE PULPA NATURAL PRESENTACION '
                        .'DISPLAY DE CARTON IMPRESO A CUATRO TINTAS CON 24 BOLSAS DE 12 UNIDADES CADA '
                        .'UNA SABORES MANGO MARACUYA PIÑA Y TAMARINDO EDICION ESPECIAL 2026'
                    : sprintf('PRODUCTO DE EXPORTACION NUMERO %03d', $i),
                'precio_unitario' => round(3.5 + ($i % 7) * 1.37, 2),
                'tipo_impuesto' => TipoImpuesto::Gravado->value,
            ]);
            // Cantidades variadas a propósito (filas de distinta altura de contenido).
            $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1 + ($i % 9) * 3);
        }

        return $dte->refresh();
    }

    /**
     * Renderiza el PDF de verdad y devuelve, por página, los textos dibujados.
     * Se envuelve el canvas de Dompdf para saber la página real de cada texto (no se
     * intenta leer el PDF ya comprimido).
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
     * Número de página en que quedó cada línea, por su código de barras.
     *
     * @param  array<int, string[]>  $porPagina
     * @return array<int, int> numero_linea => pagina
     */
    private function paginaPorLinea(array $porPagina, Dte $dte): array
    {
        $prefijo = substr((string) $dte->lineas->first()?->codigo_barra, 0, 8);
        $paginas = [];
        foreach ($porPagina as $pagina => $textos) {
            foreach ($textos as $texto) {
                if (preg_match('/^'.preg_quote($prefijo, '/').'(\d{5})$/', trim($texto), $m)) {
                    $paginas[(int) $m[1]] = $pagina;
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
     * La plantilla aplica `text-transform: uppercase` a varias etiquetas y Dompdf
     * dibuja el texto ya transformado: se compara sin distinguir mayúsculas.
     *
     * @param  string[]  $textos
     */
    private function contiene(array $textos, string $aguja): bool
    {
        return str_contains(mb_strtolower(implode('|', $textos)), mb_strtolower($aguja));
    }

    // --- El defecto corregido: ya no hay bloques fijos de 10 ---

    public function test_fex_de_30_lineas_no_se_parte_en_bloques_de_10(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($fex), $fex);

        // El síntoma reportado era exactamente 10/10/10 en 3 páginas.
        $this->assertNotSame([1 => 10, 2 => 10, 3 => 10], $porPagina,
            'La FEX volvió a partirse en bloques fijos de 10 líneas por página.');

        // La primera página debe aprovechar la altura disponible: bastante más de 10.
        $this->assertGreaterThan(10, $porPagina[1],
            'La primera página de la FEX sigue limitada a 10 líneas.');
    }

    public function test_fex_de_30_lineas_cabe_en_dos_paginas(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);

        $paginas = count($this->textosPorPagina($fex));

        $this->assertSame(2, $paginas, 'Una FEX de 30 líneas debería ocupar 2 páginas.');
    }

    public function test_fex_incluye_todas_las_lineas_una_sola_vez(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);

        $paginaPorLinea = $this->paginaPorLinea($this->textosPorPagina($fex), $fex);

        $this->assertSame(range(1, 30), array_keys($paginaPorLinea),
            'El PDF de la FEX no contiene todas las líneas del documento.');
    }

    /** Con muchas líneas la ganancia debe ser grande (antes: 45 líneas = 5 páginas). */
    public function test_fex_de_45_lineas_no_gasta_una_pagina_por_cada_10(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 45);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($fex), $fex);

        $this->assertLessThanOrEqual(2, count($porPagina));
        $this->assertCount(45, $this->paginaPorLinea($this->textosPorPagina($fex), $fex));
    }

    // --- Encabezado repetido y filas indivisibles ---

    public function test_encabezado_de_tabla_se_repite_en_cada_pagina_con_lineas(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);
        $porPagina = $this->textosPorPagina($fex);
        $paginasConLineas = array_unique(array_values($this->paginaPorLinea($porPagina, $fex)));

        $this->assertGreaterThan(1, count($paginasConLineas), 'El caso de prueba debe abarcar varias páginas.');

        foreach ($paginasConLineas as $pagina) {
            $this->assertTrue($this->contiene($porPagina[$pagina], 'Cant.'),
                "Falta el encabezado de columnas en la página {$pagina}.");
            $this->assertTrue($this->contiene($porPagina[$pagina], 'Present.'),
                "Falta el encabezado de columnas en la página {$pagina}.");
        }
    }

    public function test_una_fila_con_descripcion_larga_no_se_corta_entre_paginas(): void
    {
        // La línea 24 cae justo en el límite de la primera página: si la fila fuera
        // divisible, su descripción quedaría repartida entre dos páginas.
        $fex = $this->documento(TipoDte::FacturaExportacion, 30, lineaLarga: 24);
        $porPagina = $this->textosPorPagina($fex);

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
            'La descripción larga quedó repartida entre varias páginas: la fila se partió.');
    }

    public function test_no_oculta_ni_trunca_la_descripcion_larga(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30, lineaLarga: 24);
        $porPagina = $this->textosPorPagina($fex);

        $todo = [];
        foreach ($porPagina as $textos) {
            $todo = array_merge($todo, $textos);
        }

        // Primer y ÚLTIMO trozo de la descripción: si estuviera truncada, faltaría el final.
        $this->assertTrue($this->contiene($todo, 'CARAMELO'));
        $this->assertTrue($this->contiene($todo, '2026'));
        $this->assertFalse($this->contiene($todo, '...'), 'La descripción aparece truncada.');
    }

    // --- Totales / bloque de cierre ---

    public function test_totales_van_completos_y_en_una_sola_pagina(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);
        $porPagina = $this->textosPorPagina($fex);

        $paginasConCierre = [];
        foreach ($porPagina as $pagina => $textos) {
            if ($this->contiene($textos, 'Total a pagar') || $this->contiene($textos, 'Valor en letras')) {
                $paginasConCierre[$pagina] = true;
            }
        }

        $this->assertCount(1, $paginasConCierre, 'El bloque de cierre quedó partido entre páginas.');
        $this->assertSame(max(array_keys($porPagina)), array_key_first($paginasConCierre),
            'El bloque de cierre no está en la última página.');

        // Y con todas sus filas fiscales (no se pierde nada al repaginar).
        $todo = [];
        foreach ($porPagina as $textos) {
            $todo = array_merge($todo, $textos);
        }
        foreach (['Ventas exportación', 'Sumas de ventas', 'Sub-total', 'Monto total de la operación', 'Total a pagar'] as $etiqueta) {
            $this->assertTrue($this->contiene($todo, $etiqueta), "Falta «{$etiqueta}» en los totales.");
        }
    }

    // --- La plantilla no debe reintroducir saltos manuales para la FEX ---

    public function test_html_de_la_fex_no_usa_saltos_manuales_ni_encabezado_de_continuacion(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);
        $html = $this->htmlPdf($fex);

        // Una sola tabla de líneas, marcada para paginación natural, con thead repetible.
        // (Se comprueba la clase APLICADA en el atributo, no el nombre de la regla CSS,
        //  que siempre está presente en el <style> para todos los tipos de documento.)
        $this->assertSame(1, substr_count($html, 'class="items items-nat"'));
        $this->assertStringContainsString('display: table-header-group', $html);
        // Sin bloques manuales: ni salto forzado por cada 10 líneas ni cintas de "Continuación".
        $this->assertStringNotContainsString('class="items items-blk"', $html);
        $this->assertStringNotContainsString('cont-hdr items-cont', $html);
        $this->assertStringNotContainsString('Continuación ·', $html);
    }

    public function test_una_sola_linea_sigue_generando_una_pagina(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 1);

        $this->assertSame(1, count($this->textosPorPagina($fex)));
    }

    // --- No-regresión: CCF / Factura conservan su maquetación histórica ---

    public function test_ccf_conserva_los_bloques_de_10_lineas_por_pagina(): void
    {
        $ccf = $this->documento(TipoDte::CreditoFiscal, 25);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($ccf), $ccf);

        $this->assertSame([1 => 10, 2 => 10, 3 => 5], $porPagina,
            'El CCF cambió de maquetación: el arreglo debe afectar solo a la FEX (tipo 11).');
    }

    public function test_factura_conserva_los_bloques_de_10_lineas_por_pagina(): void
    {
        $factura = $this->documento(TipoDte::Factura, 25);

        $porPagina = $this->lineasPorPagina($this->textosPorPagina($factura), $factura);

        $this->assertSame([1 => 10, 2 => 10, 3 => 5], $porPagina,
            'La Factura cambió de maquetación: el arreglo debe afectar solo a la FEX (tipo 11).');
    }

    public function test_ccf_sigue_usando_encabezado_de_continuacion_y_salto_manual(): void
    {
        $html = $this->htmlPdf($this->documento(TipoDte::CreditoFiscal, 25));

        $this->assertStringContainsString('cont-hdr items-cont', $html);
        $this->assertStringContainsString('Continuación ·', $html);
        $this->assertStringContainsString('class="items items-blk"', $html);
        // El CCF NO debe entrar en el modo de paginación natural de la FEX.
        $this->assertStringNotContainsString('class="items items-nat"', $html);
    }

    // --- El PDF sigue siendo solo presentación ---

    public function test_generar_el_pdf_no_cambia_montos_ni_estado_del_documento(): void
    {
        $fex = $this->documento(TipoDte::FacturaExportacion, 30);
        $antes = $fex->only(['estado', 'total_exportacion', 'subtotal', 'iva', 'monto_total_operacion', 'total_pagar']);

        app(DtePdfService::class)->bytes($fex);

        $this->assertSame($antes, $fex->refresh()->only(array_keys($antes)));
        $this->assertNull($fex->json_generado_path);
        $this->assertNull($fex->sello_recepcion);
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
            'datosExportacion' => \App\Support\Dte\DatosExportacionPresentacion::resolver($dte),
            'datosReceptor' => \App\Support\Dte\ReceptorExportacionPresentacion::resolver($dte),
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
