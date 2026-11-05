<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\ModuloSistema;
use App\Facades\Ajustes;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Support\HoraNegocio;

/**
 * Lector de asistencia activo que no se reporta en horario laboral. Fuera de
 * horario no opina: de noche el lector puede estar apagado.
 */
class LectorSinPing implements Detector
{
    public function tipo(): string
    {
        return 'lector_sin_ping';
    }

    public function nombre(): string
    {
        return 'Lector de asistencia sin señal';
    }

    /**
     * Desde el inicio del horario más el margen: al abrir, el lector recién
     * encendido tiene ese tiempo para reportarse antes de que se avise.
     */
    public function aplica(): bool
    {
        if (! ModuloSistema::Asistencia->activo()) {
            return false;
        }

        $ahora = HoraNegocio::ahora();
        // «08:00-17:00»; vacío = ese día no se vigila. El formato lo valida el ajuste.
        $texto = (string) Ajustes::texto('alertas.lector.horario.'.$ahora->dayOfWeekIso, '');
        $horario = explode('-', $texto);

        if (count($horario) !== 2) {
            return false;
        }

        $desde = $ahora->copy()->setTimeFromTimeString($horario[0])->addMinutes($this->margen());
        $hasta = $ahora->copy()->setTimeFromTimeString($horario[1]);

        return $ahora->gte($desde) && $ahora->lt($hasta);
    }

    public function revisar(): ?Hallazgo
    {
        $limite = now()->subMinutes($this->margen());

        $callados = AsistenciaDispositivo::query()
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('ultima_conexion_at')->orWhere('ultima_conexion_at', '<', $limite))
            ->orderBy('codigo')
            ->get(['codigo', 'nombre', 'ultima_conexion_at']);

        if ($callados->isEmpty()) {
            return null;
        }

        $detalle = $callados->map(fn (AsistenciaDispositivo $d) => '«'.($d->nombre ?: $d->codigo).'»: '
            .($d->ultima_conexion_at
                ? 'última señal '.HoraNegocio::aLocal($d->ultima_conexion_at)->format('d/m/Y H:i')
                : 'nunca se reportó'))
            ->implode('; ');

        return new Hallazgo(
            titulo: $callados->count() === 1 ? 'El lector de asistencia no se reporta' : $callados->count().' lectores de asistencia no se reportan',
            detalle: $detalle.'.',
            queHacer: 'Revisá que el lector tenga corriente y wifi. Si se reinicia y sigue sin señal, revisá la red de la planta. Las marcas que guarde sin red se mandan solas al volver.',
            conteo: $callados->count(),
            desde: $callados->pluck('ultima_conexion_at')->filter()->min(),
            enlace: 'admin.salud-sistema',
        );
    }

    private function margen(): int
    {
        return max(1, (int) Ajustes::entero('alertas.lector.sin_ping_minutos', 15));
    }
}
