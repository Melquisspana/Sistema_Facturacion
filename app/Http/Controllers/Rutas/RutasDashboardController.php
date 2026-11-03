<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoRutas;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * El tablero del jefe de vendedores (docs/PLAN_TABLERO_RUTAS.md, sección 1): resumen, lo
 * que va en camino, cada ruta con su ritmo ({@see RitmoRutas}) y las salas que tocan.
 *
 * Todo sale de unas pocas consultas para todas las rutas y salas juntas; la «última
 * entrega» se busca solo para las salas de la página que se muestra.
 */
class RutasDashboardController extends Controller
{
    private const SALAS_POR_PAGINA = 25;

    /** Pastillas de la lista de salas. `semana`: no atrasadas que tocan en 7 días o menos. */
    private const VER = ['atrasadas', 'semana', 'todas'];

    private const DIAS_SEMANA = 7;

    public function index(Request $request, RitmoRutas $ritmo, EntregasCcf $entregas): View
    {
        $hoy = CarbonImmutable::today();

        // El jefe de vendedores aterriza acá y no en «Mi salida»: si va en una salida en
        // curso, se le ofrece el enlace.
        $usuario = $request->user();
        $vaEnUnaSalida = $usuario->can('rutas.entregas.registrar')
            && SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)
                ->whereHas('personal', fn ($q) => $q->where('rutas_personal.user_id', $usuario->id)->where('rutas_personal.activo', true))
                ->exists();

        $salidasAbiertas = SalidaRuta::query()
            ->whereIn('estado', [EstadoSalidaRuta::EnCurso->value, EstadoSalidaRuta::Planificada->value])
            ->with(['ruta:id,nombre', 'personal:id,nombre', 'entregas.dte:id,numero_orden_compra'])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoSalidaRuta::EnCurso->value])
            ->orderBy('fecha_inicio')
            ->get();
        // Los albaranes de todas las salidas de una vez, no salida por salida.
        $resoluciones = $entregas->resolucionesAlbaran($salidasAbiertas->flatMap->entregas);
        $enCamino = $salidasAbiertas->map(fn (SalidaRuta $s) => [
            'salida' => $s,
            'resumen' => $entregas->resumen($s->entregas, $resoluciones),
        ]);

        $rutas = $ritmo->porRuta($hoy);
        $salas = $rutas->flatMap(fn (array $fila) => $fila['salas']->map(
            fn (ClienteSucursal $sala) => $this->filaSala($sala, $fila, $fila['ritmoSalas'][$sala->id], $hoy)
        ));
        $salasDeRuta = $salas->groupBy(fn (array $s) => $s['ruta']->id);

        // Filtros de la lista de salas. Lo que no se reconoce se ignora.
        $ver = in_array($request->query('ver'), self::VER, true) ? $request->query('ver') : 'todas';
        $rutaId = (int) $request->query('ruta');
        $rutaId = $rutas->contains(fn (array $f) => $f['ruta']->id === $rutaId) ? $rutaId : null;
        $buscar = Str::limit(trim((string) $request->query('q')), 100, '');
        $soloRutasAtrasadas = $request->query('rutas') === 'atrasadas';

        $lista = $this->filtrar($salas, $ver, $rutaId, $buscar);
        $pagina = $this->paginar($lista, $request);

        return view('rutas.dashboard', [
            'resumen' => [
                'rutasAtrasadas' => $rutas->where('estado', RitmoRutas::ATRASADA)->count(),
                'salasAtrasadas' => $salas->where('estado', Ritmo::ATRASADA)->count(),
                'salasSemana' => $salas->filter(fn (array $s) => $this->tocaEstaSemana($s))->count(),
                'enCamino' => $salidasAbiertas->where('estado', EstadoSalidaRuta::EnCurso)->count(),
            ],
            'filas' => $soloRutasAtrasadas ? $rutas->where('estado', RitmoRutas::ATRASADA)->values() : $rutas,
            'soloRutasAtrasadas' => $soloRutasAtrasadas,
            'salasDeRuta' => $salasDeRuta,
            'conteoRuta' => $salasDeRuta->map(fn (Collection $deEsta) => [
                'atrasadas' => $deEsta->where('estado', Ritmo::ATRASADA)->count(),
                'semana' => $deEsta->filter(fn (array $s) => $this->tocaEstaSemana($s))->count(),
            ]),
            'pendientes' => $entregas->pendientesPorRuta(),
            'enCamino' => $enCamino,
            'salas' => $pagina,
            'ultimaEntrega' => $entregas->ultimaVisitaPorSala($pagina->getCollection()->map(fn (array $s) => $s['sala']->id)->all()),
            'hayFiltro' => $ver !== 'todas' || $rutaId !== null || $buscar !== '',
            'filtro' => ['ver' => $ver, 'ruta' => $rutaId, 'q' => $buscar],
            'conteoVer' => [
                'atrasadas' => $this->filtrar($salas, 'atrasadas', $rutaId, $buscar)->count(),
                'semana' => $this->filtrar($salas, 'semana', $rutaId, $buscar)->count(),
                'todas' => $this->filtrar($salas, 'todas', $rutaId, $buscar)->count(),
            ],
            'vendedores' => PersonalRuta::activos()->orderBy('nombre')->get(['id', 'nombre']),
            'salasSinRuta' => ClienteSucursal::whereNull('ruta_id')->where('activo', true)->count(),
            'vaEnUnaSalida' => $vaEnUnaSalida,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ruta  fila de {@see RitmoRutas::porRuta()}
     * @return array{sala: ClienteSucursal, ruta: Ruta, ritmo: Ritmo, estado: string, dias: ?int, faltan: ?int, dejoDeComprar: bool}
     */
    private function filaSala(ClienteSucursal $sala, array $ruta, Ritmo $ritmo, CarbonImmutable $hoy): array
    {
        $dias = $ritmo->diasDesde($hoy);

        return [
            'sala' => $sala,
            'ruta' => $ruta['ruta'],
            'ritmo' => $ritmo,
            'estado' => $ritmo->estadoEn($hoy),
            'dias' => $dias,
            // Días para el próximo pedido esperado; negativo = días de atraso.
            'faltan' => $ritmo->dias !== null ? $ritmo->dias - $dias : null,
            'dejoDeComprar' => $ritmo->dejoDeComprar($hoy),
        ];
    }

    private function tocaEstaSemana(array $sala): bool
    {
        return $sala['estado'] !== Ritmo::ATRASADA && $sala['faltan'] !== null && $sala['faltan'] <= self::DIAS_SEMANA;
    }

    /**
     * Las atrasadas primero (la más atrasada arriba), después las que tocan antes, al
     * final las que no tienen ritmo.
     *
     * @param  Collection<int, array>  $salas
     * @return Collection<int, array>
     */
    private function filtrar(Collection $salas, string $ver, ?int $rutaId, string $buscar): Collection
    {
        $norm = fn (?string $s) => Str::lower(Str::ascii((string) $s));
        $orden = [Ritmo::ATRASADA => 0, Ritmo::PRONTO => 1, Ritmo::AL_DIA => 2, Ritmo::SIN_RITMO => 3];

        return $salas
            ->when($ver === 'atrasadas', fn ($c) => $c->where('estado', Ritmo::ATRASADA))
            ->when($ver === 'semana', fn ($c) => $c->filter(fn (array $s) => $this->tocaEstaSemana($s)))
            ->when($rutaId !== null, fn ($c) => $c->filter(fn (array $s) => $s['ruta']->id === $rutaId))
            ->when($buscar !== '', fn ($c) => $c->filter(fn (array $s) => str_contains($norm($s['sala']->nombre), $norm($buscar))
                || str_contains($norm($s['sala']->codigo), $norm($buscar))))
            ->sortBy([
                fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']],
                fn ($a, $b) => ($a['faltan'] ?? PHP_INT_MAX) <=> ($b['faltan'] ?? PHP_INT_MAX),
                fn ($a, $b) => ($b['dias'] ?? -1) <=> ($a['dias'] ?? -1),
                fn ($a, $b) => strcmp($a['sala']->nombre, $b['sala']->nombre),
            ])
            ->values();
    }

    /** @param  Collection<int, array>  $lista */
    private function paginar(Collection $lista, Request $request): LengthAwarePaginator
    {
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $lista->forPage($pagina, self::SALAS_POR_PAGINA)->values(),
            $lista->count(),
            self::SALAS_POR_PAGINA,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()],
        ))->fragment('salas');
    }
}
