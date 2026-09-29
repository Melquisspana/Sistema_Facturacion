<?php

namespace App\Models;

use App\Services\Rutas\PropuestaRutas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lugar que atiende una ruta: un DEPARTAMENTO completo o un DISTRITO suelto (los
 * pueblos: Cojutepeque, Sensuntepeque, Ilobasco). Exactamente uno de los dos.
 *
 * No asigna nada: alimenta la propuesta de ruta de cada sala
 * ({@see PropuestaRutas}). Un mismo lugar pertenece a una sola ruta;
 * lo garantizan los índices únicos de la tabla.
 */
class RutaCobertura extends Model
{
    protected $table = 'ruta_coberturas';

    protected $fillable = [
        'ruta_id',
        'departamento_id',
        'distrito_id',
    ];

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class, 'ruta_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function distrito(): BelongsTo
    {
        return $this->belongsTo(Distrito::class);
    }

    public function esDepartamento(): bool
    {
        return $this->departamento_id !== null;
    }

    /** «Cabañas (todo el departamento)» o «Cojutepeque · Cuscatlán». */
    public function etiqueta(): string
    {
        if ($this->esDepartamento()) {
            return ($this->departamento?->nombre ?? '—').' (todo el departamento)';
        }

        return ($this->distrito?->nombre ?? '—').' · '.($this->distrito?->departamento?->nombre ?? '—');
    }
}
