<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\NumeroParaMarcarRequest;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\NumeroParaMarcar;
use Illuminate\Http\RedirectResponse;

/**
 * Poner, cambiar o quitar el número para marcar desde la ficha de la persona. Las reglas
 * viven en {@see NumeroParaMarcar}; acá solo se traduce a un mensaje. Ningún mensaje
 * repite el número: después de guardarlo no se vuelve a mostrar.
 */
class NumeroParaMarcarController extends Controller
{
    public function update(NumeroParaMarcarRequest $request, AsistenciaEmpleado $empleado, NumeroParaMarcar $numeros): RedirectResponse
    {
        $tenia = $empleado->tieneNumeroMarcar();
        $numeros->asignar($empleado, (string) $request->validated('numero'));

        return back()->with('status', ($tenia ? 'Número para marcar cambiado' : 'Número para marcar guardado')
            ." para {$empleado->nombreCompleto()}. Ya no se vuelve a mostrar; si lo olvida, ponele otro.");
    }

    public function destroy(AsistenciaEmpleado $empleado, NumeroParaMarcar $numeros): RedirectResponse
    {
        if (! $numeros->quitar($empleado)) {
            return back()->with('error', 'Esta persona no tenía número para marcar; no se cambió nada.');
        }

        return back()->with('status', "Número para marcar quitado. {$empleado->nombreCompleto()} marca solo con huella.");
    }
}
