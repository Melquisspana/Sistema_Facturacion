<?php

namespace Tests\Unit\Dte;

use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\NumeroALetras;
use App\Support\Dte\NumeroControlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UtilidadesJsonTest extends TestCase
{
    // --- CodigoGeneracion (UUID v4 mayúsculas) ---

    public function test_uuid_es_valido_y_en_mayusculas(): void
    {
        $uuid = CodigoGeneracion::generar();

        $this->assertSame(strtoupper($uuid), $uuid);
        $this->assertTrue(CodigoGeneracion::esValido($uuid));
        $this->assertMatchesRegularExpression('/^[0-9A-F-]{36}$/', $uuid);
    }

    public function test_uuid_invalido_es_rechazado(): void
    {
        $this->assertFalse(CodigoGeneracion::esValido('no-es-uuid'));
        $this->assertFalse(CodigoGeneracion::esValido(strtolower(CodigoGeneracion::generar()))); // minúsculas
    }

    // --- NumeroControlBuilder ---

    public function test_numero_control_formato_esperado(): void
    {
        $this->assertSame(
            'DTE-03-M001P001-000000000000001',
            NumeroControlBuilder::construir('03', 'M001', 'P001', 1)
        );
        $this->assertSame(
            'DTE-01-M001P001-000000000001234',
            NumeroControlBuilder::construir('01', 'M001', 'P001', 1234)
        );
    }

    public function test_numero_control_rellena_codigos_cortos(): void
    {
        // Códigos de menos de 4 caracteres se rellenan con ceros a la izquierda.
        $this->assertSame(
            'DTE-03-00010002-000000000000005',
            NumeroControlBuilder::construir('03', '1', '2', 5)
        );
    }

    public function test_numero_control_tipo_invalido_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumeroControlBuilder::construir('3', 'M001', 'P001', 1);
    }

    public function test_numero_control_correlativo_invalido_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumeroControlBuilder::construir('03', 'M001', 'P001', 0);
    }

    // --- NumeroALetras ---

    #[DataProvider('montos')]
    public function test_numero_a_letras(string|int|float $monto, string $esperado): void
    {
        $this->assertSame($esperado, NumeroALetras::convertir($monto));
    }

    public static function montos(): array
    {
        return [
            [0, 'CERO 00/100 DÓLARES'],
            [113.00, 'CIENTO TRECE 00/100 DÓLARES'],
            [18.50, 'DIECIOCHO 50/100 DÓLARES'],
            [100, 'CIEN 00/100 DÓLARES'],
            [21, 'VEINTIUNO 00/100 DÓLARES'],
            [1000, 'MIL 00/100 DÓLARES'],
            [1234.56, 'MIL DOSCIENTOS TREINTA Y CUATRO 56/100 DÓLARES'],
            [21000, 'VEINTIÚN MIL 00/100 DÓLARES'],
            [1000000, 'UN MILLÓN 00/100 DÓLARES'],
            [2000000, 'DOS MILLONES 00/100 DÓLARES'],
        ];
    }
}
