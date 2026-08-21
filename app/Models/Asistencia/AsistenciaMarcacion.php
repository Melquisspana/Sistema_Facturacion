<?php

namespace App\Models\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un hecho: alguien marcó a tal hora. APPEND-ONLY (ver la migración).
 *
 * `UPDATED_AT = null` es la traducción en Eloquent de «esta fila no tiene
 * updated_at porque no se actualiza nunca»: Eloquent sigue poniendo `created_at`
 * al insertar y deja de buscar la otra columna, que la tabla no tiene. Si alguien
 * intentara `->update()` sobre una marcación, no habría ninguna columna que
 * delatara el cambio — por eso la tabla no la tiene, y por eso las correcciones
 * futuras serán filas NUEVAS con `origen = 'manual'` en vez de ediciones.
 */
class AsistenciaMarcacion extends Model
{
    use HasFactory;

    /** Append-only: `created_at` lo pone Eloquent al insertar; no hay `updated_at`. */
    public const UPDATED_AT = null;

    protected $table = 'asistencia_marcaciones';

    protected $fillable = [
        'asistencia_empleado_id',
        'asistencia_dispositivo_id',
        'asistencia_huella_id',
        'tipo',
        'marcado_at',
        'fecha_local',
        'origen',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMarcacion::class,
            'marcado_at' => 'datetime',
            'fecha_local' => 'date',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDispositivo::class, 'asistencia_dispositivo_id');
    }

    public function huella(): BelongsTo
    {
        return $this->belongsTo(AsistenciaHuella::class, 'asistencia_huella_id');
    }
}
