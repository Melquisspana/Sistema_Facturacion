<?php

namespace Tests\Unit\Rutas;

use App\Services\Rutas\EstimadoPedidos;
use App\Services\Rutas\FuentePedidos;
use App\Services\Rutas\PedidoEstimado;
use App\Services\Rutas\RitmoPedidos;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * La regla del pedido estimado (etapa 4 de docs/PLAN_TABLERO_RUTAS.md), sin base de datos:
 * últimos 6 pedidos, entra lo que apareció en al menos la mitad, la cantidad es la mediana.
 */
class EstimadoPedidosTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    /**
     * Un día por pedido, separados una semana, desde el 01/09/2026.
     *
     * @param  array<int, array<int, float|int|string>>  $pedidos  [producto_id => cantidad] por pedido
     */
    private function estimado(array $pedidos): PedidoEstimado
    {
        $comienzos = [];
        $dias = [];
        foreach (array_values($pedidos) as $i => $productos) {
            $fecha = CarbonImmutable::parse('2026-09-01')->addWeeks($i);
            $comienzos[] = $fecha;
            if ($productos !== []) {
                $dias[$fecha->toDateString()] = $productos;
            }
        }

        return $this->servicio(new FuenteFalsa)->deSala($comienzos, $dias);
    }

    private function servicio(FuentePedidos $fuente): EstimadoPedidos
    {
        return new EstimadoPedidos(new RitmoPedidos($fuente), $fuente);
    }

    /** @return array<int, array> producto_id => línea */
    private function lineas(PedidoEstimado $estimado): array
    {
        return array_column($estimado->lineas, null, 'producto_id');
    }

    public function test_entra_lo_que_aparecio_en_al_menos_la_mitad_de_los_pedidos(): void
    {
        $estimado = $this->estimado([
            [self::A => 5, self::B => 1], [self::A => 5, self::B => 1], [self::A => 5], [], [], [],
        ]);

        $lineas = $this->lineas($estimado);
        $this->assertSame(6, $estimado->pedidos);
        $this->assertSame([self::A], array_keys($lineas), 'A apareció en 3 de 6 (la mitad); B en 2, no entra.');
        $this->assertSame(3, $lineas[self::A]['veces']);
    }

    public function test_solo_cuentan_los_ultimos_6_pedidos(): void
    {
        $estimado = $this->estimado([
            [self::B => 9], [self::B => 9], [self::A => 1], [self::A => 1], [self::A => 1], [], [], [],
        ]);

        $this->assertSame(6, $estimado->pedidos);
        $this->assertSame([self::A], array_column($estimado->lineas, 'producto_id'));
        $this->assertSame('2026-10-20', $estimado->ultimo->toDateString());
    }

    public function test_la_cantidad_es_la_mediana_con_impar(): void
    {
        $linea = $this->lineas($this->estimado([[self::A => 2], [self::A => 10], [self::A => 4]]))[self::A];

        $this->assertSame('4.0000', $linea['cantidad']);
        $this->assertSame('4.0000', $linea['ultima_cantidad']);
        $this->assertSame(3, $linea['veces']);
    }

    public function test_la_cantidad_es_la_mediana_con_par(): void
    {
        $linea = $this->lineas($this->estimado([[self::A => 2], [self::A => 10], [self::A => 4], [self::A => 6]]))[self::A];

        $this->assertSame('5.0000', $linea['cantidad'], 'Ordenados 2, 4, 6, 10: el promedio de 4 y 6.');
        $this->assertSame('6.0000', $linea['ultima_cantidad']);
    }

    public function test_los_decimales_se_respetan_sin_redondear_a_enteros(): void
    {
        $impar = $this->lineas($this->estimado([['1' => '1.2500'], ['1' => '0.7500'], ['1' => '3.1250']]))[self::A];
        $par = $this->lineas($this->estimado([['1' => '1.0001'], ['1' => '1.0002']]))[self::A];

        $this->assertSame('1.2500', $impar['cantidad']);
        $this->assertSame('1.0002', $par['cantidad'], 'El promedio cae en 1.00015: se queda en 4 decimales, hacia arriba.');
    }

    public function test_la_ultima_cantidad_es_la_de_la_ultima_vez_que_aparecio(): void
    {
        $linea = $this->lineas($this->estimado([[self::A => 3], [self::A => 7], [self::B => 1], [self::A => 8], [], []]))[self::A];

        $this->assertSame('8.0000', $linea['ultima_cantidad']);
        $this->assertSame('7.0000', $linea['cantidad']);
    }

    public function test_confianza_como_el_ritmo(): void
    {
        $this->assertSame(PedidoEstimado::CONFIABLE, $this->estimado([[self::A => 1], [], [], []])->confianza());
        $this->assertSame(PedidoEstimado::APROXIMADO, $this->estimado([[self::A => 1], [], []])->confianza());
    }

    public function test_con_menos_de_3_pedidos_es_poco_dato_y_devuelve_lo_que_haya(): void
    {
        $estimado = $this->estimado([[self::A => 2, self::B => 1], [self::A => 4]]);

        $this->assertSame(PedidoEstimado::POCO_DATO, $estimado->confianza());
        $this->assertSame([self::A, self::B], array_column($estimado->lineas, 'producto_id'));
        $this->assertSame('3.0000', $this->lineas($estimado)[self::A]['cantidad']);
    }

    public function test_sin_pedidos_no_hay_estimado(): void
    {
        $estimado = $this->estimado([]);

        $this->assertSame(PedidoEstimado::POCO_DATO, $estimado->confianza());
        $this->assertSame([], $estimado->lineas);
        $this->assertSame(0, $estimado->pedidos);
        $this->assertNull($estimado->ultimo);
    }

    /** La fuente se inyecta: el cálculo no sabe si son CCF o pedidos reales. */
    public function test_usa_la_fuente_que_se_le_inyecte(): void
    {
        $fuente = new FuenteFalsa([
            7 => [
                '2026-10-01' => [self::A => 2],
                '2026-10-02' => [self::A => 1, self::B => 4], // el día siguiente: mismo pedido
                '2026-10-15' => [self::A => 3],
                '2026-10-29' => [self::A => 5],
            ],
        ]);

        $estimados = $this->servicio($fuente)->porSala([7, 8], CarbonImmutable::parse('2026-11-03'));

        $sala = $estimados[7];
        $this->assertSame(3, $sala->pedidos);
        $this->assertSame('2026-10-29', $sala->ultimo->toDateString());
        $this->assertSame(['3.0000', '5.0000'], [$this->lineas($sala)[self::A]['cantidad'], $this->lineas($sala)[self::A]['ultima_cantidad']], 'El 01 y el 02 suman 3; mediana de 3, 3 y 5.');
        $this->assertArrayNotHasKey(self::B, $this->lineas($sala), 'B salió en 1 de 3.');
        $this->assertSame([[7], '2026-10-01'], $fuente->pedidoDeProductos, 'Pide los productos solo de las salas con pedidos, desde el más viejo.');

        $this->assertSame(0, $estimados[8]->pedidos);
        $this->assertSame([], $estimados[8]->lineas);
    }
}

/** Una fuente cualquiera, como serán los pedidos reales. */
class FuenteFalsa implements FuentePedidos
{
    public ?array $pedidoDeProductos = null;

    /** @param  array<int, array<string, array<int, float|int|string>>>  $productos */
    public function __construct(private readonly array $productos = []) {}

    public function fechasPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $fechas = [];
        foreach (array_intersect_key($this->productos, array_flip($salaIds)) as $id => $dias) {
            $fechas[$id] = array_map(fn ($dia) => CarbonImmutable::parse($dia), array_keys($dias));
        }

        return $fechas;
    }

    public function productosPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $this->pedidoDeProductos = [$salaIds, $desde->toDateString()];

        return array_intersect_key($this->productos, array_flip($salaIds));
    }
}
