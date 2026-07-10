<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuración de Contabilidad: correo de contabilidad y si se envía copia
 * (BCC) cuando el usuario usa el flujo manual de "Enviar correo" de un DTE.
 *
 * Guardar aquí NO envía ningún correo: solo persiste la preferencia. La copia
 * viaja como BCC dentro del mismo envío existente (job EnviarDteCorreo), nunca
 * de forma automática ni retroactiva. Solo administrador (middleware de ruta).
 */
class ContabilidadController extends Controller
{
    public function edit(): View
    {
        return view('configuracion.contabilidad.edit', [
            'correoContabilidad' => Configuracion::get('contabilidad.correo'),
            'enviarCopia' => Configuracion::getBool('contabilidad.enviar_copia', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $enviarCopia = $request->boolean('enviar_copia_contabilidad');

        $datos = $request->validate([
            // Si se activa la copia, el correo es obligatorio y válido; si no, opcional.
            'correo_contabilidad' => [$enviarCopia ? 'required' : 'nullable', 'email', 'max:255'],
        ], [], ['correo_contabilidad' => 'correo de contabilidad']);

        Configuracion::set('contabilidad.correo', trim((string) ($datos['correo_contabilidad'] ?? '')) ?: null);
        Configuracion::set('contabilidad.enviar_copia', $enviarCopia);

        return back()->with('status', 'Configuración de contabilidad guardada. No se envió ningún correo.');
    }
}
