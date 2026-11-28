<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\PanelClientes;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Clientes» de Rutas (Melqui, 28/11/2026), con el permiso de «Pedidos» (`rutas.pedidos.ver`):
 * todas las salas para todos los que entran, sin filtrar por la zona del vendedor, con lo
 * último que se les sacó y su último CCF; la historia de una sala; y el PDF de un CCF suyo
 * para verlo, imprimirlo o mandarlo por WhatsApp. La lista y la historia van sin montos; el
 * PDF, con montos (aprobado). La otra pestaña, «Todos los pedidos», es {@see PedidoSalaController::index()}.
 */
class ClientesController extends Controller
{
    /** Tope de «Ver más» en la historia: 50 vueltas de 10. */
    private const MAX_PAGINAS = 50;

    public function __construct(private readonly PanelClientes $panel) {}

    public function index(Request $request): View
    {
        // Los filtros son solo texto: uno en lista (un enlace armado a mano) o una zona que no
        // existe se ignoran, no dan 500.
        $buscar = $request->query('q');
        $buscar = Str::limit(trim(is_string($buscar) ? $buscar : ''), 100, '');
        $zonas = $this->panel->zonas();
        $zona = $request->query('zona');
        $zonaId = is_string($zona) && ctype_digit($zona) && $zonas->contains('id', (int) $zona) ? (int) $zona : null;

        $lista = $this->panel->salas($buscar, $zonaId);
        // Una página de más se avisa en la pantalla; una enorme armada a mano no da 500.
        $pagina = min(LengthAwarePaginator::resolveCurrentPage(), intdiv($lista->count(), PanelClientes::POR_PAGINA) + 2);
        $salas = new LengthAwarePaginator($lista->forPage($pagina, PanelClientes::POR_PAGINA)->values(), $lista->count(),
            PanelClientes::POR_PAGINA, $pagina, ['path' => $request->url(), 'query' => $request->query()]);

        return view('rutas.clientes.index', [
            'salas' => $salas,
            'resumen' => $this->panel->resumen(collect($salas->items())->map(fn (array $f) => $f['sala']->id)->all()),
            'zonas' => $zonas,
            'filtro' => ['q' => $buscar, 'zona' => $zonaId],
            'filtrado' => $buscar !== '' || $zonaId !== null,
        ]);
    }

    public function show(Request $request, ClienteSucursal $sala, PropuestaRutas $propuesta): View
    {
        $paginas = function (string $clave) use ($request): int {
            $valor = $request->query($clave);

            return is_string($valor) && ctype_digit($valor) ? min(self::MAX_PAGINAS, max(1, (int) $valor)) : 1;
        };

        return view('rutas.clientes.show', [
            'sala' => $sala,
            'zona' => $propuesta->zonaDe($sala->ruta_id, $sala->distrito_id, $sala->departamento_id),
            'paginasPedidos' => $paginas('pedidos'),
            'paginasCcf' => $paginas('ccf'),
        ] + $this->panel->historia($sala, $paginas('pedidos'), $paginas('ccf')));
    }

    /**
     * El PDF del CCF, en el navegador: se ve, se descarga y se imprime desde ahí, y «Compartir»
     * lo baja de acá para mandarlo. Cualquiera con el permiso, de cualquier sala, pero solo un
     * CCF que cuenta en Rutas ({@see EntregasCcf::baseCcf()}) y de ESA sala: otro documento,
     * invalidado, archivado o de otra sala da 404. Es la representación de Facturación, la
     * misma que baja el vendedor en «Por entregar» ({@see DtePdfService}).
     */
    public function ccf(ClienteSucursal $sala, Dte $dte, EntregasCcf $entregas, DtePdfService $pdf): Response
    {
        abort_unless($entregas->baseCcf()->whereKey($dte->id)->where('cliente_sucursal_id', $sala->id)->exists(), 404);

        return $pdf->pdf($dte)->stream($pdf->nombre($dte));
    }
}
