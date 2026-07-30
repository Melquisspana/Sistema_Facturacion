<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Distrito (tercer nivel territorial, reforma 2024 de El Salvador).
 * Lleva su departamento y el nombre del municipio 2024 (agrupación) al que pertenece.
 */
class Distrito extends Model
{
    protected $table = 'distritos';

    protected $fillable = [
        'departamento_id',
        'municipio',
        'municipio_codigo',
        'codigo',
        'nombre',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * Municipio fiscal 2024 (CAT-013) al que pertenece el distrito.
     *
     * `municipios` no es un catálogo 1:1 de las 44 agrupaciones (varias filas históricas
     * comparten el mismo código CAT-013 dentro de un departamento), por eso el vínculo se
     * hace por CÓDIGO + DEPARTAMENTO y no por una clave foránea. Devuelve la primera fila
     * que corresponde a esa agrupación; para mostrar el nombre oficial usá
     * {@see Municipio::nombreFiscal()}.
     */
    public function municipioFiscal(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_codigo', 'codigo')
            ->whereColumn('municipios.departamento_id', 'distritos.departamento_id');
    }

    /** ¿Este distrito pertenece al municipio indicado (misma agrupación CAT-013)? */
    public function perteneceAMunicipio(?Municipio $municipio): bool
    {
        if (! $municipio) {
            return false;
        }

        return (int) $municipio->departamento_id === (int) $this->departamento_id
            && filled($this->municipio_codigo)
            && (string) $municipio->codigo === (string) $this->municipio_codigo;
    }
}
