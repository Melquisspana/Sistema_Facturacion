<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Services\Rutas\EstimadoPedidos;
use App\Services\Rutas\FuentePedidos;
use App\Services\Rutas\PedidoEstimado;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El pedido estimado de cada sala con los CCF y sus líneas como fuente (etapa 4 de
 * docs/PLAN_TABLERO_RUTAS.md).
 */
class EstimadoPedidosTest extends TestCase
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
        $this->hoy = CarbonImmutable::parse('2026-11-03');
        $this->travelTo($this->hoy->setTime(9, 0));

        $this->sala = $this->nuevaSala('Súper Ficticio Centro');
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'un']);
        $this->dulce = Producto::factory()->create(['nombre' => 'Dulce de prueba']);
        $this->chicle = Producto::factory()->create(['nombre' => 'Chicle de prueba']);
    }

    private function nuevaSala(string $nombre): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'activo' => true]);
    }

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });
    }

    /**
     * Un documento aceptado de producción con sus líneas.
     *
     * @param  array<int, array{0: ?Producto, 1: float|int|string}>  $lineas  [producto, cantidad]
     */
    private function documento(string $fecha, array $lineas, array $extra = [], ?ClienteSucursal $sala = null): Dte
    {
        $sala ??= $this->sala;
        $this->correlativo++;

        $dte = Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
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

        foreach (array_values($lineas) as $i => [$producto, $cantidad]) {
            DteLinea::withoutEvents(fn () => DteLinea::create([
                'dte_id' => $dte->id, 'numero_linea' => $i + 1, 'producto_id' => $producto?->id,
                'descripcion' => $producto->nombre ?? 'Texto libre', 'tipo_impuesto' => 'gravado',
                'cantidad' => $cantidad, 'precio_unitario' => 1, 'venta_gravada' => 1, 'total_linea' => 1,
            ]));
        }

        return $dte;
    }

    private function estimado(?ClienteSucursal $sala = null): PedidoEstimado
    {
        return app(EstimadoPedidos::class)->paraSala(($sala ?? $this->sala)->id, $this->hoy);
    }

    /** @return array<int, array> producto_id => línea */
    private function lineas(PedidoEstimado $estimado): array
    {
        return array_column($estimado->lineas, null, 'producto_id');
    }

    public function test_estima_con_las_lineas_de_los_ccf(): void
    {
        $this->documento('2026-09-15', [[$this->dulce, 10], [$this->chicle, 2]]);
        $this->documento('2026-09-29', [[$this->dulce, 12]]);
        $this->documento('2026-10-13', [[$this->dulce, 8]]);
        $this->documento('2026-10-27', [[$this->dulce, 14], [$this->chicle, 3]]);

        $estimado = $this->estimado();

        $this->assertSame(PedidoEstimado::CONFIABLE, $estimado->confianza());
        $this->assertSame(4, $estimado->pedidos);
        $this->assertSame('2026-10-27', $estimado->ultimo->toDateString());
        $this->assertSame([
            ['producto_id' => $this->dulce->id, 'cantidad' => '11.0000', 'veces' => 4, 'ultima_cantidad' => '14.0000'],
            ['producto_id' => $this->chicle->id, 'cantidad' => '2.5000', 'veces' => 2, 'ultima_cantidad' => '3.0000'],
        ], $estimado->lineas);
    }

    public function test_varios_ccf_del_mismo_dia_son_un_pedido_y_se_suman(): void
    {
        $this->documento('2026-10-06', [[$this->dulce, 4]]);
        $this->documento('2026-10-06', [[$this->dulce, 6], [$this->chicle, 1]]);
        $this->documento('2026-10-07', [[$this->chicle, 1]]); // el día siguiente: el mismo pedido, como en el ritmo
        $this->documento('2026-10-20', [[$this->dulce, 10]]);

        $estimado = $this->estimado();

        $this->assertSame(2, $estimado->pedidos);
        $this->assertSame(PedidoEstimado::POCO_DATO, $estimado->confianza());
        $lineas = $this->lineas($estimado);
        $this->assertSame(['10.0000', 2], [$lineas[$this->dulce->id]['cantidad'], $lineas[$this->dulce->id]['veces']]);
        $this->assertSame(['2.0000', 1], [$lineas[$this->chicle->id]['cantidad'], $lineas[$this->chicle->id]['veces']]);
    }

    public function test_los_anulados_rechazados_y_sin_sello_no_cuentan(): void
    {
        $this->documento('2026-10-06', [[$this->dulce, 5]]);
        $this->documento('2026-10-13', [[$this->chicle, 50]], ['estado' => 'invalidado']);
        $this->documento('2026-10-20', [[$this->chicle, 50]], ['estado' => 'rechazado', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $this->documento('2026-10-27', [[$this->chicle, 50]], ['sello_recepcion' => 'MOCK-'.Str::random(10), 'fecha_procesamiento_mh' => null]);
        $this->documento('2026-10-28', [[$this->chicle, 50]], ['tipo_dte' => '01']);
        $this->documento('2026-10-30', [[$this->chicle, 50]], ['ambiente' => '00']);

        $estimado = $this->estimado();

        $this->assertSame(1, $estimado->pedidos);
        $this->assertSame('2026-10-06', $estimado->ultimo->toDateString());
        $this->assertSame([$this->dulce->id], array_column($estimado->lineas, 'producto_id'));
    }

    public function test_las_lineas_sin_producto_no_cuentan_pero_el_pedido_si(): void
    {
        $this->documento('2026-10-06', [[$this->dulce, 5], [null, 99]]);
        $this->documento('2026-10-13', [[null, 99]]);
        $this->documento('2026-10-20', [[$this->dulce, 7]]);

        $estimado = $this->estimado();

        $this->assertSame(3, $estimado->pedidos, 'El CCF solo con texto libre igual es un pedido.');
        $this->assertSame([$this->dulce->id], array_column($estimado->lineas, 'producto_id'));
        $this->assertSame('6.0000', $estimado->lineas[0]['cantidad']);
    }

    public function test_una_sala_sin_ccf_no_tiene_estimado(): void
    {
        $estimado = $this->estimado();

        $this->assertSame(0, $estimado->pedidos);
        $this->assertSame([], $estimado->lineas);
        $this->assertNull($estimado->ultimo);
        $this->assertSame(PedidoEstimado::POCO_DATO, $estimado->confianza());
    }

    public function test_la_devolucion_aceptada_resta_y_las_demas_notas_no(): void
    {
        $ccf = $this->documento('2026-10-06', [[$this->dulce, 10], [$this->chicle, 2]]);
        $this->documento('2026-10-20', [[$this->dulce, 10]]);
        $this->documento('2026-11-02', [[$this->dulce, 10]]);

        $nota = ['tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id];
        $this->documento('2026-10-10', [[$this->dulce, 4]], $nota + ['tipo_nota_credito' => 'devolucion_producto']);
        $this->documento('2026-10-10', [[$this->chicle, 2]], $nota + ['tipo_nota_credito' => 'devolucion_producto']);
        $this->documento('2026-10-11', [[$this->dulce, 5]], $nota + ['tipo_nota_credito' => 'averia']);
        $this->documento('2026-10-11', [[$this->dulce, 5]], $nota + ['tipo_nota_credito' => 'faltante_entrega']);
        $this->documento('2026-10-12', [[$this->dulce, 5]], $nota + ['tipo_nota_credito' => 'devolucion_producto', 'estado' => 'invalidado']);

        $estimado = $this->estimado();

        $this->assertSame(3, $estimado->pedidos, 'Las notas no son pedidos.');
        $lineas = $this->lineas($estimado);
        $this->assertSame(['10.0000', 3], [$lineas[$this->dulce->id]['cantidad'], $lineas[$this->dulce->id]['veces']], 'Mediana de 6, 10 y 10.');
        $this->assertArrayNotHasKey($this->chicle->id, $lineas, 'Devolvió todo el chicle: ese pedido ya no lo lleva.');

        $dias = app(FuentePedidos::class)->productosPorSala([$this->sala->id], $this->hoy->subMonth(), $this->hoy);
        $this->assertEquals([$this->dulce->id => 6.0], $dias[$this->sala->id]['2026-10-06']);
    }

    public function test_varias_salas_con_las_mismas_consultas_sin_importar_los_productos(): void
    {
        $otra = $this->nuevaSala('Súper Ficticio Norte');
        $this->documento('2026-10-06', [[$this->dulce, 5]]);
        $this->documento('2026-10-06', [[$this->dulce, 3]], [], $otra);

        $consultas = function (array $salaIds): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(EstimadoPedidos::class)->porSala($salaIds, $this->hoy);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $pocas = $consultas([$this->sala->id, $otra->id]);
        foreach (Producto::factory()->count(5)->create() as $producto) {
            $this->documento('2026-10-20', [[$producto, 1]]);
            $this->documento('2026-10-20', [[$producto, 1]], [], $otra);
        }
        $tercera = $this->nuevaSala('Súper Ficticio Sur');
        $this->documento('2026-10-21', [[$this->chicle, 1]], [], $tercera);

        $this->assertSame(3, $pocas, 'Fechas, líneas de los CCF y notas de crédito.');
        $this->assertSame($pocas, $consultas([$this->sala->id, $otra->id, $tercera->id]));
    }
}
