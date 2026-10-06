<?php

namespace App\Services\Ppq;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Support\IdentidadPpq;
use Illuminate\Support\Collection;

/**
 * En qué está REALMENTE cada lote PPQ, derivado de sus CCF en el Seguimiento de Cobros
 * (presentación y pago de cada `cobro_documentos`), no de la columna `ppq_lotes.estado`,
 * que casi nada mueve y dejaba lotes ya presentados en «Borrador».
 *
 * Prioridad: pagado (todos) > con diferencias > en cobro (X de N) > presentado > armado.
 * Un lote sin ningún CCF en el Seguimiento es «anterior» y conserva su estado guardado.
 * «Devuelto: K» (lo que Calleja no tomó en un caso) es una etiqueta aparte, no un estado.
 */
class EstadoRealLotePpq
{
    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return collect(['armado' => 'Armado', 'presentado' => 'Presentado', 'en_cobro' => 'En cobro', 'diferencias' => 'Con diferencias', 'pagado' => 'Pagado', 'anterior' => 'Anterior'])
            ->map(fn ($label, $value) => compact('value', 'label'))->values()->all();
    }

    /** Calcula todos los lotes juntos, sin consultas por item ni por lote. */
    public function calcular(Collection $lotes): Collection
    {
        if ($lotes->isEmpty()) {
            return collect();
        }
        $lotes = new \Illuminate\Database\Eloquent\Collection($lotes->all());
        $lotes->loadMissing('items');
        $items = $lotes->flatMap->items;
        $ids = $items->pluck('dte_id')->filter()->unique();
        $controles = $items->map(fn ($i) => IdentidadPpq::normalizar($i->numero_control))->filter()->unique();
        $documentos = CobroDocumento::query()->where(function ($q) use ($ids, $controles) {
            $q->whereIn('dte_id', $ids)->orWhereIn('numero_control_norm', $controles);
        })->with(['cliente', 'eventos' => fn ($q) => $q->whereIn('tipo', ['recibido', 'presentacion', 'nota'])])->get();
        $porDte = $documentos->filter(fn ($d) => $d->dte_id !== null)->groupBy('dte_id');
        $porControl = $documentos->groupBy('numero_control_norm');

        return $lotes->mapWithKeys(function ($lote) use ($porDte, $porControl) {
            $r = array_fill_keys(['ccf_total', 'pagados', 'con_diferencia', 'presentados', 'preparados', 'devueltos', 'sin_seguimiento'], 0);
            $r += ['total_neto' => 0, 'cobrado' => 0, 'fecha' => null, 'cliente_derivado' => null];
            $clientes = collect();
            foreach ($lote->items as $item) {
                $r['total_neto'] += $item->montoDteConSigno();
                $candidatos = ($porDte->get($item->dte_id, collect()))
                    ->merge($porControl->get(IdentidadPpq::normalizar($item->numero_control), collect()))->unique('id')
                    ->filter(fn ($d) => $d->tipo_dte === $item->tipo_dte && ($lote->cliente_id === null || $d->cliente_id === $lote->cliente_id));
                // Una identidad ambigua no acredita ni cliente ni seguimiento.
                $doc = $candidatos->count() === 1 ? $candidatos->first() : null;
                if ($item->tipo_dte !== '03') {
                    continue;
                }
                if ($doc) {
                    $clientes->put($doc->cliente_id, $doc->cliente);
                }
                $r['ccf_total']++;
                if (! $doc) {
                    $r['sin_seguimiento']++;

                    continue;
                }
                $r['cobrado'] += (float) $doc->monto_pagado;
                $r['pagados'] += (int) ($doc->pago_estado === EstadoPagoCobro::Pagado);
                $r['con_diferencia'] += (int) in_array($doc->pago_estado, [EstadoPagoCobro::Parcial, EstadoPagoCobro::Diferencia], true);
                $r['preparados'] += (int) ($doc->presentacion_estado === EstadoPresentacionCobro::Preparada);
                if (in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true)) {
                    $r['presentados']++;
                    foreach ($doc->eventos as $evento) {
                        if (in_array($evento->tipo, [TipoEventoCobro::Recibido, TipoEventoCobro::Presentacion], true)) {
                            $fecha = $evento->fecha ?? $evento->created_at;
                            if ($fecha && ($r['fecha'] === null || $fecha->lt($r['fecha']))) {
                                $r['fecha'] = $fecha;
                            }
                        }
                    }
                }
                $r['devueltos'] += (int) $doc->eventos->contains(fn ($e) => $e->tipo === TipoEventoCobro::Nota
                    && preg_match('/^caso-.+-fuera$/', $e->referencia_linea ?? '')
                    && $e->created_at >= $item->created_at);
            }
            // El cliente sale de sus CCF en el Seguimiento, solo si todos son del mismo.
            $r['cliente_derivado'] = $lote->cliente_id === null && $clientes->count() === 1 ? $clientes->first() : null;
            $r['cliente_motivo'] = $clientes->count() > 1 ? 'documentos de varios clientes' : 'sin documentos en el seguimiento';
            $r['total_neto'] = round($r['total_neto'], 2);
            $r['cobrado'] = round($r['cobrado'], 2);
            $r['pendiente'] = max(0, round($r['total_neto'] - $r['cobrado'], 2));
            $key = match (true) {
                $r['ccf_total'] === $r['sin_seguimiento'] => 'anterior',
                $r['pagados'] === $r['ccf_total'] => 'pagado',
                $r['con_diferencia'] > 0 => 'diferencias',
                $r['pagados'] > 0 => 'en_cobro',
                $r['presentados'] > 0 => 'presentado',
                default => 'armado',
            };
            $label = collect(self::opciones())->firstWhere('value', $key)['label'];
            if ($key === 'anterior') {
                $label = $lote->estado->label().' (anterior)';
            } elseif ($key === 'en_cobro') {
                $label .= ' · '.$r['pagados'].' de '.$r['ccf_total'].' pagados';
            }
            $clase = match ($key) {
                'pagado' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                'diferencias' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                'presentado', 'en_cobro' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                default => 'bg-gray-100 text-gray-700 dark:bg-ink-700 dark:text-paper-100',
            };
            $r['estado'] = compact('key', 'label', 'clase');
            $r['fecha'] ??= $lote->fecha;

            return [$lote->id => $r];
        });
    }
}
