<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\AmbienteHacienda;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\EmpresaRequest;
use App\Models\ActividadEconomica;
use App\Models\Empresa;
use App\Models\Pais;
use App\Support\Ubicacion\OpcionesUbicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Datos del emisor (registro único).
 *
 * Esta pantalla NO cambia el ambiente fiscal. La columna `empresas.ambiente` sigue
 * existiendo en la tabla, pero ningún consumidor fiscal la lee: el ambiente del JSON
 * del MH sale de `config('dte.ambiente')`. Aquí solo se MUESTRA ese valor real, en
 * solo lectura, y `EmpresaRequest` no acepta el campo, así que ni un POST manual
 * puede escribirlo desde este controlador.
 */
class EmpresaController extends Controller
{
    /** Muestra (y permite editar) los datos del emisor. Es un registro único. */
    public function edit(): View
    {
        $empresa = Empresa::first();
        $codigoAmbiente = (string) config('dte.ambiente');
        $ambiente = AmbienteHacienda::tryFrom($codigoAmbiente);

        return view('configuracion.empresa.edit', [
            'empresa' => $empresa,
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            // Ambiente fiscal REAL, solo para mostrar. Nunca se guarda desde aquí.
            'ambienteFiscalCodigo' => $codigoAmbiente,
            'ambienteFiscalEtiqueta' => $ambiente?->label() ?? 'Desconocido',
            'ambienteFiscalEsProduccion' => $ambiente === AmbienteHacienda::Produccion,
            ...OpcionesUbicacion::todas(),
        ]);
    }

    public function update(EmpresaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $empresa = Empresa::first();
        if ($empresa) {
            $empresa->update($datos);
        } else {
            Empresa::create($datos);
        }

        return redirect()
            ->route('configuracion.empresa.edit')
            ->with('status', 'Datos del emisor guardados correctamente.');
    }
}
