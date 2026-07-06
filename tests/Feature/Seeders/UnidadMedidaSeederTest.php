<?php

namespace Tests\Feature\Seeders;

use App\Models\UnidadMedida;
use Database\Seeders\UnidadMedidaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica los códigos CAT-014 que asigna el seeder de unidades de medida:
 * Unidad = 59, Otra = 99 y Bolsa = 99 (CAT-014 no tiene código propio de bolsa, se
 * mapea a "Otra"). Así una línea con unidad Bolsa puede generar JSON válido.
 */
class UnidadMedidaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_bolsa_toma_codigo_99_y_unidad_sigue_59(): void
    {
        (new UnidadMedidaSeeder)->run();

        $this->assertSame('99', UnidadMedida::where('nombre', 'Bolsa')->value('codigo'));
        // No regresión: Unidad y Otra conservan sus códigos.
        $this->assertSame('59', UnidadMedida::where('nombre', 'Unidad')->value('codigo'));
        $this->assertSame('99', UnidadMedida::where('nombre', 'Otra')->value('codigo'));
    }

    public function test_seeder_es_idempotente_no_duplica_bolsa(): void
    {
        (new UnidadMedidaSeeder)->run();
        (new UnidadMedidaSeeder)->run();

        $this->assertSame(1, UnidadMedida::where('nombre', 'Bolsa')->count());
        $this->assertSame('99', UnidadMedida::where('nombre', 'Bolsa')->value('codigo'));
    }
}
