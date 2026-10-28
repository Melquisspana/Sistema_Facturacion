<?php

namespace App\Models;

use App\Enums\ConceptoGastoRuta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalidaRutaGasto extends Model
{
    public const FOTO_MAX_KB = 10240;

    public const MONTO_MAXIMO = '9999.99';

    protected $table = 'salida_ruta_gastos';

    protected $fillable = ['fecha', 'personal_id', 'concepto', 'detalle', 'monto', 'archivo_ruta', 'archivo_mime', 'archivo_bytes', 'archivo_sha256', 'registrado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'concepto' => ConceptoGastoRuta::class, 'monto' => 'decimal:2', 'personal_id' => 'integer', 'registrado_por' => 'integer', 'archivo_bytes' => 'integer'];
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
