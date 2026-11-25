<?php

namespace Tests\Feature\Alertas;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\DatosQueNoCuadran;
use App\Services\Sistema\IntegridadDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Las alertas de datos de Salud del sistema que están en rojo, por correo. Datos inventados. */
class DatosQueNoCuadranTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_advertencias_no_avisan_y_las_criticas_si(): void
    {
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        Producto::factory()->create(['activo' => true, 'codigo_barra' => null]);
        $detector = app(DatosQueNoCuadran::class);
        $this->assertTrue($detector->aplica());
        $this->assertSame('integridad_datos', $detector->tipo());
        $this->assertNull($detector->revisar(), 'Un producto sin código de barra es una advertencia.');

        Cliente::factory()->count(2)->create(['nombre' => 'Cliente Secreto', 'num_documento' => '06141111111111']);
        Cache::flush();
        $h = $detector->revisar();

        $this->assertSame('Clientes duplicados por número de documento: 1.', $h->detalle);
        $this->assertSame(1, $h->conteo);
        $this->assertSame('admin.salud-sistema', $h->enlace);
        $this->assertStringContainsString('Salud del sistema › Alertas de datos', $h->queHacer);
        $this->assertStringNotContainsString('Secreto', $h->titulo.$h->detalle.$h->queHacer);
        $this->assertStringNotContainsString('06141111111111', $h->titulo.$h->detalle.$h->queHacer);
        $this->assertContains('integridad_datos', array_map(fn ($d) => $d->tipo(), app(Avisador::class)->detectores()));
    }

    public function test_el_resultado_se_guarda_una_hora(): void
    {
        Cliente::factory()->count(2)->create(['num_documento' => '06141111111111']);
        $detector = app(DatosQueNoCuadran::class);
        $this->assertNotNull($detector->revisar());

        Cliente::query()->update(['num_documento' => null]);
        $this->assertNotNull($detector->revisar());
        $this->travel(61)->minutes();
        $this->assertNull($detector->revisar());
    }

    public function test_el_servicio_da_las_mismas_filas_que_mostraba_la_pantalla(): void
    {
        $filas = app(IntegridadDatos::class)->contar();

        $this->assertCount(11, $filas);
        $this->assertSame(['label', 'count', 'estado'], array_keys($filas[0]));
        $this->assertSame(7, collect($filas)->where('estado', 'critico')->count());
    }
}
