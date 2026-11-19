<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Comprobar una contraseña tarda (bcrypt). Si el intento se contara recién después de
 * comprobarla, una ráfaga de pedidos simultáneos con la misma sesión pasaría entera la
 * revisión del límite antes de que se sumara un solo fallo: se probarían decenas de
 * contraseñas por minuto en vez de 6.
 *
 * La ráfaga se arma con un hasher que, mientras comprueba una contraseña, deja entrar el
 * pedido siguiente. Así los pedidos quedan todos en curso a la vez, como en el servidor.
 */
class ContrasenaRafagaTest extends TestCase
{
    use RefreshDatabase;

    private const PEDIDOS = 30;

    /**
     * Manda PEDIDOS pedidos con contraseñas equivocadas, todos en curso a la vez, y
     * devuelve cuántas contraseñas se llegaron a comprobar.
     *
     * @param  Closure(string): mixed  $pedido  manda un pedido con esa contraseña
     */
    private function rafaga(User $user, Closure $pedido): int
    {
        $comprobadas = 0;
        $restantes = self::PEDIDOS;
        $enviar = function () use (&$restantes, $pedido) {
            while ($restantes > 0) {
                $clave = 'probando-'.$restantes--;
                try {
                    $pedido($clave);
                } catch (ValidationException) {
                    // Contraseña equivocada o límite alcanzado: la ráfaga sigue igual.
                }
            }
        };

        $this->app->instance('hash', new HasherQueDejaEntrar(
            $this->app->make('hash'),
            function () use (&$comprobadas, $enviar) {
                $comprobadas++;
                $enviar();
            },
        ));
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);

        $enviar();

        return $comprobadas;
    }

    private function pedido(User $user, string $uri, array $datos): Request
    {
        $request = Request::create($uri, 'POST', $datos);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_en_una_rafaga_al_confirmar_solo_se_comprueban_6_contrasenas(): void
    {
        $user = User::factory()->create();

        $comprobadas = $this->rafaga($user, fn (string $clave) => app(ConfirmablePasswordController::class)
            ->store($this->pedido($user, '/confirm-password', ['password' => $clave])));

        $this->assertSame(6, $comprobadas);
    }

    public function test_en_una_rafaga_al_cambiar_la_contrasena_solo_se_comprueban_6(): void
    {
        $user = User::factory()->create();

        $comprobadas = $this->rafaga($user, fn (string $clave) => app(PasswordController::class)
            ->update($this->pedido($user, '/password', [
                'current_password' => $clave,
                'password' => 'Nueva#Clave2026',
                'password_confirmation' => 'Nueva#Clave2026',
            ])));

        $this->assertSame(6, $comprobadas);
    }
}

/** Hasher de verdad que, cada vez que comprueba una contraseña, primero avisa. */
final class HasherQueDejaEntrar implements Hasher
{
    public function __construct(private Hasher $real, private Closure $alComprobar) {}

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        ($this->alComprobar)();

        return $this->real->check($value, $hashedValue, $options);
    }

    public function info($hashedValue): array
    {
        return $this->real->info($hashedValue);
    }

    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        return $this->real->make($value, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->real->needsRehash($hashedValue, $options);
    }

    public function __call(string $metodo, array $argumentos): mixed
    {
        return $this->real->{$metodo}(...$argumentos);
    }
}
