<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Atrás» en Rutas (12/11/2026). Las pantallas de la oficina que solo se miran dejan que el
 * navegador las restaure al tocar «atrás» (`private, no-cache`: nada en cachés compartidas y
 * una visita nueva la pide fresca). Las del vendedor que marcan cosas siguen con `no-store`:
 * «atrás» no puede mostrar un estado viejo que lleve a marcar dos veces. Al salir, el
 * navegador borra lo guardado del sitio.
 */
class CabecerasAtrasRutasTest extends TestCase
{
    use RefreshDatabase;

    private function directivas(string $url): array
    {
        $valor = (string) $this->get($url)->assertOk()->headers->get('Cache-Control');

        return array_map('trim', explode(',', $valor));
    }

    public function test_las_pantallas_de_la_oficina_vuelven_al_instante_con_atras(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));

        foreach ([
            route('rutas.dashboard'),
            route('rutas.viajes'),
            route('rutas.mapa'),
            route('rutas.pedidos.index'),
            route('rutas.ventas.index'),
            route('rutas.salidas.index'),
        ] as $url) {
            $directivas = $this->directivas($url);
            $this->assertContains('private', $directivas, $url);
            $this->assertContains('no-cache', $directivas, $url);
            $this->assertNotContains('no-store', $directivas, $url);
            $this->assertNotContains('public', $directivas, $url);
        }
    }

    /**
     * Lo que se edita en la misma página (Zonas, el detalle de un pedido con su orden de
     * compra) y las descargas de Ventas no se guardan: «atrás» no puede devolver un
     * formulario viejo que pise lo que otro ya cambió, ni dejar el Excel en la caché.
     */
    public function test_lo_que_se_edita_y_las_descargas_no_se_guardan(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));

        foreach ([
            route('rutas.rutas.index'),
            route('rutas.ventas.excel'),
        ] as $url) {
            $directivas = $this->directivas($url);
            $this->assertContains('no-store', $directivas, $url);
            $this->assertContains('private', $directivas, $url);
        }

        foreach (['rutas.pedidos.show', 'rutas.ventas.pdf'] as $nombre) {
            $this->assertContains('cache.headers:no_store;private', app('router')->getRoutes()->getByName($nombre)->gatherMiddleware(), $nombre);
        }
    }

    public function test_las_pantallas_del_vendedor_que_marcan_no_se_guardan(): void
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba', 'ruta_id' => $ruta->id]);
        $this->actingAs($vendedor);

        foreach ([
            route('rutas.mi-salida.index'),
            route('rutas.mi-salida.dia'),
            route('rutas.por-entregar.index'),
            route('rutas.sacar-pedido.index'),
            route('rutas.sacar-pedido.create', $sala),
        ] as $url) {
            $directivas = $this->directivas($url);
            $this->assertContains('no-store', $directivas, $url);
            $this->assertContains('private', $directivas, $url);
        }
    }

    public function test_al_salir_no_se_borra_todo_lo_guardado_y_atras_pide_la_pagina_de_nuevo(): void
    {
        // Clear-Site-Data «cache» tardaba segundos en el celular y «Salir» parecía trabado (13/11/2026).
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));
        $this->post(route('logout'))->assertRedirect()->assertHeaderMissing('Clear-Site-Data');
        $this->assertGuest();

        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));
        $this->post(route('logout.completo'))->assertRedirect()->assertHeaderMissing('Clear-Site-Data');
        $this->assertGuest();

        // En su lugar, la pestaña recuerda que salió y, si «atrás» devuelve una pantalla de antes
        // desde la memoria, la pide de nuevo; al entrar otra vez se olvida.
        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');
        $html = $this->actingAs($oficina)->get(route('dashboard'))->assertOk()->getContent();
        $cabeza = strstr($html, '</head>', true);
        $this->assertStringContainsString("navegacion.type === 'back_forward' && vieja()", $cabeza);
        $this->assertStringContainsString('if (event.persisted && vieja()) recargar();', $cabeza);
        $this->assertStringContainsString('dlgCargada: Date.now()', $cabeza);
        // La hora de salida va a localStorage: la ven todas las pestañas.
        $this->assertStringContainsString("localStorage.setItem('dlg-salio', String(Date.now()))", $html);
        $this->assertStringContainsString('if (form.dataset.saliendo) return;', $html);

        $this->post(route('logout'));
        $login = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString("navegacion.type === 'back_forward' && vieja()", strstr($login, '</head>', true));

        // El «Salir» del vendedor también anota la hora (un jefe que vuelve al Tablero con «atrás»).
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $this->actingAs($vendedor)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee("localStorage.setItem('dlg-salio', String(Date.now()))", false);
    }
}
