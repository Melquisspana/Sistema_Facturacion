<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use App\Services\Gastos\Recurrencia\RepetirGasto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reglas recurrentes: la pantalla de «esto vuelve todos los meses».
 *
 * Una regla NO es una deuda, y la pantalla lo dice todo el tiempo: muestra qué
 * períodos ya se generaron, cuáles vienen y cuáles se omitieron, sin un solo total
 * de dinero pendiente. Lo que se debe se mira en Gastos, sobre las obligaciones
 * reales.
 *
 * Generar a mano existe porque el proceso automático arranca apagado: hay que poder
 * usar el módulo sin encender un cron que crea deuda sola. El botón hace lo mismo
 * que la tarea programada y con los mismos candados de unicidad.
 */
class ReglaController extends Controller
{
    public function index(Request $request, CalendarioRecurrencia $calendario)
    {
        $usuario = $request->user();

        $reglas = Regla::query()
            ->when(! $usuario->can('gastos.personales'), fn ($q) => $q->where('ambito', 'empresarial'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->withCount(['ocurrencias as generadas' => fn ($q) => $q->where('estado', 'generada')])
            ->orderByRaw("CASE estado WHEN 'activa' THEN 0 WHEN 'pausada' THEN 1 ELSE 2 END")
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('gastos.reglas.index', [
            'reglas' => $reglas,
            'calendario' => $calendario,
            'estado' => $request->string('estado')->toString(),
            'hoy' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    public function create(Request $request, CalendarioRecurrencia $calendario)
    {
        abort_unless($request->user()->can('gastos.recurrencias'), 403);

        return view('gastos.reglas.create', [
            'clave' => (string) Str::uuid(),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'calendario' => $calendario,
            'hoy' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    public function store(Request $request, AdministrarReglas $administrar)
    {
        $regla = $administrar->crear($request->user(), $request->all());

        return redirect()
            ->route('gastos.reglas.show', $regla)
            ->with('gastos.aviso', 'Listo. Todavía no generó ninguna obligación: revisá los próximos períodos antes de generarlos.');
    }

    public function show(Request $request, Regla $regla, AdministrarReglas $administrar, CalendarioRecurrencia $calendario)
    {
        $this->autorizarVer($request, $regla);

        return view('gastos.reglas.show', [
            'regla' => $regla,
            // Cuándo cae el siguiente. Es lo primero que quiere saber quien abre esto.
            'proximo' => app(RepetirGasto::class)
                ->proximoVencimiento($regla, CarbonImmutable::now()),
            'calendario' => $calendario,
            'proximos' => $administrar->proximosPeriodos($regla, CarbonImmutable::now()),
            'ocurrencias' => $regla->ocurrencias()->with('gasto')->limit(60)->get(),
            'versiones' => $regla->versiones()->with('registrador')->get(),
            'hoy' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    public function edit(Request $request, Regla $regla, CalendarioRecurrencia $calendario)
    {
        abort_unless($request->user()->can('gastos.recurrencias'), 403);
        $this->autorizarVer($request, $regla);

        return view('gastos.reglas.edit', [
            'regla' => $regla,
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'calendario' => $calendario,
            'yaGenero' => $regla->ocurrencias()->exists(),
        ]);
    }

    public function update(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);

        $administrar->actualizar($request->user(), $regla, $request->all(), (string) $request->input('motivo', ''));

        return redirect()
            ->route('gastos.reglas.show', $regla)
            ->with('gastos.aviso', 'Cambios guardados. Las obligaciones ya generadas quedaron como estaban: el cambio rige de acá en adelante.');
    }

    public function pausar(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);
        $administrar->pausar($request->user(), $regla, (string) $request->input('motivo', ''));

        return back()->with('gastos.aviso', 'Pausado. No generará períodos nuevos; las obligaciones que ya creó siguen vigentes.');
    }

    public function reanudar(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);
        $administrar->reanudar($request->user(), $regla, (string) $request->input('motivo', ''));

        return back()->with('gastos.aviso', 'Reanudado. Revisá los períodos pendientes antes de generarlos.');
    }

    /**
     * Completa lo que le faltaba a una regla «Por completar» y la activa.
     *
     * Hasta este momento la regla existía pero no generaba nada. A partir de acá sí,
     * así que es una acción con consecuencias y por eso va por su propia ruta.
     *
     * `beneficiario` viaja con la programación porque el dato pendiente no siempre es el
     * día: en las reglas guardadas «a confirmar el nombre» es justamente a quién se le
     * paga. Se corrige sobre la misma regla —una versión más— en vez de obligar a crear
     * otra al lado.
     */
    public function activar(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);

        $regla = $administrar->completarYActivar($request->user(), $regla, $request->only([
            'dia_semana', 'dia_mes', 'dia_mes_2', 'mes', 'beneficiario',
        ]));

        // Se dice DESDE CUÁNDO rige, porque es la pregunta que sigue: la vigencia es la
        // que se guardó, no el día en que alguien terminó de llenar el formulario.
        return back()->with('gastos.aviso',
            'Listo: la repetición quedó activa y vigente desde el '.$regla->vigente_desde->format('Y-m-d')
            .($regla->vigente_hasta !== null ? ' hasta el '.$regla->vigente_hasta->format('Y-m-d') : '')
            .'. Todavía no creó ninguna obligación: revisá «Lo que viene» y generá vos los períodos que correspondan.');
    }

    public function cancelar(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);
        $administrar->cancelar($request->user(), $regla, (string) $request->input('motivo', ''));

        return back()->with('gastos.aviso', 'Cancelado. Lo que ya generó no se tocó: si alguna obligación no corresponde, resolvela una por una.');
    }

    public function omitir(Request $request, Regla $regla, AdministrarReglas $administrar)
    {
        $this->autorizarVer($request, $regla);

        $administrar->omitir(
            $request->user(),
            $regla,
            (string) $request->input('periodo', ''),
            (string) $request->input('motivo', ''),
        );

        return back()->with('gastos.aviso', 'Período saltado. La generación automática ya no va a crearlo.');
    }

    /**
     * Generar a mano lo que le toca a esta regla. Mismo servicio y mismos candados
     * que la tarea programada; no depende del interruptor del cron, porque acá hay
     * una persona apretando el botón y asumiendo el resultado.
     */
    public function generar(Request $request, Regla $regla, GenerarObligaciones $generador)
    {
        abort_unless($request->user()->can('gastos.recurrencias'), 403);
        $this->autorizarVer($request, $regla);

        $resultado = $generador->paraRegla($regla, CarbonImmutable::now(), $request->user());

        if ($resultado['motivo_omision'] !== null) {
            return back()->withErrors(['regla' => $resultado['motivo_omision'].' No se generó nada.']);
        }

        $aviso = $resultado['generadas'] === 0
            ? 'No había ningún período pendiente de generar.'
            : $resultado['generadas'].' obligación(es) creada(s), todas SIN pagar: '.implode(', ', $resultado['periodos']).'.';

        if ($resultado['fuera_de_ventana'] !== []) {
            $aviso .= ' Quedaron '.count($resultado['fuera_de_ventana'])
                .' período(s) anteriores a la ventana de recuperación sin generar; revisalos antes de crearlos.';
        }

        return back()->with('gastos.aviso', $aviso);
    }

    /** Una regla personal no se abre sin alcance, igual que un gasto personal. */
    private function autorizarVer(Request $request, Regla $regla): void
    {
        abort_unless(
            $regla->ambito === 'empresarial' || $request->user()->can('gastos.personales'),
            403,
        );
    }
}
