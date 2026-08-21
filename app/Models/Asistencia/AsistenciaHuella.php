<?php

namespace App\Models\Asistencia;

use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * La asociación «ranura N de ESTE lector = esta persona», y su historia.
 *
 * No guarda ningún dato biométrico: la plantilla de la huella vive y muere dentro
 * del sensor AS608. Acá solo hay un número de ranura.
 *
 * ─────────────────────────── Una fila por ASIGNACIÓN ───────────────────────────
 *
 * Una fila NO es «la ranura 1»: es «la ranura 1 fue de Ana, desde tal día hasta
 * tal otro». Cuando Ana se va y su plantilla se libera en el sensor, esta fila NO
 * se borra ni se reasigna: se marca `activo = false` con su `liberada_at`, y la
 * siguiente persona estrena una fila NUEVA. Así las marcaciones de Ana siguen
 * apuntando a la asignación con la que se hicieron, y el pasado no cambia porque
 * alguien reutilice una ranura.
 *
 * La garantía de que no haya dos asignaciones ACTIVAS para la misma ranura no
 * está en este modelo: la impone la base de datos con un único sobre una columna
 * generada (ver la migración `2026_08_21_090000`). Es a propósito — una
 * comprobación en PHP se pierde entre dos peticiones simultáneas.
 *
 * ESCRIBIRLA A MANO NO ES EL CAMINO. Crear o liberar una asignación son actos que
 * hay que poder explicar frente a una planilla, así que pasan por
 * {@see AsignarHuella} y {@see LiberarHuella}, que auditan.
 * Un `AsistenciaHuella::create()` suelto funciona, pero deja un cambio de
 * titularidad sin rastro.
 */
class AsistenciaHuella extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'asistencia_huellas';

    protected $fillable = [
        'asistencia_empleado_id',
        'asistencia_dispositivo_id',
        'fingerprint_id',
        'activo',
        'liberada_at',
    ];

    /**
     * La columna generada NO se expone. No es información: es un detalle del
     * índice que impone la unicidad, y publicarla invita a que alguien la lea
     * como si significara algo distinto de `activo`.
     */
    protected $hidden = [
        'fingerprint_id_activo',
    ];

    protected function casts(): array
    {
        return [
            'fingerprint_id' => 'integer',
            'activo' => 'boolean',
            'liberada_at' => 'datetime',
        ];
    }

    /**
     * Auditoría: quién asignó una ranura a quién, y cuándo se liberó. Es el cambio
     * MÁS sensible del módulo —decide de quién son las marcaciones que vengan
     * después— y hasta ahora era el único que no dejaba rastro.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asistencia')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'asignó una huella a un empleado',
                'updated' => 'modificó la asignación de una huella',
                'deleted' => 'eliminó la asignación de una huella',
                default => $evento,
            });
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDispositivo::class, 'asistencia_dispositivo_id');
    }

    /** Asignaciones vigentes. Lo contrario son las históricas, que no se tocan. */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** La asignación VIGENTE de una ranura concreta, o null si está libre. */
    public function scopeDeRanura(Builder $query, int $dispositivoId, int $fingerprintId): Builder
    {
        return $query
            ->where('asistencia_dispositivo_id', $dispositivoId)
            ->where('fingerprint_id', $fingerprintId);
    }

    public function estaLiberada(): bool
    {
        return ! $this->activo;
    }
}
