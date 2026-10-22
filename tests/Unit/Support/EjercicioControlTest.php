<?php

namespace Tests\Unit\Support;

use App\Support\EjercicioControl;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * El ejercicio que identifica un número de control (#121).
 *
 * Desde 2027 el correlativo vuelve a 1 cada año (#42), así que el número solo identifica
 * junto con su año. Antes de 2027 el número ya era único por sí solo: todo lo anterior
 * comparte el valor 2026 y no hace falta adivinar el año de un histórico sin fecha.
 */
class EjercicioControlTest extends TestCase
{
    public function test_lo_anterior_al_reinicio_comparte_2026_y_desde_2027_vale_el_anio(): void
    {
        $this->assertSame(2026, EjercicioControl::de('2024-03-01'));
        $this->assertSame(2026, EjercicioControl::de('2025-12-31'));
        $this->assertSame(2026, EjercicioControl::de('2026-12-31'));
        $this->assertSame(2027, EjercicioControl::de('2027-01-01'));
        $this->assertSame(2028, EjercicioControl::de(Carbon::parse('2028-06-15')));
        $this->assertSame(2026, EjercicioControl::de(2019));
        $this->assertSame(2027, EjercicioControl::de(2027));
        $this->assertNull(EjercicioControl::de(null));
        $this->assertNull(EjercicioControl::de(''));
    }

    public function test_la_llave_une_numero_normalizado_y_ejercicio(): void
    {
        $this->assertSame(
            EjercicioControl::llave('DTE-03-M001P002-000000000000001', 2027),
            EjercicioControl::llave('DTE03M001P002000000000000001', 2027),
        );
        $this->assertNotSame(
            EjercicioControl::llave('DTE-03-M001P002-000000000000001', 2026),
            EjercicioControl::llave('DTE-03-M001P002-000000000000001', 2027),
        );
        $this->assertNull(EjercicioControl::llave(null, 2027));
        $this->assertNull(EjercicioControl::llave('  ', 2027));
    }
}
