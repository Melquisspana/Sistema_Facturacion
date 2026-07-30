<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Exceptions\Planta\BucketInvalidoException;
use App\Support\Planta\BucketInventario;
use PHPUnit\Framework\TestCase;

/**
 * El objeto de valor que mantiene juntas las cinco dimensiones del saldo.
 *
 * Lo que se fija aquí es que el ORDEN de las columnas sea único y estable: es el
 * mismo que usan la tabla del mayor, el UNIQUE de las existencias, el índice del
 * bucket, el hash del efecto y el GROUP BY de la reconciliación. Si una de esas
 * piezas se desalineara, sumaría saldos que no son el mismo saldo.
 */
class BucketInventarioTest extends TestCase
{
    public function test_las_columnas_son_las_cinco_dimensiones_en_orden(): void
    {
        $this->assertSame([
            'planta_insumo_id',
            'planta_lote_id',
            'planta_ubicacion_id',
            'estado',
            'planta_traslado_id',
        ], BucketInventario::COLUMNAS);
    }

    public function test_a_columnas_respeta_el_orden_canonico(): void
    {
        $bucket = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Retenido, 4);

        $this->assertSame(BucketInventario::COLUMNAS, array_keys($bucket->aColumnas()));
        $this->assertSame([
            'planta_insumo_id' => 1,
            'planta_lote_id' => 2,
            'planta_ubicacion_id' => 3,
            'estado' => 'retenido',
            'planta_traslado_id' => 4,
        ], $bucket->aColumnas());
    }

    public function test_el_traslado_es_cero_por_defecto(): void
    {
        $bucket = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible);

        $this->assertSame(0, $bucket->trasladoId);
        $this->assertFalse($bucket->enTransito());
    }

    public function test_un_traslado_positivo_marca_transito(): void
    {
        $bucket = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible, 9);

        $this->assertTrue($bucket->enTransito());
    }

    public function test_rechaza_un_traslado_negativo(): void
    {
        $this->expectException(BucketInvalidoException::class);

        new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible, -1);
    }

    public function test_la_clave_canonica_distingue_las_cinco_dimensiones(): void
    {
        $base = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible, 0);

        $distintos = [
            new BucketInventario(9, 2, 3, EstadoDisponibilidad::Disponible, 0),
            new BucketInventario(1, 9, 3, EstadoDisponibilidad::Disponible, 0),
            new BucketInventario(1, 2, 9, EstadoDisponibilidad::Disponible, 0),
            new BucketInventario(1, 2, 3, EstadoDisponibilidad::Retenido, 0),
            new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible, 9),
        ];

        foreach ($distintos as $otro) {
            $this->assertNotSame($base->claveCanonica(), $otro->claveCanonica());
            $this->assertFalse($base->esIgualA($otro));
        }
    }

    public function test_dos_buckets_iguales_comparten_clave(): void
    {
        $uno = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Rechazado, 5);
        $otro = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Rechazado, 5);

        $this->assertSame($uno->claveCanonica(), $otro->claveCanonica());
        $this->assertTrue($uno->esIgualA($otro));
    }

    public function test_se_reconstruye_desde_una_fila_de_la_base(): void
    {
        $bucket = BucketInventario::desdeFila([
            'planta_insumo_id' => '7',
            'planta_lote_id' => '8',
            'planta_ubicacion_id' => '9',
            'estado' => 'retenido',
            'planta_traslado_id' => '3',
        ]);

        $this->assertSame(7, $bucket->insumoId);
        $this->assertSame(8, $bucket->loteId);
        $this->assertSame(9, $bucket->ubicacionId);
        $this->assertSame(EstadoDisponibilidad::Retenido, $bucket->estado);
        $this->assertSame(3, $bucket->trasladoId);
    }

    public function test_se_reconstruye_desde_un_objeto_sin_traslado(): void
    {
        $bucket = BucketInventario::desdeFila((object) [
            'planta_insumo_id' => 1,
            'planta_lote_id' => 2,
            'planta_ubicacion_id' => 3,
            'estado' => 'disponible',
        ]);

        $this->assertSame(0, $bucket->trasladoId);
    }

    public function test_la_descripcion_solo_menciona_el_traslado_cuando_lo_hay(): void
    {
        $fisico = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible);
        $enViaje = new BucketInventario(1, 2, 3, EstadoDisponibilidad::Disponible, 4);

        $this->assertStringNotContainsString('traslado', $fisico->descripcion());
        $this->assertStringContainsString('traslado #4', $enViaje->descripcion());
    }
}
