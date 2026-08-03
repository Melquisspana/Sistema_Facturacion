<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\AccionTrasladoRequest;
use App\Http\Requests\Planta\ReversarTrasladoRequest;
use App\Http\Requests\Planta\TrasladoRequest;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaTrasladoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Traslados de inventario entre ubicaciones.
 *
 * Controlador DELGADO: autoriza, delega y traduce el resultado a una
 * redirección. Enviar, recibir y reversar pasan ENTERAS por
 * {@see PlantaTrasladoService}, que abre la transacción, bloquea el documento y
 * habla con el motor de inventario.
 *
 * Las excepciones de dominio se traducen a un mensaje en la redirección: son
 * situaciones esperables —falta saldo, el documento cambió de estado, no existe
 * la ubicación de tránsito— y el usuario debe leerlas, no encontrarse un 500.
 *
 * LA PROPIEDAD PROTEGE EL CONTENIDO, NO EL ACTO FÍSICO. El borrador solo lo
 * edita, actualiza o cancela quien lo escribió ({@see exigirBorradorPropio()}).
 * ENVIAR y RECIBIR no exigen autoría, y es deliberado: son actos físicos
 * distintos —la salida en Casa y la llegada en Fábrica— que recaen a menudo en
 * personas distintas, y por eso tienen permisos propios. Ahí está la garantía
 * completa: quien envía el borrador de otro despacha exactamente lo que su autor
 * escribió, porque no ha podido modificarlo; y el documento guarda quién envió y
 * quién recibió.
 */
class TrasladoController extends Controller
{
    /**
     * Permiso que permite gestionar CUALQUIER borrador, no solo el propio.
     *
     * Es `reversar` y no `enviar`: producción TIENE `planta.traslados.enviar`
     * —y `recibir`—, así que cualquiera de los dos como marca administrativa
     * dejaría el candado sin efecto. `reversar` es el único permiso del
     * documento que producción no tiene, y encaja por significado: quien puede
     * deshacer un traslado ya contabilizado puede, con más razón, corregir o
     * descartar un borrador ajeno.
     */
    private const GESTIONA_CUALQUIER_BORRADOR = 'planta.traslados.reversar';

    public function __construct(private readonly PlantaTrasladoService $servicio) {}

    public function index(Request $request): View
    {
        $traslados = PlantaTraslado::query()
            ->with(['origen:id,codigo,nombre', 'destino:id,codigo,nombre', 'creadoPor:id,name'])
            ->withCount('detalles')
            ->when($request->filled('numero'), fn ($q) => $q->where('numero', $request->integer('numero')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->date('hasta')))
            ->when($request->filled('origen'), fn ($q) => $q->where('planta_ubicacion_origen_id', $request->integer('origen')))
            ->when($request->filled('destino'), fn ($q) => $q->where('planta_ubicacion_destino_id', $request->integer('destino')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('insumo'), fn ($q) => $q->whereHas(
                'detalles',
                fn ($d) => $d->where('planta_insumo_id', $request->integer('insumo'))
            ))
            ->when($request->filled('lote'), fn ($q) => $q->whereHas(
                'detalles',
                fn ($d) => $d->where('planta_lote_id', $request->integer('lote'))
            ))
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        return view('planta.traslados.index', [
            'traslados' => $traslados,
            'estados' => EstadoTrasladoPlanta::cases(),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'lotes' => PlantaLote::reales()->orderBy('codigo_interno')->get(['id', 'codigo_interno']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('planta.traslados.create', $this->opcionesFormulario($request->integer('origen') ?: null));
    }

    public function store(TrasladoRequest $request): RedirectResponse
    {
        try {
            $traslado = $this->servicio->crearBorrador($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} creado como borrador.");
    }

    public function show(PlantaTraslado $traslado): View
    {
        $traslado->load([
            'detalles.insumo:id,codigo,nombre,unidad_base',
            'detalles.lote:id,codigo_interno',
            'origen:id,codigo,nombre',
            'destino:id,codigo,nombre',
            'creadoPor:id,name',
            'enviadoPor:id,name',
            'recibidoPor:id,name',
            'reversionDe:id,numero',
            'revertidoPor:id,numero',
        ]);

        return view('planta.traslados.show', ['traslado' => $traslado]);
    }

    public function edit(Request $request, PlantaTraslado $traslado): View|RedirectResponse
    {
        $this->exigirBorradorPropio($request, $traslado);

        if (! $traslado->esEditable()) {
            return redirect()
                ->route('planta.traslados.show', $traslado)
                ->withErrors(['traslado' => 'Solo se edita un borrador.']);
        }

        $traslado->load('detalles');

        return view('planta.traslados.edit', ['traslado' => $traslado]
            + $this->opcionesFormulario($traslado->planta_ubicacion_origen_id));
    }

    public function update(TrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        $this->exigirBorradorPropio($request, $traslado);

        try {
            $this->servicio->actualizarBorrador($traslado, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} actualizado.");
    }

    public function cancelar(Request $request, PlantaTraslado $traslado): RedirectResponse
    {
        $this->exigirBorradorPropio($request, $traslado);

        try {
            $this->servicio->cancelar($traslado);
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} cancelado.");
    }

    public function enviar(AccionTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->enviar($traslado, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} enviado: la mercancía está en tránsito.");
    }

    public function recibir(AccionTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->recibir($traslado, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} recibido: el saldo ya está en el destino.");
    }

    public function reversar(ReversarTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $reversion = $this->servicio->reversar($traslado, $request->validated()['motivo'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $reversion)
            ->with('status', "Traslado #{$traslado->numero} reversado con el documento #{$reversion->numero}.");
    }

    /**
     * Un borrador lo edita quien lo escribió, o quien puede reversar.
     *
     * `planta.traslados.crear` autoriza a PREPARAR traslados, no a manipular los
     * de los demás: sin esto, un operario podría cambiar las cantidades del
     * borrador de otro —o cancelárselo— justo antes de que salga el camión.
     *
     * Es un candado de ACCESO y responde 403, no 404: el documento existe, se
     * lista y se abre con `traslados.ver`; lo que no se puede es tocarlo.
     *
     * NO se aplica a enviar ni a recibir, que son actos físicos con permiso
     * propio y pueden recaer en otra persona. Tampoco juzga el estado: de eso
     * siguen ocupándose `esEditable()` y el servicio.
     */
    private function exigirBorradorPropio(Request $request, PlantaTraslado $traslado): void
    {
        $usuario = $request->user();

        if ($usuario?->can(self::GESTIONA_CUALQUIER_BORRADOR)) {
            return;
        }

        abort_unless($usuario !== null && $traslado->creado_por === $usuario->id, 403);
    }

    /**
     * Opciones del formulario. Solo se ofrecen ubicaciones que puedan operar de
     * verdad, y los lotes son SOLO los que tienen saldo disponible en el origen
     * elegido: ofrecer combinaciones sin saldo llevaría a descubrir el error al
     * enviar en vez de al escribir.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(?int $origenId): array
    {
        return [
            'ubicaciones' => PlantaUbicacion::where('activo', true)
                ->where('permite_operacion_manual', true)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'origenSeleccionado' => $origenId,
            'disponibles' => $origenId ? $this->servicio->lotesDisponiblesEn($origenId) : collect(),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ];
    }
}
