<?php

namespace Tests\Feature\Personal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nadie se bloquea a sí mismo: quien edita su propia cuenta no se desactiva, no se cambia el
 * rol ni se elimina. Lo hace otro administrador, que sí puede.
 */
class AccesoPropioTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE = 'No podés quitarte tu propio acceso: pedíselo a otro administrador.';

    private User $yo;

    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();

        // Dos administradores: así no salta la regla del último administrador activo.
        $this->yo = User::factory()->create(['activo' => true, 'email' => 'yo@example.test'])->assignRole('administrador');
        $this->otro = User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    public function test_no_se_desactiva_a_si_mismo(): void
    {
        $this->actingAs($this->yo)->patch(route('personal.accesos.toggle-activo', $this->yo))
            ->assertRedirect(route('personal.accesos.show', $this->yo))
            ->assertSessionHasErrorsIn('acceso', ['activo' => self::MENSAJE]);

        $this->assertTrue($this->yo->fresh()->activo);
    }

    public function test_no_se_cambia_el_rol_pero_si_el_correo(): void
    {
        $this->actingAs($this->yo)->put(route('personal.accesos.update', $this->yo), [
            'name' => $this->yo->name, 'email' => $this->yo->email, 'rol' => 'facturacion',
        ])->assertSessionHasErrorsIn('acceso', ['rol' => self::MENSAJE]);
        $this->assertTrue($this->yo->fresh()->hasRole('administrador'));

        // Con el mismo rol, el correo sí se cambia.
        $this->actingAs($this->yo)->put(route('personal.accesos.update', $this->yo), [
            'name' => $this->yo->name, 'email' => 'yo.nuevo@example.test', 'rol' => 'administrador',
        ])->assertSessionHasNoErrors();
        $this->assertSame('yo.nuevo@example.test', $this->yo->fresh()->email);
    }

    public function test_no_se_elimina_a_si_mismo(): void
    {
        $this->actingAs($this->yo)->from(route('personal.accesos.show', $this->yo))
            ->delete(route('personal.accesos.destroy', $this->yo))
            ->assertRedirect(route('personal.accesos.show', $this->yo))
            ->assertSessionHas('error', self::MENSAJE);

        $this->assertDatabaseHas('users', ['id' => $this->yo->id]);
    }

    public function test_la_pagina_propia_bloquea_rol_desactivar_y_eliminar(): void
    {
        $this->actingAs($this->yo)->get(route('personal.accesos.show', $this->yo))->assertOk()
            ->assertSee('Es tu cuenta: tu rol y tu acceso los cambia otro administrador.')
            ->assertSee('<input type="hidden" name="rol" value="administrador">', false)
            ->assertDontSee(route('personal.accesos.toggle-activo', $this->yo), false)
            ->assertDontSee('Eliminar cuenta');

        // La de otro se ve completa.
        $this->actingAs($this->yo)->get(route('personal.accesos.show', $this->otro))->assertOk()
            ->assertSee(route('personal.accesos.toggle-activo', $this->otro), false)
            ->assertSee('Eliminar cuenta')
            ->assertDontSee('Es tu cuenta');
    }

    public function test_otro_administrador_si_puede(): void
    {
        $this->actingAs($this->otro)->put(route('personal.accesos.update', $this->yo), [
            'name' => $this->yo->name, 'email' => $this->yo->email, 'rol' => 'facturacion',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->yo->fresh()->hasRole('facturacion'));

        $this->actingAs($this->otro)->patch(route('personal.accesos.toggle-activo', $this->yo))->assertSessionHasNoErrors();
        $this->assertFalse($this->yo->fresh()->activo);

        $this->actingAs($this->otro)->delete(route('personal.accesos.destroy', $this->yo))
            ->assertRedirect(route('personal.accesos.index'));
        $this->assertDatabaseMissing('users', ['id' => $this->yo->id]);
    }
}
