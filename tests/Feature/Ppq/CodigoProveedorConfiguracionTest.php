<?php

namespace Tests\Feature\Ppq;

use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Models\PpqLote;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Services\Ppq\QuedanCallejaExporter;
use App\Services\Ppq\ValidadorCodigoProveedorTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CodigoProveedorConfiguracionTest extends TestCase
{
    public function test_acepta_el_codigo_configurado_incluso_si_no_es_el_ejemplo(): void
    {
        config(['ppq.codigo_proveedor' => '000456']);
        $filas = app(ConciliacionTxtParser::class)->parse('000456;TITULAR DE EJEMPLO;CF;DTE03M001P001000000000000001;01-SEP-26;100');

        app(ValidadorCodigoProveedorTxt::class)->verificar($filas);

        $this->assertCount(1, $filas);
    }

    public function test_rechaza_otro_codigo(): void
    {
        config(['ppq.codigo_proveedor' => '000456']);
        $this->expectException(ArchivoProveedorInvalidoException::class);

        app(ValidadorCodigoProveedorTxt::class)->verificar([['raw' => '000123;TITULAR DE EJEMPLO']]);
    }

    public static function codigosVacios(): array
    {
        return [[null], [''], ['   ']];
    }

    #[DataProvider('codigosVacios')]
    public function test_sin_configuracion_rechaza_incluso_un_archivo_vacio(?string $codigo): void
    {
        config(['ppq.codigo_proveedor' => $codigo]);
        $this->expectException(ArchivoProveedorInvalidoException::class);
        $this->expectExceptionMessage('Falta configurar el código de proveedor (PPQ_CODIGO_PROVEEDOR)');

        app(ValidadorCodigoProveedorTxt::class)->verificar([]);
    }

    public static function exportadores(): array
    {
        return [[ExcelCallejaExporter::class, 'generar'], [ExcelCallejaExporter::class, 'nombreArchivo'], [QuedanCallejaExporter::class, 'generar'], [QuedanCallejaExporter::class, 'nombreArchivo']];
    }

    #[DataProvider('exportadores')]
    public function test_excel_sin_codigo_falla_antes_de_generar(string $exportador, string $metodo): void
    {
        config(['ppq.codigo_proveedor' => null]);
        $this->expectException(ArchivoProveedorInvalidoException::class);
        $this->expectExceptionMessage('Falta configurar el código de proveedor (PPQ_CODIGO_PROVEEDOR)');

        app($exportador)->{$metodo}(new PpqLote);
    }
}
