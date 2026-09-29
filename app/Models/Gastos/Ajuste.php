<?php

namespace App\Models\Gastos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Crédito, débito o corrección sobre una cuota. NO es un pago: no hay salida de
 * dinero, así que no entra en «pagado en el período» ni necesita comprobante de
 * pago. Se revierte, nunca se borra.
 */
class Ajuste extends Model
{
    protected $table = 'gastos_ajustes';

    protected $guarded = ['id'];

    public const DIRECCIONES = ['credito', 'debito'];

    public const TIPOS = [
        'nota_credito' => 'Nota de crédito',
        'nota_debito' => 'Nota de débito',
        'correccion' => 'Corrección de importe',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'revertido_at' => 'datetime'];
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class, 'cuota_id');
    }

    public function vigente(): bool
    {
        return $this->revertido_at === null;
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
