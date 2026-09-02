<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\ListaEmpaqueExcelBuilder;
use App\Services\Exportaciones\ListaEmpaqueExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El Excel de la lista de empaque se genera SIN PLANTILLA y con el formato REAL.
 *
 * Antes se cargaba `storage/app/templates/exportaciones/lista_empaque.xlsx` y se
 * rellenaban sus celdas. Ese archivo no está en el disco ni en el repositorio, así
 * que la única salida del módulo llevaba meses lanzando una excepción que nadie
 * veía porque nunca se creó una lista. Un formato que depende de un binario no
 * versionado no es reproducible ni se puede probar.
 *
 * La primera versión sin plantilla arregló eso pero inventó una cuadrícula que no
 * era la de las listas que se mandan al cliente: título distinto, una segunda fila
 * de traducciones al inglés que el formato real no tiene, y pesos en libras
 * copiados del snapshot en vez de calculados. Estas pruebas fijan el formato de
 * verdad, tomando como referencia canónica «Lista empaque julio 2026.xlsx»:
 * celdas, textos, combinaciones, fórmulas, columnas ocultas, colores y
 * configuración de impresión.
 *
 * NO SON PRUEBAS DE VALORES SOLAMENTE. Lo que se rompe en una hoja de cálculo casi
 * nunca es el número: es el ancho de una columna, un bloque combinado que se
 * desarma o una fila de totales que queda flotando ocho filas más abajo. Por eso
 * hay aserciones sobre anchos, alturas, combinaciones, rellenos y bordes.
 */
class ListaEmpaqueExcelTest extends TestCase
{
    use RefreshDatabase;

    /** Fila de totales de una lista de N productos. */
    private const PRIMERA = ListaEmpaqueExcelBuilder::FILA_PRIMER_PRODUCTO;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{establecimiento_id: int, punto_venta_id: int} */
    private function emisor(): array
    {
        // Propiedad de instancia y NO `static`: PHPUnit reutiliza el proceso pero
        // RefreshDatabase vacía la base entre pruebas, así que unos ids cacheados en
        // una estática apuntarían a filas que ya no existen y la FK reventaría en la
        // segunda prueba del archivo.
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function lista(array $extra = []): Exportacion
    {
        $lista = Exportacion::create($extra + [
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'cliente_direccion' => '11235 SOMERSET, BELTSVILLE, MD 20705 EEUU',
            'exportador_nombre' => 'ELSA FIDELINA HERNANDEZ DE ESPAÑA',
            'exportador_direccion' => 'Hacienda Santa Barbara, Olocuilta, La Paz',
            'fda_reg_number' => '12015435846',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $lista->items()->create([
            'nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy box', 'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 144.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        $lista->items()->create([
            'nombre_es' => 'Caja de nance', 'nombre_en' => 'Yellow cherry candy', 'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 216, 'cantidad_cajas' => 4, 'precio_caja' => 172.80,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 13.96, 'peso_bruto_caja_kg' => 14.96,
            'peso_neto_caja_lb' => 30.78, 'peso_bruto_caja_lb' => 32.98,
        ]);

        return $lista->fresh();
    }

    /** Lista con exactamente $cuantos productos, para probar tamaños distintos. */
    private function listaCon(int $cuantos, string $nombre = 'Producto'): Exportacion
    {
        $lista = Exportacion::create([
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'exportador_nombre' => 'ELSA FIDELINA HERNANDEZ DE ESPAÑA',
            'fda_reg_number' => '12015435846',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        for ($i = 1; $i <= $cuantos; $i++) {
            $lista->items()->create([
                'nombre_es' => $nombre.' '.$i, 'nombre_en' => 'Item '.$i, 'unidad' => 'Bolsa 12X12',
                'unidades_por_caja' => 100 + $i, 'cantidad_cajas' => $i, 'precio_caja' => 10 + $i,
                'gramos_por_unidad' => 50 + $i, 'onzas_por_unidad' => 2,
                'peso_neto_caja_kg' => 5 + $i, 'peso_bruto_caja_kg' => 6 + $i,
                'peso_neto_caja_lb' => 11, 'peso_bruto_caja_lb' => 13,
            ]);
        }

        return $lista->fresh();
    }

    /** Genera el archivo y devuelve la hoja para inspeccionarla celda a celda. */
    private function hojaDe(Exportacion $lista): Worksheet
    {
        $ruta = app(ListaEmpaqueExcelService::class)->generar($lista);
        $this->assertFileExists($ruta);
        $this->assertGreaterThan(0, filesize($ruta), 'el archivo no puede salir vacío');

        $hoja = IOFactory::load($ruta)->getSheetByName('Lista');
        @unlink($ruta);

        $this->assertNotNull($hoja, 'el libro debe traer la hoja «Lista»');

        return $hoja;
    }

    /** Fila donde caen los totales de una lista de $productos renglones. */
    private function filaTotales(int $productos): int
    {
        return self::PRIMERA + $productos;
    }

    // ------------------------------------------------- título y bloque superior

    public function test_se_genera_aunque_no_exista_ninguna_plantilla(): void
    {
        // Se apunta a propósito a un archivo que no existe: el generador ya no lo lee.
        config(['exportaciones.plantilla' => 'templates/exportaciones/no-existe.xlsx']);

        $hoja = $this->hojaDe($this->lista());

        // El título es EXACTAMENTE el del formato real. Nada de «/ PACKING LIST»:
        // el bilingüe del documento vive dentro de la descripción de cada producto.
        $this->assertSame('LISTA DE EMPAQUE', $hoja->getCell('B1')->getValue());
    }

    public function test_el_titulo_ocupa_la_banda_combinada_de_arriba(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertContains('B1:T1', $hoja->getMergeCells());
        $this->assertEqualsWithDelta(21.75, $hoja->getRowDimension(1)->getRowHeight(), 0.01);
    }

    public function test_el_encabezado_lleva_exportador_cliente_fecha_y_fda(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame('Exportador', $hoja->getCell('B2')->getValue());
        $this->assertSame('ELSA FIDELINA HERNANDEZ DE ESPAÑA', $hoja->getCell('C2')->getValue());
        $this->assertSame('Direccion', $hoja->getCell('B3')->getValue());
        $this->assertSame('Hacienda Santa Barbara, Olocuilta, La Paz', $hoja->getCell('C3')->getValue());

        $this->assertSame('Cliente', $hoja->getCell('K2')->getValue());
        $this->assertSame('CAROLINAS WHOLESALE LLC', $hoja->getCell('L2')->getValue());
        $this->assertSame('Dirección', $hoja->getCell('K3')->getValue());
        $this->assertSame('11235 SOMERSET, BELTSVILLE, MD 20705 EEUU', $hoja->getCell('L3')->getValue());

        $this->assertSame('Facturas', $hoja->getCell('B4')->getValue());
        $this->assertSame('Fecha', $hoja->getCell('B5')->getValue());
        $this->assertSame('FDA reg. number', $hoja->getCell('E4')->getValue());

        // El FDA va como TEXTO: un registro que empiece por cero no puede perderlo.
        $this->assertSame('12015435846', $hoja->getCell('E5')->getValue());
        $this->assertIsString($hoja->getCell('E5')->getValue());

        // La fecha va como fecha real de Excel, no como cadena, y con su formato.
        $this->assertIsNumeric($hoja->getCell('C5')->getValue());
        $this->assertSame('m/d/yyyy', $hoja->getStyle('C5')->getNumberFormat()->getFormatCode());
    }

    /**
     * Las combinaciones del bloque superior son parte del formato: si una se cae,
     * el nombre del cliente se corta en la primera columna y la hoja deja de
     * parecerse a la que recibe el importador.
     */
    public function test_el_bloque_superior_conserva_todas_sus_combinaciones(): void
    {
        $hoja = $this->hojaDe($this->lista());

        foreach (['C2:I2', 'C3:I3', 'L2:S2', 'L3:S3', 'E4:I4', 'E5:I5'] as $rango) {
            $this->assertContains($rango, $hoja->getMergeCells(), "falta la combinación {$rango}");
        }
    }

    // --------------------------------------------------- cabeceras (filas 7 y 8)

    public function test_la_fila_siete_agrupa_factura_y_los_dos_bloques_de_peso(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame('FACTURA', $hoja->getCell('C7')->getValue());
        $this->assertSame('Peso en kilogramos', $hoja->getCell('L7')->getValue());
        $this->assertSame('Peso en libras', $hoja->getCell('Q7')->getValue());

        $this->assertContains('L7:O7', $hoja->getMergeCells());
        $this->assertContains('Q7:T7', $hoja->getMergeCells());

        // Gris para los kilos, azul para las libras: es lo que deja ver de un
        // vistazo dónde termina un sistema de unidades y empieza el otro.
        $this->assertSame('FFBFBFBF', $hoja->getStyle('L7')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFDEEAF6', $hoja->getStyle('Q7')->getFill()->getStartColor()->getARGB());
    }

    public function test_los_nombres_de_columna_van_en_la_fila_ocho(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $esperado = [
            'B' => "Cantidad de\ncajas",
            'C' => 'Descripción',
            'D' => 'Unidad',
            'E' => 'Unidades por caja',
            'F' => "GRAMOS\nPeso por unidad",
            'H' => "ONZAS\nPeso por unidad",
            'I' => 'Total de unidades',
            'J' => 'Precio unitario por caja',
            'K' => 'Valor total',
            'L' => 'Peso neto por caja',
            'M' => 'Peso bruto por caja',
            'N' => 'PESO NETO TOTAL',
            'O' => 'PESO BRUTO TOTAL',
            'Q' => 'Peso neto por caja',
            'R' => 'Peso bruto por caja',
            'S' => 'PESO NETO TOTAL',
            'T' => 'PESO BRUTO TOTAL',
        ];

        foreach ($esperado as $columna => $texto) {
            $this->assertSame($texto, $hoja->getCell($columna.'8')->getValue(), "cabecera de {$columna}8");
        }

        $this->assertSame('FFD0CECE', $hoja->getStyle('N8')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFDEEAF6', $hoja->getStyle('S8')->getFill()->getStartColor()->getARGB());
    }

    /**
     * El formato real NO tiene una segunda fila de traducciones al inglés. La 9 es
     * ya el primer producto; si alguien la vuelve a meter, los datos bajan una fila
     * y el importador de catálogo —que lee desde la 9— empieza a leer rótulos.
     */
    public function test_no_hay_una_fila_aparte_de_traducciones_al_ingles(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame(9, self::PRIMERA);
        $this->assertSame(10, $hoja->getCell('B9')->getValue());
        $this->assertSame('Caja de camote \\ Sweet potato candy box', $hoja->getCell('C9')->getValue());
        $this->assertNotSame('BOXES', $hoja->getCell('B9')->getValue());
    }

    // --------------------------------------------------------------- productos

    public function test_cada_columna_del_producto_lleva_su_dato(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame(10, $hoja->getCell('B9')->getValue(), 'B: cantidad de cajas');
        $this->assertSame('Caja de camote \\ Sweet potato candy box', $hoja->getCell('C9')->getValue(), 'C: descripción bilingüe');
        $this->assertSame('Bolsa 12X18', $hoja->getCell('D9')->getValue(), 'D: unidad de empaque');
        $this->assertSame(144, $hoja->getCell('E9')->getValue(), 'E: unidades por caja');
        $this->assertEqualsWithDelta(85.0, (float) $hoja->getCell('F9')->getValue(), 0.001, 'F: gramos por unidad');
        $this->assertEqualsWithDelta(0.035274, (float) $hoja->getCell('G9')->getValue(), 0.0000001, 'G: factor a onzas');
        $this->assertEqualsWithDelta(144.00, (float) $hoja->getCell('J9')->getValue(), 0.001, 'J: precio por caja');
        $this->assertEqualsWithDelta(19.40, (float) $hoja->getCell('L9')->getValue(), 0.001, 'L: peso neto por caja');
        $this->assertEqualsWithDelta(20.40, (float) $hoja->getCell('M9')->getValue(), 0.001, 'M: peso bruto por caja');
        $this->assertEqualsWithDelta(2.2046, (float) $hoja->getCell('P9')->getValue(), 0.0000001, 'P: factor a libras');

        // La segunda fila es la 10, sin huecos entre productos.
        $this->assertSame(4, $hoja->getCell('B10')->getValue());
        $this->assertSame('Caja de nance \\ Yellow cherry candy', $hoja->getCell('C10')->getValue());
    }

    /**
     * Los dos factores de conversión viven en la hoja —las fórmulas los usan— pero
     * NO en el documento que lee el cliente. Van en columnas ocultas: si se
     * escondieran borrándolos, H, Q, R, S y T se quedarían en cero.
     */
    public function test_los_factores_de_conversion_van_en_columnas_ocultas(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertFalse($hoja->getColumnDimension('G')->getVisible(), 'G (gramos→onzas) va oculta');
        $this->assertFalse($hoja->getColumnDimension('P')->getVisible(), 'P (kg→lb) va oculta');

        foreach (['B', 'C', 'D', 'E', 'F', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'Q', 'R', 'S', 'T'] as $columna) {
            $this->assertTrue($hoja->getColumnDimension($columna)->getVisible(), "{$columna} tiene que verse");
        }
    }

    /**
     * TODAS las fórmulas derivadas, iguales en TODOS los renglones. El fallo
     * clásico de estas hojas es que alguien arrastra mal una celda y una sola fila
     * queda calculando otra cosa; acá se comprueba renglón por renglón.
     */
    public function test_las_formulas_son_las_mismas_en_todos_los_renglones(): void
    {
        $productos = 24;
        $hoja = $this->hojaDe($this->listaCon($productos));

        $esperadas = [
            'H' => '=F%1$d*G%1$d',
            'I' => '=B%1$d*E%1$d',
            'K' => '=B%1$d*J%1$d',
            'N' => '=B%1$d*L%1$d',
            'O' => '=B%1$d*M%1$d',
            'Q' => '=L%1$d*P%1$d',
            'R' => '=M%1$d*P%1$d',
            'S' => '=N%1$d*P%1$d',
            'T' => '=O%1$d*P%1$d',
        ];

        for ($fila = self::PRIMERA; $fila < self::PRIMERA + $productos; $fila++) {
            foreach ($esperadas as $columna => $plantilla) {
                $this->assertSame(
                    sprintf($plantilla, $fila),
                    $hoja->getCell($columna.$fila)->getValue(),
                    "fórmula de {$columna}{$fila}"
                );
            }
        }
    }

    /**
     * NO SE REDONDEA ANTES DE CALCULAR. El peso en libras y las onzas salen de una
     * fórmula sobre el valor completo; el formato de la celda decide cuántos
     * decimales se ven. Guardar 153.9 en vez de 153.88108 mete un error que se
     * acumula renglón a renglón y termina en un total que no cuadra con la factura.
     */
    public function test_lo_derivado_conserva_la_precision_y_el_formato_decide_los_decimales(): void
    {
        $hoja = $this->hojaDe($this->lista());

        // Segundo producto: 4 cajas de 13.96 kg netos.
        $this->assertEqualsWithDelta(13.96 * 2.2046, (float) $hoja->getCell('Q10')->getCalculatedValue(), 0.000001);
        $this->assertEqualsWithDelta(4 * 13.96 * 2.2046, (float) $hoja->getCell('S10')->getCalculatedValue(), 0.000001);
        $this->assertEqualsWithDelta(85 * 0.035274, (float) $hoja->getCell('H10')->getCalculatedValue(), 0.000001);

        // Un decimal a la vista, precisión completa por dentro.
        $this->assertSame('0.0', $hoja->getStyle('S10')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0', $hoja->getStyle('H10')->getNumberFormat()->getFormatCode());
    }

    public function test_un_nombre_largo_se_escribe_entero_y_ajusta_en_su_celda(): void
    {
        $largo = 'Caja de bandejas de alfeñique grande con relleno de leche y ajonjolí tostado, presentación navideña de 36 unidades';
        $hoja = $this->hojaDe($this->listaCon(1, $largo));

        $this->assertSame($largo.' 1 \\ Item 1', $hoja->getCell('C9')->getValue());
        $this->assertTrue($hoja->getStyle('C9')->getAlignment()->getWrapText(), 'la descripción ajusta el texto');

        // Ni la fila crece a mano ni el bloque de abajo se corre: los totales siguen
        // pegados al único producto.
        $this->assertSame('=SUM(B9:B9)', $hoja->getCell('B10')->getValue());
    }

    // ----------------------------------------------------------------- totales

    /**
     * Los totales van INMEDIATAMENTE después del último producto, con el rango real
     * de la lista. El formato anterior tenía 24 filas fijas y había que insertar
     * filas a mano dentro del rango para no romper los `=SUM`.
     */
    public function test_los_totales_caen_pegados_al_ultimo_producto_sea_cual_sea_el_tamano(): void
    {
        foreach ([1, 3, 24, 40] as $productos) {
            $hoja = $this->hojaDe($this->listaCon($productos));
            $ultima = self::PRIMERA + $productos - 1;
            $totales = $this->filaTotales($productos);

            $this->assertSame(
                "=SUM(B9:B{$ultima})",
                $hoja->getCell('B'.$totales)->getValue(),
                "lista de {$productos} productos"
            );
            $this->assertSame("=SUM(T9:T{$ultima})", $hoja->getCell('T'.$totales)->getValue());
            $this->assertNull(
                $hoja->getCell('B'.($totales + 1))->getValue(),
                'debajo de los totales no puede quedar nada'
            );
        }
    }

    public function test_solo_las_siete_columnas_totalizables_llevan_suma(): void
    {
        $productos = 3;
        $hoja = $this->hojaDe($this->listaCon($productos));
        $fila = $this->filaTotales($productos);

        foreach (['B', 'I', 'K', 'N', 'O', 'S', 'T'] as $columna) {
            $this->assertStringStartsWith(
                "=SUM({$columna}9:",
                (string) $hoja->getCell($columna.$fila)->getValue(),
                "{$columna} tiene que totalizarse"
            );
        }

        // Sumar precios unitarios o pesos por caja no significa nada: son datos por
        // renglón, no cantidades que se acumulen.
        foreach (['C', 'D', 'E', 'F', 'H', 'J', 'L', 'M', 'Q', 'R'] as $columna) {
            $this->assertNull(
                $hoja->getCell($columna.$fila)->getValue(),
                "{$columna} no lleva total"
            );
        }
    }

    public function test_los_totales_suman_de_verdad(): void
    {
        $hoja = $this->hojaDe($this->lista());
        $fila = $this->filaTotales(2);

        // 10 + 4 cajas; 10×144 + 4×216 unidades; 10×144.00 + 4×172.80 dólares.
        $this->assertSame(14.0, (float) $hoja->getCell('B'.$fila)->getCalculatedValue());
        $this->assertSame(2304.0, (float) $hoja->getCell('I'.$fila)->getCalculatedValue());
        $this->assertEqualsWithDelta(2131.20, (float) $hoja->getCell('K'.$fila)->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(10 * 19.40 + 4 * 13.96, (float) $hoja->getCell('N'.$fila)->getCalculatedValue(), 0.001);
    }

    // ------------------------------------- formato: anchos, alturas y extensión

    public function test_las_columnas_conservan_los_anchos_del_formato(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $anchos = ['A' => 1.86, 'B' => 8.0, 'C' => 29.57, 'D' => 10.43, 'K' => 12.71, 'R' => 4.71, 'T' => 8.0];

        foreach ($anchos as $columna => $ancho) {
            $this->assertEqualsWithDelta(
                $ancho,
                $hoja->getColumnDimension($columna)->getWidth(),
                0.01,
                "ancho de la columna {$columna}"
            );
        }
    }

    public function test_los_renglones_de_producto_van_enmarcados_y_con_su_color(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame(
            Border::BORDER_THIN,
            $hoja->getStyle('C9')->getBorders()->getLeft()->getBorderStyle(),
            'cada celda de producto va enmarcada'
        );

        // Los dos bloques de peso siguen coloreados renglón a renglón.
        $this->assertSame('FFD0CECE', $hoja->getStyle('L9')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFD0CECE', $hoja->getStyle('O10')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFDEEAF6', $hoja->getStyle('Q9')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFDEEAF6', $hoja->getStyle('T10')->getFill()->getStartColor()->getARGB());
    }

    /**
     * La hoja termina en la fila de totales. Extender bordes y rellenos hasta la
     * 1000 «por si acaso» engorda el archivo, ensucia la vista previa de impresión
     * con páginas en blanco y hace que Excel proponga rangos absurdos al ordenar.
     */
    public function test_el_formato_no_se_extiende_mas_alla_de_los_totales(): void
    {
        $productos = 24;
        $hoja = $this->hojaDe($this->listaCon($productos));

        $this->assertSame($this->filaTotales($productos), $hoja->getHighestRow());
        $this->assertSame('T', $hoja->getHighestColumn());
    }

    // ------------------------------------------------------------- impresión

    public function test_la_hoja_sale_apaisada_a_una_pagina_de_ancho_y_repite_cabeceras(): void
    {
        $hoja = $this->hojaDe($this->listaCon(40));
        $impresion = $hoja->getPageSetup();

        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $impresion->getOrientation());
        $this->assertSame(1, $impresion->getFitToWidth(), 'todas las columnas en un solo papel de ancho');
        $this->assertSame(0, $impresion->getFitToHeight(), 'a lo alto puede usar las páginas que necesite');

        // Con 40 productos la lista pasa de una página: sin repetir las filas 7 y 8,
        // la segunda hoja es una tabla de números sin nombre de columna.
        $this->assertSame([7, 8], $impresion->getRowsToRepeatAtTop());

        $margenes = $hoja->getPageMargins();
        $this->assertEqualsWithDelta(0.2362, $margenes->getLeft(), 0.0001);
        $this->assertEqualsWithDelta(0.2362, $margenes->getRight(), 0.0001);
    }

    // ------------------------------------------------------- una y varias facturas

    public function test_la_casilla_de_factura_trae_el_numero_del_dte(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $lista = $this->lista(['exportacion_cliente_id' => $perfil->id, 'factura' => 'TEXTO VIEJO']);

        $fex = Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'cliente_id' => $cliente->id,
            'estado' => 'generado', 'ambiente' => '00', 'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00',
            'numero_control' => 'DTE-11-M001P001-000000000000001', 'total_pagar' => 100,
        ]);
        $lista->dtes()->attach($fex->id, ['principal' => true]);

        $hoja = $this->hojaDe($lista->fresh());

        $this->assertSame('DTE-11-M001P001-000000000000001', $hoja->getCell('C4')->getValue());
    }

    /**
     * Con varias facturas la casilla las lista todas SIN desarmar el encabezado: el
     * FDA sigue en su bloque combinado y la fecha en su casilla.
     */
    public function test_con_varias_facturas_la_casilla_las_lista_todas_sin_mover_el_resto(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $lista = $this->lista(['exportacion_cliente_id' => $perfil->id]);

        foreach (['000000000000001', '000000000000002', '000000000000003'] as $i => $sufijo) {
            $fex = Dte::create($this->emisor() + [
                'tipo_dte' => TipoDte::FacturaExportacion->value, 'cliente_id' => $cliente->id,
                'estado' => 'generado', 'ambiente' => '00', 'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00',
                'numero_control' => 'DTE-11-M001P001-'.$sufijo, 'total_pagar' => 100,
            ]);
            $lista->dtes()->attach($fex->id, ['principal' => $i === 0]);
        }

        $hoja = $this->hojaDe($lista->fresh());

        $this->assertSame(
            'DTE-11-M001P001-000000000000001 · DTE-11-M001P001-000000000000002 · DTE-11-M001P001-000000000000003',
            $hoja->getCell('C4')->getValue()
        );
        $this->assertTrue($hoja->getStyle('C4')->getAlignment()->getWrapText(), 'la casilla ajusta el texto');

        // El encabezado sigue entero.
        $this->assertSame('FDA reg. number', $hoja->getCell('E4')->getValue());
        $this->assertSame('12015435846', $hoja->getCell('E5')->getValue());
        $this->assertContains('E4:I4', $hoja->getMergeCells());
        $this->assertContains('E5:I5', $hoja->getMergeCells());
        $this->assertIsNumeric($hoja->getCell('C5')->getValue());
        $this->assertSame(10, $hoja->getCell('B9')->getValue(), 'los productos siguen empezando en la fila 9');
    }

    // -------------------------------------------------------- fallos y descarga

    public function test_una_lista_sin_productos_no_genera_archivo_y_lo_dice(): void
    {
        $lista = Exportacion::create([
            'cliente_nombre' => 'CLIENTE', 'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista))
            ->assertSessionHas('error');
    }

    /**
     * Un fallo de almacenamiento se ve como un mensaje, nunca como una descarga
     * vacía: un .xlsx de 0 bytes se abre como «archivo dañado» y manda a buscar el
     * problema al lado equivocado.
     */
    public function test_un_fallo_de_almacenamiento_se_muestra_y_no_devuelve_una_descarga_vacia(): void
    {
        $lista = $this->lista();

        $this->app->bind(ListaEmpaqueExcelService::class, fn () => new class(app(ListaEmpaqueExcelBuilder::class)) extends ListaEmpaqueExcelService
        {
            public function generar(Exportacion $exportacion): string
            {
                throw new \RuntimeException('El Excel de la lista de empaque se generó vacío y no se entregó.');
            }
        });

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista));

        $this->assertStringContainsString('se generó vacío', session('error'));
    }

    public function test_la_descarga_entrega_un_xlsx_con_nombre_util(): void
    {
        $lista = $this->lista();

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertOk();

        $this->assertStringContainsString(
            'lista-empaque-'.$lista->id.'-2026-09-01.xlsx',
            $resp->headers->get('content-disposition')
        );
    }

    // ------------------------------------------------------------- imprimible

    public function test_la_version_imprimible_trae_los_datos_y_los_totales(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario())->get(route('facturacion.listas.imprimir', $lista))->assertOk()
            ->assertSee('LISTA DE EMPAQUE / PACKING LIST')
            ->assertSee('CAROLINAS WHOLESALE LLC')
            ->assertSee('Caja de camote \\ Sweet potato candy box')
            ->assertSee('12015435846')
            // Totales: 10 + 4 cajas.
            ->assertSee('TOTALES / TOTALS')
            ->assertSee('14');
    }
}
