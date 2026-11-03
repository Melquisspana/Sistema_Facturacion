<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Trabajos de la cola en `failed_jobs`: envío de DTE, correos, PDF. */
class TrabajosFallidos implements Detector
{
    public function tipo(): string
    {
        return 'trabajos_fallidos';
    }

    public function nombre(): string
    {
        return 'Trabajos de la cola fallidos';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $fallidos = DB::table('failed_jobs')->orderBy('failed_at')->get(['payload', 'failed_at']);

        if ($fallidos->isEmpty()) {
            return null;
        }

        // Solo el nombre de la clase del trabajo: el payload trae los datos del documento.
        $porTrabajo = $fallidos
            ->map(fn ($f) => class_basename((string) (json_decode((string) $f->payload, true)['displayName'] ?? 'Trabajo')))
            ->countBy()
            ->map(fn (int $n, string $trabajo) => $trabajo.' ('.$n.')')
            ->implode(', ');

        $n = $fallidos->count();

        return new Hallazgo(
            titulo: $n === 1 ? '1 trabajo de la cola falló' : $n.' trabajos de la cola fallaron',
            detalle: 'Trabajos en failed_jobs: '.$porTrabajo.'.',
            queHacer: 'Abrí Salud del sistema para ver cuáles son. Si el problema ya se corrigió, reintentalos (queue:retry) o borralos (queue:forget).',
            conteo: $n,
            desde: Carbon::parse($fallidos->first()->failed_at),
            enlace: 'admin.salud-sistema',
        );
    }
}
