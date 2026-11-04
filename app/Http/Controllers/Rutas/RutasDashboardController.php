<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaParticipante;
use App\Models\User;
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

    /** «Sus rutas»: no hay rutas fijas por vendedor; se muestran a las que fue en estos días. */
    private const DIAS_RUTAS_VENDEDOR = 90;

    public function index(Request $request, RitmoRutas $ritmo, EntregasCcf $entregas): View
    {
        $hoy = CarbonImmutable::today();

        // El jefe de vendedores aterriza acá y no en «Mi salida»: si va en una salida en
        // curso o en una planificada («Tu próxima salida»), se le ofrece el enlace.
        $usuario = $request->user();
        $vaEnUnaSalida = $usuario->can('rutas.entregas.registrar')
            && SalidaRuta::abiertas()
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
        // «En camino» son solo las en curso, como el número del resumen; las planificadas van
        // aparte, en «Programadas».
        [$enCamino, $programadas] = $salidasAbiertas
            ->map(fn (SalidaRuta $s) => [
                'salida' => $s,
                'resumen' => $entregas->resumen($s->entregas, $resoluciones),
            ])
            ->partition(fn (array $f) => $f['salida']->estado === EstadoSalidaRuta::EnCurso);

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

        $vendedores = PersonalRuta::activos()->orderBy('nombre')->get(['id', 'nombre', 'telefono']);

        return view('rutas.dashboard', [
            'resumen' => [
                'rutasAtrasadas' => $rutas->where('estado', RitmoRutas::ATRASADA)->count(),
                'salasAtrasadas' => $salas->where('estado', Ritmo::ATRASADA)->count(),
                'salasSemana' => $salas->filter(fn (array $s) => $this->tocaEstaSemana($s))->count(),
                'enCamino' => $enCamino->count(),
            ],
            'filas' => $soloRutasAtrasadas ? $rutas->where('estado', RitmoRutas::ATRASADA)->values() : $rutas,
            'soloRutasAtrasadas' => $soloRutasAtrasadas,
            'salasDeRuta' => $salasDeRuta,
            'conteoRuta' => $salasDeRuta->map(fn (Collection $deEsta) => [
                'atrasadas' => $deEsta->where('estado', Ritmo::ATRASADA)->count(),
                'semana' => $deEsta->filter(fn (array $s) => $this->tocaEstaSemana($s))->count(),
            ]),
            'pendientes' => $entregas->pendientesPorRuta(),
            'enCamino' => $enCamino->values(),
            'programadas' => $programadas->values(),
            'salas' => $pagina,
            'ultimaEntrega' => $entregas->ultimaVisitaPorSala($pagina->getCollection()->map(fn (array $s) => $s['sala']->id)->all()),
            'hayFiltro' => $ver !== 'todas' || $rutaId !== null || $buscar !== '',
            'filtro' => ['ver' => $ver, 'ruta' => $rutaId, 'q' => $buscar],
            'conteoVer' => [
                'atrasadas' => $this->filtrar($salas, 'atrasadas', $rutaId, $buscar)->count(),
                'semana' => $this->filtrar($salas, 'semana', $rutaId, $buscar)->count(),
                'todas' => $this->filtrar($salas, 'todas', $rutaId, $buscar)->count(),
            ],
            'vendedores' => $vendedores,
            'tarjetasVendedores' => $this->tarjetasVendedores($vendedores, $usuario, $hoy),
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
        return RitmoRutas::filaSala($sala, $ritmo, $hoy) + ['ruta' => $ruta['ruta']];
    }

    /**
     * La sección Vendedores: una tarjeta por persona activa de Rutas (el jefe incluido),
     * con su salida de ahora o la última, las rutas a las que fue en los últimos 90 días
     * (la más frecuente primero) y a qué ficha lleva «Ver ficha».
     *
     * «Ver ficha» lleva a la ficha de Personal solo a quien puede abrirla
     * ({@see PersonalRuta::seGestionaEnPersonal()}), o a la de Rutas a la administración sin
     * Planilla. Los demás, como el jefe, no ven fichas de empleados (decisión de Melqui,
     * 04/11/2026): para ellos ni se consulta Planilla.
     *
     * @param  Collection<int, PersonalRuta>  $personas
     * @return Collection<int, array{persona: PersonalRuta, ahora: ?SalidaRuta, ultima: ?SalidaRuta, rutas: array<int, string>, ficha: ?string}>
     */
    private function tarjetasVendedores(Collection $personas, User $usuario, CarbonImmutable $hoy): Collection
    {
        if ($personas->isEmpty()) {
            return collect();
        }

        $desde = $hoy->subDays(self::DIAS_RUTAS_VENDEDOR)->toDateString();
        $participaciones = SalidaRutaParticipante::query()
            ->whereIn('rutas_personal_id', $personas->pluck('id'))
            ->whereHas('salida', fn ($q) => $q->where(fn ($q) => $q->where('estado', EstadoSalidaRuta::EnCurso->value)
                ->orWhere(fn ($q) => $q->where('estado', EstadoSalidaRuta::Finalizada->value)->whereDate('fecha_inicio', '>=', $desde))))
            ->with('salida.ruta:id,nombre')
            ->get()
            ->groupBy('rutas_personal_id');

        $enPersonal = PersonalRuta::seGestionaEnPersonal($usuario)
            ? PlanillaEmpleado::whereIn('personal_ruta_id', $personas->pluck('id'))->pluck('id', 'personal_ruta_id')
            : collect();

        // Las fichas de los empleados son solo de la administración (04/11/2026).
        $verFichas = $usuario->can('planilla.ver') && $usuario->can('rutas.personal.ver');

        return $personas->map(function (PersonalRuta $persona) use ($participaciones, $enPersonal, $verFichas) {
            $salidas = $participaciones->get($persona->id, collect())->pluck('salida')->sortByDesc(fn (SalidaRuta $s) => [$s->fecha_inicio, $s->id]);

            return [
                'persona' => $persona,
                'ahora' => $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::EnCurso),
                'ultima' => $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Finalizada),
                'rutas' => $salidas->groupBy('ruta_id')
                    ->sortBy([fn ($a, $b) => $b->count() <=> $a->count(), fn ($a, $b) => strcmp($a->first()->ruta->nombre, $b->first()->ruta->nombre)])
                    ->map(fn (Collection $deRuta) => $deRuta->first()->ruta->nombre)
                    ->values()
                    ->all(),
                'ficha' => match (true) {
                    isset($enPersonal[$persona->id]) => route('personal.empleados.show', 'p'.$enPersonal[$persona->id]),
                    $verFichas => route('rutas.personal.show', $persona),
                    default => null,
                },
            ];
        });
    }

    private function tocaEstaSemana(array $sala): bool
    {
        return $sala['estado'] !== Ritmo::ATRASADA && $sala['faltan'] !== null && $sala['faltan'] <= self::DIAS_SEMANA;
    }

    /**
     * Las salas filtradas, en el orden de urgencia de {@see RitmoRutas::ordenarSalas()}.
     *
     * @param  Collection<int, array>  $salas
     * @return Collection<int, array>
     */
    private function filtrar(Collection $salas, string $ver, ?int $rutaId, string $buscar): Collection
    {
        $norm = fn (?string $s) => Str::lower(Str::ascii((string) $s));

        return RitmoRutas::ordenarSalas($salas
            ->when($ver === 'atrasadas', fn ($c) => $c->where('estado', Ritmo::ATRASADA))
            ->when($ver === 'semana', fn ($c) => $c->filter(fn (array $s) => $this->tocaEstaSemana($s)))
            ->when($rutaId !== null, fn ($c) => $c->filter(fn (array $s) => $s['ruta']->id === $rutaId))
            ->when($buscar !== '', fn ($c) => $c->filter(fn (array $s) => str_contains($norm($s['sala']->nombre), $norm($buscar))
                || str_contains($norm($s['sala']->codigo), $norm($buscar)))));
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
