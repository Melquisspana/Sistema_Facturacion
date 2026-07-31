<?php

namespace App\Models\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\UnidadBase;
use App\Support\Planta\BucketInventario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una línea de recepción: un insumo, una cantidad y el bucket al que entrará.
 *
 * Congela la conversión que se aplicó (`contenido_por_unidad`,
 * `factor_conversion`, `unidad_recibida`) para que el histórico no cambie cuando
 * cambie el catálogo del insumo.
 *
 * `cantidad_base` está persistida por comodidad de lectura, pero la fuente de
 * verdad es el CÁLCULO: {@see cantidadBaseCalculada()} lo reproduce y el
 * servicio lo recalcula al guardar y al confirmar. Lo que llegue del formulario
 * en ese campo se descarta siempre.
 */
class PlantaRecepcionDetalle extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Decimales del inventario. Igual que PlantaInventarioService::ESCALA. */
    public const ESCALA = 4;

    protected $table = 'planta_recepcion_detalles';

    /**
     * `cantidad_base`, `unidad_base` y `planta_lote_id` NO son asignables en
     * masa: los dos primeros los deriva el servidor y el tercero lo resuelve el
     * servicio al confirmar.
     */
    protected $fillable = [
        'planta_recepcion_id',
        'planta_insumo_id',
        'cantidad_recibida',
        'unidad_recibida',
        'contenido_por_unidad',
        'factor_conversion',
        'estado_destino',
        'lote_codigo_proveedor',
        'fecha_elaboracion',
        'fecha_vencimiento',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado_destino' => EstadoDisponibilidad::class,
            'unidad_base' => UnidadBase::class,
            // decimal:N devuelve STRING: la aritmética del inventario nunca pasa por float.
            'cantidad_recibida' => 'decimal:4',
            'contenido_por_unidad' => 'decimal:4',
            'factor_conversion' => 'decimal:8',
            'cantidad_base' => 'decimal:4',
            'fecha_elaboracion' => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_recepcion_detalle')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'agregó una línea a la recepción',
                'updated' => 'modificó una línea de la recepción',
                'deleted' => 'quitó una línea de la recepción',
                default => $evento,
            });
    }

    /**
     * Reproduce la conversión con aritmética decimal exacta.
     *
     * bcmath TRUNCA en vez de redondear, así que el medio-arriba se hace a mano:
     * se calcula con dos decimales de más, se suma medio último dígito y se
     * trunca. Hacerlo con `round()` de PHP obligaría a pasar por float, que es
     * exactamente lo que el inventario evita.
     */
    public function cantidadBaseCalculada(): string
    {
        return self::convertir(
            (string) $this->cantidad_recibida,
            (string) $this->contenido_por_unidad,
            (string) $this->factor_conversion,
        );
    }

    /** @see cantidadBaseCalculada() */
    public static function convertir(string $cantidadRecibida, string $contenidoPorUnidad, string $factor): string
    {
        $bruto = bcmul(bcmul($cantidadRecibida, $contenidoPorUnidad, 12), $factor, 12);

        $medio = bcdiv('1', bcpow('10', (string) self::ESCALA, 0), self::ESCALA + 1);
        $medio = bcdiv($medio, '2', self::ESCALA + 2);

        $ajustado = bccomp($bruto, '0', 12) === -1
            ? bcsub($bruto, $medio, 12)
            : bcadd($bruto, $medio, 12);

        return bcadd($ajustado, '0', self::ESCALA);
    }

    /** ¿El valor persistido coincide con el que sale de la fórmula? */
    public function cantidadBaseEsCoherente(): bool
    {
        return bccomp((string) $this->cantidad_base, $this->cantidadBaseCalculada(), self::ESCALA) === 0;
    }

    /**
     * Bucket al que entra esta línea. Exige lote resuelto: antes de confirmar no
     * hay bucket porque no hay lote.
     */
    public function bucket(): BucketInventario
    {
        return new BucketInventario(
            insumoId: (int) $this->planta_insumo_id,
            loteId: (int) $this->planta_lote_id,
            ubicacionId: (int) $this->recepcion->planta_ubicacion_id,
            estado: $this->estado_destino,
            // Una recepción entra siempre a una ubicación física: nunca a tránsito.
            trasladoId: 0,
        );
    }

    // --- Relaciones ---

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(PlantaRecepcion::class, 'planta_recepcion_id');
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PlantaLote::class, 'planta_lote_id');
    }
}
