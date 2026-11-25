<?php

namespace App\Models;

use App\Enums\CategoriaProductoExportacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * El dulce, independiente de cómo se empaca: «Maní dulce». Sus presentaciones
 * (144 u en 12×12, 216 u en 12×18…) son {@see ExportacionProducto}, y es ahí
 * donde cuelgan los precios de cliente y los items de las listas.
 *
 * Auditoría: renombrar o archivar el producto queda acá. Las presentaciones que
 * cambian con él (nombre y archivado) se actualizan en una sola consulta y no
 * dejan registro propio: el del producto base lo explica.
 */
class ExportacionProductoBase extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'exportacion_productos_base';

    protected $fillable = [
        'nombre_es',
        'nombre_en',
        'categoria',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaProductoExportacion::class,
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('producto_exportacion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó un producto de exportación',
                'updated' => 'actualizó un producto de exportación',
                'deleted' => 'eliminó un producto de exportación',
                default => $evento,
            });
    }

    public function presentaciones(): HasMany
    {
        return $this->hasMany(ExportacionProducto::class, 'exportacion_producto_base_id');
    }
}
