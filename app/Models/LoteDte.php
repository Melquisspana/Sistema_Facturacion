<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoteDte extends Model
{
    protected $table = 'lotes_dte';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respuesta_envio' => 'array', 'respuesta_consulta' => 'array', 'enviado_en' => 'datetime', 'ultima_consulta_en' => 'datetime', 'cerrado_en' => 'datetime'];
    }

    public function contingenciaEvento(): BelongsTo
    {
        return $this->belongsTo(ContingenciaEvento::class);
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }
}
