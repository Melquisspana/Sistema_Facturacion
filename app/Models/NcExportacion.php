<?php

namespace App\Models;

use App\Enums\EstadoNcExportacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Lote de notas de crédito exportadas al cliente. Ver la migración.
 *
 * No tiene fecha propia: `created_at` es cuándo se generó el archivo, y las notas que
 * contiene pueden ser de fechas de emisión distintas.
 */
class NcExportacion extends Model
{
    use HasFactory;

    protected $table = 'nc_exportaciones';

    protected $fillable = [
        'cliente_id',
        'referencia',
        'formato',
        'archivo_nombre',
        'estado',
        'descargado_en',
        'descargas',
        'user_id',
    ];

    /**
     * Un lote recién creado ya ES «generado»: el valor por defecto se declara también acá
     * y no solo en la migración, para que el objeto en memoria diga lo mismo que la fila
     * sin tener que releerla. Sin esto, `estado` queda null hasta el primer refresh y
     * cualquier lectura inmediata —una vista, un log— vería un lote sin estado.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => EstadoNcExportacion::Generado->value,
        'descargas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoNcExportacion::class,
            'descargado_en' => 'datetime',
            'descargas' => 'integer',
        ];
    }

    /**
     * Deja constancia de una descarga. NO significa que el archivo se le haya enviado al
     * cliente: eso se hace fuera del sistema y no tenemos evidencia de ello (ver
     * {@see EstadoNcExportacion}). Descargar diez veces no duplica ni marca documentos:
     * solo mueve el contador, porque los documentos del lote ya quedaron fijados al crearlo.
     */
    public function registrarDescarga(): void
    {
        $this->forceFill([
            'estado' => EstadoNcExportacion::Descargado->value,
            'descargado_en' => $this->descargado_en ?? now(),
            'descargas' => $this->descargas + 1,
        ])->save();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NcExportacionItem::class, 'nc_exportacion_id');
    }

    /**
     * Las NC del lote en el orden en que se exportaron. Ese orden se congeló al crear el
     * lote, así que regenerar produce el mismo archivo aunque entretanto hayan aparecido
     * NC nuevas.
     *
     * @return Collection<int, Dte>
     */
    public function notas(): Collection
    {
        return $this->items()
            ->with('dte')
            ->orderBy('orden')
            ->orderBy('id')
            ->get()
            ->map(fn (NcExportacionItem $i) => $i->dte)
            ->filter()
            ->values();
    }
}
