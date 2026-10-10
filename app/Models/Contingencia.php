<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contingencia extends Model
{
    protected $table = 'contingencias';

    protected $fillable = ['tipo', 'motivo', 'origen', 'inicio', 'cese', 'estado', 'activada_por', 'cerrada_por'];

    protected function casts(): array
    {
        return ['tipo' => 'integer', 'inicio' => 'datetime', 'cese' => 'datetime'];
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ContingenciaEvento::class);
    }

    public function activadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activada_por');
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }
}
