<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SinInternetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['dte.contingencia.enabled' => true, 'dte.contingencia.url_local' => '']);
    }

    public function test_requires_authentication(): void
    {
        $this->get('/sin-internet')->assertRedirect(route('login'));
    }

    public function test_disabled_route_is_unavailable(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $this->actingAs(User::factory()->create())->get('/sin-internet')->assertNotFound();
    }

    public function test_page_is_independent_and_has_no_session_or_user_data(): void
    {
        $user = User::factory()->create(['name' => 'Persona ficticia privada']);
        $this->actingAs($user)->withSession(['dato_privado' => 'Monto ficticio privado']);
        $this->get('/sin-internet')->assertOk()
            ->assertSee('Sin internet: entrá por la red del local')
            ->assertSee('Avanzado → Continuar')
            ->assertSee('Pedí la dirección al administrador.')
            ->assertDontSee('Abrir Facturación (sin internet)')
            ->assertDontSee($user->name)->assertDontSee($user->email)
            ->assertDontSee('Monto ficticio privado')->assertDontSee(csrf_token())
            ->assertDontSee('csrf-token')->assertDontSee('_token')
            ->assertDontSee('<script', false)->assertDontSee('<nav', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_button_requires_valid_https_url_and_escapes_it(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['', 'http://example.test', 'javascript:alert(1)', 'https://', 'not a url'] as $url) {
            config(['dte.contingencia.url_local' => $url]);
            $this->get('/sin-internet')->assertOk()->assertDontSee('Abrir Facturación (sin internet)');
        }
        config(['dte.contingencia.url_local' => 'https://example.test/?a=1&b=2']);
        $this->get('/sin-internet')->assertOk()->assertSee('Abrir Facturación (sin internet)')
            ->assertSee('href="https://example.test/?a=1&amp;b=2"', false);
    }

    public function test_guest_layout_never_registers_worker(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('navigator.serviceWorker.register(', false)
            ->assertSee('registration.unregister()', false)->assertSee("name.startsWith('sin-internet-')", false);
    }

    public function test_authenticated_layout_registers_only_when_enabled(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true]));
        $this->get('/profile')->assertOk()->assertSee('navigator.serviceWorker.register(workerPath', false)
            ->assertSee("const workerPath = '/sw-contingencia.js'", false)
            ->assertSee("{ scope: '/' }", false);
        config(['dte.contingencia.enabled' => false]);
        $this->get('/profile')->assertOk()->assertDontSee('navigator.serviceWorker.register(', false)
            ->assertSee('registration.unregister()', false)->assertSee('cleanup().catch(', false);
    }

    public function test_logout_arrives_at_guest_page_with_cleanup(): void
    {
        $this->actingAs(User::factory()->create());
        $this->followingRedirects()->post('/logout')->assertOk()
            ->assertDontSee('navigator.serviceWorker.register(', false)->assertSee('cleanup().catch(', false);
        $this->assertGuest();
    }

    public function test_cleanup_handles_both_logout_forms_before_submission(): void
    {
        $html = view('layouts.partials.sw-contingencia')->render();
        $this->assertStringContainsString("['/logout', '/logout-completo']", $html);
        $this->assertStringContainsString('await cleanup()', $html);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form)', $html);
        $navigation = file_get_contents(resource_path('views/layouts/navigation.blade.php'));
        $this->assertSame(2, substr_count($navigation, "this.closest('form').requestSubmit()"));
    }

    public function test_worker_only_caches_offline_page_and_handles_specified_navigations(): void
    {
        $js = file_get_contents(public_path('sw-contingencia.js'));
        $this->assertStringContainsString("request.mode !== 'navigate' || request.method !== 'GET'", $js);
        $this->assertStringContainsString('[502, 504, 520, 521, 522, 523, 524, 525, 526, 527]', $js);
        $this->assertStringContainsString('response.status !== 200', $js);
        $this->assertStringContainsString("redirect: 'error'", $js);
        $this->assertStringContainsString("const OFFLINE_URL = '/sin-internet'", $js);
        $this->assertSame(1, substr_count($js, 'cache.put('));
        $this->assertStringContainsString('cache.put(OFFLINE_URL, response)', $js);
        $this->assertStringContainsString('name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME', $js);
        $this->assertStringContainsString("const CACHE_NAME = 'sin-internet-v1'", $js);
    }
}
