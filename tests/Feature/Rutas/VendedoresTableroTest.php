<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\RolSistema;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La sección Vendedores del tablero de Rutas (sección 1 de docs/PLAN_TABLERO_RUTAS.md):
 * teléfono, salida de ahora o la última, sus rutas de los últimos 90 días y «Ver ficha».
 * El jefe va a la ficha de campo y nunca a Planilla.
 */
class VendedoresTableroTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private PersonalRuta $luis;

    private PersonalRuta $rosa;

    private Ruta $norte;

    private Ruta $oriente;

    private Ruta $costa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['planilla.enabled' => true]);
        $this->travelTo('2026-11-03 09:00:00');

        $this->jefe = app(CrearUsuario::class)->crear([
            'name' => 'Jefe Inventado', 'email' => 'jefe@example.test', 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => RolSistema::JefeVendedores->value,
        ]);
        $this->norte = Ruta::create(['nombre' => 'Ruta Norte']);
        $this->oriente = Ruta::create(['nombre' => 'Ruta Oriente']);
        $this->costa = Ruta::create(['nombre' => 'Ruta Costa']);
        $this->luis = PersonalRuta::create(['nombre' => 'Luis Inventado', 'telefono' => '7000-0000']);
        $this->rosa = PersonalRuta::create(['nombre' => 'Rosa Ficticia', 'telefono' => '7000-0001']);
    }

    private function salida(Ruta $ruta, PersonalRuta $persona, string $inicio, EstadoSalidaRuta $estado = EstadoSalidaRuta::Finalizada, ?string $regreso = null): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => $inicio, 'fecha_fin_real' => $regreso, 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

        return $salida;
    }

    /** Ficha de Personal (Planilla) enlazada a la persona de Rutas. */
    private function fichaDePersonal(PersonalRuta $persona): PlanillaEmpleado
    {
        return PlanillaEmpleado::create([
            'clave' => 'ruta-'.$persona->id,
            'registrado_por' => $this->jefe->id,
            'nombre' => $persona->nombre, 'personal_ruta_id' => $persona->id,
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-'.$persona->id,
            'con_descuentos_ley' => false, 'activo' => true,
        ]);
    }

    public function test_cada_vendedor_con_telefono_salida_y_sus_rutas(): void
    {
        // Luis: va ahora a Norte; en 90 días fue dos veces a Oriente y una a Norte.
        $this->salida($this->oriente, $this->luis, '2026-10-01', EstadoSalidaRuta::Finalizada, '2026-10-02');
        $this->salida($this->oriente, $this->luis, '2026-10-15', EstadoSalidaRuta::Finalizada, '2026-10-16');
        $this->salida($this->norte, $this->luis, '2026-11-03', EstadoSalidaRuta::EnCurso);
        // Rosa: su última salida terminó el 28/10; la de hace más de 90 días no cuenta.
        $this->salida($this->costa, $this->rosa, '2026-10-27', EstadoSalidaRuta::Finalizada, '2026-10-28');
        $this->salida($this->norte, $this->rosa, '2026-07-01', EstadoSalidaRuta::Finalizada, '2026-07-02');
        // Las canceladas no cuentan.
        $this->salida($this->norte, $this->rosa, '2026-10-30', EstadoSalidaRuta::Cancelada);

        $respuesta = $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk();

        $respuesta->assertSeeInOrder(['Vendedores', 'Jefe Inventado', 'Luis Inventado', 'Rosa Ficticia']);
        $respuesta->assertSeeInOrder(['Luis Inventado', 'href="tel:70000000"', 'Tel. 7000-0000', 'Ahora: Ruta Norte, desde hoy', 'Sus rutas: Ruta Oriente, Ruta Norte'], false);
        $respuesta->assertSeeInOrder(['Rosa Ficticia', 'href="tel:70000001"', 'Última: Ruta Costa, 28/10/2026', 'Sus rutas: Ruta Costa', 'Ver ficha'], false);
    }

    public function test_vendedor_sin_telefono_ni_salidas(): void
    {
        $sinNada = PersonalRuta::create(['nombre' => 'Zoe Ejemplo']);

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Zoe Ejemplo', 'href="'.route('rutas.personal.show', $sinNada).'"', 'Sin teléfono', 'Sin salidas en los últimos 90 días', 'Ver ficha'], false);
    }

    public function test_el_jefe_va_a_la_ficha_de_campo_y_nunca_ve_planilla(): void
    {
        $ficha = $this->fichaDePersonal($this->luis);

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('rutas.personal.show', $this->luis).'"', false)
            ->assertDontSee(route('personal.empleados.show', 'p'.$ficha->id))
            ->assertDontSee('/personal/empleados');

        // La ficha de campo abre, sin enlace a Personal; la de Personal le da 403.
        $this->actingAs($this->jefe)->get(route('rutas.personal.show', $this->luis))
            ->assertOk()
            ->assertSee('7000-0000')
            ->assertDontSee('Ficha en Personal')
            ->assertDontSee('/personal/empleados');
        $this->actingAs($this->jefe)->get(route('personal.empleados.show', 'p'.$ficha->id))->assertForbidden();
    }

    public function test_el_administrador_va_a_la_ficha_de_personal(): void
    {
        $ficha = $this->fichaDePersonal($this->luis);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->actingAs($admin)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Luis Inventado', 'href="'.route('personal.empleados.show', 'p'.$ficha->id).'"', 'Ver ficha'], false)
            // Rosa no está en Personal: va a su ficha de campo.
            ->assertSeeInOrder(['Rosa Ficticia', 'href="'.route('rutas.personal.show', $this->rosa).'"', 'Ver ficha'], false);
    }
}
