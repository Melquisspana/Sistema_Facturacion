<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\RolEnSalida;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El viaje que se arma solo (Melqui, 10/11/2026). Es una salida (`salidas_ruta`) con
 * `automatica` y propósito «las dos»: saca pedidos y lleva entregas.
 *
 * La regla, al sacar un pedido ({@see paraPedido()}):
 *
 *   1. Si el vendedor tiene un viaje que sigue sumando ({@see sigueSumando()}) y la sala es
 *      de una de sus zonas o de un departamento igual o vecino al de sus salas, el pedido va
 *      ahí. Una sala sin zona ni departamento también va ahí, y la tienda nueva (sin sala
 *      todavía): no hay con qué decir que no.
 *   2. Si no, se abre otro viaje, de hoy, con el vendedor adentro. Su viaje anterior deja de
 *      sumar y queda abierto solo para entregar.
 *
 * Sigue sumando mientras no diga «No, vuelvo» al terminar el día ({@see terminarDia()}) y
 * tenga un pedido de hoy o del día anterior: un día entero sin pedidos corta el viaje. El
 * domingo no cuenta: del sábado al lunes sigue siendo seguido. Cada vendedor tiene el suyo;
 * cualquiera puede entregar sus CCF.
 *
 * La zona de una sala es su ruta; si no tiene, la que propone la cobertura
 * ({@see PropuestaRutas}). Las rutas quedan como catálogo de zonas.
 *
 * Se cierra solo ({@see cerrarTerminados()}, cada mañana) cuando ya no suma y todos sus
 * pedidos están facturados y entregados (marcados por quien sea o confirmados por el albarán
 * del supermercado; la regla es la de {@see PasoPedido}, la misma de la pestaña «Viajes»),
 * o tras {@see self::DIAS_SIN_MOVIMIENTO} días sin
 * movimiento (un pedido que nunca sale de la fábrica no lo deja abierto para siempre). No se
 * cierra por fecha.
 */
class Viajes
{
    /** Días sin pedidos ni entregas tras los que el viaje se cierra aunque falte algo. */
    public const DIAS_SIN_MOVIMIENTO = 10;

    /**
     * Departamentos que se tocan (códigos del catálogo del MH). La zona vecina se mide así:
     * un viaje real cubre unos 4 departamentos seguidos; oriente y occidente van aparte.
     */
    private const VECINOS = [
        '01' => ['02', '03'],
        '02' => ['01', '03', '04', '05'],
        '03' => ['01', '02', '05'],
        '04' => ['02', '05', '06', '07', '09'],
        '05' => ['02', '03', '04', '06', '08'],
        '06' => ['04', '05', '07', '08'],
        '07' => ['04', '06', '08', '09', '10'],
        '08' => ['05', '06', '07', '10', '11'],
        '09' => ['04', '07', '10'],
        '10' => ['07', '08', '09', '11'],
        '11' => ['08', '10', '12'],
        '12' => ['11', '13', '14'],
        '13' => ['12', '14'],
        '14' => ['12', '13'],
    ];

    public function __construct(
        private readonly PropuestaRutas $propuesta,
        private readonly EntregasCcf $entregas,
        private readonly PasoPedido $pasos,
    ) {}

    /** La zona de la sala: su ruta o, si no tiene, la que propone la cobertura. */
    public function zona(ClienteSucursal $sala): ?Ruta
    {
        return $sala->ruta_id !== null ? Ruta::find($sala->ruta_id) : $this->propuesta->para($sala);
    }

    /**
     * Solo el id de la zona, sin consultar por sala: para listas. La sala tiene que traer
     * `ruta_id`, `distrito_id` y `departamento_id`.
     */
    public function zonaId(ClienteSucursal $sala): ?int
    {
        return $sala->ruta_id !== null ? (int) $sala->ruta_id : $this->propuesta->para($sala)?->id;
    }

    /**
     * El viaje donde va el pedido nuevo de esta sala: el que sigue o uno nuevo. Llamar dentro
     * de la transacción que guarda el pedido. Con la persona bloqueada, dos pedidos a la vez
     * del mismo vendedor no abren dos viajes. Sin `$sala`, la tienda nueva.
     */
    public function paraPedido(PersonalRuta $persona, ?ClienteSucursal $sala, User $usuario): SalidaRuta
    {
        return DB::transaction(function () use ($persona, $sala, $usuario) {
            PersonalRuta::whereKey($persona->id)->lockForUpdate()->firstOrFail();
            $hoy = HoraNegocio::fechaHoy();
            $zona = $sala ? $this->zona($sala) : null;

            $actual = $this->abierto($persona, lock: true);
            if ($actual !== null && $actual->suma_pedidos && $this->sigueSumando($actual, $hoy) && $this->esDeSuZona($actual, $sala, $zona)) {
                return $actual;
            }
            if ($actual !== null && $actual->suma_pedidos) {
                $this->dejarDeSumar($actual, $usuario, 'otra_zona');
            }

            $viaje = SalidaRuta::create([
                'ruta_id' => $zona?->id,
                'fecha_inicio' => $hoy,
                'estado' => EstadoSalidaRuta::EnCurso,
                'proposito' => PropositoSalida::Ambos,
                'automatica' => true,
                'suma_pedidos' => true,
                'created_by' => $usuario->id,
            ]);
            $viaje->participantes()->create(['rutas_personal_id' => $persona->id, 'rol' => RolEnSalida::Responsable]);
            activity('salida_ruta')
                ->performedOn($viaje)
                ->causedBy($usuario)
                ->withProperties(['desde' => 'sacar_pedido', 'sala' => $sala?->id, 'zona' => $zona?->nombre, 'vendedor' => $persona->nombre])
                ->log('se abrió solo el viaje');

            return $viaje;
        });
    }

    /** El viaje en curso más nuevo de la persona, sume pedidos o ya no. */
    public function abierto(PersonalRuta $persona, bool $lock = false): ?SalidaRuta
    {
        return SalidaRuta::automaticas()->enEstado(EstadoSalidaRuta::EnCurso)
            ->where('proposito', '!=', PropositoSalida::Entregar->value)
            ->whereHas('participantes', fn ($q) => $q->where('rutas_personal_id', $persona->id))
            ->orderByDesc('fecha_inicio')->orderByDesc('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /**
     * ¿Todavía recibe pedidos? Sin «No, vuelvo» y con un pedido (o su apertura) de hoy o del
     * día anterior. El domingo no se cuenta: el lunes, el día anterior es el sábado.
     */
    public function sigueSumando(SalidaRuta $viaje, ?string $hoy = null): bool
    {
        $ayer = CarbonImmutable::parse($hoy ?? HoraNegocio::fechaHoy())->subDay();
        $ayer = ($ayer->isSunday() ? $ayer->subDay() : $ayer)->toDateString();
        $ultimo = $viaje->pedidos()->max('fecha');
        $ultimo = $ultimo !== null ? CarbonImmutable::parse($ultimo)->toDateString() : $viaje->fecha_inicio->toDateString();

        return $viaje->suma_pedidos && $viaje->estado === EstadoSalidaRuta::EnCurso && $ultimo >= $ayer;
    }

    /** Día del viaje: el 1 es el día en que se abrió. */
    public function dia(SalidaRuta $viaje, ?string $hoy = null): int
    {
        return (int) $viaje->fecha_inicio->copy()->startOfDay()->diffInDays(CarbonImmutable::parse($hoy ?? HoraNegocio::fechaHoy())) + 1;
    }

    /**
     * «Terminar el día»: ¿mañana sigue en la misma zona? Si no, el viaje deja de sumar pedidos y
     * queda abierto para entregar hasta que todo esté entregado.
     */
    public function terminarDia(SalidaRuta $viaje, bool $sigue, User $usuario): void
    {
        DB::transaction(function () use ($viaje, $sigue, $usuario) {
            $viaje = SalidaRuta::lockForUpdate()->findOrFail($viaje->id);
            if (! $sigue && $viaje->suma_pedidos) {
                $viaje->update(['suma_pedidos' => false]);
            }
            activity('salida_ruta')
                ->performedOn($viaje)
                ->causedBy($usuario)
                ->withProperties(['sigue' => $sigue, 'dia' => HoraNegocio::fechaHoy()])
                ->log($sigue ? 'terminó el día: sigue en la zona' : 'terminó el día: no sigue en la zona');
        });
    }

    /**
     * La tarea de cada mañana. Los viajes que pasaron un día sin pedidos dejan de sumar; los
     * que ya no suman se cierran si todo está entregado o si no se movieron en
     * {@see self::DIAS_SIN_MOVIMIENTO} días, y se cancelan si se quedaron sin nada. Cerrar es
     * finalizar. El viaje no se liquida: el dinero se entrega con el PDF del día firmado.
     *
     * @return array{dejaronDeSumar: int, finalizados: int, cancelados: int}
     */
    public function cerrarTerminados(): array
    {
        $hoy = HoraNegocio::fechaHoy();
        $cuenta = ['dejaronDeSumar' => 0, 'finalizados' => 0, 'cancelados' => 0];
        $ids = SalidaRuta::automaticas()->enEstado(EstadoSalidaRuta::EnCurso)
            ->where('proposito', '!=', PropositoSalida::Entregar->value)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hoy, &$cuenta) {
                $viaje = SalidaRuta::lockForUpdate()->find($id);
                if ($viaje === null || $viaje->estado !== EstadoSalidaRuta::EnCurso || ! $viaje->esViaje()) {
                    return;
                }
                if ($viaje->suma_pedidos && ! $this->sigueSumando($viaje, $hoy)) {
                    $this->dejarDeSumar($viaje, null, 'sin_pedidos');
                    $cuenta['dejaronDeSumar']++;
                }
                if ($viaje->suma_pedidos) {
                    return;
                }
                if ($this->entregas->cancelarAutomaticaVacia($viaje)) {
                    $cuenta['cancelados']++;

                    return;
                }

                $ultimo = $this->ultimoMovimiento($viaje);
                $quieto = $ultimo < CarbonImmutable::parse($hoy)->subDays(self::DIAS_SIN_MOVIMIENTO)->toDateString();
                $entregado = $this->todoEntregado($viaje);
                if (! $entregado && ! $quieto) {
                    return;
                }
                $viaje->finalizar($ultimo);
                activity('salida_ruta')
                    ->performedOn($viaje)
                    ->withProperties(['estado_anterior' => EstadoSalidaRuta::EnCurso->value, 'estado_nuevo' => EstadoSalidaRuta::Finalizada->value,
                        'motivo' => $entregado ? 'todo_entregado' : 'sin_movimiento', 'ultimo_movimiento' => $ultimo])
                    ->log($entregado ? 'se cerró solo el viaje: todo entregado' : 'se cerró solo el viaje: sin movimiento');
                $cuenta['finalizados']++;
            });
        }

        return $cuenta;
    }

    /**
     * ¿Todo facturado y entregado? Cada pedido vigente llegó al final ({@see PasoPedido}:
     * entregado, o la fábrica no lo trae) y cada CCF que se anotó en el viaje sin salir de un
     * pedido suyo también se entregó. Entregado es marcado en esta salida o en otra, por quien
     * sea, o confirmado por el albarán de entrega del supermercado. Es la misma regla que
     * muestra la pestaña «Viajes» ({@see TableroViajes}): no pueden discrepar.
     */
    public function todoEntregado(SalidaRuta $viaje): bool
    {
        $pedidos = $viaje->pedidos()->vigentes()
            ->with(['envios' => fn ($q) => $q->vigentes(), 'envios.dte:id,estado,deleted_at,fecha_emision,numero_orden_compra'])->get();
        $sueltos = Dte::withTrashed()->whereKey($viaje->entregas()->pluck('dte_id')->all())->get(['id', 'fecha_emision', 'numero_orden_compra']);
        $entregados = $this->pasos->entregados($pedidos->flatMap->envios->pluck('dte')->filter()->concat($sueltos)->unique('id'));

        return $pedidos->every(fn (PedidoSala $p) => PasoPedido::terminado($this->pasos->de($p, $entregados)[0]))
            && $sueltos->every(fn (Dte $d) => isset($entregados[$d->id]));
    }

    /** El último día con algo: la apertura, un pedido o un resultado de entrega. */
    private function ultimoMovimiento(SalidaRuta $viaje): string
    {
        return collect([
            $viaje->fecha_inicio->toDateString(),
            $viaje->pedidos()->max('fecha'),
            $viaje->entregas()->max('fecha_resultado'),
        ])->filter()->map(fn ($f) => CarbonImmutable::parse($f)->toDateString())->max();
    }

    /**
     * ¿La sala es de una zona del viaje, o de un departamento igual o vecino al de sus salas?
     * Sin zona ni departamento (o la tienda nueva, sin sala) no hay con qué separarla: se suma.
     */
    private function esDeSuZona(SalidaRuta $viaje, ?ClienteSucursal $sala, ?Ruta $zona): bool
    {
        if ($sala === null) {
            return true;
        }
        $pedidos = $viaje->pedidos()->vigentes()->with('sala.departamento:id,codigo')->get(['id', 'ruta_id', 'cliente_sucursal_id']);
        $zonas = $pedidos->pluck('ruta_id')->push($viaje->ruta_id)->filter()->unique();
        if ($zona !== null && $zonas->contains($zona->id)) {
            return true;
        }

        $codigo = $sala->departamento()->value('codigo');
        if ($codigo === null) {
            return $zona === null;
        }
        $departamentos = $this->departamentos($pedidos);
        if ($departamentos->isEmpty()) {
            return $zona === null || $zonas->isEmpty();
        }

        return $departamentos->contains(fn (string $d) => $d === $codigo || in_array($codigo, self::VECINOS[$d] ?? [], true));
    }

    /**
     * @param  Collection<int, PedidoSala>  $pedidos
     * @return Collection<int, string>
     */
    private function departamentos(Collection $pedidos): Collection
    {
        return $pedidos->map(fn (PedidoSala $p) => $p->sala?->departamento?->codigo)->filter()->unique()->values();
    }

    private function dejarDeSumar(SalidaRuta $viaje, ?User $usuario, string $motivo): void
    {
        $viaje->update(['suma_pedidos' => false]);
        activity('salida_ruta')
            ->performedOn($viaje)
            ->causedBy($usuario)
            ->withProperties(['motivo' => $motivo])
            ->log('el viaje dejó de sumar pedidos');
    }
}
