<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil ADMINISTRATIVO de exportación (destinatario del embarque): lista de
 * precios/productos permitidos (exportacion_cliente_productos) y datos propios
 * del embarque (FDA reg. number, contacto). El Cliente maestro (`cliente`) es la
 * fuente de verdad para nombre legal, documento fiscal y dirección fiscal — este
 * modelo NUNCA las expone como si fueran suyas; ver nombreLegal()/direccionFiscal().
 * `nombre`/`direccion` propios son datos OPERATIVOS opcionales (alias interno /
 * dirección de entrega o bodega), no el nombre legal ni la dirección fiscal.
 */
class ExportacionCliente extends Model
{
    use HasFactory;

    protected $table = 'exportacion_clientes';

    protected $fillable = [
        'cliente_id',
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

    /** Cliente DTE real vinculado (receptor de la futura FEX). Nullable: puede no estar vinculado todavía. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
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

    /**
     * Nombre LEGAL: del Cliente DTE vinculado (fuente de verdad). Sin vínculo,
     * cae al nombre operativo propio (mejor que mostrar vacío) — caso que solo
     * debería darse antes de vincular un cliente nuevo.
     */
    public function nombreLegal(): string
    {
        return $this->cliente?->nombre ?? $this->nombre;
    }

    /** Dirección FISCAL: SOLO del Cliente DTE vinculado. Sin vínculo, no hay ninguna. */
    public function direccionFiscal(): ?string
    {
        return $this->cliente?->direccion;
    }

    /**
     * Dirección de entrega/bodega propia de este perfil, mostrada SOLO cuando es
     * literalmente distinta de la dirección fiscal (para no repetir el mismo dato
     * dos veces). Comparación exacta (trim), a propósito: no intenta adivinar si
     * dos direcciones "parecidas" son la misma — eso es una decisión de datos, no
     * de presentación (ver el comando de auditoría de exportacion_clientes).
     */
    public function direccionEntregaBodega(): ?string
    {
        if (blank($this->direccion)) {
            return null;
        }

        $fiscal = $this->direccionFiscal();
        if ($fiscal !== null && trim($this->direccion) === trim($fiscal)) {
            return null;
        }

        return $this->direccion;
    }

    /** ¿El Cliente DTE vinculado todavía tiene el documento fiscal provisional (bloquea FEX)? */
    public function tieneDocumentoFiscalProvisional(): bool
    {
        return (bool) $this->cliente?->tieneDocumentoProvisional();
    }
}
