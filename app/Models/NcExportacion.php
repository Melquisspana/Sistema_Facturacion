<?php

namespace App\Models;

use App\Enums\EstadoNcExportacion;
use App\Enums\ProcedenciaArchivoNc;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
            'archivo_origen' => ProcedenciaArchivoNc::class,
            'archivado_en' => 'datetime',
            'presentada_en' => 'datetime',
        ];
    }

    /**
     * ¿Alguien declaró haber cargado este archivo al portal del cliente? Es un hecho
     * aparte de la descarga y del `estado`: bajar el archivo no lo sube.
     */
    public function presentada(): bool
    {
        return $this->presentada_en !== null;
    }

    /** ¿Se bajó alguna vez? Sin eso no hay archivo que pueda haberse subido. */
    public function descargadoAlgunaVez(): bool
    {
        return $this->descargas > 0 || $this->descargado_en !== null;
    }

    /**
     * ¿Hay ALGO registrado de una copia archivada (aunque sea incompleto)? Cualquier dato
     * basta para no volver a generar: un registro a medias se revisa, no se pisa.
     */
    public function tieneCopiaArchivada(): bool
    {
        return $this->archivo_hash !== null || $this->archivo_path !== null
            || $this->archivo_origen !== null || $this->archivado_en !== null;
    }

    /**
     * ¿El registro de la copia está COMPLETO? Sin procedencia no se sabe si es la primera
     * descarga o una reconstrucción, y eso no se infiere.
     */
    public function registroDeCopiaCompleto(): bool
    {
        return $this->archivo_hash !== null && $this->archivo_path !== null
            && $this->archivo_origen !== null && $this->archivado_en !== null;
    }

    /**
     * ¿Se descargó alguna vez SIN que quedara copia? Entonces la próxima copia será una
     * reconstrucción, no el archivo que se entregó.
     */
    public function descargadoSinCopia(): bool
    {
        return ! $this->tieneCopiaArchivada() && ($this->descargas > 0 || $this->descargado_en !== null);
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

    public function presentadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presentada_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NcExportacionItem::class, 'nc_exportacion_id');
    }

    /**
     * Las NC del lote como relación de consulta, para AGREGAR en SQL sin cargarlas: el
     * historial suma `total_pagar` de cada lote con `withSum()` en una sola consulta. El
     * orden de exportación sigue siendo el de {@see notas()}.
     */
    public function dtes(): HasManyThrough
    {
        return $this->hasManyThrough(Dte::class, NcExportacionItem::class, 'nc_exportacion_id', 'id', 'id', 'dte_id');
    }

    /**
     * Las NC del lote en el orden en que se exportaron. Ese orden se congeló al crear el
     * lote, así que generar el archivo nunca incorpora NC aparecidas después.
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
