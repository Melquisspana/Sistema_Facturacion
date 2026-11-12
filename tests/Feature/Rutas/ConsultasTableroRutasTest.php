<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalidaDePrueba;
use Tests\TestCase;

/**
 * Tope de consultas de las pantallas de Rutas (12/11/2026): con más zonas, salas, vendedores
 * y viajes, la pantalla hace las mismas consultas (nada se pide de a uno) y no pasa del tope.
 * Si una crece, falta cargar algo en grupo.
 */
class ConsultasTableroRutasTest extends TestCase
{
    use RefreshDatabase;

    private int $correlativo = 0;

    private ?Establecimiento $establecimiento = null;

    /** @var array<int, User> */
    private array $vendedores = [];

    private int $tanda = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->travelTo('2026-11-12 09:00:00');
    }

    private function ccf(int $clienteId, int $salaId, string $fecha): Dte
    {
        $this->correlativo++;
        $this->establecimiento ??= Establecimiento::create([
            'empresa_id' => Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true])->id,
            'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true,
        ]);

        return Dte::create([
            'establecimiento_id' => $this->establecimiento->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $clienteId,
            'cliente_sucursal_id' => $salaId,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'hora_emision' => '10:00:00',
            'subtotal' => 100.00,
            'total_pagar' => 113.00,
        ]);
    }

    /**
     * Tres zonas con `$salasPorZona` salas cada una, con CCF de varios meses; un vendedor por
     * zona con un viaje abierto desde hace días (pedidos sin mandar, mandados y facturados
     * por entregar, uno ya entregado) y un viaje cerrado con entregas hechas.
     */
    private function escenario(int $salasPorZona): void
    {
        $this->tanda++;
        foreach (['Zona Inventada Norte', 'Zona Inventada Sur', 'Zona Inventada Costa'] as $n => $nombreZona) {
            $z = $this->tanda.$n;
            $zona = Ruta::create(['nombre' => $nombreZona.' '.$this->tanda]);
            $usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');
            $persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba '.$z, 'user_id' => $usuario->id, 'telefono' => '7000000'.$z]);
            $this->vendedores[] = $usuario;

            $cerrado = SalidaRuta::create(['ruta_id' => $zona->id, 'fecha_inicio' => '2026-10-20', 'fecha_fin_real' => '2026-10-21', 'estado' => EstadoSalidaRuta::Finalizada,
                'proposito' => PropositoSalida::Ambos, 'automatica' => true]);
            SalidaDePrueba::participantes($cerrado, [$persona->id], $persona->id);
            $viaje = SalidaRuta::create(['ruta_id' => $zona->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso,
                'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true]);
            SalidaDePrueba::participantes($viaje, [$persona->id], $persona->id);

            $cliente = Cliente::factory()->create();
            for ($i = 0; $i < $salasPorZona; $i++) {
                $sala = $cliente->sucursales()->create(['nombre' => "Sala de prueba {$z}-{$i}", 'codigo' => "9{$z}{$i}", 'ruta_id' => $zona->id, 'activo' => true]);
                foreach (['2026-09-01', '2026-09-22', '2026-10-13'] as $fecha) {
                    $this->ccf($cliente->id, $sala->id, $fecha);
                }
                $entregado = $this->ccf($cliente->id, $sala->id, '2026-10-20');
                $cerrado->entregas()->create([
                    'dte_id' => $entregado->id, 'cliente_sucursal_id' => $sala->id,
                    'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-10-21 11:00:00',
                    'entregado_por_id' => $persona->id,
                ]);

                // Un pedido por sala: sin mandar, mandado y facturado por entregar, o ya entregado.
                $pedido = $viaje->pedidos()->create([
                    'cliente_sucursal_id' => $sala->id, 'ruta_id' => $zona->id,
                    'sacado_por_id' => $persona->id, 'registrado_por' => $usuario->id,
                    'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
                ]);
                if ($i % 3 === 0) {
                    continue;
                }
                $ccf = $this->ccf($cliente->id, $sala->id, '2026-11-10');
                $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-10',
                    'registrado_por' => $usuario->id, 'dte_id' => $ccf->id]);
                $pedido->update(['estado' => EstadoPedidoSala::Enviado]);
                $viaje->entregas()->create($i % 3 === 1
                    ? ['dte_id' => $ccf->id, 'cliente_sucursal_id' => $sala->id]
                    : ['dte_id' => $ccf->id, 'cliente_sucursal_id' => $sala->id, 'resultado' => ResultadoEntrega::Entregado,
                        'fecha_resultado' => '2026-11-11 10:00:00', 'entregado_por_id' => $persona->id]);
            }
        }
    }

    /** Cuántas consultas hace la pantalla, con la caché de permisos ya cargada. */
    private function consultas(string $ruta, User $quien, array $parametros = []): int
    {
        $this->actingAs($quien);
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });
        $this->get(route($ruta, $parametros))->assertOk();

        return $total;
    }

    /**
     * La pantalla con más zonas, salas, vendedores y viajes hace las mismas consultas: nada
     * se pide de a uno. Y no pasa del tope.
     */
    private function noCreceConLosDatos(string $ruta, int $tope, ?callable $quien = null, array $parametros = []): void
    {
        $this->escenario(2);
        $usuario = $quien ? $quien() : User::factory()->create(['activo' => true])->assignRole('administrador');
        // La primera vez carga los permisos y, al vendedor, su ficha: no se cuenta.
        $this->consultas($ruta, $usuario, $parametros);
        $antes = $this->consultas($ruta, $usuario, $parametros);

        $this->escenario(5);
        $despues = $this->consultas($ruta, $usuario, $parametros);

        $this->assertSame($antes, $despues, "{$ruta}: {$antes} consultas con pocos datos y {$despues} con más");
        $this->assertLessThanOrEqual($tope, $despues, "{$ruta}: {$despues} consultas, el tope es {$tope}");
    }

    public function test_el_resumen_del_tablero_no_pide_nada_de_a_uno(): void
    {
        $this->noCreceConLosDatos('rutas.dashboard', 38);
    }

    public function test_viajes_no_pide_nada_de_a_uno(): void
    {
        $this->noCreceConLosDatos('rutas.viajes', 19);
    }
}
