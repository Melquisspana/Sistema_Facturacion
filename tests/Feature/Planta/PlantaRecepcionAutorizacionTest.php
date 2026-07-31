<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de las recepciones.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada, y lo que
 * importa es qué responde el servidor a una petición construida a mano.
 *
 * Tres capas, en orden:
 *   1. `auth` — el invitado va al login;
 *   2. `modulo.planta` — 404 con el módulo apagado, para TODOS los roles;
 *   3. `permission:...` — 403 por acción, no por pantalla.
 */
class PlantaRecepcionAutorizacionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.recepciones.index'],
            'create' => ['get', 'planta.recepciones.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_todo_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_confirmar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertNotFound();

        $this->assertSame(0, PlantaMovimiento::count());
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.recepciones.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción ---

    public function test_produccion_ve_el_listado(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.recepciones.index'))
            ->assertOk();
    }

    public function test_produccion_crea_un_borrador(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [$this->linea($insumo)]))
            ->assertRedirect();

        $this->assertSame(1, PlantaRecepcion::count());
    }

    public function test_produccion_edita_su_borrador(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.recepciones.edit', $recepcion))
            ->assertOk();
    }

    public function test_produccion_confirma_hacia_disponible(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $recepcion->refresh()->estado);
    }

    public function test_produccion_no_puede_reversar(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.recepciones.reversar', $confirmada), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $confirmada->refresh()->estado);
    }

    public function test_produccion_no_puede_recibir_como_retenido(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        // La ruta la deja pasar —tiene `crear`—, pero el servicio la rechaza por
        // falta de `planta.calidad.gestionar`. El candado no está en el formulario.
        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [
                $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
            ]))
            ->assertSessionHasErrors('recepcion');

        $this->assertSame(0, PlantaRecepcion::count());
    }

    // --- Administrador ---

    public function test_el_administrador_puede_recibir_como_retenido(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $this->actingAs($this->admin())
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [
                $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
            ]))
            ->assertRedirect();

        $this->assertSame(1, PlantaRecepcion::count());
    }

    public function test_el_administrador_puede_reversar(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->actingAs($this->admin())
            ->patch(route('planta.recepciones.reversar', $confirmada), ['motivo' => 'devolución completa al proveedor'])
            ->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Reversada, $confirmada->refresh()->estado);
    }

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.recepciones.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.show', $recepcion))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.edit', $recepcion))->assertOk();
    }

    // --- Roles ajenos al área ---

    /** @return array<string, array{0: string}> */
    public static function rolesAjenos(): array
    {
        return [
            'facturacion' => ['facturacion'],
            'contabilidad' => ['contabilidad'],
            'jefatura' => ['jefatura'],
        ];
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_reciben_403(string $rol): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol($rol))
            ->get(route('planta.recepciones.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_confirman(string $rol): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::count());
    }

    // --- No existe borrado físico ---

    public function test_no_hay_ruta_de_borrado(): void
    {
        $rutas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.recepciones.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un documento de inventario no se borra: se anula o se reversa.
        $this->assertNotContains('DELETE', $rutas);
    }
}
