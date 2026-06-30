<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Distrito (tercer nivel territorial, reforma 2024 de El Salvador).
 * Lleva su departamento y el nombre del municipio 2024 (agrupación) al que pertenece.
 */
class Distrito extends Model
{
    protected $table = 'distritos';

    protected $fillable = [
        'departamento_id',
        'municipio',
        'codigo',
        'nombre',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }
}
