<?php

namespace App\Models\Cobros;

use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Models\Cliente;
use App\Models\Concerns\RecortaTextosAColumna;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una PRESENTACIÓN de documentos al cliente: la selección, el archivo exacto y lo que pasó
 * después.
 *
 * El archivo se genera una sola vez, desde los renglones congelados, y se archiva con su
 * huella (`archivo_hash`/`archivo_path`); bajarlo de nuevo entrega esa misma copia, byte a
 * byte, y nunca una regenerada. Y descargarlo no lo presenta: `presentada_en` solo lo
 * escribe una persona que declara haberlo subido al portal, porque el sistema no sube nada
 * y no tiene forma de saberlo.
 */
class CobroSolicitud extends Model
{
    use HasFactory;
    use RecortaTextosAColumna;

    /** @return array<string, int> */
    public static function largosDeTexto(): array
    {
        return [
            'presentada_nota' => 255,
        ];
    }

    protected $table = 'cobro_solicitudes';

    protected $fillable = [
        'cliente_id',
        'referencia',
        'formato',
        'archivo_nombre',
        'archivo_hash',
        'archivo_path',
        'estado',
        'presentada_en',
        'presentada_por',
        'presentada_nota',
        'referencia_calleja',
        'recibida_en',
        'fecha_programada_pago',
        'corrige_a_id',
        'motivo_correccion',
        'user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => 'generada',
        'descargas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSolicitudCobro::class,
            'descargada_en' => 'datetime',
            'descargas' => 'integer',
            'presentada_en' => 'datetime',
            'recibida_en' => 'datetime',
            'fecha_programada_pago' => 'date',
        ];
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
        return $this->hasMany(CobroSolicitudItem::class)->orderBy('orden')->orderBy('id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(CobroDocumento::class);
    }

    /** La solicitud que ESTA corrige (el envío anterior), si es un reenvío. */
    public function corrigeA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrige_a_id');
    }

    /** El reenvío que corrigió a esta, si lo hubo. */
    public function correcciones(): HasMany
    {
        return $this->hasMany(self::class, 'corrige_a_id');
    }

    public function correos(): HasMany
    {
        return $this->hasMany(CobroCorreo::class);
    }

    /** NC que respaldaron a sus CCF al crearla, con el lote de NC ya cargado al portal. */
    public function notas(): HasMany
    {
        return $this->hasMany(CobroSolicitudNota::class);
    }

    /**
     * Deja constancia de una descarga. NO la presenta: el archivo se sube al portal a mano
     * y el sistema no tiene forma de saber si eso ocurrió (ver {@see EstadoSolicitudCobro}).
     */
    public function registrarDescarga(): void
    {
        $this->forceFill([
            'descargada_en' => $this->descargada_en ?? now(),
            'descargas' => $this->descargas + 1,
        ])->save();
    }

    /** Total de los documentos que lleva, con signo (las NC restan). */
    public function total(): string
    {
        $total = '0';

        foreach ($this->items as $item) {
            $monto = $item->monto ?? '0';
            $total = $item->documento?->esNc()
                ? Dinero::restar($total, $monto)
                : Dinero::sumar($total, $monto);
        }

        return Dinero::redondear($total);
    }
}
