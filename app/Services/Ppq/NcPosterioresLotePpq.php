<?php

namespace App\Services\Ppq;

use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\NcExportacionItem;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\EjercicioControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Incorpora NC posteriores al armado, en su lote editable o en el próximo PPQ del cliente. */
class NcPosterioresLotePpq
{
    /** NC aceptadas de los CCF de este lote que todavía no ocupan un PPQ vigente. */
    public function notas(PpqLote $lote): Collection
    {
        $ids = $lote->items()->where('tipo_dte', '03')->whereNotNull('dte_id')->pluck('dte_id');

        return $this->sinLoteVigente(Dte::sinNcProntoPago()->whereIn('dte_relacionado_id', $ids)->where('tipo_dte', '05')
            ->where('estado', EstadoDte::Aceptado->value)->whereNotNull('sello_recepcion')->orderBy('id')->get());
    }

    /**
     * NC del cliente emitidas DESPUÉS de que su CCF viajó en un lote ya presentado: van en el
     * próximo PPQ que se arme (decisión del usuario, 06/10/2026).
     *
     * Para no barrer historia vieja hacia un PPQ nuevo, la NC tiene que haberse aceptado
     * después de armado ese lote y no haber salido ya en un archivo de NC (nc_exportacion_items).
     *
     * @return Collection<int, Dte>
     */
    public function sueltasDelCliente(Cliente $cliente): Collection
    {
        // Las que el TXT devolvió (PendientesDelTxt): el lote del que salieron ya no las retiene.
        $devueltaDe = self::devueltasPorTxt($cliente->id);
        $devueltas = $this->sinLoteVigente(Dte::sinNcProntoPago()->where('cliente_id', $cliente->id)->where('tipo_dte', '05')
            ->whereKey(array_keys($devueltaDe))->where('estado', EstadoDte::Aceptado->value)->whereNotNull('sello_recepcion')->get(), $devueltaDe);
        $notas = $this->sinLoteVigente(Dte::sinNcProntoPago()->where('cliente_id', $cliente->id)->where('tipo_dte', '05')
            ->where('estado', EstadoDte::Aceptado->value)->whereNotNull('sello_recepcion')
            ->whereNotNull('dte_relacionado_id')
            ->whereNotIn('id', NcExportacionItem::query()->select('dte_id'))
            ->orderBy('id')->get());
        if ($notas->isEmpty()) {
            return $devueltas->sortBy('id')->values();
        }
        $ids = $notas->pluck('dte_relacionado_id')->unique();
        $lotes = PpqLote::whereHas('items', fn ($q) => $q->where('tipo_dte', '03')->whereIn('dte_id', $ids))
            ->with('items')->get();
        $presentados = collect();   // dte_id del CCF => cuándo se armó el primer lote presentado
        $armados = collect();
        foreach ($lotes as $lote) {
            $admite = $this->admite($lote);
            foreach ($lote->items->where('tipo_dte', '03')->pluck('dte_id')->filter() as $id) {
                if ($admite) {
                    $armados->put($id, true);
                } elseif (! $presentados->has($id) || $lote->created_at?->lt($presentados->get($id))) {
                    $presentados->put($id, $lote->created_at);
                }
            }
        }

        return $notas->filter(function (Dte $nc) use ($presentados, $armados) {
            if (! $presentados->has($nc->dte_relacionado_id) || $armados->has($nc->dte_relacionado_id)) {
                return false;
            }
            $armadoEn = $presentados->get($nc->dte_relacionado_id);
            $aceptadaEn = $nc->fecha_procesamiento_mh ?? $nc->created_at;

            return $armadoEn === null || ($aceptadaEn !== null && $aceptadaEn->gt($armadoEn));
        })->merge($devueltas)->unique('id')->sortBy('id')->values();
    }

    /**
     * NC que un TXT devolvió para el próximo PPQ (eventos `txt-…-nc-{id}` en su CCF), con los
     * lotes de los que salieron. Lo usan este servicio y el exportador del quedan.
     *
     * @return array<int, array<int, int>> id de la NC => ids de los lotes de los que se devolvió
     */
    public static function devueltasPorTxt(?int $clienteId = null): array
    {
        $devueltas = [];
        CobroEvento::where('tipo', TipoEventoCobro::Nota->value)->where('referencia_linea', 'like', 'txt-%-nc-%')
            ->when($clienteId !== null, fn ($q) => $q->whereHas('documento', fn ($d) => $d->where('cliente_id', $clienteId)))
            ->get(['datos'])
            ->each(function (CobroEvento $evento) use (&$devueltas) {
                $nc = (int) ($evento->datos['nc_dte_id'] ?? 0);
                if ($nc > 0) {
                    $devueltas[$nc][] = (int) ($evento->datos['ppq_lote_id'] ?? 0);
                }
            });

        return $devueltas;
    }

    /**
     * Excluye ocupación por id o número normalizado, incluso en items sin DTE vinculado.
     *
     * @param  array<int, array<int, int>>  $devueltaDe  lotes que ya no retienen cada NC ({@see devueltasPorTxt()})
     */
    private function sinLoteVigente(Collection $notas, array $devueltaDe = []): Collection
    {
        if ($notas->isEmpty()) {
            return $notas;
        }
        $ocupados = PpqItem::whereHas('lote')->get(['ppq_lote_id', 'dte_id', 'numero_control', 'ejercicio_control']);
        $porDte = $ocupados->whereNotNull('dte_id')->groupBy('dte_id')->map(fn ($items) => $items->pluck('ppq_lote_id')->map(fn ($id) => (int) $id));
        $porControl = $ocupados->groupBy(fn ($item) => EjercicioControl::llave($item->numero_control, $item->ejercicio_control) ?? '')
            ->map(fn ($items) => $items->pluck('ppq_lote_id')->map(fn ($id) => (int) $id));

        return $notas->reject(function ($nc) use ($porDte, $porControl, $devueltaDe) {
            $llave = EjercicioControl::llave($nc->numero_control, EjercicioControl::de($nc->fecha_emision));
            $lotes = $porDte->get($nc->id, collect())->merge($llave !== null ? $porControl->get($llave, collect()) : collect());

            return $lotes->diff($devueltaDe[$nc->id] ?? [])->isNotEmpty();
        })->values();
    }

    /** Permite incorporar NC solamente antes de la presentación o el cobro del lote. */
    public function admite(PpqLote $lote): bool
    {
        if ($lote->trashed() || ! $lote->estado->esEditable()) {
            return false;
        }
        $lote->load('items');
        $real = app(EstadoRealLotePpq::class)->calcular(collect([$lote]))[$lote->id];

        return in_array($real['estado']['key'], ['armado', 'anterior'], true);
    }

    /** Agrega las NC pendientes bajo bloqueo del lote y devuelve cuántas incorporó. */
    public function agregar(PpqLote $lote, ?User $usuario = null): int
    {
        return DB::transaction(function () use ($lote) {
            $actual = PpqLote::whereKey($lote->id)->lockForUpdate()->first();
            if (! $actual || ! $this->admite($actual)) {
                return 0;
            }
            $notas = $this->notas($actual);
            foreach ($notas as $nc) {
                $actual->items()->create(self::datosItemNc($nc));
            }

            return $notas->count();
        });
    }

    /** Incorpora una NC recién aceptada a los lotes editables de su CCF. */
    public function alAceptarse(Dte $nc): void
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito || $nc->estado !== EstadoDte::Aceptado || ! $nc->dte_relacionado_id) {
            return;
        }
        $lotes = PpqLote::whereHas('items', fn ($q) => $q->where('tipo_dte', '03')->where('dte_id', $nc->dte_relacionado_id))->orderBy('id')->get();
        foreach ($lotes as $lote) {
            $this->agregar($lote);
        }
    }

    /** Datos comunes del item de NC, tanto al armar como al completar el PPQ. */
    public static function datosItemNc(Dte $nc): array
    {
        return [
            'dte_id' => $nc->id,
            'origen' => 'local',
            'numero_control' => $nc->numero_control,
            'codigo_generacion' => $nc->codigo_generacion,
            'sello_recepcion' => $nc->sello_recepcion,
            'tipo_dte' => '05',
            'fecha_documento' => $nc->fecha_emision,
            'sin_albaran' => true,
            'numero_orden_compra' => $nc->numero_orden_compra,
            'monto_dte' => $nc->total_pagar,
        ];
    }
}
