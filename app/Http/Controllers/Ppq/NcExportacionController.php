<?php

namespace App\Http\Controllers\Ppq;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\NcExportacion;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\NcExportacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Formato de notas de crédito del cliente: elegir las notas pendientes —de cualquier
 * fecha—, armar el archivo y llevar registro de lo EXPORTADO.
 *
 * No es una rutina diaria: se entra cuando toca llenar el formato, desde la acción del
 * encabezado de Facturación, y por eso no ocupa un lugar fijo en la navegación.
 *
 * El correo al cliente se manda a mano, fuera del sistema: acá no se envía nada, y el
 * estado del lote nunca dice lo contrario.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal: solo lee documentos ya
 * aceptados por Hacienda y los vuelca al formato que pide el cliente.
 */
class NcExportacionController extends Controller
{
    /** Claves de filtro aceptadas. Todas OPCIONALES: ninguna restringe el lote. */
    private const FILTROS = ['desde', 'hasta', 'tipo', 'sala', 'q'];

    public function __construct(
        private readonly NcExportacionService $exportaciones,
        private readonly PerfilDocumentoResolver $perfiles,
    ) {}

    /** Pendientes (todas, de cualquier fecha), ya exportadas y lotes generados. */
    public function index(Request $request): View
    {
        $clientes = $this->clientesConPerfil();
        $cliente = $this->clienteElegido($request, $clientes);
        $filtros = $this->filtros($request);

        return view('ppq.nc-exportaciones.index', [
            'clientes' => $clientes,
            'cliente' => $cliente,
            'filtros' => $filtros,
            'hayFiltros' => collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty(),
            'pendientes' => $cliente ? $this->exportaciones->pendientes($cliente, $filtros) : collect(),
            'yaEnLote' => $cliente ? $this->exportaciones->yaExportadas($cliente, $filtros) : collect(),
            'salas' => $cliente ? $this->exportaciones->salasPendientes($cliente) : [],
            'lotes' => $cliente
                ? NcExportacion::where('cliente_id', $cliente->id)
                    ->withCount('items')->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }

    /** Crea el lote con las notas marcadas, sean de las fechas que sean. */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'dtes' => ['required', 'array', 'min:1'],
            'dtes.*' => ['integer'],
        ], [], ['dtes' => 'notas de crédito']);

        $cliente = Cliente::findOrFail($datos['cliente_id']);

        try {
            $lote = $this->exportaciones->crear($cliente, $datos['dtes'], $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id])
            ->with('status', "Formato {$lote->referencia} generado con ".$lote->items()->count().' nota(s) de crédito. Descargalo y adjuntalo al correo del cliente.');
    }

    /**
     * Descarga (o vuelve a generar) el archivo del lote. Regenerar NO vuelve a elegir
     * documentos: relee los items del lote, así que bajarlo diez veces da diez archivos
     * iguales y no marca ninguna nota adicional.
     *
     * Deja constancia de la descarga —fecha y contador—, que es lo único comprobable.
     * NO marca el lote como enviado: el correo al cliente se manda a mano, fuera del
     * sistema (ver {@see \App\Enums\EstadoNcExportacion}).
     */
    public function descargar(NcExportacion $lote): BinaryFileResponse
    {
        $lote->loadMissing('cliente');

        $archivo = $this->exportaciones->archivo($lote);

        $lote->registrarDescarga();

        return response()
            ->download($archivo, $lote->archivo_nombre)
            ->deleteFileAfterSend(true);
    }

    /** @return array<string, string> */
    private function filtros(Request $request): array
    {
        $filtros = [];
        foreach (self::FILTROS as $clave) {
            $filtros[$clave] = trim((string) $request->input($clave, ''));
        }

        return $filtros;
    }

    /** @return \Illuminate\Support\Collection<int, Cliente> */
    private function clientesConPerfil(): \Illuminate\Support\Collection
    {
        return Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true)->whereNotNull('formato_export'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nombre_comercial']);
    }

    /** @param \Illuminate\Support\Collection<int, Cliente> $clientes */
    private function clienteElegido(Request $request, \Illuminate\Support\Collection $clientes): ?Cliente
    {
        if ($request->filled('cliente_id')) {
            return $clientes->firstWhere('id', $request->integer('cliente_id'));
        }

        // Con un solo cliente configurado, elegirlo solo evita un clic que nunca aporta.
        return $clientes->count() === 1 ? $clientes->first() : null;
    }
}
