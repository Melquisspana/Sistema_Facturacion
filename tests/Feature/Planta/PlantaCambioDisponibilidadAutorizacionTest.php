<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los cambios de disponibilidad.
 *
 * EL REPARTO. La LECTURA usa `planta.existencias.ver` —lo que este documento
 * responde es cuánto saldo es utilizable, que es una pregunta de existencias, no
 * de ajustes— y la ESCRITURA usa `planta.calidad.gestionar`. Producción tiene el
 * primero y no el segundo: consulta las decisiones de calidad pero no las toma.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada, y lo que
 * importa es qué responde el servidor a una petición construida a mano.
 */
class PlantaCambioDisponibilidadAutorizacionTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.disponibilidad.index'],
            'create' => ['get', 'planta.disponibilidad.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_confirmar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertNotFound();

        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.disponibilidad.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción: lee pero no escribe ---

    public function test_produccion_puede_ver_el_listado(): void
    {
        $this->encenderModulo();
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.existencias.ver'));
        $this->assertFalse($produccion->can('planta.calidad.gestionar'));

        $this->actingAs($produccion)->get(route('planta.disponibilidad.index'))->assertOk();
    }

    public function test_produccion_puede_ver_el_detalle(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.disponibilidad.show', $cambio))
            ->assertOk();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeEscritura(): array
    {
        return [
            'create' => ['get', 'create'],
            'store' => ['post', 'store'],
            'edit' => ['get', 'edit'],
            'update' => ['put', 'update'],
            'anular' => ['patch', 'anular'],
            'confirmar' => ['patch', 'confirmar'],
            'reversar' => ['patch', 'reversar'],
        ];
    }

    #[DataProvider('rutasDeEscritura')]
    public function test_produccion_recibe_403_en_toda_escritura(string $verbo, string $accion): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        $ruta = in_array($accion, ['create', 'store'], true)
            ? route("planta.disponibilidad.{$accion}")
            : route("planta.disponibilidad.{$accion}", $cambio);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->$verbo($ruta)
            ->assertForbidden();
    }

    public function test_produccion_no_puede_confirmar_ni_por_la_puerta_de_atras(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::where('tipo', 'cambio_disponibilidad')->count());
        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
    }

    // --- Administrador ---

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.disponibilidad.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.disponibilidad.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.disponibilidad.show', $cambio))->assertOk();
        $this->actingAs($admin)->get(route('planta.disponibilidad.edit', $cambio))->assertOk();
    }

    public function test_el_administrador_crea_confirma_y_reversa(): void
    {
        $this->encenderModulo();
        $recepcion = $this->saldoRetenido();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.disponibilidad.store'), $this->payloadCambio($recepcion, '100'))
            ->assertRedirect();

        $cambio = PlantaCambioDisponibilidad::latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertRedirect();

        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $cambio->refresh()->estado);

        $this->actingAs($admin)
            ->patch(route('planta.disponibilidad.reversar', $cambio), ['motivo' => 'la liberación fue un error de criterio'])
            ->assertRedirect();

        $this->assertSame(EstadoCambioDisponibilidad::Reversado, $cambio->refresh()->estado);
    }

    public function test_el_administrador_anula_un_borrador(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        $this->actingAs($this->admin())
            ->patch(route('planta.disponibilidad.anular', $cambio))
            ->assertRedirect();

        $this->assertSame(EstadoCambioDisponibilidad::Anulado, $cambio->refresh()->estado);
    }

    public function test_el_motivo_corto_se_rechaza_en_la_reversion(): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();
        $admin = $this->admin();
        $this->servicioCambio()->confirmar($cambio, $admin);

        $this->actingAs($admin)
            ->patch(route('planta.disponibilidad.reversar', $cambio), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $cambio->refresh()->estado);
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
            ->get(route('planta.disponibilidad.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_confirman(string $rol): void
    {
        $this->encenderModulo();
        $cambio = $this->borradorCambio();

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::where('tipo', 'cambio_disponibilidad')->count());
    }

    // --- Superficie ---

    public function test_no_hay_ruta_de_borrado(): void
    {
        $metodos = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.disponibilidad.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un documento de inventario no se borra: se anula o se reversa.
        $this->assertNotContains('DELETE', $metodos);
    }
}
