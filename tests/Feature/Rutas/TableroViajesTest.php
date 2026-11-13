<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\TableroViajes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La pestaña «Viajes» del tablero de Rutas (etapa 6, 10/11/2026): cada viaje abierto con en
 * qué paso va cada pedido (sacado, en fábrica, facturado, entregado), todo calculado, y lo
 * trabado marcado. Datos ficticios.
 */
class TableroViajesTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes.
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
    }

    private function tablero(): array
    {
        return app(TableroViajes::class)->calcular();
    }

    private function viaje(string $desde = '2026-11-09', ?PersonalRuta $persona = null, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso, ?string $fin = null): SalidaRuta
    {
        $viaje = SalidaRuta::create([
            'ruta_id' => $this->ruta->id, 'fecha_inicio' => $desde, 'estado' => $estado, 'fecha_fin_real' => $fin,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => $estado === EstadoSalidaRuta::EnCurso,
        ]);
        $viaje->participantes()->create(['rutas_personal_id' => ($persona ?? $this->persona)->id, 'rol' => RolEnSalida::Responsable]);

        return $viaje;
    }

    private function sala(string $nombre): ClienteSucursal
    {
        return $this->cliente->sucursales()->create(['nombre' => $nombre, 'ruta_id' => $this->ruta->id]);
    }

    private function pedido(SalidaRuta $viaje, string $sala, string $fecha = '2026-11-10', EstadoPedidoSala $estado = EstadoPedidoSala::Registrado, array $extra = []): PedidoSala
    {
        return $viaje->pedidos()->create([
            'cliente_sucursal_id' => $this->sala($sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => $fecha, 'estado' => $estado,
        ] + $extra);
    }

    private function ccf(PedidoSala $pedido, string $estado = 'aceptado', string $fecha = '2026-11-10'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::withTrashed()->count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => $estado, 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $pedido->cliente_sucursal_id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '07:00:00', 'total_pagar' => 50.00,
        ]);
    }

    /** La fábrica lo mandó todo; con `$estadoCcf`, además se facturó en un CCF. */
    private function mandado(PedidoSala $pedido, ?string $estadoCcf = null, string $fechaCcf = '2026-11-10'): ?Dte
    {
        $dte = $estadoCcf !== null ? $this->ccf($pedido, $estadoCcf, $fechaCcf) : null;
        $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => $pedido->fecha->toDateString(),
            'registrado_por' => $this->vendedor->id, 'dte_id' => $dte?->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        return $dte;
    }

    private function entregado(SalidaRuta $salida, Dte $dte, ResultadoEntrega $resultado = ResultadoEntrega::Entregado): void
    {
        $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => $resultado, 'fecha_resultado' => '2026-11-10', 'entregado_por_id' => $this->persona->id]);
    }

    private function albaran(Dte $dte, string $tipo): void
    {
        PpqAlbaran::create(['numero_albaran' => $tipo.'/0232/00/'.random_int(1000, 9999), 'dte_id' => $dte->id,
            'monto_albaran' => 50, 'fecha_albaran' => '2026-11-10', 'origen' => 'gmail']);
    }

    /** @return array<string, string> sala => paso */
    private function pasos(array $viaje): array
    {
        return $viaje['pedidos']->mapWithKeys(fn (array $p) => [$p['pedido']->sala->nombre => $p['paso']])->all();
    }

    public function test_cada_pedido_esta_en_su_paso(): void
    {
        $viaje = $this->viaje();
        $this->pedido($viaje, 'Sala recien sacada');
        $this->pedido($viaje, 'Sala en armado', extra: ['armando_desde' => now()]);
        $this->pedido($viaje, 'Sala mandada en parte', estado: EstadoPedidoSala::Parcial);
        $this->mandado($this->pedido($viaje, 'Sala mandada sin facturar'));
        $this->mandado($this->pedido($viaje, 'Sala facturada'), 'aceptado');
        $this->mandado($this->pedido($viaje, 'Sala con CCF invalidado'), 'invalidado');
        $this->mandado($this->pedido($viaje, 'Sala con CCF rechazado'), 'rechazado');
        $this->entregado($viaje, $this->mandado($this->pedido($viaje, 'Sala entregada'), 'aceptado'));
        // La entrega pudo marcarse en otra salida: igual cuenta.
        $otra = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->entregado($otra, $this->mandado($this->pedido($viaje, 'Sala entregada en otra salida'), 'aceptado'));
        $this->entregado($viaje, $this->mandado($this->pedido($viaje, 'Sala que no recibio'), 'aceptado'), ResultadoEntrega::NoEntregado);
        $this->albaran($this->mandado($this->pedido($viaje, 'Sala con albaran'), 'aceptado'), 'AC01');
        $this->albaran($this->mandado($this->pedido($viaje, 'Sala con albaran de averia'), 'aceptado'), 'AC02');
        $this->pedido($viaje, 'Sala que no se trae', estado: EstadoPedidoSala::NoSeTrae);
        $this->pedido($viaje, 'Sala anulada', estado: EstadoPedidoSala::Anulado);

        $tablero = $this->tablero();

        $this->assertCount(1, $tablero['abiertos']);
        $fila = $tablero['abiertos']->first();
        $this->assertEquals([
            'Sala recien sacada' => TableroViajes::SACADO,
            'Sala en armado' => TableroViajes::EN_FABRICA,
            'Sala mandada en parte' => TableroViajes::EN_FABRICA,
            'Sala mandada sin facturar' => TableroViajes::EN_FABRICA,
            'Sala facturada' => TableroViajes::FACTURADO,
            'Sala con CCF invalidado' => TableroViajes::EN_FABRICA,
            'Sala con CCF rechazado' => TableroViajes::EN_FABRICA,
            'Sala entregada' => TableroViajes::ENTREGADO,
            'Sala entregada en otra salida' => TableroViajes::ENTREGADO,
            'Sala que no recibio' => TableroViajes::FACTURADO,
            'Sala con albaran' => TableroViajes::ENTREGADO,
            'Sala con albaran de averia' => TableroViajes::FACTURADO,
            'Sala que no se trae' => TableroViajes::NO_SE_TRAE,
        ], $this->pasos($fila));
        $this->assertEquals([
            TableroViajes::SACADO => 1, TableroViajes::EN_FABRICA => 5, TableroViajes::FACTURADO => 3, TableroViajes::ENTREGADO => 3,
        ], $fila['conteo']);
        $this->assertSame(1, $fila['noSeTrae']);
        $this->assertSame('Lunes · San Miguel', $fila['nombre']);
        $this->assertSame(2, $fila['dia']);
        $this->assertSame(['Carlos Ficticio'], $fila['vendedores']);
        $this->assertSame(13, $fila['salas'], 'Las salas con pedido vigente, sin la anulada.');
    }

    public function test_un_pedido_mandado_en_dos_ccf_va_en_el_paso_del_que_va_mas_atras(): void
    {
        $viaje = $this->viaje();
        $pedido = $this->pedido($viaje, 'Sala en dos CCF');
        $this->entregado($viaje, $this->mandado($pedido, 'aceptado'));
        $pedido->envios()->create(['numero' => 2, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-10',
            'registrado_por' => $this->vendedor->id, 'dte_id' => $this->ccf($pedido)->id]);

        $this->assertSame(TableroViajes::FACTURADO, $this->tablero()['abiertos']->first()['pedidos']->first()['paso']);
    }

    public function test_lo_trabado_sale_marcado(): void
    {
        $viaje = $this->viaje('2026-11-05');
        $this->pedido($viaje, 'Sala sacada hace 2 dias', '2026-11-08');
        $this->pedido($viaje, 'Sala sacada ayer', '2026-11-09');
        $this->pedido($viaje, 'Sala en armado hace 3 dias', '2026-11-07', extra: ['armando_desde' => now()]);
        $this->mandado($this->pedido($viaje, 'Sala facturada hace 3 dias', '2026-11-06'), 'aceptado', '2026-11-07');
        $this->mandado($this->pedido($viaje, 'Sala facturada hace 2 dias', '2026-11-06'), 'aceptado', '2026-11-08');
        $this->entregado($viaje, $this->mandado($this->pedido($viaje, 'Sala entregada hace mucho', '2026-11-01'), 'aceptado', '2026-11-02'));
        $this->mandado($this->pedido($viaje, 'Sala mandada sin facturar', '2026-11-07'));

        $fila = $this->tablero()['abiertos']->first();
        $trabados = $fila['pedidos']->filter(fn (array $p) => $p['trabado'] !== null)
            ->mapWithKeys(fn (array $p) => [$p['pedido']->sala->nombre => $p['trabado']])->all();

        $this->assertEquals([
            'Sala facturada hace 3 dias' => 'Facturado hace 3 días y todavía no se entrega.',
            'Sala en armado hace 3 dias' => 'Sacado hace 3 días y todavía no se despacha.',
            'Sala sacada hace 2 dias' => 'Sacado hace 2 días y todavía no se despacha.',
            'Sala mandada sin facturar' => 'Sacado hace 3 días y todavía no se factura.',
        ], $trabados);
        $this->assertSame(4, $fila['trabados']);
        $this->assertSame(2, TableroViajes::DIAS_TRABADO_FABRICA);
        $this->assertSame(3, TableroViajes::DIAS_TRABADO_ENTREGA);
    }

    public function test_los_viajes_cerrados_recientes_van_aparte_y_lo_demas_no_aparece(): void
    {
        $abierto = $this->viaje();
        $this->pedido($abierto, 'Sala del viaje abierto');
        $cerrado = $this->viaje('2026-11-02', estado: EstadoSalidaRuta::Finalizada, fin: '2026-11-05');
        $this->entregado($cerrado, $this->mandado($this->pedido($cerrado, 'Sala del viaje cerrado', '2026-11-02'), 'aceptado', '2026-11-03'));
        // Cerrado hace más de dos semanas, cancelado, armado a mano y el de «Por entregar»: no.
        $this->viaje('2026-10-10', estado: EstadoSalidaRuta::Finalizada, fin: '2026-10-20');
        $this->viaje('2026-11-09', estado: EstadoSalidaRuta::Cancelada);
        SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso, 'proposito' => PropositoSalida::Ambos]);
        SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Entregar, 'automatica' => true]);

        $tablero = $this->tablero();

        $this->assertSame([$abierto->id], $tablero['abiertos']->pluck('salida.id')->all());
        $this->assertSame([$cerrado->id], $tablero['cerrados']->pluck('salida.id')->all());
        $this->assertEquals([TableroViajes::SACADO => 0, TableroViajes::EN_FABRICA => 0, TableroViajes::FACTURADO => 0, TableroViajes::ENTREGADO => 1],
            $tablero['cerrados']->first()['conteo']);
        $this->assertSame(0, $tablero['cerrados']->first()['trabados'], 'Un viaje cerrado no se marca trabado.');
    }

    public function test_el_jefe_ve_la_pestana_y_el_vendedor_no(): void
    {
        $viaje = $this->viaje();
        $this->pedido($viaje, 'Sala de prueba Norte', '2026-11-08');

        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.viajes'))
            ->assertOk()
            ->assertSee('Lunes · San Miguel')
            ->assertSee('Sala de prueba Norte')
            ->assertSee('Carlos Ficticio')
            ->assertSee('Trabado')
            ->assertSee('aria-current="page"', false);
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()->assertSee(route('rutas.viajes'), false);

        foreach (['facturacion', 'jefatura', 'administrador'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->actingAs($usuario)->get(route('rutas.viajes'))->assertOk();
        }

        $this->actingAs($this->vendedor)->get(route('rutas.viajes'))->assertForbidden();
        $this->get(route('rutas.viajes'))->assertForbidden();
    }

    /**
     * «Todos los viajes» salió del menú (12/11/2026): lo suyo pasó a esta pestaña. La oficina
     * cancela acá el viaje abierto por error (la misma ruta y el mismo permiso de siempre) y
     * el listado completo se abre con «Ver viajes anteriores». El menú resalta «Tablero».
     */
    public function test_la_oficina_cancela_aca_el_viaje_abierto_por_error_y_abre_los_anteriores(): void
    {
        $viaje = $this->viaje();
        $this->pedido($viaje, 'Sala de prueba Norte');
        $cancelar = 'action="'.route('rutas.salidas.cancelar', $viaje).'"';
        $anteriores = 'href="'.route('rutas.salidas.index').'"';

        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->get(route('rutas.viajes'))->assertOk()
            ->assertSee($cancelar, false)
            ->assertSee('Cancelar viaje')
            ->assertSee('¿Cancelar este viaje?')
            ->assertSee($anteriores, false)
            ->assertSee('Ver viajes anteriores');

        // El jefe de vendedores mira, pero no cancela (no tiene `rutas.gestionar`).
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertDontSee($cancelar, false)
            ->assertSee($anteriores, false);
        $this->actingAs($jefe)->patch(route('rutas.salidas.cancelar', $viaje))->assertForbidden();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->fresh()->estado);

        // Vuelve a la pestaña con el aviso, y el viaje ya no está entre los abiertos.
        $this->actingAs($oficina)->from(route('rutas.viajes'))->patch(route('rutas.salidas.cancelar', $viaje))
            ->assertRedirect(route('rutas.viajes'))->assertSessionHas('status', 'Viaje cancelado.');
        $this->assertSame(EstadoSalidaRuta::Cancelada, $viaje->fresh()->estado);
        $this->actingAs($oficina)->get(route('rutas.viajes'))->assertOk()->assertDontSee($cancelar, false);

        // El listado y el detalle siguen abriendo; el menú resalta «Tablero».
        $tablero = '/href="'.preg_quote(route('rutas.dashboard'), '/').'"\s+aria-current="page"/u';
        foreach ([route('rutas.salidas.index'), route('rutas.salidas.show', $viaje)] as $url) {
            $html = $this->actingAs($oficina)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression($tablero, $html, $url);
        }
    }

    public function test_sin_viajes_lo_dice(): void
    {
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');

        $this->actingAs($jefe)->get(route('rutas.viajes'))
            ->assertOk()
            ->assertSee('No hay viajes abiertos.');
    }

    public function test_las_consultas_no_crecen_con_los_viajes(): void
    {
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $cargar = function (int $cuantos): void {
            for ($i = 0; $i < $cuantos; $i++) {
                $persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba '.Str::random(4)]);
                $viaje = $this->viaje(persona: $persona);
                $this->pedido($viaje, 'Sala sacada '.Str::random(4));
                $this->entregado($viaje, $this->mandado($this->pedido($viaje, 'Sala entregada '.Str::random(4)), 'aceptado'));
                $this->albaran($this->mandado($this->pedido($viaje, 'Sala con albaran '.Str::random(4)), 'aceptado'), 'AC01');
            }
        };
        $contar = function () use ($jefe): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($jefe)->get(route('rutas.viajes'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $cargar(1);
        // La primera vez se cargan los permisos y los ajustes: no se cuenta.
        $contar();
        $uno = $contar();
        $cargar(3);

        $this->assertSame($uno, $contar());
    }
}
