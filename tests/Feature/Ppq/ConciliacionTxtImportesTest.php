<?php

namespace Tests\Feature\Ppq;

use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\Dinero;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Importes exactos sobre un fixture ficticio: conserva filas CF/NC/QD y el caso
 * de abono sin cero entero que el parser debe interpretar como centavos.
 */
class ConciliacionTxtImportesTest extends TestCase
{
    /** Fixture ficticio con la misma estructura que el TXT de pagos de Calleja. */
    private function archivoReal(): string
    {
        $ruta = base_path('tests/Fixtures/Ppq/pagos-000123-20260907.txt');
        $this->assertFileExists($ruta, 'Falta el TXT ficticio de referencia.');

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
     * DORADA · el fixture ficticio cuadra con sus totales conocidos: 45 CF por $4,500.00,
     * 13 NC por −$60.50, el ajuste QD de −$25.00 y un neto de $4,414.50.
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

        $this->assertSame('4500.00', $this->total($filas, 'CF'));
        $this->assertSame('-60.50', $this->total($filas, 'NC'));
        $this->assertSame('-25.00', $this->total($filas, 'QD'));

        $neto = Dinero::sumar(Dinero::sumar($this->total($filas, 'CF'), $this->total($filas, 'NC')), $this->total($filas, 'QD'));
        $this->assertSame('4414.50', Dinero::redondear($neto));
    }

    /**
     * DORADA · la fila exacta del error: `-.50` son cincuenta centavos.
     *
     * Se comprueba el valor Y que no sea el viejo −50: la diferencia entre los dos es la
     * que descuadraba el archivo entero.
     */
    public function test_dorada_el_abono_escrito_sin_cero_entero_vale_cincuenta_centavos(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $fila = null;
        foreach ($filas as $f) {
            if ($f['numeroNorm'] === 'DTE05M001P001000000000090013') {
                $fila = $f;
                break;
            }
        }

        $this->assertNotNull($fila, 'La NC de ejemplo tiene que estar en el fixture ficticio.');
        $this->assertStringContainsString(';-.50', $fila['raw'], 'En el archivo viene escrita sin el cero entero.');
        $this->assertSame(0, Dinero::comparar('-0.50', $fila['valor']));
        $this->assertSame(-1, Dinero::comparar('-1', $fila['valor']), 'No puede ser −50 ni ningún otro valor menor que −1.');
    }

    /** El ajuste QD del fixture ficticio: referencia PPQ/31001 y −$25.00, sin fecha. */
    public function test_el_ajuste_qd_conserva_su_referencia_y_no_trae_fecha(): void
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());

        $qd = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'QD'))[0];

        $this->assertSame('PPQ/31001', $qd['numero']);
        $this->assertSame('PPQ31001', $qd['numeroNorm']);
        $this->assertSame(0, Dinero::comparar('-25.00', $qd['valor']));
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
            'sin cero entero, negativo' => ['-.50', '-0.50'],
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

    /** El nombre con Ñ sobrevive al encoding con que Calleja manda el TXT (Windows-1252). */
    public function test_el_nombre_del_proveedor_conserva_la_enie(): void
    {
        $contenido = "000123;TITULAR DE EJEMPLO PE\xD1A;CF;DTE03M001P001000000000000001;05-JUN-26;1.00\n";

        $filas = app(ConciliacionTxtParser::class)->parse($contenido);

        $this->assertStringContainsString('PEÑA', (string) $filas[0]['nombre']);
    }
}
