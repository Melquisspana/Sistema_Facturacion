<?php

namespace Tests\Feature\Planta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QUÉ PUEDE Y QUÉ NO PUEDE DEMOSTRAR ESTA SUITE SOBRE CONCURRENCIA.
 *
 * Existe para que nadie lea las pruebas del motor de inventario como si fueran
 * una prueba de que `lockForUpdate` funciona. No lo son, y conviene que quede
 * escrito en una prueba y no solo en un comentario:
 *
 *   - la suite corre sobre SQLite en memoria;
 *   - la gramática de SQLite compila `lockForUpdate()` a CADENA VACÍA, así que
 *     el bloqueo ni siquiera llega a la base;
 *   - SQLite serializa la base ENTERA durante una escritura, de modo que
 *     cualquier prueba de «dos escritores a la vez» pasaría igual aunque el
 *     servicio no tomara ningún candado;
 *   - además, dos «procesos» simulados dentro de un mismo test PHP comparten
 *     conexión: no habría carrera que medir.
 *
 * Lo que sí se prueba aquí es la corrección SECUENCIAL, que es real y sí depende
 * del código: que dos efectos consecutivos sobre el mismo bucket se acumulen y
 * que el segundo parta del saldo que dejó el primero.
 *
 * La concurrencia REAL —dos transacciones InnoDB simultáneas, en procesos
 * distintos— se verifica con `planta:diagnostico-concurrencia` contra MySQL. Su
 * existencia y sus candados se comprueban abajo; sus resultados se documentan en
 * la entrega del paso, no aquí.
 */
class PlantaConcurrenciaTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    public function test_la_suite_corre_sobre_sqlite_en_memoria(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_en_sqlite_el_bloqueo_de_fila_ni_siquiera_llega_a_la_base(): void
    {
        $sql = strtolower(
            DB::table('planta_existencias')->where('id', 1)->lockForUpdate()->toSql()
        );

        // La demostración concreta de por qué esta suite NO puede probar el
        // bloqueo: la gramática de SQLite lo descarta al compilar.
        $this->assertStringNotContainsString('for update', $sql);
    }

    public function test_los_efectos_consecutivos_sobre_un_bucket_se_acumulan(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        for ($i = 0; $i < 20; $i++) {
            $this->aplicar($bucket, '1.0000');
        }

        // Ningún efecto se pierde y el saldo es exactamente la suma del mayor.
        $this->assertSame('20.0000', $this->saldoProyectado($bucket));
        $this->assertSame($this->sumaMayor($bucket), $this->saldoProyectado($bucket));
        $this->assertSame(20, DB::table('planta_movimientos')->where($bucket->aColumnas())->count());
    }

    public function test_dentro_de_una_misma_transaccion_el_segundo_efecto_ve_el_primero(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        DB::transaction(function () use ($bucket) {
            $primero = $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());
            $segundo = $this->servicio()->aplicarMovimiento($bucket, '5.0000', $this->contexto());

            $this->assertSame('0.0000', $primero->saldoAntes());
            $this->assertSame('10.0000', $segundo->saldoAntes());
            $this->assertSame('15.0000', $segundo->saldoDespues());
        });

        $this->assertSame('15.0000', $this->saldoProyectado($bucket));
    }

    public function test_sobre_un_bucket_existente_no_se_intenta_insertar_de_nuevo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->aplicar($bucket, '5.0000');

        $inserciones = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains(strtolower($q['query']), 'insert')
                && str_contains(strtolower($q['query']), 'planta_existencias')
        );

        // Fija el ORDEN «bloquear primero, insertar solo si falta». Con el orden
        // contrario habría aquí un `insert or ignore` inútil, y ese insert es el
        // que provocaba deadlocks en InnoDB al tomar un lock compartido que luego
        // había que subir a exclusivo. Lo midió el diagnóstico contra MySQL: con
        // seis procesos concurrentes, uno se quedaba fuera; reordenado, ninguno.
        $this->assertSame([], $inserciones, 'Un bucket que ya existe no debe reintentar el insert.');

        DB::disableQueryLog();
    }

    public function test_el_bucket_solo_se_crea_una_vez_aunque_se_pida_muchas(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        for ($i = 0; $i < 5; $i++) {
            $this->aplicar($bucket, '1.0000');
        }

        // `insertOrIgnore` es lo que hace idempotente la creación del bucket. Que
        // aquí no haya carrera no le quita valor: prueba que el camino repetido no
        // duplica la fila.
        $this->assertSame(1, DB::table('planta_existencias')->where($bucket->aColumnas())->count());
    }

    // --- El diagnóstico que sí prueba la concurrencia real ---

    public function test_existe_el_comando_de_diagnostico_de_concurrencia(): void
    {
        $this->assertArrayHasKey('planta:diagnostico-concurrencia', Artisan::all());
    }

    public function test_el_diagnostico_no_corre_sin_confirmacion_explicita(): void
    {
        // Escribe datos reales: no puede dispararse por accidente.
        $this->artisan('planta:diagnostico-concurrencia')->assertExitCode(1);
    }

    public function test_el_diagnostico_admite_los_cuatro_escenarios_exigidos(): void
    {
        $definicion = Artisan::all()['planta:diagnostico-concurrencia']->getDefinition();
        $descripcion = $definicion->getOption('escenario')->getDescription();

        foreach (['crear-bucket', 'sumar', 'ultimo-saldo', 'lote-generico'] as $escenario) {
            $this->assertStringContainsString($escenario, $descripcion);
        }
    }
}
