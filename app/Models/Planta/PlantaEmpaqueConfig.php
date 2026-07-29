<?php

namespace App\Models\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Services\Planta\EmpaqueConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Qué bolsa y qué viñeta corresponden a una presentación para un mercado y una
 * marca. Configuración declarativa: en esta fase no consume ni descuenta nada.
 *
 * `marca_norm`, `vinieta_key` y `predeterminada_key` NO están en `$fillable` ni
 * se calculan aquí: las mantiene el motor de base de datos como columnas
 * generadas `STORED`. Intentar escribirlas provoca un error del motor, que es
 * exactamente la garantía que se buscaba.
 *
 * Toda escritura debe pasar por {@see EmpaqueConfigService},
 * que valida los invariantes que una FK no puede expresar (que la bolsa sea de
 * tipo bolsa, que la viñeta sea de tipo viñeta, que los insumos estén activos)
 * y serializa el cambio de predeterminada.
 */
class PlantaEmpaqueConfig extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_empaque_configs';

    /** Las tres derivadas quedan fuera a propósito: las calcula la base. */
    protected $fillable = [
        'planta_presentacion_id',
        'planta_insumo_bolsa_id',
        'planta_insumo_vinieta_id',
        'marca',
        'mercado',
        'referencia_cliente',
        'es_predeterminada',
        'activo',
        'vigente_desde',
        'vigente_hasta',
    ];

    /** Columnas mantenidas por el motor; nunca se escriben desde PHP. */
    public const DERIVADAS = ['marca_norm', 'vinieta_key', 'predeterminada_key'];

    protected function casts(): array
    {
        return [
            'mercado' => MercadoPlanta::class,
            'es_predeterminada' => 'boolean',
            'activo' => 'boolean',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'vinieta_key' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_empaque')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la configuración de empaque',
                'updated' => 'actualizó la configuración de empaque',
                'deleted' => 'eliminó la configuración de empaque',
                default => $evento,
            });
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(PlantaPresentacion::class, 'planta_presentacion_id');
    }

    public function bolsa(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_bolsa_id');
    }

    /** Viñeta: opcional, hay empaques que no la llevan. */
    public function vinieta(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_vinieta_id');
    }

    /** Texto de vigencia para listados; ambas fechas son opcionales. */
    public function vigenciaLegible(): string
    {
        $desde = $this->vigente_desde?->format('d/m/Y');
        $hasta = $this->vigente_hasta?->format('d/m/Y');

        return match (true) {
            $desde && $hasta => "{$desde} — {$hasta}",
            (bool) $desde => "desde {$desde}",
            (bool) $hasta => "hasta {$hasta}",
            default => 'sin vigencia definida',
        };
    }
}
