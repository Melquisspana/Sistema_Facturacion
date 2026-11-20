<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\TipoEstablecimiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\EstablecimientoRequest;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Pais;
use App\Support\Ubicacion\OpcionesUbicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        $datos = $request->validated();

        // El código de uno borrado sigue ocupado en la base y es el que tiene Hacienda: se
        // recupera ese con los datos nuevos. Para borrarlo no podía tener puntos de venta,
        // correlativos ni documentos, así que no trae nada colgado.
        $borrado = Establecimiento::onlyTrashed()
            ->where('empresa_id', $datos['empresa_id'])
            ->where('codigo', $datos['codigo'])
            ->first();
        if ($borrado !== null) {
            DB::transaction(function () use ($borrado, $datos) {
                $borrado->restore();
                $borrado->update($datos);
            });

            return redirect()
                ->route('configuracion.establecimientos.index')
                ->with('status', "Se recuperó el establecimiento {$borrado->codigo}, que se había borrado, con los datos nuevos.");
        }

        Establecimiento::create($datos);

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
        // Borrado suave. Con documentos tampoco: su número de control saldría con códigos
        // de relleno al no encontrar el establecimiento.
        if ($establecimiento->puntosVenta()->exists() || $establecimiento->correlativos()->exists() || $establecimiento->tieneDocumentos()) {
            return back()->with('error', 'No se puede eliminar: tiene puntos de venta, correlativos, facturas o borradores. Desactivalo en su lugar.');
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
            ...OpcionesUbicacion::todas(),
        ];
    }
}
