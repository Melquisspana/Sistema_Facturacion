<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Support\IdentidadPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qué CCF hay que entregar, qué lleva cada salida y qué pasó con cada uno. Toda la regla
 * vive acá: controladores y vistas no la repiten.
 *
 * ─────────────────────────── Pendiente de entregar ───────────────────────────
 *
 * Un CCF está pendiente cuando:
 *
 *   · es un CCF (03) aceptado de verdad por Hacienda y no archivado, del AMBIENTE
 *     OPERATIVO de esta instalación: producción en el servidor, pruebas en desarrollo
 *     (el mismo criterio del listado de Facturación), para que se pueda probar;
 *   · se emitió desde `rutas.entregas_desde` (lo anterior se entregó antes de que
 *     existiera este control y no tiene registro);
 *   · nadie registró que se entregó;
 *   · no tiene albarán de ENTREGA inequívoco ({@see AlbaranLocalizador}): en Calleja, que
 *     caiga el albarán ya prueba la entrega aunque nadie la haya marcado;
 *   · y no está sin registrar en otra salida abierta —ya va en camino—.
 *
 * Un «no entregado» NO saca al CCF de pendientes: vuelve solo en la próxima salida.
 */
class EntregasCcf
{
    public function __construct(private readonly AlbaranLocalizador $albaranes) {}

    // ================================================================ pendientes

    /** @return Collection<int, Dte> */
    public function pendientesDeRuta(Ruta $ruta): Collection
    {
        $salas = $ruta->sucursales()->where('activo', true)->pluck('id');

        if ($salas->isEmpty()) {
            return collect();
        }

        $candidatos = $this->baseCcf()
            ->whereIn('cliente_sucursal_id', $salas)
            ->whereDate('fecha_emision', '>=', $this->desde())
            ->whereNotIn('id', $this->idsEntregados())
            ->whereNotIn('id', $this->idsEnCamino())
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();

        return $this->sinAlbaranDeEntrega($candidatos);
    }

    /**
     * Cuántos CCF pendientes y por cuánto, por ruta activa. Para el tablero.
     *
     * @return array<int, array{cantidad: int, monto: float}>
     */
    public function pendientesPorRuta(): array
    {
        $resumen = [];

        foreach (Ruta::activas()->get() as $ruta) {
            $pendientes = $this->pendientesDeRuta($ruta);
            $resumen[$ruta->id] = [
                'cantidad' => $pendientes->count(),
                'monto' => round((float) $pendientes->sum('total_pagar'), 2),
            ];
        }

        return $resumen;
    }

    // ================================================================ la salida

    /** Agrega a la salida los pendientes de su ruta. Devuelve cuántos agregó. */
    public function cargarPendientes(SalidaRuta $salida, ?User $usuario = null): int
    {
        $this->exigirAbierta($salida);

        return DB::transaction(function () use ($salida) {
            $yaEnLaSalida = $salida->entregas()->pluck('dte_id')->all();
            $agregados = 0;

            foreach ($this->pendientesDeRuta($salida->ruta) as $dte) {
                if (in_array($dte->id, $yaEnLaSalida, true)) {
                    continue;
                }

                $salida->entregas()->create([
                    'dte_id' => $dte->id,
                    'cliente_sucursal_id' => $dte->cliente_sucursal_id,
                ]);
                $agregados++;
            }

            return $agregados;
        });
    }

    /**
     * Alta manual por número de control, de cualquier sala. No exige la fecha de corte ni
     * que la sala sea de la ruta: es justamente para lo que se sale de la regla.
     *
     * @throws ValidationException
     */
    public function agregar(SalidaRuta $salida, string $numeroControl, ?User $usuario = null): SalidaRutaEntrega
    {
        $this->exigirAbierta($salida);

        $dte = IdentidadPpq::dteLocal($numeroControl);

        $motivo = match (true) {
            $dte === null => 'No hay ningún documento con ese número de control.',
            $dte->tipo_dte?->value !== '03' => 'Ese documento no es un CCF.',
            ! $this->baseCcf()->whereKey($dte->id)->exists() => 'Ese CCF no está aceptado por Hacienda en este ambiente (borrador, rechazado, invalidado o de otro ambiente).',
            $salida->entregas()->where('dte_id', $dte->id)->exists() => 'Ese CCF ya está en esta salida.',
            in_array($dte->id, $this->idsEntregados()->all(), true) => 'Ese CCF ya consta como entregado.',
            $this->albaranes->paraUno($dte->id, $dte->numero_orden_compra)->estaVinculado() => 'Ese CCF ya tiene albarán de entrega: consta como entregado.',
            in_array($dte->id, $this->idsEnCamino()->all(), true) => 'Ese CCF ya va sin registrar en otra salida abierta.',
            default => null,
        };

        if ($motivo !== null) {
            throw ValidationException::withMessages(['numero_control' => $motivo]);
        }

        return $salida->entregas()->create([
            'dte_id' => $dte->id,
            'cliente_sucursal_id' => $dte->cliente_sucursal_id,
        ]);
    }

    /** Quita un CCF de la salida. Solo si nadie registró nada y la salida sigue abierta. */
    public function quitar(SalidaRutaEntrega $entrega): void
    {
        $this->exigirAbierta($entrega->salida);

        if (! $entrega->estaPendiente()) {
            throw ValidationException::withMessages([
                'entrega' => 'Ese CCF ya tiene un resultado registrado. Deshacelo primero si fue un error.',
            ]);
        }

        $entrega->delete();
    }

    // ================================================================ registrar

    /**
     * Anota qué pasó con un CCF.
     *
     * @param  array{resultado: string, entregado_por_id?: int|string|null, trae_nota_averia?: bool|string|null, motivo_no_entrega?: string|null, nota?: string|null, fecha_resultado?: string|null}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(SalidaRutaEntrega $entrega, array $datos, ?User $usuario, OrigenRegistroEntrega $origen): SalidaRutaEntrega
    {
        $salida = $entrega->salida;
        $this->exigirRegistrable($salida);

        $resultado = ResultadoEntrega::tryFrom((string) ($datos['resultado'] ?? ''))
            ?? throw ValidationException::withMessages(['resultado' => 'Indicá si se entregó o no.']);

        $comun = [
            'resultado' => $resultado,
            'fecha_resultado' => filled($datos['fecha_resultado'] ?? null) ? Carbon::parse($datos['fecha_resultado']) : now(),
            'origen_registro' => $origen,
            'registrado_por' => $usuario?->id,
        ];

        if ($resultado === ResultadoEntrega::Entregado) {
            $personaId = (int) ($datos['entregado_por_id'] ?? 0);

            if (! $salida->participantes()->where('rutas_personal_id', $personaId)->exists()) {
                throw ValidationException::withMessages([
                    'entregado_por_id' => 'Elegí quién lo entregó entre las personas que van en la salida.',
                ]);
            }

            $entrega->update($comun + [
                'entregado_por_id' => $personaId,
                'trae_nota_averia' => filter_var($datos['trae_nota_averia'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'motivo_no_entrega' => null,
                'nota' => null,
            ]);

            return $entrega;
        }

        $motivo = MotivoNoEntrega::tryFrom((string) ($datos['motivo_no_entrega'] ?? ''))
            ?? throw ValidationException::withMessages(['motivo_no_entrega' => 'Elegí por qué no se entregó.']);

        $nota = trim((string) ($datos['nota'] ?? '')) ?: null;

        if ($motivo->exigeNota() && $nota === null) {
            throw ValidationException::withMessages(['nota' => 'Con «Otro», escribí qué pasó.']);
        }

        $entrega->update($comun + [
            'motivo_no_entrega' => $motivo,
            'nota' => $nota,
            'entregado_por_id' => null,
            'trae_nota_averia' => false,
        ]);

        return $entrega;
    }

    /** Toda la sala entregada, por la misma persona. Devuelve cuántos marcó. */
    public function registrarSala(SalidaRuta $salida, int $salaId, int $entregadoPorId, ?User $usuario, OrigenRegistroEntrega $origen): int
    {
        return DB::transaction(function () use ($salida, $salaId, $entregadoPorId, $usuario, $origen) {
            $pendientes = $salida->entregas()->pendientes()->where('cliente_sucursal_id', $salaId)->get();

            foreach ($pendientes as $entrega) {
                $entrega->setRelation('salida', $salida);
                $this->registrar($entrega, [
                    'resultado' => ResultadoEntrega::Entregado->value,
                    'entregado_por_id' => $entregadoPorId,
                ], $usuario, $origen);
            }

            return $pendientes->count();
        });
    }

    /** Vuelve a «sin registrar»: corrige un registro equivocado. */
    public function deshacer(SalidaRutaEntrega $entrega): void
    {
        if ($entrega->salida->estado === EstadoSalidaRuta::Cancelada) {
            throw ValidationException::withMessages(['entrega' => 'La salida está cancelada: no se corrige nada en ella.']);
        }

        $entrega->update([
            'resultado' => null,
            'motivo_no_entrega' => null,
            'nota' => null,
            'trae_nota_averia' => false,
            'entregado_por_id' => null,
            'fecha_resultado' => null,
            'origen_registro' => null,
            'registrado_por' => null,
        ]);
    }

    // ================================================================ lectura

    /**
     * Qué dice el albarán de cada CCF de estas entregas. Una fila sin resultado propio y con
     * albarán de entrega cuenta como entregada.
     *
     * @param  Collection<int, SalidaRutaEntrega>  $entregas  con `dte` cargado
     * @return array<int, ResolucionAlbaran> dte_id => resolución
     */
    public function resolucionesAlbaran(Collection $entregas): array
    {
        $dtes = $entregas->pluck('dte')->filter();
        [$porDte, $porOrden] = $this->albaranes->indices($dtes->pluck('id')->all(), $dtes->pluck('numero_orden_compra')->all());

        $resoluciones = [];
        foreach ($dtes as $dte) {
            $resoluciones[$dte->id] = $this->albaranes->elegir($porDte, $porOrden, $dte->id, $dte->numero_orden_compra);
        }

        return $resoluciones;
    }

    /**
     * Resumen de una salida: total, entregados (incluye los confirmados por albarán), no
     * entregados y sin registrar.
     *
     * @param  Collection<int, SalidaRutaEntrega>  $entregas
     * @param  array<int, ResolucionAlbaran>  $resoluciones
     * @return array{total: int, entregados: int, no_entregados: int, sin_registrar: int}
     */
    public function resumen(Collection $entregas, array $resoluciones): array
    {
        $porAlbaran = fn (SalidaRutaEntrega $e) => $e->estaPendiente() && ($resoluciones[$e->dte_id] ?? null)?->estaVinculado();

        return [
            'total' => $entregas->count(),
            'entregados' => $entregas->filter(fn ($e) => $e->resultado === ResultadoEntrega::Entregado || $porAlbaran($e))->count(),
            'no_entregados' => $entregas->filter(fn ($e) => $e->resultado === ResultadoEntrega::NoEntregado)->count(),
            'sin_registrar' => $entregas->filter(fn ($e) => $e->estaPendiente() && ! $porAlbaran($e))->count(),
        ];
    }

    /**
     * Última entrega conocida en cada sala: la más reciente entre lo que registró alguien y
     * lo que prueba un albarán de entrega vinculado a un CCF de esa sala.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, Carbon> sala_id => fecha
     */
    public function ultimaVisitaPorSala(array $salaIds): array
    {
        if ($salaIds === []) {
            return [];
        }

        $fechas = [];

        SalidaRutaEntrega::query()
            ->entregadas()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->groupBy('cliente_sucursal_id')
            ->selectRaw('cliente_sucursal_id, MAX(fecha_resultado) as ultima')
            ->get()
            ->each(function ($fila) use (&$fechas) {
                $fechas[$fila->cliente_sucursal_id] = Carbon::parse($fila->ultima)->startOfDay();
            });

        // Albaranes de entrega vinculados por `dte_id`: es el vínculo explícito. La orden de
        // compra no se usa acá; basta con que el albarán diga de qué CCF es.
        PpqAlbaran::query()
            ->join('dtes', 'dtes.id', '=', 'ppq_albaranes.dte_id')
            ->whereIn('dtes.cliente_sucursal_id', $salaIds)
            ->whereNotNull('ppq_albaranes.fecha_albaran')
            ->get(['ppq_albaranes.*', 'dtes.cliente_sucursal_id as sala_id'])
            ->filter(fn (PpqAlbaran $a) => $a->esDeEntrega())
            ->each(function (PpqAlbaran $a) use (&$fechas) {
                $fecha = $a->fecha_albaran->copy()->startOfDay();
                $actual = $fechas[$a->sala_id] ?? null;
                if ($actual === null || $fecha->gt($actual)) {
                    $fechas[$a->sala_id] = $fecha;
                }
            });

        return $fechas;
    }

    public function desde(): Carbon
    {
        return Carbon::parse((string) config('rutas.entregas_desde'))->startOfDay();
    }

    // ================================================================ apoyo

    /** @return Builder<Dte> */
    private function baseCcf(): Builder
    {
        return Dte::query()
            ->where('tipo_dte', '03')
            ->where('ambiente', (string) (config('rutas.ambiente_ccf') ?: config('dte.ambiente')))
            ->noArchivados()
            ->aceptadoRealMh();
    }

    /** CCF con al menos una entrega registrada. */
    private function idsEntregados(): Collection
    {
        return SalidaRutaEntrega::query()->entregadas()->distinct()->pluck('dte_id');
    }

    /** CCF sin registrar en una salida todavía abierta: ya van en camino. */
    private function idsEnCamino(): Collection
    {
        return SalidaRutaEntrega::query()
            ->pendientes()
            ->whereHas('salida', fn ($q) => $q->abiertas())
            ->distinct()
            ->pluck('dte_id');
    }

    /**
     * @param  Collection<int, Dte>  $dtes
     * @return Collection<int, Dte>
     */
    private function sinAlbaranDeEntrega(Collection $dtes): Collection
    {
        if ($dtes->isEmpty()) {
            return $dtes;
        }

        [$porDte, $porOrden] = $this->albaranes->indices($dtes->pluck('id')->all(), $dtes->pluck('numero_orden_compra')->all());

        return $dtes
            ->reject(fn (Dte $d) => $this->albaranes->elegir($porDte, $porOrden, $d->id, $d->numero_orden_compra)->estaVinculado())
            ->values();
    }

    private function exigirAbierta(SalidaRuta $salida): void
    {
        if ($salida->estado->esTerminal()) {
            throw ValidationException::withMessages([
                'salida' => 'La salida ya está '.mb_strtolower($salida->estado->label()).': no se le agregan ni quitan CCF.',
            ]);
        }
    }

    private function exigirRegistrable(SalidaRuta $salida): void
    {
        if (! in_array($salida->estado, [EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada], true)) {
            throw ValidationException::withMessages([
                'salida' => $salida->estado === EstadoSalidaRuta::Planificada
                    ? 'La salida todavía no inició: iniciala para registrar entregas.'
                    : 'La salida está cancelada: no se registran entregas en ella.',
            ]);
        }
    }
}
