<?php

namespace App\Models\Gastos;

use App\Models\User;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una obligación que vuelve: alquiler, agua, seguro, cuota de préstamo.
 *
 * La regla NO es una deuda. Es la plantilla con la que se crean deudas, una por
 * período. Mientras nadie la genere, no hay nada que pagar y no aparece en ningún
 * total: por eso `gastos_reglas` no tiene importe pendiente, saldo ni estado de
 * pago. Lo que se paga son los gastos que produce, por el camino normal.
 *
 * `estado` tiene tres valores y ninguno toca lo ya generado:
 *
 *   activa     genera lo que le toque
 *   pausada    NO genera; las obligaciones existentes siguen vivas y exigibles
 *   cancelada  cerrada para siempre; tampoco borra nada de lo anterior
 */
class Regla extends Model
{
    protected $table = 'gastos_reglas';

    protected $guarded = ['id'];

    public const ESTADOS = [
        // Guardada a medio llenar: falta decir CUÁNDO se cobra. No genera nada y no
        // aparece en «Por pagar». Existe para que nadie tenga que inventar un día con
        // tal de poder guardar, que es lo que pasaba antes.
        'borrador' => 'Por completar',
        'activa' => 'Activa',
        'pausada' => 'Pausada',
        'cancelada' => 'Cancelada',
    ];

    /** Estados en los que la regla NO puede generar obligaciones. */
    public const SIN_GENERAR = ['borrador', 'pausada', 'cancelada'];

    public const MONTO_MODOS = [
        'fijo' => 'Siempre el mismo importe',
        'variable' => 'Cambia cada período (llega recibo)',
    ];

    protected function casts(): array
    {
        return [
            'importe' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'generado_hasta' => 'date',
            'pausada_at' => 'datetime',
            'cancelada_at' => 'datetime',
        ];
    }

    public function ocurrencias(): HasMany
    {
        return $this->hasMany(Ocurrencia::class, 'regla_id')->orderByDesc('vence');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(ReglaVersion::class, 'regla_id')->orderByDesc('version');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function activa(): bool
    {
        return $this->estado === 'activa';
    }

    /**
     * ¿Está a medio llenar?
     *
     * No genera obligaciones por construcción, no por un filtro que alguien tenga que
     * acordarse de poner: {@see GenerarObligaciones}
     * solo trabaja con reglas activas, y esta no lo está.
     */
    public function porCompletar(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * ¿Tiene ya el «cuándo» que le falta para poder activarse?
     *
     * Es la misma pregunta que hace la validación al activar, pero en un sitio donde la
     * pantalla puede consultarla para encender o apagar el botón.
     */
    public function programacionCompleta(): bool
    {
        return match ($this->frecuencia) {
            'semanal' => $this->dia_semana !== null,
            'quincenal' => $this->dia_mes !== null && $this->dia_mes_2 !== null,
            'mensual' => $this->dia_mes !== null,
            'anual' => $this->dia_mes !== null && $this->mes !== null,
            default => false,
        };
    }

    /**
     * Qué le falta a un borrador, en palabras, sin adivinar y sin inventar.
     *
     * Vive acá y no en cada pantalla porque el panel y el detalle tienen que decir LO
     * MISMO. Cuando no coincidían pasaba esto: el panel decía «un dato por confirmar»
     * —correcto— y el detalle afirmaba que faltaba el día de cobro sobre una regla que
     * ya tenía el 15 guardado. Una pantalla que reclama un dato que sí está enseña a
     * desconfiar de las que reclaman uno que de verdad falta.
     *
     * Lista vacía NO significa «no falta nada»: significa que lo que falta no es ni el
     * día ni el importe, y entonces está escrito en las observaciones de la regla —el
     * nombre de la institución, por ejemplo—. Por eso sigue siendo un borrador.
     *
     * @return array<int, string>
     */
    public function faltantes(): array
    {
        $falta = [];

        if (! $this->programacionCompleta()) {
            $falta[] = 'el día de cobro';
        }

        if ($this->monto_modo === 'fijo' && $this->importe === null) {
            $falta[] = 'el importe';
        }

        return $falta;
    }

    /** Monto variable: la obligación nace SIN importe y espera el recibo. */
    public function montoVariable(): bool
    {
        return $this->monto_modo === 'variable';
    }

    /** Los campos de calendario, en la forma que espera CalendarioRecurrencia. */
    public function calendario(): array
    {
        return [
            'frecuencia' => $this->frecuencia,
            'dia_semana' => $this->dia_semana,
            'dia_mes' => $this->dia_mes,
            'dia_mes_2' => $this->dia_mes_2,
            'mes' => $this->mes,
        ];
    }

    /**
     * La plantilla con la que se crea el gasto de un período. Se copia tal cual desde
     * la VERSIÓN vigente, no desde la regla, cuando se regenera un período viejo.
     */
    public function plantilla(): array
    {
        return [
            'beneficiario' => $this->beneficiario,
            'concepto' => $this->concepto,
            'categoria' => $this->categoria,
            'ambito' => $this->ambito,
            'persona' => $this->persona,
            'naturaleza' => $this->naturaleza,
            'moneda' => $this->moneda,
            'documentacion' => $this->documentacion,
            'observaciones' => $this->observaciones,
            'responsable_id' => $this->responsable_id,
            'monto_modo' => $this->monto_modo,
            'importe' => $this->importe,
        ];
    }
}
