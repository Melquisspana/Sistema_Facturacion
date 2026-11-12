<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaParticipante;
use App\Models\User;
use App\Services\Planilla\NombreUnico;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoRutas;
use App\Services\Rutas\TableroViajes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * El tablero del jefe de vendedores (docs/PLAN_TABLERO_RUTAS.md, sección 1): resumen, lo
 * que va en camino, cada zona con su ritmo ({@see RitmoRutas}) y las salas que tocan. Solo
 * lectura: nadie arma salidas, el viaje se abre solo con el primer pedido.
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

    /** El resumen muestra lo facturado a cada ruta en estos días, hoy incluido. */
    private const DIAS_FACTURADO = 30;

    public function index(Request $request, RitmoRutas $ritmo, EntregasCcf $entregas, TableroViajes $viajes): View
    {
        $hoy = CarbonImmutable::today();

        $usuario = $request->user();

        // Los viajes (y las salidas de antes) en curso. Los pedidos con su zona, para nombrar
        // cada viaje sin consultar uno por uno.
        $salidasAbiertas = SalidaRuta::query()
            ->enEstado(EstadoSalidaRuta::EnCurso)
            ->with(['ruta:id,nombre', 'personal:id,nombre', 'entregas.dte:id,numero_orden_compra',
                'pedidos:id,salida_ruta_id,ruta_id,estado', 'pedidos.ruta:id,nombre'])
            ->orderBy('fecha_inicio')
            ->get();
        // Los albaranes de todas las salidas de una vez, no salida por salida.
        $resoluciones = $entregas->resolucionesAlbaran($salidasAbiertas->flatMap->entregas);
        $enCamino = $salidasAbiertas->map(fn (SalidaRuta $s) => [
            'salida' => $s,
            'resumen' => $entregas->resumen($s->entregas, $resoluciones),
        ]);

        // Cada sala activa en su zona; la que ninguna ruta cubre sale igual, sin zona.
        ['zonas' => $rutas, 'salas' => $salas] = $ritmo->tablero($hoy);
        $salasDeRuta = $salas->filter(fn (array $s) => $s['ruta'] !== null)->groupBy(fn (array $s) => $s['ruta']->id);

        // Filtros de la lista de salas. Lo que no se reconoce se ignora.
        $ver = in_array($request->query('ver'), self::VER, true) ? $request->query('ver') : 'todas';
        $rutaId = (int) $request->query('ruta');
        $rutaId = $rutas->contains(fn (array $f) => $f['ruta']->id === $rutaId) ? $rutaId : null;
        $buscar = Str::limit(trim((string) $request->query('q')), 100, '');
        $soloRutasAtrasadas = $request->query('rutas') === 'atrasadas';

        $lista = $this->filtrar($salas, $ver, $rutaId, $buscar);
        $pagina = $this->paginar($lista, $request);

        $vendedores = PersonalRuta::activos()->orderBy('nombre')->get(['id', 'nombre', 'telefono', 'user_id']);
        $facturado = $this->facturadoPorZona($entregas, $hoy, $salas);

        return view('rutas.dashboard', [
            // Las facturas que ya no se pudieron entregar dos veces: el mismo aviso de «Por entregar».
            'fallas' => $viajes->fallas()->count(),
            'resumen' => [
                'rutasAtrasadas' => $rutas->where('estado', RitmoRutas::ATRASADA)->count(),
                // Con un viaje abierto, la zona está resuelta: cuenta como al día.
                'rutasAlDia' => $rutas->whereIn('estado', [RitmoRutas::AL_DIA, RitmoRutas::EN_RUTA])->count(),
                'rutas' => $rutas->count(),
                'salasAtrasadas' => $salas->where('estado', Ritmo::ATRASADA)->count(),
                'salasAlDia' => $salas->where('estado', Ritmo::AL_DIA)->count(),
                'salas' => $salas->count(),
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
            'facturado' => $facturado,
            'enCamino' => $enCamino->values(),
            'salas' => $pagina,
            'hayFiltro' => $ver !== 'todas' || $rutaId !== null || $buscar !== '',
            'filtro' => ['ver' => $ver, 'ruta' => $rutaId, 'q' => $buscar],
            'conteoVer' => [
                'atrasadas' => $this->filtrar($salas, 'atrasadas', $rutaId, $buscar)->count(),
                'semana' => $this->filtrar($salas, 'semana', $rutaId, $buscar)->count(),
                'todas' => $this->filtrar($salas, 'todas', $rutaId, $buscar)->count(),
            ],
            'tarjetasVendedores' => $this->tarjetasVendedores($vendedores, $usuario, $hoy),
            'salasSinZona' => $salas->whereNull('ruta')->count(),
        ]);
    }

    /**
     * Lo facturado a las salas de cada zona en los últimos 30 días (hoy incluido): la suma
     * sin IVA de los CCF que cuentan en Rutas ({@see EntregasCcf::baseCcf()}). Una consulta,
     * por sala; se reparte por la zona de cada sala. No resta notas de crédito: para eso
     * está Ventas.
     *
     * @param  Collection<int, array>  $salas  las del tablero, con su zona en `ruta`
     * @return array<int, float> ruta_id (zona) => monto
     */
    private function facturadoPorZona(EntregasCcf $entregas, CarbonImmutable $hoy, Collection $salas): array
    {
        $zonaDe = $salas->filter(fn (array $s) => $s['ruta'] !== null)->mapWithKeys(fn (array $s) => [$s['sala']->id => $s['ruta']->id]);
        $porSala = $entregas->baseCcf()
            ->whereIn('cliente_sucursal_id', $zonaDe->keys()->all())
            ->whereDate('fecha_emision', '>=', $hoy->subDays(self::DIAS_FACTURADO - 1)->toDateString())
            ->whereDate('fecha_emision', '<=', $hoy->toDateString())
            ->groupBy('cliente_sucursal_id')
            ->toBase()
            ->selectRaw('cliente_sucursal_id, SUM(subtotal) as monto')
            ->pluck('monto', 'cliente_sucursal_id');

        $facturado = [];
        foreach ($porSala as $salaId => $monto) {
            $zona = $zonaDe[$salaId];
            $facturado[$zona] = round(($facturado[$zona] ?? 0) + (float) $monto, 2);
        }

        return $facturado;
    }

    /**
     * La sección Vendedores: una tarjeta por persona activa de Rutas (el jefe incluido),
     * con su salida de ahora o la última, su última entrega, las rutas a las que fue en los
     * últimos 90 días (la más frecuente primero) y a qué ficha lleva «Ver ficha».
     *
     * Nombre y teléfono son los de su ficha de Personal: el nombre se edita ahí y llega solo
     * a `rutas_personal` ({@see NombreUnico}), y el teléfono se
     * edita en el bloque «Rutas» de esa misma ficha.
     *
     * «Ver ficha» lleva a la ficha de Personal solo a quien puede abrirla
     * ({@see PersonalRuta::seGestionaEnPersonal()}), o a la de Rutas a la administración sin
     * Planilla. Los demás, como el jefe, no ven fichas de empleados (decisión de Melqui,
     * 04/11/2026): para ellos ni se consulta Planilla.
     *
     * El Administrador tiene ficha de Rutas para entrar al menú del vendedor, pero no es
     * vendedor: sale acá solo si en esos 90 días fue en un viaje o entregó.
     *
     * @param  Collection<int, PersonalRuta>  $personas
     * @return Collection<int, array{persona: PersonalRuta, ahora: ?SalidaRuta, ultima: ?SalidaRuta, ultimaEntrega: ?CarbonImmutable, rutas: array<int, string>, ficha: ?string}>
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
            ->with(['salida.ruta:id,nombre', 'salida.pedidos:id,salida_ruta_id,ruta_id,estado', 'salida.pedidos.ruta:id,nombre'])
            ->get()
            ->groupBy('rutas_personal_id');

        // La última entrega que hizo cada uno, de cualquier salida: una consulta para todos.
        $entregas = SalidaRutaEntrega::query()
            ->entregadas()
            ->whereIn('entregado_por_id', $personas->pluck('id'))
            ->groupBy('entregado_por_id')
            ->selectRaw('entregado_por_id, MAX(fecha_resultado) as ultima')
            ->pluck('ultima', 'entregado_por_id');

        $enPersonal = PersonalRuta::seGestionaEnPersonal($usuario)
            ? PlanillaEmpleado::whereIn('personal_ruta_id', $personas->pluck('id'))->pluck('id', 'personal_ruta_id')
            : collect();

        // Las fichas de los empleados son solo de la administración (04/11/2026).
        $verFichas = $usuario->can('planilla.ver') && $usuario->can('rutas.personal.ver');

        // Quien tiene usuario y no fue en ningún viaje ni entregó en esos 90 días: si es
        // Administrador, no sale. Solo por ellos se consultan los roles, de una vez.
        $quietos = $personas->filter(fn (PersonalRuta $persona) => $persona->user_id !== null
            && ! $participaciones->has($persona->id)
            && (! isset($entregas[$persona->id]) || substr((string) $entregas[$persona->id], 0, 10) < $desde));
        if ($quietos->isNotEmpty()) {
            $administradores = User::whereIn('id', $quietos->pluck('user_id'))
                ->whereHas('roles', fn ($q) => $q->where('name', RolSistema::Administrador->value))
                ->pluck('id')
                ->flip();
            $personas = $personas->reject(fn (PersonalRuta $persona) => $quietos->contains($persona) && isset($administradores[$persona->user_id]))->values();
        }

        return $personas->map(function (PersonalRuta $persona) use ($participaciones, $entregas, $enPersonal, $verFichas) {
            $salidas = $participaciones->get($persona->id, collect())->pluck('salida')->sortByDesc(fn (SalidaRuta $s) => [$s->fecha_inicio, $s->id]);

            return [
                'persona' => $persona,
                'ahora' => $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::EnCurso),
                'ultima' => $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Finalizada),
                'ultimaEntrega' => isset($entregas[$persona->id]) ? CarbonImmutable::parse($entregas[$persona->id])->startOfDay() : null,
                // El viaje sin zona no suma a ninguna.
                'rutas' => $salidas->whereNotNull('ruta_id')->groupBy('ruta_id')
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
            ->when($rutaId !== null, fn ($c) => $c->filter(fn (array $s) => $s['ruta']?->id === $rutaId))
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
