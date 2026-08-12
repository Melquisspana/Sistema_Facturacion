<?php

namespace App\Services\Rutas;

use App\Models\PpqAlbaran;
use App\Models\SalidaRutaDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Encuentra el albarán de Calleja que corresponde a un documento.
 *
 * NO inventa un matching nuevo: son EXACTAMENTE las dos llaves que el módulo PPQ
 * ya venía usando para lo mismo, extraídas acá para que exista un solo lugar
 * donde esté escrita la regla (PPQ delega en esta clase).
 *
 *   1. `ppq_albaranes.dte_id`             — vínculo explícito, el más fuerte.
 *   2. `ppq_albaranes.numero_orden_compra` — la llave real del día a día: hoy
 *      NINGÚN albarán tiene `dte_id` puesto, y la OC es la que Calleja imprime
 *      tanto en el albarán como en el CCF. Sirve además para los documentos P001
 *      históricos, que no tienen `dte_id` que ofrecer.
 *
 * Lo que deliberadamente NO se usa como llave: `cliente_sucursal_id`. Una sala
 * tiene decenas de documentos y decenas de albaranes en el mismo mes; casar por
 * sala emparejaría cualquiera con cualquiera y pintaría «Entregado» sobre
 * documentos que nadie entregó. Como llave de identidad no sirve, y una entrega
 * falsa es justo el error que este módulo no puede permitirse.
 *
 * Los albaranes dados de baja no cuentan: el scope de SoftDeletes los deja fuera,
 * y un albarán anulado no prueba ninguna entrega.
 */
class AlbaranLocalizador
{
    /**
     * Resuelve en BLOQUE (una sola consulta) y devuelve los dos índices con los que
     * después se decide, en este orden de prioridad, cuál le toca a cada documento.
     *
     * @param  array<int, int|null>  $dteIds
     * @param  array<int, string|null>  $ordenes
     * @return array{0: array<int, PpqAlbaran>, 1: array<string, PpqAlbaran>} [porDte, porOrden]
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
        })->get();

        $porDte = [];
        $porOrden = [];

        foreach ($albaranes as $albaran) {
            if ($albaran->dte_id) {
                $porDte[$albaran->dte_id] = $albaran;
            }
            // El primero gana: si una OC tuviera varios albaranes, elegir uno u otro
            // por monto sería adivinar. Para lo único que se usa —«¿se entregó?»—
            // cualquiera de ellos responde que sí.
            if ($albaran->numero_orden_compra && ! isset($porOrden[$albaran->numero_orden_compra])) {
                $porOrden[$albaran->numero_orden_compra] = $albaran;
            }
        }

        return [$porDte, $porOrden];
    }

    /** El albarán de UN documento. Una consulta por llamada: no usar dentro de un bucle. */
    public function paraUno(?int $dteId, ?string $orden): ?PpqAlbaran
    {
        [$porDte, $porOrden] = $this->indices([$dteId], [$orden]);

        return $this->elegir($porDte, $porOrden, $dteId, $orden);
    }

    /**
     * Aplica la prioridad: vínculo explícito primero, orden de compra después.
     *
     * @param  array<int, PpqAlbaran>  $porDte
     * @param  array<string, PpqAlbaran>  $porOrden
     */
    public function elegir(array $porDte, array $porOrden, ?int $dteId, ?string $orden): ?PpqAlbaran
    {
        if ($dteId !== null && isset($porDte[$dteId])) {
            return $porDte[$dteId];
        }

        return ($orden !== null && $orden !== '') ? ($porOrden[$orden] ?? null) : null;
    }

    /**
     * Índices ya armados a partir de una colección de documentos de salida.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return array{0: array<int, PpqAlbaran>, 1: array<string, PpqAlbaran>}
     */
    public function paraDocumentos(Collection $documentos): array
    {
        return $this->indices(
            $documentos->map(fn ($d) => $d->dte_id)->all(),
            $documentos->map(fn ($d) => $d->orden())->all(),
        );
    }
}
