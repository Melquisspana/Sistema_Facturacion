<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\FuentePedidos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El ritmo y el estimado salen de los PEDIDOS de sala y se completan con los CCF que no
 * vienen de un pedido. Un pedido y el CCF que se le facturó son la misma visita: se cuenta
 * una vez, con la fecha del pedido. Nombres inventados: el repositorio es público.
 */
class FuentePedidosSalaTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoy;

    private ClienteSucursal $sala;

    private Producto $dulce;

    private Producto $chicle;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->hoy = CarbonImmutable::parse('2026-11-10');
        $this->travelTo($this->hoy->setTime(9, 0));

        $this->sala = $this->nuevaSala('Sala de prueba Centro');
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'un']);
        $this->dulce = Producto::factory()->create(['nombre' => 'Dulce de prueba']);
        $this->chicle = Producto::factory()->create(['nombre' => 'Chicle de prueba']);
    }

    private function nuevaSala(string $nombre): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'activo' => true]);
    }

    /** @param  array<int, array{0: Producto, 1: int|float}>  $lineas */
    private function ccf(string $fecha, array $lineas, ?ClienteSucursal $sala = null): Dte
    {
        $sala ??= $this->sala;
        $this->correlativo++;
        $establecimiento = Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });
        $dte = Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '10:00:00', 'total_pagar' => 100.00,
        ]);
        foreach (array_values($lineas) as $i => [$producto, $cantidad]) {
            DteLinea::withoutEvents(fn () => DteLinea::create([
                'dte_id' => $dte->id, 'numero_linea' => $i + 1, 'producto_id' => $producto->id, 'descripcion' => $producto->nombre,
                'tipo_impuesto' => 'gravado', 'cantidad' => $cantidad, 'precio_unitario' => 1, 'venta_gravada' => 1, 'total_linea' => 1,
            ]));
        }

        return $dte;
    }

    /** @param  array<int, array{0: Producto, 1: int|float}>  $lineas */
    private function pedido(string $fecha, array $lineas, ?ClienteSucursal $sala = null, EstadoPedidoSala $estado = EstadoPedidoSala::Registrado): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id,
            'registrado_por' => User::factory()->create()->id,
            'fecha' => $fecha,
            'estado' => $estado,
        ]);
        $pedido->lineas()->createMany(array_map(fn (array $l) => ['producto_id' => $l[0]->id, 'cantidad' => $l[1]], $lineas));

        return $pedido;
    }

    /** El CCF que se le facturó al pedido: un envío de la fábrica enlazado al CCF. */
    private function facturar(PedidoSala $pedido, Dte $ccf, bool $anulado = false): void
    {
        $pedido->envios()->create([
            'numero' => $pedido->envios()->count() + 1, 'clave' => (string) Str::uuid(), 'fecha' => $ccf->fecha_emision,
            'registrado_por' => $pedido->registrado_por, 'dte_id' => $ccf->id, 'anulado_en' => $anulado ? now() : null,
        ]);
    }

    /** @return array<int, string> */
    private function fechas(?ClienteSucursal $sala = null): array
    {
        $sala ??= $this->sala;
        $fechas = app(FuentePedidos::class)->fechasPorSala([$sala->id], $this->hoy->subYear(), $this->hoy);

        return array_map(fn (CarbonImmutable $f) => $f->toDateString(), $fechas[$sala->id] ?? []);
    }

    private function productos(): array
    {
        return app(FuentePedidos::class)->productosPorSala([$this->sala->id], $this->hoy->subYear(), $this->hoy)[$this->sala->id] ?? [];
    }

    public function test_el_pedido_de_sala_cuenta_como_visita_con_sus_productos(): void
    {
        $this->pedido('2026-10-20', [[$this->dulce, 10], [$this->chicle, 2]]);

        $this->assertSame(['2026-10-20'], $this->fechas());
        $this->assertEquals(['2026-10-20' => [$this->dulce->id => 10.0, $this->chicle->id => 2.0]], $this->productos());
    }

    /** Se factura tres días después: no son dos visitas ni se suma dos veces lo pedido. */
    public function test_el_pedido_y_su_ccf_son_una_sola_visita(): void
    {
        $pedido = $this->pedido('2026-10-20', [[$this->dulce, 10]]);
        $this->facturar($pedido, $this->ccf('2026-10-23', [[$this->dulce, 9]]));

        $this->assertSame(['2026-10-20'], $this->fechas());
        $this->assertEquals(['2026-10-20' => [$this->dulce->id => 10.0]], $this->productos());
    }

    /** Sin pedidos sigue con los CCF, y un CCF sin pedido completa al pedido de otro día. */
    public function test_los_ccf_sin_pedido_completan_la_historia(): void
    {
        $this->ccf('2026-09-01', [[$this->dulce, 4]]);
        $this->ccf('2026-09-29', [[$this->chicle, 3]]);
        $this->pedido('2026-10-27', [[$this->dulce, 6]]);
        // El envío anulado ya no enlaza: ese CCF vuelve a contar solo.
        $this->facturar($this->pedido('2026-08-03', [[$this->dulce, 1]], estado: EstadoPedidoSala::Anulado), $this->ccf('2026-08-04', [[$this->dulce, 2]]), anulado: true);

        $this->assertSame(['2026-08-04', '2026-09-01', '2026-09-29', '2026-10-27'], $this->fechas());
        $this->assertEquals([
            '2026-08-04' => [$this->dulce->id => 2.0],
            '2026-09-01' => [$this->dulce->id => 4.0],
            '2026-09-29' => [$this->chicle->id => 3.0],
            '2026-10-27' => [$this->dulce->id => 6.0],
        ], $this->productos());
    }

    public function test_varias_salas_con_las_mismas_consultas(): void
    {
        $otra = $this->nuevaSala('Sala de prueba Norte');
        $this->pedido('2026-10-01', [[$this->dulce, 1]], $otra);
        $this->ccf('2026-10-02', [[$this->dulce, 1]]);
        $consultas = function (array $salaIds): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(FuentePedidos::class)->fechasPorSala($salaIds, $this->hoy->subYear(), $this->hoy);
            app(FuentePedidos::class)->productosPorSala($salaIds, $this->hoy->subYear(), $this->hoy);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $pocas = $consultas([$this->sala->id]);
        $tercera = $this->nuevaSala('Sala de prueba Sur');
        $this->pedido('2026-10-05', [[$this->dulce, 1], [$this->chicle, 1]], $tercera);
        $this->ccf('2026-10-06', [[$this->chicle, 1]], $tercera);

        $this->assertSame($pocas, $consultas([$this->sala->id, $otra->id, $tercera->id]));
        $this->assertSame(['2026-10-01'], $this->fechas($otra));
    }
}
