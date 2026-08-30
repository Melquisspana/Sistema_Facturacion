<?php

namespace App\Services\Rutas;

use App\Models\PpqAlbaran;
use App\Models\SalidaRutaDocumento;
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
 *      albaranes compiten; NO exime del tipo (ver {@see ResolucionAlbaran}).
 *   2. `ppq_albaranes.numero_orden_compra` — la llave real del día a día: hoy
 *      NINGÚN albarán tiene `dte_id` puesto, y la OC es la que el cliente imprime
 *      tanto en el albarán como en el CCF. Sirve además para los documentos P001
 *      históricos, que no tienen `dte_id` que ofrecer.
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
     * Resuelve en BLOQUE (una sola consulta) y devuelve los dos índices con los que
     * después se decide, en este orden de prioridad, cuál le toca a cada documento.
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

        $albaranes = PpqAlbaran::where(function (Builder $q) use ($dteIds, $ordenes) {
            if ($dteIds !== []) {
                $q->whereIn('dte_id', $dteIds);
            }
            if ($ordenes !== []) {
                $q->orWhereIn('numero_orden_compra', $ordenes);
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
            if ($albaran->dte_id) {
                $agrupadosPorDte[$albaran->dte_id] ??= collect();
                $agrupadosPorDte[$albaran->dte_id]->push($albaran);
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

    /** La resolución de UN documento. Una consulta por llamada: no usar dentro de un bucle. */
    public function paraUno(?int $dteId, ?string $orden): ResolucionAlbaran
    {
        [$porDte, $porOrden] = $this->indices([$dteId], [$orden]);

        return $this->elegir($porDte, $porOrden, $dteId, $orden);
    }

    /**
     * Aplica la prioridad: vínculo explícito primero, orden de compra después.
     *
     * Cuando hay vínculo explícito se devuelve SU resolución, resuelva o no. No se cae a
     * la orden de compra: si alguien vinculó a este documento un albarán que no es de
     * entrega, un AC01 hallado por OC taparía el error y el «entregado» aparecería igual.
     * La excepción es lo que hace que alguien vaya a corregir el vínculo.
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

    /**
     * Índices ya armados a partir de una colección de documentos de salida.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return array{0: array<int, ResolucionAlbaran>, 1: array<string, ResolucionAlbaran>}
     */
    public function paraDocumentos(Collection $documentos): array
    {
        return $this->indices(
            $documentos->map(fn ($d) => $d->dte_id)->all(),
            $documentos->map(fn ($d) => $d->orden())->all(),
        );
    }
}
