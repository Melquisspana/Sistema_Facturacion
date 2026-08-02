<?php

namespace Tests\Feature\Planta;

use App\Exceptions\Planta\LoteGenericoNoAplicableException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Candados de acceso de la pantalla de lotes.
 *
 * El orden importa y se prueba en ese orden: primero el interruptor del módulo
 * (404 para todos, incluido el administrador), luego el permiso de área (403),
 * luego el de catálogos, y por último el reparto lectura/escritura dentro de la
 * pantalla. Ningún botón oculto sustituye a esto: todas las pruebas fuerzan la
 * URL directamente.
 *
 * El reparto es el mismo que el del resto de catálogos y NO se creó ningún
 * permiso nuevo: `planta.catalogos.ver` para consultar y
 * `planta.catalogos.gestionar` para retirar o reincorporar. El rol `produccion`
 * tiene el primero y no el segundo.
 */
class PlantaLotesAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function encenderModulo(): void
    {
        config()->set('planta.enabled', true);
    }

    /** Lote real, con factory: aquí se prueban candados, no el motor. */
    private function lote(): PlantaLote
    {
        return PlantaLote::factory()->create();
    }

    private function generico(): PlantaLote
    {
        $insumo = PlantaInsumo::factory()->bolsa()->create();

        return PlantaLote::factory()->generico($insumo)->create();
    }

    // --- Interruptor del módulo ---

    public function test_con_el_modulo_apagado_el_administrador_recibe_404(): void
    {
        // phpunit.xml fija PLANTA_ENABLED=false: no hay que apagar nada.
        $admin = $this->usuario('administrador');
        $lote = $this->lote();

        $this->actingAs($admin)->get(route('planta.lotes.index'))->assertNotFound();
        $this->actingAs($admin)->get(route('planta.lotes.show', $lote))->assertNotFound();
        $this->actingAs($admin)->patch(route('planta.lotes.toggle-activo', $lote))->assertNotFound();

        // Y el intento no cambió nada.
        $this->assertTrue($lote->fresh()->activo);
    }

    // --- Invitado ---

    public function test_el_invitado_es_redirigido_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.lotes.index'))->assertRedirect(route('login'));
        $this->get(route('planta.lotes.show', $this->lote()))->assertRedirect(route('login'));
    }

    // --- Roles ajenos al área ---

    public function test_los_roles_ajenos_al_area_reciben_403(): void
    {
        $this->encenderModulo();
        $lote = $this->lote();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $usuario = $this->usuario($rol);

            // Ni siquiera pasan el primer candado (planta.ver).
            $this->actingAs($usuario)->get(route('planta.lotes.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('planta.lotes.show', $lote))->assertForbidden();
            $this->actingAs($usuario)->patch(route('planta.lotes.toggle-activo', $lote))->assertForbidden();
        }

        $this->assertTrue($lote->fresh()->activo);
    }

    // --- Rol produccion: consulta, no administra ---

    public function test_produccion_puede_listar_y_ver_la_ficha(): void
    {
        $this->encenderModulo();
        $lote = $this->lote();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.lotes.index'))
            ->assertOk()
            ->assertSee($lote->codigo_interno);

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.lotes.show', $lote))
            ->assertOk()
            ->assertSee($lote->codigo_interno);
    }

    public function test_produccion_no_puede_retirar_ni_reincorporar_un_lote(): void
    {
        $this->encenderModulo();
        $lote = $this->lote();

        $this->actingAs($this->usuario('produccion'))
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertForbidden();

        $this->assertTrue($lote->fresh()->activo, 'El lote no pudo cambiar de estado.');
    }

    public function test_produccion_no_ve_el_boton_de_cambiar_estado(): void
    {
        $this->encenderModulo();
        $lote = $this->lote();

        // El componente de estado se dibuja como etiqueta inerte sin el permiso
        // de gestión: no hay formulario que apunte a la ruta de escritura.
        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.lotes.index'))
            ->assertOk()
            ->assertDontSee(route('planta.lotes.toggle-activo', $lote), false);
    }

    // --- Administrador: la única escritura ---

    public function test_el_administrador_retira_y_reincorpora_un_lote_real(): void
    {
        $this->encenderModulo();
        $admin = $this->usuario('administrador');
        $lote = $this->lote();

        $this->actingAs($admin)
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertRedirect();

        $this->assertFalse($lote->fresh()->activo);

        $this->actingAs($admin)
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertRedirect();

        $this->assertTrue($lote->fresh()->activo);
    }

    public function test_el_administrador_ve_el_boton_de_cambiar_estado(): void
    {
        $this->encenderModulo();
        $lote = $this->lote();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('planta.lotes.index'))
            ->assertOk()
            ->assertSee(route('planta.lotes.toggle-activo', $lote), false);
    }

    // --- El lote genérico ---

    public function test_el_lote_generico_no_se_puede_alternar_por_http(): void
    {
        $this->encenderModulo();
        $generico = $this->generico();

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('planta.lotes.toggle-activo', $generico))
            ->assertNotFound();

        $this->assertTrue($generico->fresh()->activo);
    }

    /**
     * Y el candado de la superficie no sustituye al del modelo: si alguien
     * llegara al genérico por otro camino, `updating` sigue lanzando. Son dos
     * capas, no una repetida.
     */
    public function test_la_proteccion_del_modelo_sobre_el_generico_sigue_intacta(): void
    {
        $generico = $this->generico();

        $this->expectException(LoteGenericoNoAplicableException::class);

        $generico->update(['activo' => false]);
    }

    // --- Superficie HTTP ---

    /**
     * Solo hay dos GET y un PATCH. Cualquier otro verbo muere en el router, con
     * 405, antes de tocar una línea de código del controlador.
     */
    public function test_los_verbos_no_declarados_responden_405(): void
    {
        $this->encenderModulo();
        $admin = $this->usuario('administrador');
        $lote = $this->lote();

        $this->actingAs($admin)->post(route('planta.lotes.index'), [])->assertMethodNotAllowed();
        $this->actingAs($admin)->put(route('planta.lotes.show', $lote), [])->assertMethodNotAllowed();
        $this->actingAs($admin)->patch(route('planta.lotes.show', $lote), [])->assertMethodNotAllowed();
        $this->actingAs($admin)->delete(route('planta.lotes.show', $lote))->assertMethodNotAllowed();
        $this->actingAs($admin)->post(route('planta.lotes.toggle-activo', $lote), [])->assertMethodNotAllowed();

        $this->assertTrue($lote->fresh()->activo);
    }

    // --- Navegación ---

    public function test_la_sidebar_ofrece_lotes_a_quien_puede_ver_los_catalogos(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.lotes.index'), false);
    }
}
