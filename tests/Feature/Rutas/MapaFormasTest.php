<?php

namespace Tests\Feature\Rutas;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Services\Rutas\MapaVentas;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las formas del mapa de ventas (public/geo, ver public/geo/FUENTES.txt) calzan con el
 * catálogo de ubicaciones que siembra el sistema: cada distrito tiene su forma y cada forma
 * es de un distrito, con la clave «departamento-distrito» que usa {@see MapaVentas}.
 */
class MapaFormasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Distritos del catálogo que pueden quedar sin forma, con el motivo. Hoy ninguno: los
     * 262 calzan. Si el catálogo suma uno sin forma, se agrega aquí con su motivo.
     *
     * @var array<string, string>
     */
    private const SIN_FORMA = [];

    /** @return array<int, array{type: string, properties: array, geometry: array}> */
    private function formas(string $archivo): array
    {
        $geo = json_decode((string) file_get_contents(public_path('geo/'.$archivo)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FeatureCollection', $geo['type']);

        return $geo['features'];
    }

    public function test_cada_distrito_del_catalogo_tiene_su_forma_y_ninguna_sobra(): void
    {
        $this->seed(CatalogosMhSeeder::class);

        $catalogo = Distrito::query()
            ->join('departamentos', 'departamentos.id', '=', 'distritos.departamento_id')
            ->get(['distritos.codigo', 'distritos.nombre', 'departamentos.codigo as departamento']);
        $claves = $catalogo->map(fn ($d) => MapaVentas::clave($d->departamento, $d->codigo));
        $this->assertNotContains(null, $claves->all(), 'Todo distrito sembrado lleva su código CAT-008.');
        $this->assertCount(262, $claves);

        $formas = collect($this->formas('distritos.json'));
        $codigos = $formas->pluck('properties.c');
        $this->assertSame($codigos->count(), $codigos->unique()->count(), 'Un distrito tiene una sola forma (las islas van en la misma).');

        $this->assertSame(array_keys(self::SIN_FORMA), $claves->diff($codigos)->values()->all(), 'Distritos del catálogo sin forma.');
        $this->assertSame([], $codigos->diff($claves)->values()->all(), 'Formas que no son de ningún distrito del catálogo.');

        // El nombre y el departamento de cada forma son los del catálogo.
        $porClave = $catalogo->keyBy(fn ($d) => MapaVentas::clave($d->departamento, $d->codigo));
        foreach ($formas as $forma) {
            $distrito = $porClave[$forma['properties']['c']];
            $this->assertSame($distrito->nombre, $forma['properties']['n']);
            $this->assertSame($distrito->departamento, $forma['properties']['d']);
            $this->assertContains($forma['geometry']['type'], ['Polygon', 'MultiPolygon']);
        }
    }

    public function test_los_14_departamentos_tienen_su_forma(): void
    {
        $this->seed(CatalogosMhSeeder::class);

        $formas = collect($this->formas('departamentos.json'));

        $this->assertEqualsCanonicalizing(Departamento::pluck('codigo')->all(), $formas->pluck('properties.c')->all());
        $this->assertSame(Departamento::pluck('nombre', 'codigo')->sortKeys()->all(), $formas->pluck('properties.n', 'properties.c')->sortKeys()->all());
    }
}
