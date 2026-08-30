<?php

namespace App\Services\Rutas;

use App\Models\PpqAlbaran;
use Illuminate\Support\Collection;

/**
 * El resultado de buscarle el albarán de ENTREGA a un documento: o hay uno inequívoco, o
 * hay una razón concreta por la que no lo hay.
 *
 * ─────────────────────────── Por qué no basta con `?PpqAlbaran` ───────────────────────────
 *
 * Antes esta búsqueda devolvía un albarán o `null`, y «null» tapaba tres situaciones que
 * no son la misma y que se resuelven de forma distinta:
 *
 *   · no llegó ningún albarán todavía          → hay que esperar, o reclamar;
 *   · llegaron VARIOS y no se sabe cuál es      → hay que mirarlo y elegir;
 *   · el que llegó no dice de qué tipo es       → hay que completar el número a mano.
 *
 * Y peor: cuando llegaban varios, el código se quedaba con «el primero», sin orden
 * definido y sin mirar el tipo. Como el vínculo se hace por ORDEN DE COMPRA, y una misma
 * OC ampara el albarán de ENTREGA (AC01) y el de CRÉDITO que se emite después si hubo
 * avería o devolución (AC02, AC04), el documento podía quedar «entregado» por un albarán
 * de abono, y tomar de él el monto contra el que se calcula la diferencia con el CCF.
 *
 * ─────────────────── La regla: candidato de ENTREGA ÚNICO, siempre ───────────────────
 *
 * La identidad es el TIPO y la UNICIDAD, y no hay ninguna vía que se salte esa
 * comprobación. Monto y sala NO deciden el vínculo: sirven después, como validación, para
 * avisar de que algo no cuadra. Un albarán no es el de este documento porque su monto se
 * parezca —dos pedidos de la misma sala en la misma semana se parecen— y darlo por bueno
 * por eso es exactamente cómo se pinta «entregado» sobre algo que nadie entregó.
 *
 * ──────────── El vínculo explícito NO es una excepción a la regla ────────────
 *
 * `ppq_albaranes.dte_id` es la llave más fuerte para decir DE QUÉ DOCUMENTO es un albarán,
 * y por eso gana sobre la orden de compra. Pero responde a otra pregunta que la de este
 * módulo: «¿de quién es este albarán?» no es «¿este albarán prueba una entrega?».
 *
 * Una versión anterior de esta clase aceptaba cualquier albarán vinculado por `dte_id`
 * como entrega, sin mirar el tipo. Contradecía la regla de arriba y dejaba abierta la
 * misma puerta que se acababa de cerrar: bastaba que alguien vinculara a mano el AC02 de
 * una avería para que el CCF apareciera entregado. Ahora el vínculo explícito decide
 * QUIÉN compite, no si gana: entre los albaranes vinculados a este documento se aplica
 * exactamente el mismo criterio de tipo y unicidad.
 *
 * Y cuando el vínculo explícito existe pero no resuelve, NO se cae a la orden de compra.
 * Hacerlo dejaría que un AC01 hallado por OC tape el hecho de que alguien vinculó un
 * albarán de crédito a este documento: el «entregado» aparecería igual y el dato mal
 * puesto no se vería nunca. Se prefiere la excepción, que es lo que manda a alguien a
 * mirarlo.
 */
final class ResolucionAlbaran
{
    /** Hay un único albarán de entrega y es este. */
    public const VINCULADO = 'vinculado';

    /** No hay ningún albarán para esa llave. Lo normal mientras el pedido no se entrega. */
    public const SIN_CANDIDATOS = 'sin_candidatos';

    /** Hay más de un albarán de entrega posible: lo decide una persona, no el sistema. */
    public const VARIOS_CANDIDATOS = 'varios_candidatos';

    /**
     * Hay albaranes, pero ninguno dice ser de entrega: o son de crédito (AC02/AC04), o su
     * número quedó incompleto y no se puede saber. En ninguno de los dos casos se supone
     * que es de entrega.
     */
    public const TIPO_INDETERMINADO = 'tipo_indeterminado';

    private function __construct(
        public readonly ?PpqAlbaran $albaran,
        public readonly string $estado,
        /** @var Collection<int, PpqAlbaran> Todo lo que había para esa llave, tipos incluidos. */
        public readonly Collection $candidatos,
        /** ¿Se resolvió sobre albaranes vinculados por `dte_id`? Solo cambia cómo se explica. */
        public readonly bool $porVinculoExplicito = false,
    ) {}

    /**
     * Decide a partir de TODOS los albaranes que comparten la llave.
     *
     * @param  Collection<int, PpqAlbaran>|array<int, PpqAlbaran>  $albaranes
     * @param  bool  $porVinculoExplicito  si la llave fue `dte_id` en vez de la orden de compra
     */
    public static function decidir(Collection|array $albaranes, bool $porVinculoExplicito = false): self
    {
        $todos = $albaranes instanceof Collection ? $albaranes->values() : collect($albaranes)->values();

        if ($todos->isEmpty()) {
            return new self(null, self::SIN_CANDIDATOS, $todos, $porVinculoExplicito);
        }

        $entrega = $todos->filter(fn (PpqAlbaran $a) => $a->esDeEntrega())->values();

        return match ($entrega->count()) {
            0 => new self(null, self::TIPO_INDETERMINADO, $todos, $porVinculoExplicito),
            1 => new self($entrega->first(), self::VINCULADO, $todos, $porVinculoExplicito),
            default => new self(null, self::VARIOS_CANDIDATOS, $todos, $porVinculoExplicito),
        };
    }

    public static function vacia(): self
    {
        return new self(null, self::SIN_CANDIDATOS, collect());
    }

    public function estaVinculado(): bool
    {
        return $this->estado === self::VINCULADO;
    }

    /** ¿Hay algo que una persona tenga que mirar? Faltar un albarán todavía no lo es. */
    public function esExcepcion(): bool
    {
        return in_array($this->estado, [self::VARIOS_CANDIDATOS, self::TIPO_INDETERMINADO], true);
    }

    /**
     * Qué pasa y qué hay que hacer, en una frase. `null` cuando el vínculo se resolvió o
     * cuando simplemente todavía no llegó nada, que no es una excepción sino la espera
     * normal.
     *
     * El texto cambia según cómo se llegó a los candidatos, porque la acción que hay que
     * tomar es distinta: por orden de compra se elige entre varios; por vínculo explícito
     * hay que ir a corregir un vínculo que alguien puso mal.
     */
    public function motivo(): ?string
    {
        if ($this->porVinculoExplicito) {
            return match ($this->estado) {
                self::VARIOS_CANDIDATOS => 'Hay '.$this->candidatos->count().' albaranes de entrega vinculados a este documento: '
                    .'dejá vinculado solo el que corresponde.',
                self::TIPO_INDETERMINADO => 'El albarán vinculado a este documento no es de entrega '
                    .'(puede ser de avería o devolución, o tener el número incompleto), así que no prueba ninguna entrega. '
                    .'Revisá el vínculo.',
                default => null,
            };
        }

        return match ($this->estado) {
            self::VARIOS_CANDIDATOS => 'Hay '.$this->candidatos->count().' albaranes de entrega para esta orden de compra: '
                .'elegí a mano cuál corresponde a este documento.',
            self::TIPO_INDETERMINADO => 'Llegó un albarán para esta orden de compra pero no consta que sea de entrega '
                .'(puede ser de avería o devolución, o tener el número incompleto). Revisá su número completo.',
            default => null,
        };
    }
}
