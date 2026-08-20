<?php

namespace App\Models;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\RepositorioAjustes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de override del Centro de Configuración (tabla `ajustes_sistema`).
 *
 * Modelo DELIBERADAMENTE tonto: no sabe de tipos, niveles ni validación (eso es
 * {@see CatalogoAjustes}) y no cifra ni descifra por su cuenta (eso
 * es {@see RepositorioAjustes}, único lugar del sistema que llama a
 * Crypt para estos valores).
 *
 * AUDITORÍA: este modelo NO usa LogsActivity a propósito. La auditoría de los
 * ajustes es CENTRAL y se emite desde {@see AuditoriaAjustes}, que
 * es quien sabe si la clave es un secreto y por tanto si su valor puede
 * escribirse. Un LogsActivity a nivel de modelo escribiría el criptograma —o
 * peor, el valor— sin esa información.
 *
 * `valor` está en $hidden: ninguna serialización accidental (toArray, toJson,
 * un `dd()` en una vista, un payload de cola) puede arrastrar el criptograma de
 * un secreto hacia afuera. Quien necesita el valor lo pide por la propiedad,
 * nunca por el array.
 */
class AjusteSistema extends Model
{
    protected $table = 'ajustes_sistema';

    protected $fillable = ['clave', 'valor', 'cifrado'];

    /** @var array<int, string> */
    protected $hidden = ['valor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cifrado' => 'boolean'];
    }
}
