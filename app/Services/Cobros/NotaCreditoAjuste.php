<?php

namespace App\Services\Cobros;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\Cobros\CobroAjuste;
use App\Models\Dte;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\SaldoMontoCcf;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotaCreditoAjuste
{
    public function __construct(private DteBorradorService $borradores) {}

    public function validarPendiente(CobroAjuste $ajuste): void
    {
        if ($ajuste->estadoEfectivo() !== 'pendiente_nc') {
            throw ValidationException::withMessages(['cobro_ajuste_id' => 'El ajuste está descartado o ya tiene una nota de crédito activa.']);
        }
    }

    public function notasDelCliente(int $clienteId): Builder
    {
        return Dte::query()->where('cliente_id', $clienteId)
            ->where('tipo_dte', TipoDte::NotaCredito->value)
            ->where('tipo_nota_credito', TipoNotaCredito::ProntoPago->value)
            ->where('estado', '!=', EstadoDte::Invalidado->value)
            ->where(fn ($q) => $q->whereNull('sello_invalidacion')->orWhere('sello_invalidacion', ''));
    }

    /** Una referencia desconocida no demuestra que dos deducciones sean la misma. */
    private function otrosAjustes(CobroAjuste $ajuste): Builder
    {
        return CobroAjuste::query()->where('id', '!=', $ajuste->id)
            ->when(filled($ajuste->referencia_calleja), fn ($q) => $q->where(fn ($q) => $q
                ->where('cliente_id', '!=', $ajuste->cliente_id)
                ->orWhereNull('referencia_calleja')
                ->orWhere('referencia_calleja', '!=', $ajuste->referencia_calleja)
                ->orWhere('monto', '!=', $ajuste->monto)));
    }

    public function candidatas(CobroAjuste $ajuste): Builder
    {
        return $this->notasDelCliente($ajuste->cliente_id)
            ->whereNotIn('id', $this->otrosAjustes($ajuste)->whereNotNull('nc_dte_id')->select('nc_dte_id'))
            ->orderByRaw('CASE WHEN ABS(total_pagar - ?) < 0.005 THEN 0 ELSE 1 END', [abs((float) $ajuste->monto)])
            ->orderByDesc('id');
    }

    /** Serializa las operaciones de una deducción, incluso si aparece en varios TXT. */
    private function bloquear(CobroAjuste $ajuste): CobroAjuste
    {
        Cliente::whereKey($ajuste->cliente_id)->lockForUpdate()->firstOrFail();

        return CobroAjuste::whereKey($ajuste->id)->lockForUpdate()->with('notaCredito')->firstOrFail();
    }

    public function crear(CobroAjuste $ajuste, Dte $original, array $datos, User $usuario): Dte
    {
        return DB::transaction(function () use ($ajuste, $original, $datos, $usuario) {
            $ajuste = $this->bloquear($ajuste);
            $this->validarPendiente($ajuste);
            if ((int) $ajuste->cliente_id !== (int) $original->cliente_id
                || (int) ($datos['cliente_id'] ?? $original->cliente_id) !== (int) $ajuste->cliente_id
                || ($datos['modalidad'] ?? null) !== 'pronto_pago'
                || ($datos['tipo'] ?? null) !== TipoNotaCredito::ProntoPago->value) {
                throw ValidationException::withMessages(['cobro_ajuste_id' => 'El ajuste debe ser del mismo cliente y la nota debe ser de pronto pago.']);
            }
            $objetivo = (int) round(abs((float) $ajuste->monto) * 100);
            if ($objetivo === 0) {
                throw ValidationException::withMessages(['cobro_ajuste_id' => 'El ajuste debe tener un importe distinto de cero.']);
            }
            $this->frenarSiNoAlcanza($original, $this->brutoEstimado($ajuste, $original));

            $nc = $this->borradores->crearNotaCredito($original, $datos, $usuario);
            $linea = $this->borradores->agregarConceptoNotaCredito($nc, [
                'descripcion' => 'Nota de crédito de pronto pago '.($ajuste->referencia_calleja ? '#'.$ajuste->referencia_calleja : $ajuste->referencia),
                'monto' => number_format(max(1, (int) round($objetivo / 1.13)) / 100, 2, '.', ''),
            ]);

            // Ambas ramas, también junto al umbral: solo el motor decide si retiene.
            $bases = [];
            foreach ([1.12, 1.13] as $factor) {
                $centro = (int) round($objetivo / $factor);
                foreach ([0, -1, 1, -2, 2, -3, 3, -4, 4, -5, 5] as $delta) {
                    $bases[] = max(1, $centro + $delta);
                }
            }
            $mejor = null;
            $diferencia = PHP_INT_MAX;
            foreach (array_unique($bases) as $base) {
                $linea->update(['precio_unitario' => number_format($base / 100, 2, '.', '')]);
                $this->borradores->recalcular($nc);
                $distancia = abs((int) round((float) $nc->total_pagar * 100) - $objetivo);
                if ($distancia < $diferencia) {
                    $mejor = $base;
                    $diferencia = $distancia;
                }
                if ($distancia === 0) {
                    break;
                }
            }
            $linea->update(['precio_unitario' => number_format($mejor / 100, 2, '.', '')]);
            $this->borradores->recalcular($nc);
            // La estimación de arriba descarta lo evidente; esto mide el total definitivo.
            // Lanzar acá revierte la transacción entera: ni borrador ni vínculo.
            $this->frenarSiNoAlcanza($original, Dinero::redondear($nc->monto_total_operacion ?? '0'));
            $ajuste->update(['nc_dte_id' => $nc->id]);

            return $nc;
        });
    }

    /**
     * Sala a la que se propone emitir la NC del ajuste: la MÁS USADA en las NC de pronto
     * pago del cliente ya aceptadas por Hacienda (en Calleja, la Bodega de Oficina
     * Central); empate, la más reciente. Un borrador suelto a otra sala no la mueve. Sin
     * ninguna aceptada, la de la última NC de pronto pago vigente; si tampoco, ninguna.
     */
    public function salaPropuesta(int $clienteId): ?int
    {
        $aceptadas = $this->notasDelCliente($clienteId)
            ->where('estado', EstadoDte::Aceptado->value)
            ->whereNotNull('cliente_sucursal_id')
            ->get(['id', 'cliente_sucursal_id', 'estado', 'sello_recepcion', 'fecha_procesamiento_mh', 'ambiente'])
            ->filter(fn (Dte $nc) => $nc->aceptadoRealmentePorMh());

        if ($aceptadas->isNotEmpty()) {
            return (int) $aceptadas->groupBy('cliente_sucursal_id')
                ->sortByDesc(fn ($grupo) => [$grupo->count(), $grupo->max('id')])
                ->keys()->first();
        }

        $ultima = $this->notasDelCliente($clienteId)->latest('id')->value('cliente_sucursal_id');

        return $ultima !== null ? (int) $ultima : null;
    }

    /**
     * Total bruto (antes de retención) que tendrá la NC del ajuste si se relaciona con
     * este CCF. Es una ESTIMACIÓN para ofrecer solo los CCF que alcanzan: el importe del
     * TXT es neto de la retención del 1 %, que la NC hereda del CCF cuando su base supera
     * el umbral. El total exacto lo mide crear() con el motor fiscal.
     */
    public function brutoEstimado(CobroAjuste $ajuste, Dte $ccf): string
    {
        $neto = abs((float) $ajuste->monto);
        $base = $neto / 1.12;
        $retiene = (bool) $ccf->aplica_retencion_iva
            && $base > (float) config('dte.retencion_iva_umbral', 100);

        $bruto = $retiene ? $base * 1.13 : $neto;

        return number_format(ceil(round($bruto * 100, 6)) / 100, 2, '.', '');
    }

    /**
     * Marca cada opción del buscador con su saldo y si alcanza para la NC del ajuste.
     *
     * @param  array<int, array<string, mixed>>  $opciones  forma de BusquedaCcfParaNotaCredito::opciones()
     * @param  Collection<int, Dte>  $ccfs
     * @return array<int, array<string, mixed>>
     */
    public function anotarSaldos(array $opciones, Collection $ccfs, CobroAjuste $ajuste): array
    {
        $saldos = app(SaldoMontoCcf::class)->saldos($ccfs);
        $porId = $ccfs->keyBy('id');

        return array_map(function (array $o) use ($saldos, $porId, $ajuste) {
            $ccf = $porId[$o['id']] ?? null;
            if ($ccf === null) {
                return $o;
            }
            $necesita = $this->brutoEstimado($ajuste, $ccf);
            $saldo = $saldos[$ccf->id];

            return $o + [
                'saldo' => number_format((float) $saldo, 2, '.', ''),
                'monto_nota' => $necesita,
                'alcanza' => Dinero::comparar($saldo, $necesita) >= 0,
            ];
        }, $opciones);
    }

    private function frenarSiNoAlcanza(Dte $ccf, string $total): void
    {
        $saldo = app(SaldoMontoCcf::class)->saldo($ccf);
        if (Dinero::comparar($total, $saldo) > 0) {
            throw ValidationException::withMessages(['dte_relacionado_id' => SaldoMontoCcf::mensaje($ccf, $saldo, $total)]);
        }
    }

    public function vincular(CobroAjuste $ajuste, int $ncId): void
    {
        DB::transaction(function () use ($ajuste, $ncId) {
            $ajuste = $this->bloquear($ajuste);
            $this->validarPendiente($ajuste);
            $nc = $this->notasDelCliente($ajuste->cliente_id)->whereKey($ncId)->lockForUpdate()->first();
            if (! $nc || $this->otrosAjustes($ajuste)->where('nc_dte_id', $ncId)->exists()) {
                throw ValidationException::withMessages(['nc_dte_id' => 'Seleccione una NC de pronto pago vigente del mismo cliente que no esté usada por otra deducción.']);
            }
            $ajuste->update(['nc_dte_id' => $nc->id]);
        });
    }

    public function desvincular(CobroAjuste $ajuste): void
    {
        DB::transaction(function () use ($ajuste) {
            $this->bloquear($ajuste)->update(['nc_dte_id' => null]);
        });
    }

    public function avisoImporte(Dte $nc): ?string
    {
        $ajuste = CobroAjuste::where('nc_dte_id', $nc->id)->where('estado', '!=', 'descartado')->first();
        $diferencia = $ajuste ? abs((int) round(abs((float) $ajuste->monto) * 100) - (int) round((float) $nc->total_pagar * 100)) : 0;

        return $diferencia > 0
            ? 'El total a pagar difiere del TXT en $'.number_format($diferencia / 100, 2, '.', '').($diferencia === 1 ? ' por redondeo' : '. Revise el importe de la nota.')
            : null;
    }
}
