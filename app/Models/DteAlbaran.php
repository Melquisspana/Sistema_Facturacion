<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Albarán de crédito que originó una nota de crédito. Ver la migración para el detalle,
 * incluida la razón por la que la unicidad del número vive en un scope y no en un índice.
 */
class DteAlbaran extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'dte_albaranes';

    protected $fillable = [
        'dte_id',
        'numero_canonico',
        'tipo_codigo',
        'sala_codigo',
        'numero',
        'fecha',
        'total',
        'ppq_albaran_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('albaran_nota_credito')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'registró el albarán de la nota de crédito',
                'updated' => 'actualizó el albarán de la nota de crédito',
                'deleted' => 'quitó el albarán de la nota de crédito',
                default => $evento,
            });
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function ppqAlbaran(): BelongsTo
    {
        return $this->belongsTo(PpqAlbaran::class, 'ppq_albaran_id');
    }

    /**
     * Albaranes cuya NC todavía «ocupa» el albarán. Reutiliza el MISMO criterio de
     * vigencia del saldo acreditable ({@see Dte::scopeConsumeSaldoAcreditable()}) para no
     * tener dos definiciones distintas de «NC que aún cuenta»: quedan fuera las
     * invalidadas y las rechazadas-archivadas, y el scope global de SoftDeletes deja
     * fuera los borradores eliminados. En los tres casos el albarán vuelve a estar libre.
     */
    public function scopeDeNotasVigentes(Builder $q): Builder
    {
        return $q->whereHas('dte', fn (Builder $d) => $d->consumeSaldoAcreditable());
    }
}
