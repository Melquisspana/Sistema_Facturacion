<?php

namespace App\Http\Middleware;

use App\Enums\ModuloSistema;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor de backend del módulo Planilla, y candado de su esquema.
 *
 * Dos preguntas distintas, con dos respuestas distintas, por la misma razón que en
 * Gastos —donde un menú que consultaba una tabla inexistente tumbó el sistema
 * entero—:
 *
 *   PLANILLA_ENABLED=false     → 404. Es una DECISIÓN: el módulo no está para nadie.
 *   tablas sin migrar          → 503 con explicación. El módulo está encendido y lo
 *                                que falta es correr las migraciones.
 *
 * Confundirlas mandaría a quien administra a buscar un interruptor que ya está
 * encendido. Y exige usuario ACTIVO: la planilla toca salarios, así que una cuenta
 * desactivada con la sesión viva no entra.
 *
 * `modulo.planilla:personal` es para las fichas de Personal (alta, datos, módulos, baja):
 * no miran el interruptor, porque Personal funciona con Planilla apagada. El usuario
 * activo y las tablas se siguen exigiendo.
 */
class ModuloPlanillaActivo
{
    /** Tablas sin las cuales el módulo no puede funcionar. */
    private const TABLAS = ['planilla_empleados', 'planillas', 'planilla_detalles', 'planilla_conceptos'];

    public function handle(Request $request, Closure $next, ?string $parte = null): Response
    {
        abort_unless($parte === 'personal' || ModuloSistema::Planilla->activo(), 404);
        abort_unless((bool) $request->user()?->activo, 403);

        $faltan = array_values(array_filter(self::TABLAS, fn (string $t) => ! Schema::hasTable($t)));

        if ($faltan !== []) {
            Log::warning('Planilla sin instalar', ['ruta' => $request->path(), 'tablas_faltantes' => $faltan]);

            return response()->view('planilla.sin-instalar', ['tablasQueFaltan' => $faltan], 503);
        }

        return $next($request);
    }
}
