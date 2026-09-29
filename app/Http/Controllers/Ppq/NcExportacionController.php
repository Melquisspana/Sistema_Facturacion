<?php

namespace App\Http\Controllers\Ppq;

use App\Enums\EstadoNcExportacion;
use App\Exceptions\CopiaArchivadaInservibleException;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\NcExportacion;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNc;
use App\Services\Ppq\NcExportacionService;
use App\Services\RegistroDescargas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Formato de notas de crédito del cliente: elegir las notas pendientes —de cualquier
 * fecha—, armar el archivo y llevar registro de lo EXPORTADO.
 *
 * No es una rutina diaria: se entra cuando toca llenar el formato, desde la acción del
 * encabezado de Facturación, y por eso no ocupa un lugar fijo en la navegación.
 *
 * La entrega se hace a mano, fuera del sistema —por correo o subiendo el archivo al portal
 * del cliente, según el formato—: acá no se envía ni se carga nada, y el estado del lote
 * nunca dice lo contrario. Cómo se entrega cada formato lo declara el propio exportador
 * ({@see ExportadorNc::entrega()}); la pantalla solo repite
 * lo que el formato activo responda, para que no haya un texto fijo que envejezca mal
 * cuando el cliente cambia de formato.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal: solo lee documentos ya
 * aceptados por Hacienda y los vuelca al formato que pide el cliente.
 */
class NcExportacionController extends Controller
{
    /** Claves de filtro aceptadas. Todas OPCIONALES: ninguna restringe el lote. */
    private const FILTROS = ['desde', 'hasta', 'tipo', 'sala', 'q'];

    /** Archivos por página del historial. */
    private const LOTES_POR_PAGINA = 20;

    /** NC pendientes por página. */
    private const PENDIENTES_POR_PAGINA = 25;

    /** NC por página en la ficha de un lote. */
    private const NOTAS_POR_PAGINA = 50;

    public function __construct(
        private readonly NcExportacionService $exportaciones,
        private readonly PerfilDocumentoResolver $perfiles,
    ) {}

    /**
     * Pendientes (de cualquier fecha) y el historial de archivos, cada lista paginada con
     * su propio parámetro: cambiar de página en una no mueve la otra. Las NC ya exportadas
     * no se listan acá: se consultan en la ficha de su lote.
     */
    public function index(Request $request): View
    {
        $clientes = $this->clientesConPerfil();
        $cliente = $this->clienteElegido($request, $clientes);
        $filtros = $this->filtros($request);
        $pendientes = $cliente
            ? $this->exportaciones->pendientesPaginadas($cliente, $filtros, self::PENDIENTES_POR_PAGINA, 'pendientes_page')
            : null;
        $exportador = $cliente ? $this->exportaciones->exportador($cliente) : null;

        return view('ppq.nc-exportaciones.index', [
            'clientes' => $clientes,
            'cliente' => $cliente,
            'filtros' => $filtros,
            'hayFiltros' => collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty(),
            'pendientes' => $pendientes,
            // Qué formato se le arma HOY a este cliente y cómo se entrega. Null si su
            // perfil quedó sin formato utilizable: la pantalla se ve igual, sin el texto.
            'formato' => $exportador ? [
                'nombre' => $exportador::nombre(),
                'entrega' => $exportador::entrega(),
            ] : null,
            // Datos que le faltan a cada pendiente DE ESTA PÁGINA, antes de generar.
            'faltantes' => $pendientes ? $this->exportaciones->faltantes($cliente, $pendientes->getCollection()) : [],
            'salas' => $cliente ? $this->exportaciones->salasPendientes($cliente) : [],
            // Historial de ARCHIVOS, una fila por lote y paginado con su propio parámetro
            // para no pisar otras listas. Ya no se cargan todas las NC exportadas: el
            // detalle de cada lote está en su ficha.
            'lotes' => $cliente ? $this->historial($cliente) : null,
        ]);
    }

    /**
     * Ficha de un lote: sus NC (por su propio vínculo, nunca re-elegidas), la suma
     * cotejable y la descarga. Solo lectura: abrirla no cambia estado ni descargas.
     */
    public function show(NcExportacion $lote): View
    {
        $lote->loadMissing(['cliente:id,nombre', 'usuario:id,name', 'presentadaPor:id,name'])
            ->loadCount(['items', 'dtes'])
            ->loadSum('dtes as total_notas', 'total_pagar');

        $items = $lote->items()
            ->with(['dte:id,numero_control,fecha_emision,total_pagar', 'dte.albaran:id,dte_id,numero_canonico,tipo_codigo,sala_codigo'])
            ->orderBy('orden')
            ->orderBy('id')
            ->paginate(self::NOTAS_POR_PAGINA, ['*'], 'notas_page')
            ->withQueryString();

        $registro = app(RegistroDescargas::class);

        return view('ppq.nc-exportaciones.show', [
            'lote' => $lote,
            'items' => $items,
            // Solo lectura: abrir la ficha no registra nada.
            'descargas' => $registro->historial($lote),
            'descargasRegistradas' => $registro->cantidad($lote),
        ]);
    }

    /**
     * Una fila por lote, con cantidad de NC y suma de su `total_pagar` calculadas en SQL
     * (dos subconsultas por página, no una por lote).
     *
     * @return LengthAwarePaginator<NcExportacion>
     */
    private function historial(Cliente $cliente): LengthAwarePaginator
    {
        return NcExportacion::where('cliente_id', $cliente->id)
            ->with('cliente:id,nombre')
            ->withCount('items')
            ->withSum('dtes as total_notas', 'total_pagar')
            ->latest('id')
            ->paginate(self::LOTES_POR_PAGINA, ['*'], 'lotes_page')
            ->withQueryString();
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

        // Qué hacer con el archivo depende del formato: uno se adjunta a un correo y otro
        // se sube al portal. Lo dice el exportador, no un texto fijo de la pantalla.
        $siguiente = $this->exportaciones->exportador($cliente)?->entrega() ?? '';

        return redirect()
            ->route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id])
            ->with('status', trim("Formato {$lote->referencia} generado con ".$lote->items()->count()
                .' nota(s) de crédito. '.$siguiente));
    }

    /**
     * Descarga el archivo del lote: la primera vez se genera con el formato con el que nació
     * y se archiva; después se sirve la copia archivada, verificada por su huella
     * ({@see NcExportacionService::archivo()}). Nunca vuelve a elegir notas.
     *
     * Deja constancia de la descarga —fecha y contador— SOLO si el archivo quedó preparado
     * para servirse: si la copia falta o no cuadra, falla antes de contar. Esa constancia
     * no prueba que el navegador terminara de recibirlo, y NO marca el lote como entregado:
     * el archivo se manda o se sube a mano, fuera del sistema (ver {@see EstadoNcExportacion}).
     */
    public function descargar(NcExportacion $lote): BinaryFileResponse|RedirectResponse
    {
        $lote->loadMissing('cliente');

        try {
            $archivo = $this->exportaciones->archivo($lote);
        } catch (CopiaArchivadaInservibleException $e) {
            // Falla ESPERABLE de la copia guardada: se registra el detalle técnico y se
            // vuelve a la ficha con un texto seguro. No se regeneró ni se contó nada.
            // Cualquier otro error (generación, programación) sigue su curso normal.
            report($e);

            return redirect()
                ->route('ppq.nc-exportaciones.show', $lote)
                ->with('error', $e->mensajeUsuario());
        }

        // Contador + entrada en la bitácora de descargas PREPARADAS, juntos y solo ahora que
        // el archivo está verificado. La huella registrada es la del archivo que se sirve.
        try {
            app(RegistroDescargas::class)->registrar(
                $lote, $archivo, (string) $lote->archivo_nombre, request()->user(), (string) $lote->referencia,
            );
        } catch (Throwable $e) {
            @unlink($archivo);

            throw $e;
        }

        // Tipo EXPLÍCITO: el temporal no lleva extensión y Symfony deduciría un ZIP.
        return response()
            ->download($archivo, $lote->archivo_nombre, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Registro EXPLÍCITO de que una persona cargó el archivo del lote al portal del cliente.
     * No cambia el estado del lote, ni sus notas, ni nada fiscal; y descargar no lo hace.
     */
    public function presentar(Request $request, NcExportacion $lote): RedirectResponse
    {
        $datos = $request->validate([
            'presentada_en' => ['nullable', 'date', 'before_or_equal:today'],
            'referencia_portal' => ['nullable', 'string', 'max:60'],
            'nota' => ['nullable', 'string', 'max:255'],
        ], [], [
            'presentada_en' => 'fecha de carga',
            'referencia_portal' => 'referencia del portal',
        ]);

        try {
            $this->exportaciones->registrarPresentacion(
                $lote,
                $request->user(),
                filled($datos['presentada_en'] ?? null) ? Carbon::parse($datos['presentada_en']) : null,
                $datos['referencia_portal'] ?? null,
                $datos['nota'] ?? null,
            );
        } catch (ValidationException $e) {
            return redirect()->route('ppq.nc-exportaciones.show', $lote)->withErrors($e->errors());
        }

        return redirect()->route('ppq.nc-exportaciones.show', $lote)
            ->with('status', "Carga de {$lote->referencia} al portal registrada. El estado fiscal de sus notas no cambió.");
    }

    /** @return array<string, string> */
    private function filtros(Request $request): array
    {
        $filtros = [];
        foreach (self::FILTROS as $clave) {
            $valor = $request->query($clave, '');
            $filtros[$clave] = is_scalar($valor) ? trim((string) $valor) : '';
        }

        return $filtros;
    }

    /** @return Collection<int, Cliente> */
    private function clientesConPerfil(): Collection
    {
        return Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true)->whereNotNull('formato_export'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nombre_comercial']);
    }

    /** @param Collection<int, Cliente> $clientes */
    private function clienteElegido(Request $request, Collection $clientes): ?Cliente
    {
        $valor = $request->query('cliente_id');
        if (is_scalar($valor) && trim((string) $valor) !== '') {
            return $clientes->firstWhere('id', $request->integer('cliente_id'));
        }

        // Con un solo cliente configurado, elegirlo solo evita un clic que nunca aporta.
        return $clientes->count() === 1 ? $clientes->first() : null;
    }
}
