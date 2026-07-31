<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Recepción de insumos: la entrada física de mercancía a una ubicación.
 *
 * Es un DOCUMENTO, no un catálogo: su estado manda sobre lo que se puede hacer
 * con él, y la máquina de transiciones vive en {@see EstadoRecepcionPlanta}. La
 * comprobación de estado que importa la hace PlantaRecepcionService DENTRO de la
 * transacción y DESPUÉS de bloquear la fila; los helpers de este modelo
 * ({@see esEditable()}, {@see puedeConfirmarse()}) sirven para decidir qué
 * botones pintar, y ocultar un botón nunca es autorizar.
 *
 * No conoce el inventario. No inserta movimientos ni toca existencias: eso solo
 * lo hace PlantaInventarioService, invocado por el servicio del documento.
 */
class PlantaRecepcion extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'planta_recepciones';

    /**
     * `numero`, `estado`, `confirmado_por`, `confirmado_en`, `reversion_de_id` y
     * `revertido_por_id` NO son asignables: los escribe el servicio, nunca un
     * formulario.
     */
    protected $fillable = [
        'fecha',
        'planta_proveedor_id',
        'planta_ubicacion_id',
        'documento_referencia',
        'responsable_user_id',
        'responsable_nombre',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoRecepcionPlanta::class,
            'fecha' => 'date',
            'numero' => 'integer',
            'confirmado_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_recepcion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el borrador de recepción',
                'updated' => 'actualizó el borrador de recepción',
                'deleted' => 'eliminó la recepción',
                default => $evento,
            });
    }

    // --- Estado ---

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeConfirmarse(): bool
    {
        return $this->estado === EstadoRecepcionPlanta::Borrador;
    }

    public function puedeAnularse(): bool
    {
        return $this->estado === EstadoRecepcionPlanta::Borrador;
    }

    /**
     * Una recepción solo se reversa UNA vez. `revertido_por_id` es lo que lo
     * impide de forma duradera: el estado por sí solo no distinguiría un
     * documento reversado de otro que llegó a `reversada` por cualquier vía.
     */
    public function puedeReversarse(): bool
    {
        return $this->estado === EstadoRecepcionPlanta::Confirmada
            && $this->revertido_por_id === null
            && $this->reversion_de_id === null;
    }

    /** ¿Este documento es la COMPENSACIÓN de otro, en vez de una entrada real? */
    public function esReversion(): bool
    {
        return $this->reversion_de_id !== null;
    }

    // --- Consultas ---

    public function scopeConfirmadas(Builder $query): Builder
    {
        return $query->where('estado', EstadoRecepcionPlanta::Confirmada->value);
    }

    public function scopeBorradores(Builder $query): Builder
    {
        return $query->where('estado', EstadoRecepcionPlanta::Borrador->value);
    }

    /** Entradas reales: excluye los documentos de compensación. */
    public function scopeEntradas(Builder $query): Builder
    {
        return $query->whereNull('reversion_de_id');
    }

    // --- Relaciones ---

    public function detalles(): HasMany
    {
        return $this->hasMany(PlantaRecepcionDetalle::class, 'planta_recepcion_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(PlantaProveedor::class, 'planta_proveedor_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(PlantaUbicacion::class, 'planta_ubicacion_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_user_id');
    }

    /** Documento original que este compensa. Solo en documentos de reversión. */
    public function reversionDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversion_de_id');
    }

    /** Documento de compensación que reversó a este. Solo en originales reversados. */
    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revertido_por_id');
    }

    /**
     * Movimientos del libro mayor generados por este documento. La relación es
     * por el par polimórfico suelto que guarda el mayor, no por una FK.
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'documento_id')
            ->where('documento_type', self::class);
    }
}
