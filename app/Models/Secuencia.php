<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Contador GLOBAL del sistema. Nada que ver con los correlativos fiscales del MH
 * (modelo {@see Correlativo}), que son por tipo/establecimiento/punto de venta/ambiente.
 *
 * Su razón de existir es {@see siguiente()}: entregar el próximo número con un BLOQUEO DE
 * FILA real (SELECT … FOR UPDATE) en lugar de MAX()+1, que bajo concurrencia le da el
 * mismo número a dos procesos y rompe el unique del destino.
 */
class Secuencia extends Model
{
    /** Numeración comercial visible, compartida por todos los tipos de DTE. */
    public const NUMERO_SISTEMA = 'numero_sistema';

    protected $table = 'secuencias';

    protected $fillable = ['clave', 'ultimo_numero'];

    protected function casts(): array
    {
        return ['ultimo_numero' => 'integer'];
    }

    /**
     * Consulta de la fila del contador BLOQUEADA para escritura. Aislada para poder
     * verificar en pruebas que realmente emite `for update` con la gramática de MySQL
     * (en SQLite la gramática no la imprime, pero cada test corre en una sola conexión).
     */
    public static function consultaBloqueada(string $clave, ?string $conexion = null): Builder
    {
        return static::on($conexion ?? config('database.default'))
            ->where('clave', $clave)
            ->lockForUpdate();
    }

    /**
     * Entrega el SIGUIENTE número de la secuencia y lo persiste, todo bajo bloqueo.
     *
     * Debe invocarse DENTRO de una transacción: sin ella el `FOR UPDATE` se libera de
     * inmediato y dos procesos simultáneos podrían leer el mismo valor. Se exige de forma
     * explícita en vez de abrir una transacción propia porque quien numera un DTE ya está
     * dentro de la transacción de generación, y el número debe vivir o morir con ella.
     *
     * El número entregado se considera CONSUMIDO aunque quien lo pidió falle después: no
     * se reutiliza (salvo rollback de la transacción completa, donde nunca existió).
     *
     * @throws \LogicException si se llama fuera de una transacción
     */
    public static function siguiente(string $clave): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Secuencia::siguiente('.$clave.') debe llamarse dentro de una transacción: '
                .'fuera de ella el bloqueo de fila no protege contra números duplicados.'
            );
        }

        $fila = static::consultaBloqueada($clave)->first();

        if (! $fila) {
            // La fila la crea la migración; este camino solo cubre una clave nueva.
            static::query()->insertOrIgnore([
                'clave' => $clave,
                'ultimo_numero' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $fila = static::consultaBloqueada($clave)->firstOrFail();
        }

        $fila->ultimo_numero = $fila->ultimo_numero + 1;
        $fila->save();

        return $fila->ultimo_numero;
    }

    /** Último número entregado (0 si la secuencia todavía no entregó ninguno). */
    public static function ultimo(string $clave): int
    {
        return (int) (static::query()->where('clave', $clave)->value('ultimo_numero') ?? 0);
    }
}
