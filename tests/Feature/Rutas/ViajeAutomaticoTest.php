<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\Viajes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El viaje se arma solo (Melqui, 10/11/2026): se abre con el primer pedido del vendedor, los
 * días seguidos en la misma zona o en una vecina se suman, y se cierra cuando todo está
 * facturado y entregado. Las rutas son el catálogo de zonas. Datos ficticios.
 */
class ViajeAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    /** @var array<string, ClienteSucursal> */
    private array $salas = [];

    /** @var array<string, Ruta> */
    private array $rutas = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);

        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        // Zona => [código del departamento, nombre del departamento, sala].
        foreach ([
            'San Miguel' => ['12', 'San Miguel', 'Sala de prueba Oriente'],
            'Usulután' => ['11', 'Usulután', 'Sala de prueba Costa'],
            'Santa Ana' => ['02', 'Santa Ana', 'Sala de prueba Occidente'],
        ] as $zona => [$codigo, $departamento, $sala]) {
            $dep = Departamento::firstOrCreate(['codigo' => $codigo], ['nombre' => $departamento, 'activo' => true]);
            $this->rutas[$zona] = Ruta::create(['nombre' => $zona]);
            $this->salas[$zona] = $cliente->sucursales()->create(['nombre' => $sala, 'ruta_id' => $this->rutas[$zona]->id, 'departamento_id' => $dep->id]);
        }
        // Sin ruta asignada pero en San Miguel: la zona sale del departamento.
        $this->salas['oriente sin ruta'] = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba Oriente',
            'departamento_id' => Departamento::where('codigo', '12')->value('id')]);
        $this->salas['sin nada'] = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba sin zona']);
    }

    private function viajes(): Viajes
    {
        return app(Viajes::class);
    }

    /** Lo que hace «Sacar pedido»: el viaje y el pedido en él. */
    private function sacar(string $sala, ?PersonalRuta $persona = null, ?User $usuario = null): PedidoSala
    {
        return DB::transaction(function () use ($sala, $persona, $usuario) {
            $sala = $this->salas[$sala];
            $viaje = $this->viajes()->paraPedido($persona ?? $this->persona, $sala, $usuario ?? $this->vendedor);

            return $viaje->pedidos()->create([
                'cliente_sucursal_id' => $sala->id, 'ruta_id' => $this->viajes()->zona($sala)?->id,
                'sacado_por_id' => ($persona ?? $this->persona)->id, 'registrado_por' => ($usuario ?? $this->vendedor)->id,
                'fecha' => now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado,
            ]);
        });
    }

    private function ccf(ClienteSucursal $sala): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 500 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => 50.00,
        ]);
    }

    /** La fábrica lo mandó y se facturó en un CCF. */
    private function mandadoYFacturado(PedidoSala $pedido): Dte
    {
        $dte = $this->ccf($pedido->sala);
        $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'registrado_por' => $this->vendedor->id, 'dte_id' => $dte->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        return $dte;
    }

    public function test_el_primer_pedido_abre_el_viaje_con_nombre_de_su_zona(): void
    {
        $this->assertSame(0, SalidaRuta::count());

        $pedido = $this->sacar('San Miguel');
        $viaje = $pedido->salida;

        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->estado);
        $this->assertTrue($viaje->automatica);
        $this->assertTrue($viaje->esAutomatica());
        $this->assertTrue($viaje->esViaje());
        $this->assertTrue($viaje->suma_pedidos);
        $this->assertSame(PropositoSalida::Ambos, $viaje->proposito);
        $this->assertSame($this->rutas['San Miguel']->id, $viaje->ruta_id);
        $this->assertSame('2026-11-09', $viaje->fecha_inicio->toDateString());
        $this->assertNull($viaje->observaciones, 'Ya no se reconoce por el texto.');
        $this->assertSame([$this->persona->id], $viaje->participantes()->pluck('rutas_personal_id')->all());
        $this->assertSame('Lunes · San Miguel', $viaje->nombreViaje());
        $this->assertSame(1, $this->viajes()->dia($viaje));
        $this->assertTrue(Activity::where('subject_type', SalidaRuta::class)->where('subject_id', $viaje->id)
            ->where('description', 'se abrió solo el viaje')->where('causer_id', $this->vendedor->id)->exists());

        // El segundo pedido del día, en otra sala de la zona, va al mismo viaje.
        $this->assertSame($viaje->id, $this->sacar('oriente sin ruta')->salida_ruta_id);
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame($viaje->id, $this->viajes()->abierto($this->persona)?->id);
    }

    public function test_la_sala_sin_zona_abre_un_viaje_sin_ruta(): void
    {
        $viaje = $this->sacar('sin nada')->salida;

        $this->assertNull($viaje->ruta_id);
        $this->assertSame('Lunes · Sin zona', $viaje->nombreViaje());
        $this->assertSame('Sin zona', $viaje->ruta->nombre, 'Las pantallas que muestran la ruta no se rompen.');

        // La siguiente sala con zona se suma y le da nombre.
        $this->assertSame($viaje->id, $this->sacar('San Miguel')->salida_ruta_id);
        $this->assertSame('Lunes · San Miguel', $viaje->refresh()->nombreViaje());
    }

    public function test_el_dia_siguiente_en_la_misma_zona_o_una_vecina_se_suma(): void
    {
        $viaje = $this->sacar('San Miguel')->salida;

        $this->travelTo('2026-11-10 08:00:00');
        $this->assertSame($viaje->id, $this->sacar('San Miguel')->salida_ruta_id);
        // Usulután es vecino de San Miguel.
        $this->assertSame($viaje->id, $this->sacar('Usulután')->salida_ruta_id);

        $viaje->refresh();
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame('Lunes · San Miguel y Usulután', $viaje->nombreViaje());
        $this->assertSame(2, $this->viajes()->dia($viaje));
        $this->assertSame('2026-11-09', $viaje->fecha_inicio->toDateString());
    }

    public function test_otra_zona_abre_otro_viaje_y_el_anterior_deja_de_sumar(): void
    {
        $oriente = $this->sacar('San Miguel')->salida;

        $this->travelTo('2026-11-10 08:00:00');
        $occidente = $this->sacar('Santa Ana')->salida;

        $this->assertNotSame($oriente->id, $occidente->id);
        $this->assertSame('Martes · Santa Ana', $occidente->nombreViaje());
        $oriente->refresh();
        $this->assertFalse($oriente->suma_pedidos);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $oriente->estado, 'Sigue abierto para entregar.');
        $this->assertTrue(Activity::where('subject_id', $oriente->id)->where('subject_type', SalidaRuta::class)
            ->where('description', 'el viaje dejó de sumar pedidos')->exists());

        // Volver a San Miguel no reabre el viejo: abre otro.
        $this->assertNotContains($this->sacar('San Miguel')->salida_ruta_id, [$oriente->id, $occidente->id]);
    }

    public function test_cada_vendedor_tiene_su_viaje(): void
    {
        $otroUsuario = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $otra = PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $otroUsuario->id]);

        $suyo = $this->sacar('San Miguel')->salida;
        $deElla = $this->sacar('San Miguel', $otra, $otroUsuario)->salida;

        $this->assertNotSame($suyo->id, $deElla->id);
        $this->assertTrue($suyo->refresh()->suma_pedidos, 'El viaje del otro no corta el suyo.');
    }

    public function test_si_dice_que_no_sigue_el_viaje_deja_de_sumar(): void
    {
        $viaje = $this->sacar('San Miguel')->salida;

        // «Sí, sigo»: nada cambia.
        $this->viajes()->terminarDia($viaje, true, $this->vendedor);
        $this->assertTrue($viaje->refresh()->suma_pedidos);

        $this->viajes()->terminarDia($viaje, false, $this->vendedor);
        $viaje->refresh();
        $this->assertFalse($viaje->suma_pedidos);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->estado);
        $this->assertTrue(Activity::where('subject_id', $viaje->id)->where('subject_type', SalidaRuta::class)
            ->where('description', 'terminó el día: no sigue en la zona')->where('causer_id', $this->vendedor->id)->exists());

        // Al otro día, en la misma zona, es un viaje nuevo.
        $this->travelTo('2026-11-10 08:00:00');
        $this->assertNotSame($viaje->id, $this->sacar('San Miguel')->salida_ruta_id);
    }

    public function test_un_viaje_de_varios_dias_y_un_dia_sin_pedidos_lo_corta(): void
    {
        $viaje = $this->sacar('San Miguel')->salida;
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $dia) {
            $this->travelTo($dia.' 08:00:00');
            $this->assertSame($viaje->id, $this->sacar('San Miguel')->salida_ruta_id);
        }
        $this->assertSame(4, $this->viajes()->dia($viaje->refresh()));
        $this->assertSame(4, $viaje->pedidos()->count());

        // Viernes sin pedido; el sábado ya no son días seguidos.
        $this->travelTo('2026-11-14 08:00:00');
        $this->assertNotSame($viaje->id, $this->sacar('San Miguel')->salida_ruta_id);
    }

    public function test_la_tarea_de_las_4_no_rompe_un_viaje_de_varios_dias(): void
    {
        $viaje = $this->sacar('San Miguel')->salida;
        $this->travelTo('2026-11-10 08:00:00');
        $this->sacar('San Miguel');

        $this->travelTo('2026-11-11 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();

        $viaje->refresh();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->estado);
        $this->assertTrue($viaje->suma_pedidos, 'Ayer hubo pedido: hoy sigue sumando.');
        $this->travelTo('2026-11-11 09:00:00');
        $this->assertSame($viaje->id, $this->sacar('Usulután')->salida_ruta_id);

        // Dos días sin pedidos: la tarea lo deja de sumar, pero lo deja abierto para entregar.
        $this->travelTo('2026-11-13 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $viaje->refresh();
        $this->assertFalse($viaje->suma_pedidos);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->estado);
    }

    public function test_se_cierra_solo_cuando_todo_esta_facturado_y_entregado(): void
    {
        $uno = $this->sacar('San Miguel');
        $dos = $this->sacar('oriente sin ruta');
        $anulado = $this->sacar('San Miguel');
        $anulado->update(['estado' => EstadoPedidoSala::Anulado]);
        $noSeTrae = $this->sacar('oriente sin ruta');
        $noSeTrae->update(['estado' => EstadoPedidoSala::NoSeTrae]);
        $viaje = $uno->salida;
        $this->viajes()->terminarDia($viaje, false, $this->vendedor);

        $cerrar = function (string $dia) use ($viaje) {
            $this->travelTo($dia.' 04:00:00');
            $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();

            return $viaje->refresh();
        };

        // Sin mandar de la fábrica: abierto.
        $this->assertSame(EstadoSalidaRuta::EnCurso, $cerrar('2026-11-10')->estado);

        // Mandado y facturado, sin entregar: abierto.
        $ccfUno = $this->mandadoYFacturado($uno);
        $ccfDos = $this->mandadoYFacturado($dos);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $cerrar('2026-11-11')->estado);

        // Uno entregado (en cualquier salida, por cualquiera) y el otro «no se pudo»: abierto.
        $otra = SalidaRuta::create(['ruta_id' => $this->rutas['San Miguel']->id, 'fecha_inicio' => '2026-11-11', 'estado' => EstadoSalidaRuta::EnCurso]);
        $otra->entregas()->create(['dte_id' => $ccfUno->id, 'cliente_sucursal_id' => $uno->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-11 10:00:00']);
        $intento = $viaje->entregas()->create(['dte_id' => $ccfDos->id, 'cliente_sucursal_id' => $dos->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::NoEntregado, 'fecha_resultado' => '2026-11-11 11:00:00']);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $cerrar('2026-11-12')->estado);

        // El CCF invalidado deja el pedido por facturar: tampoco cierra.
        $intento->update(['resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-12 09:30:00']);
        DB::table('dtes')->where('id', $ccfDos->id)->update(['estado' => 'invalidado']);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $cerrar('2026-11-13')->estado);
        DB::table('dtes')->where('id', $ccfDos->id)->update(['estado' => 'aceptado']);

        $viaje = $cerrar('2026-11-14');
        $this->assertSame(EstadoSalidaRuta::Finalizada, $viaje->estado);
        $this->assertSame('2026-11-12', $viaje->fecha_fin_real->toDateString(), 'Regresa el día del último movimiento.');
        $this->assertFalse($viaje->estaLiquidada());
        $this->assertTrue($viaje->esPorLiquidar());
        $this->assertTrue(Activity::where('subject_id', $viaje->id)->where('subject_type', SalidaRuta::class)
            ->where('description', 'se cerró solo el viaje: todo entregado')->exists());
    }

    public function test_mientras_suma_pedidos_no_se_cierra_aunque_este_todo_entregado(): void
    {
        $pedido = $this->sacar('San Miguel');
        $pedido->update(['estado' => EstadoPedidoSala::NoSeTrae]);

        $this->travelTo('2026-11-10 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();

        $this->assertSame(EstadoSalidaRuta::EnCurso, $pedido->salida->refresh()->estado);
    }

    public function test_sin_movimiento_en_varios_dias_se_cierra_igual(): void
    {
        $viaje = $this->sacar('San Miguel')->salida;

        // Nadie manda el pedido de la fábrica.
        $this->travelTo('2026-11-19 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->refresh()->estado, 'Diez días sin movimiento todavía no.');

        $this->travelTo('2026-11-20 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $viaje->refresh();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $viaje->estado);
        $this->assertSame('2026-11-09', $viaje->fecha_fin_real->toDateString());
        $this->assertTrue(Activity::where('subject_id', $viaje->id)->where('subject_type', SalidaRuta::class)
            ->where('description', 'se cerró solo el viaje: sin movimiento')->exists());
    }

    public function test_el_viaje_con_todos_sus_pedidos_anulados_se_cancela(): void
    {
        $pedido = $this->sacar('San Miguel');
        $viaje = $pedido->salida;
        $pedido->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->viajes()->terminarDia($viaje, false, $this->vendedor);

        $this->travelTo('2026-11-10 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();

        $this->assertSame(EstadoSalidaRuta::Cancelada, $viaje->refresh()->estado);
        $this->assertSame(1, $viaje->pedidos()->count(), 'El pedido anulado se conserva.');
    }

    public function test_deshacer_la_unica_entrega_no_cancela_un_viaje_con_pedidos(): void
    {
        $pedido = $this->sacar('San Miguel');
        $viaje = $pedido->salida;
        $dte = $this->mandadoYFacturado($pedido);
        $entrega = $viaje->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $pedido->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => now(), 'entregado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id]);

        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertSessionHasNoErrors();

        $this->assertSame(0, $viaje->entregas()->count());
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->refresh()->estado);
    }

    public function test_las_salidas_viejas_siguen_como_estaban(): void
    {
        $id = DB::table('salidas_ruta')->insertGetId(['ruta_id' => $this->rutas['San Miguel']->id, 'fecha_inicio' => '2026-11-02',
            'estado' => 'en_curso', 'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA, 'created_at' => now(), 'updated_at' => now()]);
        $vieja = SalidaRuta::findOrFail($id);

        $this->assertFalse($vieja->automatica);
        $this->assertFalse($vieja->suma_pedidos);
        $this->assertTrue($vieja->esAutomatica(), 'La automática de «Por entregar» de antes se sigue reconociendo.');
        $this->assertFalse($vieja->esViaje());
        $this->assertSame(1, SalidaRuta::automaticas()->count());

        // Un formal en curso del vendedor no recibe los pedidos: el viaje es aparte.
        $formal = SalidaRuta::create(['ruta_id' => $this->rutas['San Miguel']->id, 'fecha_inicio' => '2026-11-09',
            'estado' => EstadoSalidaRuta::EnCurso, 'proposito' => PropositoSalida::Ambos]);
        $formal->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => 'responsable']);
        $this->assertNotContains($this->sacar('San Miguel')->salida_ruta_id, [$vieja->id, $formal->id]);
    }
}
