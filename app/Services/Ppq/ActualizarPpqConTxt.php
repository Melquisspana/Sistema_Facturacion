<?php

namespace App\Services\Ppq;

use App\Enums\EstadoPpq;
use App\Exceptions\Ppq\ConciliacionYaProcesadaException;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;

/**
 * El TXT de pago se carga UNA vez (en el Seguimiento). Con esas mismas filas se concilian
 * los PPQ que tienen alguno de sus documentos, y el estado de cada PPQ se pone solo:
 * pagado cuando todos sus CCF quedaron pagados.
 */
class ActualizarPpqConTxt
{
    public function __construct(private readonly ConciliadorPpq $conciliador) {}

    /**
     * @param  array<int, array<string, mixed>>  $filas  del ConciliacionTxtParser
     * @return array<int, array{lote: PpqLote, pagado: bool}>
     */
    public function aplicar(array $filas, ArchivoConciliacion $archivo, ?User $usuario = null): array
    {
        $numeros = collect($filas)->map(fn ($f) => ConciliacionTxtParser::normalizarNumero($f['numero'] ?? null))->filter()->flip();
        if ($numeros->isEmpty()) {
            return [];
        }

        $loteIds = PpqItem::query()->get(['ppq_lote_id', 'numero_control'])
            ->filter(fn (PpqItem $i) => $numeros->has($i->numeroNormalizado()))
            ->pluck('ppq_lote_id')->unique();

        $resultado = [];
        foreach (PpqLote::whereIn('id', $loteIds)->get() as $lote) {
            try {
                $this->conciliador->conciliar($lote, $filas, $usuario, $archivo);
            } catch (ConciliacionYaProcesadaException) {
                // Ya se había aplicado este mismo archivo al lote: nada que hacer.
            }
            $resultado[] = ['lote' => $lote, 'pagado' => self::actualizarEstado($lote)];
        }

        return $resultado;
    }

    /** Pagado cuando todos sus CCF quedaron conciliados. Devuelve si quedó pagado. */
    public static function actualizarEstado(PpqLote $lote): bool
    {
        $ccf = $lote->items()->get()->reject(fn (PpqItem $i) => $i->esNc());
        $pagado = $ccf->isNotEmpty() && $ccf->every(fn (PpqItem $i) => $i->estaConciliado());

        if ($pagado && $lote->estado !== EstadoPpq::Pagado) {
            $lote->update(['estado' => EstadoPpq::Pagado->value]);
        }

        return $pagado;
    }
}
