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
     * Ancho máximo que se le supone a la secuencia final del número de control.
     * La norma son 15 dígitos, pero hay documentos históricos con 16, así que el
     * correlativo se busca probando anchos en vez de dar uno por sentado.
     */
    private const ANCHO_MAX_SECUENCIA = 20;

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

        // El texto va AL FINAL para que la comprobación de «¿existe ya en P002?»
        // se haga sobre la misma búsqueda que el usuario está viendo —mismo tipo,
        // mismas fechas, sin archivados— y no sobre la tabla entera. Si se
        // resolviera antes, un documento P002 de otro tipo o ya archivado podría
        // esconder el P001 que sí correspondía mostrar.
        $this->aplicarTexto($q, trim((string) ($filtros['q'] ?? '')));

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

        // Un término SOLO de dígitos es un correlativo: es el caso de «escribo los
        // últimos 4». Cualquier otra cosa —número de control completo, código de
        // generación, sello, orden de compra con letras— es una referencia
        // literal y se busca como subcadena, que es como se buscó siempre.
        if (ctype_digit($texto)) {
            $this->aplicarCorrelativo($q, $texto);

            return;
        }

        $q->where(function (Builder $sub) use ($texto) {
            $sub->where('numero_control', 'like', "%{$texto}%")
                ->orWhere('codigo_generacion', 'like', "%{$texto}%")
                ->orWhere('sello_recepcion', 'like', "%{$texto}%")
                ->orWhere('numero_orden_compra', 'like', "%{$texto}%");
        });
    }

    /**
     * Correlativo numérico. Contra el número de control se exige coincidencia
     * EXACTA —no subcadena—, para que escribir `0340` no arrastre el `0003401`
     * de otro documento. Contra el resto de campos se mantiene la subcadena,
     * porque una orden de compra o un sello también pueden ser solo dígitos.
     *
     * Si ese mismo correlativo ya existe en P002, se muestra ese y no el
     * histórico de P001: tras el cambio de punto de venta ambos comparten
     * numeración y el vigente es el nuevo.
     */
    private function aplicarCorrelativo(Builder $q, string $digitos): void
    {
        $hayEnP002 = (clone $q)
            ->where('numero_control', 'like', '%P002-%')
            ->where(fn (Builder $sub) => $this->correlativoExacto($sub, $digitos))
            ->exists();

        $q->where(function (Builder $sub) use ($digitos, $hayEnP002) {
            $sub->where(function (Builder $control) use ($digitos, $hayEnP002) {
                // El grupo va anidado: sin él, el `AND P002` se pegaría solo al
                // último patrón del `OR` en vez de a todos.
                $control->where(fn (Builder $c) => $this->correlativoExacto($c, $digitos));

                if ($hayEnP002) {
                    $control->where('numero_control', 'like', '%P002-%');
                }
            })
                ->orWhere('codigo_generacion', 'like', "%{$digitos}%")
                ->orWhere('sello_recepcion', 'like', "%{$digitos}%")
                ->orWhere('numero_orden_compra', 'like', "%{$digitos}%");
        });
    }

    /**
     * El número de control TERMINA en ese correlativo, con su relleno de ceros
     * completo hasta el separador.
     *
     * Se prueba un patrón por ancho posible en vez de fijar 15 dígitos: el ancho
     * real varía entre documentos y darlo por sentado fue lo que dejó de
     * encontrar los de 16. Anclar en el guion es lo que da la exactitud —entre el
     * separador y el final solo puede haber ceros y el correlativo—, de modo que
     * `986` no casa con `...100986`.
     */
    private function correlativoExacto(Builder $q, string $digitos): void
    {
        // `0986` y `986` son el mismo correlativo: el relleno lo pone el patrón.
        $valor = ltrim($digitos, '0');

        if ($valor === '') {
            $valor = '0';
        }

        // `max()` evita que un término más largo que el ancho máximo genere un
        // rango descendente: en ese caso solo cabe su propio ancho.
        foreach (range(strlen($valor), max(strlen($valor), self::ANCHO_MAX_SECUENCIA)) as $i => $ancho) {
            $patron = '%-'.str_pad($valor, $ancho, '0', STR_PAD_LEFT);

            $i === 0
                ? $q->where('numero_control', 'like', $patron)
                : $q->orWhere('numero_control', 'like', $patron);
        }
    }

    /**
     * IDs de DTE ya usados en algún lote PPQ (para avisar duplicados en la búsqueda).
     *
     * @param  array<int, int>  $dteIds
     * @return array<int, int> dte_id => ppq_lote_id (un lote cualquiera donde ya está)
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
