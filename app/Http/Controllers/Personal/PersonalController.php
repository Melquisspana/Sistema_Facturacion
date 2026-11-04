<?php

namespace App\Http\Controllers\Personal;

use App\Enums\ModuloSistema;
use App\Http\Controllers\Controller;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Asistencia\HoraOficial;
use App\Services\Planilla\PosiblesRepetidos;
use App\Services\Planilla\SalarioMinimo;
use App\Services\Planilla\SueldoHabitual;
use App\Support\Asistencia\ConsultaAsistencia;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * La lista única de empleados y la ficha de cada persona.
 *
 * Una persona puede estar en planilla, en Asistencia o en las dos. Esta pantalla las junta
 * sin tabla nueva: una fila por cada `planilla_empleados`, más quien solo está en Asistencia.
 *
 * AQUÍ NO SE ESCRIBE NADA. Las acciones siguen en los controladores de cada módulo, con sus
 * rutas y sus permisos, y esta pantalla solo las ofrece.
 *
 * Cada módulo se muestra si está encendido Y el usuario tiene su permiso, y no hay más
 * llaves que esas: `asistencia.ver` para huellas y marcaciones, `planilla.ver` para lo de
 * planilla sin importes, y `planilla.salarios` para los importes.
 */
class PersonalController extends Controller
{
    private const POR_PAGINA = 25;

    public function index(Request $request, SueldoHabitual $sueldos, SalarioMinimo $salarioMinimo): View
    {
        [$veAsistencia, $vePlanilla] = $this->accesos($request);

        $verImportes = $vePlanilla && $request->user()->can('planilla.salarios');
        $empleados = $vePlanilla ? PlanillaEmpleado::with(['asistencia' => $this->conHuellas(...)])->get() : collect();
        $hoy = now()->toDateString();
        $mensuales = $verImportes ? $sueldos->mensualesEn($empleados, $hoy) : [];
        $minimo = $verImportes ? $this->minimoDe($salarioMinimo, $hoy) : null;

        $filas = $empleados->map(fn (PlanillaEmpleado $e) => [
            'clave' => 'p'.$e->id,
            'nombre' => $e->nombre,
            'codigo' => $e->codigo ?? $e->asistencia?->codigo,
            'ingreso' => $e->alta_el ?? $e->asistencia?->fecha_ingreso,
            'activo' => $e->activo,
            'descuentos' => $e->con_descuentos_ley,
            'huellas' => $veAsistencia ? ($e->asistencia?->huellas_activas_count ?? null) : null,
            'marca' => $veAsistencia && $e->asistencia !== null,
            'mensual' => $mensuales[$e->id] ?? null,
        ]);

        if ($veAsistencia) {
            $enPlanilla = $empleados->pluck('asistencia_empleado_id')->filter();

            $filas = $filas->concat(
                $this->conHuellas(AsistenciaEmpleado::query())->whereNotIn('id', $enPlanilla)->get()
                    ->map(fn (AsistenciaEmpleado $e) => [
                        'clave' => 'a'.$e->id,
                        'nombre' => $e->nombreCompleto(),
                        'codigo' => $e->codigo,
                        'ingreso' => $e->fecha_ingreso,
                        'activo' => $e->activo,
                        'descuentos' => null,
                        'huellas' => $e->huellas_activas_count,
                        'marca' => true,
                        'mensual' => null,
                    ])
            );
        }

        $bajoMinimo = $minimo === null ? 0 : $filas
            ->filter(fn (array $f) => $f['activo'] && $f['mensual'] !== null && $f['mensual'] < $minimo)
            ->count();

        return view('personal.empleados', [
            'empleados' => $this->paginar($request, $this->filtrar($request, $filas)),
            'veAsistencia' => $veAsistencia,
            'vePlanilla' => $vePlanilla,
            'verImportes' => $verImportes,
            'minimo' => $minimo,
            'bajoMinimo' => $bajoMinimo,
            'rutasSinFicha' => $request->user()->can('rutas.personal.ver') && $request->user()->can('planilla.ver') && PlanillaEmpleado::enUso()
                ? PersonalRuta::whereNotIn('id', PlanillaEmpleado::whereNotNull('personal_ruta_id')->select('personal_ruta_id'))->orderBy('nombre')->get()
                : collect(),
        ]);
    }

    /**
     * Posibles repetidos de cuando cada módulo daba su propia alta. Solo se muestran: cada
     * grupo se revisa y se enlaza a mano desde la ficha. Lo ve quien gestiona Personal.
     */
    public function repetidos(Request $request, PosiblesRepetidos $repetidos): View
    {
        [$veAsistencia, $vePlanilla] = $this->accesos($request);
        abort_unless($vePlanilla && $request->user()->can('planilla.gestionar'), 403);

        return view('personal.repetidos', ['grupos' => $repetidos->grupos(), 'veAsistencia' => $veAsistencia]);
    }

    /** `$persona` es «p12» (planilla) o «a7» (solo Asistencia). */
    public function show(
        Request $request,
        string $persona,
        SueldoHabitual $sueldos,
        ConsultaAsistencia $consulta,
        HoraOficial $horaOficial,
    ): View {
        [$veAsistencia, $vePlanilla] = $this->accesos($request);
        abort_unless(preg_match('/^([ap])(\d+)$/D', $persona, $partes) === 1, 404);

        $ficha = $partes[1] === 'p' ? PlanillaEmpleado::findOrFail($partes[2]) : null;
        $asistencia = $partes[1] === 'a' ? AsistenciaEmpleado::findOrFail($partes[2]) : $ficha?->asistencia;
        $ficha ??= $vePlanilla ? PlanillaEmpleado::where('asistencia_empleado_id', $asistencia->id)->first() : null;

        // Lo que este usuario no alcanza no existe para esta pantalla.
        $ficha = $vePlanilla ? $ficha : null;
        $asistencia = $veAsistencia ? $asistencia : null;
        abort_if($ficha === null && $asistencia === null, 403);

        $verImportes = $ficha !== null && $request->user()->can('planilla.salarios');
        $datos = [
            'nombre' => $ficha?->nombre ?? $asistencia->nombreCompleto(),
            // Todo lo de la persona en un lugar: su usuario y su ficha de Rutas.
            'ficha' => $ficha?->load(['usuario.roles', 'personalRuta.funciones']),
            'asistencia' => $asistencia,
            'verImportes' => $verImportes,
            'mensual' => $verImportes ? ($sueldos->mensualesEn(new Collection([$ficha]), now()->toDateString())[$ficha->id] ?? null) : null,
            'quincenas' => $ficha === null ? collect() : PlanillaDetalle::where('planilla_empleado_id', $ficha->id)
                ->with('planilla')->latest('id')->limit(12)->get(),
            // Cuentas de antes que todavía no son de nadie: se pueden enlazar acá.
            'usuariosSinFicha' => $ficha?->user_id === null && $ficha?->activo && $request->user()->can('usuarios.gestionar')
                ? User::whereNotIn('id', PlanillaEmpleado::whereNotNull('user_id')->select('user_id'))->with('roles')->orderBy('name')->get()
                : collect(),
            // Fichas de Asistencia de antes que no son de nadie en Personal: al ponerla a
            // marcar se enlaza la suya en vez de crear otra (si no, sale dos veces en «Hoy»).
            'asistenciasSueltas' => $ficha?->asistencia_empleado_id === null && $ficha?->activo && $request->user()->can('asistencia.gestionar')
                ? $this->asistenciasSueltas($ficha)
                : collect(),
            // El historial de su acceso, para quien lo gestiona (las acciones son de AccesoController).
            'actividadesCuenta' => $ficha?->usuario && $request->user()->can('usuarios.gestionar')
                ? $ficha->usuario->activities()->with('causer')->latest()->limit(30)->get()
                : collect(),
        ];

        if ($asistencia === null) {
            return view('personal.ficha', $datos);
        }

        $asistencia->load(['huellas' => fn ($q) => $q->where('activo', true)->with('dispositivo')->orderByDesc('id')]);

        return view('personal.ficha', $datos + [
            'empleado' => $asistencia,
            // Solo lectores ACTIVOS: asignar una ranura de un lector desactivado
            // crearía una asignación que no puede marcar nada.
            'lectores' => AsistenciaDispositivo::query()->where('activo', true)->orderBy('nombre')->get(),

            // Las últimas marcaciones, por la MISMA capa de consulta que usa el
            // historial completo. La ficha no construye su propia consulta: si lo
            // hiciera, el día que cambie el orden o el criterio, las dos pantallas
            // dirían cosas distintas de la misma persona.
            'ultimas' => $consulta->ultimasDe($asistencia->id),
            'zona' => $horaOficial->zona(),

            // La orden viva permite seguir la captura y cancelarla desde la ficha.
            'ordenViva' => AsistenciaOrdenEnrolamiento::query()
                ->where('asistencia_empleado_id', $asistencia->id)
                ->vivas()->with('dispositivo')->latest('id')->first(),
        ]);
    }

    /**
     * Qué módulos alcanza este usuario. Repite lo que hacen `modulo.asistencia` y
     * `modulo.planilla`, porque esta pantalla tiene que abrir con cualquiera de los dos:
     * ambos apagados es 404, y encendidos pero sin permiso es 403.
     *
     * @return array{0: bool, 1: bool}
     */
    private function accesos(Request $request): array
    {
        $asistencia = ModuloSistema::Asistencia->activo();
        // Sin tablas, Planilla no está instalada: esta pantalla sigue por Asistencia.
        $planilla = ModuloSistema::Planilla->activo() && Schema::hasTable('planilla_empleados');

        abort_unless($asistencia || $planilla, 404);
        abort_unless((bool) $request->user()?->activo, 403);

        $veAsistencia = $asistencia && $request->user()->can('asistencia.ver');
        $vePlanilla = $planilla && $request->user()->can('planilla.ver');

        abort_unless($veAsistencia || $vePlanilla, 403);

        return [$veAsistencia, $vePlanilla];
    }

    /**
     * Las fichas de Asistencia sin persona en Personal, las de nombre parecido primero:
     * mismo primer nombre y su primer apellido entre las demás palabras de la ficha.
     *
     * @return Collection<int, array{id: int, nombre: string, activo: bool, parecido: bool}>
     */
    private function asistenciasSueltas(PlanillaEmpleado $ficha): Collection
    {
        $palabras = fn (?string $s) => preg_split('/\s+/', Str::lower(Str::ascii(trim((string) $s))), -1, PREG_SPLIT_NO_EMPTY);
        $deLaFicha = $palabras($ficha->nombre);

        return AsistenciaEmpleado::whereNotIn('id', PlanillaEmpleado::whereNotNull('asistencia_empleado_id')->select('asistencia_empleado_id'))
            ->orderBy('nombres')->orderBy('apellidos')->get()
            ->map(fn (AsistenciaEmpleado $a) => [
                'id' => $a->id, 'nombre' => $a->nombreCompleto(), 'activo' => (bool) $a->activo,
                'parecido' => ($palabras($a->nombres)[0] ?? null) === ($deLaFicha[0] ?? '')
                    && in_array($palabras($a->apellidos)[0] ?? null, array_slice($deLaFicha, 1), true),
            ])
            ->sortByDesc('parecido')->values();
    }

    /**
     * Se cuentan las huellas VIGENTES, no todas: alguien con tres asignaciones históricas
     * y ninguna activa no puede marcar, y la pantalla tiene que decir eso y no «3».
     */
    private function conHuellas($consulta)
    {
        return $consulta->withCount(['huellas as huellas_activas_count' => fn ($q) => $q->where('activo', true)]);
    }

    private function minimoDe(SalarioMinimo $salarioMinimo, string $fecha): ?int
    {
        // Sin mínimo vigente la pantalla lo dice: no es motivo para no abrirla.
        try {
            return $salarioMinimo->vigenteEn($fecha);
        } catch (ValidationException) {
            return null;
        }
    }

    private function filtrar(Request $request, Collection $filas): Collection
    {
        $buscar = Str::lower(Str::ascii(trim((string) $request->query('q'))));

        return $filas
            ->when($buscar !== '', fn ($f) => $f->filter(
                fn (array $fila) => str_contains(Str::lower(Str::ascii($fila['nombre'].' '.$fila['codigo'])), $buscar)
            ))
            ->when($request->filled('activo'), fn ($f) => $f->where('activo', $request->boolean('activo')))
            ->sortBy(fn (array $fila) => Str::lower(Str::ascii($fila['nombre'])))
            ->values();
    }

    private function paginar(Request $request, Collection $filas): LengthAwarePaginator
    {
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $filas->forPage($pagina, self::POR_PAGINA)->values(), $filas->count(), self::POR_PAGINA, $pagina,
            ['path' => $request->url()],
        ))->withQueryString();
    }
}
