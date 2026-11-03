<?php

namespace App\Http\Controllers;

use App\Services\Sistema\SaludPublica;
use Illuminate\Http\JsonResponse;

/**
 * GET /salud, para el vigilante de afuera. Pública y sin login: 200 si todo anda,
 * 503 si algo cayó, y en el cuerpo QUÉ cayó. Ver {@see SaludPublica} para lo que
 * puede y no puede decir.
 */
class SaludController extends Controller
{
    public function __invoke(SaludPublica $salud): JsonResponse
    {
        $componentes = $salud->evaluar();
        $fallan = array_keys(array_filter($componentes, fn (?string $motivo) => $motivo !== null));

        return response()->json([
            'estado' => $fallan === [] ? 'ok' : 'falla',
            'fallan' => $fallan,
            'componentes' => array_map(
                fn (?string $motivo) => $motivo === null ? ['ok' => true] : ['ok' => false, 'motivo' => $motivo],
                $componentes,
            ),
            'revisado' => now()->toIso8601String(),
        ], $fallan === [] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
