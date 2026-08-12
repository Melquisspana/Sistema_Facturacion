<?php

namespace App\Services\Rutas;

use App\Models\Dte;
use App\Models\SalidaRutaDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Encuentra la nota de crédito REAL asociada a un documento de una salida.
 *
 * Esto es solo LECTURA de `dtes`. No crea, no modifica y no copia nada: el estado
 * que se muestra en la pantalla de rutas es el que tiene la NC en ese instante. Si
 * mañana la invalidan, la pantalla lo dice sin que nadie tenga que sincronizar
 * nada, precisamente porque no se guardó ninguna copia.
 *
 * Dos vías, en orden de confianza:
 *
 *  1. P002 — `dtes.dte_relacionado_id`. Es el vínculo fiscal explícito que el
 *     propio sistema escribe al emitir la NC ({@see Dte::notasCreditoRelacionadas()}).
 *     No hay nada más seguro que esto y por eso va primero.
 *
 *  2. P001 histórico y CCF sin NC enlazada — la ORDEN DE COMPRA. Es la
 *     compatibilidad mínima que PPQ ya usaba para sugerir el CCF de una NC; acá
 *     se recorre en el sentido inverso. Es más débil que la vía 1 (la OC la pone
 *     Calleja, no Hacienda), así que solo se usa cuando no hay vínculo fiscal.
 *
 * Si hay varias NC para el mismo documento se toma la MÁS RECIENTE y punto: la
 * marca `requiere_nc` de la salida es operativa («había algo que corregir») y no
 * pretende llevar la contabilidad de cuántas correcciones hubo. Eso se consulta
 * en el módulo fiscal, que es donde vive.
 */
class LocalizadorNotaCredito
{
    private const TIPO_NC = '05';

    /**
     * @param  array<int, int|null>  $dteIds
     * @param  array<int, string|null>  $ordenes
     * @return array{0: array<int, Dte>, 1: array<string, Dte>} [porCcfRelacionado, porOrden]
     */
    public function indices(array $dteIds, array $ordenes): array
    {
        $dteIds = array_values(array_unique(array_filter($dteIds)));
        $ordenes = array_values(array_unique(array_filter($ordenes)));

        if ($dteIds === [] && $ordenes === []) {
            return [[], []];
        }

        $notas = Dte::query()
            ->where('tipo_dte', self::TIPO_NC)
            // Una NC archivada (rechazada y sacada de la operación) no corrige nada.
            ->noArchivados()
            ->where(function (Builder $q) use ($dteIds, $ordenes) {
                if ($dteIds !== []) {
                    $q->whereIn('dte_relacionado_id', $dteIds);
                }
                if ($ordenes !== []) {
                    $q->orWhereIn('numero_orden_compra', $ordenes);
                }
            })
            // Ascendente para que el último `foreach` deje la más reciente en el índice.
            ->orderBy('id')
            ->get(['id', 'tipo_dte', 'estado', 'numero_control', 'dte_relacionado_id', 'numero_orden_compra', 'fecha_emision', 'total_pagar']);

        $porCcf = [];
        $porOrden = [];

        foreach ($notas as $nota) {
            if ($nota->dte_relacionado_id) {
                $porCcf[$nota->dte_relacionado_id] = $nota;
            }
            if ($nota->numero_orden_compra) {
                $porOrden[$nota->numero_orden_compra] = $nota;
            }
        }

        return [$porCcf, $porOrden];
    }

    public function paraUno(?int $dteId, ?string $orden): ?Dte
    {
        [$porCcf, $porOrden] = $this->indices([$dteId], [$orden]);

        return $this->elegir($porCcf, $porOrden, $dteId, $orden);
    }

    /**
     * @param  array<int, Dte>  $porCcf
     * @param  array<string, Dte>  $porOrden
     */
    public function elegir(array $porCcf, array $porOrden, ?int $dteId, ?string $orden): ?Dte
    {
        if ($dteId !== null && isset($porCcf[$dteId])) {
            return $porCcf[$dteId];
        }

        return ($orden !== null && $orden !== '') ? ($porOrden[$orden] ?? null) : null;
    }

    /**
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return array{0: array<int, Dte>, 1: array<string, Dte>}
     */
    public function paraDocumentos(Collection $documentos): array
    {
        return $this->indices(
            $documentos->map(fn ($d) => $d->dte_id)->all(),
            $documentos->map(fn ($d) => $d->orden())->all(),
        );
    }
}
