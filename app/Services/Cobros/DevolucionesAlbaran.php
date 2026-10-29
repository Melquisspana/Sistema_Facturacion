<?php

namespace App\Services\Cobros;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Services\Ppq\AlbaranParser;
use App\Support\Albaran;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** La referencia al CCF o la OC identifican; sala y cliente validan la devolución. */
class DevolucionesAlbaran
{
    /** La referencia se conserva en la evidencia PDF, sin agregar campos a la tabla. */
    public function auditarRecibida(PpqAlbaran $nota, ?string $referencia = null): array
    {
        if ($nota->tipo_codigo !== 'AC04') {
            return ['dte' => null, 'motivo' => 'no es una devolución AC04'];
        }
        if ($nota->archivo_path !== null) {
            $disk = Storage::disk((string) config('dte.storage.disk', 'local'));
            if (! $disk->exists($nota->archivo_path)) {
                return ['dte' => null, 'motivo' => 'la evidencia de la AC04 no está disponible para comprobar su referencia'];
            }
            $datos = app(AlbaranParser::class)->desdePdf($disk->get($nota->archivo_path));
            if (filled($datos['debug']['error'] ?? null)) {
                return ['dte' => null, 'motivo' => 'no se pudo leer inequívocamente la evidencia de la AC04'];
            }
            $guardada = $datos['numero_control_referenciado'] ?? null;
            if ($referencia !== null && IdentidadPpq::normalizar($referencia) !== IdentidadPpq::normalizar($guardada)) {
                return ['dte' => null, 'motivo' => 'la referencia recibida contradice la evidencia guardada'];
            }
            $referencia = $guardada;
        }
        if (filled($referencia)) {
            $dte = IdentidadPpq::dteLocal($referencia, (string) config('dte.ambiente'));
        } else {
            $oc = OrdenCompra::normalizar($nota->numero_orden_compra);
            $candidatos = $oc === '' ? collect() : Dte::where('tipo_dte', '03')
                ->where('estado', EstadoDte::Aceptado->value)->where('ambiente', (string) config('dte.ambiente'))
                ->whereRaw("REPLACE(REPLACE(numero_orden_compra, '-', ''), ' ', '') = ?", [$oc])->limit(2)->get();
            $dte = $candidatos->count() === 1 ? $candidatos->first() : null;
        }
        if ($dte === null || $dte->tipo_dte !== TipoDte::CreditoFiscal || $dte->estado !== EstadoDte::Aceptado) {
            return ['dte' => null, 'motivo' => 'sin CCF aceptado e inequívoco para la referencia o la OC'];
        }
        $clienteNota = $nota->clienteSucursal?->cliente_id ?? config('ppq.cliente_default_id');
        $salaNota = Sala::normalizar($nota->sala_codigo ?: Albaran::salaDesdeNumero($nota->numero_albaran));
        $salaNumero = Sala::normalizar(Albaran::salaDesdeNumero($nota->numero_albaran));
        $salaCcf = Sala::normalizar($dte->clienteSucursal?->codigo) ?: OrdenCompra::salaDesde($dte->numero_orden_compra);
        $motivo = match (true) {
            $nota->dte_id !== null && $nota->dte_id !== $dte->id => 'la AC04 ya apunta a otro DTE',
            $clienteNota === null => 'cliente de la AC04 sin confirmar',
            (int) $clienteNota !== $dte->cliente_id => 'el CCF pertenece a otro cliente',
            $salaNota === null || $salaCcf === null || $salaNota !== $salaCcf => 'el CCF corresponde a otra sala o su sala no está confirmada',
            $salaNumero !== null && $salaNumero !== $salaNota => 'la sala registrada contradice el número de la AC04',
            $nota->clienteSucursal !== null && Sala::normalizar($nota->clienteSucursal->codigo) !== $salaNota => 'la sucursal registrada corresponde a otra sala',
            $nota->monto_albaran === null || Dinero::comparar($nota->monto_albaran, '0') < 0 => 'importe de la AC04 ausente o negativo',
            default => null,
        };

        return ['dte' => $motivo === null ? $dte : null, 'motivo' => $motivo];
    }

    public function vincularRecibida(PpqAlbaran $nota, ?string $referencia = null): string
    {
        return DB::transaction(function () use ($nota, $referencia) {
            $nota = PpqAlbaran::lockForUpdate()->findOrFail($nota->id);
            $resultado = $this->auditarRecibida($nota, $referencia);
            if ($resultado['dte'] === null) {
                return 'sin vincular: '.$resultado['motivo'];
            }
            if ($nota->dte_id === null) {
                $nota->update(['dte_id' => $resultado['dte']->id]);
            }

            return 'vinculada al CCF '.$resultado['dte']->numero_control;
        }, 3);
    }

    /** @return array{notas: Collection, rechazadas: array<int, string>} */
    public function analizar(CobroDocumento $doc, PpqAlbaran $entrega, bool $bloquear = false): array
    {
        $oc = OrdenCompra::normalizar($entrega->numero_orden_compra);
        $consulta = PpqAlbaran::where('tipo_codigo', 'AC04')
            ->where(function ($q) use ($oc, $doc) {
                $q->whereRaw("REPLACE(REPLACE(numero_orden_compra, '-', ''), ' ', '') = ?", [$oc]);
                if ($doc->dte_id !== null) {
                    $q->orWhere('dte_id', $doc->dte_id);
                }
            })->with('clienteSucursal');
        $candidatas = ($bloquear ? $consulta->lockForUpdate() : $consulta)->orderBy('id')->get();
        $sala = Sala::normalizar($entrega->sala_codigo ?: Albaran::salaDesdeNumero($entrega->numero_albaran));
        $salaCcf = Sala::normalizar($doc->dte?->clienteSucursal?->codigo);
        $notas = collect();
        $rechazadas = [];
        foreach ($candidatas as $nota) {
            $salaNumero = Sala::normalizar(Albaran::salaDesdeNumero($nota->numero_albaran));
            $salaNota = Sala::normalizar($nota->sala_codigo ?: $salaNumero);
            $motivo = match (true) {
                $doc->dte_id === null => 'el CCF no tiene DTE local',
                $nota->dte_id !== null && $nota->dte_id !== $doc->dte_id => 'apunta a otro DTE',
                $nota->dte_id !== $doc->dte_id && ($oc === '' || OrdenCompra::normalizar($nota->numero_orden_compra) !== $oc) => 'otra orden de compra',
                $nota->clienteSucursal !== null && $nota->clienteSucursal->cliente_id !== $doc->cliente_id => 'otro cliente',
                $sala === null || $salaNota !== $sala || ($salaNumero !== null && $salaNumero !== $sala)
                    || ($salaCcf !== null && $salaCcf !== $salaNota)
                    || ($nota->clienteSucursal !== null && Sala::normalizar($nota->clienteSucursal->codigo) !== $salaNota) => 'otra sala o sala sin confirmar',
                $nota->monto_albaran === null || Dinero::comparar($nota->monto_albaran, '0') < 0 => 'importe ausente o negativo',
                default => null,
            };
            if ($motivo !== null) {
                $rechazadas[$nota->id] = $nota->numero_albaran.': no vinculada ('.$motivo.').';
            } else {
                $notas->push($nota);
            }
        }

        return ['notas' => $notas, 'rechazadas' => $rechazadas];
    }

    /** Se llama dentro de la transacción y bajo el bloqueo de la entrega. */
    public function vincular(CobroDocumento $doc, PpqAlbaran $entrega): array
    {
        $resultado = $this->analizar($doc, $entrega, bloquear: true);
        foreach ($resultado['notas'] as $nota) {
            if ($nota->dte_id === null) {
                $nota->update(['dte_id' => $doc->dte_id]);
            }
        }

        return $resultado;
    }

    /** CCF − (entrega − devoluciones), con la misma comparación a centavos de Cobros. */
    public function diferencia(CobroDocumento $doc, PpqAlbaran $entrega, Collection $notas): ?string
    {
        if ($doc->monto === null || $entrega->monto_albaran === null) {
            return null;
        }
        $neto = $entrega->monto_albaran;
        foreach ($notas as $nota) {
            $neto = Dinero::restar($neto, $nota->monto_albaran);
        }

        return Dinero::redondear(Dinero::restar(Dinero::redondear($doc->monto), Dinero::redondear($neto)));
    }

    /** Solo avisos; no interviene en la elegibilidad del PPQ. */
    public function avisos(CobroDocumento $doc, ?PpqAlbaran $entrega = null): array
    {
        $entrega ??= $doc->albaran;
        if ($entrega === null || $doc->tipo_dte !== '03' || $doc->estaInvalidado()) {
            return ['reciente' => false, 'pedir_nota' => false];
        }
        $recibidas = $this->analizar($doc, $entrega)['notas'];
        $notas = $recibidas->where('dte_id', $doc->dte_id);
        $fecha = $entrega->fecha_albaran ?? $entrega->created_at;
        $ultima = $notas->sortByDesc('created_at')->first()?->created_at;
        if ($ultima !== null && ($fecha === null || $ultima->gt($fecha))) {
            $fecha = $ultima;
        }
        $diferencia = $this->diferencia($doc, $entrega, collect());

        return [
            'reciente' => $fecha !== null && $fecha->betweenIncluded(now()->subDays(max(0, (int) config('cobros.dias_albaran_reciente', 3))), now()),
            'pedir_nota' => $recibidas->isEmpty() && $diferencia !== null && Dinero::comparar($diferencia, '0') !== 0
                && $entrega->fecha_albaran !== null && today()->gt($entrega->fecha_albaran),
        ];
    }
}
