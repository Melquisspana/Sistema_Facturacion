<?php

namespace Tests\Feature\Exportaciones;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El comando de unificación funde precios negociados y borra filas: sus candados
 * importan más que su camino feliz, que se verificó contra una copia de la base
 * real (ver docs/DISENO_CATALOGO_EXPORTACION.md).
 */
class UnificarCatalogoExportacionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_si_la_base_no_coincide_con_el_plan_aborta_sin_escribir(): void
    {
        $this->producto(1, 'Caja de semilla de maranon horneada', 216, 45);
        // El #2 del plan es «maní dulce 144 × 85 g»; acá es otra cosa.
        $this->producto(2, 'Caja de pistacho', 216, 50);

        $this->artisan('exportacion:unificar-catalogo', ['--aplicar' => true])
            ->expectsOutputToContain('no coincide con el plan')
            ->expectsOutputToContain('#2 «Caja de pistacho» no contiene «mani dulce»')
            ->assertFailed();

        $this->assertSame(0, DB::table('exportacion_productos_base')->count());
        $this->assertSame('Caja de pistacho', DB::table('exportacion_productos')->where('id', 2)->value('nombre_es'));
        $this->assertNull(DB::table('exportacion_productos')->where('id', 1)->value('exportacion_producto_base_id'));
    }

    public function test_una_presentacion_activa_fuera_del_plan_bloquea_la_unificacion(): void
    {
        $this->producto(900, 'Producto que nadie aprobó', 144, 85);

        $this->artisan('exportacion:unificar-catalogo')
            ->expectsOutputToContain('#900 «Producto que nadie aprobó» está activo y no figura en el plan')
            ->assertFailed();
    }

    public function test_no_se_aplica_dos_veces(): void
    {
        DB::table('exportacion_productos_base')->insert([
            'nombre_es' => 'Maní dulce', 'nombre_en' => 'Sweet baked peanut', 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('exportacion:unificar-catalogo', ['--aplicar' => true])
            ->expectsOutputToContain('ya se aplicó')
            ->assertFailed();

        $this->assertSame(1, DB::table('exportacion_productos_base')->count());
    }

    private function producto(int $id, string $nombre, int $unidades, float $gramos): void
    {
        DB::table('exportacion_productos')->insert([
            'id' => $id,
            'nombre_es' => $nombre,
            'nombre_en' => $nombre,
            'unidad' => 'Bolsa de polipropileno 12x12',
            'unidades_por_caja' => $unidades,
            'gramos_por_unidad' => $gramos,
            'onzas_por_unidad' => round($gramos * 0.035274, 2),
            'precio_caja' => 100,
            'peso_neto_caja_kg' => 10,
            'peso_bruto_caja_kg' => 11,
            'peso_neto_caja_lb' => 22.05,
            'peso_bruto_caja_lb' => 24.25,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
