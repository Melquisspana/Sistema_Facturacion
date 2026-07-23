<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de cada ejecución del backup diario de base de datos
 * ({@see \App\Console\Commands\BackupMysqlDiarioCommand}). Fuente de verdad para
 * "¿hay un backup válido de hoy?" del readiness (reemplaza el escaneo de archivos
 * por fecha de modificación, que tenía un bug de timezone documentado en el código
 * anterior).
 */
class RespaldoEjecucion extends Model
{
    protected $table = 'respaldo_ejecuciones';

    protected $fillable = [
        'iniciado_en', 'terminado_en', 'exitoso', 'archivo_ruta',
        'archivo_tamano_bytes', 'sha256', 'mensaje', 'origen',
    ];

    protected function casts(): array
    {
        return [
            'iniciado_en' => 'datetime',
            'terminado_en' => 'datetime',
            'exitoso' => 'boolean',
            'archivo_tamano_bytes' => 'integer',
        ];
    }

    public function scopeExitosos(Builder $q): Builder
    {
        return $q->where('exitoso', true);
    }

    /** ¿Hay un backup EXITOSO cuyo `terminado_en` cae en el día de HOY (timezone de la app)? */
    public static function hayValidoHoy(): bool
    {
        $hoy = Carbon::now(config('app.timezone'))->toDateString();

        return self::exitosos()->whereDate('terminado_en', $hoy)->exists();
    }

    /** Última ejecución (cualquier origen/resultado), para mostrar en pantalla. */
    public static function ultima(): ?self
    {
        return self::query()->latest('terminado_en')->first();
    }
}
