<?php

namespace App\Models;

use App\Enums\FuncionPersonalRuta;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una persona de la operación de campo. Ver la migración para el porqué de la tabla propia.
 *
 * NO es un usuario del sistema y casi nunca tiene login: `user_id` y
 * `asistencia_empleado_id` son punteros opcionales para no duplicar identidad, no
 * dependencias. Este modelo nunca lee marcaciones ni exige que el módulo de Asistencia esté
 * encendido.
 *
 * No se borra: se desactiva. Alguien que ya fue en salidas no puede desaparecer sin
 * llevarse el historial de quién fue a dónde.
 */
class PersonalRuta extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'rutas_personal';

    protected $fillable = [
        'nombre',
        'user_id',
        'asistencia_empleado_id',
        'telefono',
        'notas',
        'activo',
    ];

    /**
     * Alguien nace activo, igual que dice la migración.
     *
     * El valor por defecto se declara TAMBIÉN acá y no solo en la base: sin esto, un
     * `PersonalRuta::create(['nombre' => 'X'])` devuelve un objeto cuyo `activo` es null
     * —la fila sí queda activa, pero la instancia en memoria no lo sabe— y el código que
     * la usa a continuación la trata como inactiva. Es un desacuerdo entre el objeto y su
     * propia fila, y aparece justo en el camino más común: dar de alta y usar.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    public static function seGestionaEnPersonal(?User $usuario): bool
    {
        return PlanillaEmpleado::enUso() && ($usuario?->can('planilla.ver') ?? false);
    }

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('rutas_personal')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'dio de alta a una persona de campo',
                'updated' => 'actualizó a una persona de campo',
                'deleted' => 'eliminó a una persona de campo',
                default => $evento,
            });
    }

    // ------------------------------------------------------------- relaciones

    /** Enlace OPCIONAL con el usuario del sistema. Null es lo normal. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** La MISMA persona en Asistencia, si alguien la enlazó. Solo identidad. */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function funciones(): HasMany
    {
        return $this->hasMany(PersonalRutaFuncion::class, 'rutas_personal_id');
    }

    public function participaciones(): HasMany
    {
        return $this->hasMany(SalidaRutaParticipante::class, 'rutas_personal_id');
    }

    // ---------------------------------------------------------------- lectura

    /** @return Collection<int, FuncionPersonalRuta> */
    public function funcionesEnum(): Collection
    {
        return $this->funciones
            ->map(fn (PersonalRutaFuncion $f) => $f->funcion)
            ->filter()
            ->values();
    }

    public function tieneFuncion(FuncionPersonalRuta $funcion): bool
    {
        return $this->funcionesEnum()->contains($funcion);
    }

    /** Primer nombre + primer apellido, que es como se llama a la gente en voz alta. */
    public function nombreCorto(): string
    {
        $partes = Str::of($this->nombre)->trim()->explode(' ')->filter()->values();

        return $partes->count() <= 2
            ? $this->nombre
            : trim($partes->first().' '.$partes->get(1));
    }

    /** El teléfono para un enlace `tel:`: solo dígitos y «+». Vacío si no tiene. */
    public function telefonoMarcable(): string
    {
        return (string) preg_replace('/[^0-9+]/', '', (string) $this->telefono);
    }

    /** Etiqueta para listas: «Hugo Ficticio (inactivo)». */
    public function etiqueta(): string
    {
        return $this->nombre.($this->activo ? '' : ' (inactivo)');
    }

    // ----------------------------------------------------------------- scopes

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    /**
     * Quienes pueden figurar como el que entregó un CCF (12/11/2026): los vendedores activos y,
     * además, la ficha activa de quien lo anota. Es la única regla: la usan la tarjeta de «Por
     * entregar» y {@see EntregasCcf::registrar()}, por cualquier pantalla.
     */
    public function scopePuedenEntregar(Builder $q, ?User $anota): Builder
    {
        return $q->activos()->where(fn (Builder $q) => $q->conFuncion(FuncionPersonalRuta::Vendedor)
            ->orWhere('user_id', $anota?->id ?? 0));
    }

    /** Quienes declaran una función concreta. */
    public function scopeConFuncion(Builder $q, FuncionPersonalRuta $funcion): Builder
    {
        return $q->whereHas('funciones', fn (Builder $f) => $f->where('funcion', $funcion->value));
    }
}
