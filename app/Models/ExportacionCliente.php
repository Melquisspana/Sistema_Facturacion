<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente de EXPORTACIÓN (destinatario del embarque). Tiene su lista de
 * precios/productos permitidos en exportacion_cliente_productos.
 */
class ExportacionCliente extends Model
{
    use HasFactory;

    protected $table = 'exportacion_clientes';

    protected $fillable = [
        'nombre',
        'direccion',
        'fda_reg_number',
        'contacto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ExportacionClienteProducto::class);
    }

    public function exportaciones(): HasMany
    {
        return $this->hasMany(Exportacion::class);
    }

    /**
     * Precio específico del cliente para un producto (solo asignaciones activas).
     * null = el cliente no tiene precio propio y aplica el fallback al precio base.
     */
    public function precioPara(int $productoId): ?float
    {
        $asignacion = $this->productos
            ->firstWhere(fn (ExportacionClienteProducto $a) => $a->exportacion_producto_id === $productoId && $a->activo);

        return $asignacion !== null ? (float) $asignacion->precio_caja : null;
    }
}
