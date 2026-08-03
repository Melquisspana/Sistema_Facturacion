<?php

namespace App\Models\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\UnidadBaseInmutableException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Qué se almacena. Define cómo se comporta el insumo en el inventario, pero
 * NO conoce saldos: las existencias son otra tabla y se derivan del mayor.
 */
class PlantaInsumo extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_insumos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'unidad_base',
        'controla_lotes',
        'permite_fraccion',
        'factor_conversion_sugerido',
        'unidad_recepcion_sugerida',
        'contenido_sugerido',
        'stock_minimo',
        'activo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoInsumo::class,
            'unidad_base' => UnidadBase::class,
            'controla_lotes' => 'boolean',
            'permite_fraccion' => 'boolean',
            'factor_conversion_sugerido' => 'decimal:8',
            'contenido_sugerido' => 'decimal:4',
            'stock_minimo' => 'decimal:4',
            'activo' => 'boolean',
        ];
    }

    /**
     * Candado de la UNIDAD BASE. Solo actúa si la unidad está cambiando de
     * verdad: un `update()` que no la toca —`toggleActivo()`, un cambio de
     * nombre o de stock mínimo— ni siquiera consulta el historial.
     *
     * Por qué existe: `unidad_base` no es un rótulo del catálogo, es la unidad
     * en la que están escritas TODAS las cantidades del insumo. El mayor guarda
     * una copia congelada al escribir cada asiento y `planta_existencias` la
     * toma del catálogo por JOIN, así que cambiarla con historial dejaría el
     * mayor diciendo «libras» y el saldo presentándose como «unidades» sin que
     * ninguna cifra hubiera cambiado.
     *
     * Es la capa DEFENSIVA. El usuario nunca ve esta excepción: el flujo HTTP lo
     * detiene antes en `InsumoRequest`, con un error de validación sobre el
     * campo. Esto cubre lo que no pasa por el formulario.
     *
     * Mismo límite que el resto de candados de Eloquent del módulo: no cubre
     * `PlantaInsumo::query()->update(...)` ni el SQL crudo, porque esas rutas no
     * materializan el modelo. Defensa en profundidad, no garantía.
     */
    protected static function booted(): void
    {
        static::updating(function (self $insumo): void {
            if (! $insumo->isDirty('unidad_base') || ! $insumo->tieneHistorialDeInventario()) {
                return;
            }

            // Valores CRUDOS: construir el mensaje no puede depender del cast,
            // que lanzaría con un valor inválido y taparía el error real.
            throw UnidadBaseInmutableException::tieneHistorial(
                (int) $insumo->getKey(),
                (string) $insumo->getRawOriginal('unidad_base'),
                (string) ($insumo->getAttributes()['unidad_base'] ?? ''),
            );
        });
    }

    /**
     * ¿Este insumo ya entró en operación?
     *
     * ÚNICA DEFINICIÓN DE «HISTORIAL» del módulo: la comparten el candado de
     * arriba y `InsumoRequest`, para que la validación que ve el usuario y la
     * que protege la escritura no puedan divergir nunca.
     *
     * Dos señales y ninguna más:
     *   - `planta_movimientos`: la verdad. Es append-only, no se borra jamás y
     *     guarda la unidad congelada en cada asiento.
     *   - `planta_existencias`: red de seguridad para la proyección que el mayor
     *     no respalde. Una fila EN CERO también cuenta: se conserva a propósito
     *     cuando su bucket existió y se vació.
     *
     * NO mira lotes, líneas de documento ni empaques, y es deliberado: un lote
     * real solo nace al confirmar una recepción —luego implica movimientos— y no
     * guarda unidad; un borrador todavía no escribió nada; y las configuraciones
     * de empaque no almacenan cantidades.
     *
     * Corta en la primera señal: si hay movimientos, no llega a mirar saldos.
     */
    public function tieneHistorialDeInventario(): bool
    {
        return $this->movimientos()->exists() || $this->existencias()->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_insumo')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el insumo de planta',
                'updated' => 'actualizó el insumo de planta',
                'deleted' => 'eliminó el insumo de planta',
                default => $evento,
            });
    }

    /** Lotes de este insumo. La FK es restrictOnDelete: no se borra con historial. */
    public function lotes(): HasMany
    {
        return $this->hasMany(PlantaLote::class, 'planta_insumo_id');
    }

    /** Configuraciones de empaque donde este insumo actúa como bolsa. */
    public function configsComoBolsa(): HasMany
    {
        return $this->hasMany(PlantaEmpaqueConfig::class, 'planta_insumo_bolsa_id');
    }

    /** Configuraciones de empaque donde este insumo actúa como viñeta. */
    public function configsComoVinieta(): HasMany
    {
        return $this->hasMany(PlantaEmpaqueConfig::class, 'planta_insumo_vinieta_id');
    }

    /** Movimientos del mayor que afectan a este insumo. FK restrictOnDelete. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'planta_insumo_id');
    }

    /**
     * Saldos por bucket de este insumo. Es una PROYECCIÓN del mayor y es de solo
     * lectura: el insumo sigue sin conocer saldos propios, únicamente da acceso
     * a los que se derivan de sus movimientos.
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(PlantaExistencia::class, 'planta_insumo_id');
    }
}
