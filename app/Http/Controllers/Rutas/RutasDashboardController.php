<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\BandejaDocumentos;
use App\Services\Rutas\SaldoPorRuta;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio del área Rutas / Cobros.
 *
 * Dos preguntas y una sola fuente. El DASHBOARD contesta «cuánto y qué requiere
 * atención»; la BANDEJA de documentos contesta «cuáles son». Cada tarjeta de acá es la
 * entrada a la bandeja con su filtro puesto, así que la segunda pregunta se contesta
 * siempre a un clic de la primera.
 *
 * ─────────────────── El candado: una sola llamada, cero cálculos ───────────────────
 *
 * Este controlador NO calcula dinero. Llama UNA vez a {@see BandejaDocumentos::consultar()}
 * —la misma función, con los mismos filtros, sobre el mismo universo que la bandeja— y
 * reparte lo que vuelve. No hay una segunda ruta de agregación, ni un `SUM` propio, ni
 * una regla de PPQ o de NC reescrita acá o en el Blade.
 *
 * No es prolijidad: es lo que hace IMPOSIBLE que un número del dashboard contradiga al
 * listado que abre. Son el mismo array, del mismo servicio, sobre la misma colección ya
 * filtrada e hidratada. Un dashboard que suma por su cuenta miente el día que alguien
 * toca una regla y se olvida del otro lado.
 *
 * La banda por ruta ({@see SaldoPorRuta}) trabaja sobre esa misma colección y delega los
 * montos en {@see Cobranza}: agrupa, no suma.
 *
 * ───────────────────────── La ventana es la misma que la bandeja ─────────────────────────
 *
 * Se comparte `rutas.bandeja_dias` a propósito, sin un parámetro propio del dashboard.
 * El precio, que la pantalla declara en vez de esconder: esto responde «cómo va la
 * operación en este período», NO «deuda histórica total». Ese total exigiría agregar en
 * SQL y reescribir las reglas de cobro, que es justo lo que no se hace acá.
 */
class RutasDashboardController extends Controller
{
    /** Los filtros de cabecera: solo DUROS, los mismos que la bandeja resuelve en SQL. */
    private const FILTROS = ['desde', 'hasta', 'ruta_id', 'sucursal_id'];

    public function index(Request $request, BandejaDocumentos $bandeja, SaldoPorRuta $saldoPorRuta): View
    {
        $filtros = $request->only(self::FILTROS);

        // ── UNA sola llamada. Todo lo que sigue sale de acá. ──
        [
            'documentos' => $documentos,
            'resumen' => $resumen,
            'dinero' => $dinero,
            'antiguedad' => $antiguedad,
            'desde' => $desde,
            'hasta' => $hasta,
        ] = $bandeja->consultar($filtros);

        return view('rutas.dashboard', [
            // ── lo que se está mirando ──
            'filtros' => $filtros,
            'desde' => $desde,
            'hasta' => $hasta,

            // ── las cinco bandas ──
            'dinero' => $dinero,
            'antiguedad' => $antiguedad,
            'resumen' => $resumen,
            'porRuta' => $saldoPorRuta->agrupar($documentos),

            // Base de TODOS los enlaces hacia la bandeja. Se arma acá y no en el Blade
            // para que ninguna tarjeta pueda olvidarse de arrastrar el contexto: si se
            // está mirando una ruta y un rango de fechas, el listado que se abre tiene
            // que ser ese mismo universo y no otro. Las fechas van RESUELTAS (las que
            // devolvió la consulta), no las que el usuario escribió o dejó en blanco.
            'enlaceBase' => array_filter([
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'ruta_id' => $filtros['ruta_id'] ?? null,
                'sucursal_id' => $filtros['sucursal_id'] ?? null,
            ], fn ($valor) => filled($valor)),

            // ── selectores de la cabecera ──
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
            'sucursales' => ClienteSucursal::whereNotNull('ruta_id')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo']),

            // ── operación: lo que este dashboard ya mostraba y sigue siendo útil ──
            'rutasActivas' => Ruta::activas()->count(),
            'rutasTotales' => Ruta::count(),
            'planificadas' => SalidaRuta::enEstado(EstadoSalidaRuta::Planificada)->count(),
            'enCurso' => SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)->count(),
            'salasSinRuta' => ClienteSucursal::whereNull('ruta_id')->where('activo', true)->count(),
            'ultimas' => SalidaRuta::with(['ruta:id,nombre', 'vendedores:id,name'])
                ->orderByDesc('fecha_inicio')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
