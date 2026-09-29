<?php

namespace App\Models;

use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un CCF que viajó en una salida y qué pasó con él. Un intento por fila: si sobró, en la
 * próxima salida es otra fila. Ver la migración para el porqué de cada columna.
 */
class SalidaRutaEntrega extends Model
{
    use LogsActivity;

    protected $table = 'salida_ruta_entregas';

    protected $fillable = [
        'salida_ruta_id',
        'dte_id',
        'cliente_sucursal_id',
        'resultado',
        'motivo_no_entrega',
        'nota',
        'trae_nota_averia',
        'entregado_por_id',
        'fecha_resultado',
        'origen_registro',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'resultado' => ResultadoEntrega::class,
            'motivo_no_entrega' => MotivoNoEntrega::class,
            'origen_registro' => OrigenRegistroEntrega::class,
            'trae_nota_averia' => 'boolean',
            'fecha_resultado' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['resultado', 'motivo_no_entrega', 'nota', 'trae_nota_averia', 'entregado_por_id', 'fecha_resultado', 'origen_registro'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('salida_ruta_entrega')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'cargó el CCF a la salida',
                'updated' => 'registró la entrega del CCF',
                'deleted' => 'quitó el CCF de la salida',
                default => $evento,
            });
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'dte_id');
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class, 'cliente_sucursal_id');
    }

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'entregado_por_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function estaPendiente(): bool
    {
        return $this->resultado === null;
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereNull('resultado');
    }

    public function scopeEntregadas(Builder $q): Builder
    {
        return $q->where('resultado', ResultadoEntrega::Entregado->value);
    }
}
