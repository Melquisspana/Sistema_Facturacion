<?php

namespace Tests\Feature;

use App\Enums\RolSistema;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk()
            ->assertSee('Para cambiar tu correo, escribí tu contraseña actual.')
            ->assertSee('name="current_password"', false)
            ->assertDontSee('Delete Account')
            ->assertDontSee('confirm-user-deletion')
            ->assertDontSee('name="_method" value="delete"', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertArrayNotHasKey('current_password', $user->getAttributes());
    }

    public function test_guardar_sin_cambiar_el_correo_no_pide_contrasena(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame('Test User', $user->refresh()->name);
    }

    public function test_el_borrado_del_perfil_no_existe_para_invitados(): void
    {
        $this->delete('/profile')->assertStatus(405);
    }

    public function test_ningun_rol_puede_borrar_su_cuenta_desde_el_perfil(): void
    {
        $this->seed(RolesSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertStatus(405);
        foreach (RolSistema::cases() as $rol) {
            $user->syncRoles([$rol->value]);
            $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertStatus(405);
            $this->assertNotNull($user->fresh());
        }
    }

    /** @return array<string, array{?string}> */
    public static function contrasenasInvalidas(): array
    {
        return ['sin contraseña' => [null], 'contraseña incorrecta' => ['incorrecta']];
    }

    #[DataProvider('contrasenasInvalidas')]
    public function test_cambiar_el_correo_exige_la_contrasena_correcta(?string $contrasena): void
    {
        $user = User::factory()->create();
        $correo = $user->email;

        $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => 'Cuenta de prueba',
            'email' => 'nuevo@example.test',
            'current_password' => $contrasena,
        ])->assertRedirect('/profile')->assertSessionHasErrors('current_password');

        $this->assertSame($correo, $user->fresh()->email);
        $this->assertFalse(session()->hasOldInput('current_password'));
    }

    public function test_las_direcciones_retiradas_de_verificacion_no_existen(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->post('/email/verification-notification')->assertNotFound();
    }
}
