<?php

namespace Tests\Feature\Rutas;

use App\Enums\RolSistema;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los datos de los empleados son solo de la administración (Melqui, 04/11/2026): jefe de
 * vendedores, Jefatura y Facturación no abren Rutas › Personal ni lo ven en el menú, pero
 * siguen eligiendo a quién va al armar una salida.
 */
class PersonalSoloAdministracionTest extends TestCase
{
    use RefreshDatabase;

    private PersonalRuta $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rosa = PersonalRuta::create(['nombre' => 'Rosa Ficticia', 'telefono' => '7000-0001']);
        $this->rosa->funciones()->create(['funcion' => 'vendedor']);
        Ruta::create(['nombre' => 'Ruta Oriente', 'frecuencia_objetivo_dias' => 7]);
    }

    private function con(RolSistema $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol->value);
    }

    /** @return array<string, array{0: RolSistema}> */
    public static function rolesSinAcceso(): array
    {
        return [
            'jefe de vendedores' => [RolSistema::JefeVendedores],
            'jefatura' => [RolSistema::Jefatura],
            'facturación' => [RolSistema::Facturacion],
        ];
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_no_abre_ni_cambia_el_personal_de_rutas(RolSistema $rol): void
    {
        $usuario = $this->con($rol);

        foreach ([route('rutas.personal.index'), route('rutas.personal.show', $this->rosa), route('rutas.personal.edit', $this->rosa), route('rutas.personal.create')] as $url) {
            $this->actingAs($usuario)->get($url)->assertForbidden();
        }
        $this->actingAs($usuario)->put(route('rutas.personal.update', $this->rosa), ['nombre' => 'Otra', 'telefono' => '1'])->assertForbidden();
        $this->actingAs($usuario)->patch(route('rutas.personal.toggle-activo', $this->rosa))->assertForbidden();

        $this->assertSame(['Rosa Ficticia', '7000-0001', true], [$this->rosa->fresh()->nombre, $this->rosa->fresh()->telefono, $this->rosa->fresh()->activo]);
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_el_menu_no_muestra_personal_pero_la_salida_sigue_eligiendo_personas(RolSistema $rol): void
    {
        $respuesta = $this->actingAs($this->con($rol))->get(route('rutas.dashboard'))->assertOk();

        $respuesta->assertDontSee('href="'.route('rutas.personal.index').'"', false);
        // Los tres arman salidas: siguen eligiendo a la persona que va.
        $respuesta->assertSee('Salir a esta ruta')->assertSee('Rosa Ficticia');
    }

    public function test_la_administracion_sigue_entrando(): void
    {
        $admin = $this->con(RolSistema::Administrador);

        // La lista de Rutas es la de Personal: quien no tiene ficha aparece en su aviso.
        $this->actingAs($admin)->get(route('rutas.personal.index'))->assertRedirect(route('personal.empleados'));
        $this->actingAs($admin)->followingRedirects()->get(route('rutas.personal.index'))->assertOk()->assertSee('Rosa Ficticia');
        $this->actingAs($admin)->get(route('rutas.personal.show', $this->rosa))->assertOk();
        $this->actingAs($admin)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('href="'.route('personal.empleados').'"', false);
    }
}
