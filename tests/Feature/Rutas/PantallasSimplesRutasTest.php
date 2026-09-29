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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    // ══════════════════════════════ salir a una ruta

    public function test_salir_a_una_ruta_desde_el_tablero_la_deja_en_camino(): void
    {
        $ruta = $this->rutaAtrasada();
        $carlos = PersonalRuta::create(['nombre' => 'Carlos']);

        $this->actingAs($this->usuario('jefatura'))
            ->post(route('rutas.salidas.store'), ['ruta_id' => $ruta->id, 'personal' => [(string) $carlos->id]])
            ->assertSessionHasNoErrors();

        $salida = SalidaRuta::latest('id')->first();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->estado);
        $this->assertTrue($salida->fecha_inicio->isToday());

        $this->actingAs($this->usuario())
            ->get(route('rutas.dashboard'))
            ->assertSee('En camino: Puerto')
            ->assertSee('Abrir hoja');
    }

    // ══════════════════════════════ configurar rutas

    public function test_configurar_rutas_reune_rutas_sugerencias_y_vendedores(): void
    {
        $departamento = Departamento::create(['codigo' => '05', 'nombre' => 'La Libertad']);
        $distrito = Distrito::create(['departamento_id' => $departamento->id, 'municipio' => 'La Libertad Costa', 'nombre' => 'La Libertad']);
        $ruta = Ruta::create(['nombre' => 'Puerto', 'frecuencia_objetivo_dias' => 12]);
        $ruta->coberturas()->create(['distrito_id' => $distrito->id]);
        Cliente::factory()->create()->sucursales()->create([
            'nombre' => 'Súper Selectos Puerto', 'departamento_id' => $departamento->id, 'distrito_id' => $distrito->id,
        ]);
        PersonalRuta::create(['nombre' => 'Carlos', 'telefono' => '7777-0000']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('rutas.rutas.index'))
            ->assertOk()
            ->assertSee('Puerto')
            ->assertSee('Asignar 1 sala sugerida')
            ->assertSee('Vendedores')
            ->assertSee('Carlos');
    }

    public function test_desde_configurar_rutas_se_crea_ruta_y_vendedor_sin_salir_de_la_pagina(): void
    {
        $usuario = $this->usuario('facturacion');

        $this->actingAs($usuario)
            ->from(route('rutas.rutas.index'))
            ->post(route('rutas.rutas.store'), ['nombre' => 'San Marcos', 'frecuencia_objetivo_dias' => 12, 'en_linea' => 1])
            ->assertRedirect(route('rutas.rutas.index'));

        $this->actingAs($usuario)
            ->from(route('rutas.rutas.index'))
            ->post(route('rutas.personal.store'), ['nombre' => 'José', 'funciones' => ['vendedor'], 'en_linea' => 1])
            ->assertRedirect(route('rutas.rutas.index'));

        $this->assertSame(12, Ruta::where('nombre', 'San Marcos')->sole()->frecuencia_objetivo_dias);
        $this->assertTrue(PersonalRuta::where('nombre', 'José')->sole()->activo);
    }
}
