<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContingenciaEvento extends Model
{
    protected $table = 'contingencia_eventos';

    protected $fillable = ['contingencia_id', 'parte', 'codigo_generacion', 'estado', 'json_path', 'jws_path', 'respuesta_mh_path', 'respuesta_mh', 'sello_recibido', 'fecha_transmision', 'fecha_procesamiento', 'rechazado_en'];

    protected function casts(): array
    {
        return ['parte' => 'integer', 'respuesta_mh' => 'array', 'fecha_transmision' => 'datetime', 'fecha_procesamiento' => 'datetime', 'rechazado_en' => 'datetime'];
    }

    public function contingencia(): BelongsTo
    {
        return $this->belongsTo(Contingencia::class);
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }
}
