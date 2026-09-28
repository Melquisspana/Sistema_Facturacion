<?php

namespace App\Models;

use App\Enums\CategoriaProductoExportacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El dulce, independiente de cómo se empaca: «Maní dulce». Sus presentaciones
 * (144 u en 12×12, 216 u en 12×18…) son {@see ExportacionProducto}, y es ahí
 * donde cuelgan los precios de cliente y los items de las listas.
 */
class ExportacionProductoBase extends Model
{
    use HasFactory;

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

    public function presentaciones(): HasMany
    {
        return $this->hasMany(ExportacionProducto::class, 'exportacion_producto_base_id');
    }
}
