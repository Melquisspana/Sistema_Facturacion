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
            route('rutas.rutas.index'),
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

    public function test_al_salir_el_navegador_borra_las_paginas_guardadas(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));

        $this->post(route('logout'))->assertRedirect()->assertHeader('Clear-Site-Data', '"cache"');
        $this->assertGuest();

        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));
        $this->post(route('logout.completo'))->assertRedirect()->assertHeader('Clear-Site-Data', '"cache"');
        $this->assertGuest();
    }
}
