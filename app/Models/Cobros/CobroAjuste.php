<?php

namespace App\Models\Cobros;

use App\Models\Cliente;
use App\Models\Concerns\RecortaTextosAColumna;
use App\Models\Dte;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ajuste que el cliente informó en su archivo de pagos: las filas QD
 * («QD;PPQ/31001;;-107.21»).
 *
 * Se guarda como un hecho aparte y NO se reparte entre las facturas de esa referencia. El
 * archivo dice cuánto descontó y sobre qué referencia; no dice a qué factura corresponde
 * cada centavo. Prorratearlo dejaría cada factura cobrada por una cifra que nadie informó,
 * y el descuadre aparecería mucho después, factura por factura.
 *
 * A pedido se crea una NC desde el ajuste por el neto del TXT, calculando su concepto
 * con el motor fiscal existente. También puede vincularse una NC ya creada. Se resuelve
 * al leer cuando Hacienda la acepta realmente; invalidarla vuelve a dejarlo pendiente.
 */
class CobroAjuste extends Model
{
    use HasFactory;
    use RecortaTextosAColumna;

    /** @return array<string, int> */
    public static function largosDeTexto(): array
    {
        return [
            'motivo' => 255,
            'evidencia_nombre' => 160,
        ];
    }

    protected $table = 'cobro_ajustes';

    protected $fillable = [
        'cliente_id',
        'referencia',
        'referencia_calleja',
        'cobro_solicitud_id',
        'monto',
        'fecha',
        'estado',
        'nc_dte_id',
        'motivo',
        'evidencia_hash',
        'evidencia_nombre',
        'referencia_linea',
        'user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => 'pendiente_nc',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(CobroSolicitud::class, 'cobro_solicitud_id');
    }

    /** La nota de crédito que finalmente cubrió el ajuste, emitida por su propio circuito. */
    public function notaCredito(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'nc_dte_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function label(): string
    {
        return match ($this->estadoEfectivo()) {
            'pendiente_nc' => 'NC de pronto pago pendiente',
            'nc_en_proceso' => 'NC en proceso',
            'resuelto' => 'Resuelto con nota de crédito',
            'descartado' => 'Descartado',
            default => (string) $this->estado,
        };
    }

    public function clase(): string
    {
        return match ($this->estadoEfectivo()) {
            'nc_en_proceso' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300',
            'pendiente_nc' => 'bg-amber-100 text-amber-800',
            'resuelto' => 'bg-green-100 text-green-700',
            'descartado' => 'bg-gray-100 text-gray-500',
            default => 'bg-gray-100 text-gray-500',
        };
    }

    public function estadoEfectivo(): string
    {
        if ($this->estado === 'descartado') {
            return 'descartado';
        }

        $nc = $this->notaCredito;
        if (! $nc || $nc->tieneEventoInvalidacion()) {
            return 'pendiente_nc';
        }

        return $nc->aceptadoRealmentePorMh() ? 'resuelto' : 'nc_en_proceso';
    }

    /** «PPQ/31001» → «31001». Null si la referencia no tiene esa forma. */
    public static function referenciaCalleja(?string $referencia): ?string
    {
        if (preg_match('/(\d{3,})\s*$/', (string) $referencia, $m)) {
            return $m[1];
        }

        return null;
    }
}
