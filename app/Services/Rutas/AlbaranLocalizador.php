<?php

namespace App\Services\Rutas;

use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Support\Albaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Encuentra el albarán de ENTREGA que corresponde a un documento, o explica por qué no
 * hay uno inequívoco.
 *
 * Son EXACTAMENTE las dos llaves que el módulo PPQ ya venía usando para lo mismo,
 * extraídas acá para que exista un solo lugar donde esté escrita la regla (PPQ delega en
 * esta clase):
 *
 *   1. `ppq_albaranes.dte_id`             — vínculo explícito, el más fuerte. Decide QUÉ
 *      albaranes compiten; NO exime del tipo (ver {@see ResolucionAlbaran}). Los de
 *      crédito ({@see Albaran::TIPOS_DE_CREDITO}) no compiten por esta vía: su `dte_id`
 *      dice qué CCF acreditan, no que se haya entregado.
 *      `cobro_documentos.ppq_albaran_id` (el vínculo que pone Seguimiento) y el renglón
 *      del CCF en un PPQ vigente (`ppq_items`) valen lo mismo y entran al mismo grupo:
 *      apenas cae el albarán de entrega, por el camino que sea, el CCF está entregado.
 *   2. `ppq_albaranes.numero_orden_compra` — la llave real del día a día: la OC es la
 *      que el cliente imprime tanto en el albarán como en el CCF. Sirve además para los
 *      documentos P001 históricos, que no tienen `dte_id` que ofrecer.
 *
 * ═══════════════ El tipo de albarán: lo que faltaba para que la OC sirviera ═══════════════
 *
 * La OC por sí sola NO alcanza, y durante un tiempo se usó como si alcanzara. Una misma
 * orden de compra ampara el albarán de ENTREGA (AC01) y también el de CRÉDITO que se
 * emite después si hubo avería o devolución (AC02, AC04). El código se quedaba con «el
 * primero» —sin orden definido y sin mirar el tipo—, así que un documento podía quedar
 * marcado como ENTREGADO por un albarán de abono, y tomar de él el monto contra el que se
 * calcula la diferencia con el CCF.
 *
 * Ahora la regla es explícita y no admite atajos:
 *
 *   · solo compiten los albaranes que declaran ser de ENTREGA ({@see PpqAlbaran::esDeEntrega()});
 *   · tiene que haber EXACTAMENTE UNO. Con dos, no se elige: se devuelve la excepción.
 *   · un albarán cuyo número no permite determinar el tipo NO se supone de entrega.
 *
 * Y el monto y la sala no participan de esta decisión. Sirven como VALIDACIÓN después
 * —para avisar de que algo no cuadra— pero jamás como identidad: dos pedidos de la misma
 * sala en la misma semana se parecen lo suficiente como para emparejar cualquiera con
 * cualquiera.
 *
 * Lo que deliberadamente NO se usa como llave: `cliente_sucursal_id`. Una sala tiene
 * decenas de documentos y decenas de albaranes en el mismo mes; casar por sala pintaría
 * «Entregado» sobre documentos que nadie entregó. Como llave de identidad no sirve, y una
 * entrega falsa es justo el error que este módulo no puede permitirse.
 *
 * Los albaranes dados de baja no cuentan: el scope de SoftDeletes los deja fuera, y un
 * albarán anulado no prueba ninguna entrega.
 */
class AlbaranLocalizador
{
    /**
     * Resuelve en BLOQUE (dos consultas para todos: la de los otros vínculos y la de
     * albaranes) y devuelve los dos índices con los que después se decide, en este orden
     * de prioridad, cuál le toca a cada documento.
     *
     * El índice por orden de compra guarda una {@see ResolucionAlbaran} y no un albarán
     * suelto: para una misma OC puede haber uno, ninguno o varios, y esa diferencia es
     * justo lo que hay que conservar.
     *
     * @param  array<int, int|null>  $dteIds
     * @param  array<int, string|null>  $ordenes
     * @return array{0: array<int, ResolucionAlbaran>, 1: array<string, ResolucionAlbaran>} [porDte, porOrden]
     */
    public function indices(array $dteIds, array $ordenes): array
    {
        $dteIds = array_values(array_unique(array_filter($dteIds)));
        $ordenes = array_values(array_unique(array_filter($ordenes)));

        if ($dteIds === [] && $ordenes === []) {
            return [[], []];
        }

        // Los otros dos vínculos con que el sistema une un albarán a su CCF son tan explícitos
        // como `ppq_albaranes.dte_id`, y un AC01 que llegó sin orden de compra solo queda
        // unido por ellos: el de Seguimiento (`cobro_documentos.ppq_albaran_id`) y el renglón
        // del CCF en un PPQ vigente (`ppq_items`). Una consulta para todos los documentos.
        /** @var array<int, array<int, int>> $deOtrosVinculos ppq_albaran_id => [dte_id, …] */
        $deOtrosVinculos = $dteIds === [] ? [] : CobroDocumento::query()->toBase()
            ->whereIn('dte_id', $dteIds)
            ->whereNotNull('ppq_albaran_id')
            ->select(['dte_id', 'ppq_albaran_id'])
            ->union(PpqItem::query()
                ->whereHas('lote')
                ->where('tipo_dte', '03')
                ->where('sin_albaran', false)
                ->whereIn('dte_id', $dteIds)
                ->whereNotNull('ppq_albaran_id')
                ->toBase()
                ->select(['dte_id', 'ppq_albaran_id']))
            ->get()
            ->groupBy('ppq_albaran_id')
            ->map(fn (Collection $vinculos) => $vinculos->pluck('dte_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->all();

        $albaranes = PpqAlbaran::where(function (Builder $q) use ($dteIds, $ordenes, $deOtrosVinculos) {
            if ($dteIds !== []) {
                $q->whereIn('dte_id', $dteIds);
            }
            if ($ordenes !== []) {
                $q->orWhereIn('numero_orden_compra', $ordenes);
            }
            if ($deOtrosVinculos !== []) {
                $q->orWhereIn('id', array_keys($deOtrosVinculos));
            }
        })
            // Orden estable: sin él, «cuál vino primero» dependía del plan de la consulta
            // y podía cambiar entre dos cargas de la misma pantalla.
            ->orderBy('id')
            ->get();

        /** @var array<int, Collection<int, PpqAlbaran>> $agrupadosPorDte */
        $agrupadosPorDte = [];
        /** @var array<string, Collection<int, PpqAlbaran>> $agrupadosPorOrden */
        $agrupadosPorOrden = [];

        foreach ($albaranes as $albaran) {
            // Los DOS índices se AGRUPAN y ninguno se queda con «el último que pasó». Un
            // documento puede tener más de un albarán vinculado por `dte_id`, igual que
            // una orden de compra puede tener varios: en los dos casos la decisión la toma
            // la misma regla, que exige un candidato de entrega único.
            //
            // Un albarán de CRÉDITO no entra al índice por `dte_id`: su vínculo con el CCF es
            // legítimo (la devolución se vincula a propósito al CCF que acredita, ver
            // {@see \App\Services\Cobros\DevolucionesAlbaran}) pero no dice nada de la
            // entrega. Si entrara, taparía al AC01 de la orden de compra y el CCF quedaría
            // «por entregar» para siempre. Por la orden de compra sí se agrupa: ahí no tapa a
            // nadie, porque {@see ResolucionAlbaran::decidir()} solo elige albaranes de entrega.
            //
            // Los vínculos de Seguimiento y del PPQ entran al mismo grupo y pasan por la
            // misma regla; si varios señalan el mismo albarán, cuenta una sola vez.
            $vinculados = array_unique(array_filter([$albaran->dte_id, ...($deOtrosVinculos[$albaran->id] ?? [])]));
            if (! Albaran::esDeCredito($albaran->tipo_codigo)) {
                foreach ($vinculados as $dteId) {
                    $agrupadosPorDte[$dteId] ??= collect();
                    $agrupadosPorDte[$dteId]->push($albaran);
                }
            }

            if ($albaran->numero_orden_compra) {
                $agrupadosPorOrden[$albaran->numero_orden_compra] ??= collect();
                $agrupadosPorOrden[$albaran->numero_orden_compra]->push($albaran);
            }
        }

        $porDte = [];
        foreach ($agrupadosPorDte as $dteId => $delDte) {
            $porDte[$dteId] = ResolucionAlbaran::decidir($delDte, porVinculoExplicito: true);
        }

        $porOrden = [];
        foreach ($agrupadosPorOrden as $orden => $delaOrden) {
            $porOrden[$orden] = ResolucionAlbaran::decidir($delaOrden);
        }

        return [$porDte, $porOrden];
    }

    /** La resolución de UN documento. Dos consultas por llamada: no usar dentro de un bucle. */
    public function paraUno(?int $dteId, ?string $orden): ResolucionAlbaran
    {
        [$porDte, $porOrden] = $this->indices([$dteId], [$orden]);

        return $this->elegir($porDte, $porOrden, $dteId, $orden);
    }

    /**
     * Aplica la prioridad: vínculo explícito primero, orden de compra después.
     *
     * Cuando hay vínculo explícito se devuelve SU resolución, resuelva o no. No se cae a
     * la orden de compra: si alguien vinculó a este documento un albarán de tipo
     * desconocido, un AC01 hallado por OC taparía la duda y el «entregado» aparecería
     * igual. La excepción es lo que hace que alguien vaya a corregir el vínculo. Los de
     * crédito no llegan a este índice ({@see indices()}): vincularlos al CCF es correcto.
     *
     * @param  array<int, ResolucionAlbaran>  $porDte
     * @param  array<string, ResolucionAlbaran>  $porOrden
     */
    public function elegir(array $porDte, array $porOrden, ?int $dteId, ?string $orden): ResolucionAlbaran
    {
        if ($dteId !== null && isset($porDte[$dteId])) {
            return $porDte[$dteId];
        }

        if ($orden === null || $orden === '') {
            return ResolucionAlbaran::vacia();
        }

        return $porOrden[$orden] ?? ResolucionAlbaran::vacia();
    }
}
