<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\MovimientoInmutableException;
use App\Models\User;
use App\Support\Planta\BucketInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una fila del LIBRO MAYOR de inventario: un efecto firmado sobre un bucket.
 *
 * Es la fuente de verdad del saldo. `PlantaExistencia` es solo su proyección y
 * puede reconstruirse entera desde aquí.
 *
 * APPEND-ONLY, en tres capas de dureza decreciente:
 *
 *   1. ESQUEMA — la tabla no tiene `updated_at` ni `deleted_at`. No hay dónde
 *      registrar una modificación porque no debe haberla.
 *   2. MODELO — {@see booted()} lanza {@see MovimientoInmutableException} en
 *      `updating` y `deleting`.
 *   3. SUPERFICIE — no hay ruta, controlador ni Form Request que llegue aquí;
 *      el único escritor es PlantaInventarioService.
 *
 * LÍMITE HONESTO DE LA CAPA 2, para que nadie la confunda con una garantía: los
 * eventos de Eloquent solo se disparan cuando la escritura pasa por una
 * INSTANCIA del modelo. NO se disparan en:
 *
 *   - `PlantaMovimiento::query()->update([...])` y `->delete()`, que compilan
 *     una sentencia contra la tabla sin materializar modelos;
 *   - `DB::table('planta_movimientos')->update(...)`;
 *   - SQL crudo, un cliente de base de datos o una restauración parcial.
 *
 * Ninguna de esas rutas puede bloquearse desde Eloquent —el ORM no está en el
 * camino—, y por eso el modelo NO es la defensa principal. La defensa real es
 * que nada del dominio necesita hacerlo, más `planta:reconciliar-existencias`,
 * que compara la proyección contra el mayor y delata cualquier escritura
 * lateral que haya descuadrado el saldo.
 *
 * SIN LogsActivity a propósito: el mayor YA es el registro de auditoría. Un log
 * de actividad sobre una tabla append-only duplicaría cada fila sin añadir un
 * solo dato nuevo.
 *
 * SIN factory, también a propósito: una factory sería un atajo para crear
 * movimientos sin pasar por PlantaInventarioService, es decir, mayor sin saldo
 * proyectado. Las pruebas que necesitan un mayor corrupto lo escriben con el
 * query builder, de forma explícita y visible.
 */
class PlantaMovimiento extends Model
{
    /**
     * No hay `updated_at`: una fila del mayor no se actualiza jamás. Laravel
     * deja de tocar la columna en cuanto esta constante es null.
     */
    public const UPDATED_AT = null;

    protected $table = 'planta_movimientos';

    /**
     * Columnas escribibles EN LA INSERCIÓN. Después de esa, ninguna lo es.
     * `efecto_uid` no está: lo calcula el servicio a partir del bucket y el
     * contexto, nunca lo aporta el llamador.
     */
    protected $fillable = [
        'planta_insumo_id',
        'planta_lote_id',
        'planta_ubicacion_id',
        'estado',
        'planta_traslado_id',
        'cantidad',
        'unidad_base',
        'tipo',
        'documento_type',
        'documento_id',
        'documento_detalle_id',
        'transicion',
        'grupo_uuid',
        'movimiento_revertido_id',
        'user_id',
        'responsable_nombre',
        'fecha_efectiva',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoDisponibilidad::class,
            'tipo' => TipoMovimientoPlanta::class,
            'unidad_base' => UnidadBase::class,
            // decimal:4 devuelve STRING: el saldo nunca pasa por float.
            'cantidad' => 'decimal:4',
            'planta_traslado_id' => 'integer',
            'documento_id' => 'integer',
            'documento_detalle_id' => 'integer',
            'fecha_efectiva' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movimiento): void {
            throw MovimientoInmutableException::actualizar($movimiento->id);
        });

        static::deleting(function (self $movimiento): void {
            throw MovimientoInmutableException::eliminar($movimiento->id);
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

    /** Movimientos de una misma operación. */
    public function scopeDelGrupo(Builder $query, string $grupoUuid): Builder
    {
        return $query->where('grupo_uuid', $grupoUuid);
    }

    /** Movimientos generados por un documento concreto. */
    public function scopeDelDocumento(Builder $query, string $tipo, int $id): Builder
    {
        return $query->where('documento_type', $tipo)->where('documento_id', $id);
    }

    // --- Lectura ---

    /** Saldo del bucket ANTES de este movimiento, tal como se congeló al escribirlo. */
    public function saldoAntes(): ?string
    {
        $valor = $this->metadata['saldo_antes'] ?? null;

        return $valor === null ? null : (string) $valor;
    }

    /** Saldo del bucket DESPUÉS de este movimiento. */
    public function saldoDespues(): ?string
    {
        $valor = $this->metadata['saldo_despues'] ?? null;

        return $valor === null ? null : (string) $valor;
    }

    public function esEntrada(): bool
    {
        return bccomp((string) $this->cantidad, '0', 4) === 1;
    }

    public function esSalida(): bool
    {
        return bccomp((string) $this->cantidad, '0', 4) === -1;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Movimiento original que este compensa. Solo en tipos `reversion_*`. */
    public function revertido(): BelongsTo
    {
        return $this->belongsTo(self::class, 'movimiento_revertido_id');
    }

    /** Compensaciones que se han escrito contra este movimiento. */
    public function reversiones(): HasMany
    {
        return $this->hasMany(self::class, 'movimiento_revertido_id');
    }
}
