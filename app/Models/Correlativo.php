<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Correlativo extends Model
{
    protected $table = 'correlativos';

    protected $fillable = [
        'tipo_dte',
        'establecimiento_id',
        'punto_venta_id',
        'ambiente',
        'serie',
        'ultimo_numero',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_dte' => TipoDte::class,
            'ambiente' => AmbienteHacienda::class,
            'ultimo_numero' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * Siguiente número que se asignaría (solo lectura/informativo).
     * La asignación real y transaccional se implementará en el motor DTE.
     */
    public function getSiguienteNumeroAttribute(): int
    {
        return $this->ultimo_numero + 1;
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }
}
