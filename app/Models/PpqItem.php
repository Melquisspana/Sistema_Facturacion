<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de un lote PPQ: un CCF/NC incluido, opcionalmente vinculado a un albarán.
 * Guarda snapshots de OC/montos para la conciliación y el Excel de Calleja.
 */
class PpqItem extends Model
{
    use HasFactory;

    protected $table = 'ppq_items';

    protected $fillable = [
        'ppq_lote_id',
        'dte_id',
        'origen',
        'numero_control',
        'codigo_generacion',
        'sello_recepcion',
        'tipo_dte',
        'fecha_documento',
        'gmail_message_id',
        'ppq_albaran_id',
        'sin_albaran',
        'conciliacion_estado',
        'fecha_pago',
        'monto_pagado',
        'conciliado_en',
        'numero_orden_compra',
        'sala_nombre',
        'monto_dte',
        'monto_albaran',
        'diferencia',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_documento' => 'date',
            'sin_albaran' => 'boolean',
            'fecha_pago' => 'date',
            'conciliado_en' => 'datetime',
            'monto_dte' => 'decimal:2',
            'monto_albaran' => 'decimal:2',
            'monto_pagado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    /** Recalcula la diferencia monto_dte - monto_albaran cuando hay albarán. */
    protected static function booted(): void
    {
        static::saving(function (PpqItem $item) {
            $item->diferencia = $item->monto_albaran === null
                ? null
                : round((float) $item->monto_dte - (float) $item->monto_albaran, 2);
        });
    }

    /** Código de sala (4 dígitos) derivado de la orden de compra. */
    public function salaCodigo(): ?string
    {
        return \App\Support\OrdenCompra::salaDesde($this->numero_orden_compra);
    }

    /**
     * Nombre comercial de la sala: snapshot guardado al agregar → sucursal relacionada al
     * DTE → búsqueda por código. Null si ninguno resuelve.
     */
    public function salaNombre(): ?string
    {
        if (filled($this->sala_nombre)) {
            return $this->sala_nombre;
        }

        return \App\Support\Sala::nombrePreferido($this->salaCodigo(), $this->dte?->clienteSucursal?->nombre);
    }

    /** Texto siempre presente para pantalla ("…sin nombre registrado" si no hay nombre). */
    public function salaDescripcion(): string
    {
        return \App\Support\Sala::descripcion($this->salaCodigo(), $this->salaNombre());
    }

    /** ¿El documento es una Nota de Crédito (tipo 05)? Resta en el cobro. */
    public function esNc(): bool
    {
        return ($this->tipo_dte ?? $this->dte?->tipo_dte?->value) === '05';
    }

    /** Número de control normalizado (solo alfanumérico, mayúsculas) para cruzar con el TXT. */
    public function numeroNormalizado(): ?string
    {
        return \App\Services\Ppq\ConciliacionTxtParser::normalizarNumero(
            $this->numero_control ?? $this->dte?->numero_control,
        );
    }

    /** ¿Ya está conciliado contra el TXT de Calleja (pagado o NC aplicada)? */
    public function estaConciliado(): bool
    {
        return in_array($this->conciliacion_estado, ['pagado', 'aplicada'], true);
    }

    /** Etiqueta del estado de pago para pantalla (NUNCA "pagado" solo por estar en el PPQ). */
    public function estadoPagoLabel(): string
    {
        return match ($this->conciliacion_estado) {
            'pagado' => 'Pagado / conciliado',
            'aplicada' => 'Descontada / aplicada',
            default => 'Pendiente',
        };
    }

    /** Clases del badge de estado de pago. */
    public function estadoPagoClase(): string
    {
        return match ($this->conciliacion_estado) {
            'pagado' => 'bg-green-100 text-green-700',
            'aplicada' => 'bg-indigo-100 text-indigo-700',
            default => 'bg-gray-100 text-gray-500',
        };
    }

    /** Diferencia entre el monto del sistema y el del TXT (null si no está conciliado). */
    public function diferenciaPago(): ?float
    {
        return $this->monto_pagado === null ? null : round((float) $this->monto_dte - (float) $this->monto_pagado, 2);
    }

    /** Orden por TIPO: CCF (0) antes que NC (1). */
    public function ordenTipo(): int
    {
        return $this->esNc() ? 1 : 0;
    }

    /** Correlativo numérico del número de control (sus dígitos finales) para ordenar ascendente. */
    public function correlativoNumero(): int
    {
        $control = $this->numero_control ?? $this->dte?->numero_control;
        preg_match('/(\d+)$/', (string) $control, $m);

        return (int) ($m[1] ?? 0);
    }

    /** Signo del item en el cobro: -1 para NC (resta), +1 para CCF (suma). */
    public function signo(): int
    {
        return $this->esNc() ? -1 : 1;
    }

    /** Monto del CCF/NC con signo (negativo si es NC). */
    public function montoDteConSigno(): float
    {
        return $this->signo() * (float) $this->monto_dte;
    }

    /** Monto del albarán con signo (negativo si es NC); null si no hay albarán. */
    public function montoAlbaranConSigno(): ?float
    {
        return $this->monto_albaran === null ? null : $this->signo() * (float) $this->monto_albaran;
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PpqLote::class, 'ppq_lote_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function albaran(): BelongsTo
    {
        return $this->belongsTo(PpqAlbaran::class, 'ppq_albaran_id');
    }
}
