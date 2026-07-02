<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteJsonException;
use App\Exceptions\Dte\DteJsonInvalidoException;
use App\Exceptions\Dte\DteNoMapeableException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\User;
use App\Support\Dinero;
use App\Support\Dte\OrdenProductosOc;
use Illuminate\Support\Facades\DB;

/**
 * Pasa un DTE de BORRADOR a GENERADO: valida el documento, consume el correlativo
 * interno de forma transaccional, asigna un número INTERNO/provisional, cambia el
 * estado con la máquina de estados y construye el JSON oficial preliminar (número de
 * control + código de generación + archivo en storage), todo de forma ATÓMICA.
 *
 * Alcance: NO firma, NO transmite a Hacienda, NO guarda sello. Si el JSON oficial no
 * pasa la validación del schema, se revierte TODA la generación (el documento queda
 * en borrador y el correlativo no se consume) y se reporta el error.
 */
class DteGeneracionService
{
    public function __construct(
        private readonly DteStateMachine $maquina,
        private readonly DteJsonService $json,
    ) {}

    /**
     * @throws GeneracionException
     */
    public function generar(Dte $dte, ?User $usuario = null): Dte
    {
        $this->validar($dte);

        return DB::transaction(function () use ($dte, $usuario) {
            // Bloquea la fila del correlativo para evitar números duplicados.
            $correlativo = Correlativo::whereKey($this->resolverCorrelativo($dte)->id)
                ->lockForUpdate()
                ->first();

            $numero = $correlativo->ultimo_numero + 1;
            $correlativo->ultimo_numero = $numero;
            $correlativo->save();

            // Aún en borrador: el observer permite estos cambios de cabecera.
            $dte->correlativo_id = $correlativo->id;
            $dte->numero_interno = $this->formatearNumeroInterno($dte, $numero);
            $dte->save();

            // Congela el orden de las líneas según la orden de compra (solo CCF) y
            // reasigna numero_linea 1..n ANTES de transicionar (aún en borrador: el
            // observer permite editar líneas). Así el JSON oficial y el PDF quedan en el
            // mismo orden. No cambia cantidades, precios ni totales.
            $this->reordenarLineasSegunOc($dte);

            // Transición de estado + bitácora (única vía válida).
            $this->maquina->transicionar($dte, EstadoDte::Generado, $usuario, 'Generación del documento');

            // JSON oficial preliminar dentro de la MISMA transacción: si falla la
            // validación, el rollback deja el documento en borrador (sin números a medias).
            $this->generarJsonOficial($dte);

            return $dte->refresh();
        });
    }

    /**
     * Construye el JSON oficial preliminar del documento recién generado (numeración
     * oficial + serialización + validación contra el schema del MH + archivo guardado).
     * Los tipos sin serializador oficial se generan sin JSON (comportamiento previo).
     *
     * @throws GeneracionException  si el documento no se puede mapear/serializar/validar
     */
    private function generarJsonOficial(Dte $dte): void
    {
        if (! $this->json->soporta($dte->tipo_dte)) {
            return; // tipos sin JSON oficial conservan el flujo anterior (solo número interno)
        }

        try {
            $this->json->generar($dte); // asigna numero_control + codigo_generacion y guarda json_generado_path
        } catch (DteJsonInvalidoException $e) {
            throw new GeneracionException(
                'El documento no se generó: su JSON oficial no pasó la validación del schema del MH ('
                .implode(' | ', array_slice($e->errores, 0, 6)).'). Corregí los datos e intentá de nuevo.'
            );
        } catch (DteNoMapeableException|DteNoSerializableException $e) {
            throw new GeneracionException(
                'El documento no se generó: no se pudo construir el JSON oficial ('
                .implode(' | ', array_slice($e->problemas, 0, 6)).'). Corregí los datos e intentá de nuevo.'
            );
        } catch (DteJsonException $e) {
            throw new GeneracionException('El documento no se generó: '.$e->getMessage());
        }
    }

    /**
     * Ordena las líneas del CCF según la orden de compra (código de barras / nombre;
     * lo no listado al final) y reasigna numero_linea 1..n, de modo que el JSON oficial
     * (numItem) y el PDF salgan en ese mismo orden. Solo aplica a documentos NUEVOS que
     * se están generando desde borrador (aún editables) y solo al CCF; las notas de
     * crédito y otros tipos conservan su orden. No modifica cantidades, precios ni totales.
     *
     * Público para poder verificarlo de forma aislada; en el flujo real lo invoca
     * {@see generar()} sobre el borrador antes de transicionar a generado.
     */
    public function reordenarLineasSegunOc(Dte $dte): void
    {
        if ($dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return;
        }

        $ordenadas = $dte->lineas()->get()
            ->sortBy(fn (DteLinea $l) => [
                OrdenProductosOc::rank($l->codigo_barra, $l->descripcion),
                mb_strtoupper((string) $l->descripcion),
            ])
            ->values();

        $numero = 1;
        foreach ($ordenadas as $linea) {
            if ((int) $linea->numero_linea !== $numero) {
                $linea->numero_linea = $numero;
                $linea->save();
            }
            $numero++;
        }

        $dte->load('lineas'); // recargar en el nuevo orden para el JSON oficial / PDF
    }

    /**
     * @throws GeneracionException
     */
    private function validar(Dte $dte): void
    {
        if (! $dte->esEditable()) {
            throw new GeneracionException('Solo se puede generar un documento en borrador (estado actual: '.$dte->estado->label().').');
        }

        if ($dte->lineas()->count() === 0) {
            throw new GeneracionException('No se puede generar un documento sin líneas.');
        }

        if ($dte->total_pagar === null || Dinero::comparar($dte->total_pagar, '0') < 0) {
            throw new GeneracionException('Los totales del documento no son válidos. Recalcule antes de generar.');
        }

        if (! $dte->establecimiento_id || ! $dte->punto_venta_id) {
            throw new GeneracionException('El documento debe tener establecimiento y punto de venta del emisor.');
        }

        $this->validarOrdenCompra($dte);

        // Debe existir un correlativo válido para consumir.
        $this->resolverCorrelativo($dte);
    }

    /**
     * En CCF, si el cliente o la sucursal exigen orden de compra, debe estar presente.
     *
     * @throws GeneracionException
     */
    private function validarOrdenCompra(Dte $dte): void
    {
        if ($dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return;
        }

        if (\App\Support\Dte\ReglaOrdenCompra::requeridaParaDte($dte) && blank($dte->numero_orden_compra)) {
            throw new GeneracionException('Este cliente requiere número de orden de compra para emitir CCF.');
        }
    }

    /**
     * Devuelve el correlativo a consumir: el del DTE si lo tiene, o uno activo que
     * coincida por tipo / establecimiento / ambiente (y punto de venta si aplica).
     *
     * @throws GeneracionException
     */
    private function resolverCorrelativo(Dte $dte): Correlativo
    {
        if ($dte->correlativo_id && $dte->correlativo && $dte->correlativo->activo) {
            return $dte->correlativo;
        }

        $correlativo = Correlativo::query()
            ->where('activo', true)
            ->where('tipo_dte', $dte->tipo_dte->value)
            ->where('establecimiento_id', $dte->establecimiento_id)
            ->where('ambiente', $dte->ambiente->value)
            ->where(function ($q) use ($dte) {
                $q->where('punto_venta_id', $dte->punto_venta_id)->orWhereNull('punto_venta_id');
            })
            ->orderByRaw('punto_venta_id IS NULL') // prioriza el que coincide con el punto de venta
            ->first();

        if (! $correlativo) {
            throw new GeneracionException('No hay un correlativo válido para este tipo de documento, establecimiento y ambiente.');
        }

        return $correlativo;
    }

    /**
     * Número INTERNO/provisional: INT-{tipo}-{serie}-{correlativo a 15 dígitos}.
     * Ej.: INT-03-M001P001-000000000000001. No es el número de control del MH.
     */
    private function formatearNumeroInterno(Dte $dte, int $numero): string
    {
        $dte->loadMissing(['establecimiento', 'puntoVenta']);
        $serie = ($dte->establecimiento?->codigo ?? '').($dte->puntoVenta?->codigo ?? '');
        $correlativo = str_pad((string) $numero, 15, '0', STR_PAD_LEFT);

        return 'INT-'.$dte->tipo_dte->value.'-'.$serie.'-'.$correlativo;
    }
}
