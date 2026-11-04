<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En la hoja de la oficina, una salida programada para otro día no ofrece «Salir ahora»:
 * dice qué día sale, y el servidor tampoco la deja iniciar antes, como «Empezar ruta» de
 * Mi salida.
 */
class SalidaProgramadaOficinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-04 09:00:00'); // miércoles
    }

    private function salida(string $fecha): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => Ruta::create(['nombre' => 'Oriente'])->id,
            'fecha_inicio' => $fecha,
            'estado' => EstadoSalidaRuta::Planificada,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    public function test_la_de_otro_dia_dice_cuando_sale_y_no_ofrece_salir(): void
    {
        $salida = $this->salida('2026-11-06');

        $this->actingAs($this->admin())->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertSee('Sale el viernes 06/11/2026')
            ->assertDontSee('Salir ahora')
            ->assertDontSee(route('rutas.salidas.iniciar', $salida), false);
    }

    public function test_el_servidor_no_deja_iniciar_la_de_otro_dia(): void
    {
        $salida = $this->salida('2026-11-06');

        $this->actingAs($this->admin())->from(route('rutas.salidas.show', $salida))
            ->patch(route('rutas.salidas.iniciar', $salida))
            ->assertRedirect(route('rutas.salidas.show', $salida))
            ->assertSessionHas('error', 'Esta salida es para el viernes 06/11/2026. La podés empezar ese día.');

        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->fresh()->estado);

        // Llegado el día, sí sale.
        $this->travelTo('2026-11-06 07:00:00');
        $this->actingAs($this->admin())->patch(route('rutas.salidas.iniciar', $salida))->assertSessionHas('status');
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->fresh()->estado);
    }

    public function test_la_de_hoy_sigue_ofreciendo_salir(): void
    {
        $salida = $this->salida('2026-11-04');

        $this->actingAs($this->admin())->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertSee('Salir ahora')
            ->assertSee(route('rutas.salidas.iniciar', $salida), false)
            ->assertDontSee('Sale el');
    }
}
