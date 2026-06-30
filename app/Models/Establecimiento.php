<?php

namespace App\Models;

use App\Enums\TipoEstablecimiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Establecimiento extends Model
{
    use SoftDeletes;

    protected $table = 'establecimientos';

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nombre',
        'tipo_establecimiento',
        'pais_id',
        'departamento_id',
        'municipio_id',
        'distrito_id',
        'direccion',
        'telefono',
        'correo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_establecimiento' => TipoEstablecimiento::class,
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
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

    public function puntosVenta(): HasMany
    {
        return $this->hasMany(PuntoVenta::class);
    }

    public function correlativos(): HasMany
    {
        return $this->hasMany(Correlativo::class);
    }
}
