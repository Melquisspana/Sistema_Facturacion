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
        $this->assertSame(['rutas.entregas.registrar'], RolSistema::Vendedor->permisos());
        foreach (RolSistema::cases() as $rol) {
            $this->assertSame(in_array($rol, [RolSistema::Administrador, RolSistema::Vendedor], true), in_array(PermisoSistema::RutasEntregasRegistrar->value, $rol->permisos(), true));
        }
        $this->assertSame([AreaSistema::Rutas], AreaSistema::visiblesPara($this->usuario));
        $this->assertSame('rutas.mi-salida.index', AreaSistema::Rutas->rutaInicioPara($this->usuario));
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertRedirect(route('rutas.mi-salida.index'));
        $this->followingRedirects()->get('/')->assertOk()->assertSee('No tenés salidas en camino');
    }

    public function test_indice_enlace_vacio_una_y_varias_salidas(): void
    {
        $this->persona->update(['user_id' => null]);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.');
        $this->persona->update(['user_id' => $this->usuario->id]);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('No tenés salidas en camino');
        $una = $this->salida();
        $this->salida(false);
        $this->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $una));
        $otra = $this->salida();
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee(route('rutas.mi-salida.show', $una))->assertSee(route('rutas.mi-salida.show', $otra));
    }

    public function test_policy_y_hoja_compartida_con_numero_final(): void
    {
        $salida = $this->salida();
        $entrega = $this->entrega($salida);
        $ana = PersonalRuta::create(['nombre' => 'Ana']);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->persona->id, $ana->id], null);
        $this->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertSee('Vas con Ana')->assertSee('…0907')
            ->assertSee('title="'.$entrega->dte->numero_control.'"', false)->assertSee('<span class="sr-only sm:not-sr-only sm:break-all">'.$entrega->dte->numero_control.'</span>', false)
            ->assertDontSee('Quitar de la salida')->assertDontSee('¿Quién entrega?')->assertDontSee('Terminar salida')->assertDontSee('sidebar');
        foreach ([EstadoSalidaRuta::Planificada, EstadoSalidaRuta::Finalizada, EstadoSalidaRuta::Cancelada] as $estado) {
            $salida->update(['estado' => $estado]);
            $this->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
            $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado'])->assertForbidden();
            $this->patch(route('rutas.mi-salida.deshacer', [$salida, $entrega]))->assertForbidden();
            $this->post(route('rutas.mi-salida.sala', [$salida, $this->sala]))->assertForbidden();
        }
        $ajena = $this->salida(false);
        $ajenaEntrega = $this->entrega($ajena);
        $this->get(route('rutas.mi-salida.show', $ajena))->assertForbidden();
        $this->patch(route('rutas.mi-salida.update', [$ajena, $ajenaEntrega]))->assertForbidden();
        $salida->update(['estado' => EstadoSalidaRuta::EnCurso]);
        $this->persona->update(['activo' => false]);
        $this->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
        $this->persona->update(['activo' => true]);
        $this->usuario->removeRole('vendedor');
        $this->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
    }

    public function test_registro_forzado_scope_y_deshacer_propio(): void
    {
        $salida = $this->salida();
        $entrega = $this->entrega($salida);
        $ajeno = PersonalRuta::create(['nombre' => 'Otro']);
        $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $ajeno->id, 'trae_nota_averia' => '1'])->assertSessionHas('status');
        $entrega->refresh();
        $this->assertSame($this->persona->id, $entrega->entregado_por_id);
        $this->assertSame($this->usuario->id, $entrega->registrado_por);
        $this->assertSame(OrigenRegistroEntrega::Vendedor, $entrega->origen_registro);
        $this->assertTrue($entrega->trae_nota_averia);
        $this->patch(route('rutas.mi-salida.deshacer', [$salida, $entrega]))->assertSessionHas('status');
        $this->assertTrue($entrega->refresh()->estaPendiente());
        $otra = $this->salida();
        $this->patch(route('rutas.mi-salida.update', [$otra, $entrega]))->assertNotFound();
        $this->patch(route('rutas.mi-salida.deshacer', [$otra, $entrega]))->assertNotFound();
        $oficina = User::factory()->create()->assignRole('facturacion');
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->persona->id, $ajeno->id], null);
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $ajeno->id])->assertSessionHas('status');
        $this->assertSame($ajeno->id, $entrega->refresh()->entregado_por_id);
        $this->actingAs($this->usuario)->patch(route('rutas.mi-salida.deshacer', [$salida, $entrega]))->assertForbidden();
        $this->get(route('rutas.mi-salida.show', $salida))->assertDontSee('Deshacer');
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.deshacer', [$salida, $entrega]))->assertSessionHas('status');
        $this->get(route('rutas.salidas.show', $salida))->assertOk()->assertSee('…0907')->assertSee('title="'.$entrega->dte->numero_control.'"', false);
    }

    public function test_no_entregado_valida_motivo_y_nota(): void
    {
        $salida = $this->salida();
        $entrega = $this->entrega($salida);
        $url = route('rutas.mi-salida.update', [$salida, $entrega]);
        $this->patch($url, ['resultado' => 'no_entregado'])->assertSessionHasErrors('motivo_no_entrega');
        $this->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro'])->assertSessionHasErrors('nota');
        $this->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro', 'nota' => 'Sin sistema'])->assertSessionHas('status');
        $this->assertSame('Sin sistema', $entrega->refresh()->nota);
        $this->assertSame(OrigenRegistroEntrega::Vendedor, $entrega->origen_registro);
    }

    public function test_todo_entregado_solo_marca_la_sala_elegida(): void
    {
        $salida = $this->salida();
        $primera = $this->entrega($salida);
        $segunda = $this->entrega($salida);
        $otraSala = $this->sala->cliente->sucursales()->create(['nombre' => 'Otra sala']);
        $otra = $this->entrega($salida, $otraSala);
        $this->post(route('rutas.mi-salida.sala', [$salida, $this->sala]), ['entregado_por_id' => 999])->assertSessionHas('status');
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
            if (in_array('guest', $middleware, true)
                || Str::is(['rutas.mi-salida.*', 'profile.*', 'verification.*', 'password.*', 'logout', 'dashboard', 'storage.local*', 'livewire.preview-file'], $nombre)) {
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
