<?php

namespace App\Models;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\RolEnSalida;
use App\Support\HoraNegocio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Salida de ruta: un recorrido concreto, con fechas, estado y participantes.
 *
 * Las transiciones de estado NO se hacen escribiendo `estado` a mano desde el
 * controlador: se piden por {@see iniciar()}, {@see finalizar()} y
 * {@see cancelar()}, que validan contra {@see EstadoSalidaRuta} y son el único
 * lugar donde `fecha_fin_real` se llena. Así una salida no puede quedar
 * finalizada sin fecha de regreso ni saltar de planificada a finalizada.
 *
 * Auditoría: alta/edición con spatie/activitylog; los cambios de estado y de
 * participantes los registra el controlador con su propia descripción, porque son
 * actos con nombre propio y no simples ediciones de campo.
 */
class SalidaRuta extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'salidas_ruta';

    protected $fillable = [
        'ruta_id',
        'fecha_inicio',
        'fecha_fin_estimada',
        'fecha_fin_real',
        'estado',
        'proposito',
        'observaciones',
        'created_by',
        'entrega_dinero_personal_id',
        'liquidada_por',
        'liquidada_en',
    ];

    /** Las salidas que no dicen para qué salen son de entregar, como todas hasta el 05/11/2026. */
    protected $attributes = [
        'proposito' => 'entregar',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin_estimada' => 'date',
            'fecha_fin_real' => 'date',
            'estado' => EstadoSalidaRuta::class,
            'proposito' => PropositoSalida::class,
            'liquidada_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('salida_ruta')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la salida de ruta',
                'updated' => 'actualizó la salida de ruta',
                'deleted' => 'eliminó la salida de ruta',
                default => $evento,
            });
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class, 'ruta_id');
    }

    /** Quién REGISTRÓ la salida (no quién viaja: eso son los `participantes`). */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Quiénes van en la salida, con el papel que lleva cada uno.
     *
     * Reemplaza al pivote `salida_ruta_user`, que apuntaba a `users` y por eso solo admitía
     * gente con login. Los vendedores no la tienen ni deben tenerla: el catálogo de quienes
     * salen es {@see PersonalRuta}.
     */
    public function participantes(): HasMany
    {
        return $this->hasMany(SalidaRutaParticipante::class, 'salida_ruta_id');
    }

    /** Las personas, sin el detalle del pivote. Para listados y etiquetas. */
    public function personal(): BelongsToMany
    {
        return $this->belongsToMany(PersonalRuta::class, 'salida_ruta_participantes', 'salida_ruta_id', 'rutas_personal_id')
            ->withPivot(['rol'])
            ->withTimestamps();
    }

    /**
     * Quién quedó a cargo de este viaje, si alguien lo hizo.
     *
     * Es `hasOne` y no un campo de la salida porque el rol vive en el participante: la misma
     * persona es responsable de un viaje y acompañante del siguiente. Que NO haya
     * responsable es válido —una salida de una sola persona no lo necesita—.
     */
    public function responsable(): HasOne
    {
        return $this->hasOne(SalidaRutaParticipante::class, 'salida_ruta_id')
            ->where('rol', RolEnSalida::Responsable->value);
    }

    /** Los CCF que lleva esta salida y qué pasó con cada uno. */
    public function entregas(): HasMany
    {
        return $this->hasMany(SalidaRutaEntrega::class, 'salida_ruta_id');
    }

    /** Los pedidos que se sacaron en las salas durante esta salida. */
    public function pedidos(): HasMany
    {
        return $this->hasMany(PedidoSala::class, 'salida_ruta_id');
    }

    // ----------------------------------------------------------------- cierres
    public function cierres(): HasMany
    {
        return $this->hasMany(SalidaRutaCierre::class, 'salida_ruta_id');
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(SalidaRutaGasto::class, 'salida_ruta_id');
    }

    /** Quién entregó el dinero de toda la salida, al terminar la ruta. */
    public function entregaDinero(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'entrega_dinero_personal_id');
    }

    public function liquidadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liquidada_por');
    }

    /** Ruta terminada y liquidada: ya no se tocan sus días, entregas ni gastos. */
    public function estaLiquidada(): bool
    {
        return $this->liquidada_en !== null;
    }

    public function cierreDelDia(?string $fecha = null): ?SalidaRutaCierre
    {
        return $this->cierres()->whereDate('fecha', $fecha ?? HoraNegocio::fechaHoy())->first();
    }

    public function diaCerrado(?string $fecha = null): bool
    {
        return $this->cierreDelDia($fecha)?->estaCerrado() ?? false;
    }

    // ------------------------------------------------------------ transiciones

    /** Arranca la salida. Solo desde `planificada`. */
    public function iniciar(): bool
    {
        return $this->transicionar(EstadoSalidaRuta::EnCurso);
    }

    /**
     * Cierra la salida y deja constancia del regreso REAL. Solo desde `en_curso`.
     * Si no se indica fecha se usa hoy, que es el caso normal (se cierra al volver).
     */
    public function finalizar(?string $fechaRegreso = null): bool
    {
        return $this->transicionar(EstadoSalidaRuta::Finalizada, [
            'fecha_fin_real' => $fechaRegreso ?? now()->toDateString(),
        ]);
    }

    /** Cancela la salida. Desde `planificada` o `en_curso`; nunca deja fecha real. */
    public function cancelar(): bool
    {
        return $this->transicionar(EstadoSalidaRuta::Cancelada);
    }

    /**
     * Aplica un cambio de estado si la transición es válida. Devuelve false —sin
     * escribir nada— cuando no lo es, para que el llamador responda con un error
     * en vez de dejar la salida en un estado imposible.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transicionar(EstadoSalidaRuta $destino, array $extra = []): bool
    {
        if (! $this->estado->puedeTransicionarA($destino)) {
            return false;
        }

        return $this->update(['estado' => $destino] + $extra);
    }

    /**
     * Quiénes van, para leer: «Ana Ficticia (encabeza), Beto Ejemplo y Carla Inventada».
     * Quien encabeza va primero y marcado (solo si van varios); los demás, por nombre.
     * Con `$sin` se deja afuera a una persona: en «Mi salida», «Vas con …» no se nombra a
     * quien mira. Usa `personal` (con el rol en el pivote): cargarlo antes en listas.
     */
    public function quienesVan(?int $sin = null): string
    {
        $encabeza = fn (PersonalRuta $p) => $p->pivot?->rol === RolEnSalida::Responsable->value;
        $marcar = $this->personal->count() > 1;

        return $this->personal
            ->reject(fn (PersonalRuta $p) => $p->id === $sin)
            ->sortBy(fn (PersonalRuta $p) => ($encabeza($p) ? '0' : '1').mb_strtolower($p->nombre))
            ->map(fn (PersonalRuta $p) => $p->nombre.($marcar && $encabeza($p) ? ' (encabeza)' : ''))
            ->join(', ', ' y ');
    }

    /** Etiqueta breve para mensajes: «San Miguel · 14–16 ago 2026». */
    public function descripcionCorta(): string
    {
        return ($this->ruta?->nombre ?? 'Salida').' · '.$this->periodoLegible();
    }

    // ----------------------------------------------------------------- scopes

    public function scopeEnEstado(Builder $query, EstadoSalidaRuta $estado): Builder
    {
        return $query->where('estado', $estado->value);
    }

    /** Salidas todavía abiertas: planificadas o en curso. */
    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereIn('estado', [
            EstadoSalidaRuta::Planificada->value,
            EstadoSalidaRuta::EnCurso->value,
        ]);
    }

    /**
     * Rango de fechas legible: «14–16 ago 2026», o solo la de inicio si todavía no
     * hay regreso conocido. Se usa en el encabezado del detalle y en el listado.
     */
    public function periodoLegible(): string
    {
        $fin = $this->fecha_fin_real ?? $this->fecha_fin_estimada;

        if ($fin === null) {
            return $this->fecha_inicio->translatedFormat('d M Y');
        }

        // Mismo mes y año: no se repite el mes ("14–16 ago 2026").
        if ($this->fecha_inicio->isSameMonth($fin)) {
            return $this->fecha_inicio->translatedFormat('d').'–'.$fin->translatedFormat('d M Y');
        }

        return $this->fecha_inicio->translatedFormat('d M').' – '.$fin->translatedFormat('d M Y');
    }
}
