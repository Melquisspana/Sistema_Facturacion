<?php

namespace App\Models\Planilla;

use App\Models\User;
use App\Services\Planilla\VacacionesAnuales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La planilla de un período.
 *
 * En BORRADOR se edita libremente y no debe nada: no existe ninguna obligación, no
 * hay nada que pagar y cambiar un importe no tiene consecuencias. CONFIRMADA genera
 * sus obligaciones UNA sola vez y deja de editarse.
 *
 * No calcula nada legal. Los importes los escribe una persona.
 */
class Planilla extends Model
{
    protected $table = 'planillas';

    protected $guarded = ['id'];

    public const TIPOS_PERIODO = [
        'semanal' => 'Semanal',
        'quincenal' => 'Quincenal (dos por mes)',
        'mensual' => 'Mensual',
    ];

    public const CLASES = [
        'regular' => 'Normal',
        'extraordinaria' => 'Fuera de calendario',
        'aguinaldo' => 'Aguinaldo',
        'quincena25' => 'Quincena 25',
        'vacaciones' => 'Vacaciones',
    ];

    public const ESTADOS = [
        'borrador' => 'Borrador',
        'confirmada' => 'Confirmada',
        'anulada' => 'Anulada',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date', 'hasta' => 'date', 'fecha_pago' => 'date',
            'confirmada_at' => 'datetime', 'anulada_at' => 'datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PlanillaDetalle::class, 'planilla_id')->orderBy('nombre_snapshot');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function borrador(): bool
    {
        return $this->estado === 'borrador';
    }

    public function confirmada(): bool
    {
        return $this->estado === 'confirmada';
    }

    /**
     * La persona de un pago individual (vacaciones). Las planillas de varios guardan 0 en
     * `individual_de`, así que no la tienen.
     */
    public function individual(): BelongsTo
    {
        return $this->belongsTo(PlanillaEmpleado::class, 'individual_de');
    }

    /**
     * Pago de vacaciones: siempre de UNA persona y anual. En pantalla no se le muestra
     * tipo de período ni mes: `tipo_periodo` y `periodo` son solo su clave técnica.
     */
    public function esVacaciones(): bool
    {
        return $this->clase === VacacionesAnuales::CLASE;
    }

    /** «Pago de vacaciones de Ana Pérez». */
    public function nombreDeVacaciones(): string
    {
        return 'Pago de vacaciones de '.($this->individual?->nombre ?? $this->detalles->first()?->nombre_snapshot ?? '—');
    }

    /**
     * El año de servicio que paga, para un pago de vacaciones.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function anioDeServicio(): ?array
    {
        if (! $this->esVacaciones() || $this->individual === null || $this->desde === null) {
            return null;
        }

        return app(VacacionesAnuales::class)->anioDeServicio($this->individual, $this->desde->toImmutable());
    }

    /** Cómo se lee el período, en palabras. En vacaciones, el año de servicio. */
    public function periodoEnPalabras(): string
    {
        if ($this->esVacaciones()) {
            $anio = $this->anioDeServicio();

            return $anio === null ? 'Año de servicio sin fecha de ingreso'
                : 'Año de servicio: del '.$anio['desde']->format('d/m/Y').' al '.$anio['hasta']->format('d/m/Y');
        }

        return $this->desde?->format('d/m/Y').' al '.$this->hasta?->format('d/m/Y');
    }
}
