<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\EstadoDte;
use App\Enums\EstadoPpq;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\IdentidadPpq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arma un PPQ (lote) con los CCF entregados que se marcan en el Seguimiento, más las NC
 * vigentes emitidas contra ellos. Los CCF quedan «en PPQ» hasta que se cargue el reporte
 * del caso (presentado) o el TXT (pagado).
 */
class CrearPpqDesdeSeguimiento
{
    /**
     * @param  array<int, int>  $ids  CobroDocumento de CCF
     *
     * @throws ValidationException si alguno no se puede meter en un PPQ
     */
    public function crear(Cliente $cliente, array $ids, ?User $usuario = null): PpqLote
    {
        $documentos = CobroDocumento::deCliente($cliente->id)->whereIn('id', $ids)->where('tipo_dte', '03')
            ->with('albaran', 'dte')->get();

        if ($documentos->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['documentos' => 'Algún documento marcado no es un CCF de este cliente.']);
        }

        $enLotes = PpqItem::query()->pluck('numero_control')->map(fn ($n) => IdentidadPpq::normalizar($n))->filter()->flip();
        $problemas = [];
        foreach ($documentos as $doc) {
            $motivo = match (true) {
                $doc->estaInvalidado() => 'fue invalidado en Hacienda',
                $doc->albaran === null => 'no tiene albarán (no entregado)',
                $doc->pago_estado !== EstadoPagoCobro::Pendiente => 'ya tiene pago',
                in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true) => 'ya se presentó',
                $enLotes->has(IdentidadPpq::normalizar($doc->numero_control)) => 'ya está en un PPQ',
                default => null,
            };
            if ($motivo !== null) {
                $problemas[] = $doc->correlativoCorto().': '.$motivo;
            }
        }
        if ($problemas !== []) {
            throw ValidationException::withMessages(['documentos' => 'No se creó el PPQ. '.implode('; ', $problemas).'.']);
        }

        return DB::transaction(function () use ($cliente, $documentos, $usuario, $enLotes) {
            $lote = PpqLote::create([
                'referencia' => 'PPQ '.now()->locale('es')->translatedFormat('j \d\e F Y'),
                'fecha' => today(),
                'estado' => EstadoPpq::Borrador->value,
                'cliente_id' => $cliente->id,
                'user_id' => $usuario?->id,
            ]);

            foreach ($documentos->sortBy('numero_control') as $doc) {
                $dte = $doc->dte;
                $lote->items()->create([
                    'dte_id' => $doc->dte_id,
                    'origen' => $doc->dte_id ? 'local' : 'gmail',
                    'numero_control' => $doc->numero_control,
                    'codigo_generacion' => $doc->codigo_generacion ?? $dte?->codigo_generacion,
                    'sello_recepcion' => $dte?->sello_recepcion,
                    'tipo_dte' => '03',
                    'fecha_documento' => $doc->fecha_emision,
                    'ppq_albaran_id' => $doc->ppq_albaran_id,
                    'sin_albaran' => false,
                    'numero_orden_compra' => $dte?->numero_orden_compra ?? $doc->albaran?->numero_orden_compra,
                    'monto_dte' => $doc->monto,
                    'monto_albaran' => $doc->albaran?->monto_albaran,
                ]);
                $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Preparada->value])->save();

                if ($doc->dte_id === null) {
                    continue;
                }
                // Sus NC vigentes que todavía no viajaron en otro PPQ.
                $notas = Dte::where('dte_relacionado_id', $doc->dte_id)->where('tipo_dte', '05')
                    ->where('estado', EstadoDte::Aceptado->value)->whereNotNull('sello_recepcion')->get();
                foreach ($notas as $nc) {
                    if ($enLotes->has(IdentidadPpq::normalizar($nc->numero_control))) {
                        continue;
                    }
                    $lote->items()->create([
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
                    ]);
                }
            }

            return $lote;
        });
    }
}
