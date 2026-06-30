<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TipoCliente;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\Importacion\ExportadorDatos;
use App\Services\Importacion\ImportadorProductosPrecios;
use App\Services\Importacion\ImportadorSalas;
use App\Services\Importacion\ResultadoImportacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * Importación/exportación administrativa (CSV) de salas y precios por cliente.
 * Acceso solo administrador (middleware de ruta). No toca facturación ni JSON.
 */
class ImportacionController extends Controller
{
    public function index(): View
    {
        $clientes = Cliente::where('tipo_cliente', TipoCliente::Contribuyente->value)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return view('admin.importaciones.index', compact('clientes'));
    }

    public function importarSalas(Request $request, ImportadorSalas $importador): RedirectResponse
    {
        $cliente = $this->validarYResolverCliente($request);
        $resultado = $importador->importar($cliente, $request->file('archivo')->getRealPath());
        $this->auditarImportacion($request, $cliente, 'salas', $resultado);

        return back()->with('resumen', $resultado->aArray())->with('resumen_titulo', "Importación de salas — {$cliente->nombre}");
    }

    public function importarPrecios(Request $request, ImportadorProductosPrecios $importador): RedirectResponse
    {
        $cliente = $this->validarYResolverCliente($request);
        $resultado = $importador->importar($cliente, $request->file('archivo')->getRealPath());
        $this->auditarImportacion($request, $cliente, 'precios', $resultado);

        return back()->with('resumen', $resultado->aArray())->with('resumen_titulo', "Importación de precios — {$cliente->nombre}");
    }

    /**
     * Registra un resumen de auditoría de la importación (sin guardar el archivo
     * ni datos sensibles). Visible en la pantalla de Auditoría (log "importacion").
     */
    private function auditarImportacion(Request $request, Cliente $cliente, string $tipo, ResultadoImportacion $resultado): void
    {
        activity('importacion')
            ->causedBy($request->user())
            ->performedOn($cliente)
            ->withProperties([
                'tipo' => $tipo, // 'salas' | 'precios'
                'cliente_id' => $cliente->id,
                'cliente' => $cliente->nombre,
                'archivo' => $request->file('archivo')?->getClientOriginalName(),
                'leidas' => $resultado->leidas,
                'creadas' => $resultado->creadas,
                'actualizadas' => $resultado->actualizadas,
                'ignoradas' => $resultado->ignoradas,
                'advertencias' => $resultado->advertencias,
                'errores' => $resultado->errores,
            ])
            ->log("Importación de {$tipo}: {$resultado->creadas} creadas, {$resultado->actualizadas} actualizadas, {$resultado->ignoradas} ignoradas, {$resultado->advertencias} advertencias, {$resultado->errores} errores");
    }

    public function exportarSalas(Request $request, ExportadorDatos $exportador): StreamedResponse
    {
        $cliente = $this->resolverCliente($request);
        $csv = $exportador->salasCsv($cliente);

        return $this->descargar($csv, 'salas-'.$cliente->id.'.csv');
    }

    public function exportarPrecios(Request $request, ExportadorDatos $exportador): StreamedResponse
    {
        $cliente = $this->resolverCliente($request);
        $csv = $exportador->preciosCsv($cliente);

        return $this->descargar($csv, 'precios-'.$cliente->id.'.csv');
    }

    public function plantillaSalas(ExportadorDatos $exportador): StreamedResponse
    {
        return $this->descargar($exportador->plantillaSalasCsv(), 'plantilla-salas.csv');
    }

    public function plantillaPrecios(ExportadorDatos $exportador): StreamedResponse
    {
        return $this->descargar($exportador->plantillaPreciosCsv(), 'plantilla-precios.csv');
    }

    private function validarYResolverCliente(Request $request): Cliente
    {
        $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'archivo' => ['required', 'file', function ($attr, $file, $fail) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (! in_array($ext, ['csv', 'txt'], true)) {
                    $fail('El archivo debe ser CSV (.csv o .txt).');
                }
            }],
        ]);

        return Cliente::findOrFail($request->integer('cliente_id'));
    }

    private function resolverCliente(Request $request): Cliente
    {
        $request->validate(['cliente_id' => ['required', 'integer', 'exists:clientes,id']]);

        return Cliente::findOrFail($request->integer('cliente_id'));
    }

    private function descargar(string $csv, string $nombre): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($csv),
            $nombre,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
