<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración de Contabilidad: correo de contabilidad y si se envía copia
 * (BCC) cuando el usuario usa el flujo manual de "Enviar correo" de un DTE.
 *
 * Guardar aquí NO envía ningún correo: solo persiste la preferencia. La copia
 * viaja como BCC dentro del mismo envío existente (job EnviarDteCorreo), nunca
 * de forma automática ni retroactiva. Solo administrador (middleware de ruta).
 *
 * Lectura y escritura pasan por el Centro de Configuración
 * ({@see \App\Ajustes\Ajustes}), que resuelve las mismas dos claves de la tabla
 * `configuraciones` de siempre —los datos no se movieron— pero añade validación
 * por tipo, comprobación del permiso que exige el nivel y auditoría central.
 *
 * La validación del formulario se mantiene acá porque es la que produce mensajes
 * de error en el campo correcto; la del resolver es la segunda barrera, la que
 * protege a cualquier otro llamador que no venga de este formulario.
 */
class ContabilidadController extends Controller
{
    public function edit(): View
    {
        return view('configuracion.contabilidad.edit', [
            'correoContabilidad' => Ajustes::texto('contabilidad.correo'),
            'enviarCopia' => Ajustes::bool('contabilidad.enviar_copia', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $enviarCopia = $request->boolean('enviar_copia_contabilidad');

        $datos = $request->validate([
            // Si se activa la copia, el correo es obligatorio y válido; si no, opcional.
            'correo_contabilidad' => [$enviarCopia ? 'required' : 'nullable', 'email', 'max:255'],
        ], [], ['correo_contabilidad' => 'correo de contabilidad']);

        // Las dos claves en UNA transacción: si la segunda fallara, no queda la
        // preferencia activada apuntando a un correo que no llegó a guardarse.
        try {
            Ajustes::guardarVarios([
                'contabilidad.correo' => trim((string) ($datos['correo_contabilidad'] ?? '')) ?: null,
                'contabilidad.enviar_copia' => $enviarCopia,
            ]);
        } catch (ValorAjusteInvalidoException $e) {
            // La regla `email` de Laravel y filter_var no coinciden del todo: la
            // primera acepta direcciones sin punto en el dominio ("juan@intranet")
            // que la segunda rechaza, y son las que de verdad usan los consumidores
            // para decidir si envían. Antes ese valor se guardaba y luego lo
            // ignoraba en silencio todo el que iba a mandar el correo; ahora se
            // devuelve al campo como lo que es: un correo que no sirve.
            throw ValidationException::withMessages(['correo_contabilidad' => $e->getMessage()]);
        }

        return back()->with('status', 'Configuración de contabilidad guardada. No se envió ningún correo.');
    }
}
