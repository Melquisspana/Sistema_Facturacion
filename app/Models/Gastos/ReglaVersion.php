<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versión de la plantilla de una regla, con su fecha de efecto.
 *
 * Existe para que cambiar la regla no reescriba el pasado. Si en marzo el alquiler
 * sube de 400 a 450, la obligación de enero tiene que seguir explicándose con 400:
 * la ocurrencia de enero apunta a la versión 1 y esta fila conserva sus datos.
 *
 * Es inmutable: no se edita ni se borra. Un cambio crea la versión siguiente.
 */
class ReglaVersion extends Model
{
    protected $table = 'gastos_regla_versiones';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'vigente_desde' => 'date', 'created_at' => 'datetime'];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(Regla::class, 'regla_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
