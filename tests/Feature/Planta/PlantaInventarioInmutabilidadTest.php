<?php

namespace Tests\Feature\Planta;

use App\Exceptions\Planta\ExistenciaNoEscribibleException;
use App\Exceptions\Planta\MovimientoInmutableException;
use App\Models\Planta\PlantaExistencia;
use App\Models\Planta\PlantaMovimiento;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inmutabilidad del mayor y carácter de solo lectura de la proyección.
 *
 * Se prueban las DOS caras, y la segunda importa tanto como la primera:
 *
 *   1. que los candados de Eloquent bloqueen lo que pueden bloquear;
 *   2. que NO bloqueen lo que no pueden —`query()->update()`, `DB::table()`,
 *      SQL crudo— y que eso esté documentado como límite y no confundido con
 *      una garantía.
 *
 * Dejar la segunda sin probar sería peor que no tener las pruebas: daría por
 * cerrada una puerta que sigue abierta. La defensa real frente a esa puerta es
 * `planta:reconciliar-existencias`, y aquí se comprueba que efectivamente la ve.
 */
class PlantaInventarioInmutabilidadTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    // --- Movimientos: append-only ---

    public function test_un_movimiento_no_puede_actualizarse(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $movimiento = $this->aplicar($bucket, '10.0000');

        $movimiento->cantidad = '999.0000';

        $this->expectException(MovimientoInmutableException::class);

        $movimiento->save();
    }

    public function test_un_movimiento_no_puede_eliminarse(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $movimiento = $this->aplicar($bucket, '10.0000');

        $this->expectException(MovimientoInmutableException::class);

        $movimiento->delete();
    }

    public function test_un_movimiento_rechazado_conserva_su_valor_original(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $movimiento = $this->aplicar($bucket, '10.0000');

        try {
            $movimiento->cantidad = '999.0000';
            $movimiento->save();
        } catch (MovimientoInmutableException) {
            // esperado
        }

        $this->assertSame('10.0000', $movimiento->fresh()->cantidad);
    }

    public function test_el_modelo_no_gestiona_updated_at(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $movimiento = $this->aplicar($bucket, '10.0000');

        $this->assertNull(PlantaMovimiento::UPDATED_AT);
        $this->assertNotNull($movimiento->created_at);
    }

    public function test_el_candado_de_eloquent_no_alcanza_a_las_escrituras_masivas(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $movimiento = $this->aplicar($bucket, '10.0000');

        // ESTO FUNCIONA, y debe quedar escrito que funciona: `query()->update()`
        // compila una sentencia contra la tabla sin materializar el modelo, así que
        // los eventos de Eloquent no corren. No hay forma de impedirlo desde el ORM.
        PlantaMovimiento::query()->where('id', $movimiento->id)->update(['cantidad' => '999.0000']);

        $this->assertSame('999.0000', $movimiento->fresh()->cantidad);

        // Y esta es la razón por la que el candado del modelo NO es la defensa
        // principal: lo que de verdad delata la escritura lateral es comparar el
        // mayor con su proyección.
        $this->assertNotSame($this->sumaMayor($bucket), $this->saldoProyectado($bucket));
    }

    /**
     * Ninguna ruta ESCRIBE en el mayor ni en su proyección.
     *
     * Hasta el paso 11 esta prueba exigía que no existiera ninguna ruta que
     * mencionase movimientos o existencias, porque no había ninguna. Ahora hay
     * dos pantallas de consulta, y la garantía que importaba nunca fue «que no se
     * puedan mirar» sino «que no se puedan tocar»: leer el inventario no lo
     * modifica. Por eso lo que se comprueba es el VERBO, no la existencia de la
     * ruta. Un POST, PUT, PATCH o DELETE contra estas URLs sería un camino de
     * escritura hacia una tabla append-only y una proyección que solo el motor
     * de inventario puede actualizar.
     */
    public function test_ninguna_ruta_de_movimientos_ni_existencias_acepta_escritura(): void
    {
        $sospechosas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_contains($ruta->uri(), 'movimiento') || str_contains($ruta->uri(), 'existencia'))
            ->filter(fn ($ruta) => array_diff($ruta->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($ruta) => implode('|', $ruta->methods()).' '.$ruta->uri())
            ->values()
            ->all();

        $this->assertSame([], $sospechosas);
    }

    // --- Existencias: proyección de solo lectura ---

    public function test_una_existencia_no_puede_crearse_por_eloquent(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $existencia = new PlantaExistencia;
        $existencia->forceFill(array_merge($bucket->aColumnas(), ['cantidad' => '5.0000']));

        $this->expectException(ExistenciaNoEscribibleException::class);

        $existencia->save();
    }

    public function test_una_existencia_no_puede_editarse_por_eloquent(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        $existencia = PlantaExistencia::delBucket($bucket)->firstOrFail();
        $existencia->forceFill(['cantidad' => '999.0000']);

        $this->expectException(ExistenciaNoEscribibleException::class);

        $existencia->save();
    }

    public function test_una_existencia_no_puede_eliminarse_por_eloquent(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        $existencia = PlantaExistencia::delBucket($bucket)->firstOrFail();

        $this->expectException(ExistenciaNoEscribibleException::class);

        $existencia->delete();
    }

    public function test_la_cantidad_no_es_asignable_en_masa(): void
    {
        $existencia = new PlantaExistencia;

        // Con `$fillable = []` el modelo queda TOTALMENTE protegido, y Eloquent
        // trata eso como error explícito en vez de descartar el atributo en
        // silencio. Mejor así: un `fill(['cantidad' => …])` escrito por descuido
        // falla en la cara de quien lo escribió, no seis meses después.
        $this->expectException(MassAssignmentException::class);

        $existencia->fill(['cantidad' => '123.0000']);
    }

    public function test_el_saldo_solo_cambia_a_traves_del_servicio(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');
        $this->assertSame('10.0000', $this->saldoProyectado($bucket));

        $this->aplicar($bucket, '-4.0000');
        $this->assertSame('6.0000', $this->saldoProyectado($bucket));

        // Y en todo momento la proyección es exactamente la suma del mayor.
        $this->assertSame($this->sumaMayor($bucket), $this->saldoProyectado($bucket));
    }

    public function test_la_lectura_por_eloquent_si_esta_permitida(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        $existencia = PlantaExistencia::delBucket($bucket)->firstOrFail();

        $this->assertSame('10.0000', $existencia->cantidad);
        $this->assertTrue($existencia->bucket()->esIgualA($bucket));
        $this->assertSame(1, PlantaExistencia::utilizable()->conSaldo()->count());
    }

    public function test_el_motor_impide_dos_filas_para_el_mismo_bucket_incluso_por_query_builder(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        // A diferencia del candado de Eloquent, el UNIQUE sí alcanza al SQL crudo:
        // es una garantía del motor, no una defensa del ORM.
        $this->expectException(QueryException::class);

        DB::table('planta_existencias')->insert(array_merge($bucket->aColumnas(), [
            'cantidad' => '1.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
