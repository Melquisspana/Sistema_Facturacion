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

    /** Confirma con la sesión limpia: así un acierto anterior no tapa lo que pasó en este. */
    private function confirmar(User $user, string $clave)
    {
        $this->flushSession();

        return $this->actingAs($user)
            ->from('/confirm-password')
            ->post('/confirm-password', ['password' => $clave]);
    }

    public function test_confirmar_tiene_limite_de_intentos_fallidos_con_aviso_en_el_formulario(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->confirmar($user, 'probando-'.$i)->assertSessionHasErrors('password');
        }

        // El séptimo del minuto no se procesa, aunque traiga la contraseña buena: vuelve al
        // formulario con el aviso, como en el login, no a una página de error suelta.
        $this->confirmar($user, 'password')
            ->assertRedirect('/confirm-password')
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertMatchesRegularExpression('/^Demasiados intentos\. Probá de nuevo en \d+ segundos\.$/', session('errors')->first('password'));

        // El límite es de esa cuenta: otra persona sigue pudiendo confirmar.
        $this->confirmar(User::factory()->create(), 'password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');

        // Pasado el minuto, la misma persona vuelve a poder.
        $this->travel(61)->seconds();
        $this->confirmar($user, 'password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_confirmar_bien_muchas_veces_seguidas_no_frena_a_nadie(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->confirmar($user, 'password')
                ->assertSessionHasNoErrors()
                ->assertSessionHas('auth.password_confirmed_at');
        }
    }

    public function test_un_acierto_borra_los_fallos_anteriores(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->confirmar($user, 'probando-'.$i)->assertSessionHasErrors('password');
        }
        $this->confirmar($user, 'password')->assertSessionHas('auth.password_confirmed_at');

        // Con el contador en cero, cinco fallos más todavía no bloquean la contraseña buena.
        for ($i = 0; $i < 5; $i++) {
            $this->confirmar($user, 'otra-'.$i)->assertSessionHasErrors('password');
        }
        $this->confirmar($user, 'password')
            ->assertSessionHasNoErrors()
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

    public function test_cambiar_la_contrasena_tiene_limite_de_intentos_con_aviso_en_el_formulario(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->cambiar($user, 'probando-'.$i)->assertSessionHasErrorsIn('updatePassword', 'current_password');
        }

        // El séptimo del minuto no se procesa, aunque traiga la contraseña buena: vuelve al
        // perfil con el aviso junto al campo de la contraseña actual.
        $this->cambiar($user, 'password')
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->assertMatchesRegularExpression(
            '/^Demasiados intentos\. Probá de nuevo en \d+ segundos\.$/',
            session('errors')->getBag('updatePassword')->first('current_password'),
        );
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        // Pasado el minuto, sí la cambia.
        $this->travel(61)->seconds();
        $this->cambiar($user, 'password')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::NUEVA, $user->refresh()->password));
    }

    /**
     * El límite es de la cuenta, no de cada pantalla: 6 fallos al confirmar la contraseña
     * también frenan cambiarla y cambiar el correo. Si cada pantalla contara aparte, con una
     * sesión ajena se probarían 18 contraseñas por minuto.
     */
    public function test_seis_fallos_en_una_pantalla_frenan_tambien_las_otras_dos(): void
    {
        $user = User::factory()->create();
        $correo = $user->email;

        for ($i = 0; $i < 6; $i++) {
            $this->confirmar($user, 'probando-'.$i)->assertSessionHasErrors('password');
        }

        $this->cambiar($user, 'password')->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->assertMatchesRegularExpression('/^Demasiados intentos\./', session('errors')->getBag('updatePassword')->first('current_password'));
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->actingAs($user)->from('/profile')
            ->patch('/profile', ['name' => $user->name, 'email' => 'nuevo@ejemplo.com', 'current_password' => 'password'])
            ->assertSessionHasErrors('current_password');
        $this->assertMatchesRegularExpression('/^Demasiados intentos\./', session('errors')->first('current_password'));
        $this->assertSame($correo, $user->refresh()->email);
    }

    /**
     * Solo cuenta la contraseña actual equivocada: quien la pone bien y se equivoca en la
     * nueva (muy corta, no coincide la confirmación) no se queda afuera.
     */
    public function test_con_la_contrasena_actual_correcta_los_errores_de_la_nueva_no_cuentan(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 7; $i++) {
            $this->actingAs($user)
                ->from('/profile')
                ->put('/password', [
                    'current_password' => 'password',
                    'password' => self::NUEVA,
                    'password_confirmation' => 'no-coincide-'.$i,
                ])
                ->assertSessionHasErrorsIn('updatePassword', 'password');
        }

        $this->cambiar($user, 'password')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::NUEVA, $user->refresh()->password));
    }
}
