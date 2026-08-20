<?php

namespace App\Models;

use App\Ajustes\Correo\PruebaConexionSmtp;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una comprobación de configuración ya ejecutada (tabla
 * `verificaciones_configuracion`). Fila de solo-añadir: nunca se edita, por eso
 * no lleva `updated_at`.
 *
 * `mensaje` llega YA SANEADO por quien registra; este modelo no vuelve a
 * limpiarlo. La responsabilidad vive en quien conoce la excepción original
 * ({@see PruebaConexionSmtp}), que es el único que puede
 * distinguir la parte útil del texto de la parte que podría llevar credenciales.
 */
class VerificacionConfiguracion extends Model
{
    protected $table = 'verificaciones_configuracion';

    /** Solo se crea; jamás se modifica. */
    public const UPDATED_AT = null;

    protected $fillable = ['clave', 'resultado', 'mensaje', 'user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resultado' => ResultadoVerificacion::class,
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeDe(Builder $q, string $clave): Builder
    {
        return $q->where('clave', $clave);
    }

    public function exitosa(): bool
    {
        return $this->resultado === ResultadoVerificacion::Exito;
    }
}
