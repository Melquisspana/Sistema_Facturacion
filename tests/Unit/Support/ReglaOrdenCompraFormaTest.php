<?php

namespace Tests\Unit\Support;

use App\Support\Dte\ReglaOrdenCompra;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Forma de la orden de compra del cliente que la exige (#169): `AAMM` + sala de 4
 * dígitos que empieza con 0 + correlativo, de 13 a 15 dígitos en total, con el año
 * y el mes a no más de un mes de la fecha del CCF. Órdenes ficticias.
 */
class ReglaOrdenCompraFormaTest extends TestCase
{
    private function problemas(string $orden, string $fecha = '2026-11-03'): array
    {
        return ReglaOrdenCompra::problemasForma($orden, Carbon::parse($fecha));
    }

    public function test_orden_valida_no_avisa(): void
    {
        $this->assertSame([], $this->problemas('26110123456789'));
        $this->assertSame([], $this->problemas(' 26110123456789 '));
    }

    public function test_un_mes_antes_o_despues_cuadra_incluso_al_cambiar_de_anio(): void
    {
        $this->assertSame([], $this->problemas('26100123456789'));
        $this->assertSame([], $this->problemas('26120123456789'));
        $this->assertSame([], $this->problemas('25120123456789', '2026-01-05'));
        $this->assertSame([], $this->problemas('27010123456789', '2026-12-28'));
    }

    public function test_de_13_a_15_digitos_cuadran_porque_el_correlativo_crece(): void
    {
        // Como vienen de Calleja: 2611 + sala 0232 + correlativo de 5, 6 o 7 cifras.
        $this->assertSame([], $this->problemas('2611023200103'));
        $this->assertSame([], $this->problemas('26110232001039'));
        $this->assertSame([], $this->problemas('261102320010394'));
    }

    public function test_menos_de_13_o_mas_de_15_digitos_avisa(): void
    {
        $this->assertSame(
            ['La orden de compra «2611023200103941» tiene 16 dígitos y deben ser de 13 a 15.'],
            $this->problemas('2611023200103941'),
        );
        $this->assertSame(
            ['La orden de compra «261102320010» tiene 12 dígitos y deben ser de 13 a 15.'],
            $this->problemas('261102320010'),
        );
    }

    public function test_letras_o_simbolos_avisan(): void
    {
        $this->assertSame(
            ['La orden de compra «2611012345678A» tiene caracteres que no son números.'],
            $this->problemas('2611012345678A'),
        );
        $this->assertCount(1, $this->problemas('26110123456789}'));
    }

    public function test_quinto_digito_distinto_de_cero_avisa(): void
    {
        $this->assertSame(
            ['El quinto dígito de la orden de compra «26115123456789» debería ser 0.'],
            $this->problemas('26115123456789'),
        );
    }

    public function test_mes_fuera_de_margen_avisa(): void
    {
        $this->assertSame(
            ['La orden de compra «26080123456789» es de 08/2026 y el CCF es del 03/11/2026: debería ser del mismo mes, del anterior o del siguiente.'],
            $this->problemas('26080123456789'),
        );
        $this->assertCount(1, $this->problemas('25110123456789'));
    }

    public function test_mes_inexistente_avisa(): void
    {
        $this->assertSame(
            ['La orden de compra «26130123456789» no empieza con un año y mes válidos (AAMM).'],
            $this->problemas('26130123456789'),
        );
    }

    public function test_orden_vacia_se_informa(): void
    {
        $this->assertSame(['No tiene orden de compra.'], ReglaOrdenCompra::problemasForma(null, Carbon::parse('2026-11-03')));
    }
}
