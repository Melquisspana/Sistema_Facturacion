<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\User;
use App\Support\Planta\BucketInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Documento que libera o rechaza saldo retenido.
 *
 * Es un DOCUMENTO: su estado manda sobre lo que se puede hacer con él, y la
 * máquina de transiciones vive en {@see EstadoCambioDisponibilidad}. Los helpers
 * de este modelo sirven para decidir qué botones pintar; la comprobación que
 * vale la hace PlantaCambioDisponibilidadService dentro de la transacción y con
 * la fila bloqueada, porque ocultar un botón no autoriza nada.
 *
 * No conoce el inventario. Los dos movimientos que emite al confirmarse los
 * escribe PlantaInventarioService.
 */
class PlantaCambioDisponibilidad extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Transiciones de disponibilidad admitidas por este documento. */
    public const TRANSICIONES = [
        // Liberar lo que estaba a la espera de revisión.
        'disponible',
        // Rechazarlo: terminal en Fase 2, solo sale con un ajuste.
        'rechazado',
    ];

    protected $table = 'planta_cambios_disponibilidad';

    /**
     * `numero`, `estado`, `estado_origen`, `confirmado_*` y los punteros de
     * reversión NO son asignables: los escribe el servicio, nunca un formulario.
     */
    protected $fillable = [
        'planta_insumo_id',
        'planta_lote_id',
        'planta_ubicacion_id',
        'estado_destino',
        'cantidad',
        'fecha',
        'motivo',
        'responsable_user_id',
        'responsable_nombre',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCambioDisponibilidad::class,
            'estado_origen' => EstadoDisponibilidad::class,
            'estado_destino' => EstadoDisponibilidad::class,
            // decimal:4 devuelve STRING: la aritmética del inventario nunca pasa por float.
            'cantidad' => 'decimal:4',
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
            ->useLogName('planta_cambio_disponibilidad')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el borrador de cambio de disponibilidad',
                'updated' => 'actualizó el borrador de cambio de disponibilidad',
                'deleted' => 'eliminó el cambio de disponibilidad',
                default => $evento,
            });
    }

    // --- Estado del documento ---

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeConfirmarse(): bool
    {
        return $this->estado === EstadoCambioDisponibilidad::Borrador;
    }

    public function puedeAnularse(): bool
    {
        return $this->estado === EstadoCambioDisponibilidad::Borrador;
    }

    /**
     * Solo se reversa UNA vez. `revertido_por_id` es lo que lo impide de forma
     * duradera: el estado por sí solo no distinguiría un documento reversado de
     * otro que llegó a `reversado` por cualquier vía.
     */
    public function puedeReversarse(): bool
    {
        return $this->estado === EstadoCambioDisponibilidad::Confirmado
            && $this->revertido_por_id === null
            && $this->reversion_de_id === null;
    }

    /** ¿Este documento COMPENSA a otro, en vez de ser una decisión propia? */
    public function esReversion(): bool
    {
        return $this->reversion_de_id !== null;
    }

    /** ¿Libera saldo, o lo rechaza? Solo cambia la etiqueta que lee la persona. */
    public function esLiberacion(): bool
    {
        return $this->estado_destino === EstadoDisponibilidad::Disponible;
    }

    public function accionLegible(): string
    {
        return $this->esLiberacion() ? 'Liberación' : 'Rechazo';
    }

    // --- Buckets ---

    /** Bucket del que SALE la cantidad. */
    public function bucketOrigen(): BucketInventario
    {
        return $this->bucketEn($this->estado_origen);
    }

    /** Bucket al que ENTRA la cantidad. */
    public function bucketDestino(): BucketInventario
    {
        return $this->bucketEn($this->estado_destino);
    }

    private function bucketEn(EstadoDisponibilidad $estado): BucketInventario
    {
        return new BucketInventario(
            insumoId: (int) $this->planta_insumo_id,
            loteId: (int) $this->planta_lote_id,
            ubicacionId: (int) $this->planta_ubicacion_id,
            estado: $estado,
            // Este documento nunca toca saldo en tránsito: eso son los traslados.
            trasladoId: 0,
        );
    }

    // --- Consultas ---

    public function scopeConfirmados(Builder $query): Builder
    {
        return $query->where('estado', EstadoCambioDisponibilidad::Confirmado->value);
    }

    /** Decisiones propias: excluye los documentos de compensación. */
    public function scopeDecisiones(Builder $query): Builder
    {
        return $query->whereNull('reversion_de_id');
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

    public function reversionDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversion_de_id');
    }

    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revertido_por_id');
    }

    /** Movimientos del mayor generados por este documento (par compensado). */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'documento_id')
            ->where('documento_type', self::class);
    }
}
