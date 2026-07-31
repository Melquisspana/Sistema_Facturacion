<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\TipoAjuste;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ajuste de inventario: el documento que altera la cantidad física sin
 * contrapartida documental.
 *
 * Es lo que lo hace distinto de todo lo demás del módulo. Una recepción tiene un
 * proveedor detrás, un traslado tiene un camión, un cambio de disponibilidad
 * suma cero. Un ajuste dice «aquí hay más» o «aquí hay menos» porque alguien lo
 * constató, y no hay nada externo que lo respalde: por eso el motivo es
 * obligatorio y por eso es admin-only.
 *
 * El SIGNO de cada movimiento lo decide {@see sumaSaldo()} a partir del tipo,
 * nunca el formulario. La corrección de conteo es la excepción: su signo sale de
 * la diferencia entre lo contado y lo que decía el sistema, calculada al
 * confirmar y bajo bloqueo.
 */
class PlantaAjuste extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Tipos que SUMAN saldo. El resto resta, salvo la corrección de conteo. */
    public const TIPOS_POSITIVOS = [TipoAjuste::CargaInicial, TipoAjuste::Positivo];

    /** Tipos que RESTAN saldo y por tanto exigen saldo suficiente. */
    public const TIPOS_NEGATIVOS = [
        TipoAjuste::Negativo,
        TipoAjuste::Merma,
        TipoAjuste::Dano,
        TipoAjuste::Vencimiento,
    ];

    protected $table = 'planta_ajustes';

    /**
     * `numero`, `estado`, las firmas de confirmación y los punteros de reversión
     * NO son asignables: los escribe el servicio, nunca un formulario.
     */
    protected $fillable = [
        'tipo',
        'fecha',
        'motivo',
        'responsable_user_id',
        'responsable_nombre',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAjustePlanta::class,
            'tipo' => TipoAjuste::class,
            'fecha' => 'date',
            'numero' => 'integer',
            'confirmado_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_ajuste')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el borrador de ajuste',
                'updated' => 'actualizó el borrador de ajuste',
                'deleted' => 'eliminó el ajuste',
                default => $evento,
            });
    }

    // --- Naturaleza del tipo ---

    /** ¿Este tipo SUMA saldo? Null en la corrección de conteo: depende de la línea. */
    public function sumaSaldo(): ?bool
    {
        if (in_array($this->tipo, self::TIPOS_POSITIVOS, true)) {
            return true;
        }

        if (in_array($this->tipo, self::TIPOS_NEGATIVOS, true)) {
            return false;
        }

        // correccion_conteo: el signo lo pone la diferencia de cada línea.
        return null;
    }

    public function esCorreccionDeConteo(): bool
    {
        return $this->tipo === TipoAjuste::CorreccionConteo;
    }

    public function esCargaInicial(): bool
    {
        return $this->tipo->esCargaInicial();
    }

    // --- Estado ---

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeConfirmarse(): bool
    {
        return $this->estado === EstadoAjustePlanta::Borrador;
    }

    public function puedeAnularse(): bool
    {
        return $this->estado === EstadoAjustePlanta::Borrador;
    }

    /** Solo se reversa UNA vez, y solo lo que ya movió inventario. */
    public function puedeReversarse(): bool
    {
        return $this->estado === EstadoAjustePlanta::Confirmado
            && $this->revertido_por_id === null
            && $this->reversion_de_id === null;
    }

    public function esReversion(): bool
    {
        return $this->reversion_de_id !== null;
    }

    // --- Consultas ---

    public function scopeConfirmados(Builder $query): Builder
    {
        return $query->where('estado', EstadoAjustePlanta::Confirmado->value);
    }

    /** Ajustes propios: excluye los documentos de compensación. */
    public function scopeDecisiones(Builder $query): Builder
    {
        return $query->whereNull('reversion_de_id');
    }

    public function scopeDelTipo(Builder $query, TipoAjuste $tipo): Builder
    {
        return $query->where('tipo', $tipo->value);
    }

    // --- Relaciones ---

    public function detalles(): HasMany
    {
        return $this->hasMany(PlantaAjusteDetalle::class, 'planta_ajuste_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_user_id');
    }

    public function reversionDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversion_de_id');
    }

    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revertido_por_id');
    }

    /** Movimientos del mayor generados por este documento. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'documento_id')
            ->where('documento_type', self::class);
    }
}
