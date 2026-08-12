<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Catálogo de rutas: alta, edición, activación y su auditoría.
 *
 * Una ruta NO se elimina: la acción visible es desactivarla, que conserva salas e
 * historial. Por eso no hay ni ruta ni prueba de `destroy`.
 */
class RutaCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    public function test_crea_una_ruta(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.store'), [
                'nombre' => 'San Miguel',
                'frecuencia_objetivo_dias' => 15,
                'activa' => '1',
            ])
            ->assertRedirect();

        $ruta = Ruta::sole();
        $this->assertSame('San Miguel', $ruta->nombre);
        $this->assertSame(15, $ruta->frecuencia_objetivo_dias);
        $this->assertTrue($ruta->activa);
    }

    public function test_la_ruta_nace_activa_y_sin_frecuencia_si_no_se_indica(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.store'), ['nombre' => 'Lourdes', 'activa' => '1'])
            ->assertRedirect();

        $ruta = Ruta::sole();
        $this->assertNull($ruta->frecuencia_objetivo_dias);
        $this->assertTrue($ruta->activa);
    }

    public function test_no_permite_dos_rutas_con_el_mismo_nombre(): void
    {
        Ruta::create(['nombre' => 'Sonsonate']);

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.store'), ['nombre' => 'Sonsonate'])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, Ruta::count());
    }

    public function test_editar_sin_cambiar_el_nombre_no_choca_con_su_propio_indice(): void
    {
        $ruta = Ruta::create(['nombre' => 'Usulután', 'frecuencia_objetivo_dias' => 7]);

        $this->actingAs($this->admin())
            ->put(route('rutas.rutas.update', $ruta), [
                'nombre' => 'Usulután',
                'frecuencia_objetivo_dias' => 21,
                'activa' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(21, $ruta->refresh()->frecuencia_objetivo_dias);
    }

    public function test_activar_y_desactivar_la_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'Santa Ana']);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('rutas.rutas.toggle-activa', $ruta))->assertRedirect();
        $this->assertFalse($ruta->refresh()->activa);

        $this->actingAs($admin)->patch(route('rutas.rutas.toggle-activa', $ruta))->assertRedirect();
        $this->assertTrue($ruta->refresh()->activa);
    }

    public function test_desactivar_conserva_las_salas_asignadas(): void
    {
        [$ruta, $sala] = $this->rutaConSala();

        $this->actingAs($this->admin())->patch(route('rutas.rutas.toggle-activa', $ruta));

        $this->assertFalse($ruta->refresh()->activa);
        $this->assertSame($ruta->id, $sala->refresh()->ruta_id);
    }

    public function test_el_listado_muestra_cuantas_salas_tiene_cada_ruta(): void
    {
        [$ruta] = $this->rutaConSala();

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.rutas.index'));

        $respuesta->assertOk();
        $this->assertSame(1, $respuesta->viewData('rutas')->firstWhere('id', $ruta->id)->sucursales_count);
    }

    public function test_el_alta_y_la_edicion_quedan_auditadas(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('rutas.rutas.store'), ['nombre' => 'San Salvador', 'activa' => '1']);
        $ruta = Ruta::sole();

        $this->actingAs($admin)->put(route('rutas.rutas.update', $ruta), ['nombre' => 'San Salvador Centro', 'activa' => '1']);

        $eventos = Activity::where('log_name', 'ruta')->pluck('description');
        $this->assertContains('creó la ruta', $eventos);
        $this->assertContains('actualizó la ruta', $eventos);
    }

    /** @return array{0: Ruta, 1: ClienteSucursal} */
    private function rutaConSala(): array
    {
        $ruta = Ruta::create(['nombre' => 'Ruta con sala']);
        $cliente = Cliente::factory()->create();
        $sala = $cliente->sucursales()->create(['nombre' => 'Sala 1', 'codigo' => '0001', 'ruta_id' => $ruta->id]);

        return [$ruta, $sala];
    }
}
