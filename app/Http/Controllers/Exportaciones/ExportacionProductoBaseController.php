<?php

namespace App\Http\Controllers\Exportaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\PresentacionExportacionRequest;
use App\Http\Requests\Exportaciones\ProductoBaseExportacionRequest;
use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use App\Services\Exportaciones\CatalogoExportacion;
use App\Services\Exportaciones\ListaPreciosExportacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * El producto base de exportación («Maní dulce»): sus nombres, su categoría y el
 * alta de presentaciones nuevas. Las presentaciones existentes se editan en
 * {@see ExportacionProductoController}.
 */
class ExportacionProductoBaseController extends Controller
{
    public function edit(ExportacionProductoBase $base): View
    {
        return view('productos.exportacion.base', ['base' => $base->load('presentaciones')]);
    }

    public function update(ProductoBaseExportacionRequest $request, ExportacionProductoBase $base, CatalogoExportacion $catalogo): RedirectResponse
    {
        $catalogo->actualizarBase($base, $request->validated());

        return $this->volver($base, "«{$base->nombre_es}» actualizado. Las listas ya hechas conservan el nombre con el que se armaron.");
    }

    public function toggleActivo(ExportacionProductoBase $base, CatalogoExportacion $catalogo): RedirectResponse
    {
        $catalogo->alternarActivo($base);

        return back()->with('status', $base->activo
            ? "«{$base->nombre_es}» reactivado con todas sus presentaciones."
            : "«{$base->nombre_es}» archivado: ya no aparece al armar listas nuevas, pero conserva sus precios y su histórico.");
    }

    public function createPresentacion(ExportacionProductoBase $base): View
    {
        return view('productos.exportacion.presentacion', [
            'base' => $base,
            'presentacion' => new ExportacionProducto(['activo' => true]),
            'clientes' => app(ListaPreciosExportacion::class)->clientesActivos(),
        ]);
    }

    public function storePresentacion(PresentacionExportacionRequest $request, ExportacionProductoBase $base, CatalogoExportacion $catalogo, ListaPreciosExportacion $precios): RedirectResponse
    {
        $datos = $request->validated();
        $presentacion = DB::transaction(function () use ($catalogo, $precios, $base, $datos) {
            $presentacion = $catalogo->agregarPresentacion($base, $datos);
            $precios->sincronizarClientes($presentacion, $datos['clientes'] ?? []);

            return $presentacion;
        });

        return $this->volver($base, "Presentación agregada: {$base->nombre_es}, {$presentacion->etiquetaEmpaque()}.");
    }

    private function volver(ExportacionProductoBase $base, string $mensaje): RedirectResponse
    {
        return redirect()
            ->to(route('productos.exportacion.index').'#producto-'.$base->id)
            ->with('status', $mensaje);
    }
}
