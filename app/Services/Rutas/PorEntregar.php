<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Dte;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Support\HoraNegocio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Por entregar» (Melqui, 06/11/2026): el vendedor marca un CCF sin que nadie arme la salida.
 * Qué está por entregar lo decide {@see EntregasCcf::porEntregar()}; registrar la entrega,
 * {@see EntregasCcf::registrar()}. Acá solo se elige en qué salida queda anotada:
 *
 *   1. si el CCF ya va sin registrar en una salida en curso (una formal a San Miguel, por
 *      ejemplo), en esa;
 *   2. si no, en la salida de entrega en curso de HOY de la ruta de su sala;
 *   3. si no hay, en una que se arma sola: en curso, para entregar, de hoy, a nombre del
 *      vendedor y con él adentro. No carga los demás pendientes: lleva solo lo que se marca.
 *
 * En los tres casos, si el vendedor no iba en la salida, se suma ({@see ParticipantesSalida}).
 */
class PorEntregar
{
    public const SIN_RUTA = 'Esta sala no tiene ruta: pedile a la oficina que se la asigne.';

    public function __construct(private readonly EntregasCcf $entregas, private readonly ParticipantesSalida $participantes) {}

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

            $entrega = $this->enCamino($dte) ?? $this->enLaDeHoy($dte, $rutaId, $usuario);
            $this->sumar($entrega->salida, $persona, $usuario);

            return $entrega;
        });
    }

    /** Sin registrar en una salida abierta: se marca en esa, si ya salió. */
    private function enCamino(Dte $dte): ?SalidaRutaEntrega
    {
        $entrega = SalidaRutaEntrega::with('salida.ruta')->where('dte_id', $dte->id)->pendientes()
            ->whereHas('salida', fn ($q) => $q->abiertas())->lockForUpdate()->first();

        if ($entrega?->salida->estado === EstadoSalidaRuta::Planificada) {
            throw ValidationException::withMessages(['entrega' => 'Este CCF va en la salida '.$entrega->salida->descripcionCorta()
                .', que todavía no empezó: se marca cuando la empiecen.']);
        }

        return $entrega;
    }

    private function enLaDeHoy(Dte $dte, int $rutaId, User $usuario): SalidaRutaEntrega
    {
        $hoy = HoraNegocio::fechaHoy();
        // La que armó la oficina va antes que la automática.
        $salida = SalidaRuta::where('ruta_id', $rutaId)->enEstado(EstadoSalidaRuta::EnCurso)
            ->whereDate('fecha_inicio', $hoy)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (SalidaRuta $s) => $s->proposito->entrega())
            ->sortBy(fn (SalidaRuta $s) => $s->esAutomatica() ? 1 : 0)->first()
            ?? $this->armar($rutaId, $hoy, $usuario, $dte);

        $entrega = $salida->entregas()->where('dte_id', $dte->id)->first() ?? $this->entregas->meterCcf($salida, $dte->id);

        return $entrega->setRelation('salida', $salida);
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

    private function sumar(SalidaRuta $salida, PersonalRuta $persona, User $usuario): void
    {
        $actuales = $salida->participantes()->pluck('rutas_personal_id')->map(fn ($id) => (int) $id);
        if ($actuales->contains($persona->id)) {
            return;
        }

        $resultado = $this->participantes->sincronizar($salida, [...$actuales->all(), $persona->id], $salida->responsable()->value('rutas_personal_id'));
        activity('salida_ruta')
            ->performedOn($salida)
            ->causedBy($usuario)
            ->withProperties(['agregados' => $resultado['agregados'], 'quitados' => [], 'responsable' => $resultado['responsable'], 'desde' => 'por_entregar'])
            ->log('cambió los participantes de la salida');
    }
}
