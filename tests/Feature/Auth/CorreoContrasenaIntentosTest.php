<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cambiar el correo en el perfil pide la contraseña actual, así que es otra puerta para
 * probar contraseñas con una sesión ajena (por ejemplo, una PC que quedó abierta). Si
 * acierta, el correo pasa a ser el de quien prueba y con «olvidé mi contraseña» se queda
 * con la cuenta. Tiene el mismo límite que confirmar o cambiar la contraseña.
 */
class CorreoContrasenaIntentosTest extends TestCase
{
    use RefreshDatabase;

    private function cambiarCorreo(User $user, string $clave, string $correo = 'nuevo@ejemplo.com')
    {
        return $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $correo,
                'current_password' => $clave,
            ]);
    }

    public function test_cambiar_el_correo_tiene_limite_de_intentos_con_aviso_en_el_formulario(): void
    {
        $user = User::factory()->create();
        $correo = $user->email;

        for ($i = 0; $i < 6; $i++) {
            $this->cambiarCorreo($user, 'probando-'.$i)->assertSessionHasErrors('current_password');
        }

        // El séptimo del minuto no se procesa aunque traiga la contraseña buena: vuelve al
        // perfil con el aviso junto a la contraseña y el correo no cambia.
        $this->cambiarCorreo($user, 'password')
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('current_password');
        $this->assertMatchesRegularExpression('/^Demasiados intentos\. Probá de nuevo en \d+ segundos\.$/', session('errors')->first('current_password'));
        $this->assertSame($correo, $user->refresh()->email);

        // El límite es de esa cuenta: otra persona sí cambia el suyo.
        $otro = User::factory()->create();
        $this->cambiarCorreo($otro, 'password', 'otro-nuevo@ejemplo.com')->assertSessionHasNoErrors();
        $this->assertSame('otro-nuevo@ejemplo.com', $otro->refresh()->email);

        // Pasado el minuto, la misma persona sí lo cambia.
        $this->travel(61)->seconds();
        $this->cambiarCorreo($user, 'password')->assertSessionHasNoErrors();
        $this->assertSame('nuevo@ejemplo.com', $user->refresh()->email);
    }

    /** Solo cuenta la contraseña equivocada: con la buena y un correo ya usado, no se queda afuera. */
    public function test_con_la_contrasena_correcta_los_otros_errores_no_cuentan(): void
    {
        User::factory()->create(['email' => 'ocupado@ejemplo.com']);
        $user = User::factory()->create();

        for ($i = 0; $i < 7; $i++) {
            $this->cambiarCorreo($user, 'password', 'ocupado@ejemplo.com')
                ->assertSessionHasErrors('email')
                ->assertSessionDoesntHaveErrors('current_password');
        }

        $this->cambiarCorreo($user, 'password')->assertSessionHasNoErrors();
        $this->assertSame('nuevo@ejemplo.com', $user->refresh()->email);
    }

    public function test_un_acierto_borra_los_fallos_anteriores(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->cambiarCorreo($user, 'probando-'.$i)->assertSessionHasErrors('current_password');
        }
        $this->cambiarCorreo($user, 'password', 'primero@ejemplo.com')->assertSessionHasNoErrors();

        for ($i = 0; $i < 5; $i++) {
            $this->cambiarCorreo($user->refresh(), 'otra-'.$i)->assertSessionHasErrors('current_password');
        }
        $this->cambiarCorreo($user, 'password')->assertSessionHasNoErrors();
        $this->assertSame('nuevo@ejemplo.com', $user->refresh()->email);
    }

    /** Cambiar solo el nombre no pide la contraseña, así que tampoco cuenta. */
    public function test_cambiar_solo_el_nombre_no_cuenta_intentos(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($user)->from('/profile')
                ->patch('/profile', ['name' => 'Nombre '.$i, 'email' => $user->email])
                ->assertSessionHasNoErrors();
        }

        $this->cambiarCorreo($user->refresh(), 'password')->assertSessionHasNoErrors();
        $this->assertSame('nuevo@ejemplo.com', $user->refresh()->email);
    }
}
