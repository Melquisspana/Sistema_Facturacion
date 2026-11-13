<?php

namespace Tests\Feature\Ordenes;

use App\Enums\ModuloSistema;
use App\Models\OrdenCompraRecibida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Órdenes recibidas» apagada por ahora para todos (Melqui, 12/11/2026): sale del menú y sus
 * rutas responden 404, detrás del interruptor de Configuración › Módulos, apagado por
 * defecto. Lo demás que usa las órdenes (la sincronización del correo, lo que se ofrece al
 * facturar, «PDF + orden») sigue igual: eso lo prueban OrdenesRecibidasTest y
 * OrdenesSincronizarCommandTest con el interruptor apagado.
 */
class OrdenesRecibidasApagadaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function orden(): OrdenCompraRecibida
    {
        return OrdenCompraRecibida::create([
            'numero' => '261102320010394', 'fecha_creacion' => '2026-11-05', 'total' => '22.90',
            'pdf_path' => OrdenCompraRecibida::CARPETA_PDF.'/no-existe.pdf', 'pdf_hash' => str_repeat('a', 64),
            'gmail_message_id' => 'm-prueba', 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
    }

    public function test_viene_apagada_por_defecto(): void
    {
        $this->assertFalse(ModuloSistema::OrdenesRecibidas->activo());
    }

    public function test_apagada_las_rutas_responden_404_tambien_al_administrador(): void
    {
        $orden = $this->orden();
        $this->actingAs($this->admin());

        $this->get(route('facturacion.ordenes-recibidas.index'))->assertNotFound();
        $this->get(route('facturacion.ordenes-recibidas.pdf', $orden))->assertNotFound();
    }

    public function test_apagada_no_sale_en_el_menu(): void
    {
        $this->actingAs($this->admin())->get(route('facturacion.index'))
            ->assertOk()
            ->assertDontSee('Órdenes recibidas')
            ->assertDontSee(route('facturacion.ordenes-recibidas.index'), false);
    }

    public function test_al_prenderla_vuelven_el_menu_y_la_pantalla(): void
    {
        config([ModuloSistema::OrdenesRecibidas->claveConfig() => true]);
        $this->actingAs($this->admin());

        $this->get(route('facturacion.index'))->assertOk()->assertSee(route('facturacion.ordenes-recibidas.index'), false);
        $this->get(route('facturacion.ordenes-recibidas.index'))->assertOk();
    }

    public function test_se_prende_desde_configuracion_modulos(): void
    {
        $this->actingAs($this->admin())->get(route('configuracion.modulos'))
            ->assertOk()
            ->assertSee(ModuloSistema::OrdenesRecibidas->etiqueta());
    }
}
