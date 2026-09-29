<?php

namespace Tests\Feature\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Traslado de rutas entre servidores: se arman en una copia, se exportan y se cargan en
 * producción. La importación es conservadora: por defecto solo muestra, y nunca asigna
 * una sala que no sea con certeza la misma.
 */
class ExportarImportarRutasTest extends TestCase
{
    use RefreshDatabase;

    private string $archivo;

    private ClienteSucursal $faro;

    private Distrito $laLibertad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivo = sys_get_temp_dir().'/rutas-prueba-'.uniqid().'.json';

        $departamento = Departamento::create(['codigo' => '05', 'nombre' => 'La Libertad']);
        $this->laLibertad = Distrito::create(['departamento_id' => $departamento->id, 'municipio' => 'La Libertad Costa', 'nombre' => 'La Libertad']);
        Departamento::create(['codigo' => '12', 'nombre' => 'San Miguel']);
        $this->faro = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Súper Selectos El Faro', 'codigo' => '0212']);
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);
        parent::tearDown();
    }

    /** Arma una ruta, la exporta y deja la base como un servidor sin rutas. */
    private function exportarYVaciar(): void
    {
        $puerto = Ruta::create(['nombre' => 'Puerto', 'frecuencia_objetivo_dias' => 15]);
        $puerto->coberturas()->create(['distrito_id' => $this->laLibertad->id]);
        $sanMiguel = Ruta::create(['nombre' => 'San Miguel', 'frecuencia_objetivo_dias' => 30]);
        $sanMiguel->coberturas()->create(['departamento_id' => Departamento::where('nombre', 'San Miguel')->value('id')]);
        $this->faro->update(['ruta_id' => $puerto->id]);
        PersonalRuta::create(['nombre' => 'Carlos', 'telefono' => '7777-0000'])->funciones()->create(['funcion' => 'vendedor']);

        $this->artisan('rutas:exportar', ['archivo' => $this->archivo])->assertSuccessful();

        ClienteSucursal::query()->update(['ruta_id' => null]);
        RutaCobertura::query()->delete();
        Ruta::query()->delete();
        PersonalRuta::query()->delete();
    }

    public function test_sin_aplicar_solo_muestra_y_no_escribe_nada(): void
    {
        $this->exportarYVaciar();

        $this->artisan('rutas:importar', ['archivo' => $this->archivo])
            ->expectsOutputToContain('Simulación: no se escribió nada')
            ->assertSuccessful();

        $this->assertSame(0, Ruta::count());
        $this->assertSame(0, RutaCobertura::count());
        $this->assertNull($this->faro->refresh()->ruta_id);
        $this->assertSame(0, PersonalRuta::count());
    }

    public function test_con_aplicar_deja_las_rutas_como_estaban(): void
    {
        $this->exportarYVaciar();

        $this->artisan('rutas:importar', ['archivo' => $this->archivo, '--aplicar' => true])
            ->expectsOutputToContain('Rutas importadas.')
            ->assertSuccessful();

        $puerto = Ruta::where('nombre', 'Puerto')->sole();
        $this->assertSame(15, $puerto->frecuencia_objetivo_dias);
        $this->assertSame($this->laLibertad->id, $puerto->coberturas()->sole()->distrito_id);
        $this->assertSame($puerto->id, $this->faro->refresh()->ruta_id);
        $this->assertSame(30, Ruta::where('nombre', 'San Miguel')->sole()->frecuencia_objetivo_dias);
        $this->assertTrue(PersonalRuta::where('nombre', 'Carlos')->sole()->tieneFuncion(FuncionPersonalRuta::Vendedor));

        // Importar dos veces no duplica nada.
        $this->artisan('rutas:importar', ['archivo' => $this->archivo, '--aplicar' => true])->assertSuccessful();
        $this->assertSame(2, Ruta::count());
        $this->assertSame(2, RutaCobertura::count());
        $this->assertSame(1, PersonalRuta::count());
    }

    /** Si el id apunta a otra sala en el servidor destino, no se asigna a ciegas. */
    public function test_una_sala_cuyo_nombre_no_coincide_no_se_asigna(): void
    {
        $this->exportarYVaciar();
        $this->faro->update(['nombre' => 'Otra sala con el mismo id']);

        $this->artisan('rutas:importar', ['archivo' => $this->archivo, '--aplicar' => true])
            ->expectsOutputToContain('no se encontró la sala «Súper Selectos El Faro»')
            ->assertSuccessful();

        $this->assertNull($this->faro->refresh()->ruta_id);
    }

    public function test_un_lugar_que_ya_cubre_otra_ruta_no_se_le_quita(): void
    {
        $this->exportarYVaciar();
        Ruta::create(['nombre' => 'Costa'])->coberturas()->create(['distrito_id' => $this->laLibertad->id]);

        $this->artisan('rutas:importar', ['archivo' => $this->archivo, '--aplicar' => true])
            ->expectsOutputToContain('ya lo cubre «Costa»')
            ->assertSuccessful();

        $this->assertSame('Costa', RutaCobertura::where('distrito_id', $this->laLibertad->id)->sole()->ruta->nombre);
    }

    public function test_un_archivo_que_no_es_una_exportacion_se_rechaza(): void
    {
        file_put_contents($this->archivo, '{"otra": "cosa"}');

        $this->artisan('rutas:importar', ['archivo' => $this->archivo])->assertFailed();
    }
}
