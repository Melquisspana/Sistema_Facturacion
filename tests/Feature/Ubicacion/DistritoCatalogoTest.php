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

    public function test_codigo_mh_queda_pendiente(): void
    {
        // El código MH del distrito se completa con el catálogo oficial: por ahora NULL.
        $this->assertSame(0, Distrito::whereNotNull('codigo')->count());
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
