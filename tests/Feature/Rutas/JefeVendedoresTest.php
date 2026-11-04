<?php

namespace Tests\Feature\Rutas;

use App\Enums\AreaSistema;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FuncionPersonalRuta;
use App\Enums\RolSistema;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\FichaVendedor;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El rol «Jefe de vendedores» (sección 3 de docs/PLAN_TABLERO_RUTAS.md): ve todas las
 * rutas, usa «Mi salida» solo en las salidas donde va y no entra a nada más.
 */
class JefeVendedoresTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private PersonalRuta $ficha;

    private Ruta $ruta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-03 09:00:00');
        $this->jefe = app(CrearUsuario::class)->crear([
            'name' => 'Luis Inventado', 'email' => 'jefe@example.test', 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => RolSistema::JefeVendedores->value,
        ]);
        $this->ficha = PersonalRuta::where('user_id', $this->jefe->id)->firstOrFail();
        $this->ruta = Ruta::create(['nombre' => 'Oriente', 'frecuencia_objetivo_dias' => 7]);
    }

    private function salida(PersonalRuta $persona, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

        return $salida;
    }

    public function test_el_rol_tiene_solo_los_permisos_de_rutas_que_le_tocan(): void
    {
        $this->assertSame('Jefe de vendedores', RolSistema::JefeVendedores->label());
        $this->assertSame(['rutas.ver', 'rutas.personal.ver', 'rutas.entregas.registrar', 'rutas.salidas.armar', 'rutas.salidas.terminar'], RolSistema::JefeVendedores->permisos());

        foreach (['rutas.gestionar', 'rutas.personal.gestionar', 'rutas.ventas.ver', 'dte.ver', 'ppq.ver', 'planilla.ver', 'planilla.salarios', 'asistencia.ver', 'gastos.ver', 'dashboard.ver'] as $permiso) {
            $this->assertFalse($this->jefe->can($permiso), "El jefe no debería tener {$permiso}.");
        }
    }

    public function test_aparece_en_el_formulario_de_accesos(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->get(route('personal.accesos.create'))
            ->assertOk()
            ->assertSee('<option value="jefe_vendedores"', false)
            ->assertSee('Jefe de vendedores');
    }

    public function test_tiene_su_ficha_de_campo_como_el_vendedor(): void
    {
        $this->assertTrue($this->ficha->activo);
        $this->assertSame('Luis Inventado', $this->ficha->nombre);
        $this->assertTrue($this->ficha->funciones()->where('funcion', FuncionPersonalRuta::Vendedor->value)->exists());

        // Si deja de ser jefe, la ficha se desactiva y se conserva.
        $this->jefe->syncRoles([RolSistema::Facturacion->value]);
        app(FichaVendedor::class)->sincronizar($this->jefe);
        $this->assertFalse($this->ficha->refresh()->activo);
    }

    public function test_al_entrar_va_al_tablero_y_no_a_mi_salida(): void
    {
        $this->assertSame('rutas.dashboard', AreaSistema::principalPara($this->jefe)?->rutaInicioPara($this->jefe));

        $this->actingAs($this->jefe)->get('/dashboard')->assertRedirect(route('rutas.dashboard'));
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('Oriente')
            ->assertDontSee(route('rutas.mi-salida.index'));

        // Cuando va en una salida en curso, el tablero le ofrece «Mi salida».
        $this->salida($this->ficha);
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertSee(route('rutas.mi-salida.index'));
    }

    public function test_usa_mi_salida_solo_en_las_salidas_donde_va(): void
    {
        $suya = $this->salida($this->ficha);
        $ajena = $this->salida(PersonalRuta::create(['nombre' => 'Rosa Ficticia']));

        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $suya));
        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.show', $suya))->assertOk();
        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.cierre', $suya))->assertOk();

        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.show', $ajena))->assertForbidden();
        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.cierre', $ajena))->assertForbidden();
        $this->actingAs($this->jefe)->get(route('rutas.mi-salida.terminar', $ajena))->assertForbidden();
        $this->actingAs($this->jefe)
            ->post(route('rutas.mi-salida.gastos.store', $ajena), ['fecha' => now()->toDateString(), 'categoria' => 'comida', 'monto' => '5.00'])
            ->assertForbidden();
    }

    public function test_recibe_403_fuera_de_rutas(): void
    {
        config(['planilla.enabled' => true, 'gastos.enabled' => true, 'asistencia.enabled' => true]);
        $salida = $this->salida(PersonalRuta::create(['nombre' => 'Rosa Ficticia']));

        $prohibidas = [
            'Facturación' => route('facturacion.index'),
            'Cobros' => route('cobros.index'),
            'Planilla' => route('planilla.index'),
            'Personal' => route('personal.empleados'),
            'Gastos' => route('gastos.panel'),
            'Alta de personas' => route('rutas.personal.create'),
        ];

        foreach ($prohibidas as $que => $url) {
            $this->actingAs($this->jefe)->get($url)->assertForbidden();
        }

        $this->actingAs($this->jefe)
            ->post(route('rutas.salidas.gastos.store', $salida), ['fecha' => now()->toDateString(), 'categoria' => 'comida', 'monto' => '5.00'])
            ->assertForbidden();
        // Termina días (JefeTerminaSalidaTest), pero no los reabre.
        $this->actingAs($this->jefe)
            ->post(route('rutas.salidas.reabrir', [$salida, now()->toDateString()]), ['motivo' => 'Corregir el cobro'])
            ->assertForbidden();
        // Arma salidas (ArmarSalidaTest), pero no las edita ni las cancela.
        $this->actingAs($this->jefe)->get(route('rutas.salidas.edit', $salida))->assertForbidden();
        $this->actingAs($this->jefe)->patch(route('rutas.salidas.cancelar', $salida))->assertForbidden();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);
    }

    /** Arma salidas con `rutas.salidas.armar` y las termina con `rutas.salidas.terminar`. */
    public function test_ve_salir_a_esta_ruta_y_terminar_ruta_pero_no_editar_ni_cancelar(): void
    {
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('Salir a esta ruta')
            ->assertSee('Salir hoy');

        $salida = $this->salida(PersonalRuta::create(['nombre' => 'Rosa Ficticia']));

        $this->actingAs($this->jefe)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee(route('rutas.salidas.terminar', $salida))
            ->assertSee('Terminar ruta')
            ->assertDontSee('Cambiar quiénes van')
            ->assertDontSee(route('rutas.salidas.cancelar', $salida));

        // Una planificada todavía no se termina: no ve «Salir ahora» ni «Terminar ruta».
        $planificada = $this->salida(PersonalRuta::create(['nombre' => 'Pedro Ficticio']), EstadoSalidaRuta::Planificada);
        $this->actingAs($this->jefe)->get(route('rutas.salidas.show', $planificada))
            ->assertOk()
            ->assertDontSee('Salir ahora')
            ->assertDontSee(route('rutas.salidas.terminar', $planificada));
    }
}
