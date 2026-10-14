<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoPpq;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\IdentidadPpq;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * El reporte que devuelve el portal de Calleja al registrar un caso de quedan
 * («CASO 12027 …», una fila por documento con su DTE y ESTADO).
 *
 * Calleja no siempre toma todo lo enviado. Por eso:
 *  · lo que viene REGISTRADO queda recibido, con el número de caso;
 *  · los CCF que iban en el mismo lote PPQ y NO vinieron vuelven a «por presentar» y SALEN
 *    del lote, con sus NC, para meterlos en el siguiente PPQ: así el lote queda igual al caso.
 *    En las observaciones del lote queda la lista de lo que salió. Lo que ya se había
 *    presentado en un lote anterior no se toca;
 *  · las NC de los CCF que quedan se quedan con ellos.
 *
 * Volver a cargar el mismo reporte no cambia nada, y corrige un lote cargado antes de que
 * los devueltos salieran (decisión del usuario, 13/10/2026).
 */
class ReporteCasoCalleja
{
    /**
     * @return array{caso: string, documentos: array<string, string>}
     *
     * @throws RuntimeException si el archivo no es el reporte esperado
     */
    public function leer(string $ruta): array
    {
        try {
            $filas = IOFactory::load($ruta)->getActiveSheet()->toArray();
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer el reporte: '.$e->getMessage(), 0, $e);
        }

        if (! preg_match('/CASO\s+(\d+)/i', (string) ($filas[0][0] ?? ''), $m)) {
            throw new RuntimeException('El archivo no parece el reporte de caso de Calleja (falta «CASO …» en la primera celda).');
        }

        $documentos = [];
        foreach ($filas as $fila) {
            $clave = IdentidadPpq::normalizar((string) ($fila[8] ?? ''));
            if ($clave !== null && preg_match('/^DTE0[35]/', $clave)) {
                $documentos[$clave] = strtoupper(trim((string) ($fila[10] ?? '')));
            }
        }

        if ($documentos === []) {
            throw new RuntimeException('El reporte no trae documentos.');
        }

        return ['caso' => $m[1], 'documentos' => $documentos];
    }

    /**
     * @param  array{caso: string, documentos: array<string, string>}  $reporte
     * @return array{caso: string, recibidos: int, no_en_seguimiento: array<int, string>, devueltos: array<int, CobroDocumento>, items_quitados: Collection, conservados: Collection}
     */
    public function aplicar(Cliente $cliente, array $reporte, ?User $usuario = null, ?PpqLote $lote = null): array
    {
        $caso = $reporte['caso'];
        $registrados = array_keys(array_filter($reporte['documentos'], fn ($estado) => $estado === '' || str_contains($estado, 'REGISTRADO')));

        $porClave = CobroDocumento::deCliente($cliente->id)->get()
            ->keyBy(fn (CobroDocumento $d) => IdentidadPpq::normalizar($d->numero_control));

        $resultado = ['caso' => $caso, 'recibidos' => 0, 'no_en_seguimiento' => [], 'devueltos' => [], 'items_quitados' => collect(), 'conservados' => collect()];

        DB::transaction(function () use ($registrados, $porClave, $caso, $usuario, $lote, &$resultado) {
            $lote ??= ($id = $this->loteDelCaso($registrados)) !== null ? PpqLote::find($id) : null;
            $lote = $lote ? PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail() : null;
            $items = $lote ? $lote->items()->with('dte')->get() : collect();
            $anteriores = $lote ? PpqItem::whereHas('lote')->where('ppq_lote_id', '<', $lote->id)->get() : collect();
            $otros = app(ElegibilidadPpqSeguimiento::class)->claves($lote?->id);
            $devueltos = $items->where('tipo_dte', '03')->filter(function ($item) use ($porClave, $registrados, $anteriores) {
                $doc = $this->documentoDelItem($item, $porClave->values());

                return $doc && ! in_array(IdentidadPpq::normalizar($doc->numero_control), $registrados, true)
                    && ! $anteriores->contains(fn ($anterior) => $this->mismoDocumento($anterior, $doc))
                    && $doc->pago_estado === EstadoPagoCobro::Pendiente;
            });
            foreach ($items->where('tipo_dte', '05') as $item) {
                $ccf = $this->ccfDeNota($item, $items);
                $doc = $this->documentoDelItem($item, $porClave->values());
                if (($ccf && $devueltos->contains('id', $ccf->id)) || (! $ccf && $doc
                    && ! in_array(IdentidadPpq::normalizar($doc->numero_control), $registrados, true)
                    && ! $anteriores->contains(fn ($anterior) => $this->mismoDocumento($anterior, $doc))
                    && $doc->pago_estado === EstadoPagoCobro::Pendiente)) {
                    $devueltos->push($item);
                }
            }
            $docsDevueltos = $devueltos->map(fn ($item) => $this->documentoDelItem($item, $porClave->values()))->filter()->unique('id');
            foreach ($registrados as $clave) {
                $doc = $porClave->get($clave);
                if ($doc === null) {
                    $resultado['no_en_seguimiento'][] = $clave;

                    continue;
                }
                // Una NC registrada que salió con su CCF no se recibe al repetir el reporte.
                if ($docsDevueltos->contains('id', $doc->id) || ($lote && $doc->eventos()->where('referencia_linea', 'caso-'.$caso.'-fuera')->get()
                    ->contains(fn ($evento) => (int) ($evento->datos['ppq_lote_id'] ?? 0) === $lote->id))) {
                    continue;
                }
                $doc->forceFill([
                    'presentacion_estado' => EstadoPresentacionCobro::Recibida->value,
                    'revisar_historico' => false,
                ])->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Recibido->value, 'referencia_linea' => 'caso-'.$caso],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'detalle' => "Registrado por Calleja en el caso {$caso}.", 'datos' => ['caso' => $caso]],
                );
                $resultado['recibidos']++;
            }

            if ($lote === null) {
                return;
            }
            // El PPQ queda presentado, con su número de caso.
            $lote->update([
                'estado' => $lote->estado === EstadoPpq::Pagado ? EstadoPpq::Pagado->value : EstadoPpq::Enviado->value,
                'observaciones' => preg_match('/\bCaso\s+'.preg_quote((string) $caso, '/').'\b(?!\d)/i', (string) $lote->observaciones) ? $lote->observaciones
                    : trim(((string) $lote->observaciones)."
Caso {$caso} de Calleja."),
            ]);
            foreach ($docsDevueltos as $doc) {
                if ($otros->has(IdentidadPpq::normalizar($doc->numero_control)) || ($doc->dte_id && $otros->has('dte:'.$doc->dte_id))) {
                    continue;
                }
                $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => 'caso-'.$caso.'-fuera'],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'detalle' => "Calleja no lo tomó en el caso {$caso}: va en el siguiente PPQ.", 'datos' => ['caso' => $caso, 'ppq_lote_id' => $lote->id]],
                );
                $resultado['devueltos'][] = $doc;
            }
            $retiro = $this->sacarDelLote($lote, $caso, $devueltos, $usuario);
            $resultado['items_quitados'] = $retiro['items_quitados'];
            $resultado['conservados'] = $retiro['conservados'];
        });

        return $resultado;
    }

    /** Cruza las dos identidades admitidas en PPQ. */
    public function mismoDocumento(PpqItem $item, CobroDocumento $doc): bool
    {
        return ($doc->dte_id !== null && $item->dte_id === $doc->dte_id)
            || (IdentidadPpq::normalizar($doc->numero_control) !== null
                && IdentidadPpq::normalizar($item->numero_control) === IdentidadPpq::normalizar($doc->numero_control));
    }

    private function documentoDelItem(PpqItem $item, Collection $documentos): ?CobroDocumento
    {
        return $documentos->first(fn ($doc) => $this->mismoDocumento($item, $doc));
    }

    /** Resuelve también las NC y CCF guardados como snapshots sin dte_id. */
    private function ccfDeNota(PpqItem $nota, Collection $items): ?PpqItem
    {
        $dte = $nota->dte_id ? $nota->dte : IdentidadPpq::dteLocal($nota->numero_control);
        if (! $dte?->dte_relacionado_id) {
            return null;
        }

        return $items->where('tipo_dte', '03')->first(function ($item) use ($dte) {
            $ccf = $item->dte_id ? $item->dte : IdentidadPpq::dteLocal($item->numero_control);

            return $ccf?->id === $dte->dte_relacionado_id;
        });
    }

    /** Retira items sin perder conciliaciones; no modifica el Seguimiento. Acepta documentos o items. */
    public function sacarDelLote(PpqLote $lote, string $caso, Collection $documentos, ?User $usuario = null): array
    {
        return DB::transaction(function () use ($lote, $caso, $documentos, $usuario) {
            $lote = PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            $items = $lote->items()->lockForUpdate()->get()->filter(fn ($item) => $documentos->contains(
                fn ($doc) => $doc instanceof PpqItem ? $doc->id === $item->id : $this->mismoDocumento($item, $doc)
            ));
            $conHistoria = DB::table('ppq_conciliacion_movimientos')->whereIn('ppq_item_id', $items->pluck('id'))->pluck('ppq_item_id')->flip();
            $conservados = $items->filter(fn ($item) => $item->estaConciliado() || $conHistoria->has($item->id))->values();
            $quitados = $items->reject(fn ($item) => $conservados->contains('id', $item->id))->values();
            if ($quitados->isNotEmpty()) {
                $lineas = $quitados->map(function ($item) {
                    $monto = $item->montoDteConSigno();

                    return '· '.($item->esNc() ? 'NC ' : 'CCF ').$item->correlativoNumero().' · '.($monto < 0 ? '-' : '').'$'.number_format(abs($monto), 2).' · '.($item->fecha_documento?->format('d/m/Y') ?? '—');
                });
                $lote->update(['observaciones' => trim((string) $lote->observaciones)."\nDevueltos del caso {$caso} (salieron del PPQ, van en el siguiente):\n".$lineas->implode("\n")]);
                $actividad = activity()->performedOn($lote)->withProperties(['caso' => $caso, 'items' => $quitados->map->getAttributes()->all()]);
                if ($usuario) {
                    $actividad->causedBy($usuario);
                }
                $actividad->log('ppq.devueltos_caso');
                foreach ($quitados as $item) {
                    $item->delete();
                }
            }

            return ['items_quitados' => $quitados, 'conservados' => $conservados];
        });
    }

    /** Id del lote PPQ que contiene más documentos del reporte, o null. */
    private function loteDelCaso(array $claves): ?int
    {
        $conteo = [];
        foreach (PpqItem::whereHas('lote')->whereNotNull('numero_control')->get(['ppq_lote_id', 'numero_control']) as $item) {
            if (in_array(IdentidadPpq::normalizar($item->numero_control), $claves, true)) {
                $conteo[$item->ppq_lote_id] = ($conteo[$item->ppq_lote_id] ?? 0) + 1;
            }
        }
        arsort($conteo);

        return $conteo === [] ? null : (int) array_key_first($conteo);
    }
}
