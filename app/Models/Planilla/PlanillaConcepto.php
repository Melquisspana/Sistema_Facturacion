<?php

namespace App\Models\Planilla;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ingreso o un descuento de una línea, con concepto e importe ESCRITOS A MANO.
 *
 * Nada de esto se calcula: ni porcentajes legales, ni proporcionales, ni horas extra.
 * Quien prepara la planilla escribe «Bono de venta 25.00» o «Anticipo 50.00».
 *
 * `destino` es lo que evita contar el dinero dos veces, y solo aplica a los
 * descuentos. Queda NULL mientras no se elija: no hay valor por defecto porque
 * adivinar cuál de los tres es sería el error que esto viene a corregir.
 *
 *   anticipo → ya se le pagó antes. Reduce lo que se le debe y NO genera obligación.
 *              Guarda la REFERENCIA de ese pago: sin ella, dentro de tres meses nadie
 *              puede comprobar que el descuento correspondía.
 *   tercero  → se le debe a alguien más (cuota de un préstamo, una retención que se
 *              entrega). Reduce lo que se le paga a la persona Y genera una obligación
 *              con ese tercero, que es dinero que la empresa sigue debiendo.
 *   otro     → cualquier otro descuento acordado.
 *
 * Sin esta distinción, o se pierde la deuda con el tercero, o se suma el bruto y el
 * neto y el gasto salarial sale duplicado.
 */
class PlanillaConcepto extends Model
{
    protected $table = 'planilla_conceptos';

    protected $guarded = ['id'];

    public const TIPOS = ['ingreso' => 'Otro ingreso', 'descuento' => 'Descuento'];

    /**
     * TRES destinos, no dos. «Ya entregado» era ambiguo: daba por sentado que todo
     * descuento es dinero que la persona ya recibió, y muchos no lo son.
     */
    public const DESTINOS = [
        'anticipo' => 'Anticipo que ya se le pagó',
        'tercero' => 'Se le entrega a alguien más',
        'otro' => 'Otro descuento acordado',
    ];

    /** Ayuda corta de cada destino, para mostrarla junto a la opción. */
    public const DESTINOS_AYUDA = [
        'anticipo' => 'Dinero que ya salió antes. Hay que decir cuál anticipo era.',
        'tercero' => 'La empresa se lo debe a alguien más. Hay que decir a quién.',
        'otro' => 'Ni dinero ya entregado ni deuda con nadie de fuera.',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2'];
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(PlanillaDetalle::class, 'planilla_detalle_id');
    }

    /** El anticipo concreto que este descuento recupera, si lo hay. */
    public function anticipo(): BelongsTo
    {
        return $this->belongsTo(PlanillaAnticipo::class, 'planilla_anticipo_id');
    }

    public function esDescuento(): bool
    {
        return $this->tipo === 'descuento';
    }

    /** ¿Este descuento va a generar una obligación con un tercero? */
    public function generaDeudaConTercero(): bool
    {
        return $this->esDescuento() && $this->destino === 'tercero' && filled($this->tercero);
    }

    /** ¿Es dinero que ya se le pagó a la persona? */
    public function esAnticipo(): bool
    {
        return $this->esDescuento() && $this->destino === 'anticipo';
    }

    /** A quién o a qué apunta este descuento, en una línea para mostrar. */
    public function aQuePunta(): string
    {
        return match (true) {
            $this->generaDeudaConTercero() => 'se entrega a '.$this->tercero,
            $this->esAnticipo() => 'anticipo ya pagado'
                .($this->anticipo ? ' del '.$this->anticipo->fecha->format('d/m/Y') : '')
                .(filled($this->referencia) ? ' · '.$this->referencia : ''),
            $this->destino === 'otro' => 'descuento acordado',
            default => 'sin clasificar',
        };
    }
}
