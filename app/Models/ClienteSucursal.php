<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sucursal / sala comercial de un cliente fiscal. Ver migración para el detalle.
 *
 * Auditoría: registra alta/edición/baja con spatie/activitylog (nombre, dirección,
 * ubicación, permisos de documento, descuento, condición, retención, activación),
 * solo atributos que cambian. Sin datos sensibles.
 */
class ClienteSucursal extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'cliente_sucursales';

    protected $fillable = [
        'cliente_id',
        'codigo',
        'nombre',
        'direccion',
        'pais_id',
        'departamento_id',
        'municipio_id',
        'distrito_id',
        'telefono',
        'correo',
        'requiere_orden_compra',
        'es_agente_retencion',
        'permite_ccf',
        'permite_nota_credito',
        'descuento_global_default',
        'condicion_operacion_default',
        'activo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'requiere_orden_compra' => 'boolean',
            'es_agente_retencion' => 'boolean',
            'permite_ccf' => 'boolean',
            'permite_nota_credito' => 'boolean',
            'descuento_global_default' => 'decimal:2',
            'condicion_operacion_default' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('sucursal')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la sala/sucursal',
                'updated' => 'actualizó la sala/sucursal',
                'deleted' => 'eliminó la sala/sucursal',
                default => $evento,
            });
    }

    /**
     * ¿Esta sucursal exige orden de compra? Si su valor es null, hereda del cliente.
     */
    public function requiereOrdenCompra(): bool
    {
        return $this->requiere_orden_compra ?? (bool) $this->cliente?->requiere_orden_compra;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function distrito(): BelongsTo
    {
        return $this->belongsTo(Distrito::class);
    }
}
