<?php

namespace App\Services\Rutas;

use App\Models\Ruta;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * El aviso de rutas del PANEL PRINCIPAL: qué rutas ya toca visitar (o están por tocar) y
 * cuántos CCF esperan en cada una.
 *
 * El panel principal es la primera pantalla de todo el mundo, así que esto nunca puede
 * tumbarla (ver la memoria del proyecto sobre vistas compartidas): sin permiso, sin las
 * tablas del módulo o ante cualquier error, devuelve una lista vacía y el panel se dibuja
 * igual, sin aviso.
 *
 * Calcularlo recorre todas las salas activas (la prueba de carga del 16/11/2026 midió unos
 * 170 ms de cada visita al Inicio), así que se guarda {@see self::MINUTOS} minutos, como las
 * tarjetas «Por cobrar» y «Por entregar» del Inicio. Es el mismo para todos los que ven Rutas
 * (no depende de quién lo pide): se guarda una sola vez, y el permiso se mira antes de leerlo.
 */
class AvisoRutas
{
    /** Minutos que se guarda el aviso. */
    public const MINUTOS = 5;

    public function __construct(
        private readonly RitmoRutas $ritmo,
        private readonly EntregasCcf $entregas,
    ) {}

    /**
     * @return array<int, array{ruta: string, id: int, estado: string, texto: string, ccf: int, monto: float}>
     */
    public function para(?User $usuario): array
    {
        if ($usuario === null || ! $usuario->can('rutas.ver')) {
            return [];
        }

        try {
            // Si el cálculo falla no se guarda nada: la visita siguiente vuelve a intentar.
            return Cache::remember('inicio.aviso_rutas', self::MINUTOS * 60, fn () => $this->calcular());
        } catch (Throwable $e) {
            Log::warning('No se pudo calcular el aviso de rutas del panel principal.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<int, array{ruta: string, id: int, estado: string, texto: string, ccf: int, monto: float}>
     */
    private function calcular(): array
    {
        if (! Schema::hasTable('salida_ruta_entregas') || ! Schema::hasTable('ruta_coberturas')) {
            return [];
        }

        // Sin rutas activas no hay nada que avisar ni que consultar.
        if (! Ruta::activas()->exists()) {
            return [];
        }

        $pendientes = $this->entregas->pendientesPorRuta();

        return $this->ritmo->porRuta()
            ->filter(fn (array $f) => in_array($f['estado'], [RitmoRutas::ATRASADA, RitmoRutas::PRONTO], true))
            ->map(fn (array $f) => [
                'ruta' => $f['ruta']->nombre,
                'id' => $f['ruta']->id,
                'estado' => $f['estado'],
                'texto' => match (true) {
                    $f['faltan'] < 0 => abs($f['faltan']).' '.(abs($f['faltan']) === 1 ? 'día' : 'días').' tarde',
                    $f['faltan'] === 0 => 'toca hoy',
                    default => 'en '.$f['faltan'].' '.($f['faltan'] === 1 ? 'día' : 'días'),
                },
                'ccf' => $pendientes[$f['ruta']->id]['cantidad'] ?? 0,
                'monto' => $pendientes[$f['ruta']->id]['monto'] ?? 0.0,
            ])
            ->values()
            ->all();
    }
}
