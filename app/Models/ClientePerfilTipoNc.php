<?php

namespace App\Models;

use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoNotaCredito;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una modalidad interna de NC mapeada al código del cliente, con su regla de descuento.
 * Ver la migración: es la tabla que reemplaza al `if` que trataba avería y devolución
 * por igual.
 */
class ClientePerfilTipoNc extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'cliente_perfil_tipos_nc';

    protected $fillable = [
        'cliente_perfil_documento_id',
        'tipo_nota_credito',
        'codigo_externo',
        'etiqueta_externa',
        'descuento_origen',
        'descuento_tasa',
    ];

    protected function casts(): array
    {
        return [
            'tipo_nota_credito' => TipoNotaCredito::class,
            'descuento_origen' => OrigenDescuentoNc::class,
            'descuento_tasa' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('perfil_documento_cliente')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'mapeó una modalidad de nota de crédito',
                'updated' => 'actualizó el mapeo de una modalidad de nota de crédito',
                'deleted' => 'eliminó el mapeo de una modalidad de nota de crédito',
                default => $evento,
            });
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(ClientePerfilDocumento::class, 'cliente_perfil_documento_id');
    }

    /**
     * Porcentaje FIJO que declara esta regla, o null si el porcentaje no lo decide la
     * regla sino el CCF relacionado (origen `ccf`).
     */
    public function porcentajeFijo(): ?string
    {
        return match ($this->descuento_origen) {
            OrigenDescuentoNc::Ninguno => '0.00',
            OrigenDescuentoNc::TasaPropia => number_format(
                max(0.0, min(100.0, (float) $this->descuento_tasa)), 2, '.', ''
            ),
            default => null,
        };
    }
}
