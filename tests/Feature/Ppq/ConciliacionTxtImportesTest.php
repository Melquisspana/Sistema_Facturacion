<?php

namespace Tests\Feature\Ppq;

use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\Dinero;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los IMPORTES del TXT de pagos de Calleja, leídos exactos.
 *
 * Esta clase existe por un centavo que costaba noventa y cinco dólares: Calleja escribe
 * los abonos chicos sin el cero entero (`-.96`) y el parser los convertía en −96. Sobre el
 * archivo real del 07/09/2026 eso inflaba el total de notas de crédito de −$125.90 a
 * −$220.94 y descuadraba el neto del archivo en $95.04, que es exactamente la clase de
 * error que nadie ve hasta que el cliente paga distinto de lo que dice el sistema.
 *
 * La prueba dorada corre sobre el ARCHIVO REAL (`tests/Fixtures/Ppq/`), no sobre un
 * ejemplo inventado: los cuatro totales que compara son los que el cliente informó.
 *
 * No toca la base de datos ni la red: es solo el lector del archivo.
 */
class ConciliacionTxtImportesTest extends TestCase
{
    /** El archivo real que mandó Calleja el 07/09/2026. */
    private function archivoReal(): string
    {
        $ruta = base_path('tests/Fixtures/Ppq/pagos-000123-20260907.txt');
        $this->assertFileExists($ruta, 'Falta el TXT real de referencia.');

        return (string) file_get_contents($ruta);
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function total(array $filas, string $tipo): string
    {
        $total = '0';
        foreach ($filas as $f) {
            if ($f['tipo'] === $tipo) {
                $total = Dinero::sumar($total, $f['valor'] ?? 0);
            }
        }

        return Dinero::redondear($total);
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · el archivo real cuadra con lo que Calleja informó: 45 CF por $6,826.42,
     * 13 NC por −$125.90, el ajuste QD de −$107.21 y un neto de $6,593.31.
     */
    public function test_dorada_el_txt_real_de_calleja_da_los_totales_informados(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $cf = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'CF'));
        $nc = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'NC'));
        $qd = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'QD'));

        $this->assertCount(45, $cf, 'El archivo trae 45 CF.');
        $this->assertCount(13, $nc, 'El archivo trae 13 NC.');
        $this->assertCount(1, $qd, 'El archivo trae un solo ajuste QD.');
        $this->assertCount(59, $filas, 'Encabezado descartado: 45 + 13 + 1.');

        $this->assertSame('6826.42', $this->total($filas, 'CF'));
        $this->assertSame('-125.90', $this->total($filas, 'NC'));
        $this->assertSame('-107.21', $this->total($filas, 'QD'));

        $neto = Dinero::sumar(Dinero::sumar($this->total($filas, 'CF'), $this->total($filas, 'NC')), $this->total($filas, 'QD'));
        $this->assertSame('6593.31', Dinero::redondear($neto));
    }

    /**
     * DORADA · la fila exacta del error: `-.96` son noventa y seis centavos.
     *
     * Se comprueba el valor Y que no sea el viejo −96: la diferencia entre los dos es la
     * que descuadraba el archivo entero.
     */
    public function test_dorada_el_abono_escrito_sin_cero_entero_vale_noventa_y_seis_centavos(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $fila = null;
        foreach ($filas as $f) {
            if ($f['numeroNorm'] === 'DTE05M001P001000000000000385') {
                $fila = $f;
                break;
            }
        }

        $this->assertNotNull($fila, 'La NC 385 tiene que estar en el archivo real.');
        $this->assertStringContainsString(';-.96', $fila['raw'], 'En el archivo viene escrita sin el cero entero.');
        $this->assertSame(0, Dinero::comparar('-0.96', $fila['valor']));
        $this->assertSame(-1, Dinero::comparar('-1', $fila['valor']), 'No puede ser −96 ni ningún otro valor menor que −1.');
    }

    /** El ajuste QD del archivo real: referencia PPQ/31001 y −$107.21, sin fecha. */
    public function test_el_ajuste_qd_conserva_su_referencia_y_no_trae_fecha(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $qd = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'QD'))[0];

        $this->assertSame('PPQ/31001', $qd['numero']);
        $this->assertSame('PPQ31001', $qd['numeroNorm']);
        $this->assertSame(0, Dinero::comparar('-107.21', $qd['valor']));
        $this->assertNull($qd['fecha'], 'El QD viene con la fecha vacía y no se inventa ninguna.');
    }

    // ------------------------------------------------------------------ bordes

    /**
     * Escrituras del importe que hay que soportar, y las que no son un número.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function importes(): array
    {
        return [
            'sin cero entero, negativo' => ['-.96', '-0.96'],
            'sin cero entero, positivo' => ['.96', '0.96'],
            'normal' => ['126.44', '126.44'],
            'un solo decimal' => ['-5.3', '-5.3'],
            'miles con coma' => ['1,234.56', '1234.56'],
            'miles con punto (europeo)' => ['1.234,56', '1234.56'],
            'varios separadores de miles' => ['-1.234.567,89', '-1234567.89'],
            'entero sin decimales' => ['1000', '1000'],
            'cero negativo no lleva signo' => ['-0.00', '0.00'],
            'con espacios alrededor' => ['  -.5  ', '-0.5'],
            'vacío' => ['', null],
            'solo el signo' => ['-', null],
            'solo el separador' => ['.', null],
            'texto' => ['N/A', null],
        ];
    }

    #[DataProvider('importes')]
    public function test_lee_cada_escritura_del_importe(string $escrito, ?string $esperado): void
    {
        $contenido = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P001000000000000001;05-JUN-26;{$escrito}\n";

        $filas = app(ConciliacionTxtParser::class)->parse($contenido);

        $this->assertCount(1, $filas);
        $this->assertSame($esperado, $filas[0]['valor']);
    }

    /**
     * El importe sale como CADENA, no como float: lo que sigue después son comparaciones y
     * sumas con BCMath, y un `float` de por medio vuelve a abrir la puerta a los centavos
     * perdidos.
     */
    public function test_el_importe_es_una_cadena_decimal_no_un_float(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        foreach ($filas as $fila) {
            if ($fila['valor'] !== null) {
                $this->assertIsString($fila['valor'], 'Línea '.$fila['linea'].': el importe debe ser cadena.');
            }
        }
    }

    /** El nombre con Ñ sobrevive al encoding del archivo real (viene en Windows-1252). */
    public function test_el_nombre_del_proveedor_conserva_la_enie(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $this->assertStringContainsString('ESPAÑA', (string) $filas[0]['nombre']);
    }
}
