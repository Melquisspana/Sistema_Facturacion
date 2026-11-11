<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
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
use Tests\TestCase;

/**
 * Lo que se vio en las capturas del celular del jefe de vendedores (03/11/2026): «En
 * camino» coherente con su número, la ruta con salida armada que no sale como atrasada,
 * «A, B y C» con quién encabeza, el plural de «no entregado», «Volver al tablero» en Mi
 * salida y el teléfono para llamar en la ficha de campo. Todos los datos son inventados.
 */
class RutasEnElCelularTest extends TestCase
{
    use RefreshDatabase;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-10-01']);
        $this->travelTo('2026-11-03 09:00:00');
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

    private function sala(string $nombre, Ruta $ruta): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'ruta_id' => $ruta->id, 'activo' => true]);
    }

    private function ccf(ClienteSucursal $sala, string $fecha): Dte
    {
        $this->correlativo++;
        $establecimiento = Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (5000 + $this->correlativo), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '10:00:00', 'total_pagar' => 100.00,
        ]);
    }

    /** @param  array<int, PersonalRuta>  $van */
    private function salida(Ruta $ruta, string $fecha, EstadoSalidaRuta $estado, array $van, ?PersonalRuta $encabeza = null): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => $fecha, 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, array_map(fn (PersonalRuta $p) => $p->id, $van), $encabeza?->id);

        return $salida;
    }

    private function noEntregado(SalidaRuta $salida, ClienteSucursal $sala): void
    {
        $dte = $this->ccf($sala, '2026-11-02');
        $salida->entregas()->create([
            'dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id,
            'resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => MotivoNoEntrega::SalaCerrada,
            'fecha_resultado' => '2026-11-03 08:30:00',
        ]);
    }

    public function test_en_camino_son_solo_las_en_curso_y_las_programadas_van_aparte(): void
    {
        [$jefe, $fichaJefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        $mario = PersonalRuta::create(['nombre' => 'Mario Inventado']);
        $costa = Ruta::create(['nombre' => 'Ruta Costa']);
        $norte = Ruta::create(['nombre' => 'Ruta Norte']);
        $this->salida($costa, '2026-11-02', EstadoSalidaRuta::EnCurso, [$mario]);
        $this->salida($norte, '2026-11-05', EstadoSalidaRuta::Planificada, [$fichaJefe]);

        $this->actingAs($jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['1', 'viaje abierto'])
            // «Viajes abiertos» lleva la en curso; la del jueves va en «Programadas», después.
            ->assertSeeInOrder(['Viajes abiertos', 'Ruta Costa · Mario Inventado', 'Abierta desde el 02/11/2026', 'Programadas', 'Ruta Norte · Ernesto Ficticio', 'Sale el 05/11/2026'])
            ->assertDontSee('Programada desde');
    }

    public function test_la_ruta_con_salida_armada_no_sale_como_atrasada(): void
    {
        [$jefe, $fichaJefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        $ruta = Ruta::create(['nombre' => 'Ruta Paracentral', 'frecuencia_objetivo_dias' => 7]);
        // Se fue hace 25 días con ritmo de 7: 18 días tarde... pero ya tiene salida para el jueves.
        SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => '2026-10-09', 'fecha_fin_real' => '2026-10-09', 'estado' => EstadoSalidaRuta::Finalizada]);
        $programada = $this->salida($ruta, '2026-11-05', EstadoSalidaRuta::Planificada, [$fichaJefe]);

        $respuesta = $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk();

        $respuesta->assertSee('Ya tiene salida armada para el 05/11/2026')
            ->assertSee(route('rutas.salidas.show', $programada))
            ->assertDontSee('días tarde')
            ->assertDontSee('Atrasada 18 días')
            ->assertSeeInOrder(['0', 'zonas atrasadas']);

        // Sin la salida armada, la misma ruta sí está atrasada.
        $programada->cancelar();
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertSee('Atrasada 18 días');
    }

    public function test_los_nombres_van_con_comas_y_y_y_primero_quien_encabeza(): void
    {
        [$jefe, $fichaJefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        $mario = PersonalRuta::create(['nombre' => 'Mario Inventado']);
        $kevin = PersonalRuta::create(['nombre' => 'Kevin Ejemplo']);
        $ruta = Ruta::create(['nombre' => 'Ruta Costa']);
        $salida = $this->salida($ruta, '2026-11-02', EstadoSalidaRuta::EnCurso, [$mario, $kevin, $fichaJefe], $mario);

        $this->assertSame('Mario Inventado (encabeza), Ernesto Ficticio y Kevin Ejemplo', $salida->quienesVan());
        // En «Mi salida» no se nombra a quien mira; quien encabeza sigue marcado.
        $this->assertSame('Mario Inventado (encabeza) y Kevin Ejemplo', $salida->quienesVan($fichaJefe->id));
        // Sin nadie que encabece, por nombre y sin marca.
        $sinCabeza = $this->salida(Ruta::create(['nombre' => 'Ruta Norte']), '2026-11-02', EstadoSalidaRuta::EnCurso, [$mario, $kevin]);
        $this->assertSame('Kevin Ejemplo y Mario Inventado', $sinCabeza->quienesVan());

        $nombres = 'Mario Inventado (encabeza), Ernesto Ficticio y Kevin Ejemplo';
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertSee('Ruta Costa · '.$nombres);
        $this->actingAs($jefe)->get(route('rutas.salidas.show', $salida))->assertSee($nombres)->assertDontSee('Inventado y Kevin Ejemplo y');
        $this->actingAs($jefe)->get(route('rutas.mi-salida.show', $salida))->assertSee('Vas con Mario Inventado (encabeza) y Kevin Ejemplo');
    }

    public function test_un_no_entregado_va_en_singular_en_el_tablero_y_en_la_hoja(): void
    {
        [$jefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        $ruta = Ruta::create(['nombre' => 'Ruta Costa']);
        $salida = $this->salida($ruta, '2026-11-02', EstadoSalidaRuta::EnCurso, [PersonalRuta::create(['nombre' => 'Mario Inventado'])]);
        $sala = $this->sala('Tienda Ficticia Playa', $ruta);
        $this->noEntregado($salida, $sala);

        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertSee('1 no entregado ')->assertDontSee('1 no entregados');
        $this->actingAs($jefe)->get(route('rutas.salidas.show', $salida))->assertSeeText('1 no entregado ·')->assertDontSeeText('1 no entregados');

        $this->noEntregado($salida, $sala);
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertSee('2 no entregados');
        $this->actingAs($jefe)->get(route('rutas.salidas.show', $salida))->assertSeeText('2 no entregados');
    }

    public function test_volver_al_tablero_en_mi_salida_solo_para_quien_ve_el_tablero(): void
    {
        [$jefe, $fichaJefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        [$vendedor, $fichaVendedor] = $this->usuario('Carlos Ficticio', RolSistema::Vendedor);
        $salida = $this->salida(Ruta::create(['nombre' => 'Ruta Costa']), '2026-11-03', EstadoSalidaRuta::EnCurso, [$fichaJefe, $fichaVendedor]);

        $this->assertTrue($jefe->can('rutas.ver'));
        $this->actingAs($jefe)->get(route('rutas.mi-salida.show', $salida))
            ->assertOk()->assertSee('Volver al tablero')->assertSee('href="'.route('rutas.dashboard').'"', false);

        $this->assertFalse($vendedor->can('rutas.ver'));
        $this->actingAs($vendedor)->get(route('rutas.mi-salida.show', $salida))
            ->assertOk()->assertDontSee('Volver al tablero');
    }

    public function test_en_la_ficha_de_campo_el_telefono_se_toca_para_llamar(): void
    {
        // La ficha de campo es de la administración; el jefe llama desde el tablero.
        [$jefe] = $this->usuario('Ernesto Ficticio', RolSistema::JefeVendedores);
        $admin = User::factory()->create(['activo' => true])->assignRole(RolSistema::Administrador->value);
        $mario = PersonalRuta::create(['nombre' => 'Mario Inventado', 'telefono' => '7012-3401']);
        $sinTelefono = PersonalRuta::create(['nombre' => 'Kevin Ejemplo']);

        $this->actingAs($admin)->get(route('rutas.personal.show', $mario))
            ->assertOk()->assertSee('href="tel:70123401"', false)->assertSee('7012-3401');
        // El tablero usa el mismo enlace.
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertSee('href="tel:70123401"', false);
        $this->actingAs($admin)->get(route('rutas.personal.show', $sinTelefono))->assertOk()->assertDontSee('tel:', false);
    }
}
