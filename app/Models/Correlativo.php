<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use App\Support\EjercicioControl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Correlativo extends Model
{
    use LogsActivity;

    private const CONSUMO = 'consumió el correlativo';

    protected $table = 'correlativos';

    /**
     * Lo marca el motor de emisión antes de guardar el número que acaba de usar. Solo eso es un
     * consumo: una edición a mano que sube un número es un cambio a mano, y el motor lo respeta.
     */
    public bool $consumoDelMotor = false;

    protected $fillable = [
        'tipo_dte',
        'establecimiento_id',
        'punto_venta_id',
        'ambiente',
        'serie',
        'ultimo_numero',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_dte' => TipoDte::class,
            'ambiente' => AmbienteHacienda::class,
            'ultimo_numero' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * Auditoría SIEMPRE, sin excepción. Es el registro más crítico del sistema: mover
     * `ultimo_numero` hacia atrás puede provocar numeración DUPLICADA ante Hacienda, y
     * hasta ahora no dejaba ningún rastro de quién lo movió ni desde qué valor.
     *
     * Se registran los dos casos y se distinguen en la descripción:
     *  - CONSUMO normal: el motor de emisión avanza el número exactamente en 1 al
     *    generar un documento. Queda un registro por documento, que es justamente el
     *    rastro que hace falta para reconstruir qué número fue de quién.
     *  - EDICIÓN manual: cualquier otro cambio (salto, retroceso, cambio de serie,
     *    activar/desactivar), que es lo que hay que poder auditar de verdad.
     *
     * Ningún campo es secreto: se registra el antes y el después completo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('configuracion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el correlativo',
                'updated' => $this->esConsumoNormal() ? self::CONSUMO : 'modificó el correlativo',
                'deleted' => 'eliminó el correlativo',
                default => $evento,
            });
    }

    /**
     * ¿El cambio en curso es el avance normal del motor de emisión (solo `ultimo_numero`,
     * y exactamente +1)? Cualquier otra cosa es una edición que merece leerse distinto.
     * updated_at no cuenta: con el reloj andando cambia en cada guardado.
     */
    private function esConsumoNormal(): bool
    {
        $sucios = array_values(array_diff(array_keys($this->getDirty()), [$this->getUpdatedAtColumn()]));

        return $this->consumoDelMotor
            && $sucios === ['ultimo_numero']
            && (int) $this->ultimo_numero === ((int) $this->getOriginal('ultimo_numero')) + 1;
    }

    /**
     * Cuándo fijó alguien el último número, si lo último que lo movió fue un cambio a mano (el
     * alta o una edición, por la pantalla o por un comando) y no el motor de emisión. Sale de
     * la bitácora 'configuracion', que anota `ultimo_numero` solo cuando cambia; no de
     * updated_at, que también se mueve al desactivarlo o cambiarle la serie. null si lo último
     * fue el motor (consumo, reinicio o salto), si no quedó anotado o si está en cero.
     */
    public function numeroFijadoAManoEl(): ?CarbonInterface
    {
        if ($this->ultimo_numero <= 0) {
            return null;
        }

        $ultimo = Activity::query()
            ->where('log_name', 'configuracion')
            ->whereMorphedTo('subject', $this)
            ->where(fn ($q) => $q->whereNotNull('properties->attributes->ultimo_numero')->orWhereNotNull('properties->ultimo_numero'))
            ->latest('id')
            ->first();

        $aMano = $ultimo !== null
            && in_array($ultimo->event, ['created', 'updated'], true)
            && $ultimo->description !== self::CONSUMO
            // El número de hoy (leído con el candado) tiene que ser el que se fijó: si no, lo
            // movió otro después, aunque esta lectura todavía no lo vea.
            && (int) data_get($ultimo->properties, 'attributes.ultimo_numero') === $this->ultimo_numero;

        return $aMano ? $ultimo->created_at : null;
    }

    /**
     * Siguiente número que se asignaría (solo lectura/informativo).
     * La asignación real y transaccional se implementará en el motor DTE.
     */
    public function getSiguienteNumeroAttribute(): int
    {
        return $this->ultimo_numero + 1;
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    /** Documentos que salieron con este correlativo (lo toman al generarse). */
    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }

    /**
     * El número más alto que ya usó un documento de este correlativo en el ejercicio de hoy:
     * desde 2027 vuelve a 1 cada año y lo anterior cuenta como 2026, igual que al emitir. 0 si
     * este año todavía no salió ninguno, porque el siguiente documento empieza el año (por
     * ejemplo, después de lo que numeró Conta Portable en enero). Bajar `ultimo_numero` por
     * debajo de este número haría que el siguiente documento repita uno ya usado.
     */
    public function mayorNumeroEmitido(): int
    {
        $hoy = EjercicioControl::hoy();

        return (int) $this->dtes()->whereNotNull('numero_interno')
            ->when($hoy > EjercicioControl::ANTERIOR_AL_REINICIO, fn ($q) => $q->where('ejercicio', $hoy))
            ->pluck('numero_interno')
            ->map(fn (string $numero) => preg_match('/(\d{1,15})$/', $numero, $m) ? (int) $m[1] : 0)
            ->max();
    }
}
