<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\InventarioFueraDeTransaccionException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use App\Services\Planta\PlantaInventarioService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * La guarda de transacción obligatoria, demostrada con `DB::transactionLevel()`
 * valiendo 0 DE VERDAD y con tablas reales detrás.
 *
 * EL PROBLEMA QUE HAY QUE SORTEAR
 * -------------------------------
 * `RefreshDatabase` envuelve CADA prueba en una transacción de base de datos
 * —también con SQLite en memoria, al contrario de lo que sugiere su nombre—, así
 * que dentro de una prueba normal el nivel vale 1 y la guarda, que salta cuando
 * vale 0, no puede observarse. Una prueba escrita ahí estaría midiendo el
 * envoltorio de la suite, no el servicio.
 *
 * `DatabaseMigrations` sí dejaría el nivel en 0, pero no es utilizable en este
 * repositorio: su `tearDown` ejecuta `migrate:rollback`, y una migración
 * histórica de `dtes` no es reversible en SQLite. Arreglar eso queda fuera del
 * paso 5 y tocaría migraciones anteriores.
 *
 * LO QUE SE HACE EN SU LUGAR
 * --------------------------
 * {@see enNivelCero()} cierra explícitamente el envoltorio de la suite con un
 * `rollBack()`, deja el nivel en 0, ejecuta el bloque de la prueba y restituye
 * el envoltorio al salir. Dentro de ese bloque el escenario se crea en
 * autocommit, así que se BORRA a mano antes de restituir: sin eso, esas filas
 * sobrevivirían al `tearDown` y contaminarían las pruebas siguientes del mismo
 * proceso.
 *
 * Con el nivel realmente en 0, las dos mitades se prueban de verdad: fuera de
 * transacción se rechaza sin escribir nada, y el MISMO flujo dentro de
 * `DB::transaction` funciona y escribe.
 */
class PlantaInventarioTransaccionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ejecuta el bloque con el nivel de transacción en 0 y devuelve la base al
     * estado que espera la suite.
     *
     * El `rollBack()` inicial descarta lo sembrado por `RefreshDatabase` en este
     * test (roles incluidos); no importa, porque cada prueba lo siembra en su
     * propio `setUp`. Lo que sí importa es limpiar lo que el bloque confirme.
     */
    private function enNivelCero(callable $bloque): void
    {
        DB::rollBack();

        try {
            $this->assertSame(0, DB::transactionLevel(), 'El bloque debe correr sin transacción abierta.');

            $bloque();
        } finally {
            $this->limpiarInventario();

            // Restituye el envoltorio: el tearDown de RefreshDatabase espera
            // encontrarse una transacción abierta que deshacer.
            DB::beginTransaction();
        }
    }

    /** Borra en orden de dependencias lo que el bloque haya confirmado. */
    private function limpiarInventario(): void
    {
        DB::table('planta_movimientos')->delete();
        DB::table('planta_existencias')->delete();
        DB::table('planta_lotes')->delete();
        DB::table('planta_insumos')->delete();
        DB::table('planta_ubicaciones')->delete();
    }

    private function bucket(): BucketInventario
    {
        $insumo = PlantaInsumo::factory()->create();

        return new BucketInventario(
            insumoId: $insumo->id,
            loteId: PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id])->id,
            ubicacionId: PlantaUbicacion::factory()->create()->id,
            estado: EstadoDisponibilidad::Disponible,
        );
    }

    private function contexto(int $documentoId = 1): ContextoMovimiento
    {
        return ContextoMovimiento::para(
            tipo: TipoMovimientoPlanta::CargaInicial,
            documentoType: 'Tests\\Documento',
            documentoId: $documentoId,
            transicion: 'confirmar',
            fechaEfectiva: '2026-07-30',
        );
    }

    private function servicio(): PlantaInventarioService
    {
        return app(PlantaInventarioService::class);
    }

    private function saldo(BucketInventario $bucket): ?string
    {
        $valor = DB::table('planta_existencias')->where($bucket->aColumnas())->value('cantidad');

        return $valor === null ? null : bcadd((string) $valor, '0', 4);
    }

    // --- El andamiaje hace lo que dice ---

    public function test_la_suite_si_envuelve_las_pruebas_normales_en_una_transaccion(): void
    {
        // Deja constancia del motivo por el que existe enNivelCero(). Si Laravel
        // dejara de envolver, esta prueba falla y el andamiaje sobra.
        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_en_nivel_cero_el_nivel_es_realmente_cero_y_se_restituye(): void
    {
        $this->enNivelCero(function () {
            $this->assertSame(0, DB::transactionLevel());
        });

        $this->assertSame(1, DB::transactionLevel(), 'El envoltorio debe quedar restituido.');
    }

    // --- FUERA de transacción: se rechaza ---

    public function test_fuera_de_transaccion_lanza_la_excepcion(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            $lanzada = null;

            try {
                $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());
            } catch (InventarioFueraDeTransaccionException $e) {
                $lanzada = $e;
            }

            $this->assertInstanceOf(InventarioFueraDeTransaccionException::class, $lanzada);
            $this->assertStringContainsString('DB::transaction', $lanzada->getMessage());
        });
    }

    public function test_fuera_de_transaccion_no_escribe_absolutamente_nada(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            try {
                $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());
                $this->fail('Se esperaba InventarioFueraDeTransaccionException.');
            } catch (InventarioFueraDeTransaccionException) {
                // esperado
            }

            // Sin transacción, un fallo a mitad dejaría el mayor y el saldo
            // descuadrados para siempre. Por eso no se empieza siquiera.
            $this->assertSame(0, DB::table('planta_movimientos')->count());
            $this->assertSame(0, DB::table('planta_existencias')->count());
        });
    }

    public function test_fuera_de_transaccion_la_guarda_salta_antes_de_consultar_nada(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());
                $this->fail('Se esperaba InventarioFueraDeTransaccionException.');
            } catch (InventarioFueraDeTransaccionException) {
                // esperado
            }

            // Ni una lectura, ni un insertOrIgnore, ni un bloqueo.
            $this->assertSame([], DB::getQueryLog());

            DB::disableQueryLog();
        });
    }

    // --- DENTRO de transacción: el mismo flujo funciona ---

    public function test_el_mismo_flujo_dentro_de_una_transaccion_funciona_y_escribe(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            // Mismo servicio, mismo bucket, misma cantidad, mismo contexto que la
            // prueba de arriba. Lo ÚNICO que cambia es la transacción.
            $movimiento = DB::transaction(
                fn () => $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto()),
                3,
            );

            $this->assertSame(1, DB::table('planta_movimientos')->count());
            $this->assertSame(1, DB::table('planta_existencias')->where($bucket->aColumnas())->count());
            $this->assertSame('10.0000', $movimiento->fresh()->cantidad);
            $this->assertSame('10.0000', $this->saldo($bucket));

            // Y al salir, la transacción se cerró de verdad.
            $this->assertSame(0, DB::transactionLevel());
        });
    }

    public function test_varios_efectos_comparten_una_sola_transaccion(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            DB::transaction(function () use ($bucket) {
                $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto(1));
                $this->servicio()->aplicarMovimiento($bucket, '-4.0000', $this->contexto(2));
            }, 3);

            // Que la transacción la abra el LLAMADOR y no el servicio es lo que
            // permite esto: una operación con varios efectos que confirma o se
            // deshace entera.
            $this->assertSame(2, DB::table('planta_movimientos')->count());
            $this->assertSame('6.0000', $this->saldo($bucket));
        });
    }

    public function test_un_fallo_dentro_de_la_transaccion_lo_deshace_todo_de_verdad(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();

            try {
                DB::transaction(function () use ($bucket) {
                    $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());

                    throw new RuntimeException('fallo posterior al movimiento');
                });
            } catch (RuntimeException) {
                // esperado
            }

            // Sin savepoints de por medio: es un rollback real hasta el nivel 0.
            $this->assertSame(0, DB::table('planta_movimientos')->count());
            $this->assertNull($this->saldo($bucket));
        });
    }

    // --- Contrato de reintentos: DB::transaction($callback, 3) ---

    public function test_el_llamador_debe_usar_tres_intentos_y_el_servicio_lo_soporta(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();
            $intentos = 0;

            // Simula lo que hace InnoDB bajo contención: mata la primera tentativa
            // con un deadlock. Laravel reconoce ese mensaje como error de
            // CONCURRENCIA y reintenta. Es el motivo por el que TODO servicio
            // documental del módulo debe usar DB::transaction($callback, 3).
            $movimiento = DB::transaction(function () use ($bucket, &$intentos) {
                $intentos++;

                if ($intentos === 1) {
                    throw new QueryException(
                        'sqlite',
                        'select 1',
                        [],
                        new PDOException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction'),
                    );
                }

                return $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());
            }, 3);

            $this->assertSame(2, $intentos, 'La tentativa muerta por deadlock debe reintentarse.');

            // Y lo que más importa: el reintento no duplica el efecto.
            $this->assertSame(1, DB::table('planta_movimientos')->count());
            $this->assertSame('10.0000', $movimiento->fresh()->cantidad);
            $this->assertSame('10.0000', $this->saldo($bucket));
        });
    }

    public function test_un_error_que_no_es_de_concurrencia_no_se_reintenta(): void
    {
        $this->enNivelCero(function () {
            $bucket = $this->bucket();
            $intentos = 0;

            try {
                DB::transaction(function () use ($bucket, &$intentos) {
                    $intentos++;

                    $this->servicio()->aplicarMovimiento($bucket, '10.0000', $this->contexto());

                    throw new RuntimeException('error de negocio');
                }, 3);
            } catch (RuntimeException) {
                // esperado
            }

            // Los tres intentos son para deadlocks, no para tapar errores de
            // negocio: repetir uno de esos solo repetiría el error.
            $this->assertSame(1, $intentos);
            $this->assertSame(0, DB::table('planta_movimientos')->count());
        });
    }
}
