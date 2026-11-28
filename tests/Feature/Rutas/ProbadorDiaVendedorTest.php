<?php

namespace Tests\Feature\Rutas;

use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El probador del día del vendedor (27/11/2026, antes del primer sábado de uso real): lo que
 * un formulario o un enlace puede mandar raro (listas donde va un texto, cantidades con coma,
 * negativas o enormes) no da 500: vuelve con un aviso en castellano y no guarda nada.
 * Datos ficticios.
 */
class ProbadorDiaVendedorTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private ClienteSucursal $sala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Sábado, a media mañana en El Salvador.
        $this->travelTo('2026-11-28 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);

        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->persona->funciones()->create(['funcion' => 'vendedor']);

        $departamento = Departamento::firstOrCreate(['codigo' => '02'], ['nombre' => 'Santa Ana', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Cliente de prueba'])
            ->sucursales()->create(['nombre' => 'Sala de prueba', 'codigo' => '9001', 'ruta_id' => $ruta->id, 'departamento_id' => $departamento->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
    }

    /** Un CCF aceptado de la sala, por entregar. */
    private function ccf(): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26112832'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => '100.25',
        ]);
    }

    // ---------------------------------------------------------------- sacar pedido

    public function test_la_tienda_nueva_con_el_nombre_en_lista_vuelve_con_el_aviso(): void
    {
        $this->actingAs($this->vendedor)
            ->from(route('rutas.sacar-pedido.tienda-nueva.create'))
            ->post(route('rutas.sacar-pedido.tienda-nueva.store'), [
                'sala_nueva_nombre' => ['Tienda de prueba'],
                'cantidades' => [$this->galleta->id => '5'],
            ])
            ->assertRedirect(route('rutas.sacar-pedido.tienda-nueva.create'))
            ->assertSessionHasErrors(['sala_nueva_nombre' => 'Escribí el nombre de la tienda.']);

        $this->assertSame(0, PedidoSala::count());
    }

    public function test_cantidades_raras_vuelven_con_el_aviso_y_no_guardan_nada(): void
    {
        $this->actingAs($this->vendedor);
        $raras = [
            'con coma' => '1,5',
            'negativa' => '-3',
            'enorme' => '100000',
            'cero' => '0',
            'con exponente' => '1e3',
            'con letras' => 'doce',
            'con cinco decimales' => '1.23456',
            'en lista' => ['5'],
        ];
        foreach ($raras as $caso => $cantidad) {
            $this->from(route('rutas.sacar-pedido.create', $this->sala))
                ->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => $cantidad]])
                ->assertRedirect(route('rutas.sacar-pedido.create', $this->sala))
                ->assertSessionHasErrors('lineas.0.cantidad', "Cantidad {$caso}");
        }
        // Las cantidades como texto suelto o la OC y las notas en lista, tampoco.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => 'doce'])->assertSessionHasErrors();
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'numero_orden_compra' => ['OC-1']])
            ->assertSessionHasErrors('numero_orden_compra');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'notas' => ['nota']])
            ->assertSessionHasErrors('notas');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'confirmar_cantidades' => ['1']])
            ->assertSessionHasErrors('confirmar_cantidades');
        // El buscador y la tienda nueva con la búsqueda en lista: aviso, no 500.
        $this->get(route('rutas.sacar-pedido.index', ['q' => ['sala']]))->assertRedirect();
        $this->getJson(route('rutas.sacar-pedido.salas', ['q' => ['sala']]))->assertUnprocessable();
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => ['Tienda']]))->assertRedirect();

        $this->assertSame(0, PedidoSala::count());
    }

    // ---------------------------------------------------------------- por entregar

    public function test_por_entregar_con_los_filtros_en_lista_abre_igual(): void
    {
        $dte = $this->ccf();

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ver' => ['todas']]))
            ->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ruta' => ['sin']]))
            ->assertOk()->assertSee($dte->numero_control);
    }

    public function test_el_tablero_y_todos_los_viajes_con_los_filtros_en_lista_abren_igual(): void
    {
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');

        $this->actingAs($jefatura)->get(route('rutas.dashboard', ['q' => ['sala'], 'ver' => ['todas'], 'ruta' => ['1']]))->assertOk();
        $this->actingAs($jefatura)->get(route('rutas.salidas.index', ['estado' => ['en_curso'], 'ruta_id' => ['1']]))->assertOk();
    }

    public function test_marcar_con_un_dato_en_lista_vuelve_con_el_aviso_y_no_anota_nada(): void
    {
        $dte = $this->ccf();
        $this->actingAs($this->vendedor);
        $base = ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id];
        $casos = [
            'resultado' => ['resultado' => ['entregado']],
            'forma_cobro' => ['forma_cobro' => ['efectivo']],
            'nota' => ['nota' => ['Devolvió una caja']],
            'quedan_numero' => ['forma_cobro' => 'sin_cobro', 'quedan_numero' => ['Q-1']],
            'quedan_fecha_pago' => ['forma_cobro' => 'sin_cobro', 'quedan_fecha_pago' => ['2026-12-05']],
            'motivo_no_entrega' => ['resultado' => 'no_entregado', 'motivo_no_entrega' => ['cerrado']],
        ];
        foreach ($casos as $campo => $datos) {
            $this->from(route('rutas.por-entregar.index'))
                ->post(route('rutas.por-entregar.marcar', $dte), $datos + $base)
                ->assertRedirect(route('rutas.por-entregar.index'))
                ->assertSessionHasErrors($campo);
        }

        $this->assertSame(0, SalidaRutaEntrega::whereNotNull('resultado')->count());
        // Con los datos bien, se anota como siempre.
        $this->post(route('rutas.por-entregar.marcar', $dte), $base)->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::Entregado, SalidaRutaEntrega::sole()->resultado);
    }
}
