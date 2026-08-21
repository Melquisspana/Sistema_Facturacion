<?php

namespace App\Models\Asistencia;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una persona que marca asistencia. NO es un usuario del sistema (ver la
 * migración): `user_id` es un enlace opcional para quien además tiene login.
 *
 * Auditoría con spatie/activitylog, como el resto del proyecto: dar de alta a
 * alguien, cambiarle el nombre o desactivarlo son actos que después hay que poder
 * explicar frente a una planilla.
 */
class AsistenciaEmpleado extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'asistencia_empleados';

    protected $fillable = [
        'codigo',
        'nombres',
        'apellidos',
        'activo',
        'fecha_ingreso',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_ingreso' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asistencia')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'dio de alta al empleado',
                'updated' => 'actualizó al empleado',
                'deleted' => 'eliminó al empleado',
                default => $evento,
            });
    }

    public function huellas(): HasMany
    {
        return $this->hasMany(AsistenciaHuella::class, 'asistencia_empleado_id');
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(AsistenciaMarcacion::class, 'asistencia_empleado_id');
    }

    /** Enlace OPCIONAL con el usuario del sistema. Casi siempre null. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }

    /**
     * Nombre para la pantalla del lector: 128x128 píxeles no aguantan «María
     * Fernanda de los Ángeles Hernández Portillo». Primer nombre + primer
     * apellido, que es como se llama a la gente en voz alta.
     *
     * Se DERIVA, no se guarda: una columna más que llenar a mano se queda vacía
     * o se contradice con el nombre real. Si algún día hace falta un apodo
     * elegido a mano, esa columna se agrega y este método la prefiere.
     */
    public function nombreCorto(): string
    {
        $primerNombre = Str::of($this->nombres)->trim()->explode(' ')->first() ?? '';
        $primerApellido = Str::of($this->apellidos)->trim()->explode(' ')->first() ?? '';

        return trim($primerNombre.' '.$primerApellido);
    }
}
