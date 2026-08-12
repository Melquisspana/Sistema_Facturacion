<?php

namespace App\Services\Rutas;

use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use Illuminate\Support\Collection;

/**
 * Arma la foto de una salida: sus documentos con todo lo DERIVADO ya resuelto, y
 * los contadores del encabezado.
 *
 * Existe por una razón concreta: entrega, nota de crédito y estado de cobro no son
 * columnas de `salida_ruta_documentos` —a propósito, para no tener dos verdades— y
 * resolverlas documento por documento desde la vista serían cuatro consultas por
 * fila. Acá se resuelven en cuatro consultas en total, y las vistas se limitan a leer.
 *
 * Que todo sea DERIVADO es lo que hace que una salida ya finalizada siga viva: el
 * albarán puede llegar el martes, el lote de PPQ el miércoles y el pago conciliarse
 * la semana siguiente, y la pantalla lo muestra sin que nadie sincronice nada,
 * precisamente porque no hay ninguna copia que actualizar.
 *
 * Los contadores salen de los MISMOS objetos que después se listan. No se cuenta
 * por un lado y se lista por otro: si la tarjeta dice 5 entregados, son
 * exactamente las 5 filas que abajo aparecen entregadas.
 */
class SeguimientoDocumentos
{
    public function __construct(
        private readonly AlbaranLocalizador $albaranes,
        private readonly LocalizadorNotaCredito $notas,
        private readonly LocalizadorPpq $ppq,
    ) {}

    /**
     * Documentos de la salida, ordenados y con albarán/NC/PPQ precargados.
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    public function documentosDe(SalidaRuta $salida): Collection
    {
        $documentos = $salida->documentos()
            ->with([
                'dte:id,tipo_dte,estado,numero_control,numero_orden_compra,fecha_emision,total_pagar,cliente_id,cliente_sucursal_id',
                'dte.cliente:id,nombre',
                'dte.clienteSucursal:id,nombre,codigo',
                'clienteSucursal:id,nombre,codigo',
                'documentacionRecibidaPor:id,name',
            ])
            ->get()
            // Por número de control: es como la gente busca un documento concreto en
            // la lista («el 986»). Ordenar por estado de entrega movería las filas de
            // sitio cada vez que llega un albarán, y se perdería el papel a media
            // revisión.
            ->sortBy(fn (SalidaRutaDocumento $d) => $d->numeroLegible())
            ->values();

        return $this->hidratar($documentos);
    }

    /**
     * Precarga los derivados sobre una colección ya cargada.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return Collection<int, SalidaRutaDocumento>
     */
    public function hidratar(Collection $documentos): Collection
    {
        if ($documentos->isEmpty()) {
            return $documentos;
        }

        [$albPorDte, $albPorOrden] = $this->albaranes->paraDocumentos($documentos);
        [$ncPorCcf, $ncPorOrden] = $this->notas->paraDocumentos($documentos);
        [$ppqPorDte, $ppqPorControl] = $this->ppq->paraDocumentos($documentos);

        foreach ($documentos as $doc) {
            $doc->precargarAlbaran($this->albaranes->elegir($albPorDte, $albPorOrden, $doc->dte_id, $doc->orden()));
            $doc->precargarNotaCredito($this->notas->elegir($ncPorCcf, $ncPorOrden, $doc->dte_id, $doc->orden()));
            $doc->precargarPpq($this->ppq->elegir($ppqPorDte, $ppqPorControl, $doc->dte_id, $doc->numeroLegible()));
        }

        // Segunda vuelta para el PPQ de las NC: recién ahora se sabe qué NC tiene
        // cada documento, y son otros números de control que los de arriba.
        $notas = $documentos->map(fn (SalidaRutaDocumento $d) => $d->notaCredito())->filter();

        if ($notas->isNotEmpty()) {
            [$ncPpqPorDte, $ncPpqPorControl] = $this->ppq->indices(
                $notas->map(fn ($n) => $n->id)->all(),
                $notas->map(fn ($n) => $n->numero_control)->all(),
            );

            foreach ($documentos as $doc) {
                $nc = $doc->notaCredito();
                $doc->precargarPpqNotaCredito(
                    $nc === null ? null : $this->ppq->elegir($ncPpqPorDte, $ncPpqPorControl, $nc->id, $nc->numero_control),
                );
            }
        } else {
            foreach ($documentos as $doc) {
                $doc->precargarPpqNotaCredito(null);
            }
        }

        return $documentos;
    }

    /**
     * Contadores del encabezado de la salida.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos  ya hidratados
     * @return array<string, int>
     */
    public function resumen(Collection $documentos): array
    {
        $entregados = $documentos->filter(fn (SalidaRutaDocumento $d) => $d->entregado())->count();
        $enPpq = $documentos->filter(fn (SalidaRutaDocumento $d) => $d->enPpq())->count();

        return [
            'total' => $documentos->count(),
            'entregados' => $entregados,
            'sin_albaran' => $documentos->count() - $entregados,
            'documentacion_fisica' => $documentos->filter(fn (SalidaRutaDocumento $d) => $d->documentacionFisicaRecibida())->count(),
            'requieren_nc' => $documentos->filter(fn (SalidaRutaDocumento $d) => $d->requiere_nc)->count(),
            'nc_reales' => $documentos->filter(fn (SalidaRutaDocumento $d) => $d->notaCredito() !== null)->count(),
            'en_ppq' => $enPpq,
            'sin_ppq' => $documentos->count() - $enPpq,
            'pagados' => $documentos->filter(fn (SalidaRutaDocumento $d) => $d->pagado())->count(),
        ];
    }
}
