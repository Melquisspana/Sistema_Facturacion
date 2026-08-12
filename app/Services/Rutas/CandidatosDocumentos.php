<?php

namespace App\Services\Rutas;

use App\Models\Dte;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * CCF que PODRÍAN pertenecer a una salida. Es una propuesta para que una persona
 * mire y elija: nada de lo que sale de acá se agrega solo.
 *
 * Los cinco filtros, y por qué cada uno:
 *
 *  - tipo 03 y no archivado — es lo que se cobra; un rechazado archivado está fuera
 *    de la operación.
 *  - con `cliente_sucursal_id` — sin sala no hay forma de saber si es de esta ruta.
 *  - la sala pertenece a la ruta de la salida — es lo que hace «candidato» a un
 *    documento y no a los otros mil.
 *  - sin dueño — un documento que ya está en otra salida abierta no se ofrece; si
 *    de verdad va en esta, se MUEVE explícitamente desde donde está.
 *  - dentro de la ventana de fechas — un CCF de hace tres meses casi nunca es de la
 *    salida que se arma hoy, y ofrecerlo entierra los que sí lo son.
 *
 * La ventana se calcula alrededor del PERÍODO DE LA SALIDA, no alrededor de hoy:
 * armar una salida de la semana pasada tiene que proponer los documentos de esa
 * semana. Los márgenes son configurables (`config/rutas.php`) porque el documento
 * puede emitirse un día antes de cargar el camión o un día después de la entrega.
 *
 * NO filtra por serie: acá decide una persona, y si quiere ver un CCF de la serie
 * vieja emitido por este sistema, puede. La restricción a P002 aplica solo a lo que
 * el sistema hace SOLO ({@see AsignadorAutomaticoDocumentos}).
 */
class CandidatosDocumentos
{
    private const POR_PAGINA = 20;

    /**
     * @param  array<string, mixed>  $filtros  q (control/OC), sucursal_id
     */
    public function paraSalida(SalidaRuta $salida, array $filtros = []): LengthAwarePaginator
    {
        [$desde, $hasta] = $this->ventana($salida);

        $sucursalesDeLaRuta = $salida->ruta->sucursales()->pluck('id');

        return Dte::query()
            ->where('tipo_dte', '03')
            ->noArchivados()
            ->whereIn('cliente_sucursal_id', $sucursalesDeLaRuta)
            ->whereDate('fecha_emision', '>=', $desde)
            ->whereDate('fecha_emision', '<=', $hasta)
            // Sin dueño: se compara por número de control, que es la identidad que
            // comparten los dos caminos (P002 y P001).
            ->whereNotIn('numero_control', SalidaRutaDocumento::vigentes()->select('numero_control'))
            ->when(filled($filtros['q'] ?? null), function ($q) use ($filtros) {
                $texto = '%'.trim((string) $filtros['q']).'%';
                $q->where(fn ($w) => $w->where('numero_control', 'like', $texto)->orWhere('numero_orden_compra', 'like', $texto));
            })
            ->when(filled($filtros['sucursal_id'] ?? null), fn ($q) => $q->where('cliente_sucursal_id', (int) $filtros['sucursal_id']))
            ->with(['cliente:id,nombre', 'clienteSucursal:id,nombre,codigo'])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Ventana de fechas de la salida, ensanchada por los márgenes de configuración.
     *
     * @return array{0: string, 1: string}
     */
    public function ventana(SalidaRuta $salida): array
    {
        $inicio = $salida->fecha_inicio instanceof Carbon ? $salida->fecha_inicio->copy() : Carbon::parse($salida->fecha_inicio);
        $fin = $salida->fecha_fin_real ?? $salida->fecha_fin_estimada ?? $inicio;
        $fin = $fin instanceof Carbon ? $fin->copy() : Carbon::parse($fin);

        return [
            $inicio->subDays((int) config('rutas.candidatos_dias_antes'))->toDateString(),
            $fin->addDays((int) config('rutas.candidatos_dias_despues'))->toDateString(),
        ];
    }
}
