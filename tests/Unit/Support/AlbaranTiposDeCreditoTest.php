<?php

namespace Tests\Unit\Support;

use App\Support\Albaran;
use PHPUnit\Framework\TestCase;

/**
 * Una sola lista de tipos de albarán de crédito para todo el sistema: AC02 (avería) y
 * AC04 (devolución). AC06 no se usa. La comparten el quedan del lote PPQ y Cobros.
 */
class AlbaranTiposDeCreditoTest extends TestCase
{
    public function test_los_tipos_de_credito_son_ac02_y_ac04(): void
    {
        $this->assertSame(['AC02', 'AC04'], Albaran::TIPOS_DE_CREDITO);
    }

    public function test_reconoce_el_tipo_sin_importar_mayusculas_ni_espacios(): void
    {
        $this->assertTrue(Albaran::esDeCredito('AC02'));
        $this->assertTrue(Albaran::esDeCredito(' ac04 '));
    }

    public function test_ac06_el_de_entrega_y_lo_vacio_no_son_de_credito(): void
    {
        $this->assertFalse(Albaran::esDeCredito('AC06'));
        $this->assertFalse(Albaran::esDeCredito('AC01'));
        $this->assertFalse(Albaran::esDeCredito(''));
        $this->assertFalse(Albaran::esDeCredito(null));
    }
}
