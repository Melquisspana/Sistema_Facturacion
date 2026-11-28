<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosSala;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Sacar pedido sin salida armada (Melqui, 10/11/2026): el vendedor busca cualquier sala, saca
 * el pedido y el pedido se suma solo a su viaje. El inicio muestra el viaje y los dos botones.
 * Datos ficticios.
 */
class SacarPedidoSinSalidaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);

        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Oriente', 'codigo' => '7001', 'ruta_id' => $ruta->id, 'departamento_id' => $dep->id]);
        // Sin ruta: cualquier sala sirve, no solo las de una ruta.
        $this->otraSala = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba Centro', 'codigo' => '7002', 'departamento_id' => $dep->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->actingAs($this->vendedor);
    }

    private function sacar(ClienteSucursal $sala, array $cantidades = []): TestResponse
    {
        return $this->post(route('rutas.sacar-pedido.store', $sala), ['cantidades' => $cantidades ?: [$this->galleta->id => '12']]);
    }

    /** La auditoría va en una columna JSON y MySQL reordena las claves: se comparan ordenadas. */
    private function conClavesOrdenadas(array $filas): array
    {
        return array_map(function (array $fila): array {
            ksort($fila);

            return $fila;
        }, $filas);
    }

    public function test_el_vendedor_entra_al_inicio_sin_viaje_con_los_dos_botones(): void
    {
        $this->get('/dashboard')->assertRedirect(route('rutas.mi-salida.index'));
        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('Todavía no tenés un viaje abierto')
            ->assertSee('Se abre solo con el primer pedido que saqués.')
            ->assertSee('href="'.route('rutas.sacar-pedido.index').'"', false)
            ->assertSee('href="'.route('rutas.por-entregar.index').'"', false)
            ->assertDontSee('¿Mañana seguís en la misma zona?');
    }

    /**
     * Las consultas de `guardar()` desde que abre su transacción. En MySQL la primera lectura
     * sin candado fija la foto de la transacción: si va antes de los candados, el segundo
     * vendedor no ve el pedido que el primero acaba de guardar y la sala queda con dos.
     *
     * @return list<string>
     */
    private function consultasAlGuardar(?ClienteSucursal $sala, array $datos): array
    {
        $consultas = null;
        Event::listen(TransactionBeginning::class, function () use (&$consultas) {
            $consultas ??= [];
        });
        DB::listen(function (QueryExecuted $q) use (&$consultas) {
            if ($consultas !== null) {
                $consultas[] = $q->sql;
            }
        });
        app(PedidosSala::class)->guardar($sala, $datos + ['lineas' => [['producto_id' => $this->galleta->id, 'cantidad' => '12']]], $this->vendedor);

        return $consultas;
    }

    public function test_al_sacar_pedido_lo_primero_son_los_candados_de_la_persona_y_la_sala(): void
    {
        $consultas = $this->consultasAlGuardar($this->sala, []);

        // Con comillas (SQLite) o backticks (MySQL).
        $this->assertMatchesRegularExpression('/^select \* from ["`]rutas_personal["`] where ["`]rutas_personal["`]\.["`]id["`] = \?/', $consultas[0], 'Primero, el candado de la persona.');
        $this->assertMatchesRegularExpression('/^select \* from ["`]cliente_sucursales["`] where ["`]cliente_sucursales["`]\.["`]id["`] = \?/', $consultas[1], 'Después, el de la sala.');
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringEndsWith('for update', $consultas[0]);
            $this->assertStringEndsWith('for update', $consultas[1]);
        }
        $this->assertSame(1, PedidoSala::count());
    }

    public function test_la_tienda_nueva_tambien_empieza_por_el_candado_de_la_persona(): void
    {
        $consultas = $this->consultasAlGuardar(null, ['sala_nueva_nombre' => 'Tienda La Chapina']);

        $this->assertMatchesRegularExpression('/^select \* from ["`]rutas_personal["`] where ["`]rutas_personal["`]\.["`]id["`] = \?/', $consultas[0]);
    }

    public function test_el_doble_toque_en_la_tienda_nueva_no_crea_dos_pedidos(): void
    {
        $guardar = fn (string $nombre) => $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => $nombre, 'cantidades' => [$this->galleta->id => '12']]);

        $guardar('Tienda La Chapina')->assertSessionHasNoErrors();
        // El segundo toque (o el mismo nombre con otras mayúsculas y espacios): no crea otro.
        $guardar('Tienda La Chapina')->assertSessionHasErrors(['lineas' => 'El pedido de «Tienda La Chapina» ya está sacado. Si cambió algo, corregilo.']);
        $guardar('  tienda   la CHAPINA ')->assertSessionHasErrors('lineas');
        $this->assertSame(1, PedidoSala::count());

        // Otra tienda, otro vendedor o el mismo nombre otro día sí son otro pedido.
        $guardar('Tienda El Roble')->assertSessionHasNoErrors();
        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Dora Ficticia', 'user_id' => $otro->id]);
        $this->actingAs($otro)->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => 'Tienda La Chapina', 'cantidades' => [$this->galleta->id => '6']])
            ->assertSessionHasNoErrors();
        $this->travelTo('2026-11-10 09:00:00');
        $this->actingAs($this->vendedor);
        $guardar('Tienda La Chapina')->assertSessionHasNoErrors();
        $this->assertSame(4, PedidoSala::count());

        // Anulado el de hoy, se saca de nuevo.
        PedidoSala::where('sacado_por_id', $this->persona->id)->whereDate('fecha', '2026-11-10')->sole()->update(['estado' => EstadoPedidoSala::Anulado]);
        $guardar('Tienda La Chapina')->assertSessionHasNoErrors();
        $this->assertSame(5, PedidoSala::count());
    }

    public function test_busca_cualquier_sala_y_saca_el_pedido_sin_salida_armada(): void
    {
        $this->assertSame(0, SalidaRuta::count());
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'tienda']))->assertOk()
            ->assertSee('Tienda de prueba Centro')->assertDontSee('Sala de prueba Oriente')
            ->assertSee(route('rutas.sacar-pedido.create', $this->otraSala));
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'x']))->assertOk()->assertSee('Escribí al menos 2 letras.');

        $this->get(route('rutas.sacar-pedido.create', $this->otraSala))->assertOk()
            ->assertSee('Pedido de Tienda de prueba Centro')->assertDontSee('Sugerido')->assertSee('Galleta de prueba')->assertSee('Tu primer pedido abre tu viaje.');
        $this->sacar($this->otraSala)->assertSessionHasNoErrors()
            ->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('status', 'Pedido de «Tienda de prueba Centro» guardado. Se abrió tu viaje: Lunes · Sin zona.');

        $pedido = PedidoSala::sole();
        $viaje = SalidaRuta::sole();
        $this->assertTrue($viaje->esViaje());
        $this->assertSame($viaje->id, $pedido->salida_ruta_id);
        $this->assertSame($this->persona->id, $pedido->sacado_por_id);
        $this->assertSame($this->vendedor->id, $pedido->registrado_por);
        $this->assertNull($pedido->ruta_id);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $actividad = Activity::where('log_name', 'pedido_sala')->sole();
        $this->assertSame('sacó el pedido de la sala', $actividad->description);
        $this->assertSame($viaje->id, $actividad->properties['salida_ruta_id']);
        $this->assertSame($this->galleta->id, $actividad->properties['lineas'][0]['producto_id']);

        // El segundo pedido, en una sala de ruta, va al mismo viaje y le da nombre.
        $this->sacar($this->sala)->assertSessionHas('status', 'Pedido de «Sala de prueba Oriente» guardado.');
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame($viaje->id, PedidoSala::latest('id')->first()->salida_ruta_id);

        // Una vez por sala: el segundo intento no crea otro.
        $this->sacar($this->sala)->assertSessionHasErrors('lineas');
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertRedirect(route('rutas.sacar-pedido.edit', PedidoSala::latest('id')->first()));
        $this->assertSame(2, PedidoSala::count());

        // La lista muestra los pedidos de hoy con Corregir.
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Tus pedidos de hoy')->assertSee('Tienda de prueba Centro')->assertSee('Corregir');
    }

    public function test_el_inicio_muestra_el_viaje_y_terminar_el_dia(): void
    {
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $this->travelTo('2026-11-10 08:00:00');
        $this->sacar($this->otraSala)->assertSessionHasNoErrors();
        $viaje = SalidaRuta::sole();

        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('Tu viaje')->assertSee('Lunes · San Miguel')->assertSee('Día 2 · 2 salas con pedido')
            // El viaje no tiene hoja propia: lo suyo está en «Sacar pedido» y «Por entregar».
            ->assertDontSee('href="'.url('/rutas/mi-salida/'.$viaje->id).'"', false)
            ->assertSee('href="'.route('rutas.mi-salida.dia').'"', false);
        $this->get('/rutas/mi-salida/'.$viaje->id)->assertNotFound();
        // La pregunta está en «Terminar el día», con el resumen y el PDF.
        $this->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSee('¿Mañana seguís en la misma zona?')
            ->assertSee('action="'.route('rutas.mi-salida.terminar-dia', $viaje).'"', false);
        // Lo sacado hoy está en «Sacar pedido».
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('Lunes · San Miguel')
            ->assertSee('Tus pedidos de hoy');

        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0'])
            ->assertRedirect(route('rutas.mi-salida.index'))
            ->assertSessionHas('status', 'Listo. Este viaje ya no suma pedidos; queda abierto para entregar.');
        $this->assertFalse($viaje->refresh()->suma_pedidos);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Lunes · San Miguel')->assertSee('Ya no suma pedidos');
        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertDontSee('¿Mañana seguís en la misma zona?');
        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => 'quizas'])->assertSessionHasErrors('sigue');

        // El próximo pedido abre otro viaje.
        $this->travelTo('2026-11-11 08:00:00');
        $this->sacar($this->sala)->assertSessionHas('status', fn ($m) => str_contains($m, 'Se abrió tu viaje: Miércoles · San Miguel.'));
        $this->assertSame(2, SalidaRuta::count());
    }

    /** Pedidos anteriores de la sala, de galletas (tres o más: ya hay con qué comparar). */
    private function historia(array $cantidades = ['2026-10-20' => '10', '2026-10-27' => '10', '2026-11-03' => '10']): void
    {
        foreach ($cantidades as $fecha => $cantidad) {
            PedidoSala::create(['cliente_sucursal_id' => $this->sala->id, 'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
                'fecha' => $fecha, 'estado' => EstadoPedidoSala::Enviado])->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => $cantidad]);
        }
    }

    /**
     * Antes se guardaba y después avisaba «Revisá: … lleva 1,000». Desde el 16/11/2026 (Melqui)
     * la cantidad exagerada no se guarda sin confirmar: el 500 en vez de 5.
     */
    public function test_una_cantidad_exagerada_no_se_guarda_sin_confirmar(): void
    {
        $this->historia();
        $chocolate = Producto::factory()->create(['nombre' => 'Chocolate de prueba', 'codigo' => 'C01']);
        $formulario = route('rutas.sacar-pedido.create', $this->sala);
        $cantidades = [$this->galleta->id => '1000', $chocolate->id => '500'];

        // 1000 en vez de 10 (más del triple de lo habitual) y 500 de lo que nunca pidió (más de
        // 200): no se guarda, ni abre el viaje, y vuelve al formulario.
        $this->from($formulario)->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => $cantidades])
            ->assertRedirect($formulario)
            ->assertSessionHasErrors(['confirmar_cantidades' => 'Galleta de prueba: 1,000 (esta sala suele llevar 10)'])
            ->assertSessionHasErrors(['confirmar_cantidades' => 'Chocolate de prueba: 500 (es más de 200)'])
            ->assertSessionMissing('borrador_guardado');
        $this->assertSame(3, PedidoSala::count());
        $this->assertSame(0, SalidaRuta::count());

        // El formulario vuelve con el aviso, lo escrito y la casilla.
        $this->get($formulario)->assertOk()
            ->assertSeeInOrder(['Revisá estas cantidades antes de guardar:', 'Galleta de prueba: 1,000 (esta sala suele llevar 10)', 'Chocolate de prueba: 500 (es más de 200)'])
            ->assertSee('name="confirmar_cantidades" value="1"', false)->assertSee('Sí, las cantidades están bien')
            ->assertSee('value="1000"', false)->assertSee('value="500"', false);

        // «0» no confirma.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => $cantidades, 'confirmar_cantidades' => '0'])
            ->assertSessionHasErrors('confirmar_cantidades');
        $this->assertSame(3, PedidoSala::count());

        // Confirmado: se guarda, sin el aviso de después, y la auditoría dice qué se confirmó.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => $cantidades, 'confirmar_cantidades' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('status', fn (string $s) => str_starts_with($s, 'Pedido de «Sala de prueba Oriente» guardado.') && ! str_contains($s, 'Revisá'));
        $pedido = PedidoSala::latest('id')->first();
        $this->assertSame(['1000.0000', '500.0000'], $pedido->lineas()->orderBy('id')->pluck('cantidad')->all());
        $this->assertSame($this->conClavesOrdenadas([
            ['producto_id' => $this->galleta->id, 'cantidad' => '1000', 'suele' => '10.0000'],
            ['producto_id' => $chocolate->id, 'cantidad' => '500', 'suele' => null],
        ]), $this->conClavesOrdenadas(Activity::where('description', 'sacó el pedido de la sala')->sole()->properties['cantidades_confirmadas']));

        // La oficina, al corregir, sigue con el aviso de después: solo lo que tiene con qué compararse.
        $this->assertSame(['Revisá: Galleta de prueba lleva 1,000 y esta sala suele llevar 10.'], app(PedidosSala::class)->avisosCantidad($pedido));
    }

    public function test_una_cantidad_normal_se_guarda_como_siempre_sin_la_casilla(): void
    {
        $this->historia();
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk()
            ->assertDontSee('name="confirmar_cantidades"', false)->assertDontSee('Sí, las cantidades están bien');

        // Hasta el triple de lo habitual, y hasta 200 sin historia: se guarda sin preguntar.
        $this->sacar($this->sala, [$this->galleta->id => '30'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => ! str_contains($s, 'Revisá'));
        $this->sacar($this->otraSala, [$this->galleta->id => '200'])->assertSessionHasNoErrors();
        $this->assertSame(5, PedidoSala::count());
        $this->assertFalse(Activity::where('description', 'sacó el pedido de la sala')->get()
            ->contains(fn (Activity $a) => $a->properties->has('cantidades_confirmadas')));
    }

    public function test_se_compara_con_la_mediana_aunque_la_ultima_vez_haya_llevado_mas(): void
    {
        // 10, 10 y 100: lo habitual es 10. Un 100 nuevo pide confirmar aunque la última vez llevó 100.
        $this->historia(['2026-10-20' => '10', '2026-10-27' => '10', '2026-11-03' => '100']);

        $this->sacar($this->sala, [$this->galleta->id => '100'])
            ->assertSessionHasErrors(['confirmar_cantidades' => 'Galleta de prueba: 100 (esta sala suele llevar 10)']);
        $this->assertSame(3, PedidoSala::count());
    }

    public function test_corregir_y_la_tienda_nueva_tambien_piden_confirmar(): void
    {
        // Sin historia, el tope fijo: más de 200.
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '500']])
            ->assertSessionHasErrors(['confirmar_cantidades' => 'Galleta de prueba: 500 (es más de 200)']);
        $this->assertSame('12.0000', $pedido->refresh()->lineas->sole()->cantidad);

        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '500'], 'confirmar_cantidades' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Pedido de «Sala de prueba Oriente» corregido.');
        $this->assertSame('500.0000', $pedido->refresh()->lineas->sole()->cantidad);
        $this->assertSame($this->conClavesOrdenadas([['producto_id' => $this->galleta->id, 'cantidad' => '500', 'suele' => null]]),
            $this->conClavesOrdenadas(Activity::where('description', 'corrigió el pedido de la sala')->sole()->properties['cantidades_confirmadas']));

        $tiendaNueva = fn (array $extra = []) => $this->post(route('rutas.sacar-pedido.tienda-nueva.store'),
            ['sala_nueva_nombre' => 'Tienda de prueba Nueva', 'cantidades' => [$this->galleta->id => '201']] + $extra);
        $tiendaNueva()->assertSessionHasErrors(['confirmar_cantidades' => 'Galleta de prueba: 201 (es más de 200)']);
        $this->assertSame(1, PedidoSala::count());
        $tiendaNueva(['confirmar_cantidades' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, PedidoSala::count());
    }

    /**
     * El borrador del teléfono (16/11/2026): la clave lleva el usuario, la sala (o el pedido que
     * se corrige, o la tienda nueva) y el día. Se borra solo cuando el servidor guardó: lo
     * avisa a la página siguiente con un dato de sesión de una vez.
     */
    public function test_al_guardar_la_pagina_siguiente_borra_el_borrador_del_telefono(): void
    {
        $clave = 'pedido-borrador:'.$this->vendedor->id.':sala-'.$this->sala->id.':2026-11-09';
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk()
            ->assertSee('data-borrador="'.$clave.'"', false)
            ->assertSee('Tenés un pedido sin mandar de las')->assertSee('>Recuperar</button>', false)->assertSee('>Descartar</button>', false)
            ->assertSee('Sin señal: el pedido quedó guardado en este teléfono. Mandalo cuando vuelva la señal.');

        // Con un error no se guardó: el borrador queda.
        $this->sacar($this->sala, [$this->galleta->id => '0'])->assertSessionHasErrors()->assertSessionMissing('borrador_guardado');

        $this->sacar($this->sala)->assertSessionHasNoErrors()->assertSessionHas('borrador_guardado', $clave);
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('localStorage.removeItem(\''.$clave.'\')', false);
        // Es de una vez: la página que sigue ya no lo trae.
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertDontSee($clave, false);

        // Corregir usa la clave del pedido; la tienda nueva, la suya.
        $pedido = PedidoSala::sole();
        $corregido = 'pedido-borrador:'.$this->vendedor->id.':pedido-'.$pedido->id.':2026-11-09';
        $this->get(route('rutas.sacar-pedido.edit', $pedido))->assertOk()->assertSee('data-borrador="'.$corregido.'"', false);
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '4']])
            ->assertSessionHasNoErrors()->assertSessionHas('borrador_guardado', $corregido);

        $nueva = 'pedido-borrador:'.$this->vendedor->id.':tienda-nueva:2026-11-09';
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda de prueba Nueva']))->assertOk()->assertSee('data-borrador="'.$nueva.'"', false);
        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => 'Tienda de prueba Nueva', 'cantidades' => [$this->galleta->id => '6']])
            ->assertSessionHasNoErrors()->assertSessionHas('borrador_guardado', $nueva);
    }

    public function test_validaciones_del_pedido_intactas(): void
    {
        $inactivo = Producto::factory()->create(['activo' => false]);
        foreach ([
            [[$this->galleta->id => ''], 'lineas'],
            [[$this->galleta->id => '0'], 'lineas.0.cantidad'],
            [[$this->galleta->id => '-3'], 'lineas.0.cantidad'],
            [[$this->galleta->id => '100000'], 'lineas.0.cantidad'],
            [[$inactivo->id => '2'], 'lineas.0.producto_id'],
        ] as [$cantidades, $campo]) {
            $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => $cantidades])->assertSessionHasErrors($campo);
        }
        // Un error no deja un viaje vacío.
        $this->assertSame(0, PedidoSala::count());
        $this->assertSame(0, SalidaRuta::count());

        // La sala cerrada no se busca ni se usa.
        $cerrada = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba cerrada', 'activo' => false]);
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'cerrada']))->assertOk()->assertDontSee('Sala de prueba cerrada');
        $this->get(route('rutas.sacar-pedido.create', $cerrada))->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('error', PedidosSala::SALA_DESACTIVADA);
        $this->sacar($cerrada)->assertSessionHasErrors(['lineas' => PedidosSala::SALA_DESACTIVADA]);
        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_corregir_y_anular_solo_quien_lo_saco_el_mismo_dia(): void
    {
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '4']])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Pedido de «Sala de prueba Oriente» corregido.');
        $this->assertSame('4.0000', $pedido->refresh()->lineas->sole()->cantidad);
        $this->assertSame(1, SalidaRuta::count(), 'Corregir no abre otro viaje.');
        // Una cantidad de 1,000 o más vuelve al formulario sin separador de miles: un
        // <input type="number"> con «1,000» queda vacío y al corregir se perdía la línea.
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '1500.5'], 'confirmar_cantidades' => '1'])
            ->assertSessionHasNoErrors();
        $this->get(route('rutas.sacar-pedido.edit', $pedido))->assertOk()
            ->assertSee('value="1500.5"', false)->assertDontSee('value="1,500.5"', false);
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '4']])->assertSessionHasNoErrors();

        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $otro->id]);
        $this->actingAs($otro);
        $this->get(route('rutas.sacar-pedido.edit', $pedido))->assertForbidden();
        $this->patch(route('rutas.sacar-pedido.anular', $pedido))->assertForbidden();
        // El otro vendedor no saca un segundo pedido de la misma sala el mismo día.
        $this->sacar($this->sala)->assertSessionHasErrors(['lineas' => 'El pedido de «Sala de prueba Oriente» ya lo sacó hoy Carlos Ficticio.']);

        $this->actingAs($this->vendedor);
        $this->patch(route('rutas.sacar-pedido.anular', $pedido))->assertRedirect(route('rutas.sacar-pedido.index'));
        $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
        $this->assertTrue(Activity::where('description', 'anuló el pedido de la sala')->exists());

        $this->travelTo('2026-11-10 09:00:00');
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $ultimo = PedidoSala::latest('id')->first();
        // Otro día lo sigue corrigiendo mientras no saque el de la sala siguiente.
        $this->travelTo('2026-11-11 09:00:00');
        $this->put(route('rutas.sacar-pedido.update', $ultimo), ['cantidades' => [$this->galleta->id => '2']])->assertRedirect(route('rutas.sacar-pedido.index'))->assertSessionHasNoErrors();
        $this->sacar($this->otraSala)->assertSessionHasNoErrors();
        $this->put(route('rutas.sacar-pedido.update', $ultimo), ['cantidades' => [$this->galleta->id => '1']])->assertForbidden();
        $this->patch(route('rutas.sacar-pedido.anular', $ultimo))->assertForbidden();
        $this->get(route('rutas.sacar-pedido.edit', $ultimo))->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('error', 'Ya sacaste el pedido de otra sala: este lo corrige la oficina.');
    }

    public function test_permisos_vendedor_si_fabrica_no(): void
    {
        $fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->actingAs($fabrica);
        $this->get(route('rutas.sacar-pedido.index'))->assertForbidden();
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertForbidden();
        $this->sacar($this->sala)->assertForbidden();

        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->sacar($this->sala)->assertForbidden();

        // Vendedor sin ficha de Rutas: se le dice qué hacer y no saca.
        $suelto = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->actingAs($suelto);
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('Tu usuario no es vendedor')->assertSee('Ver «Por entregar»');
        $this->sacar($this->sala)->assertForbidden();

        // Terminar el día del viaje de otro: no.
        $this->actingAs($this->vendedor)->sacar($this->sala)->assertSessionHasNoErrors();
        $viaje = SalidaRuta::sole();
        $this->actingAs($suelto)->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0'])->assertForbidden();
        $this->assertTrue($viaje->refresh()->suma_pedidos);
        $this->assertSame(1, PedidoSala::count());

        auth()->logout();
        $this->sacar($this->sala)->assertRedirect(route('login'));
    }
}
