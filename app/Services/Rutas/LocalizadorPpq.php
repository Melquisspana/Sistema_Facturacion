<?php

namespace App\Services\Rutas;

use App\Models\PpqItem;
use App\Models\SalidaRutaDocumento;
use App\Services\Ppq\ConciliacionTxtParser;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Encuentra el renglón de PPQ (cobro) que corresponde a un documento de una salida.
 *
 * Es SOLO LECTURA de `ppq_items`. No escribe, no copia y no marca nada: el estado
 * de cobro que se ve en Rutas es el que PPQ tiene en ese instante. Por eso una
 * salida ya finalizada sigue mostrando el pago que llegó después — no hay ningún
 * snapshot que se quede viejo porque no se guarda ninguno.
 *
 * ─────────────────────────── Las dos llaves (y solo dos) ───────────────────────────
 *
 *  1. `ppq_items.dte_id` — vínculo explícito. Va primero por ser el más fuerte.
 *     Aviso para quien lea esto en el futuro: HOY esta llave no encuentra nada.
 *     Los 158 items existentes vienen del barrido de Gmail (`origen = 'gmail'`) y
 *     todos tienen `dte_id` NULL. Se deja igual porque el día que PPQ empiece a
 *     poblarla debe ganarle al número de control, no porque hoy sirva.
 *
 *  2. `numero_control` NORMALIZADO — la llave que hace el trabajo real. Se compara
 *     con {@see ConciliacionTxtParser::normalizarNumero()}, que es EXACTAMENTE la
 *     misma función con la que PPQ cruza sus items contra el TXT de Calleja. No se
 *     inventa una normalización propia: si algún día cambia, cambia en un solo sitio.
 *     Funciona igual para P002 y para los P001 históricos, que es justo lo que hacía
 *     falta: el número de control es lo único que ambos caminos comparten.
 *
 * ──────────────────── Lo que deliberadamente NO se usa como llave ────────────────────
 *
 * La ORDEN DE COMPRA. Y conviene explicar por qué, porque para el albarán sí se usa
 * ({@see AlbaranLocalizador}) y la asimetría podría parecer un olvido:
 *
 *   · en `ppq_albaranes` la OC identifica al albarán, que es lo que se busca;
 *   · en `ppq_items` la OC es un SNAPSHOT para armar el Excel de Calleja, y una
 *     misma OC ampara varios CCF de la misma sala.
 *
 * Casar por OC emparejaría un documento con el renglón de otro y podría pintar
 * «Pagado» sobre algo que nadie pagó. PPQ nunca identificó un item por OC, así que
 * usarla acá sería una heurística NUEVA, no una regla existente. Tampoco se casa por
 * monto ni por sala, por lo mismo.
 *
 * ─────────────────────── Cuando el documento está en varios lotes ───────────────────────
 *
 * El índice de PPQ es `(ppq_lote_id, numero_control)`: el mismo CCF puede figurar en
 * más de un lote. Por eso lo que se devuelve no es un item suelto sino un
 * {@see RenglonPpq}, que guarda por separado el renglón que sostiene la PRESENTACIÓN
 * actual y el que prueba la CONCILIACIÓN. Las reglas de desempate viven ahí.
 *
 * ──────────────────────────── Lotes retirados (borrado lógico) ────────────────────────────
 *
 * `ppq_lotes` se borra de forma lógica y sus items no: el `cascadeOnDelete` de la clave
 * foránea no se dispara con un soft delete, así que quedan renglones vivos colgando de un
 * lote que ya nadie ve —ni siquiera PPQ, donde solo se llega a un item a través de su lote—.
 *
 * Esos renglones se SIGUEN LEYENDO, y a propósito. Antes se descartaban de plano, y eso
 * mezclaba dos hechos que no son el mismo: retirar un lote apaga la presentación, pero no
 * deshace un pago que ya entró. Quién decide qué hace cada renglón es {@see RenglonPpq};
 * acá solo se le informa si su lote sigue vivo.
 *
 * Lo único que se descarta es el item cuyo lote NO EXISTE en absoluto: sin lote no hay ni
 * presentación ni prueba de cobro, solo una fila huérfana.
 */
class LocalizadorPpq
{
    /**
     * Separadores que se limpian en SQL para comparar números de control.
     *
     * `normalizarNumero()` quita en PHP todo lo que no sea alfanumérico; en SQL hay
     * que enumerar, y estos son los separadores que un número de control puede
     * llevar de verdad: los que escribe el sistema (`-`) y los que puede teclear una
     * persona al cargar un histórico P001 a mano. La comparación se hace además
     * sobre el valor ya normalizado en PHP, así que un carácter exótico solo haría
     * que ese documento no se encuentre —nunca que se encuentre el equivocado—.
     */
    private const SEPARADORES = ['-', ' ', '.', '/', '_'];

    /**
     * Resuelve en BLOQUE y devuelve los dos índices con los que después se elige.
     *
     * La comparación por número se hace NORMALIZANDO LOS DOS LADOS dentro de la
     * consulta. Es a propósito y hace falta: comparar el valor crudo solo acierta
     * cuando ambos lados están escritos igual, y el hueco no es teórico —el mismo
     * documento aparece como `DTE-03-…-1090` en PPQ y puede venir tecleado como
     * `DTE03…1090` desde el alta manual de un P001, o al revés—. Normalizar de un
     * solo lado arregla una dirección y deja la otra rota.
     *
     * Que esto impida usar un índice no cambia nada en la práctica: `numero_control`
     * solo existe como SEGUNDA columna de `(ppq_lote_id, numero_control)`, así que una
     * búsqueda por número suelto ya recorría la tabla entera de todos modos.
     *
     * @param  array<int, int|null>  $dteIds
     * @param  array<int, string|null>  $controles
     * @return array{0: array<int, RenglonPpq>, 1: array<string, RenglonPpq>} [porDte, porControl]
     */
    public function indices(array $dteIds, array $controles): array
    {
        $dteIds = array_values(array_unique(array_filter($dteIds)));

        $buscables = [];
        foreach ($controles as $control) {
            $normalizado = ConciliacionTxtParser::normalizarNumero($control);
            if ($normalizado !== null) {
                $buscables[$normalizado] = true;
            }
        }
        $buscables = array_keys($buscables);

        if ($dteIds === [] && $buscables === []) {
            return [[], []];
        }

        $items = PpqItem::query()
            // El lote tiene que EXISTIR, aunque esté retirado. `withTrashed()` es lo que
            // deja entrar a los lotes borrados lógicamente —sin él, un pago ya cobrado
            // desaparecía junto con su lote y el documento volvía a figurar como deuda—
            // y a la vez sigue dejando fuera al item huérfano, cuyo lote no existe ni
            // borrado.
            ->whereHas('lote', fn (Builder $q) => $q->withTrashed())
            // `deleted_at` va en el select a propósito: sin esa columna `trashed()`
            // respondería que no a todo y todos los lotes parecerían vivos.
            ->with(['lote' => fn ($q) => $q->withTrashed()->select('id', 'referencia', 'estado', 'fecha', 'deleted_at')])
            ->where(function (Builder $q) use ($dteIds, $buscables) {
                if ($dteIds !== []) {
                    $q->whereIn('dte_id', $dteIds);
                }
                if ($buscables !== []) {
                    $q->orWhereIn($this->columnaNormalizada(), $buscables);
                }
            })
            ->orderBy('id')
            ->get();

        $porDte = [];
        $porControl = [];

        foreach ($items as $item) {
            $vigente = $this->enLoteVigente($item);

            if ($item->dte_id !== null) {
                $porDte[$item->dte_id] = ($porDte[$item->dte_id] ?? RenglonPpq::vacio())->con($item, $vigente);
            }

            $clave = $item->numeroNormalizado();
            if ($clave !== null) {
                $porControl[$clave] = ($porControl[$clave] ?? RenglonPpq::vacio())->con($item, $vigente);
            }
        }

        return [$porDte, $porControl];
    }

    /** El renglón de UN documento. Una consulta por llamada: no usar dentro de un bucle. */
    public function paraUno(?int $dteId, ?string $control): ?RenglonPpq
    {
        [$porDte, $porControl] = $this->indices([$dteId], [$control]);

        return $this->elegir($porDte, $porControl, $dteId, $control);
    }

    /**
     * Aplica la prioridad: vínculo explícito primero, número de control después.
     *
     * @param  array<int, RenglonPpq>  $porDte
     * @param  array<string, RenglonPpq>  $porControl
     */
    public function elegir(array $porDte, array $porControl, ?int $dteId, ?string $control): ?RenglonPpq
    {
        if ($dteId !== null && isset($porDte[$dteId])) {
            return $porDte[$dteId];
        }

        $clave = ConciliacionTxtParser::normalizarNumero($control);

        return $clave === null ? null : ($porControl[$clave] ?? null);
    }

    /**
     * Índices ya armados a partir de una colección de documentos de salida.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return array{0: array<int, RenglonPpq>, 1: array<string, RenglonPpq>}
     */
    public function paraDocumentos(Collection $documentos): array
    {
        return $this->indices(
            $documentos->map(fn ($d) => $d->dte_id)->all(),
            $documentos->map(fn ($d) => $d->numeroLegible())->all(),
        );
    }

    /**
     * `numero_control` sin separadores y en mayúsculas, como expresión SQL. Se arma
     * anidando REPLACE porque es lo único que MySQL y SQLite entienden igual (los
     * tests corren en SQLite y la operación en MySQL).
     */
    private function columnaNormalizada(): Expression
    {
        $expresion = 'numero_control';

        foreach (self::SEPARADORES as $separador) {
            $expresion = "REPLACE({$expresion}, '{$separador}', '')";
        }

        return DB::raw("UPPER({$expresion})");
    }

    /**
     * ¿El lote de este item sigue vivo? Un lote retirado (borrado lógico) responde que
     * no, y con eso {@see RenglonPpq} apaga la presentación sin tocar la conciliación.
     */
    private function enLoteVigente(PpqItem $item): bool
    {
        return $item->lote !== null && ! $item->lote->trashed();
    }
}
