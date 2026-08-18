<?php

namespace App\Services\Rutas;

use App\Models\PpqItem;

/**
 * Las DOS respuestas que un documento tiene sobre PPQ, separadas a propósito.
 *
 * Antes acá había un solo `PpqItem` que contestaba las dos preguntas a la vez, y por
 * eso una de ellas destruía a la otra: al retirar un lote, el pago que ese lote había
 * registrado desaparecía junto con él y el documento volvía a figurar como deuda.
 * Son hechos distintos y de naturaleza distinta:
 *
 *   · PRESENTADO  — ¿está HOY dentro de un lote de cobro vivo? Es un estado ACTUAL y
 *                   reversible: si el lote se retira, deja de estar presentado y
 *                   vuelve a la cola de «pendiente de ingresar». El trabajo es NUESTRO.
 *
 *   · CONCILIADO  — ¿apareció en el TXT de pagos de Calleja? Es un hecho HISTÓRICO y
 *                   consumado: la plata entró. Retirar el lote después es una acción
 *                   administrativa sobre el paquete, no un reembolso. Borrarlo haría
 *                   que el sistema reclame dos veces algo que ya se cobró.
 *
 * De ahí la regla que gobierna esta clase: un lote retirado APAGA la presentación,
 * pero NUNCA borra la conciliación.
 *
 * ─────────────────────────────── Por qué dos ranuras y no dos clases ───────────────────────────────
 *
 * Porque un mismo documento puede tener las dos cosas a la vez y por renglones
 * DISTINTOS: pagado en un lote que después se retiró y, además, vuelto a presentar en
 * un lote vivo. Guardar un solo item obligaría a elegir cuál de los dos hechos contar
 * y a perder el otro. Guardando las dos ranuras, la presentación se lee del lote vivo
 * y el dinero del renglón conciliado, sin que ninguno pise al otro.
 *
 * El dinero NO se duplica por esto: la conciliación es UNA sola ranura, así que por
 * más renglones que tenga el documento se cobra una vez y nada más.
 */
final class RenglonPpq
{
    private function __construct(
        /** El renglón que sostiene la presentación ACTUAL. Solo puede venir de un lote vivo. */
        public readonly ?PpqItem $presentado,
        /** El renglón que prueba el cobro. Vale aunque su lote se haya retirado después. */
        public readonly ?PpqItem $conciliado,
        /** Si el renglón conciliado está en un lote vivo. Solo se usa para desempatar. */
        private readonly bool $conciliadoVigente,
    ) {}

    public static function vacio(): self
    {
        return new self(null, null, false);
    }

    /**
     * Suma un renglón al conjunto y devuelve el resultado. No muta: se arma plegando
     * los items uno a uno, así que el orden en que llegan no cambia el resultado.
     *
     * @param  bool  $enLoteVigente  si el lote de ESTE item sigue vivo (lo decide quien
     *                               hizo la consulta, que es el único que sabe si cargó
     *                               `deleted_at`)
     */
    public function con(PpqItem $item, bool $enLoteVigente): self
    {
        return new self(
            // Un item de lote retirado no presenta nada, sea cual sea su estado.
            $enLoteVigente ? $this->mejorPresentado($this->presentado, $item) : $this->presentado,
            $this->ganaComoConciliado($item, $enLoteVigente) ? $item : $this->conciliado,
            $this->ganaComoConciliado($item, $enLoteVigente) ? $enLoteVigente : $this->conciliadoVigente,
        );
    }

    /** ¿Está HOY dentro de un lote de cobro vivo? */
    public function estaPresentado(): bool
    {
        return $this->presentado !== null;
    }

    /**
     * El renglón que se muestra en pantalla.
     *
     * Manda el conciliado cuando existe porque es el hecho más fuerte que hay que
     * contar («Pagado / conciliado» con su fecha y su monto). Si no hay cobro todavía,
     * se muestra el presentado, que es el que explica en qué lote está esperando.
     */
    public function paraMostrar(): ?PpqItem
    {
        return $this->conciliado ?? $this->presentado;
    }

    /**
     * Entre dos renglones VIVOS del mismo documento, cuál sostiene la presentación:
     * conciliado le gana a pendiente y, a igualdad, el más reciente. Es la regla que
     * ya existía y no cambia.
     */
    private function mejorPresentado(?PpqItem $actual, PpqItem $candidato): PpqItem
    {
        if ($actual === null) {
            return $candidato;
        }

        if ($actual->estaConciliado() !== $candidato->estaConciliado()) {
            return $candidato->estaConciliado() ? $candidato : $actual;
        }

        return $candidato->id >= $actual->id ? $candidato : $actual;
    }

    /**
     * ¿Este item pasa a ser el renglón conciliado del documento?
     *
     * Solo compiten los que están conciliados de verdad (`pagado` o `aplicada`). Entre
     * ellos gana el de lote vivo —es el que alguien puede ir a mirar— y, a igualdad de
     * condiciones, el más reciente. Un lote retirado NO descalifica: si es el único
     * renglón conciliado que hay, ese es el que prueba el cobro.
     */
    private function ganaComoConciliado(PpqItem $candidato, bool $enLoteVigente): bool
    {
        if (! $candidato->estaConciliado()) {
            return false;
        }

        if ($this->conciliado === null) {
            return true;
        }

        if ($this->conciliadoVigente !== $enLoteVigente) {
            return $enLoteVigente;
        }

        return $candidato->id >= $this->conciliado->id;
    }
}
