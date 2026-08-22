<?php

namespace App\Models\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Models\User;
use App\Services\Asistencia\ExpirarOrdenesVencidas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una orden de enrolamiento: «este lector tiene que grabar la huella de esta
 * persona en esta ranura».
 *
 * Es el buzón que permite que el servidor le pida algo al ESP32 sin poder
 * llamarlo. El lector sondea, encuentra SU orden y la ejecuta.
 *
 * ─────────────────── La expiración se materializa al leer ───────────────────
 *
 * `expira_at` no la aplica ningún cron. {@see estaViva()} la comprueba en el
 * momento y {@see ExpirarOrdenesVencidas} la escribe
 * justo antes de las dos operaciones a las que le importa (crear y sondear).
 *
 * Es deliberado: este proyecto no tiene el scheduler corriendo, y una orden que
 * dependiera de un job para vencer podría ejecutarse horas después de haberse
 * abandonado. Con esto, una orden vencida está vencida desde el instante en que
 * alguien la mira, y **no revive nunca**.
 *
 * ────────────────────────── El token de la orden ──────────────────────────
 *
 * Se guarda HASHEADO, igual que el del lector y por el mismo motivo. El valor en
 * claro se devuelve UNA vez, en la respuesta del sondeo, y no se puede recuperar
 * después. No es el token del lector: es de esta orden, muere con ella y **jamás
 * pasa por el navegador**.
 *
 * Se puede reemitir si el mismo lector vuelve a sondear una orden que ya tomó
 * —la respuesta anterior pudo perderse en la red—, y al reemitirlo el anterior
 * deja de valer.
 */
class AsistenciaOrdenEnrolamiento extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Cuánto vive una orden. Es un acto supervisado: si nadie puso el dedo, se abandonó. */
    public const MINUTOS_DE_VIDA = 3;

    /** Reintentos automáticos cuando el sensor resulta tener la ranura ocupada. */
    public const MAX_INTENTOS = 3;

    protected $table = 'asistencia_ordenes_enrolamiento';

    protected $fillable = [
        'asistencia_dispositivo_id',
        'asistencia_empleado_id',
        'estado',
        'ranura_reservada',
        'ranura_manual',
        'token_hash',
        'asistencia_huella_id',
        'motivo_fallo',
        'detalle',
        'intento',
        'orden_origen_id',
        'expira_at',
        'tomada_at',
        'finalizada_at',
        'solicitada_por_user_id',
    ];

    /**
     * El hash del token fuera de toda serialización. No es material con el que
     * autenticar —es un hash— pero tampoco tiene por qué aparecer en un JSON, en
     * un log o en un `dd()` por descuido. La columna generada tampoco se expone:
     * es un detalle del índice, no información.
     */
    protected $hidden = [
        'token_hash',
        'orden_activa_uq',
        'ranura_reservada_uq',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenEnrolamiento::class,
            'motivo_fallo' => MotivoFalloEnrolamiento::class,
            'ranura_reservada' => 'integer',
            'ranura_manual' => 'boolean',
            'intento' => 'integer',
            'expira_at' => 'datetime',
            'tomada_at' => 'datetime',
            'finalizada_at' => 'datetime',
        ];
    }

    /**
     * Auditoría del ciclo de la orden. `logOnly` con lista explícita y NO
     * `logFillable()`: `token_hash` está en `$fillable` y `logFillable()` lo
     * copiaría al registro de auditoría en cada emisión. El log es de solo-añadir
     * y lo consulta más gente que la tabla.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'ranura_reservada', 'ranura_manual', 'motivo_fallo', 'detalle', 'asistencia_huella_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asistencia')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'inició el registro de una huella',
                'updated' => 'avanzó el registro de una huella',
                'deleted' => 'eliminó una orden de registro de huella',
                default => $evento,
            });
    }

    // ------------------------------------------------------------- relaciones

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDispositivo::class, 'asistencia_dispositivo_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function huella(): BelongsTo
    {
        return $this->belongsTo(AsistenciaHuella::class, 'asistencia_huella_id');
    }

    public function solicitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por_user_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'orden_origen_id');
    }

    // --------------------------------------------------------------- alcances

    /**
     * Órdenes que ocupan el buzón. Se filtra por estado Y por expiración: una
     * orden con estado `pendiente` pero vencida NO está viva, aunque su fila
     * todavía no se haya materializado.
     */
    public function scopeVivas(Builder $query): Builder
    {
        return $query
            ->whereIn('estado', EstadoOrdenEnrolamiento::vivos())
            ->where('expira_at', '>', Carbon::now());
    }

    /** Vivas por estado pero ya vencidas: las que hay que materializar. */
    public function scopeVencidas(Builder $query): Builder
    {
        return $query
            ->whereIn('estado', EstadoOrdenEnrolamiento::vivos())
            ->where('expira_at', '<=', Carbon::now());
    }

    public function scopeDeDispositivo(Builder $query, int $dispositivoId): Builder
    {
        return $query->where('asistencia_dispositivo_id', $dispositivoId);
    }

    // ---------------------------------------------------------------- estado

    /** ¿Sigue en pie? Estado vivo Y sin vencer. */
    public function estaViva(): bool
    {
        return $this->estado->estaViva() && $this->expira_at->isFuture();
    }

    /** Vencida de hecho, aunque su estado todavía no lo diga. */
    public function estaVencida(): bool
    {
        return $this->estado->estaViva() && $this->expira_at->isPast();
    }

    public function segundosParaExpirar(): int
    {
        return max(0, (int) Carbon::now()->diffInSeconds($this->expira_at, false));
    }

    // ---------------------------------------------------------------- token

    /**
     * Emite un token NUEVO y devuelve el valor en claro. Solo se puede leer acá:
     * en base queda su SHA-256.
     *
     * Reemitir invalida el anterior a propósito. Si el lector vuelve a sondear una
     * orden que ya tomó es porque no recibió la respuesta; que el token viejo deje
     * de valer cierra la ventana en que dos copias de la misma orden podrían
     * responder.
     */
    public function emitirToken(): string
    {
        $token = Str::random(32);

        $this->forceFill(['token_hash' => self::hashDeToken($token)])->save();

        return $token;
    }

    /** Mismo criterio que el token del lector: SHA-256, sin bcrypt. */
    public static function hashDeToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Comparación en tiempo constante: el argumento es material secreto. */
    public function tokenCoincide(string $token): bool
    {
        if (blank($this->token_hash)) {
            return false;
        }

        return hash_equals($this->token_hash, self::hashDeToken($token));
    }

    /** Lo que ve el lector. Sin token, sin secretos, sin datos de más. */
    public function paraDispositivo(): array
    {
        return [
            'id' => $this->id,
            'empleado' => [
                'id' => $this->asistencia_empleado_id,
                // El nombre corto: la pantalla del lector mide 128x128.
                'nombre_corto' => $this->empleado?->nombreCorto(),
            ],
            'ranura' => $this->ranura_reservada,
            'capacidad' => $this->dispositivo?->capacidad_sensor,
            'expira_en' => $this->segundosParaExpirar(),
            'intento' => $this->intento,
        ];
    }
}
