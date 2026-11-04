<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Mi salida: Tu próxima salida» (docs/PLAN_TABLERO_RUTAS.md, PR 8). La salida que se arma
 * para otro día aparece en el celular de quienes van, solo para mirar, con las salas que
 * tocan sacar pedido y los CCF que lleva. Se empieza con «Empezar ruta», solo quien va y
 * desde el día de la salida. Todos los datos son inventados.
 *
 * Hoy es martes 03/11/2026; la salida es para el jueves 05/11/2026.
 */
class ProximaSalidaTest extends TestCase
{
    use RefreshDatabase;

    private int $correlativo = 0;

    private User $vendedor;

    private PersonalRuta $fichaVendedor;

    private User $jefe;

    private PersonalRuta $fichaJefe;

    private User $jefa;

    private PersonalRuta $fichaJefa;

    private Ruta $ruta;

    private ClienteSucursal $centro;

    protected function setUp(): void
    {
        parent::setUp();

        // Solo los CCF desde el 28/10 viajan en la salida; los anteriores dan el ritmo.
        config(['rutas.entregas_desde' => '2026-10-28', 'rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->travelTo('2026-11-03 09:00:00');

        [$this->vendedor, $this->fichaVendedor] = $this->usuario('Carlos Ficticio', RolSistema::Vendedor);
        [$this->jefe, $this->fichaJefe] = $this->usuario('Javier Inventado', RolSistema::JefeVendedores);
        [$this->jefa, $this->fichaJefa] = $this->usuario('Julia Ejemplo', RolSistema::JefeVendedores);

        // Al jueves 05/11: Centro atrasada (cada 14, último 13/10), Playa toca pronto (cada 7,
        // último 28/10), Puerto al día (último 03/11) y Bahía sin ritmo.
        $this->ruta = Ruta::create(['nombre' => 'Ruta Oriente']);
        $this->centro = $this->sala('Súper Ficticio Centro');
        $this->pedidos($this->centro, ['2026-09-01', '2026-09-15', '2026-09-28', '2026-10-13']);
        $this->pedidos($this->sala('Tienda Ficticia Playa'), ['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28']);
        $this->pedidos($this->sala('Bodega Ejemplo Puerto'), ['2026-10-06', '2026-10-20', '2026-11-03']);
        $this->sala('Kiosco Ejemplo Bahía');
    }

    /** @return array{0: User, 1: PersonalRuta} */
    private function usuario(string $nombre, RolSistema $rol): array
    {
        $usuario = app(CrearUsuario::class)->crear([
            'name' => $nombre, 'email' => Str::slug($nombre).'@example.test', 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => $rol->value,
        ]);

        return [$usuario, PersonalRuta::where('user_id', $usuario->id)->firstOrFail()];
    }

    private function sala(string $nombre): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'ruta_id' => $this->ruta->id, 'activo' => true]);
    }

    /** @param  array<int, string>  $fechas */
    private function pedidos(ClienteSucursal $sala, array $fechas): void
    {
        $establecimiento = Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });

        foreach ($fechas as $fecha) {
            $this->correlativo++;
            Dte::create([
                'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
                'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
                'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (1000 + $this->correlativo), 15, '0', STR_PAD_LEFT),
                'fecha_emision' => $fecha, 'hora_emision' => '10:00:00', 'total_pagar' => 100.00,
            ]);
        }
    }

    /** El jefe la arma para el jueves, como en «Armar salida». */
    private function armarParaElJueves(array $personal): SalidaRuta
    {
        $this->actingAs($this->jefe)->post(route('rutas.salidas.store'), [
            'ruta_id' => (string) $this->ruta->id,
            'dia' => 'otro',
            'fecha_inicio' => '2026-11-05',
            'personal' => array_map(fn (PersonalRuta $p) => (string) $p->id, $personal),
        ])->assertSessionHasNoErrors();

        $salida = SalidaRuta::latest('id')->firstOrFail();
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->estado);

        return $salida;
    }

    public function test_el_vendedor_ve_su_proxima_salida_con_ruta_dia_companeros_salas_y_ccf(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor, $this->fichaJefe]);

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $salida));

        $respuesta = $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))
            ->assertOk()
            ->assertSee('Tu próxima salida')
            ->assertSee('Ruta Oriente')
            ->assertSee('Jueves 05/11/2026')
            ->assertSee('Vas con Javier Inventado')
            ->assertSee('La podés empezar el jueves 05/11/2026.')
            ->assertDontSee(route('rutas.mi-salida.empezar', $salida))
            // Las atrasadas primero, después las que tocan pronto.
            ->assertSeeInOrder(['Salas que tocan sacar pedido', 'Súper Ficticio Centro', 'Atrasada', 'Tienda Ficticia Playa', 'Toca pronto', 'Las otras 2 salas de la ruta', 'CCF por entregar'])
            // Viajan los CCF desde el 28/10: el de Playa y el de Puerto.
            ->assertSee('2 CCF · $200.00')
            ->assertSee('…1008')
            ->assertSee('…1011');

        // Puerto (al día) y Bahía (sin ritmo) no están entre las que tocan.
        $salas = Str::between($respuesta->getContent(), 'Salas que tocan sacar pedido', 'CCF por entregar');
        $this->assertStringNotContainsString('Bodega Ejemplo Puerto', $salas);
        $this->assertStringNotContainsString('Kiosco Ejemplo Bahía', $salas);
    }

    public function test_otro_vendedor_no_la_ve_ni_la_empieza(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor]);
        [$otro] = $this->usuario('Rosa Ficticia', RolSistema::Vendedor);

        $this->actingAs($otro)->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('No tenés salidas en camino')->assertDontSee('Ruta Oriente');
        $this->actingAs($otro)->get(route('rutas.mi-salida.show', $salida))->assertForbidden();

        $this->travelTo('2026-11-05 08:00:00');
        $this->actingAs($otro)->post(route('rutas.mi-salida.empezar', $salida))->assertForbidden();
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->refresh()->estado);

        // Con la persona de Rutas inactiva tampoco, aunque vaya en la salida.
        $this->fichaVendedor->update(['activo' => false]);
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
        $this->actingAs($this->vendedor)->post(route('rutas.mi-salida.empezar', $salida))->assertForbidden();
    }

    public function test_los_dos_jefes_que_van_la_ven_y_el_que_no_va_no(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor, $this->fichaJefe, $this->fichaJefa]);
        [$tercero] = $this->usuario('Luis Inventado', RolSistema::JefeVendedores);

        foreach ([$this->jefe, $this->jefa] as $jefe) {
            $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()->assertSee(route('rutas.mi-salida.index'));
            $this->actingAs($jefe)->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $salida));
            $this->actingAs($jefe)->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertSee('Tu próxima salida')->assertSee('Carlos Ficticio');
        }
        $this->actingAs($this->jefa)->get(route('rutas.mi-salida.show', $salida))->assertSee('Vas con Carlos Ficticio y Javier Inventado');

        $this->actingAs($tercero)->get(route('rutas.dashboard'))->assertDontSee(route('rutas.mi-salida.index'));
        $this->actingAs($tercero)->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
    }

    public function test_en_una_planificada_no_se_registra_nada(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor]);
        $entrega = $salida->entregas()->firstOrFail();
        $this->travelTo('2026-11-05 08:00:00');
        $this->actingAs($this->vendedor);

        $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado'])->assertForbidden();
        $this->post(route('rutas.mi-salida.sala', [$salida, $entrega->cliente_sucursal_id]))->assertForbidden();
        $this->post(route('rutas.mi-salida.gastos.store', $salida), ['fecha' => '2026-11-05', 'categoria' => 'comida', 'monto' => '5.00'])->assertForbidden();
        $this->get(route('rutas.mi-salida.cierre', $salida))->assertForbidden();

        $this->assertTrue($entrega->refresh()->estaPendiente());
        $this->assertSame(0, $salida->gastos()->count());
    }

    public function test_empezar_ruta_antes_del_dia_se_rechaza_y_ese_dia_pasa_a_en_curso(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor]);
        $entrega = $salida->entregas()->firstOrFail();
        $this->actingAs($this->vendedor);

        // Miércoles: todavía no.
        $this->travelTo('2026-11-04 18:00:00');
        $this->post(route('rutas.mi-salida.empezar', $salida))
            ->assertRedirect(route('rutas.mi-salida.show', $salida))
            ->assertSessionHas('error', 'Esta salida es para el jueves 05/11/2026. La podés empezar ese día.');
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->refresh()->estado);

        // Jueves: aparece el botón y la empieza.
        $this->travelTo('2026-11-05 07:30:00');
        $this->get(route('rutas.mi-salida.show', $salida))->assertSee(route('rutas.mi-salida.empezar', $salida))->assertSee('Empezar ruta');
        $this->post(route('rutas.mi-salida.empezar', $salida))
            ->assertRedirect(route('rutas.mi-salida.show', $salida))
            ->assertSessionHas('status', 'Ruta empezada. Ya podés marcar las entregas.');
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);

        $registro = Activity::where('log_name', 'salida_ruta')->where('description', 'empezó la ruta')->sole();
        $this->assertSame($this->vendedor->id, $registro->causer_id);
        $this->assertSame($salida->id, $registro->subject_id);

        // Desde ahí funciona como hoy: la hoja de siempre y ya registra.
        $this->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertSee('0 de 2 entregados')->assertDontSee('Tu próxima salida');
        $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHas('status');
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->refresh()->resultado);
    }

    public function test_empezar_ruta_carga_los_ccf_facturados_desde_que_se_armo(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor]);
        $alArmar = $salida->entregas()->count();

        // El miércoles se facturan dos CCF más para salas de la ruta.
        $this->travelTo('2026-11-04 15:00:00');
        $this->pedidos($this->centro, ['2026-11-04', '2026-11-04']);
        $nuevos = Dte::where('fecha_emision', '2026-11-04')->pluck('id')->sort()->values()->all();

        $this->travelTo('2026-11-05 07:30:00');
        $this->actingAs($this->vendedor)->post(route('rutas.mi-salida.empezar', $salida))
            ->assertRedirect(route('rutas.mi-salida.show', $salida))
            ->assertSessionHas('status', 'Ruta empezada. Se agregaron 2 CCF nuevos de la ruta. Ya podés marcar las entregas.');

        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);
        $this->assertSame($alArmar + 2, $salida->entregas()->count());
        $this->assertSame($nuevos, $salida->entregas()->whereIn('dte_id', $nuevos)->pluck('dte_id')->sort()->values()->all());
    }

    public function test_doble_clic_en_empezar_no_rompe_nada(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor, $this->fichaJefe]);
        $this->travelTo('2026-11-05 08:00:00');

        $this->actingAs($this->vendedor)->post(route('rutas.mi-salida.empezar', $salida))->assertSessionHas('status');
        // El segundo toque (o el compañero a la vez) no hace nada.
        $this->actingAs($this->jefe)->post(route('rutas.mi-salida.empezar', $salida))
            ->assertRedirect(route('rutas.mi-salida.show', $salida))
            ->assertSessionHas('status', 'La ruta ya estaba en curso.');

        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);
        $this->assertSame(1, Activity::where('description', 'empezó la ruta')->count());
    }

    public function test_una_cancelada_o_terminada_no_se_empieza(): void
    {
        $salida = $this->armarParaElJueves([$this->fichaVendedor]);
        $this->travelTo('2026-11-05 08:00:00');
        $this->actingAs($this->vendedor);

        foreach ([EstadoSalidaRuta::Cancelada, EstadoSalidaRuta::Finalizada] as $estado) {
            $salida->update(['estado' => $estado]);
            $this->post(route('rutas.mi-salida.empezar', $salida))
                ->assertSessionHas('error', 'Esta salida está '.mb_strtolower($estado->label()).': ya no se puede empezar.');
            $this->assertSame($estado, $salida->refresh()->estado);
            $this->get(route('rutas.mi-salida.show', $salida))->assertForbidden();
        }
        $this->assertSame(0, Activity::where('description', 'empezó la ruta')->count());
    }

    public function test_con_una_en_curso_manda_la_en_curso_y_la_proxima_queda_como_enlace(): void
    {
        $proxima = $this->armarParaElJueves([$this->fichaVendedor]);
        $enCurso = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-03', 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($enCurso, [$this->fichaVendedor->id], null);

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $enCurso));
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $enCurso))
            ->assertOk()
            ->assertSee('Tu próxima salida')
            ->assertSee(route('rutas.mi-salida.show', $proxima))
            ->assertSee('Jueves 05/11/2026');
    }
}
