<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;

/**
 * Documentos de producción que quedaron firmados sin respuesta de Hacienda: un
 * envío que no llegó, o una respuesta sin sello o que no se pudo comprobar (ver
 * docs/TRANSMISION_DTE.md, 8.b). El reintento de siempre los resuelve, pero solo si
 * alguien vuelve a tocarlos.
 *
 * Quedan afuera los de pruebas, los de firma simulada (MOCK) y los ligados a una
 * contingencia: esos siguen su propio camino y los vigila {@see ContingenciaVencida}.
 */
class FirmadoSinTransmitir implements Detector
{
    /**
     * Firmar y transmitir van juntos y Hacienda contesta en segundos; un reintento con
     * la política previa a contingencia tarda unos minutos. Media hora sin cambios ya no
     * es un envío en curso, y sigue siendo el mismo turno de trabajo para resolverlo.
     */
    public const MINUTOS = 30;

    private const NOMBRADOS = 3;

    public function tipo(): string
    {
        return 'dte_firmado_sin_transmitir';
    }

    public function nombre(): string
    {
        return 'DTE firmados sin transmitir';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        // Una sola consulta sobre el índice de `estado`: los firmados son pocos.
        // `updated_at` es la última vez que se tocó la fila; una respuesta que no se
        // guarda va a la bitácora y no la toca.
        $firmados = Dte::query()->produccion()
            ->where('estado', EstadoDte::Firmado->value)
            ->whereNull('contingencia_id')
            ->where(fn ($q) => $q->whereNull('json_firmado_path')->orWhere('json_firmado_path', 'not like', '%.mock.jws'))
            ->where('updated_at', '<=', now()->subMinutes(self::MINUTOS))
            ->orderBy('updated_at')
            ->get(['id', 'tipo_dte', 'numero_control', 'fecha_emision', 'updated_at']);

        if ($firmados->isEmpty()) {
            return null;
        }

        $n = $firmados->count();
        $nombrados = $firmados->take(self::NOMBRADOS)
            ->map(fn (Dte $dte) => ($dte->numero_control ?: $dte->tipo_dte->label()).' del '.$dte->fecha_emision->format('d/m/Y'))
            ->implode('; ');

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento firmado sigue sin transmitir' : $n.' documentos firmados siguen sin transmitir',
            detalle: 'Llevan más de '.self::MINUTOS.' minutos firmados sin aceptación ni rechazo de Hacienda: '.$nombrados
                .($n > self::NOMBRADOS ? '; y '.($n - self::NOMBRADOS).' más.' : '.'),
            queHacer: 'Abrí cada documento y probá transmitir de nuevo; si Hacienda no contesta, revisá la contingencia.',
            conteo: $n,
            desde: $firmados->first()->updated_at,
            enlace: 'facturacion.index',
        );
    }
}
