<?php

namespace App\Models\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Concerns\RecortaTextosAColumna;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un HECHO comprobable sobre un documento de cobro, con su evidencia.
 *
 * Es la única fuente de los acumulados: {@see CobroDocumento::recalcularPago()} vuelve a
 * sumar estos eventos cada vez, en lugar de ir incrementando un contador. La diferencia
 * importa: un contador que se incrementa se infla al reprocesar un archivo, y una suma que
 * se rehace no.
 *
 * El único `(documento, tipo, evidencia_hash, referencia_linea)` de la migración es lo que
 * hace que la misma línea del mismo archivo no pueda entrar dos veces, ni por recarga ni
 * por dos archivos que se solapan.
 *
 * `monto` lleva SIGNO propio: un pago suma, una reversión resta. Acá no hay un segundo
 * convenio de signo por tipo, porque arrastrar dos es cómo se termina restando dos veces.
 */
class CobroEvento extends Model
{
    use HasFactory;
    use RecortaTextosAColumna;

    /** @return array<string, int> */
    public static function largosDeTexto(): array
    {
        return [
            'estado_motivo' => 255,
            'evidencia_nombre' => 160,
        ];
    }

    protected $table = 'cobro_eventos';

    protected $fillable = [
        'cobro_documento_id',
        'tipo',
        'origen',
        'estado',
        'estado_motivo',
        'monto',
        'fecha',
        'detalle',
        'evidencia_hash',
        'evidencia_nombre',
        'referencia_linea',
        'datos',
        'user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => 'aplicado',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoCobro::class,
            'estado' => EstadoEventoCobro::class,
            'monto' => 'decimal:2',
            'fecha' => 'date',
            'resuelto_en' => 'datetime',
            'datos' => 'array',
        ];
    }

    /**
     * ¿Este evento suma en los acumulados del documento?
     *
     * Son DOS condiciones y las dos importan: el tipo tiene que mover dinero (una
     * observación no) y el estado tiene que contar (uno en revisión no).
     */
    public function cuenta(): bool
    {
        return $this->tipo->afectaCobrado() && $this->estado->cuenta() && $this->monto !== null;
    }

    /**
     * @param  Builder<CobroEvento>  $q
     */
    public function scopeEnRevision(Builder $q): Builder
    {
        return $q->where('estado', EstadoEventoCobro::EnRevision->value);
    }

    /**
     * Resuelve un evento que estaba en revisión: o cuenta, o se descarta. En los dos casos
     * queda quién y por qué —la decisión es de una persona y tiene que poder rastrearse—.
     */
    public function resolver(EstadoEventoCobro $estado, User $usuario, string $motivo): void
    {
        $this->forceFill([
            'estado' => $estado->value,
            'estado_motivo' => trim($motivo).' — '.$usuario->name.', '.now()->format('d/m/Y H:i'),
            'resuelto_en' => now(),
            'resuelto_por' => $usuario->id,
        ])->save();

        activity('cobros_evento')
            ->performedOn($this)
            ->causedBy($usuario)
            ->withProperties([
                'estado' => $estado->value,
                'motivo' => $motivo,
                'monto' => (string) $this->monto,
                'evidencia' => $this->evidencia_nombre,
            ])
            ->log('resolvió un pago que estaba en revisión');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'cobro_documento_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
