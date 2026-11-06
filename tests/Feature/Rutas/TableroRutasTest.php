<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El inicio de Rutas como tablero del jefe (sección 1 de docs/PLAN_TABLERO_RUTAS.md):
 * resumen, en camino, rutas por urgencia y salas que tocan, con búsqueda y filtros.
 */
class TableroRutasTest extends TestCase
{
    use RefreshDatabase;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->travelTo('2026-11-03 09:00:00');
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
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
            'establecimiento_id' => $establecimiento->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    /** @param  array<int, string>  $fechas */
    private function pedidos(ClienteSucursal $sala, array $fechas): void
    {
        foreach ($fechas as $fecha) {
            $this->ccf($sala, $fecha);
        }
    }

    /**
     * Dos rutas con salas en todos los estados:
     *  · Oriente (atrasada): Centro compra cada 14 y pidió hace 21 días; Norte compra cada
     *    14 (aprox.) y pidió hace 66: atrasada y «¿dejó de comprar?».
     *  · Costa (al día): Playa compra cada 7 y toca pronto; Puerto pidió hoy; Bahía no
     *    tiene pedidos.
     *
     * @return array<string, Ruta>
     */
    private function escenario(): array
    {
        $oriente = Ruta::create(['nombre' => 'Ruta Oriente']);
        $costa = Ruta::create(['nombre' => 'Ruta Costa']);

        $centro = $this->sala('Súper Ficticio Centro', $oriente);
        $this->pedidos($centro, ['2026-09-01', '2026-09-15', '2026-09-28', '2026-10-13']);
        $this->pedidos($this->sala('Tienda Inventada Norte', $oriente), ['2026-08-01', '2026-08-15', '2026-08-29']);
        $this->pedidos($this->sala('Tienda La Inventada Playa', $costa), ['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28']);
        $this->pedidos($this->sala('Bodega Ejemplo Puerto', $costa), ['2026-10-06', '2026-10-20', '2026-11-03']);
        $this->sala('Kiosco Ejemplo Bahía', $costa);

        // Centro recibió su última entrega el 15/10, registrada por el vendedor.
        $salida = SalidaRuta::create(['ruta_id' => $oriente->id, 'fecha_inicio' => '2026-10-14', 'fecha_fin_real' => '2026-10-15', 'estado' => EstadoSalidaRuta::Finalizada]);
        $salida->entregas()->create([
            'dte_id' => Dte::where('cliente_sucursal_id', $centro->id)->latest('fecha_emision')->value('id'),
            'cliente_sucursal_id' => $centro->id,
            'resultado' => ResultadoEntrega::Entregado,
            'fecha_resultado' => '2026-10-15 11:00:00',
        ]);

        return ['oriente' => $oriente, 'costa' => $costa];
    }

    public function test_abre_sin_datos(): void
    {
        $this->actingAs($this->admin())
            ->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Todavía no hay rutas.')
            ->assertSee('Todavía no hay salas en las rutas.')
            ->assertSee('rutas atrasadas')
            ->assertDontSee('En camino desde');
    }

    public function test_resumen_rutas_y_salas_por_urgencia(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        // Resumen: 1 ruta atrasada, 2 salas atrasadas, 1 toca esta semana, 0 en camino.
        $respuesta->assertSeeInOrder(['1', 'ruta atrasada', '2', 'salas atrasadas', '1', 'sala toca esta semana', '0', 'salidas en camino']);

        // Rutas: la atrasada primero, con su ritmo calculado y sus salas.
        // Oriente se cuenta desde el regreso de su salida; Costa, que nunca salió, desde
        // la última visita a sus salas (el CCF de hoy).
        $respuesta->assertSeeInOrder(['Ruta Oriente', 'Atrasada 5 días', 'Se fue hace 19 días', 'Suele ir cada 14 días', '2 salas atrasadas', 'Ruta Costa', 'Al día', 'Última visita a sus salas hoy', 'Suele ir cada 7 días']);

        // Salas: atrasadas (la más atrasada arriba), toca ir, al día y sin ritmo.
        $respuesta->assertSeeInOrder(['Tienda Inventada Norte', 'Súper Ficticio Centro', 'Tienda La Inventada Playa', 'Bodega Ejemplo Puerto', 'Kiosco Ejemplo Bahía']);
        // Norte: su última entrega es su último CCF.
        $respuesta->assertSeeInOrder(['Tienda Inventada Norte', 'Atrasada 52 días', 'Última entrega', 'hace 66 días', 'Compra cada 14 días (aprox.)', '¿Dejó de comprar?']);
        // Centro: la entrega marcada el 15/10 es más nueva que su CCF del 13/10.
        $respuesta->assertSeeInOrder(['Súper Ficticio Centro', 'Atrasada 5 días', 'Última entrega', 'hace 19 días', 'Compra cada 14 días']);
        $respuesta->assertSeeInOrder(['Tienda La Inventada Playa', 'Toca ir', 'Compra cada 7 días']);
        $respuesta->assertSeeInOrder(['Kiosco Ejemplo Bahía', 'Sin ritmo', 'Sin entregas en 12 meses', 'Todavía sin ritmo']);
        $respuesta->assertSee('title="15/10/2026"', false);
    }

    public function test_filtros_de_salas(): void
    {
        ['costa' => $costa] = $this->escenario();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('rutas.dashboard', ['ver' => 'atrasadas']))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Súper Ficticio Centro'])
            ->assertDontSee('Tienda La Inventada Playa')
            ->assertDontSee('Kiosco Ejemplo Bahía');

        $this->actingAs($admin)->get(route('rutas.dashboard', ['ver' => 'semana']))
            ->assertSee('Tienda La Inventada Playa')
            ->assertDontSee('Súper Ficticio Centro')
            ->assertDontSee('Bodega Ejemplo Puerto');

        $this->actingAs($admin)->get(route('rutas.dashboard', ['ruta' => $costa->id]))
            ->assertSee('Kiosco Ejemplo Bahía')
            ->assertDontSee('Súper Ficticio Centro');

        // Sin acentos ni mayúsculas también encuentra.
        $this->actingAs($admin)->get(route('rutas.dashboard', ['q' => 'super ficticio']))
            ->assertSee('Súper Ficticio Centro')
            ->assertDontSee('Tienda Inventada Norte');

        $this->actingAs($admin)->get(route('rutas.dashboard', ['q' => 'no existe', 'ver' => 'atrasadas']))
            ->assertSee('Nada coincide con estos filtros.')
            ->assertSee('Limpiar filtros');

        // Lo que no se reconoce se ignora: se ve todo.
        $this->actingAs($admin)->get(route('rutas.dashboard', ['ver' => 'cualquiera', 'ruta' => 999999]))
            ->assertOk()
            ->assertSee('Kiosco Ejemplo Bahía')
            ->assertSee('Súper Ficticio Centro');
    }

    public function test_tocar_rutas_atrasadas_deja_solo_esas(): void
    {
        $this->escenario();

        $this->actingAs($this->admin())->get(route('rutas.dashboard', ['rutas' => 'atrasadas']))
            ->assertOk()
            ->assertSee('Rutas atrasadas')
            ->assertSee('Ver todas las rutas')
            ->assertDontSee('Suele ir cada 7 días');
    }

    public function test_el_tablero_hace_las_mismas_consultas_con_mas_rutas_y_salas(): void
    {
        $admin = $this->admin();
        $vendedor = PersonalRuta::create(['nombre' => 'Ana Ejemplo']);

        $poblar = function (int $rutas) use ($vendedor, $admin) {
            for ($r = 0; $r < $rutas; $r++) {
                $ruta = Ruta::create(['nombre' => 'Ruta '.Str::random(6)]);
                for ($s = 0; $s < 3; $s++) {
                    $this->pedidos($this->sala('Sala '.Str::random(6), $ruta), ['2026-09-29', '2026-10-06', '2026-10-13', '2026-10-20']);
                }
                $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => '2026-11-02', 'estado' => EstadoSalidaRuta::EnCurso]);
                app(ParticipantesSalida::class)->sincronizar($salida, [$vendedor->id], $vendedor->id);
                $dte = $this->ccf($ruta->sucursales()->first(), '2026-11-01');
                $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id]);
                // Una entrega hecha y un pedido sacado: la última visita de cada sala y vendedor.
                $otro = $this->ccf($ruta->sucursales()->skip(1)->first(), '2026-10-31');
                $salida->entregas()->create([
                    'dte_id' => $otro->id, 'cliente_sucursal_id' => $otro->cliente_sucursal_id,
                    'resultado' => ResultadoEntrega::Entregado, 'entregado_por_id' => $vendedor->id, 'fecha_resultado' => '2026-11-02 10:00:00',
                ]);
                PedidoSala::create(['cliente_sucursal_id' => $dte->cliente_sucursal_id, 'ruta_id' => $ruta->id, 'salida_ruta_id' => $salida->id, 'registrado_por' => $admin->id, 'fecha' => '2026-11-02']);
            }
        };

        $contar = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route('rutas.dashboard'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $poblar(1);
        $contar(); // La primera vez se cargan los permisos en caché; no es del tablero.
        $pocas = $contar();
        $poblar(4);
        $muchas = $contar();

        $this->assertSame($pocas, $muchas, 'El tablero no debería hacer una consulta por ruta, sala o salida.');
        // 27 hoy: todas agregadas (visitas de cada sala, pedidos sacados, facturado por ruta, …).
        $this->assertLessThan(30, $muchas);
    }
}
