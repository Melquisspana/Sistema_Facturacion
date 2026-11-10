<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Dte;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Policies\SalidaRutaPolicy;
use App\Support\HoraNegocio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Por entregar» (Melqui, 06/11/2026): el vendedor marca un CCF sin que nadie arme la salida.
 * Qué está por entregar lo decide {@see EntregasCcf::porEntregar()}; registrar la entrega,
 * {@see EntregasCcf::registrar()}. Acá solo se elige en qué salida queda anotada:
 *
 *   1. si el CCF ya va sin registrar en una salida en curso (una formal a San Miguel, por
 *      ejemplo), en esa; si es formal y el vendedor no va en ella, no se marca;
 *   2. si no, en el viaje del pedido que lo trajo, si sigue en curso; si no, en el viaje en
 *      curso del que entrega (10/11/2026: cualquier vendedor entrega cualquier CCF);
 *   3. si no, en la salida formal de entrega en curso de la ruta de su sala donde va el
 *      vendedor (aunque sea un viaje que empezó antes); si no, en la automática de hoy;
 *   4. si no hay, en una que se arma sola: en curso, para entregar, de hoy, a nombre del
 *      vendedor y con él adentro. No carga los demás pendientes: lleva solo lo que se marca.
 *
 * A una formal nunca se suma a nadie: ir en una salida da el cierre del día y la liquidación
 * ({@see SalidaRutaPolicy}), sea responsable o acompañante. A la automática (y al viaje) sí,
 * como acompañante. Las automáticas de días anteriores se finalizan solas
 * ({@see self::finalizarAutomaticasViejas()}) y quedan para que la oficina las liquide.
 */
class PorEntregar
{
    public const SIN_RUTA = 'Esta sala no tiene ruta: pedile a la oficina que se la asigne.';

    public function __construct(private readonly EntregasCcf $entregas, private readonly Viajes $viajes) {}

    /**
     * La fila de la salida donde se va a anotar este CCF, con el vendedor ya adentro. Llamar
     * dentro de una transacción junto con el registro: si el registro falla, no queda una
     * salida armada de más.
     *
     * @throws ValidationException
     */
    public function entregaPara(Dte $dte, PersonalRuta $persona, User $usuario): SalidaRutaEntrega
    {
        return DB::transaction(function () use ($dte, $persona, $usuario) {
            $rutaId = $dte->clienteSucursal?->ruta_id;
            if ($rutaId !== null) {
                // Con la ruta bloqueada, dos vendedores que marcan a la vez no arman dos salidas.
                Ruta::whereKey($rutaId)->lockForUpdate()->firstOrFail();
                $this->finalizarAutomaticasViejas($rutaId);
            }

            $entrega = $this->enCamino($dte, $persona)
                ?? $this->enElViaje($dte, $persona, $usuario)
                ?? $this->enLaDeHoy($dte, $rutaId ?? throw ValidationException::withMessages(['entrega' => self::SIN_RUTA]), $persona, $usuario);
            $this->sumar($entrega->salida, $persona, $usuario);

            return $entrega;
        });
    }

    /**
     * Cierra las automáticas de entregar de días anteriores (de una ruta, o de todas). La vacía (sin
     * entregas, gastos ni cierres) se cancela; las demás se finalizan con el regreso el día en
     * que se armaron y quedan para que la oficina haga «Terminar ruta»
     * ({@see LiquidacionSalida::liquidar()} acepta una automática finalizada). No las liquida.
     * Lo llama el planificador cada mañana y «Por entregar» al marcar.
     *
     * @return array{finalizadas: int, canceladas: int}
     */
    public function finalizarAutomaticasViejas(?int $rutaId = null): array
    {
        $hoy = HoraNegocio::fechaHoy();
        // Solo las de entregar: el viaje (que también saca pedido) dura varios días y se
        // cierra cuando todo está entregado ({@see Viajes::cerrarTerminados()}).
        $ids = SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)->automaticas()->where('proposito', PropositoSalida::Entregar->value)
            ->whereDate('fecha_inicio', '<', $hoy)->when($rutaId !== null, fn ($q) => $q->where('ruta_id', $rutaId))
            ->orderBy('id')->pluck('id');
        $cuenta = ['finalizadas' => 0, 'canceladas' => 0];

        foreach ($ids as $id) {
            $hecho = DB::transaction(function () use ($id) {
                $salida = SalidaRuta::lockForUpdate()->find($id);
                if ($salida === null || $salida->estado !== EstadoSalidaRuta::EnCurso || ! $salida->esAutomatica() || $salida->esViaje()) {
                    return null;
                }
                // Lo que quedó sin marcar (si la oficina le cargó CCF) no se entregó: queda libre
                // para otra salida, y «Terminar ruta» no pide motivos que ya no se pueden anotar.
                $sueltos = $salida->entregas()->pendientes()->lockForUpdate()->get();
                if ($sueltos->isNotEmpty()) {
                    $sueltos->each->delete();
                    activity('salida_ruta')->performedOn($salida)
                        ->withProperties(['dte_ids' => $sueltos->pluck('dte_id')->all(), 'desde' => 'por_entregar'])
                        ->log('quitó los CCF sin marcar al cerrarse sola');
                }
                if ($this->entregas->cancelarAutomaticaVacia($salida)) {
                    return 'canceladas';
                }
                $salida->finalizar($salida->fecha_inicio->toDateString());
                activity('salida_ruta')
                    ->performedOn($salida)
                    ->withProperties(['estado_anterior' => EstadoSalidaRuta::EnCurso->value, 'estado_nuevo' => EstadoSalidaRuta::Finalizada->value, 'desde' => 'por_entregar'])
                    ->log('se finalizó sola');

                return 'finalizadas';
            });
            if ($hecho !== null) {
                $cuenta[$hecho]++;
            }
        }

        return $cuenta;
    }

    /**
     * Sin registrar en una salida abierta: se marca en esa, si ya salió. Primero la salida y
     * después la fila, en el mismo orden de bloqueo que el resto de Rutas; si entre la
     * lectura y el bloqueo dejó de estar pendiente, sigue como si no estuviera en camino.
     */
    private function enCamino(Dte $dte, PersonalRuta $persona): ?SalidaRutaEntrega
    {
        $vista = SalidaRutaEntrega::where('dte_id', $dte->id)->pendientes()->whereHas('salida', fn ($q) => $q->abiertas())->first();
        if ($vista === null) {
            return null;
        }
        $salida = SalidaRuta::with('ruta')->lockForUpdate()->find($vista->salida_ruta_id);
        $entrega = SalidaRutaEntrega::whereKey($vista->id)->pendientes()->lockForUpdate()->first();
        if ($salida === null || $entrega === null || $salida->estado->esTerminal()) {
            return null;
        }
        $entrega->setRelation('salida', $salida);

        if ($salida->estado === EstadoSalidaRuta::Planificada) {
            throw ValidationException::withMessages(['entrega' => 'Este CCF va en la salida '.$salida->descripcionCorta()
                .', que todavía no empezó: se marca cuando la empiecen.']);
        }
        // Con el día cerrado la política no deja registrar: se dice por qué, no un 403.
        if ($salida->diaCerrado()) {
            throw ValidationException::withMessages(['entrega' => self::diaCerrado($salida)]);
        }
        if (! $salida->esAutomatica() && ! self::participa($salida, $persona)) {
            throw ValidationException::withMessages(['entrega' => self::noVa($salida)]);
        }

        return $entrega;
    }

    /**
     * El viaje donde queda la entrega (10/11/2026): el del pedido que trajo el CCF, si sigue
     * en curso, o el viaje en curso del que entrega. Null si no hay ninguno (sigue la regla
     * de la ruta). Un «No se pudo» ya anotado en ese viaje se reintenta en la misma fila.
     */
    private function enElViaje(Dte $dte, PersonalRuta $persona, User $usuario): ?SalidaRutaEntrega
    {
        $delPedido = PedidoSalaEnvio::query()->vigentes()->where('dte_id', $dte->id)
            ->join('pedidos_sala', 'pedidos_sala.id', '=', 'pedido_sala_envios.pedido_sala_id')
            ->whereNotNull('pedidos_sala.salida_ruta_id')->orderByDesc('pedido_sala_envios.id')
            ->value('pedidos_sala.salida_ruta_id');
        $viaje = $delPedido !== null ? SalidaRuta::lockForUpdate()->find($delPedido) : null;
        if ($viaje === null || $viaje->estado !== EstadoSalidaRuta::EnCurso || ! $viaje->esViaje()) {
            $viaje = $this->viajes->abierto($persona, lock: true);
        }
        if ($viaje === null) {
            return null;
        }
        if ($viaje->diaCerrado()) {
            throw ValidationException::withMessages(['entrega' => self::diaCerrado($viaje)]);
        }

        $entrega = $viaje->entregas()->where('dte_id', $dte->id)->lockForUpdate()->first();
        if ($entrega?->resultado === ResultadoEntrega::NoEntregado) {
            $this->reintentar($entrega, $usuario);
        }
        $entrega ??= $this->entregas->meterCcf($viaje, $dte->id);

        return $entrega->setRelation('salida', $viaje);
    }

    /**
     * La salida de entrega en curso de la ruta para hoy: la formal donde va el vendedor, aunque
     * haya empezado antes (un viaje de varios días a San Miguel), como cuando carga lo facturado
     * en camino ({@see EntregasCcf::cargarNuevosEnCurso()}); si no, la automática de hoy. Las
     * que ya cerraron el día de hoy no reciben más.
     */
    private function enLaDeHoy(Dte $dte, int $rutaId, PersonalRuta $persona, User $usuario): SalidaRutaEntrega
    {
        $hoy = HoraNegocio::fechaHoy();
        // Un «No se pudo entregar» ya anotado en una salida en curso donde se puede marcar hoy
        // se reintenta en ella (aunque sea de otra ruta, o de un día ya cerrado del viaje: no
        // movió dinero y el cierre de ese día no cambia). Así el vendedor no queda en dos
        // salidas con el cobro en otra liquidación.
        $conIntento = SalidaRutaEntrega::where('dte_id', $dte->id)->where('resultado', ResultadoEntrega::NoEntregado->value)
            ->whereHas('salida', fn ($q) => $q->enEstado(EstadoSalidaRuta::EnCurso))->pluck('salida_ruta_id')->map(fn ($id) => (int) $id)->all();
        $candidatas = SalidaRuta::where(fn ($q) => $q->where('ruta_id', $rutaId)->orWhereIn('id', $conIntento))->enEstado(EstadoSalidaRuta::EnCurso)
            ->whereDate('fecha_inicio', '<=', $hoy)->orderByDesc('fecha_inicio')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (SalidaRuta $s) => $s->proposito->entrega() && ! $s->diaCerrado($hoy)
                // Sin ir en ella, solo la automática de hoy de su propia ruta: a una ajena (otra
                // ruta, con su cobro y su vista) no se lo mete por un reintento.
                && ($s->esAutomatica() && $s->fecha_inicio->toDateString() === $hoy && (int) $s->ruta_id === $rutaId
                    || (! $s->esAutomatica() || $s->fecha_inicio->toDateString() === $hoy) && self::participa($s, $persona)))
            ->sortBy(fn (SalidaRuta $s) => [in_array($s->id, $conIntento, true) ? 0 : 1, $s->esAutomatica() ? 1 : 0]);
        // La automática, solo si no hay formal posible.
        $salida = $candidatas->first() ?? $this->armar($rutaId, $hoy, $usuario, $dte);

        $entrega = $salida->entregas()->where('dte_id', $dte->id)->lockForUpdate()->first();
        if ($entrega?->resultado === ResultadoEntrega::NoEntregado) {
            $this->reintentar($entrega, $usuario);
        }
        $entrega ??= $this->entregas->meterCcf($salida, $dte->id);

        return $entrega->setRelation('salida', $salida);
    }

    /**
     * Un «No se pudo entregar» ya anotado en esta salida (hoy, por él u otro, o un día
     * anterior del viaje, aunque ese día esté cerrado): el toque nuevo es un nuevo intento. La
     * fila vuelve a pendiente y se registra lo nuevo con fecha de hoy, en la misma transacción:
     * si el registro no pasa, queda como estaba. La bitácora guarda el intento anterior.
     */
    private function reintentar(SalidaRutaEntrega $entrega, User $usuario): void
    {
        $antes = ['motivo_no_entrega' => $entrega->motivo_no_entrega?->value, 'nota' => $entrega->nota,
            'fecha_resultado' => $entrega->fecha_resultado?->toDateTimeString(), 'registrado_por' => $entrega->registrado_por];
        $entrega->update([
            'resultado' => null, 'motivo_no_entrega' => null, 'nota' => null, 'fecha_resultado' => null,
            'origen_registro' => null, 'registrado_por' => null,
        ]);
        activity('salida_ruta')
            ->performedOn($entrega->salida()->first())
            ->causedBy($usuario)
            ->withProperties(['entrega' => $entrega->id, 'dte_id' => $entrega->dte_id, 'antes' => $antes])
            ->log(EntregasCcf::REINTENTO);
    }

    private function armar(int $rutaId, string $hoy, User $usuario, Dte $dte): SalidaRuta
    {
        $salida = SalidaRuta::create([
            'ruta_id' => $rutaId,
            'fecha_inicio' => $hoy,
            'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Entregar,
            'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA,
            'automatica' => true,
            'created_by' => $usuario->id,
        ]);
        activity('salida_ruta')
            ->performedOn($salida)
            ->causedBy($usuario)
            ->withProperties(['desde' => 'por_entregar', 'dte' => $dte->numero_control])
            ->log('se armó sola la salida al marcar una entrega');

        return $salida;
    }

    /**
     * Suma al vendedor a la automática, como acompañante, sin tocar a los demás (aunque
     * alguno esté inactivo). A una formal no llega sin ir en ella: lo frenan enCamino y enLaDeHoy.
     */
    private function sumar(SalidaRuta $salida, PersonalRuta $persona, User $usuario): void
    {
        if (self::participa($salida, $persona)) {
            return;
        }
        if (! $salida->esAutomatica()) {
            throw ValidationException::withMessages(['entrega' => self::noVa($salida)]);
        }

        $salida->participantes()->create(['rutas_personal_id' => $persona->id, 'rol' => RolEnSalida::Acompanante]);
        activity('salida_ruta')
            ->performedOn($salida)
            ->causedBy($usuario)
            ->withProperties(['agregados' => [$persona->nombre], 'quitados' => [], 'desde' => 'por_entregar'])
            ->log('cambió los participantes de la salida');
    }

    /**
     * Lo que mira el vendedor con su viaje en curso (10/11/2026). «Este viaje»: los CCF de sus
     * pedidos y los que ya se anotaron en él. «Toda la zona»: además, los de las salas de sus
     * zonas (la ruta de cada pedido y la del viaje), salgan o no de un pedido. Si el viaje no
     * tiene nada, solo «Toda la zona».
     *
     * @param  Collection<int, Dte>  $todos  los de {@see EntregasCcf::porEntregar()}, con su sala
     * @return array<string, Collection<int, Dte>>
     */
    public function vistas(SalidaRuta $viaje, Collection $todos): array
    {
        $delViaje = PedidoSalaEnvio::vigentes()->whereNotNull('dte_id')
            ->whereHas('pedido', fn ($q) => $q->where('salida_ruta_id', $viaje->id))->pluck('dte_id')
            ->merge($viaje->entregas()->pluck('dte_id'))->map(fn ($id) => (int) $id)->unique()->all();
        $zonas = $viaje->pedidos()->vigentes()->whereNotNull('ruta_id')->distinct()->pluck('ruta_id')
            ->push($viaje->ruta_id)->filter()->map(fn ($id) => (int) $id)->unique()->all();

        $vistas = [
            'viaje' => $todos->filter(fn (Dte $d) => in_array($d->id, $delViaje, true))->values(),
            'zona' => $todos->filter(fn (Dte $d) => in_array($d->id, $delViaje, true)
                || in_array((int) $d->clienteSucursal?->ruta_id, $zonas, true))->values(),
        ];

        return $vistas['viaje']->isEmpty() ? ['zona' => $vistas['zona']] : $vistas;
    }

    public static function participa(SalidaRuta $salida, PersonalRuta $persona): bool
    {
        return $salida->participantes()->where('rutas_personal_id', $persona->id)->exists();
    }

    public static function noVa(SalidaRuta $salida): string
    {
        return 'Este CCF va en la salida '.$salida->descripcionCorta().': lo marca quien va en ella o la oficina lo suma.';
    }

    public static function diaCerrado(SalidaRuta $salida): string
    {
        return 'Va en la salida '.$salida->descripcionCorta().', que ya cerró el día de hoy: pedile a la oficina que lo reabra.';
    }
}
