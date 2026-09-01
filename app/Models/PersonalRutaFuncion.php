<?php

namespace App\Models;

use App\Enums\FuncionPersonalRuta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una función declarada de una persona de campo (vendedor, repartidor, responsable de
 * salida, cobrador). Fila y no elemento de un JSON: acá se consulta —«¿quién puede ser
 * responsable?»—, se valida contra el enum y se puede agregar una función nueva sin
 * reescribir filas.
 *
 * No otorga permisos del sistema: solo describe qué se le puede pedir a esa persona.
 */
class PersonalRutaFuncion extends Model
{
    use HasFactory;

    protected $table = 'rutas_personal_funciones';

    protected $fillable = [
        'rutas_personal_id',
        'funcion',
    ];

    protected function casts(): array
    {
        return [
            'funcion' => FuncionPersonalRuta::class,
        ];
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'rutas_personal_id');
    }
}
