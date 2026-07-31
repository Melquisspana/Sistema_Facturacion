<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\UnidadBase;
use App\Support\Planta\BucketInventario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una línea de traslado: un insumo, un lote y una cantidad que viajan juntos.
 *
 * Aquí NO hay conversión: lo que viaja ya está en inventario y por tanto ya está
 * en unidad base. `unidad_base` es una instantánea del insumo para que el
 * histórico no cambie si mañana cambia el catálogo.
 *
 * LOS TRES BUCKETS DE UNA LÍNEA. La misma cantidad recorre tres sitios y esta
 * clase sabe nombrarlos:
 *
 *   {@see bucketOrigen()}   disponible en la ubicación de salida, traslado 0
 *   {@see bucketTransito()} disponible en TRÁNSITO, atado a ESTE traslado
 *   {@see bucketDestino()}  disponible en la ubicación de llegada, traslado 0
 *
 * Que el de tránsito lleve el id del traslado es lo que impide que dos viajes
 * del mismo insumo y lote se mezclen: cada uno tiene su propio saldo, y recibir
 * consume exactamente lo que ese viaje mandó.
 *
 * Un traslado solo mueve saldo DISPONIBLE. El retenido y el rechazado no viajan:
 * lo primero está a la espera de una decisión de calidad y lo segundo está fuera
 * de la operación.
 */
class PlantaTrasladoDetalle extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'planta_traslado_detalles';

    /** `unidad_base` NO es asignable: la deriva el servidor desde el insumo. */
    protected $fillable = [
        'planta_traslado_id',
        'planta_insumo_id',
        'planta_lote_id',
        'cantidad',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'unidad_base' => UnidadBase::class,
            // decimal:4 devuelve STRING: la aritmética del inventario nunca pasa por float.
            'cantidad' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_traslado_detalle')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'agregó una línea al traslado',
                'updated' => 'modificó una línea del traslado',
                'deleted' => 'quitó una línea del traslado',
                default => $evento,
            });
    }

    // --- Los tres buckets ---

    /** De dónde sale: disponible en la ubicación de origen, fuera de tránsito. */
    public function bucketOrigen(): BucketInventario
    {
        return $this->bucketEn((int) $this->traslado->planta_ubicacion_origen_id, 0);
    }

    /** Dónde espera: disponible en TRÁNSITO, atado a ESTE traslado. */
    public function bucketTransito(int $ubicacionTransitoId): BucketInventario
    {
        return $this->bucketEn($ubicacionTransitoId, (int) $this->planta_traslado_id);
    }

    /** A dónde llega: disponible en la ubicación de destino, fuera de tránsito. */
    public function bucketDestino(): BucketInventario
    {
        return $this->bucketEn((int) $this->traslado->planta_ubicacion_destino_id, 0);
    }

    private function bucketEn(int $ubicacionId, int $trasladoId): BucketInventario
    {
        return new BucketInventario(
            insumoId: (int) $this->planta_insumo_id,
            loteId: (int) $this->planta_lote_id,
            ubicacionId: $ubicacionId,
            // Solo viaja lo disponible: el retenido y el rechazado no se mueven.
            estado: EstadoDisponibilidad::Disponible,
            trasladoId: $trasladoId,
        );
    }

    // --- Relaciones ---

    public function traslado(): BelongsTo
    {
        return $this->belongsTo(PlantaTraslado::class, 'planta_traslado_id');
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PlantaLote::class, 'planta_lote_id');
    }
}
