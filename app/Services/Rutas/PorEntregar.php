<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Dte;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Policies\SalidaRutaPolicy;
use App\Support\HoraNegocio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Por entregar» (Melqui, 06/11/2026): el vendedor marca un CCF sin que nadie arme la salida.
 * Qué está por entregar lo decide {@see EntregasCcf::porEntregar()}; registrar la entrega,
 * {@see EntregasCcf::registrar()}. Acá solo se elige en qué salida queda anotada:
 *
 *   1. si el CCF ya va sin registrar en una salida en curso (una formal a San Miguel, por
 *      ejemplo), en esa; si es formal y el vendedor no va en ella, no se marca;
 *   2. si no, en la salida formal de entrega en curso de la ruta de su sala donde va el
 *      vendedor (aunque sea un viaje que empezó antes); si no, en la automática de hoy;
 *   3. si no hay, en una que se arma sola: en curso, para entregar, de hoy, a nombre del
 *      vendedor y con él adentro. No carga los demás pendientes: lleva solo lo que se marca.
 *
 * A una formal nunca se suma a nadie: ir en una salida da el cierre del día y la liquidación
 * ({@see SalidaRutaPolicy}), sea responsable o acompañante. A la automática sí,
 * como acompañante. Las automáticas de días anteriores se finalizan solas
 * ({@see self::finalizarAutomaticasViejas()}) y quedan para que la oficina las liquide.
 */
class PorEntregar
{
    public const SIN_RUTA = 'Esta sala no tiene ruta: pedile a la oficina que se la asigne.';

    public function __construct(private readonly EntregasCcf $entregas) {}

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
            $rutaId = $dte->clienteSucursal?->ruta_id
                ?? throw ValidationException::withMessages(['entrega' => self::SIN_RUTA]);
            // Con la ruta bloqueada, dos vendedores que marcan a la vez no arman dos salidas.
            Ruta::whereKey($rutaId)->lockForUpdate()->firstOrFail();
            $this->finalizarAutomaticasViejas($rutaId);

            $entrega = $this->enCamino($dte, $persona) ?? $this->enLaDeHoy($dte, $rutaId, $persona, $usuario);
            $this->sumar($entrega->salida, $persona, $usuario);

            return $entrega;
        });
    }

    /**
     * Finaliza las automáticas de días anteriores (de una ruta, o de todas): con el regreso
     * el mismo día en que se armaron. No las liquida: eso sigue siendo de la oficina. Lo
     * llama el planificador cada mañana y «Por entregar» al marcar. Devuelve cuántas.
     */
    public function finalizarAutomaticasViejas(?int $rutaId = null): int
    {
        $hoy = HoraNegocio::fechaHoy();
        $ids = SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)->where('observaciones', SalidaRuta::OBSERVACION_AUTOMATICA)
            ->whereDate('fecha_inicio', '<', $hoy)->when($rutaId !== null, fn ($q) => $q->where('ruta_id', $rutaId))
            ->orderBy('id')->pluck('id');
        $finalizadas = 0;

        foreach ($ids as $id) {
            $finalizadas += DB::transaction(function () use ($id) {
                $salida = SalidaRuta::lockForUpdate()->find($id);
                if ($salida === null || $salida->estado !== EstadoSalidaRuta::EnCurso || ! $salida->esAutomatica()) {
                    return 0;
                }
                $salida->finalizar($salida->fecha_inicio->toDateString());
                activity('salida_ruta')
                    ->performedOn($salida)
                    ->withProperties(['estado_anterior' => EstadoSalidaRuta::EnCurso->value, 'estado_nuevo' => EstadoSalidaRuta::Finalizada->value, 'desde' => 'por_entregar'])
                    ->log('se finalizó sola');

                return 1;
            });
        }

        return $finalizadas;
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
     * La salida de entrega en curso de la ruta para hoy: la formal donde va el vendedor, aunque
     * haya empezado antes (un viaje de varios días a San Miguel), como cuando carga lo facturado
     * en camino ({@see EntregasCcf::cargarNuevosEnCurso()}); si no, la automática de hoy. Las
     * que ya cerraron el día de hoy no reciben más.
     */
    private function enLaDeHoy(Dte $dte, int $rutaId, PersonalRuta $persona, User $usuario): SalidaRutaEntrega
    {
        $hoy = HoraNegocio::fechaHoy();
        $salida = SalidaRuta::where('ruta_id', $rutaId)->enEstado(EstadoSalidaRuta::EnCurso)
            ->whereDate('fecha_inicio', '<=', $hoy)->orderByDesc('fecha_inicio')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (SalidaRuta $s) => $s->proposito->entrega() && ! $s->diaCerrado($hoy)
                && ($s->esAutomatica() ? $s->fecha_inicio->toDateString() === $hoy : self::participa($s, $persona)))
            ->sortBy(fn (SalidaRuta $s) => $s->esAutomatica() ? 1 : 0)->first()
            ?? $this->armar($rutaId, $hoy, $usuario, $dte);

        $entrega = $salida->entregas()->where('dte_id', $dte->id)->lockForUpdate()->first();
        if ($entrega?->resultado === ResultadoEntrega::NoEntregado) {
            $this->reintentar($entrega, $usuario);
        }
        $entrega ??= $this->entregas->meterCcf($salida, $dte->id);

        return $entrega->setRelation('salida', $salida);
    }

    /**
     * Un «No se pudo entregar» ya anotado en esta salida (hoy, por él u otro, o un día
     * anterior del viaje): el toque nuevo es un nuevo intento. La fila vuelve a pendiente y
     * se registra lo nuevo, en la misma transacción: si el registro no pasa, queda como estaba.
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
            ->log('reintento desde Por entregar');
    }

    private function armar(int $rutaId, string $hoy, User $usuario, Dte $dte): SalidaRuta
    {
        $salida = SalidaRuta::create([
            'ruta_id' => $rutaId,
            'fecha_inicio' => $hoy,
            'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Entregar,
            'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA,
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
