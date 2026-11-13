<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\CloudflareAccessSso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * «Salir» (12/11/2026, «nunca funcionó desde el inicio»): cierra la sesión del servidor, borra
 * la cookie de «recordarme», deja la marca de «salió a propósito» y manda al login. Lo mismo
 * desde la barra normal, la de pantalla completa y la del vendedor, que usan la misma ruta.
 * Lo del dominio público con Cloudflare Access (la causa) está en CloudflareAccessSsoTest.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_salir_cierra_la_sesion_borra_recordarme_deja_la_marca_y_va_al_login(): void
    {
        $usuario = User::factory()->create(['activo' => true]);
        $login = $this->post('/login', ['email' => $usuario->email, 'password' => 'password', 'remember' => 'on']);
        $this->assertAuthenticatedAs($usuario);
        $token = $usuario->fresh()->remember_token;
        $this->assertNotNull($token);
        $recordar = Auth::guard('web')->getRecallerName();
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === $recordar);
        $this->assertNotNull($cookie, 'El login con «recordarme» deja su cookie.');

        // El navegador la manda tal como la recibió (cifrada).
        $respuesta = $this->withUnencryptedCookie($recordar, $cookie->getValue())->post(route('logout'));

        $respuesta->assertRedirect('/');
        $respuesta->assertCookieExpired($recordar);
        $respuesta->assertCookie(CloudflareAccessSso::MARCA_SALIDA);
        $marca = collect($respuesta->headers->getCookies())->first(fn ($c) => $c->getName() === CloudflareAccessSso::MARCA_SALIDA);
        $this->assertTrue($marca->isHttpOnly());
        $this->assertGreaterThan(now()->addYear()->getTimestamp(), $marca->getExpiresTime(), 'La marca dura más que la sesión.');
        $this->assertGuest();
        $this->assertNotSame($token, $usuario->fresh()->remember_token, 'El token de «recordarme» viejo ya no sirve.');
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_salir_esta_en_la_barra_normal_la_de_pantalla_completa_y_la_del_vendedor(): void
    {
        $formulario = 'action="'.route('logout').'"';

        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($admin)->get(route('profile.edit'))->assertOk()->assertSee($formulario, false);

        $fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->actingAs($fabrica)->get(route('fabrica.pedidos.index'))->assertOk()->assertSee($formulario, false)->assertSee('Salir');

        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->actingAs($vendedor)->get(route('rutas.mi-salida.index'))->assertOk()->assertSee($formulario, false)->assertSee('Salir');
    }
}
