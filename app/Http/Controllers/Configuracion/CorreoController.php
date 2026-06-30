<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Support\Dte\PlantillaCorreo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuración del módulo de correo de DTE: auto-envío al ser aceptado por MH,
 * adjuntar el JWS, y la plantilla del cuerpo. Solo administrador (middleware de ruta).
 */
class CorreoController extends Controller
{
    public function edit(): View
    {
        return view('configuracion.correo.edit', [
            'autoEnvio' => Configuracion::getBool('correo.auto_envio', false),
            'adjuntarJws' => Configuracion::getBool('correo.adjuntar_jws', false),
            'plantilla' => Configuracion::get('correo.plantilla') ?? PlantillaCorreo::DEFAULT,
            'variables' => ['{{cliente}}', '{{documento}}', '{{numero_control}}', '{{codigo_generacion}}', '{{fecha}}', '{{empresa}}', '{{total}}'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'plantilla' => ['nullable', 'string', 'max:5000'],
        ]);

        Configuracion::set('correo.auto_envio', $request->boolean('auto_envio'));
        Configuracion::set('correo.adjuntar_jws', $request->boolean('adjuntar_jws'));
        Configuracion::set('correo.plantilla', trim((string) ($datos['plantilla'] ?? '')));

        return back()->with('status', 'Configuración de correo guardada.');
    }
}
