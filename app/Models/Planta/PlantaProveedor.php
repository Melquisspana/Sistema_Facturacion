<?php

namespace App\Models\Planta;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A quién se le compran los insumos. Catálogo propio de Planta, sin relación
 * con `clientes` ni con los documentos recibidos de Facturación.
 */
class PlantaProveedor extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_proveedores';

    protected $fillable = [
        'nombre',
        'nombre_comercial',
        'telefono',
        'correo',
        'contacto',
        'direccion',
        'nit',
        'nrc',
        'observaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_proveedor')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el proveedor de planta',
                'updated' => 'actualizó el proveedor de planta',
                'deleted' => 'eliminó el proveedor de planta',
                default => $evento,
            });
    }

    /** Lotes entregados por este proveedor. */
    public function lotes(): HasMany
    {
        return $this->hasMany(PlantaLote::class, 'planta_proveedor_id');
    }
}
