<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Asignación producto→cliente de exportación con su precio específico.
 * Único por (cliente, producto): el mismo producto no se duplica en el cliente.
 *
 * Auditoría: cada alta, cambio o baja de precio queda con quién lo hizo y el valor
 * anterior, igual que los precios especiales nacionales ({@see ProductoPrecioCliente}).
 * También los que cambia una lista de empaque al finalizarse.
 */
class ExportacionClienteProducto extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'exportacion_cliente_productos';

    protected $fillable = [
        'exportacion_cliente_id',
        'exportacion_producto_id',
        'precio_caja',
        'precio_fijado_en',
        'precio_desde_exportacion_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_caja' => 'decimal:2',
            'precio_fijado_en' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('precio_exportacion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó un precio de exportación',
                'updated' => 'actualizó un precio de exportación',
                'deleted' => 'eliminó un precio de exportación',
                default => $evento,
            });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ExportacionCliente::class, 'exportacion_cliente_id');
    }

    /** Lista de empaque que dejó este precio como vigente, si salió de una. */
    public function listaOrigen(): BelongsTo
    {
        return $this->belongsTo(Exportacion::class, 'precio_desde_exportacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ExportacionProducto::class, 'exportacion_producto_id');
    }

    /**
     * Precio del CLIENTE por unidad/bolsa (precio_caja / unidades_por_caja del
     * producto). Calculado, no se guarda, para evitar inconsistencias.
     */
    public function precioPorUnidad(): ?float
    {
        $unidades = (int) ($this->producto?->unidades_por_caja ?? 0);
        if ($unidades < 1) {
            return null;
        }

        return round((float) $this->precio_caja / $unidades, 2);
    }
}
