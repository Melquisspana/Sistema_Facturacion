<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Aviso;
use App\Models\Gastos\PreferenciaAvisos;
use App\Models\Gastos\Resumen;
use App\Services\Gastos\Avisos\ArmarAvisos;
use App\Services\Gastos\Avisos\EnviarResumenes;
use App\Support\Correo\CandadoCorreoReal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bandeja interna de avisos y preferencias de cada persona.
 *
 * La bandeja es ESTRICTAMENTE propia: solo se leen y se marcan los avisos del
 * usuario autenticado. No hay pantalla para ver la bandeja de otro, ni siquiera con
 * permiso de administrar, porque un aviso puede nombrar un gasto personal de quien
 * lo recibió.
 *
 * Marcar leído no cambia ningún saldo. Un aviso no es una deuda: es un recordatorio
 * de una deuda que vive en otra tabla y se paga por otro camino.
 */
class AvisoController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();

        $avisos = Aviso::query()
            ->where('usuario_id', $usuario->id)
            ->when($request->boolean('todos') === false, fn ($q) => $q->whereNull('leido_at'))
            ->with('gasto')
            ->orderByRaw("CASE tipo WHEN 'vencido' THEN 0 WHEN 'vence' THEN 1 ELSE 2 END")
            ->orderBy('vence')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('gastos.avisos.index', [
            'avisos' => $avisos,
            'todos' => $request->boolean('todos'),
            'sinLeer' => Aviso::where('usuario_id', $usuario->id)->whereNull('leido_at')->count(),
        ]);
    }

    public function leer(Request $request, Aviso $aviso)
    {
        // El aviso de otro no se toca ni se confirma que exista.
        abort_unless($aviso->usuario_id === $request->user()->id, 404);

        if (! $aviso->leido()) {
            $aviso->update(['leido_at' => now()]);
        }

        return back();
    }

    public function leerTodos(Request $request)
    {
        Aviso::where('usuario_id', $request->user()->id)
            ->whereNull('leido_at')
            ->update(['leido_at' => now()]);

        return back()->with('gastos.aviso', 'Avisos marcados como leídos. Ninguna deuda cambió: solo se limpió la bandeja.');
    }

    public function preferencias(Request $request, CandadoCorreoReal $candado, EnviarResumenes $resumenes, ArmarAvisos $armar)
    {
        $usuario = $request->user();
        $prefs = PreferenciaAvisos::de($usuario);

        return view('gastos.avisos.preferencias', [
            'prefs' => $prefs,
            'puedePersonales' => $usuario->can('gastos.personales'),
            'correoSimulado' => $candado->debeSimular(),
            'avisoCorreo' => $candado->debeSimular() ? $candado->avisoInterfaz() : null,
            // Vista previa de lo que HOY tendría este resumen. Sirve para que nadie
            // encienda el correo a ciegas: si acá dice cero, no le va a llegar nada.
            'previa' => $resumenes->contenido($armar->ambitosDe($usuario, $prefs), CarbonImmutable::now()),
            'ultimos' => Resumen::where('usuario_id', $usuario->id)->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function guardarPreferencias(Request $request)
    {
        $usuario = $request->user();

        $datos = Validator::make($request->all(), [
            'activo' => ['nullable', 'boolean'],
            'correo' => ['nullable', 'boolean'],
            'dias_anticipacion' => ['required', 'array', 'min:1', 'max:6'],
            'dias_anticipacion.*' => ['integer', 'between:0,90'],
            'resumen' => ['required', Rule::in(array_keys(PreferenciaAvisos::RESUMENES))],
            'resumen_dia_semana' => ['required', 'integer', 'between:1,7'],
            'ambitos' => ['required', 'array', 'min:1'],
            'ambitos.*' => [Rule::in(['empresarial', 'personal'])],
        ], [
            'dias_anticipacion.required' => 'Elegí al menos un día de anticipación, o desactivá los avisos.',
            'ambitos.required' => 'Elegí al menos un ámbito.',
        ])->validate();

        // Los días se guardan ordenados y sin repetidos: «7, 7, 3» no significa nada
        // distinto de «7, 3» y ensuciaría la comparación al armar los avisos.
        $dias = array_values(array_unique(array_map('intval', $datos['dias_anticipacion'])));
        rsort($dias);

        // Pedir «personal» sin alcance no se guarda. Silenciarlo acá evita una
        // preferencia que promete algo que el permiso nunca va a entregar.
        $ambitos = array_values(array_intersect(
            $datos['ambitos'],
            $usuario->can('gastos.personales') ? ['empresarial', 'personal'] : ['empresarial'],
        ));

        PreferenciaAvisos::updateOrCreate(['usuario_id' => $usuario->id], [
            'activo' => $request->boolean('activo'),
            'correo' => $request->boolean('correo'),
            'dias_anticipacion' => $dias,
            'resumen' => $datos['resumen'],
            'resumen_dia_semana' => $datos['resumen_dia_semana'],
            'ambitos' => $ambitos === [] ? ['empresarial'] : $ambitos,
        ]);

        return redirect()
            ->route('gastos.avisos.preferencias')
            ->with('gastos.aviso', 'Preferencias guardadas.');
    }
}
