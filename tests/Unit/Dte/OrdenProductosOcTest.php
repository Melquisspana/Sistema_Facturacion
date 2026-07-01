<?php

namespace Tests\Unit\Dte;

use App\Support\Dte\OrdenProductosOc;
use PHPUnit\Framework\TestCase;

class OrdenProductosOcTest extends TestCase
{
    public function test_ordena_por_codigo_de_barras_en_la_secuencia_de_la_oc(): void
    {
        // Primer y último ítem de la orden de compra por código de barras (17 ítems: 0..16).
        $this->assertSame(0, OrdenProductosOc::rank('7412201700178', 'lo que sea')); // SEMILLA DE MARAÑON
        $this->assertSame(16, OrdenProductosOc::rank('7412201700115', 'MAZAPÁN'));    // MAZAPÁN
    }

    public function test_el_codigo_de_barras_manda_sobre_el_nombre(): void
    {
        // Barcode de CANILLITAS (rank 8) aunque el nombre diga otra cosa.
        $this->assertSame(8, OrdenProductosOc::rank('7412201700031', 'NOMBRE RARO'));
    }

    public function test_besitos_quedo_fuera_de_la_lista_fija(): void
    {
        // BESITOS se sacó de la lista a propósito: ni por su código real (284) ni por
        // nombre debe ordenarse; va al final con los no listados.
        $this->assertSame(OrdenProductosOc::FUERA_DE_ORDEN, OrdenProductosOc::rank('7412201700284', 'BESITOS'));
        $this->assertSame(OrdenProductosOc::FUERA_DE_ORDEN, OrdenProductosOc::rank(null, 'BESITOS'));
    }

    public function test_calza_por_nombre_normalizado_sin_acentos_ni_mayusculas(): void
    {
        $this->assertSame(16, OrdenProductosOc::rank(null, 'mazapán'));
        $this->assertSame(0, OrdenProductosOc::rank(null, 'Semilla de Marañon'));
    }

    public function test_fuera_de_la_lista_va_al_final(): void
    {
        $this->assertSame(OrdenProductosOc::FUERA_DE_ORDEN, OrdenProductosOc::rank('0000000000000', 'PRODUCTO INEXISTENTE'));
        $this->assertSame(OrdenProductosOc::FUERA_DE_ORDEN, OrdenProductosOc::rank(null, null));
    }

    public function test_secuencia_completa_ordena_como_la_orden_de_compra(): void
    {
        // Desordenados; al ordenar por rank deben quedar en la secuencia de la OC.
        $productos = [
            ['MAZAPÁN', '7412201700115'],
            ['CANILLITAS', '7412201700031'],
            ['SEMILLA DE MARAÑON', '7412201700178'],
            ['FUERA', '9999999999999'],
        ];

        usort($productos, fn ($a, $b) => OrdenProductosOc::rank($a[1], $a[0]) <=> OrdenProductosOc::rank($b[1], $b[0]));

        $nombres = array_column($productos, 0);
        $this->assertSame(['SEMILLA DE MARAÑON', 'CANILLITAS', 'MAZAPÁN', 'FUERA'], $nombres);
    }
}
