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
 * Una línea de ajuste: el bucket afectado y en cuánto.
 *
 * El bucket COMPLETO vive en la línea —insumo, lote, ubicación y estado—, así
 * que un mismo ajuste puede corregir Casa y Fábrica, disponible y rechazado, en
 * una sola firma. La quinta dimensión vale siempre 0: un ajuste nunca toca saldo
 * en tránsito, que solo lo mueven los traslados.
 *
 * `cantidad` es la MAGNITUD, siempre positiva. El signo lo decide el tipo del
 * documento, nunca el formulario.
 *
 * Las tres columnas de conteo solo se usan en `correccion_conteo`, y
 * `cantidad_sistema` merece atención: se lee BAJO BLOQUEO al confirmar, jamás se
 * acepta del formulario. Entre que se escribe el borrador y se confirma, el saldo
 * puede haber cambiado; usar el valor viejo escribiría una diferencia que ya no
 * existe y descuadraría justo lo que se pretendía cuadrar.
 */
class PlantaAjusteDetalle extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'planta_ajuste_detalles';

    /**
     * `cantidad_sistema`, `diferencia` y `unidad_base` NO son asignables: los
     * deriva el servidor. `cantidad` tampoco en las correcciones de conteo, donde
     * sale de la diferencia.
     */
    protected $fillable = [
        'planta_ajuste_id',
        'planta_insumo_id',
        'planta_lote_id',
        'planta_ubicacion_id',
        'estado_disponibilidad',
        'cantidad',
        'cantidad_conteo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado_disponibilidad' => EstadoDisponibilidad::class,
            'unidad_base' => UnidadBase::class,
            // decimal:4 devuelve STRING: la aritmética del inventario nunca pasa por float.
            'cantidad' => 'decimal:4',
            'cantidad_conteo' => 'decimal:4',
            'cantidad_sistema' => 'decimal:4',
            'diferencia' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_ajuste_detalle')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'agregó una línea al ajuste',
                'updated' => 'modificó una línea del ajuste',
                'deleted' => 'quitó una línea del ajuste',
                default => $evento,
            });
    }

    /**
     * El bucket exacto que esta línea altera. `traslado = 0` siempre: un ajuste
     * no toca saldo en viaje.
     */
    public function bucket(): BucketInventario
    {
        return new BucketInventario(
            insumoId: (int) $this->planta_insumo_id,
            loteId: (int) $this->planta_lote_id,
            ubicacionId: (int) $this->planta_ubicacion_id,
            estado: $this->estado_disponibilidad,
            trasladoId: 0,
        );
    }

    /** ¿Esta línea de corrección de conteo no cambia nada? */
    public function diferenciaEsCero(): bool
    {
        return $this->diferencia !== null
            && bccomp((string) $this->diferencia, '0', 4) === 0;
    }

    // --- Relaciones ---

    public function ajuste(): BelongsTo
    {
        return $this->belongsTo(PlantaAjuste::class, 'planta_ajuste_id');
    }

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
