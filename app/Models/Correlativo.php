<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use App\Support\EjercicioControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Correlativo extends Model
{
    use LogsActivity;

    protected $table = 'correlativos';

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
                'updated' => $this->esConsumoNormal() ? 'consumió el correlativo' : 'modificó el correlativo',
                'deleted' => 'eliminó el correlativo',
                default => $evento,
            });
    }

    /**
     * ¿El cambio en curso es el avance normal del motor de emisión (solo `ultimo_numero`,
     * y exactamente +1)? Cualquier otra cosa es una edición que merece leerse distinto.
     */
    private function esConsumoNormal(): bool
    {
        $sucios = array_keys($this->getDirty());

        return $sucios === ['ultimo_numero']
            && (int) $this->ultimo_numero === ((int) $this->getOriginal('ultimo_numero')) + 1;
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
     * El número más alto que ya usó un documento de este correlativo, en el ejercicio en
     * que va la numeración: desde 2027 vuelve a 1 cada año y lo anterior cuenta como 2026,
     * igual que al emitir. 0 si todavía no salió ninguno. Bajar `ultimo_numero` por debajo
     * de este número haría que el siguiente documento repita uno ya usado.
     */
    public function mayorNumeroEmitido(): int
    {
        $emitidos = fn () => $this->dtes()->whereNotNull('numero_interno');
        $ejercicio = (int) $emitidos()->max('ejercicio');

        return (int) $emitidos()
            ->when($ejercicio > EjercicioControl::ANTERIOR_AL_REINICIO, fn ($q) => $q->where('ejercicio', $ejercicio))
            ->pluck('numero_interno')
            ->map(fn (string $numero) => preg_match('/(\d{1,15})$/', $numero, $m) ? (int) $m[1] : 0)
            ->max();
    }
}
