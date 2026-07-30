<?php

namespace App\Models;

use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Municipio FISCAL del MH (CAT-013).
 *
 * Ojo con `nombre`: la tabla se reutilizó en la reforma 2024 y quedó con el nombre del
 * municipio ANTERIOR (p. ej. "Ilobasco", "Sensuntepeque") mientras `codigo` ya es el de la
 * agrupación NUEVA (10 = CABAÑAS OESTE, 11 = CABAÑAS ESTE). Por eso la interfaz mostraba
 * "Municipio: Ilobasco" cuando en realidad se estaba eligiendo "Cabañas Oeste".
 *
 * Consecuencia práctica: varias filas de un mismo departamento comparten el mismo
 * `codigo` (San Salvador tiene 5 filas con código 22 y 4 con código 23). Son la MISMA
 * agrupación fiscal: para elegir/mostrar municipios usá siempre {@see nombreFiscal()} y
 * {@see fiscalesUnicos()}, nunca `nombre` a secas.
 *
 * `nombre` NO se toca ni se renombra: lo usan el importador y datos históricos.
 */
class Municipio extends Model
{
    protected $table = 'municipios';

    protected $fillable = [
        'departamento_id',
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

    /** Distritos (CAT-008) de esta agrupación municipal, dentro del mismo departamento. */
    public function distritos(): HasMany
    {
        return $this->hasMany(Distrito::class, 'municipio_codigo', 'codigo')
            ->whereColumn('distritos.departamento_id', 'municipios.departamento_id');
    }

    /**
     * NOMBRE FISCAL OFICIAL del municipio 2024, resuelto desde CAT-013 por código.
     * Ej.: la fila `codigo=10` del departamento Cabañas devuelve "Cabañas Oeste" aunque
     * su columna `nombre` siga diciendo "Ilobasco".
     *
     * Si el catálogo no está cargado o el código no aparece, cae al `nombre` histórico
     * (nunca queda vacío).
     */
    public function nombreFiscal(): string
    {
        $oficial = self::nombresFiscales()[$this->clavefiscal()] ?? null;

        return $oficial ?? (string) $this->nombre;
    }

    /**
     * Una sola fila por AGRUPACIÓN fiscal (departamento + código CAT-013), para no
     * ofrecer opciones duplicadas que en realidad son el mismo municipio.
     *
     * @return Collection<int, Municipio>
     */
    public static function fiscalesUnicos(?int $departamentoId = null): Collection
    {
        return self::query()
            ->when($departamentoId, fn ($q) => $q->where('departamento_id', $departamentoId))
            ->orderBy('departamento_id')->orderBy('codigo')->orderBy('id')
            ->get()
            // Se conserva la PRIMERA fila de cada (departamento, código): representa la
            // agrupación completa y su nombre se muestra vía nombreFiscal().
            ->unique(fn (self $m) => $m->departamento_id.'-'.$m->codigo)
            ->values();
    }

    /** Clave de búsqueda: departamento_id + código (el código CAT-013 repite entre deptos). */
    private function clavefiscal(): string
    {
        return $this->departamento_id.'-'.$this->codigo;
    }

    /**
     * Nombres oficiales de los municipios 2024, indexados por "departamento_id-codigo".
     *
     * Se derivan de `distritos`, NO de CAT-013 directo: el catálogo del MH no trae columna
     * de departamento y sus códigos se repiten entre departamentos, así que leerlo suelto
     * no permite saber a qué departamento pertenece cada fila. En cambio `distritos` ya
     * tiene las tres piezas juntas y verificadas: `departamento_id`, el NOMBRE de la
     * agrupación 2024 (`municipio`) y su código CAT-013 (`municipio_codigo`, poblado por
     * {@see VinculaMunicipioDistrito} con emparejamiento 262/262).
     *
     * @return array<string, string>
     */
    private static function nombresFiscales(): array
    {
        return Cache::store('array')->rememberForever('municipios.nombres_fiscales', function () {
            return Distrito::query()
                ->whereNotNull('municipio_codigo')
                ->get(['departamento_id', 'municipio_codigo', 'municipio'])
                ->mapWithKeys(fn (Distrito $d) => [
                    $d->departamento_id.'-'.$d->municipio_codigo => (string) $d->municipio,
                ])
                ->all();
        });
    }

    /** Limpia el mapa memorizado de nombres fiscales (tras re-vincular o re-sembrar). */
    public static function olvidarNombresFiscales(): void
    {
        Cache::store('array')->forget('municipios.nombres_fiscales');
    }
}
