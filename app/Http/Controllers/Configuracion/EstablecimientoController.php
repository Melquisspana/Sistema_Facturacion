<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\TipoEstablecimiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\EstablecimientoRequest;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Pais;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EstablecimientoController extends Controller
{
    public function index(): View
    {
        $establecimientos = Establecimiento::with('empresa')->orderBy('codigo')->get();

        return view('configuracion.establecimientos.index', compact('establecimientos'));
    }

    public function create(): View
    {
        return view('configuracion.establecimientos.form', $this->datosFormulario(new Establecimiento(['activo' => true])));
    }

    public function store(EstablecimientoRequest $request): RedirectResponse
    {
        Establecimiento::create($request->validated());

        return redirect()
            ->route('configuracion.establecimientos.index')
            ->with('status', 'Establecimiento creado correctamente.');
    }

    public function edit(Establecimiento $establecimiento): View
    {
        return view('configuracion.establecimientos.form', $this->datosFormulario($establecimiento));
    }

    public function update(EstablecimientoRequest $request, Establecimiento $establecimiento): RedirectResponse
    {
        $establecimiento->update($request->validated());

        return redirect()
            ->route('configuracion.establecimientos.index')
            ->with('status', 'Establecimiento actualizado correctamente.');
    }

    public function destroy(Establecimiento $establecimiento): RedirectResponse
    {
        // Soft delete. Más adelante se bloqueará si tiene documentos relacionados.
        if ($establecimiento->puntosVenta()->exists() || $establecimiento->correlativos()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene puntos de venta o correlativos asociados. Desactívelo en su lugar.');
        }

        $establecimiento->delete();

        return redirect()
            ->route('configuracion.establecimientos.index')
            ->with('status', 'Establecimiento eliminado.');
    }

    private function datosFormulario(Establecimiento $establecimiento): array
    {
        return [
            'establecimiento' => $establecimiento,
            'empresas' => Empresa::orderBy('razon_social')->get(),
            'tiposEstablecimiento' => TipoEstablecimiento::opciones(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(),
            'municipios' => Municipio::where('activo', true)->orderBy('nombre')->get(),
            'distritos' => Distrito::where('activo', true)->orderBy('municipio')->orderBy('nombre')
                ->get(['id', 'nombre', 'municipio', 'departamento_id']),
        ];
    }
}
