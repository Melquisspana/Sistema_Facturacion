<?php

namespace Tests\Feature\Ubicacion;

use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Catálogo de ubicación de 3 niveles (división 2024): Departamento → Municipio → Distrito.
 */
class DistritoCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    public function test_catalogo_completo_14_44_262(): void
    {
        $this->assertSame(14, Departamento::count());
        $this->assertSame(44, Distrito::query()->select('departamento_id', 'municipio')->distinct()->get()->count());
        $this->assertSame(262, Distrito::count());
    }

    public function test_olocuilta_queda_en_la_paz_oeste(): void
    {
        $olocuilta = Distrito::where('nombre', 'Olocuilta')->with('departamento')->firstOrFail();

        $this->assertSame('La Paz', $olocuilta->departamento->nombre);
        $this->assertSame('La Paz Oeste', $olocuilta->municipio);
    }

    public function test_codigo_mh_y_vinculo_al_municipio_quedan_completos(): void
    {
        // Antes el código MH del distrito quedaba pendiente (NULL) hasta correr comandos a
        // mano. Ahora el seeder de catálogos importa el Excel oficial y completa TODO: sin
        // el código CAT-008 el JSON saldría con `distrito: ""` y Hacienda lo rechaza, así
        // que un catálogo a medias dejó de ser un estado válido.
        $this->assertSame(262, Distrito::count());
        $this->assertSame(0, Distrito::whereNull('codigo')->count(), 'Todo distrito debe tener su CAT-008.');
        $this->assertSame(0, Distrito::whereNull('municipio_codigo')->count(), 'Todo distrito debe estar vinculado a su municipio 2024.');

        // Y el caso de referencia: Ilobasco es el distrito 03 de Cabañas, en Cabañas Oeste,
        // que en el catálogo oficial vigente (2026-07-01) es el CAT-013 11, no el 10.
        $ilobasco = Distrito::where('nombre', 'Ilobasco')->firstOrFail();
        $this->assertSame('03', $ilobasco->codigo);
        $this->assertSame('11', $ilobasco->municipio_codigo);
    }

    public function test_municipios_se_agrupan_por_departamento(): void
    {
        $laPaz = Departamento::where('codigo', '08')->firstOrFail();

        $municipios = Distrito::where('departamento_id', $laPaz->id)
            ->distinct()->orderBy('municipio')->pluck('municipio')->all();

        $this->assertEqualsCanonicalizing(
            ['La Paz Centro', 'La Paz Este', 'La Paz Oeste'],
            $municipios,
        );
    }

    public function test_distritos_se_agrupan_por_municipio_y_pertenecen_al_departamento(): void
    {
        $laPaz = Departamento::where('codigo', '08')->firstOrFail();

        $distritos = Distrito::where('municipio', 'La Paz Oeste')->get();

        $this->assertTrue($distritos->contains('nombre', 'Olocuilta'));
        // Todos los distritos de un municipio pertenecen al mismo departamento.
        $this->assertTrue($distritos->every(fn (Distrito $d) => $d->departamento_id === $laPaz->id));
    }

    public function test_formulario_de_sala_entrega_los_distritos_a_la_vista(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $admin = User::factory()->create()->assignRole('administrador');

        $this->actingAs($admin)
            ->get(route('clientes.sucursales.create', $cliente))
            ->assertOk()
            ->assertSee('Olocuilta')       // distrito disponible en el cascada
            ->assertSee('La Paz Oeste')    // municipio 2024
            ->assertSee('Distrito');       // etiqueta del campo
    }
}
