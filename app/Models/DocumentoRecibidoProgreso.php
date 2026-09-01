<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un día del buzón de compras y qué se sabe de él. {@see database/migrations
 * 2026_09_01_100100_create_documentos_recibidos_progreso_table.php} para el porqué.
 *
 * La regla que ordena todo el módulo: SOLO `completo` significa cubierto. `parcial`,
 * `error` y la ausencia de fila significan lo mismo de cara al usuario —"no lo sé"— y
 * ninguno habilita a declarar un período completo.
 */
class DocumentoRecibidoProgreso extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_PARCIAL = 'parcial';

    public const ESTADO_COMPLETO = 'completo';

    public const ESTADO_ERROR = 'error';

    protected $table = 'documentos_recibidos_progreso';

    protected $fillable = [
        'dia', 'carpeta', 'uid_validity', 'estado', 'ultimo_uid',
        'correos', 'nuevos', 'duplicados', 'descartados', 'rechazados',
        'error', 'completado_en',
    ];

    /**
     * `dia` se guarda SIEMPRE como `Y-m-d` exacto, sin hora.
     *
     * Con el cast `date` de Eloquent la columna terminaba con `2026-07-10 00:00:00`, y
     * entonces ni `where('dia', '2026-07-10')` ni `whereBetween` con fechas sueltas
     * encontraban la fila: `firstOrCreate` intentaba insertar un duplicado y chocaba con
     * el índice único. Un accessor/mutator explícito deja el valor comparable como texto
     * y sigue devolviendo un Carbon al leerlo.
     */
    protected function dia(): Attribute
    {
        return Attribute::make(
            get: fn ($valor) => $valor === null ? null : Carbon::parse($valor)->startOfDay(),
            set: fn ($valor) => $valor === null ? null : Carbon::parse($valor)->toDateString(),
        );
    }

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'ultimo_uid' => 'integer',
            'correos' => 'integer',
            'nuevos' => 'integer',
            'duplicados' => 'integer',
            'descartados' => 'integer',
            'rechazados' => 'integer',
            'completado_en' => 'datetime',
        ];
    }

    public function estaCompleto(): bool
    {
        return $this->estado === self::ESTADO_COMPLETO;
    }

    /** Días recorridos ENTEROS: los únicos que cuentan como cubiertos. */
    public function scopeCompletos(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_COMPLETO);
    }

    /** Días que quedaron a medias o fallaron: hay trabajo pendiente en ellos. */
    public function scopeSinCerrar(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::ESTADO_PENDIENTE, self::ESTADO_PARCIAL, self::ESTADO_ERROR]);
    }

    public function etiquetaEstado(): string
    {
        return match ($this->estado) {
            self::ESTADO_COMPLETO => 'Completo',
            self::ESTADO_PARCIAL => 'A medias',
            self::ESTADO_ERROR => 'Con error',
            default => 'Pendiente',
        };
    }
}
