<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Las tres rutas que cambian o confirman la contraseña, miradas por su efecto: si la
 * contraseña quedó cambiada o no, si la sesión quedó confirmada o no, y que nadie pueda
 * probar contraseñas sin límite estando adentro (como ya pasa en el login).
 */
class PasswordIntentosTest extends TestCase
{
    use RefreshDatabase;

    private const NUEVA = 'Nueva#Clave2026';

    /** Pide el enlace de restablecer y devuelve el token que salió en el correo. */
    private function tokenDe(User $user): string
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    private function restablecer(string $token, string $email)
    {
        return $this->post('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => self::NUEVA,
            'password_confirmation' => self::NUEVA,
        ]);
    }

    // ---------------------------------------------------------------------
    // password.store (restablecer con el enlace del correo)
    // ---------------------------------------------------------------------

    public function test_con_un_token_valido_la_contrasena_queda_cambiada(): void
    {
        $user = User::factory()->create();

        $this->restablecer($this->tokenDe($user), $user->email)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::NUEVA, $user->refresh()->password));
    }

    public function test_con_un_token_vencido_da_error_y_no_cambia(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenDe($user);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->restablecer($token, $user->email)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_con_el_token_de_otro_usuario_da_error_y_no_cambia_ninguna(): void
    {
        $duenio = User::factory()->create();
        $otro = User::factory()->create();
        $token = $this->tokenDe($duenio);

        $this->restablecer($token, $otro->email)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $otro->refresh()->password));
        $this->assertTrue(Hash::check('password', $duenio->refresh()->password));
    }

    // ---------------------------------------------------------------------
    // confirm-password
    // ---------------------------------------------------------------------

    public function test_confirmar_con_la_contrasena_correcta_deja_la_sesion_confirmada(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_confirmar_con_la_contrasena_incorrecta_no_deja_la_sesion_confirmada(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'otra-cosa'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_confirmar_tiene_limite_de_intentos_por_usuario(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->post('/confirm-password', ['password' => 'probando-'.$i])
                ->assertSessionHasErrors('password');
        }

        // El séptimo intento del minuto no se procesa, aunque traiga la contraseña buena.
        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertStatus(429)
            ->assertSessionMissing('auth.password_confirmed_at');

        // El límite es de esa cuenta: otra persona sigue pudiendo confirmar.
        $this->actingAs(User::factory()->create())
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHas('auth.password_confirmed_at');
    }

    // ---------------------------------------------------------------------
    // PUT password (cambiar la contraseña estando adentro)
    // ---------------------------------------------------------------------

    private function cambiar(User $user, string $actual)
    {
        return $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => $actual,
                'password' => self::NUEVA,
                'password_confirmation' => self::NUEVA,
            ]);
    }

    public function test_con_la_contrasena_actual_incorrecta_no_cambia(): void
    {
        $user = User::factory()->create();

        $this->cambiar($user, 'otra-cosa')->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_cambiar_la_contrasena_tiene_limite_de_intentos(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->cambiar($user, 'probando-'.$i)->assertSessionHasErrorsIn('updatePassword', 'current_password');
        }

        // El séptimo intento del minuto no se procesa, aunque traiga la contraseña buena.
        $this->cambiar($user, 'password')->assertStatus(429);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
