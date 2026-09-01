<?php

namespace App\Models;

use App\Enums\RolEnSalida;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona en UNA salida, con el papel que lleva en ese viaje.
 *
 * `responsable_unico` es la columna redundante que hace cumplir «un responsable como
 * máximo» en la base (ver la migración). La escribe únicamente este modelo, al asignar el
 * rol, y nunca se edita desde un formulario.
 */
class SalidaRutaParticipante extends Model
{
    use HasFactory;

    protected $table = 'salida_ruta_participantes';

    protected $fillable = [
        'salida_ruta_id',
        'rutas_personal_id',
        'rol',
        'responsable_unico',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolEnSalida::class,
        ];
    }

    /**
     * Mantiene `responsable_unico` coherente con `rol` en un solo lugar.
     *
     * Se hace con un hook y no confiando en quien llama porque las dos columnas dicen lo
     * mismo y separarlas es cómo se llega a una salida con dos responsables o con ninguno
     * marcado. Acá la única fuente es `rol`.
     */
    protected static function booted(): void
    {
        static::saving(function (SalidaRutaParticipante $participante) {
            $participante->responsable_unico = $participante->rol === RolEnSalida::Responsable ? 1 : null;
        });
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'rutas_personal_id');
    }

    public function esResponsable(): bool
    {
        return $this->rol === RolEnSalida::Responsable;
    }

    public function scopeResponsable(Builder $q): Builder
    {
        return $q->where('rol', RolEnSalida::Responsable->value);
    }
}
