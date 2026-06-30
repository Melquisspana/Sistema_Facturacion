<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\EmpresaRequest;
use App\Models\ActividadEconomica;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Municipio;
use App\Models\Pais;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmpresaController extends Controller
{
    /** Muestra (y permite editar) los datos del emisor. Es un registro único. */
    public function edit(): View
    {
        $empresa = Empresa::first();

        return view('configuracion.empresa.edit', [
            'empresa' => $empresa,
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(),
            'municipios' => Municipio::where('activo', true)->orderBy('nombre')->get(),
            'distritos' => \App\Models\Distrito::where('activo', true)->orderBy('municipio')->orderBy('nombre')
                ->get(['id', 'nombre', 'municipio', 'departamento_id']),
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
