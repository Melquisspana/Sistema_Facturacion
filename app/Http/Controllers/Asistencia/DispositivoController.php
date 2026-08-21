<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\DispositivoRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Services\Asistencia\RotarTokenDispositivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lectores biométricos. Alta, edición, activación y rotación del token.
 *
 * ─────────────────── EL TOKEN SE VE UNA VEZ Y NUNCA MÁS ───────────────────
 *
 * En base solo vive su SHA-256. El valor en claro existe en dos momentos: cuando
 * se genera y dentro del firmware. Esta pantalla lo enseña UNA vez, en un aviso
 * que llega por `flash` —así que desaparece con la siguiente petición, incluso si
 * el usuario recarga— y no hay ninguna ruta que lo devuelva después. Si se
 * pierde, no se recupera: se rota otra vez, que es exactamente lo que hay que
 * hacer si alguien pudo haberlo visto.
 *
 * `token_hash` está en `$hidden` del modelo y no llega a ninguna vista.
 *
 * ────────────────────── Rotar es una pantalla, no un botón ──────────────────────
 *
 * Rotar deja al lector de la puerta sin autenticar hasta que alguien reprograme
 * el firmware: mientras tanto, nadie puede marcar. Un `confirm()` del navegador no
 * está a la altura de eso, así que la rotación tiene su propia pantalla y exige
 * ESCRIBIR el código del lector. Es el mismo criterio de la confirmación fuerte
 * del Centro de Configuración: quien escribe el código leyó qué lector es.
 */
class DispositivoController extends Controller
{
    public function index(Request $request): View
    {
        $dispositivos = AsistenciaDispositivo::query()
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->withCount(['huellas as huellas_activas_count' => fn ($q) => $q->where('activo', true)])
            ->orderBy('nombre')
            ->get();

        return view('asistencia.dispositivos.index', ['dispositivos' => $dispositivos]);
    }

    public function create(): View
    {
        return view('asistencia.dispositivos.create', ['dispositivo' => new AsistenciaDispositivo]);
    }

    /**
     * El alta GENERA el token. No se pide en el formulario: si se pudiera escribir,
     * existiría un camino para fijar uno débil o repetido.
     */
    public function store(DispositivoRequest $request): RedirectResponse
    {
        $token = AsistenciaDispositivo::generarToken();

        $dispositivo = AsistenciaDispositivo::create($request->validated() + [
            'token_hash' => AsistenciaDispositivo::hashDeToken($token),
            'activo' => true,
        ]);

        return redirect()
            ->route('asistencia.dispositivos.index')
            ->with('status', "Lector «{$dispositivo->nombre}» dado de alta.")
            // El único momento en que el valor en claro sale del servidor.
            ->with('token_generado', $token)
            ->with('token_generado_codigo', $dispositivo->codigo);
    }

    public function edit(AsistenciaDispositivo $dispositivo): View
    {
        return view('asistencia.dispositivos.edit', ['dispositivo' => $dispositivo]);
    }

    /**
     * Editar NO toca el token. Cambiar el CÓDIGO sí deja al firmware sin
     * autenticar —manda el anterior en la cabecera X-Dispositivo—, y la pantalla
     * lo avisa antes de guardar.
     */
    public function update(DispositivoRequest $request, AsistenciaDispositivo $dispositivo): RedirectResponse
    {
        $codigoAnterior = $dispositivo->codigo;

        $dispositivo->update($request->validated());

        $mensaje = "Lector «{$dispositivo->nombre}» actualizado.";

        if ($dispositivo->codigo !== $codigoAnterior) {
            $mensaje .= " El código cambió de «{$codigoAnterior}» a «{$dispositivo->codigo}»: "
                .'hay que actualizarlo en el firmware o el lector dejará de autenticar.';
        }

        return redirect()->route('asistencia.dispositivos.index')->with('status', $mensaje);
    }

    public function toggleActivo(AsistenciaDispositivo $dispositivo): RedirectResponse
    {
        $dispositivo->update(['activo' => ! $dispositivo->activo]);

        return back()->with('status', $dispositivo->activo
            ? "Lector «{$dispositivo->nombre}» reactivado: vuelve a autenticar con su token actual."
            : "Lector «{$dispositivo->nombre}» desactivado: sus peticiones responden «no autorizado». Su historial se conserva.");
    }

    /** Pantalla de confirmación fuerte. No rota nada: solo explica y pide el código. */
    public function confirmarRotacion(AsistenciaDispositivo $dispositivo): View
    {
        return view('asistencia.dispositivos.rotar-token', ['dispositivo' => $dispositivo]);
    }

    /**
     * Rota el token. La confirmación se valida acá y no en un FormRequest porque
     * la regla depende del lector concreto: hay que escribir SU código, y eso el
     * request lo sabría solo repitiendo la búsqueda que el enrutador ya hizo.
     */
    public function rotarToken(Request $request, AsistenciaDispositivo $dispositivo, RotarTokenDispositivo $rotar): RedirectResponse
    {
        $request->validate(
            ['confirmacion' => ['required', 'string']],
            [],
            ['confirmacion' => 'confirmación'],
        );

        if (trim((string) $request->input('confirmacion')) !== $dispositivo->codigo) {
            return back()
                ->withErrors(['confirmacion' => 'Escribí exactamente el código del lector: '.$dispositivo->codigo])
                ->withInput();
        }

        $token = $rotar($dispositivo);

        return redirect()
            ->route('asistencia.dispositivos.index')
            ->with('status', "Token del lector «{$dispositivo->nombre}» rotado. El firmware anterior ya no autentica.")
            ->with('token_generado', $token)
            ->with('token_generado_codigo', $dispositivo->codigo);
    }
}
