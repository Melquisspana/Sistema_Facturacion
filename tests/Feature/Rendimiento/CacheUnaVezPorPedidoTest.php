<?php

namespace Tests\Feature\Rendimiento;

use App\Ajustes\Ajustes;
use App\Models\Empresa;
use App\Models\User;
use App\Support\MemoDePedido;
use App\Support\NombreEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * En producción la caché es la tabla `cache`: cada Cache::get es una consulta. Estas
 * pruebas usan ese store (las demás usan `array`, donde no se nota) y cuentan las lecturas.
 */
class CacheUnaVezPorPedidoTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $lecturas = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        // Acepta comillas dobles (SQLite) o acentos graves (MySQL).
        DB::listen(function ($q) {
            if (preg_match('/^\s*select\b.*\bfrom\s+["`]?cache["`]?\s/i', $q->sql)) {
                $this->lecturas[] = $q->bindings[0] ?? '?';
            }
        });
    }

    private function lecturasDe(string $clave): int
    {
        return count(array_filter($this->lecturas, fn ($b) => str_ends_with((string) $b, $clave)));
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    public function test_rutas_lee_cada_clave_de_cache_una_sola_vez(): void
    {
        $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        $this->assertLessThanOrEqual(1, $this->lecturasDe('empresa.nombre_visible'));
        $this->assertLessThanOrEqual(1, $this->lecturasDe('ajustes:huella'));
        $this->assertLessThanOrEqual(4, count($this->lecturas), implode(', ', $this->lecturas));
    }

    public function test_el_panel_principal_lee_cada_clave_de_cache_una_sola_vez(): void
    {
        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk();

        $this->assertLessThanOrEqual(1, $this->lecturasDe('empresa.nombre_visible'));
        $this->assertLessThanOrEqual(1, $this->lecturasDe('worker.heartbeat.ts'));
        $this->assertLessThanOrEqual(1, $this->lecturasDe('ajustes:huella'));
        $this->assertLessThanOrEqual(5, count($this->lecturas), implode(', ', $this->lecturas));
    }

    public function test_una_pantalla_de_facturacion_lee_el_nombre_una_sola_vez(): void
    {
        $this->actingAs($this->admin())->get(route('facturacion.create-ccf'))->assertOk();

        $this->assertLessThanOrEqual(1, $this->lecturasDe('empresa.nombre_visible'));
    }

    public function test_el_cambio_de_nombre_se_ve_en_el_pedido_siguiente(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Nombre viejo']);
        $this->actingAs($this->admin());
        $this->get(route('rutas.dashboard'))->assertOk()->assertSee('Nombre viejo');

        $empresa->update(['razon_social' => 'Nombre nuevo']);

        $this->get(route('rutas.dashboard'))->assertOk()->assertSee('Nombre nuevo')->assertDontSee('Nombre viejo');
    }

    public function test_leer_muchos_ajustes_consulta_la_huella_una_sola_vez(): void
    {
        $ajustes = app(Ajustes::class);
        $this->lecturas = [];

        for ($i = 0; $i < 30; $i++) {
            $ajustes->bool('alertas.enabled');
        }

        $this->assertSame(1, $this->lecturasDe('ajustes:huella'));
    }

    public function test_el_memo_se_descarta_entre_trabajos_de_la_cola(): void
    {
        Empresa::create(['razon_social' => 'Nombre viejo']);
        $this->assertSame('Nombre viejo', NombreEmpresa::actual());

        // Otro proceso cambia el nombre y la caché: este ya había memorizado el viejo.
        Cache::put('empresa.nombre_visible', 'Nombre de otro proceso');
        $this->assertSame('Nombre viejo', NombreEmpresa::actual());

        // El worker llama a esto al terminar cada trabajo.
        app()->forgetScopedInstances();

        $this->assertSame('Nombre de otro proceso', NombreEmpresa::actual());
        $this->assertInstanceOf(MemoDePedido::class, MemoDePedido::actual());
    }
}
