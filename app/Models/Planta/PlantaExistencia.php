<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Exceptions\Planta\ExistenciaNoEscribibleException;
use App\Support\Planta\BucketInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo actual de un bucket. Es una PROYECCIÓN de `planta_movimientos`, no un
 * dato propio: si esta tabla se borrase entera, `planta:reconciliar-existencias
 * --apply` la reconstruiría sin perder información.
 *
 * SOLO LECTURA desde Eloquent. El modelo no expone `cantidad` como fillable y
 * {@see booted()} rechaza `saving` y `deleting`. Es deliberado que ni siquiera
 * el servicio lo use para escribir: PlantaInventarioService actualiza el saldo
 * con el query builder, dentro de la misma transacción que inserta el
 * movimiento y sobre la fila ya bloqueada con `lockForUpdate`. Así el candado
 * del modelo puede quedarse cerrado del todo, sin puertas traseras del tipo
 * «desactívalo mientras escribo yo», que es exactamente el mecanismo que
 * cualquier otro código acabaría reutilizando.
 *
 * Por qué importa: un saldo cambiado sin el movimiento que lo justifica NO es
 * un error que se note. La suma sigue cuadrando consigo misma; lo único que
 * revela el cambio es comparar contra el mayor, que es lo que hace la
 * reconciliación.
 *
 * LÍMITE HONESTO DEL CANDADO, idéntico al de {@see PlantaMovimiento}: los
 * eventos de Eloquent solo corren si la escritura pasa por una instancia del
 * modelo. `PlantaExistencia::query()->update(...)`, `DB::table(...)->update(...)`
 * y el SQL crudo NO los disparan y no pueden bloquearse desde el ORM. Esto es
 * una defensa en profundidad, no una garantía. Las garantías reales son tres y
 * están en otro sitio:
 *
 *   - el UNIQUE de las cinco dimensiones, que impide dos filas por bucket;
 *   - el CHECK `cantidad >= 0`, que el motor aplica venga de donde venga la
 *     escritura;
 *   - la reconciliación, que detecta y corrige lo que se haya colado.
 */
class PlantaExistencia extends Model
{
    protected $table = 'planta_existencias';

    /**
     * Vacío a propósito. No es que falten columnas por añadir: es que ninguna
     * es asignable desde fuera del motor de inventario.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'estado' => EstadoDisponibilidad::class,
            // decimal:4 devuelve STRING: el saldo nunca pasa por float.
            'cantidad' => 'decimal:4',
            'planta_traslado_id' => 'integer',
            'actualizado_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // `saving` cubre create y update a la vez: ni uno ni otro son legítimos aquí.
        static::saving(function (self $existencia): void {
            throw ExistenciaNoEscribibleException::crear(
                $existencia->exists ? 'edición' : 'creación'
            );
        });

        static::deleting(function (): void {
            throw ExistenciaNoEscribibleException::crear('borrado');
        });
    }

    // --- Bucket ---

    /** Las cinco dimensiones de esta fila, como objeto de valor. */
    public function bucket(): BucketInventario
    {
        return BucketInventario::desdeFila([
            'planta_insumo_id' => $this->planta_insumo_id,
            'planta_lote_id' => $this->planta_lote_id,
            'planta_ubicacion_id' => $this->planta_ubicacion_id,
            'estado' => $this->estado,
            'planta_traslado_id' => $this->planta_traslado_id,
        ]);
    }

    /** Acota la consulta al bucket exacto: las cinco dimensiones, nunca cuatro. */
    public function scopeDelBucket(Builder $query, BucketInventario $bucket): Builder
    {
        return $query->where($bucket->aColumnas());
    }

    /** Solo lo que se puede trasladar o consumir: el saldo disponible. */
    public function scopeUtilizable(Builder $query): Builder
    {
        return $query->where('estado', EstadoDisponibilidad::Disponible->value);
    }

    /** Buckets con saldo. Los que quedaron en cero conservan su historial en el mayor. */
    public function scopeConSaldo(Builder $query): Builder
    {
        return $query->where('cantidad', '>', 0);
    }

    // --- Relaciones ---

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PlantaLote::class, 'planta_lote_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(PlantaUbicacion::class, 'planta_ubicacion_id');
    }
}
