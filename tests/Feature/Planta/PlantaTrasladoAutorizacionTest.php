<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaTraslado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los traslados.
 *
 * Los permisos son por ACCIÓN, no por pantalla, porque enviar y recibir son
 * actos físicos distintos que pueden recaer en personas distintas. Producción
 * opera el día a día —ver, crear, enviar, recibir— y NO puede reversar: deshacer
 * inventario ya contabilizado no es operación.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada.
 */
class PlantaTrasladoAutorizacionTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.traslados.index'],
            'create' => ['get', 'planta.traslados.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_enviar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertNotFound();

        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->refresh()->estado);
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.traslados.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción: opera todo menos reversar ---

    public function test_produccion_tiene_los_permisos_de_operacion_y_no_el_de_reversar(): void
    {
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.traslados.ver'));
        $this->assertTrue($produccion->can('planta.traslados.crear'));
        $this->assertTrue($produccion->can('planta.traslados.enviar'));
        $this->assertTrue($produccion->can('planta.traslados.recibir'));
        $this->assertFalse($produccion->can('planta.traslados.reversar'));
    }

    public function test_produccion_ve_listado_y_detalle(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)->get(route('planta.traslados.index'))->assertOk();
        $this->actingAs($produccion)->get(route('planta.traslados.show', $traslado))->assertOk();
    }

    public function test_produccion_crea_un_borrador(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.traslados.store'), $this->payloadTraslado($e, '100'))
            ->assertRedirect();

        $this->assertSame(1, PlantaTraslado::count());
    }

    public function test_produccion_envia_y_recibe(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertRedirect();
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $traslado->refresh()->estado);

        $this->actingAs($produccion)
            ->patch(route('planta.traslados.recibir', $traslado))
            ->assertRedirect();
        $this->assertSame(EstadoTrasladoPlanta::Recibido, $traslado->refresh()->estado);
    }

    public function test_produccion_no_puede_reversar(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);
        $this->servicioTraslado()->recibir($traslado, $admin);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoTrasladoPlanta::Recibido, $traslado->refresh()->estado);
    }

    public function test_produccion_cancela_un_borrador(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.traslados.cancelar', $traslado))
            ->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $traslado->refresh()->estado);
    }

    // --- Administrador ---

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.traslados.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.show', $traslado))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.edit', $traslado))->assertOk();
    }

    public function test_el_administrador_reversa(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);

        $this->actingAs($admin)
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'el camión no llegó a salir'])
            ->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Reversado, $traslado->refresh()->estado);
    }

    public function test_el_motivo_corto_se_rechaza(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);

        $this->actingAs($admin)
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoTrasladoPlanta::Enviado, $traslado->refresh()->estado);
    }

    public function test_el_destino_igual_al_origen_se_rechaza_en_el_formulario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();

        $this->actingAs($this->admin())
            ->post(route('planta.traslados.store'), $this->payloadTraslado($e, '100', [
                'planta_ubicacion_destino_id' => $e['origen']->id,
            ]))
            ->assertSessionHasErrors('planta_ubicacion_destino_id');

        $this->assertSame(0, PlantaTraslado::count());
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
            ->get(route('planta.traslados.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_envian(string $rol): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::where('tipo', 'traslado_envio')->count());
    }

    // --- Superficie ---

    public function test_no_hay_ruta_de_borrado(): void
    {
        $metodos = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.traslados.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un documento de inventario no se borra: se cancela o se reversa.
        $this->assertNotContains('DELETE', $metodos);
    }
}
