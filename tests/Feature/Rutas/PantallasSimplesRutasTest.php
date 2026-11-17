<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\AvisoRutas;
use App\Services\Rutas\RitmoRutas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * El módulo en tres pantallas (27/09/2026): Rutas, la hoja de la salida y Configurar
 * rutas; más el aviso en el panel principal.
 */
class PantallasSimplesRutasTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function rutaAtrasada(): Ruta
    {
        $ruta = Ruta::create(['nombre' => 'Puerto', 'frecuencia_objetivo_dias' => 10]);
        SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => now()->subDays(14)->toDateString(),
            'fecha_fin_real' => now()->subDays(13)->toDateString(),
            'estado' => EstadoSalidaRuta::Finalizada,
        ]);

        return $ruta;
    }

    // ══════════════════════════════ panel principal

    public function test_el_panel_principal_avisa_las_rutas_que_toca_visitar(): void
    {
        $this->rutaAtrasada();
        Ruta::create(['nombre' => 'Sonsonate', 'frecuencia_objetivo_dias' => 30]); // sin salidas: no avisa

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rutas por visitar')
            ->assertSee('3 días tarde')
            ->assertDontSee('Sonsonate');
    }

    public function test_quien_no_ve_rutas_no_recibe_el_aviso(): void
    {
        $this->rutaAtrasada();

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Rutas por visitar');
    }

    /** Sin las tablas del módulo el panel principal sigue funcionando, sin aviso. */
    public function test_sin_las_tablas_de_rutas_el_aviso_queda_vacio(): void
    {
        $this->rutaAtrasada();
        Schema::drop('salida_ruta_entregas');

        $this->assertSame([], app(AvisoRutas::class)->para($this->usuario()));
        $this->actingAs($this->usuario())->get(route('dashboard'))->assertOk();
    }

    /**
     * El aviso recorre todas las salas (16/11/2026, prueba de carga: unos 170 ms de cada
     * visita al Inicio): se guarda 5 minutos y la visita siguiente no lo vuelve a calcular.
     */
    public function test_el_aviso_se_guarda_cinco_minutos_y_no_se_recalcula_en_cada_visita(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        $ruta = $this->rutaAtrasada();
        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk()->assertSee('3 días tarde');

        // Hoy volvieron de esa ruta: ya no toca, pero lo guardado sigue valiendo un rato.
        SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => '2026-11-16', 'fecha_fin_real' => '2026-11-16', 'estado' => EstadoSalidaRuta::Finalizada]);
        $this->travel(4)->minutes();
        $this->get(route('dashboard'))->assertOk()->assertSee('3 días tarde');

        $this->travel(2)->minutes();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Rutas por visitar');
    }

    /** Lo guardado es uno para todos los que ven Rutas: quien no las ve nunca lo recibe. */
    public function test_lo_guardado_no_llega_a_quien_no_ve_rutas(): void
    {
        $this->rutaAtrasada();

        // Primero entra quien no ve Rutas: a él no se le calcula ni deja un aviso vacío guardado.
        $this->actingAs($this->usuario('contabilidad'))->get(route('dashboard'))->assertOk()->assertDontSee('Rutas por visitar');
        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk()->assertSee('3 días tarde');
        // Con el aviso ya guardado, sigue sin verlo.
        $this->actingAs($this->usuario('contabilidad'))->get(route('dashboard'))->assertOk()->assertDontSee('Rutas por visitar');
    }

    public function test_si_el_aviso_falla_el_inicio_carga_igual_y_la_falla_no_se_guarda(): void
    {
        $this->rutaAtrasada();
        Log::spy();
        $this->mock(RitmoRutas::class)->shouldReceive('porRuta')->andThrow(new RuntimeException('falla inventada del aviso'));

        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk()->assertDontSee('Rutas por visitar');
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => str_contains($mensaje, 'aviso de rutas')
            && ($contexto['error'] ?? null) === 'falla inventada del aviso')->once();

        // La visita siguiente vuelve a intentar: no quedó guardado un aviso vacío.
        $this->forgetMock(RitmoRutas::class);
        $this->get(route('dashboard'))->assertOk()->assertSee('3 días tarde');
    }

    // ══════════════════════════════ salir a una ruta

    // ══════════════════════════════ configurar rutas

    public function test_configurar_rutas_reune_rutas_y_sugerencias(): void
    {
        $departamento = Departamento::create(['codigo' => '05', 'nombre' => 'La Libertad']);
        $distrito = Distrito::create(['departamento_id' => $departamento->id, 'municipio' => 'La Libertad Costa', 'nombre' => 'La Libertad']);
        $ruta = Ruta::create(['nombre' => 'Puerto', 'frecuencia_objetivo_dias' => 12]);
        $ruta->coberturas()->create(['distrito_id' => $distrito->id]);
        Cliente::factory()->create()->sucursales()->create([
            'nombre' => 'Súper Selectos Puerto', 'departamento_id' => $departamento->id, 'distrito_id' => $distrito->id,
        ]);
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('rutas.rutas.index'))
            ->assertOk()
            ->assertSee('Zonas')
            ->assertSee('Puerto')
            ->assertSee('La Libertad')
            // Ya no se asignan salas ni se cargan días: solo nombre y cobertura.
            ->assertDontSee('Asignar 1 sala sugerida')
            ->assertDontSee('Agregar salas por nombre')
            ->assertDontSee('frecuencia_objetivo_dias');
    }

    public function test_desde_configurar_rutas_se_crea_una_ruta_sin_salir_de_la_pagina(): void
    {
        $usuario = $this->usuario('facturacion');

        $this->actingAs($usuario)
            ->from(route('rutas.rutas.index'))
            ->post(route('rutas.rutas.store'), ['nombre' => 'San Marcos', 'frecuencia_objetivo_dias' => 12, 'activa' => 1, 'en_linea' => 1])
            ->assertRedirect();

        $zona = Ruta::where('nombre', 'San Marcos')->sole();
        $this->assertNull($zona->frecuencia_objetivo_dias, 'Los días aproximados ya no se cargan.');
        $this->assertTrue($zona->activa);
        // Guardar el nombre desde la tarjeta no la desactiva.
        $this->actingAs($usuario)->put(route('rutas.rutas.update', $zona), ['nombre' => 'San Marcos Sur', 'activa' => 1, 'en_linea' => 1])
            ->assertRedirect(route('rutas.rutas.index').'#ruta-'.$zona->id);
        $this->assertSame(['San Marcos Sur', true], [$zona->refresh()->nombre, $zona->activa]);
    }

    public function test_el_personal_tiene_puerta_propia_y_ya_no_vive_en_configurar_rutas(): void
    {
        PersonalRuta::create(['nombre' => 'José', 'activo' => true]);
        // Los empleados son solo de la administración (04/11/2026).
        $usuario = $this->usuario('administrador');

        // Su lista es la de Personal (08/11/2026): se llega por el área Personal.
        $this->actingAs($usuario)->get(route('rutas.rutas.index'))
            ->assertOk()
            ->assertSee(route('personal.empleados'), false)
            ->assertDontSee('Todavía no hay vendedores')
            ->assertDontSee('Nombre del vendedor');

        // La lista es la de Personal: quien no tiene ficha sale en su aviso.
        $this->actingAs($usuario)->followingRedirects()->get(route('rutas.personal.index'))
            ->assertOk()
            ->assertSee('José');

        // Sin rutas.personal.ver la puerta no se dibuja; sin planilla.ver, tampoco.
        $this->actingAs($this->usuario('contabilidad'))->get(route('dashboard'))
            ->assertDontSee(route('rutas.personal.index'), false);
        $this->actingAs($this->usuario('facturacion'))->get(route('rutas.rutas.index'))->assertOk()
            ->assertDontSee(route('rutas.personal.index'), false);
    }
}
