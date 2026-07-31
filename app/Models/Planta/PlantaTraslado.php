<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Traslado de inventario entre dos ubicaciones físicas.
 *
 * Es un DOCUMENTO de DOS ACTOS: enviar y recibir. Su estado manda sobre lo que
 * se puede hacer con él, y la máquina de transiciones vive en
 * {@see EstadoTrasladoPlanta}. Los helpers de este modelo sirven para decidir
 * qué botones pintar; la comprobación que vale la hace PlantaTrasladoService
 * dentro de la transacción y con la fila bloqueada, porque ocultar un botón no
 * autoriza nada.
 *
 * No conoce el inventario: los movimientos los escribe PlantaInventarioService.
 */
class PlantaTraslado extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'planta_traslados';

    /**
     * `numero`, `estado`, las firmas de envío y recepción y los punteros de
     * reversión NO son asignables: los escribe el servicio, nunca un formulario.
     */
    protected $fillable = [
        'fecha',
        'planta_ubicacion_origen_id',
        'planta_ubicacion_destino_id',
        'responsable_user_id',
        'responsable_nombre',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoTrasladoPlanta::class,
            'fecha' => 'date',
            'numero' => 'integer',
            'enviado_en' => 'datetime',
            'recibido_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_traslado')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el borrador de traslado',
                'updated' => 'actualizó el borrador de traslado',
                'deleted' => 'eliminó el traslado',
                default => $evento,
            });
    }

    // --- Estado ---

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeEnviarse(): bool
    {
        return $this->estado === EstadoTrasladoPlanta::Borrador;
    }

    public function puedeRecibirse(): bool
    {
        return $this->estado === EstadoTrasladoPlanta::Enviado;
    }

    public function puedeCancelarse(): bool
    {
        return $this->estado === EstadoTrasladoPlanta::Borrador;
    }

    /**
     * Se reversa desde `enviado` (deshacer la salida) o desde `recibido`
     * (compensar la llegada). Solo UNA vez: `revertido_por_id` es lo que lo
     * impide de forma duradera.
     */
    public function puedeReversarse(): bool
    {
        return in_array($this->estado, [EstadoTrasladoPlanta::Enviado, EstadoTrasladoPlanta::Recibido], true)
            && $this->revertido_por_id === null
            && $this->reversion_de_id === null;
    }

    /** ¿El saldo de este traslado está AHORA en la ubicación de tránsito? */
    public function estaEnTransito(): bool
    {
        return $this->estado->estaEnTransito();
    }

    public function esReversion(): bool
    {
        return $this->reversion_de_id !== null;
    }

    // --- Consultas ---

    public function scopeEnTransito(Builder $query): Builder
    {
        return $query->where('estado', EstadoTrasladoPlanta::Enviado->value);
    }

    /** Viajes reales: excluye los documentos de compensación. */
    public function scopeViajes(Builder $query): Builder
    {
        return $query->whereNull('reversion_de_id');
    }

    // --- Relaciones ---

    public function detalles(): HasMany
    {
        return $this->hasMany(PlantaTrasladoDetalle::class, 'planta_traslado_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(PlantaUbicacion::class, 'planta_ubicacion_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(PlantaUbicacion::class, 'planta_ubicacion_destino_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
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

    /**
     * Movimientos cuyo bucket está en TRÁNSITO atado a este traslado. Es la
     * consulta que demuestra que un viaje no se mezcla con otro.
     */
    public function movimientosEnTransito(): HasMany
    {
        return $this->movimientos()->where('planta_traslado_id', $this->getKey());
    }
}
