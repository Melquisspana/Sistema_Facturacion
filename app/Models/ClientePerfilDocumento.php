<?php

namespace App\Models;

use App\Enums\TipoNotaCredito;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Perfil de exigencias documentales de un cliente. Ver la migración para el porqué de
 * cada campo. Sin fila para un cliente, ese cliente se comporta como siempre.
 */
class ClientePerfilDocumento extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'cliente_perfiles_documento';

    protected $fillable = [
        'cliente_id',
        'activo',
        'codigo_proveedor',
        'formato_export',
        'exige_albaran_en_nc',
        'tolerancia_albaran',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'exige_albaran_en_nc' => 'boolean',
            'tolerancia_albaran' => 'decimal:2',
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
                'created' => 'creó el perfil de documentos del cliente',
                'updated' => 'actualizó el perfil de documentos del cliente',
                'deleted' => 'eliminó el perfil de documentos del cliente',
                default => $evento,
            });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function tiposNc(): HasMany
    {
        return $this->hasMany(ClientePerfilTipoNc::class, 'cliente_perfil_documento_id');
    }

    /** Regla declarada para una modalidad, o null si esa modalidad no está mapeada. */
    public function reglaPara(?TipoNotaCredito $tipo): ?ClientePerfilTipoNc
    {
        if ($tipo === null) {
            return null;
        }

        return $this->tiposNc->firstWhere('tipo_nota_credito', $tipo->value);
    }

    /** ¿Este perfil puede exportar el Excel del cliente? */
    public function exporta(): bool
    {
        return $this->activo && filled($this->formato_export);
    }
}
