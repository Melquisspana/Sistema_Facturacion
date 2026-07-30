<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROYECCIÓN MATERIALIZADA del saldo por bucket. No es la verdad: la verdad es
 * `planta_movimientos`. Esta tabla existe solo para no tener que sumar el mayor
 * entero cada vez que alguien pregunta «cuánto hay», y puede reconstruirse
 * completa con `planta:reconciliar-existencias --apply`.
 *
 * Cada fila es un bucket: la MISMA tupla de cinco dimensiones del mayor, en el
 * mismo orden. El `UNIQUE` sobre las cinco es lo que convierte «un bucket» en
 * «una fila»: sin él, dos procesos concurrentes crearían dos filas para el
 * mismo saldo y ninguna sería correcta.
 *
 * `planta_traslado_id` es `NOT NULL DEFAULT 0` por la misma razón que en el
 * mayor: NULL rompería el único (dos NULL nunca son iguales) justo en el caso
 * común. La ausencia de FK y su validación compensatoria están documentadas en
 * la cabecera de `2026_07_30_100000_create_planta_movimientos_table.php`.
 *
 * CHECK `cantidad >= 0`
 * --------------------
 * Última línea de defensa del motor, por debajo del servicio. El servicio ya
 * rechaza dejar un bucket en negativo, pero el CHECK cubre lo que el servicio
 * no ve: un UPDATE crudo, un script de mantenimiento, un bug futuro.
 *
 * Se implementa distinto en cada motor porque los motores son distintos:
 *
 *   - MySQL 8.4 aplica `CHECK` de verdad desde 8.0.16, y admite añadirlo con
 *     `ALTER TABLE ... ADD CONSTRAINT`.
 *   - SQLite SOLO admite `CHECK` dentro del `CREATE TABLE`, y no tiene
 *     `ALTER TABLE ... ADD CONSTRAINT`. Como el `CREATE TABLE` lo emite el
 *     Blueprint —que no expone constraints de tabla—, se usa el equivalente
 *     canónico de SQLite: dos triggers `BEFORE INSERT`/`BEFORE UPDATE` con
 *     `RAISE(ABORT)`.
 *
 * La diferencia es de implementación, no de garantía: en ambos motores una
 * cantidad negativa aborta la sentencia y sale como QueryException. Las pruebas
 * lo verifican en SQLite (la suite) y el paso de verificación manual lo repite
 * contra MySQL 8.4.
 *
 * Los triggers de SQLite desaparecen solos al hacer DROP TABLE; el `down()` los
 * elimina igualmente de forma explícita para no depender de ese detalle.
 *
 * NOTA: el CHECK es `>= 0`, no `> 0`. Una fila en cero es legítima: es un
 * bucket que existió y se vació, y su historial sigue en el mayor.
 */
return new class extends Migration
{
    /** Nombre compartido por el CHECK de MySQL y los triggers de SQLite. */
    private const CHECK = 'planta_exist_cantidad_no_negativa';

    public function up(): void
    {
        Schema::create('planta_existencias', function (Blueprint $table) {
            $table->id();

            // --- Bucket: las cinco dimensiones, en el mismo orden que el mayor ---
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')
                ->constrained('planta_lotes')->restrictOnDelete();
            $table->foreignId('planta_ubicacion_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete();
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad: disponible|retenido|rechazado');
            $table->unsignedBigInteger('planta_traslado_id')->default(0)
                ->comment('0 = fuera de tránsito. >0 solo en ubicación TRANSITO. SIN FK: ver el mayor');

            $table->decimal('cantidad', 14, 4)->default(0)
                ->comment('Saldo actual del bucket. Nunca negativo (CHECK). Solo lo escribe el servicio');
            $table->timestamp('actualizado_en')->nullable()
                ->comment('Cuándo lo movió el último movimiento; distinto de updated_at');

            $table->timestamps();

            // Un bucket = una fila. Es lo que hace segura la creación concurrente.
            $table->unique([
                'planta_insumo_id',
                'planta_lote_id',
                'planta_ubicacion_id',
                'estado',
                'planta_traslado_id',
            ], 'planta_exist_bucket_unico');
        });

        $this->crearCheckCantidadNoNegativa();
    }

    public function down(): void
    {
        $this->eliminarCheckCantidadNoNegativa();

        Schema::dropIfExists('planta_existencias');
    }

    /** CHECK nativo donde el motor lo soporta; triggers equivalentes en SQLite. */
    private function crearCheckCantidadNoNegativa(): void
    {
        $mensaje = 'planta_existencias.cantidad no puede ser negativa';

        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $evento) {
                $nombre = self::CHECK.'_'.strtolower($evento);

                DB::statement(<<<SQL
                    CREATE TRIGGER {$nombre}
                    BEFORE {$evento} ON planta_existencias
                    FOR EACH ROW WHEN NEW.cantidad < 0
                    BEGIN SELECT RAISE(ABORT, '{$mensaje}'); END
                SQL);
            }

            return;
        }

        DB::statement(
            'ALTER TABLE planta_existencias ADD CONSTRAINT '.self::CHECK.' CHECK (cantidad >= 0)'
        );
    }

    /**
     * Retira la restricción antes de soltar la tabla.
     *
     * El `DROP TABLE` se llevaría el CHECK por delante de todos modos; esto solo
     * evita dejarlo colgando si alguien invoca `down()` sin llegar a soltar la
     * tabla, y mantiene simétrico lo que hace `up()`.
     *
     * MySQL NO admite `DROP CONSTRAINT IF EXISTS` —es un error de sintaxis, no un
     * no-op—, así que la existencia se consulta antes en `information_schema` y se
     * usa `DROP CHECK`, que es la forma que entiende. En SQLite los triggers sí
     * admiten `IF EXISTS`.
     */
    private function eliminarCheckCantidadNoNegativa(): void
    {
        if (! Schema::hasTable('planta_existencias')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $evento) {
                DB::statement('DROP TRIGGER IF EXISTS '.self::CHECK.'_'.$evento);
            }

            return;
        }

        $existe = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS '
            ."WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'planta_existencias' "
            .'AND CONSTRAINT_NAME = ?',
            [self::CHECK]
        );

        if ((int) $existe->total > 0) {
            DB::statement('ALTER TABLE planta_existencias DROP CHECK '.self::CHECK);
        }
    }
};
