<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entrada de un catálogo oficial del MH (CAT-NNN): un código con su descripción.
 * Reference data importada desde el Excel oficial; no se edita a mano.
 */
class CatalogoMh extends Model
{
    protected $table = 'catalogos_mh';

    protected $fillable = ['cat', 'codigo', 'valor', 'nombre_catalogo'];
}
