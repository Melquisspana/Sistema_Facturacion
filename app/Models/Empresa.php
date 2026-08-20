<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Empresa emisora (registro único).
 *
 * AVISO SOBRE `ambiente`: la columna existe y se sigue casteando, pero NO es la
 * fuente de verdad del ambiente fiscal y ningún consumidor la lee. El ambiente que
 * entra al JSON del MH sale siempre de `config('dte.ambiente')` (DTE_AMBIENTE).
 * Desde Configuración → Empresa emisora ya no se puede editar (ver EmpresaRequest);
 * queda escribible solo por seeders/migraciones mientras se decide si la columna se
 * elimina. No la uses para decidir nada fiscal.
 */
class Empresa extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'razon_social',
        'nombre_comercial',
        'nit',
        'nrc',
        'actividad_economica_id',
        'pais_id',
        'departamento_id',
        'municipio_id',
        'distrito_id',
        'direccion',
        'telefono',
        'correo',
        'ambiente',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'ambiente' => AmbienteHacienda::class,
            'activo' => 'boolean',
        ];
    }

    /**
     * Auditoría del marco fiscal. Cambiar la razón social, el NIT o el NRC del emisor
     * altera TODOS los documentos siguientes ante Hacienda; hasta ahora no dejaba
     * ningún rastro. Ningún campo de esta tabla es un secreto, así que se registra el
     * antes y el después completo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('configuracion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'registró la empresa emisora',
                'updated' => 'modificó los datos de la empresa emisora',
                'deleted' => 'eliminó la empresa emisora',
                'restored' => 'restauró la empresa emisora',
                default => $evento,
            });
    }

    public function actividadEconomica(): BelongsTo
    {
        return $this->belongsTo(ActividadEconomica::class);
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function distrito(): BelongsTo
    {
        return $this->belongsTo(Distrito::class);
    }

    public function establecimientos(): HasMany
    {
        return $this->hasMany(Establecimiento::class);
    }
}
