<?php

namespace App\Models\Asistencia;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un lector biométrico dado de alta (ESP32 + AS608).
 *
 * El token en claro solo existe dos veces: cuando se genera (se muestra UNA vez
 * en la consola) y dentro del firmware. Acá vive únicamente su SHA-256.
 * {@see hashDeToken()} es el único lugar donde se decide cómo se derivan esos
 * hashes, para que el comando que da de alta y el middleware que autentica no
 * puedan divergir.
 */
class AsistenciaDispositivo extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'asistencia_dispositivos';

    protected $fillable = [
        'codigo',
        'nombre',
        'token_hash',
        'activo',
        'capacidad_sensor',
        'ranuras_ocupadas',
        'indice_sincronizado_at',
    ];

    /**
     * `token_hash` fuera de la serialización: no es material utilizable para
     * autenticar —es un hash— pero tampoco tiene por qué aparecer en un JSON,
     * en un log o en un `dd()` por descuido.
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultima_conexion_at' => 'datetime',
            'capacidad_sensor' => 'integer',
            'ranuras_ocupadas' => 'array',
            'indice_sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Auditoría del ALTA, el nombre y la baja de un lector.
     *
     * `logOnly` con lista explícita y NO `logFillable()`: `token_hash` está en
     * `$fillable` —lo escribe el comando de alta— y `logFillable()` lo copiaría
     * al registro de auditoría en cada rotación. El hash no es material con el
     * que autenticar, pero el log de auditoría es de solo-añadir y lo consultan
     * más personas que la tabla: no hay ninguna razón para dejar ahí una copia
     * permanente de un secreto derivado.
     *
     * Que una ROTACIÓN quede registrada igual, sin el valor, es exactamente lo
     * que hace falta: `updated_at` cambia y la actividad queda.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asistencia')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'dio de alta un lector de asistencia',
                'updated' => 'modificó un lector de asistencia',
                'deleted' => 'eliminó un lector de asistencia',
                default => $evento,
            });
    }

    public function ordenesEnrolamiento(): HasMany
    {
        return $this->hasMany(AsistenciaOrdenEnrolamiento::class, 'asistencia_dispositivo_id');
    }

    /**
     * ¿Sabemos qué hay dentro del sensor?
     *
     * `false` mientras el lector no haya reportado su capacidad y su índice. No es
     * un detalle: sin esa información el servidor no puede elegir una ranura sin
     * arriesgarse a pisar una plantilla heredada, y prefiere decirlo a apostar.
     */
    public function tieneIndiceSincronizado(): bool
    {
        return $this->indice_sincronizado_at !== null && $this->capacidad_sensor !== null;
    }

    /**
     * Ranuras con plantilla FÍSICA según la última sincronización. Es una foto: si
     * nadie sincronizó, está vacía, y eso NO significa «el sensor está vacío» sino
     * «no sabemos» — por eso {@see tieneIndiceSincronizado()} se pregunta aparte.
     *
     * @return array<int, int>
     */
    public function ranurasOcupadasEnSensor(): array
    {
        return array_values(array_map('intval', $this->ranuras_ocupadas ?? []));
    }

    /**
     * Guarda lo que el sensor dice de sí mismo. Telemetría del hardware, no un
     * cambio administrativo: por eso `saveQuietly`, igual que la última conexión.
     * Auditar cada sincronización llenaría el registro de ruido sin decir nada que
     * no esté ya en estas columnas.
     *
     * @param  array<int, int>  $ocupadas
     */
    public function sincronizarIndice(int $capacidad, array $ocupadas): void
    {
        $limpias = array_values(array_unique(array_map('intval', $ocupadas)));
        sort($limpias);

        $this->forceFill([
            'capacidad_sensor' => $capacidad,
            'ranuras_ocupadas' => $limpias,
            'indice_sincronizado_at' => Carbon::now(),
        ])->saveQuietly();
    }

    public function huellas(): HasMany
    {
        return $this->hasMany(AsistenciaHuella::class, 'asistencia_dispositivo_id');
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(AsistenciaMarcacion::class, 'asistencia_dispositivo_id');
    }

    /**
     * Token nuevo para un lector: 64 caracteres aleatorios. Es un secreto de
     * máquina, no una contraseña que alguien teclee.
     */
    public static function generarToken(): string
    {
        return Str::random(64);
    }

    /**
     * SHA-256 hex del token. NO es bcrypt a propósito: el token tiene entropía
     * alta (no es adivinable por diccionario) y el lector autentica en cada
     * marcación, así que un hash deliberadamente lento solo agregaría latencia
     * frente a la persona que está esperando en la puerta. Es el mismo criterio
     * de Sanctum para sus tokens de API.
     */
    public static function hashDeToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Comparación en tiempo constante: el argumento es material secreto. */
    public function tokenCoincide(string $token): bool
    {
        return hash_equals($this->token_hash, self::hashDeToken($token));
    }

    /**
     * Deja constancia de que el lector sigue vivo.
     *
     * `forceFill` + `saveQuietly`, y las dos mitades importan:
     *  - las columnas de telemetría NO están en `$fillable` —nadie debería poder
     *    fijarlas desde una petición—, así que un `update()` normal las
     *    descartaría en silencio y el dato no se escribiría nunca;
     *  - `saveQuietly` porque esto es telemetría, no un cambio de datos: no debe
     *    disparar eventos ni dejar una entrada de auditoría por cada marcación.
     */
    public function registrarConexion(?string $ip): void
    {
        $this->forceFill([
            'ultima_conexion_at' => Carbon::now(),
            'ultima_ip' => $ip,
        ])->saveQuietly();
    }
}
