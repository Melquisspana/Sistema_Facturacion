<?php

namespace App\Services\Ppq;

use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Support\OrdenCompra;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda de CCF/NC para el módulo PPQ. Solo CONSULTA documentos ya emitidos
 * (CCF tipo 03 y NC tipo 05); no toca la emisión. Soporta búsqueda por últimos
 * 4 dígitos del número de control, orden de compra, albarán, sala, fecha y monto.
 */
class PpqBusquedaService
{
    /** Tipos de documento cobrables vía PPQ. */
    private const TIPOS = ['03', '05'];

    /**
     * @param  array<string, mixed>  $filtros  q, oc, albaran, sala, fecha_desde, fecha_hasta, monto, tipo
     */
    public function buscar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        $q = Dte::query()
            ->whereIn('tipo_dte', self::TIPOS)
            // Los rechazados ARCHIVADOS están fuera de la operación: no aparecen en la
            // búsqueda rápida de cobro (se consultan por el filtro dedicado o Auditoría).
            ->noArchivados()
            ->with(['cliente:id,nombre,nombre_comercial', 'clienteSucursal:id,nombre,codigo'])
            ->latest('fecha_emision');

        $this->aplicarTexto($q, trim((string) ($filtros['q'] ?? '')));

        if (filled($filtros['oc'] ?? null)) {
            $oc = OrdenCompra::normalizar((string) $filtros['oc']);
            $q->where('numero_orden_compra', 'like', "%{$oc}%");
        }

        if (filled($filtros['albaran'] ?? null)) {
            $ocs = PpqAlbaran::where('numero_albaran', 'like', '%'.$filtros['albaran'].'%')
                ->pluck('numero_orden_compra')->filter()->all();
            $dteIds = PpqAlbaran::where('numero_albaran', 'like', '%'.$filtros['albaran'].'%')
                ->whereNotNull('dte_id')->pluck('dte_id')->all();
            $q->where(function (Builder $sub) use ($ocs, $dteIds) {
                if ($ocs !== []) {
                    $sub->whereIn('numero_orden_compra', $ocs);
                }
                if ($dteIds !== []) {
                    $sub->orWhereIn('id', $dteIds);
                }
                if ($ocs === [] && $dteIds === []) {
                    $sub->whereRaw('1 = 0'); // albarán sin coincidencia -> sin resultados
                }
            });
        }

        if (filled($filtros['sala'] ?? null)) {
            $sala = (string) $filtros['sala'];
            $q->whereHas('clienteSucursal', function (Builder $sub) use ($sala) {
                $sub->where('nombre', 'like', "%{$sala}%")->orWhere('codigo', $sala);
            });
        }

        if (filled($filtros['fecha_desde'] ?? null)) {
            $q->whereDate('fecha_emision', '>=', $filtros['fecha_desde']);
        }
        if (filled($filtros['fecha_hasta'] ?? null)) {
            $q->whereDate('fecha_emision', '<=', $filtros['fecha_hasta']);
        }

        if (filled($filtros['monto'] ?? null)) {
            $q->where('total_pagar', (float) $filtros['monto']);
        }

        if (in_array($filtros['tipo'] ?? null, self::TIPOS, true)) {
            $q->where('tipo_dte', $filtros['tipo']);
        }

        return $q->paginate($porPagina)->withQueryString();
    }

    /**
     * Texto libre: últimos dígitos del número de control, número de control completo,
     * código de generación, sello o número de orden de compra.
     */
    private function aplicarTexto(Builder $q, string $texto): void
    {
        if ($texto === '') {
            return;
        }
        $digitos = preg_replace('/\D/', '', $texto);

        /*
         * Si se escribe un correlativo numérico, primero buscamos una coincidencia
         * fiscal exacta en P002 usando la secuencia final del número de control.
         *
         * Ejemplo: 0006 debe encontrar ...M001P002-000000000000006.
         * Si todavía no existe en P002, se conserva la búsqueda histórica P001.
         */
        if ($digitos !== '') {
            $secuencia = str_pad((string) ((int) $digitos), 15, '0', STR_PAD_LEFT);

            $finalP002 = 'M001P002-'.$secuencia;
            $finalP001 = 'M001P001-'.$secuencia;

            $existeNuevo = Dte::query()
                ->whereIn('tipo_dte', self::TIPOS)
                ->where('numero_control', 'like', "%{$finalP002}")
                ->exists();

            if ($existeNuevo) {
                $q->where('numero_control', 'like', "%{$finalP002}");

                return;
            }

            $q->where('numero_control', 'like', "%{$finalP001}");

            return;
        }

        $q->where(function (Builder $sub) use ($texto, $digitos) {
            $sub->where('numero_control', 'like', "%{$texto}%")
                ->orWhere('codigo_generacion', 'like', "%{$texto}%")
                ->orWhere('sello_recepcion', 'like', "%{$texto}%")
                ->orWhere('numero_orden_compra', 'like', "%{$texto}%");

            if ($digitos !== '') {
                $sub->orWhere('numero_control', 'like', "%{$digitos}");
            }
        });
    }

    /**
     * IDs de DTE ya usados en algún lote PPQ (para avisar duplicados en la búsqueda).
     *
     * @param  array<int, int>  $dteIds
     * @return array<int, int>  dte_id => ppq_lote_id (un lote cualquiera donde ya está)
     */
    public function dtesYaUsados(array $dteIds): array
    {
        if ($dteIds === []) {
            return [];
        }

        return PpqItem::whereIn('dte_id', $dteIds)
            ->pluck('ppq_lote_id', 'dte_id')
            ->all();
    }
}
