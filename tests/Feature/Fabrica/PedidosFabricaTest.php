<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\RolSistema;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La pantalla de la fábrica (/fabrica/pedidos, etapa 1 PR 11): qué falta mandar, «Se manda
 * todo», la hoja «Qué va» que se guarda sola, los envíos en partes que se anulan y no se
 * borran, «No se trae», volver a pendiente y el rol Fábrica, que solo ve Pedidos y no
 * depende del módulo Producción. Todos los datos son inventados.
 */
class PedidosFabricaTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        // En el servidor Producción está apagado: la fábrica no depende de él.
        config()->set('planta.enabled', false);

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Fábrica Inventada'])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);
        $cliente = Cliente::factory()->create(['nombre' => 'Súper Ficticio']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
        $this->otraSala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Inventada Norte', 'codigo' => '9002', 'ruta_id' => $this->ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);

        $this->actingAs($this->fabrica);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, string $fecha = '2026-11-09', ?ClienteSucursal $sala = null, EstadoPedidoSala $estado = EstadoPedidoSala::Registrado): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => $fecha, 'estado' => $estado,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function cargado(PedidoSala $pedido): PedidoSala
    {
        return PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id);
    }

    private function version(PedidoSala $pedido): string
    {
        return $this->cargado($pedido)->versionProduccion();
    }

    private function linea(PedidoSala $pedido, Producto $producto): int
    {
        return $pedido->lineas()->where('producto_id', $producto->id)->value('id');
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $extra = []): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }

        return $this->post(route('fabrica.pedidos.mandar', $pedido), $extra + [
            'clave' => (string) Str::uuid(), 'version' => $this->version($pedido), 'cantidades' => $porLinea,
        ]);
    }

    // ══════════════════════════════ por mandar

    public function test_por_mandar_muestra_lo_pendiente_de_cualquier_fecha_del_mas_viejo_al_mas_nuevo(): void
    {
        $hoy2 = $this->pedido([$this->galleta->id => '5'], '2026-11-09', $this->otraSala);
        $hoy1 = $this->pedido([$this->galleta->id => '24'], '2026-11-09');
        $viejo = $this->pedido([$this->canilla->id => '6'], '2026-11-06');
        $parcial = $this->pedido([$this->galleta->id => '3'], '2026-11-08', $this->otraSala, EstadoPedidoSala::Parcial);
        foreach ([EstadoPedidoSala::Anulado, EstadoPedidoSala::Enviado, EstadoPedidoSala::NoSeTrae] as $estado) {
            $this->pedido([$this->galleta->id => '1'], '2026-11-09', Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala '.$estado->value, 'codigo' => $estado->value]), $estado);
        }

        $respuesta = $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('Por mandar (4)')
            ->assertSeeInOrder(['De días anteriores', 'Del 06/11/2026 · hace 3 días', 'Del 08/11/2026 · hace 1 día', 'De hoy'])
            // La lista es solo de nombres: lo que se manda y «No se trae» van en el pedido abierto.
            ->assertDontSee('Se manda todo')->assertDontSee('Faltan cosas')->assertDontSee('No se trae')
            ->assertDontSee('Sala anulado')->assertDontSee('Sala enviado')->assertDontSee('Sala no_se_trae')
            ->assertDontSee('7.77');
        // Del más viejo al más nuevo; los de hoy, por id.
        $html = $respuesta->getContent();
        $this->assertTrue(strpos($html, 'pedido-'.$viejo->id) < strpos($html, 'pedido-'.$parcial->id));
        $this->assertTrue(strpos($html, 'pedido-'.$parcial->id) < strpos($html, 'pedido-'.$hoy2->id));
        $this->assertTrue(strpos($html, 'pedido-'.$hoy2->id) < strpos($html, 'pedido-'.$hoy1->id));
        $respuesta->assertSee('Mandado en parte');
        $this->get(route('fabrica.pedidos.show', $hoy1))->assertOk()->assertSee('Carlos Inventado')
            ->assertSee('Imprimir y mandar')->assertSee('No mandar nada de este pedido')->assertDontSee('7.77');
        $this->get(route('fabrica.pedidos.show', $parcial))->assertOk()->assertSee('No mandar lo que falta');
    }

    public function test_desde_que_dia_aparecen_segun_el_ajuste(): void
    {
        $this->pedido([$this->galleta->id => '5'], '2026-11-05', $this->otraSala);
        $this->pedido([$this->galleta->id => '5'], '2026-11-09');

        $this->get(route('fabrica.pedidos.index'))->assertSee('Por mandar (2)')->assertSee('Tienda Inventada Norte');
        config()->set('pedidos.fabrica_desde', '2026-11-07');
        $this->get(route('fabrica.pedidos.index'))->assertSee('Por mandar (1)')->assertDontSee('Tienda Inventada Norte')->assertSee('Súper Ficticio Centro');
    }

    // ══════════════════════════════ acceso, rol y menú

    public function test_solo_entra_quien_tiene_el_permiso_tambien_con_la_url_a_mano(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $envio = $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-09', 'registrado_por' => $this->fabrica->id]);
        $rutas = [
            ['get', route('fabrica.pedidos.index')],
            ['get', route('fabrica.pedidos.show', $pedido)],
            ['patch', route('fabrica.pedidos.borrador', $pedido)],
            ['patch', route('fabrica.pedidos.borrador.borrar', $pedido)],
            ['post', route('fabrica.pedidos.mandar', $pedido)],
            ['patch', route('fabrica.pedidos.cerrar', $pedido)],
            ['patch', route('fabrica.pedidos.reabrir', $pedido)],
            ['patch', route('fabrica.pedidos.anular-envio', [$pedido, $envio])],
        ];

        auth()->logout();
        foreach ($rutas as [$metodo, $url]) {
            $this->{$metodo}($url)->assertRedirect(route('login'));
        }
        foreach (['vendedor', 'jefe_vendedores', 'jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol));
            foreach ($rutas as [$metodo, $url]) {
                $this->{$metodo}($url)->assertForbidden();
            }
        }
        // Producción apagado no la cierra: entran Fábrica, Producción y el administrador.
        foreach (['fabrica', 'produccion', 'administrador'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol))
                ->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('Súper Ficticio Centro');
        }
    }

    public function test_el_rol_fabrica_solo_tiene_su_permiso_aterriza_en_pedidos_y_ve_solo_pedidos(): void
    {
        $this->assertSame(['fabrica.pedidos.mandar'], RolSistema::Fabrica->permisos());
        $this->assertContains('fabrica.pedidos.mandar', RolSistema::Produccion->permisos());
        $this->assertContains('fabrica.pedidos.mandar', RolSistema::Administrador->permisos());
        foreach ([RolSistema::Vendedor, RolSistema::JefeVendedores, RolSistema::Jefatura, RolSistema::Facturacion, RolSistema::Contabilidad] as $rol) {
            $this->assertNotContains('fabrica.pedidos.mandar', $rol->permisos());
        }

        $this->get(route('dashboard'))->assertRedirect(route('fabrica.pedidos.index'));
        // Pedidos va a pantalla completa: sin barra lateral (fuera de /fabrica, solo «Pedidos»).
        $html = $this->get(route('fabrica.pedidos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="sidebar-principal"', $html);
        $this->assertStringNotContainsString('data-area-selector', $html);
        $this->assertStringNotContainsString(route('configuracion.resumen'), $html);

        // Nada más del sistema.
        $this->get(route('rutas.pedidos.index'))->assertForbidden();
        $this->get(route('planta.dashboard'))->assertNotFound();
    }

    public function test_el_menu_y_el_layout_no_consultan_tablas_de_pedidos(): void
    {
        $tablas = [];
        DB::listen(function ($q) use (&$tablas) {
            foreach (['pedidos_sala', 'pedido_sala_lineas', 'pedido_sala_envios'] as $tabla) {
                if (str_contains($q->sql, '"'.$tabla.'"')) {
                    $tablas[] = $tabla;
                }
            }
        });
        $this->get(route('profile.edit'))->assertOk();
        $this->assertSame([], $tablas);
    }

    // ══════════════════════════════ mandar

    public function test_se_manda_todo_crea_el_envio_1_con_la_fecha_de_hoy(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12', $this->canilla->id => '6']);

        $respuesta = $this->mandar($pedido, [$this->galleta->id => '12.0000', $this->canilla->id => '6.0000'])
            ->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro.')
            ->assertSessionHas('deshacer', fn ($d) => $d['tipo'] === 'envio');

        $envio = PedidoSalaEnvio::sole();
        // «Imprimir y mandar»: después del envío, su hoja para imprimir.
        $respuesta->assertRedirect(route('fabrica.pedidos.imprimir', [$pedido, $envio]));
        $this->assertSame(1, $envio->numero);
        $this->assertSame('2026-11-09', $envio->fecha->toDateString());
        $this->assertSame($this->fabrica->id, $envio->registrado_por);
        $this->assertSame('12.0000', $envio->lineas->firstWhere('pedido_sala_linea_id', $this->linea($pedido, $this->galleta))->cantidad);
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->estado);
        $this->assertNull($pedido->lineas()->first()->getAttributes()['cantidad_enviada']);
        $auditoria = Activity::where('log_name', 'pedido_sala')->where('description', 'mandó todo el pedido')->sole();
        $this->assertCount(2, $auditoria->properties['lineas']);
        $this->assertSame('registrado', $auditoria->properties['estado_anterior']);

        $this->get(route('fabrica.pedidos.index'))->assertSee('Por mandar (0)')->assertSee('No hay pedidos por mandar.')
            ->assertSee('Deshacer');
    }

    public function test_caso_canillas_lo_que_falta_va_en_otro_envio(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);

        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->assertSee('Qué va a Súper Ficticio Centro')
            ->assertSee('Lo que falta va en otro envío')->assertSee('Por qué no va (opcional)')->assertDontSee('required', false);
        $this->mandar($pedido, [$this->galleta->id => '24', $this->canilla->id => '0'], [
            'motivos' => [$this->linea($pedido, $this->canilla) => '  No salió del horno  '], 'sigue' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Salió una parte de Súper Ficticio Centro. Lo que falta sigue pendiente.');

        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->estado);
        $this->assertSame('No salió del horno', $pedido->lineas()->where('producto_id', $this->canilla->id)->value('motivo_no_enviado'));
        $this->assertTrue(Activity::where('description', 'mandó una parte del pedido')->exists());
        $this->get(route('fabrica.pedidos.index'))->assertSee('Por mandar (1)')->assertSee('Mandado en parte');
        $this->get(route('fabrica.pedidos.show', $pedido))->assertSee('Canilla de prueba')->assertDontSee('Galleta de prueba')
            ->assertSee('No mandar lo que falta');

        // Al día siguiente llegan las canillas: envío 2 y queda «Mandado».
        $this->travelTo('2026-11-10 08:00:00');
        $this->mandar($pedido, [$this->canilla->id => '6'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro.');
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->estado);
        $this->assertSame([1, 2], $pedido->envios()->orderBy('numero')->pluck('numero')->all());
        $this->assertNull($pedido->lineas()->where('producto_id', $this->canilla->id)->value('motivo_no_enviado'));

        // La oficina ve los dos envíos por separado.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('facturacion'));
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertSee('Lo que se despachó')
            ->assertSeeInOrder(['Envío 1', '24', 'Galleta de prueba', 'Envío 2', '6', 'Canilla de prueba'])
            ->assertSee('lo marcó Fábrica Inventada')
            ->assertSee('Pidió')->assertSee('Mandado')->assertSee('Falta')->assertSee('Motivo');
    }

    public function test_por_defecto_mandar_con_faltantes_cierra_y_mandar_lo_que_falto_lo_reabre(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);

        $this->mandar($pedido, [$this->galleta->id => '24', $this->canilla->id => ''], [
            'motivos' => [$this->linea($pedido, $this->canilla) => 'Sin canillas'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro. Lo que faltó no va.');
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->get(route('fabrica.pedidos.index'))->assertSee('Por mandar (0)');

        // En «Hechos» queda «Mandar lo que faltó»: reabre a parcial y abre la hoja.
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()
            ->assertSee('Mandar lo que faltó')->assertSee('Sin canillas');
        $this->patch(route('fabrica.pedidos.reabrir', $pedido), ['abrir' => '1'])
            ->assertRedirect(route('fabrica.pedidos.show', $pedido));
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->assertTrue(Activity::where('description', 'volvió a dejar pendiente el pedido')->exists());

        // La oficina ve lo que falta calculado.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('facturacion'));
        $this->get(route('rutas.pedidos.show', $pedido))->assertSee('Sin canillas');
    }

    public function test_nunca_de_mas_y_los_decimales_suman_sin_float(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12', $this->canilla->id => '5']);
        $this->mandar($pedido, [$this->galleta->id => '8'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $id = $this->linea($pedido, $this->galleta);
        $this->mandar($pedido, [$this->galleta->id => '5'], ['sigue' => '1'])
            ->assertSessionHasErrors(['cantidades.'.$id => 'De Galleta de prueba faltaba mandar 4.']);
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->mandar($pedido, [$this->galleta->id => '4'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, PedidoSalaEnvio::count());

        $this->mandar($pedido, [$this->canilla->id => '2.5'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->mandar($pedido, [$this->canilla->id => '2.5'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
    }

    public function test_validaciones_del_servidor_no_guardan_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $otro = $this->pedido([$this->canilla->id => '3'], '2026-11-09', $this->otraSala);
        $id = $this->linea($pedido, $this->galleta);
        $base = fn () => ['clave' => (string) Str::uuid(), 'version' => $this->version($pedido), 'cantidades' => [$id => '1']];

        $casos = [
            'cantidades.'.$id => [['cantidades' => [$id => '-1']], ['cantidades' => [$id => '1.12345']], ['cantidades' => [$id => '100000']], ['cantidades' => [$id => 'doce']]],
            'cantidades' => [['cantidades' => [$id => '0']], ['cantidades' => [$id => '']]],
            'pedido' => [['cantidades' => [$this->linea($otro, $this->canilla) => '1']]],
            'motivos.'.$id => [['motivos' => [$id => str_repeat('a', 201)]]],
            'clave' => [['clave' => 'no-es-uuid']],
            'version' => [['version' => null], ['version' => 'corta']],
        ];
        foreach ($casos as $campo => $variantes) {
            foreach ($variantes as $cambio) {
                $this->post(route('fabrica.pedidos.mandar', $pedido), array_merge($base(), $cambio))->assertSessionHasErrors($campo);
            }
        }
        $this->assertSame(0, PedidoSalaEnvio::count());
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);

        // El motivo vacío o ausente se acepta.
        $this->post(route('fabrica.pedidos.mandar', $pedido), array_merge($base(), ['cantidades' => [$id => '6'], 'motivos' => [$id => '']]))
            ->assertSessionHasNoErrors();
        $this->assertNull($pedido->lineas()->value('motivo_no_enviado'));
    }

    public function test_un_doble_toque_no_crea_dos_envios(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $id = $this->linea($pedido, $this->galleta);
        $datos = ['clave' => (string) Str::uuid(), 'version' => $this->version($pedido), 'cantidades' => [$id => '12']];

        $this->post(route('fabrica.pedidos.mandar', $pedido), $datos)->assertSessionHasNoErrors();
        $hoja = route('fabrica.pedidos.imprimir', [$pedido, PedidoSalaEnvio::sole()]);
        // El segundo, con el pedido ya mandado: éxito y la hoja del MISMO envío, no 403 ni error.
        $this->post(route('fabrica.pedidos.mandar', $pedido), $datos)->assertRedirect($hoja)
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro.');
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->assertSame(1, Activity::where('description', 'mandó todo el pedido')->count());

        // La misma clave con otras cantidades, otro usuario u otro pedido: error y nada nuevo.
        $this->post(route('fabrica.pedidos.mandar', $pedido), ['cantidades' => [$id => '11']] + $datos)->assertSessionHasErrors('clave');
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('fabrica'))
            ->post(route('fabrica.pedidos.mandar', $pedido), $datos)->assertSessionHasErrors('clave');
        $otro = $this->pedido([$this->galleta->id => '12'], '2026-11-09', $this->otraSala);
        $this->actingAs($this->fabrica)->post(route('fabrica.pedidos.mandar', $otro), $datos)->assertSessionHasErrors('clave');
        $this->assertSame(1, PedidoSalaEnvio::count());
    }

    public function test_con_una_version_vieja_no_se_guarda_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $vieja = $this->version($pedido);
        $id = $this->linea($pedido, $this->galleta);

        // El vendedor corrigió antes de que la fábrica empezara: cambian los id de las líneas.
        $pedido->lineas()->delete();
        $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '12']);
        $this->post(route('fabrica.pedidos.mandar', $pedido), ['clave' => (string) Str::uuid(), 'version' => $vieja, 'cantidades' => [$id => '12']])
            ->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $vieja, 'motivo' => 'No hay producto'])->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);

        // Otra tableta mandó primero una parte.
        $vieja = $this->version($pedido);
        $this->mandar($pedido, [$this->galleta->id => '2'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->post(route('fabrica.pedidos.mandar', $pedido), ['clave' => (string) Str::uuid(), 'version' => $vieja, 'cantidades' => [$this->linea($pedido, $this->galleta) => '1']])
            ->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);

        // Se anuló un envío.
        $vieja = $this->version($pedido);
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, PedidoSalaEnvio::sole()]))->assertSessionHasNoErrors();
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $vieja, 'motivo' => 'No hay producto'])->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);

        // El vendedor lo anuló.
        $vieja = $this->version($pedido);
        $pedido->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->post(route('fabrica.pedidos.mandar', $pedido), ['clave' => (string) Str::uuid(), 'version' => $vieja, 'cantidades' => [$this->linea($pedido, $this->galleta) => '1']])
            ->assertSessionHasErrors(['pedido' => PedidosFabrica::ANULADO]);
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $vieja, 'motivo' => 'No hay producto'])->assertSessionHasErrors(['pedido' => PedidosFabrica::ANULADO]);
    }

    public function test_no_se_trae_y_lo_que_falta_no_se_trae(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $this->version($pedido), 'motivo' => 'No hay producto'])
            ->assertRedirect(route('fabrica.pedidos.index'))
            ->assertSessionHas('status', 'No se manda nada del pedido de Súper Ficticio Centro.')
            ->assertSessionHas('deshacer', ['tipo' => 'cierre', 'pedido' => $pedido->id]);
        $this->assertSame(EstadoPedidoSala::NoSeTrae, $pedido->refresh()->estado);
        $this->assertTrue(Activity::where('description', 'marcó que el pedido no se trae')->exists());

        // Repetirlo no cambia nada; mandar sobre él da un error legible.
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $this->version($pedido), 'motivo' => 'No hay producto'])->assertSessionHas('status', 'Ese pedido ya estaba resuelto.');
        $this->assertSame(1, Activity::where('description', 'marcó que el pedido no se trae')->count());
        $this->mandar($pedido, [$this->galleta->id => '1'])->assertSessionHasErrors(['pedido' => PedidosFabrica::CERRADO]);

        // En un pedido parcial queda «Mandado» con el faltante.
        $parcial = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '4'], '2026-11-09', $this->otraSala);
        $this->mandar($parcial, [$this->galleta->id => '10'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->patch(route('fabrica.pedidos.cerrar', $parcial), ['version' => $this->version($parcial), 'motivo' => 'No hay producto'])
            ->assertSessionHas('status', 'Lo que falta del pedido de Tienda Inventada Norte no se manda.');
        $this->assertSame(EstadoPedidoSala::Enviado, $parcial->refresh()->estado);
        $cierre = Activity::where('description', 'cerró el pedido: lo que falta no se trae')->sole();
        // assertEquals: MySQL reordena las claves de la columna JSON.
        $this->assertEquals([['producto_id' => $this->canilla->id, 'pendiente' => '4.0000']], $cierre->properties['faltantes']);
        $this->mandar($parcial, [$this->canilla->id => '1'])->assertSessionHasErrors(['pedido' => PedidosFabrica::CERRADO]);
    }

    public function test_volver_a_pendiente(): void
    {
        $noSeTrae = $this->pedido([$this->galleta->id => '12']);
        $this->patch(route('fabrica.pedidos.cerrar', $noSeTrae), ['version' => $this->version($noSeTrae), 'motivo' => 'No hay producto']);
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertSee('Volver a pendiente');
        $this->patch(route('fabrica.pedidos.reabrir', $noSeTrae))->assertSessionHas('status', 'El pedido de Súper Ficticio Centro volvió a pendiente.');
        $this->assertSame(EstadoPedidoSala::Registrado, $noSeTrae->refresh()->estado);
        // Ya por mandar: no hace nada.
        $this->patch(route('fabrica.pedidos.reabrir', $noSeTrae))->assertSessionHas('status', 'Ese pedido ya estaba pendiente.');
        $this->assertSame(1, Activity::where('description', 'volvió a dejar pendiente el pedido')->count());

        // Un mandado completo no se reabre: se anula su envío.
        $completo = $this->pedido([$this->galleta->id => '3'], '2026-11-09', $this->otraSala);
        $this->mandar($completo, [$this->galleta->id => '3']);
        $this->patch(route('fabrica.pedidos.reabrir', $completo))->assertForbidden();
        $this->assertSame(EstadoPedidoSala::Enviado, $completo->refresh()->estado);
    }

    public function test_deshacer_un_envio_lo_anula_sin_borrarlo_y_recalcula_el_pedido(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12', $this->canilla->id => '6']);
        $this->mandar($pedido, [$this->galleta->id => '12'], ['sigue' => '1']);
        $this->mandar($pedido, [$this->canilla->id => '6']);
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        [$primero, $segundo] = $pedido->envios()->orderBy('numero')->get()->all();

        // Con otro envío vigente queda «Mandado en parte», aunque estuviera mandado.
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $segundo]))
            ->assertSessionHas('status', 'Envío 2 de Súper Ficticio Centro deshecho. Lo que llevaba vuelve a quedar pendiente.');
        $this->assertDatabaseHas('pedido_sala_envios', ['id' => $segundo->id, 'anulado_por' => $this->fabrica->id]);
        $this->assertNotNull($segundo->refresh()->anulado_en);
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->assertSame('6.0000', $this->cargado($pedido)->lineas->firstWhere('producto_id', $this->canilla->id)->pendiente());
        $this->assertTrue(Activity::where('description', 'anuló un envío del pedido')->exists());

        // Dos veces no hace nada; sin envíos vigentes vuelve a pendiente.
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $segundo]))->assertSessionHas('status', 'Ese envío ya estaba deshecho.');
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $primero]))->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame(2, PedidoSalaEnvio::count());
        $this->assertSame(2, Activity::where('description', 'anuló un envío del pedido')->count());

        // En «Hechos» los anulados se ven tachados con quién los anuló.
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()
            ->assertSee('Anulado por Fábrica Inventada el 09/11/2026')->assertSee('line-through', false);

        // Un envío de otro pedido en la URL: 404. Uno de ayer: 403.
        $otro = $this->pedido([$this->galleta->id => '1'], '2026-11-09', $this->otraSala);
        $this->patch(route('fabrica.pedidos.anular-envio', [$otro, $primero]))->assertNotFound();
        $this->mandar($pedido, [$this->galleta->id => '1'], ['sigue' => '1']);
        $tercero = $pedido->envios()->where('numero', 3)->sole();
        $this->travelTo('2026-11-10 08:00:00');
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $tercero]))->assertForbidden();
        $this->assertTrue($tercero->refresh()->estaVigente());
    }

    public function test_no_se_deshace_un_envio_facturado_pero_si_uno_con_su_borrador_borrado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12']);
        $envio = PedidoSalaEnvio::sole();
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => EstadoDte::Borrador->value,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id,
            'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        $envio->update(['dte_id' => $dte->id]);

        // Ni la política (el botón) ni el servicio: la oficina ya lo facturó.
        $this->assertFalse($this->fabrica->can('anular', $envio->refresh()));
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $envio]))
            ->assertSessionHasErrors(['envio' => PedidosFabrica::FACTURADO]);
        $this->assertTrue($envio->refresh()->estaVigente());
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertFalse(Activity::where('description', 'anuló un envío del pedido')->exists());

        // Con el borrador borrado vuelve a «Por facturar» y se puede deshacer el mismo día.
        $dte->delete();
        $this->assertTrue($this->fabrica->can('anular', $envio->refresh()));
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $envio]))->assertSessionHasNoErrors();
        $this->assertFalse($envio->refresh()->estaVigente());
    }

    public function test_hechos_dice_por_que_no_se_puede_deshacer_lo_que_ya_se_facturo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12']);
        $envio = PedidoSalaEnvio::sole();
        $hechos = route('fabrica.pedidos.index', ['ver' => 'hechos']);
        $this->get($hechos)->assertOk()->assertSee('Deshacer</button>', false)->assertDontSee(PedidosFabrica::FACTURADO);

        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => EstadoDte::Borrador->value,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id,
            'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        $envio->update(['dte_id' => $dte->id]);

        // La oficina ya lo facturó: sin «Deshacer», y la fábrica sabe a quién avisar.
        $this->get($hechos)->assertOk()->assertDontSee('Deshacer</button>', false)->assertSee(PedidosFabrica::FACTURADO);
    }

    public function test_la_hoja_de_un_pedido_ya_resuelto_vuelve_a_la_lista(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12']);

        $this->get(route('fabrica.pedidos.show', $pedido))
            ->assertRedirect(route('fabrica.pedidos.index'))->assertSessionHas('status', 'Ese pedido ya está resuelto.');
    }

    // ══════════════════════════════ borrador

    public function test_lo_marcado_se_guarda_solo_se_conserva_y_se_manda(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);
        $otro = $this->pedido([$this->galleta->id => '5'], '2026-11-09', $this->otraSala);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        $version = $this->version($pedido);

        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '24', $c => '0'], 'motivos' => [$c => 'Sin canillas']])
            ->assertOk()->assertJson(['guardado' => true]);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '20', $c => '0'], 'motivos' => [$c => 'Sin canillas']])->assertOk();
        $pedido->refresh();
        $this->assertNotNull($pedido->armando_desde);
        $this->assertSame($this->fabrica->id, $pedido->armando_por);
        $this->assertSame('20.0000', $pedido->lineas()->whereKey($g)->value('va_borrador'));
        // El motivo marcado va aparte: el oficial lo escribe recién el envío.
        $this->assertSame('Sin canillas', $pedido->lineas()->whereKey($c)->value('motivo_borrador'));
        $this->assertNull($pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));
        // Se audita una vez, no cada cambio; y el borrador no invalida la tarjeta de otra tableta.
        $this->assertSame(1, Activity::where('description', 'empezó a armar el pedido')->count());
        $this->assertSame($version, $this->version($pedido));

        // Se arma otro a la vez; al volver, la hoja muestra lo guardado y la tarjeta, «Armando».
        $this->patchJson(route('fabrica.pedidos.borrador', $otro), ['cantidades' => [$this->linea($otro, $this->galleta) => '3']])->assertOk();
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('value="20"', false)->assertSee('value="Sin canillas"', false)->assertSee('Borrar lo marcado');
        $this->get(route('fabrica.pedidos.index'))->assertSee('Armando');

        // Validaciones de cada guardado: la línea es del pedido y no pasa de lo pendiente.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$this->linea($otro, $this->galleta) => '1']])
            ->assertStatus(422)->assertJsonValidationErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '25']])
            ->assertStatus(422)->assertJsonValidationErrors('cantidades.'.$g);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '-1']])->assertStatus(422);
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('vendedor'))
            ->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '1']])->assertForbidden();
        $this->actingAs($this->fabrica);
        $this->assertSame('20.0000', $pedido->lineas()->whereKey($g)->value('va_borrador'));

        // «Mandar» crea el envío y limpia el borrador y el armado.
        $this->mandar($pedido, [$this->galleta->id => '20', $this->canilla->id => '0'], ['motivos' => [$c => 'Sin canillas']])->assertSessionHasNoErrors();
        $pedido->refresh();
        $this->assertNull($pedido->armando_desde);
        $this->assertNull($pedido->armando_por);
        $this->assertSame(0, $pedido->lineas()->whereNotNull('va_borrador')->count());
        $this->assertSame(0, $pedido->lineas()->whereNotNull('motivo_borrador')->count());
        $this->assertSame('Sin canillas', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '1']])
            ->assertStatus(422)->assertJsonValidationErrors(['pedido' => PedidosFabrica::CERRADO]);

        // «Borrar lo marcado» limpia el del otro, con auditoría.
        $this->patch(route('fabrica.pedidos.borrador.borrar', $otro))->assertRedirect(route('fabrica.pedidos.show', $otro))
            ->assertSessionHas('status', 'Se borró lo marcado.');
        $otro->refresh();
        $this->assertNull($otro->armando_desde);
        $this->assertNull($otro->lineas()->value('va_borrador'));
        $this->assertTrue(Activity::where('description', 'borró lo marcado del pedido')->exists());
    }

    // ══════════════════════════════ hechos y consultas

    public function test_hechos_hoy_muestra_los_envios_con_deshacer(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12']);

        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()
            ->assertSee('Envíos de hoy')->assertSee('Envío 1 · Súper Ficticio Centro')
            ->assertSee('lo marcó Fábrica Inventada')->assertSee('Galleta de prueba')
            ->assertSee('¿Deshacer este envío? Lo que llevaba vuelve a quedar pendiente.');

        $this->travelTo('2026-11-10 08:00:00');
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()->assertSee('Hoy todavía no salió nada.');
        $this->get(route('fabrica.pedidos.index', ['ver' => 'otra']))->assertSessionHasErrors('ver');
    }

    public function test_la_lista_no_hace_una_consulta_por_pedido(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('fabrica.pedidos.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->pedido([$this->galleta->id => '1', $this->canilla->id => '2']);
        $this->pedido([$this->galleta->id => '1'], '2026-11-08', $this->otraSala);
        $contar(); // la primera carga los permisos y los ajustes en caché
        $pocos = $contar();

        for ($i = 0; $i < 18; $i++) {
            $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba '.$i, 'codigo' => 'S'.$i]);
            $p = $this->pedido([$this->galleta->id => '3', $this->canilla->id => '1'], '2026-11-0'.(1 + $i % 9), $sala);
            $p->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-09', 'registrado_por' => $this->fabrica->id])
                ->lineas()->create(['pedido_sala_linea_id' => $p->lineas()->first()->id, 'cantidad' => '1']);
            $p->update(['estado' => EstadoPedidoSala::Parcial]);
        }
        $this->assertSame($pocos, $contar());
    }

    // ══════════════════════════════ revisión: hallazgos corregidos

    /**
     * Lo que manda el navegador con el formulario que apunta a $accion (el primero que
     * aparezca): sus campos con valor, y las casillas solo si están marcadas.
     *
     * @return array<string, mixed>
     */
    private function camposDelFormulario(string $html, string $accion): array
    {
        $this->assertSame(1, preg_match('/<form[^>]*action="'.preg_quote($accion, '/').'"[^>]*>(.*?)<\/form>/s', $html, $form));
        preg_match_all('/<input\b[^>]*>/', $form[1], $etiquetas);
        $datos = [];
        foreach ($etiquetas[0] as $etiqueta) {
            if (! preg_match('/\bname="([^"]+)"/', $etiqueta, $nombre)
                || (str_contains($etiqueta, 'type="checkbox"') && ! preg_match('/\schecked\b/', $etiqueta))) {
                continue;
            }
            $valor = preg_match('/\bvalue="([^"]*)"/', $etiqueta, $v) ? html_entity_decode($v[1]) : '';
            parse_str($nombre[1].'='.urlencode($valor), $uno);
            $datos = array_replace_recursive($datos, $uno);
        }

        return $datos;
    }

    public function test_despues_del_aviso_de_cambio_la_hoja_trae_la_version_nueva_y_se_puede_mandar(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);
        $vieja = $this->version($pedido);
        $c = $this->linea($pedido, $this->canilla);

        // Otra tableta manda una parte mientras esta tenía la hoja abierta.
        $this->mandar($pedido, [$this->galleta->id => '24', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $nueva = $this->version($pedido);

        $this->from(route('fabrica.pedidos.show', $pedido))
            ->post(route('fabrica.pedidos.mandar', $pedido), ['clave' => (string) Str::uuid(), 'version' => $vieja, 'cantidades' => [$c => '6']])
            ->assertRedirect(route('fabrica.pedidos.show', $pedido))
            ->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);

        // La hoja vuelve con la versión NUEVA (no la que venía en el formulario) y se puede mandar.
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->assertSee(PedidosFabrica::CAMBIO)->getContent();
        $this->assertStringContainsString('name="version" value="'.$nueva.'"', $html);
        $this->assertStringNotContainsString($vieja, $html);
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->camposDelFormulario($html, route('fabrica.pedidos.mandar', $pedido)))
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertSame(2, $pedido->envios()->count());
    }

    public function test_el_borrador_no_pisa_el_motivo_del_envio_anterior_y_cerrar_no_deja_motivos_del_borrador(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5', $this->canilla->id => '6']);
        $c = $this->linea($pedido, $this->canilla);
        $this->mandar($pedido, [$this->galleta->id => '5', $this->canilla->id => '0'], ['motivos' => [$c => 'sin harina'], 'sigue' => '1'])
            ->assertSessionHasNoErrors();

        // Al día siguiente la hoja trae «Va» = lo pendiente y el guardado automático lo manda.
        $this->travelTo('2026-11-10 08:00:00');
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '6'], 'motivos' => [$c => 'sin harina']])->assertOk();
        $this->assertSame('sin harina', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));

        // «Lo que falta no se trae»: el motivo oficial sigue siendo el del envío 1.
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $this->version($pedido), 'motivo' => 'No hay producto'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertSame('sin harina', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));

        // Lo marcado y abandonado no queda como motivo de un pedido que no se trae.
        $otro = $this->pedido([$this->galleta->id => '6'], '2026-11-10', $this->otraSala);
        $g = $this->linea($otro, $this->galleta);
        $this->patchJson(route('fabrica.pedidos.borrador', $otro), ['cantidades' => [$g => '2'], 'motivos' => [$g => 'marcado y abandonado']])->assertOk();
        $this->patch(route('fabrica.pedidos.cerrar', $otro), ['version' => $this->version($otro), 'motivo' => 'No hay producto'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::NoSeTrae, $otro->refresh()->estado);
        $this->assertNull($otro->lineas()->whereKey($g)->value('motivo_no_enviado'));
    }

    public function test_borrar_lo_marcado_no_borra_el_motivo_del_envio_anterior(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5', $this->canilla->id => '6']);
        $c = $this->linea($pedido, $this->canilla);
        $this->mandar($pedido, [$this->galleta->id => '5', $this->canilla->id => '0'], ['motivos' => [$c => 'No salió del horno'], 'sigue' => '1'])
            ->assertSessionHasNoErrors();

        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '2'], 'motivos' => [$c => 'Solo dos por ahora']])->assertOk();
        $this->get(route('fabrica.pedidos.show', $pedido))->assertSee('value="Solo dos por ahora"', false);
        $this->assertSame('No salió del horno', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));

        $this->patch(route('fabrica.pedidos.borrador.borrar', $pedido))->assertSessionHas('status', 'Se borró lo marcado.');
        $this->assertSame('No salió del horno', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));
        $this->get(route('fabrica.pedidos.show', $pedido))->assertSee('value="No salió del horno"', false);
    }

    public function test_el_pedido_que_se_esta_armando_manda_lo_marcado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '24', $c => '0'], 'motivos' => [$c => 'Sin canillas']])->assertOk();

        // Desde la lista se abre el pedido (ya no se manda desde la tarjeta); lo que se manda
        // es LO MARCADO, no todo lo que falta.
        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('Armando')->assertDontSee('Se manda todo');
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->camposDelFormulario($html, route('fabrica.pedidos.mandar', $pedido)))
            ->assertSessionHasNoErrors();

        $envio = PedidoSalaEnvio::with('lineas')->sole();
        $this->assertSame([$g], $envio->lineas->pluck('pedido_sala_linea_id')->all());
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->estado);
        $this->assertTrue($this->cargado($pedido)->faltaAlgo());
        $this->assertSame('Sin canillas', $pedido->lineas()->whereKey($c)->value('motivo_no_enviado'));
    }

    public function test_la_casilla_lo_que_falta_va_en_otro_envio_se_guarda_con_el_borrador(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);

        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '24', $c => '0'], 'sigue' => '1'])->assertOk();
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="sigue" value="1"[^>]*\schecked\b/', $html);
        // «Imprimir y mandar» la respeta: lo que falta sigue por mandar.
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->camposDelFormulario($html, route('fabrica.pedidos.mandar', $pedido)))
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->assertFalse($pedido->armando_sigue);

        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '0']])->assertOk();
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="sigue" value="1"[^>]*\schecked\b/', $html);
    }

    public function test_la_lista_deja_de_recargarse_si_nadie_la_usa_salvo_en_la_tableta_de_la_fabrica(): void
    {
        config()->set('session.lifetime', 30);

        // La tableta de la fábrica (solo ve Pedidos) se recarga sin tope.
        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('limite: null', false);

        // Quien ve más del sistema deja de recargar antes de que venza su sesión.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));
        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('limite: 1740000', false)->assertSee('sessionStorage', false);
    }

    public function test_la_recarga_cuenta_la_rueda_y_el_movimiento_del_mouse(): void
    {
        $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('@wheel.window', false)->assertSee('@pointermove.window', false);
    }

    public function test_la_hoja_guarda_con_un_solo_retardo_y_lo_pendiente_antes_de_salir(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24']);

        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertDontSee('debounce', false)
            ->assertSee('@pagehide.window', false)->assertSee('keepalive', false);
    }

    public function test_el_guardado_automatico_muestra_sus_errores_en_castellano(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24']);

        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('Se cerró la sesión. Volvé a entrar.')
            ->assertDontSee('j.message', false);
    }

    public function test_el_rol_fabrica_ve_solo_pedidos_tambien_fuera_de_fabrica(): void
    {
        $html = $this->get(route('profile.edit'))->assertOk()->getContent();
        preg_match('/<aside id="sidebar-principal".*?<\/aside>/s', $html, $aside);
        preg_match_all('/href="([^"]+)"/', $aside[0], $enlaces);
        $this->assertSame([route('fabrica.pedidos.index')], array_values(array_unique($enlaces[1])));
    }
}
