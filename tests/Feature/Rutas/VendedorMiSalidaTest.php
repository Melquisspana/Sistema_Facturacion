<?php

namespace Tests\Feature\Rutas;

use App\Enums\AreaSistema;
use App\Enums\EstadoSalidaRuta;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendedorMiSalidaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos', 'user_id' => $this->usuario->id]);
        $this->ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala centro', 'ruta_id' => $this->ruta->id]);
        $this->actingAs($this->usuario);
    }

    private function salida(bool $propia = true, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-10-05', 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [($propia ? $this->persona : PersonalRuta::create(['nombre' => 'Ana']))->id], null);

        return $salida;
    }

    private function entrega(SalidaRuta $salida, ?ClienteSucursal $sala = null): SalidaRutaEntrega
    {
        $sala ??= $this->sala;
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (907 + Dte::count()), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-01', 'hora_emision' => '10:00:00', 'total_pagar' => 299.99,
        ]);

        return $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id]);
    }

    public function test_rol_permiso_y_aterrizaje(): void
    {
        $this->assertSame('Vendedor', RolSistema::Vendedor->label());
        $this->assertSame(['rutas.entregas.registrar', 'rutas.pedidos.registrar'], RolSistema::Vendedor->permisos());
        foreach (RolSistema::cases() as $rol) {
            $this->assertSame(in_array($rol, [RolSistema::Administrador, RolSistema::Vendedor, RolSistema::JefeVendedores], true), in_array(PermisoSistema::RutasEntregasRegistrar->value, $rol->permisos(), true));
        }
        $this->assertSame([AreaSistema::Rutas], AreaSistema::visiblesPara($this->usuario));
        // Desde el 10/11/2026 entra por su inicio: su viaje, «Sacar pedido» y «Por entregar».
        $this->assertSame('rutas.mi-salida.index', AreaSistema::Rutas->rutaInicioPara($this->usuario));
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertRedirect(route('rutas.mi-salida.index'));
        $this->followingRedirects()->get('/')->assertOk()->assertSee('Sacar pedido')->assertSee('Por entregar');
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Todavía no tenés un viaje abierto');
    }

    public function test_indice_sin_ficha_sin_viaje_y_sin_las_salidas_de_antes(): void
    {
        $this->persona->update(['user_id' => null]);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Tu usuario no es vendedor');
        $this->persona->update(['user_id' => $this->usuario->id]);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Todavía no tenés un viaje abierto');
        // Las salidas armadas a mano de antes ya no tienen hoja ni se listan acá (11/11/2026).
        $una = $this->salida();
        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertDontSee('Salidas de antes en camino')->assertDontSee('/rutas/mi-salida/'.$una->id.'"', false);
        $this->get('/rutas/mi-salida/'.$una->id)->assertNotFound();
        $this->patch('/rutas/mi-salida/'.$una->id.'/entregas/'.$this->entrega($una)->id, ['resultado' => 'entregado'])->assertNotFound();
    }

    public function test_deshacer_propio_scope_y_registro_de_la_oficina(): void
    {
        $salida = $this->salida();
        $entrega = $this->entrega($salida);
        $ajeno = PersonalRuta::create(['nombre' => 'Otro']);
        $oficina = User::factory()->create()->assignRole('facturacion');
        // Alguien que no va en la salida no puede quedar como quien entregó.
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $ajeno->id])->assertSessionHasErrors('entregado_por_id');
        $this->assertTrue($entrega->refresh()->estaPendiente());
        // Lo que marcó el vendedor (desde «Por entregar» cae en la salida donde ya va el CCF) lo deshace él.
        app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'entregado_por_id' => $this->persona->id, 'trae_nota_averia' => '1'], $this->usuario, OrigenRegistroEntrega::Vendedor);
        $entrega->refresh();
        $this->assertSame($this->persona->id, $entrega->entregado_por_id);
        $this->assertSame($this->usuario->id, $entrega->registrado_por);
        $this->assertSame(OrigenRegistroEntrega::Vendedor, $entrega->origen_registro);
        $this->assertTrue($entrega->trae_nota_averia);
        $this->actingAs($this->usuario)->patch(route('rutas.mi-salida.deshacer', [$salida, $entrega]))->assertSessionHas('status');
        $this->assertTrue($entrega->refresh()->estaPendiente());
        $otra = $this->salida();
        $this->patch(route('rutas.mi-salida.deshacer', [$otra, $entrega]))->assertNotFound();
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->persona->id, $ajeno->id], null);
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $ajeno->id])->assertSessionHas('status');
        $this->assertSame($ajeno->id, $entrega->refresh()->entregado_por_id);
        // Lo que anotó la oficina no lo deshace el vendedor.
        $this->actingAs($this->usuario)->patch(route('rutas.mi-salida.deshacer', [$salida, $entrega]))->assertForbidden();
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.deshacer', [$salida, $entrega]))->assertSessionHas('status');
        $this->get(route('rutas.salidas.show', $salida))->assertOk()->assertSee('…0907')->assertSee('title="'.$entrega->dte->numero_control.'"', false);
    }

    public function test_las_pantallas_de_rutas_no_quedan_en_la_memoria_del_navegador(): void
    {
        $salida = $this->salida();
        $this->entrega($salida);
        $respuesta = $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('e.persisted', false);
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $oficina = User::factory()->create()->assignRole('facturacion');
        $this->assertStringContainsString('no-store', $this->actingAs($oficina)->get(route('rutas.salidas.show', $salida))->assertOk()->headers->get('Cache-Control'));
    }

    public function test_no_entregado_valida_motivo_y_nota(): void
    {
        $salida = $this->salida();
        $entrega = $this->entrega($salida);
        $url = route('rutas.salidas.entregas.update', [$salida, $entrega]);
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));
        $this->patch($url, ['resultado' => 'no_entregado'])->assertSessionHasErrors('motivo_no_entrega');
        $this->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro'])->assertSessionHasErrors('nota');
        $this->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro', 'nota' => 'Sin sistema'])->assertSessionHas('status');
        $this->assertSame('Sin sistema', $entrega->refresh()->nota);
        $this->assertSame(OrigenRegistroEntrega::Oficina, $entrega->origen_registro);
    }

    public function test_todo_entregado_solo_marca_la_sala_elegida(): void
    {
        $salida = $this->salida();
        $primera = $this->entrega($salida);
        $segunda = $this->entrega($salida);
        $otraSala = $this->sala->cliente->sucursales()->create(['nombre' => 'Otra sala']);
        $otra = $this->entrega($salida, $otraSala);
        $this->post(route('rutas.mi-salida.sala', [$salida, $this->sala]), ['entregado_por_id' => 999])->assertSessionHasErrors('entregado_por_id');
        $this->assertTrue($primera->refresh()->estaPendiente());
        $this->post(route('rutas.mi-salida.sala', [$salida, $this->sala]))->assertSessionHas('status');
        foreach ([$primera, $segunda] as $entrega) {
            $this->assertSame($this->persona->id, $entrega->refresh()->entregado_por_id);
            $this->assertSame(OrigenRegistroEntrega::Vendedor, $entrega->origen_registro);
        }
        $this->assertTrue($otra->refresh()->estaPendiente());
    }

    public function test_todas_las_rutas_get_y_gestion_de_rutas_estan_aisladas(): void
    {
        $comprobadas = 0;
        foreach (Route::getRoutes() as $ruta) {
            $nombre = $ruta->getName();
            if (! $nombre) {
                continue;
            }
            $middleware = $ruta->gatherMiddleware();
            // Públicas/invitados y archivos firmados de Laravel/Livewire; perfil y logout son excepciones de sesión.
            // Verificación y contraseña son la seguridad del propio perfil, sin datos de otras áreas.
            // `salud` es pública a propósito: la pide el vigilante de afuera sin sesión y no trae datos (#163).
            // «Por entregar» y «Sacar pedido» son del vendedor como «Mi salida»: sus permisos los
            // prueban PorEntregarTest y SacarPedidoSinSalidaTest.
            if (in_array('guest', $middleware, true)
                || Str::is(['rutas.mi-salida.*', 'rutas.por-entregar.*', 'rutas.sacar-pedido.*', 'profile.*', 'verification.*', 'password.*', 'logout', 'dashboard', 'storage.local*', 'livewire.preview-file', 'salud'], $nombre)) {
                continue;
            }
            // Dashboard es la puerta de redirección y se verifica por separado.
            $metodos = in_array('GET', $ruta->methods(), true) ? ['GET']
                : (str_starts_with($nombre, 'rutas.') ? array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $ruta->methods()) : []);
            $parametros = array_fill_keys($ruta->parameterNames(), 1);
            foreach ($metodos as $metodo) {
                $respuesta = $this->call($metodo, route($nombre, $parametros));
                $this->assertContains($respuesta->status(), [403, 404], "$metodo $nombre devolvió {$respuesta->status()}");
                $comprobadas++;
            }
        }
        $this->assertGreaterThan(100, $comprobadas);
    }
}
