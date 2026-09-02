<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use App\Models\ExportacionItem;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Construye la hoja «Lista» del Excel de lista de empaque DESDE CERO, sin
 * depender de ningún archivo de plantilla.
 *
 * POR QUÉ SIN PLANTILLA. El generador original cargaba
 * `storage/app/templates/exportaciones/lista_empaque.xlsx` y rellenaba sus
 * celdas. Ese archivo no está en el disco, así que la única salida del módulo
 * lanzaba una excepción — y como nadie había creado nunca una lista, el fallo
 * llevaba meses invisible. Un formato que depende de un binario que no está
 * versionado no es reproducible: se va con un despliegue limpio y no hay prueba
 * que lo detecte.
 *
 * QUÉ FORMATO SE REPRODUCE. El de las listas reales que se mandan al cliente,
 * tomando como referencia canónica «Lista empaque julio 2026.xlsx»: título
 * `LISTA DE EMPAQUE`, bloque superior con exportador (B2:I3), cliente (K2:S3),
 * facturas y fecha a la izquierda y FDA en E4:I5; fila 7 con los rótulos de
 * grupo (FACTURA, «Peso en kilogramos» gris, «Peso en libras» azul), fila 8 con
 * los nombres de columna y productos desde la 9. NO hay una segunda fila de
 * traducciones al inglés: el bilingüe vive dentro de la descripción de cada
 * producto («español \ english»), como en el archivo real.
 *
 * Las medidas —anchos, alturas, combinaciones, bordes y los dos colores del
 * bloque de pesos— salen de ese archivo y están fijadas acá como constantes. Es
 * la única forma de que el formato viaje con el código en vez de con un adjunto
 * que alguien tiene que acordarse de copiar.
 *
 * SIN REDONDEOS PREVIOS. A la hoja van los valores capturados tal cual y todo lo
 * derivado va como FÓRMULA (onzas, totales, libras). Redondear antes de calcular
 * mete un error que se acumula renglón a renglón y termina en un peso total que
 * no cuadra con la factura; acá el número conserva su precisión y es el FORMATO
 * de la celda el que decide cuántos decimales se ven.
 *
 * FÓRMULAS, NO VALORES. El archivo se abre en Excel y se edita a mano —cambiar
 * las cajas de un renglón y ver recalcularse el total es parte del uso real—. Un
 * archivo de solo números rompería eso.
 */
class ListaEmpaqueExcelBuilder
{
    /** Primera fila de productos. Igual que en el formato real. */
    public const FILA_PRIMER_PRODUCTO = 9;

    /** Fila de los rótulos de grupo (FACTURA / pesos en kg / pesos en lb). */
    public const FILA_GRUPOS = 7;

    /** Fila de los nombres de columna. */
    public const FILA_CABECERAS = 8;

    /** Constantes de conversión, visibles en la hoja como en el formato original. */
    private const FACTOR_GRAMOS_A_ONZAS = 0.035274;

    private const FACTOR_KG_A_LB = 2.2046;

    /** Los colores del archivo de julio. */
    private const GRIS_ROTULO = 'FFBFBFBF';

    private const GRIS_KILOS = 'FFD0CECE';

    private const AZUL_LIBRAS = 'FFDEEAF6';

    private const NEGRO = 'FF000000';

    /** Anchos de columna del archivo de julio, en caracteres. */
    private const ANCHOS = [
        'A' => 1.86, 'B' => 8.0, 'C' => 29.57, 'D' => 10.43, 'E' => 7.71,
        'F' => 10.14, 'G' => 7.43, 'H' => 8.14, 'I' => 9.14, 'J' => 8.57,
        'K' => 12.71, 'L' => 6.43, 'M' => 6.71, 'N' => 8.29, 'O' => 7.29,
        'P' => 6.43, 'Q' => 5.86, 'R' => 4.71, 'S' => 8.29, 'T' => 8.0,
    ];

    /**
     * Columnas de los dos factores de conversión. Se ocultan: son parte del
     * cálculo (H = F×G, Q = L×P…) pero no del documento que lee el cliente.
     */
    private const COLUMNAS_OCULTAS = ['G', 'P'];

    /**
     * Alturas fijas. Las que no están acá —4, 8 y las de producto— se dejan en
     * automático a propósito: crecen solas cuando una descripción larga o varias
     * facturas ocupan dos líneas.
     */
    private const ALTURAS = [1 => 21.75, 2 => 15.0, 3 => 12.75, 5 => 12.0, 6 => 5.25, 7 => 15.75];

    /** Nombres de columna de la fila 8. G y P quedan sin rótulo: van ocultas. */
    private const CABECERAS = [
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

    /**
     * TODO lo derivado de la hoja, como fórmula y con la MISMA expresión en todos
     * los renglones. `{f}` es el número de fila.
     *
     * Que estén en una tabla y no repartidas por el código es lo que hace que un
     * renglón no pueda salir con una fórmula distinta del de arriba: el fallo
     * clásico de estas hojas, donde alguien arrastra mal una celda y una sola fila
     * queda calculando otra cosa.
     */
    private const FORMULAS = [
        'H' => '=F{f}*G{f}',   // onzas por unidad = gramos × factor
        'I' => '=B{f}*E{f}',   // total de unidades = cajas × unidades por caja
        'K' => '=B{f}*J{f}',   // valor total = cajas × precio por caja
        'N' => '=B{f}*L{f}',   // peso neto total en kilogramos
        'O' => '=B{f}*M{f}',   // peso bruto total en kilogramos
        'Q' => '=L{f}*P{f}',   // peso neto por caja en libras
        'R' => '=M{f}*P{f}',   // peso bruto por caja en libras
        'S' => '=N{f}*P{f}',   // peso neto total en libras
        'T' => '=O{f}*P{f}',   // peso bruto total en libras
    ];

    /**
     * Estilo de cada columna en las filas de producto:
     * [tamaño de letra, ajuste de texto, formato numérico, relleno].
     */
    private const ESTILO_PRODUCTO = [
        'B' => [10, true, NumberFormat::FORMAT_GENERAL, null],
        'C' => [9, true, NumberFormat::FORMAT_GENERAL, null],
        'D' => [6, true, NumberFormat::FORMAT_GENERAL, null],
        'E' => [9, true, NumberFormat::FORMAT_GENERAL, null],
        'F' => [9, true, NumberFormat::FORMAT_GENERAL, null],
        'G' => [9, false, NumberFormat::FORMAT_GENERAL, null],
        'H' => [9, false, '0.0', null],
        'I' => [9, false, NumberFormat::FORMAT_GENERAL, null],
        'J' => [9, false, '"$"#,##0.00', null],
        'K' => [9, false, '"$"#,##0.00', null],
        'L' => [9, false, '#,##0.0', self::GRIS_KILOS],
        'M' => [9, false, '#,##0.0', self::GRIS_KILOS],
        'N' => [9, false, '0.0', self::GRIS_KILOS],
        'O' => [9, false, '0.0', self::GRIS_KILOS],
        'P' => [9, false, '0.0000', null],
        'Q' => [9, false, '0.0', self::AZUL_LIBRAS],
        'R' => [9, false, '0.0', self::AZUL_LIBRAS],
        'S' => [9, false, '0.0', self::AZUL_LIBRAS],
        'T' => [9, false, '0.0', self::AZUL_LIBRAS],
    ];

    /**
     * Columnas que llevan una suma al pie, con su estilo:
     * [tamaño, alineación horizontal, formato numérico].
     */
    private const ESTILO_TOTAL = [
        'B' => [10, Alignment::HORIZONTAL_CENTER, NumberFormat::FORMAT_GENERAL],
        'I' => [10, Alignment::HORIZONTAL_CENTER, '#,##0'],
        'K' => [9, Alignment::HORIZONTAL_RIGHT, '"$"#,##0.00'],
        'N' => [9, Alignment::HORIZONTAL_RIGHT, '#,##0.00'],
        'O' => [9, Alignment::HORIZONTAL_RIGHT, '#,##0.00'],
        'S' => [9, Alignment::HORIZONTAL_RIGHT, '#,##0.00'],
        'T' => [9, Alignment::HORIZONTAL_RIGHT, '#,##0.00'],
    ];

    public function construir(Exportacion $exportacion): Spreadsheet
    {
        $exportacion->loadMissing('items');

        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Lista');

        $this->prepararHoja($hoja);
        $this->escribirEncabezado($hoja, $exportacion);
        $this->escribirCabeceras($hoja);
        $ultimaFilaProducto = $this->escribirProductos($hoja, $exportacion);
        $this->escribirTotales($hoja, $ultimaFilaProducto);
        $this->configurarImpresion($hoja);

        $hoja->setSelectedCell('A1');

        return $spreadsheet;
    }

    // -------------------------------------------------------------------- hoja

    private function prepararHoja(Worksheet $hoja): void
    {
        foreach (self::ANCHOS as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        foreach (self::COLUMNAS_OCULTAS as $columna) {
            $hoja->getColumnDimension($columna)->setVisible(false);
        }

        foreach (self::ALTURAS as $fila => $alto) {
            $hoja->getRowDimension($fila)->setRowHeight($alto);
        }
    }

    /**
     * Impresión apaisada y a UNA página de ancho. Sin esto la hoja se parte por la
     * mitad: las columnas de libras salen en un papel aparte y la lista deja de
     * poder compararse con la caja física.
     *
     * El alto NO se fuerza a una página —una lista de 40 productos tiene que poder
     * usar dos— y por eso las filas 7 y 8 se repiten arriba en cada una: sin ellas
     * la segunda hoja es una tabla de números sin nombre de columna.
     */
    private function configurarImpresion(Worksheet $hoja): void
    {
        $configuracion = $hoja->getPageSetup();
        $configuracion->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $configuracion->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $configuracion->setFitToWidth(1);
        $configuracion->setFitToHeight(0);
        $configuracion->setRowsToRepeatAtTopByStartAndEnd(self::FILA_GRUPOS, self::FILA_CABECERAS);

        // Márgenes laterales mínimos: son los que hacen que las 19 columnas quepan
        // a lo ancho sin que Excel tenga que encogerlo todo para compensar.
        $margenes = $hoja->getPageMargins();
        $margenes->setLeft(0.2362);
        $margenes->setRight(0.2362);
        $margenes->setTop(0.748);
        $margenes->setBottom(0.748);
        $margenes->setHeader(0.0);
        $margenes->setFooter(0.0);
    }

    // -------------------------------------------------------------- encabezado

    private function escribirEncabezado(Worksheet $hoja, Exportacion $e): void
    {
        $hoja->mergeCells('B1:T1');
        $hoja->setCellValue('B1', 'LISTA DE EMPAQUE');
        $hoja->getStyle('B1')->getFont()->setBold(true)->setItalic(true)->setSize(14);
        $hoja->getStyle('B1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Bloque izquierdo: exportador y su dirección, rótulo en B y valor en C:I.
        $this->rotuloBloque($hoja, 'B2', 'Exportador');
        $this->valorBloque($hoja, 'C2:I2', (string) $e->exportador_nombre, 10, italica: true);

        $this->rotuloBloque($hoja, 'B3', 'Direccion');
        $this->valorBloque($hoja, 'C3:I3', (string) $e->exportador_direccion, 9, italica: true);

        // Bloque derecho: cliente y su dirección, rótulo en K y valor en L:S.
        $this->rotuloBloque($hoja, 'K2', 'Cliente');
        $this->valorBloque($hoja, 'L2:S2', (string) $e->cliente_nombre, 10);

        $this->rotuloBloque($hoja, 'K3', 'Dirección');
        $this->valorBloque($hoja, 'L3:S3', (string) $e->cliente_direccion, 10);

        $this->escribirFacturasYFecha($hoja, $e);
        $this->escribirFda($hoja, $e);
        $this->escribirRotulosDeGrupo($hoja);
    }

    /**
     * Facturas y fecha, en el bloque izquierdo bajo el exportador.
     *
     * La casilla de facturas YA NO ES TEXTO LIBRE: sale de las FEX vinculadas. Con
     * varias se listan todas separadas por « · » en la misma celda, que ajusta el
     * texto y deja crecer el alto de la fila. Así una lista con tres facturas no
     * desarma el encabezado ni empuja el FDA de su sitio.
     */
    private function escribirFacturasYFecha(Worksheet $hoja, Exportacion $e): void
    {
        $this->rotuloCasilla($hoja, 'B4', 'Facturas');
        // Explícitamente texto: un número de control como DTE-11-… no es un número,
        // y un correlativo corto se convertiría en numérico perdiendo los ceros.
        $hoja->setCellValueExplicit('C4', $e->textoFactura(), DataType::TYPE_STRING);
        $hoja->getStyle('C4')->getFont()->setSize(9);
        $hoja->getStyle('C4')->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $this->borde($hoja, 'C4', ['allBorders']);

        $this->rotuloCasilla($hoja, 'B5', 'Fecha');
        if ($e->fecha !== null) {
            $hoja->setCellValue('C5', ExcelDate::PHPToExcel($e->fecha->startOfDay()));
            $hoja->getStyle('C5')->getNumberFormat()->setFormatCode('m/d/yyyy');
        }
        $hoja->getStyle('C5')->getFont()->setSize(10);
        // A la izquierda y no donde Excel manda las fechas —a la derecha—: así queda
        // pegada a su rótulo, como el resto de las casillas del bloque.
        $hoja->getStyle('C5')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $this->borde($hoja, 'C5', ['allBorders']);
    }

    private function escribirFda(Worksheet $hoja, Exportacion $e): void
    {
        $hoja->mergeCells('E4:I4');
        $hoja->setCellValue('E4', 'FDA reg. number');
        $hoja->getStyle('E4')->getFont()->setBold(true)->setItalic(true)->setSize(10);
        $hoja->getStyle('E4')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $this->borde($hoja, 'E4:I4', ['outline']);

        $hoja->mergeCells('E5:I5');
        // Texto, para no perder los ceros iniciales del registro.
        $hoja->setCellValueExplicit('E5', (string) $e->fda_reg_number, DataType::TYPE_STRING);
        $hoja->getStyle('E5')->getFont()->setSize(10);
        $hoja->getStyle('E5')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $this->borde($hoja, 'E5:I5', ['outline']);
    }

    /**
     * Fila 7: las tres cabeceras de grupo. FACTURA a la izquierda y, sobre las
     * columnas de peso, las dos bandas de color que separan kilogramos de libras.
     */
    private function escribirRotulosDeGrupo(Worksheet $hoja): void
    {
        $fila = self::FILA_GRUPOS;

        $hoja->setCellValue('C'.$fila, 'FACTURA');
        $hoja->getStyle('C'.$fila)->getFont()->setBold(true)->setItalic(true)->setSize(14);
        $hoja->getStyle('C'.$fila)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $this->borde($hoja, 'C'.$fila, ['allBorders']);

        $this->bandaDePesos($hoja, 'L', 'O', 'Peso en kilogramos', self::GRIS_ROTULO, Border::BORDER_THIN);
        $this->bandaDePesos($hoja, 'Q', 'T', 'Peso en libras', self::AZUL_LIBRAS, Border::BORDER_MEDIUM);
    }

    /**
     * Banda de color sobre las cuatro columnas de un bloque de pesos, abierta por
     * abajo para que se lea pegada a la fila de nombres de columna.
     */
    private function bandaDePesos(
        Worksheet $hoja,
        string $desde,
        string $hasta,
        string $texto,
        string $relleno,
        string $borde
    ): void {
        $fila = self::FILA_GRUPOS;
        $rango = $desde.$fila.':'.$hasta.$fila;

        $hoja->mergeCells($rango);
        $hoja->setCellValue($desde.$fila, $texto);
        $hoja->getStyle($rango)->getFont()->setBold(true)->setItalic(true)->setSize(12);
        $hoja->getStyle($rango)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->rellenar($hoja, $rango, $relleno);

        $this->borde($hoja, $rango, ['top'], $borde);
        $this->borde($hoja, $desde.$fila, ['left'], $borde);
        $this->borde($hoja, $hasta.$fila, ['right'], $borde);
    }

    /** Rótulo pequeño de los bloques de exportador y cliente. */
    private function rotuloBloque(Worksheet $hoja, string $celda, string $texto): void
    {
        $hoja->setCellValue($celda, $texto);
        $hoja->getStyle($celda)->getFont()->setBold(true)->setSize(7);
        $hoja->getStyle($celda)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_TOP);
        $this->borde($hoja, $celda, ['allBorders']);
    }

    /** Valor de un bloque del encabezado: celdas combinadas con marco propio. */
    private function valorBloque(Worksheet $hoja, string $rango, string $texto, int $tamano, bool $italica = false): void
    {
        $primera = explode(':', $rango)[0];

        $hoja->mergeCells($rango);
        $hoja->setCellValue($primera, $texto);
        $hoja->getStyle($rango)->getFont()->setSize($tamano)->setItalic($italica);
        $hoja->getStyle($rango)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);
        $this->borde($hoja, $rango, ['outline']);
    }

    /** Rótulo de las casillas de facturas y fecha. */
    private function rotuloCasilla(Worksheet $hoja, string $celda, string $texto): void
    {
        $hoja->setCellValue($celda, $texto);
        $hoja->getStyle($celda)->getFont()->setBold(true)->setItalic(true)->setSize(10);
        $hoja->getStyle($celda)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $this->borde($hoja, $celda, ['allBorders']);
    }

    // --------------------------------------------------------------- cabeceras

    private function escribirCabeceras(Worksheet $hoja): void
    {
        $fila = self::FILA_CABECERAS;

        foreach (self::CABECERAS as $columna => $texto) {
            $hoja->setCellValue($columna.$fila, $texto);
        }

        $rango = 'B'.$fila.':T'.$fila;
        $hoja->getStyle($rango)->getFont()->setBold(true)->setSize(7);
        $hoja->getStyle($rango)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $this->borde($hoja, $rango, ['allBorders']);

        // Los dos bloques de pesos siguen coloreados en la fila de nombres: es lo
        // que deja ver de un vistazo dónde acaban los kilos y empiezan las libras.
        $this->rellenar($hoja, 'L'.$fila.':O'.$fila, self::GRIS_KILOS);
        $this->rellenar($hoja, 'Q'.$fila.':T'.$fila, self::AZUL_LIBRAS);
    }

    // --------------------------------------------------------------- productos

    /** @return int última fila de producto escrita (o la anterior si no hay ninguno) */
    private function escribirProductos(Worksheet $hoja, Exportacion $e): int
    {
        $fila = self::FILA_PRIMER_PRODUCTO;

        foreach ($e->items as $item) {
            $this->escribirFilaProducto($hoja, $fila, $item);
            $fila++;
        }

        $ultima = $fila - 1;

        if ($ultima >= self::FILA_PRIMER_PRODUCTO) {
            $this->estilarProductos($hoja, $ultima);
        }

        return $ultima;
    }

    private function escribirFilaProducto(Worksheet $hoja, int $r, ExportacionItem $item): void
    {
        // Datos capturados, SIN redondear: cuántos decimales se ven lo decide el
        // formato de la celda, no el número que se guarda.
        $hoja->setCellValue('B'.$r, (int) $item->cantidad_cajas);
        $hoja->setCellValue('C'.$r, $item->descripcionCombinada());
        $hoja->setCellValue('D'.$r, (string) $item->unidad);
        $hoja->setCellValue('E'.$r, (int) $item->unidades_por_caja);
        $hoja->setCellValue('F'.$r, (float) $item->gramos_por_unidad);
        $hoja->setCellValue('G'.$r, self::FACTOR_GRAMOS_A_ONZAS);
        $hoja->setCellValue('J'.$r, (float) $item->precio_caja);
        $hoja->setCellValue('L'.$r, (float) $item->peso_neto_caja_kg);
        $hoja->setCellValue('M'.$r, (float) $item->peso_bruto_caja_kg);
        $hoja->setCellValue('P'.$r, self::FACTOR_KG_A_LB);

        // Todo lo demás sale de la propia hoja, con la misma fórmula en cada renglón.
        foreach (self::FORMULAS as $columna => $plantilla) {
            $hoja->setCellValue($columna.$r, str_replace('{f}', (string) $r, $plantilla));
        }
    }

    /**
     * Los estilos se aplican por COLUMNA sobre todo el bloque de productos, no
     * celda por celda: una lista de 60 productos son 19 llamadas en vez de 1 140, y
     * el archivo guarda un estilo por columna en vez de uno por celda.
     */
    private function estilarProductos(Worksheet $hoja, int $ultimaFila): void
    {
        $desde = self::FILA_PRIMER_PRODUCTO;

        foreach (self::ESTILO_PRODUCTO as $columna => [$tamano, $ajusta, $formato, $relleno]) {
            $rango = $columna.$desde.':'.$columna.$ultimaFila;

            $hoja->getStyle($rango)->getFont()->setSize($tamano);
            $hoja->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
            $hoja->getStyle($rango)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText($ajusta);

            if ($relleno !== null) {
                $this->rellenar($hoja, $rango, $relleno);
            }
        }

        // La descripción es la única que se lee como texto corrido: alineada a la
        // izquierda y pegada arriba, para que dos renglones no bailen respecto al
        // resto de la fila.
        $hoja->getStyle('C'.$desde.':C'.$ultimaFila)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_TOP);

        $this->borde($hoja, 'B'.$desde.':T'.$ultimaFila, ['allBorders']);
    }

    // ----------------------------------------------------------------- totales

    /**
     * Totales inmediatamente después del último producto. El rango de cada suma se
     * calcula con la última fila real, así que una lista de 3 y una de 40 llevan
     * cada una su `=SUM` correcto sin filas de relleno de por medio.
     */
    private function escribirTotales(Worksheet $hoja, int $ultimaFilaProducto): void
    {
        $fila = max($ultimaFilaProducto, self::FILA_PRIMER_PRODUCTO - 1) + 1;

        // Sin productos no hay rango que sumar: se escriben ceros en vez de un
        // =SUM(B9:B8) que Excel abre como error de referencia.
        $hayProductos = $ultimaFilaProducto >= self::FILA_PRIMER_PRODUCTO;

        $hoja->getRowDimension($fila)->setRowHeight(15.75);

        foreach (self::ESTILO_TOTAL as $columna => [$tamano, $alineacion, $formato]) {
            $celda = $columna.$fila;

            $hoja->setCellValue(
                $celda,
                $hayProductos
                    ? "=SUM({$columna}".self::FILA_PRIMER_PRODUCTO.":{$columna}{$ultimaFilaProducto})"
                    : 0
            );

            $hoja->getStyle($celda)->getFont()->setBold(true)->setSize($tamano);
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($formato);
            $hoja->getStyle($celda)->getAlignment()
                ->setHorizontal($alineacion)
                ->setVertical(Alignment::VERTICAL_CENTER);

            // Sin borde superior: la línea de abajo del último producto ya está ahí.
            $this->borde($hoja, $celda, ['bottom', 'left', 'right']);
        }

        // El bloque de libras cierra con el mismo trazo grueso con el que abre.
        $this->borde($hoja, 'S'.$fila, ['bottom', 'left'], Border::BORDER_MEDIUM);
        $this->borde($hoja, 'T'.$fila, ['bottom', 'right'], Border::BORDER_MEDIUM);
    }

    // ------------------------------------------------------------------ estilo

    /**
     * @param  list<string>  $lados  claves de borde de PhpSpreadsheet: allBorders,
     *                               outline, top, bottom, left o right.
     */
    private function borde(Worksheet $hoja, string $rango, array $lados, string $estilo = Border::BORDER_THIN): void
    {
        $hoja->getStyle($rango)->applyFromArray([
            'borders' => array_fill_keys($lados, [
                'borderStyle' => $estilo,
                'color' => ['argb' => self::NEGRO],
            ]),
        ]);
    }

    private function rellenar(Worksheet $hoja, string $rango, string $color): void
    {
        $hoja->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($color);
    }
}
