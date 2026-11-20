<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Ppq\ActualizarPpqConTxt;
use App\Services\Ppq\NcPosterioresLotePpq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja listo para el próximo PPQ lo que el TXT no pagó. Los CCF y las NC no pagados salen
 * del lote viejo (con el resguardo de {@see RetirarItemsPpq}). Los CCF vuelven a «sin
 * presentar» con un evento `txt-…-fuera`; las NC quedan anotadas en su CCF (`txt-…-nc-…`)
 * para que las tome {@see NcPosterioresLotePpq::sueltasDelCliente()}.
 * Todo o nada: si un item no está en {@see PendientesDelTxt}, no se cambia nada.
 *
 * Un CCF que iba en un quedan presentado desde Cobros sin acuse sale también de ese quedan
 * ({@see SoltarCcfDelQuedan}), para poder ir en otro. Si el quedan no tiene la presentación
 * registrada o ya tiene el acuse, no podría ir en otro quedan: no se devuelve y se avisa.
 */
class ReenviarPendientesTxt
{
    public function reenviar(Cliente $cliente, string $hash, array $itemIds, ?User $usuario): array
    {
        return DB::transaction(function () use ($cliente, $hash, $itemIds, $usuario) {
            Cliente::whereKey($cliente->id)->lockForUpdate()->firstOrFail();
            $lotes = PpqLote::where('cliente_id', $cliente->id)
                ->whereHas('conciliaciones', fn ($q) => $q->where('origen', 'txt')->where('archivo_hash', $hash))
                ->lockForUpdate()->get();
            $listas = app(PendientesDelTxt::class)->analizar($cliente, $lotes);
            $validos = collect();
            foreach (['no_pagados', 'pagados_de_menos', 'nc_no_descontadas'] as $grupo) {
                $entradas = $listas[$grupo];
                foreach ($entradas as $entrada) {
                    $validos->put($entrada['item']->id, $entrada + ['grupo' => $grupo]);
                }
            }
            $errores = [];
            foreach ($itemIds as $id) {
                if (! $validos->has($id)) {
                    $item = PpqItem::whereKey($id)->whereHas('lote', fn ($q) => $q->where('cliente_id', $cliente->id))->first();
                    $errores[] = ($item?->numero_control ?? 'Item #'.$id).': no corresponde a los pendientes de este TXT.';
                }
            }
            $seleccion = $validos->only($itemIds);
            foreach ($seleccion as $id => $entrada) {
                if ($entrada['grupo'] === 'nc_no_descontadas') {
                    $ccfId = $entrada['item']->dte->dte_relacionado_id;
                    $ccf = $ccfId ? CobroDocumento::deCliente($cliente->id)->where('tipo_dte', '03')->where('dte_id', $ccfId)->first() : null;
                    if (! $ccf) {
                        $errores[] = $entrada['item']->numero_control.': el CCF no está en el seguimiento.';
                    } else {
                        $validos->put($id, $entrada + ['ccf' => $ccf]);
                    }
                }
            }
            // Los quedanes de Cobros de los CCF se bloquean ANTES que los CCF, en el orden del
            // acuse y de la presentación, y se decide con lo releído bajo candado.
            $salida = app(SoltarCcfDelQuedan::class);
            $entradasCcf = $seleccion->reject(fn ($entrada) => $entrada['grupo'] === 'nc_no_descontadas');
            $docsCcf = $entradasCcf->pluck('documento')->unique('id')->values();
            $quedanes = $salida->bloquearQuedanes($docsCcf);
            if ($docsCcf->isNotEmpty()) {
                CobroDocumento::whereKey($docsCcf->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();
                $docsCcf->each->refresh();
            }
            foreach ($entradasCcf as $entrada) {
                if (($motivo = $salida->motivoParaRetener($entrada['item'], $entrada['documento'], $quedanes)) !== null) {
                    $errores[] = $entrada['item']->numero_control.": no se devuelve, porque {$motivo}.";
                }
            }
            if ($errores !== [] || $itemIds === []) {
                throw ValidationException::withMessages(['items' => $errores ?: ['Marcá al menos un documento.']]);
            }
            $resultado = ['ccf' => 0, 'nc' => 0];
            foreach ($validos->only($itemIds)->groupBy('lote.id') as $entradas) {
                $lote = $entradas->first()['lote'];
                $archivo = $lote->conciliaciones()->where('origen', 'txt')->where('archivo_hash', $hash)->firstOrFail();
                app(RetirarItemsPpq::class)->retirar($lote, $entradas->where('grupo', '!=', 'pagados_de_menos')->pluck('item'),
                    "Devueltos por el TXT {$archivo->archivo_nombre}: no vinieron pagados (van en el siguiente PPQ):", 'ppq.devueltos_txt', ['hash' => $hash], $usuario);
                foreach ($entradas as $entrada) {
                    $nc = $entrada['grupo'] === 'nc_no_descontadas';
                    $doc = $nc ? $entrada['ccf'] : $entrada['documento'];
                    $datos = ['ppq_lote_id' => $lote->id, 'hash' => $hash];
                    $quedan = null;
                    if ($nc) {
                        $datos['nc_dte_id'] = $entrada['item']->dte->id;
                    } else {
                        $datos['faltante'] = $entrada['grupo'] === 'pagados_de_menos';
                        $quedan = $salida->quedanQueSuelta($entrada['item'], $doc, $quedanes);
                        if ($quedan !== null) {
                            $salida->soltar($doc, $quedan, $usuario, 'un CCF salió del quedan presentado: el TXT de pagos no lo pagó',
                                ['ppq_lote_id' => $lote->id, 'hash' => $hash]);
                            $datos['cobro_solicitud_id'] = $quedan->id;
                        } else {
                            $doc->update(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value]);
                        }
                    }
                    CobroEvento::firstOrCreate(
                        ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value,
                            'referencia_linea' => 'txt-'.substr($hash, 0, 12).($nc ? '-nc-'.$entrada['item']->dte->id : '-fuera')],
                        ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id, 'datos' => $datos,
                            'detalle' => $nc ? 'Calleja no descontó la NC: va en el siguiente PPQ.'
                                : 'Devuelto para el próximo PPQ por el TXT de pagos.'.($quedan !== null ? " Salió del quedan presentado {$quedan->referencia}." : '')],
                    );
                    $resultado[$nc ? 'nc' : 'ccf']++;
                }
                ActualizarPpqConTxt::actualizarEstado($lote);
            }

            return $resultado;
        });
    }
}
