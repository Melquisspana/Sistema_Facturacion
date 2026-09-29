<?php

namespace App\Models\Planilla;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un documento firmado: la hoja general con las firmas, o el recibo de una persona.
 *
 * Vive en disco PRIVADO con nombre generado por el servidor y solo se entrega por
 * controlador autorizado (`planilla.documentos`). Un recibo de sueldo con el nombre y
 * el importe de alguien no puede quedar detrás de una URL adivinable.
 */
class PlanillaDocumento extends Model
{
    protected $table = 'planilla_documentos';

    public $timestamps = false;

    protected $guarded = ['id'];

    public const TIPOS = [
        'hoja_firmada' => 'Hoja de firmas',
        'recibo_firmado' => 'Recibo firmado',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(PlanillaDetalle::class, 'planilla_detalle_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function esDeLaPersona(): bool
    {
        return $this->planilla_detalle_id !== null;
    }
}
