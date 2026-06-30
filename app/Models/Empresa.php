<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
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
