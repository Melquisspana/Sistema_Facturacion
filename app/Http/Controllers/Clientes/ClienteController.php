<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\CondicionPago;
use App\Enums\TamanioContribuyente;
use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPersona;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clientes\ClienteRequest;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\ExportacionCliente;
use App\Models\ExportacionProducto;
use App\Models\Pais;
use App\Support\Ubicacion\OpcionesUbicacion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ClienteController extends Controller
{
    use AuthorizesRequests;

    /**
     * Filtros del directorio: DOS grupos independientes que se combinan entre sí, y
     * ambos con el buscador y con el tipo de cliente.
     *
     * Separados y no excluyentes porque la pregunta útil es cruzada: «activos que
     * todavía no tienen sala» es el pendiente real de trabajo, y con un solo grupo de
     * pastillas había que elegir entre ver los activos o ver los que no tienen sala.
     */
    private const ESTADOS = ['activos', 'todos', 'inactivos'];

    private const SALAS = ['todas', 'sin', 'con'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cliente::class);

        $busqueda = trim((string) $request->input('q', ''));
        $tipoCliente = $request->input('tipo_cliente');
        $estado = $this->estadoVigente($request);
        $salas = $this->salasVigente($request);

        $clientes = Cliente::query()
            // Conteos por subconsulta: el listado muestra «N salas · M activas» en cada
            // fila y con withCount eso no cuesta una consulta por cliente. Las salas
            // borradas (soft delete) no cuentan, que es lo que espera el operador.
            ->withCount([
                'sucursales as salas_total',
                'sucursales as salas_activas' => fn ($q) => $q->where('activo', true),
            ])
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $like = "%{$busqueda}%";

                // El cliente entra por sus propios campos O por los de alguna de sus
                // salas. orWhereHas mantiene todo en una sola consulta: no se recorre
                // la lista de clientes preguntando por sus salas una por una.
                $query->where(function ($w) use ($like) {
                    $w->where('nombre', 'like', $like)
                        ->orWhere('codigo', 'like', $like)
                        ->orWhere('num_documento', 'like', $like)
                        ->orWhere('nrc', 'like', $like)
                        ->orWhere('correo', 'like', $like)
                        ->orWhereHas('sucursales', fn ($s) => $s->where(
                            fn ($t) => $t->where('nombre', 'like', $like)->orWhere('codigo', 'like', $like)
                        ));
                });

                // Contexto de la coincidencia: se precargan SOLO las salas que casan con
                // lo buscado, para poder decir en la fila por qué apareció ese cliente.
                // Es una única consulta extra para toda la página, y cuando la búsqueda
                // casó por datos del cliente la relación viene vacía y no se muestra nada.
                $query->with(['sucursales' => fn ($s) => $s
                    ->where(fn ($t) => $t->where('nombre', 'like', $like)->orWhere('codigo', 'like', $like))
                    ->orderBy('nombre'),
                ]);
            })
            // Los dos grupos son independientes: cada uno añade su condición, y la
            // combinación «activos + sin salas» sale sola de aplicar las dos.
            ->when(TipoCliente::tryFrom((string) $tipoCliente), fn ($q) => $q->where('tipo_cliente', $tipoCliente))
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when($salas === 'sin', fn ($q) => $q->doesntHave('sucursales'))
            ->when($salas === 'con', fn ($q) => $q->has('sucursales'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('clientes.index', [
            'clientes' => $clientes,
            'filtros' => [
                'q' => $busqueda,
                'tipo_cliente' => $tipoCliente,
                'estado' => $estado,
                'salas' => $salas,
            ],
            'tiposCliente' => TipoCliente::opciones(),
        ]);
    }

    /**
     * Estado vigente. Por defecto «activos»: el directorio es una herramienta de
     * trabajo y lo normal es operar sobre clientes vivos; los inactivos se piden.
     *
     * Se sigue entendiendo el `?activo=1|0` del filtro anterior para que un enlace o un
     * favorito guardado no cambie de significado al abrirlo.
     */
    private function estadoVigente(Request $request): string
    {
        $estado = (string) $request->input('estado', '');
        if (in_array($estado, self::ESTADOS, true)) {
            return $estado;
        }

        if ($request->has('activo')) {
            return match ((string) $request->input('activo')) {
                '1' => 'activos',
                '0' => 'inactivos',
                default => 'todos',   // el «Todos» del select viejo mandaba activo=''
            };
        }

        return 'activos';
    }

    /**
     * Filtro de salas. Por defecto «todas»: al entrar, la cantidad de salas no debe
     * esconder a nadie; es una pregunta que se hace aparte.
     */
    private function salasVigente(Request $request): string
    {
        $salas = (string) $request->input('salas', '');

        return in_array($salas, self::SALAS, true) ? $salas : 'todas';
    }

    public function create(): View
    {
        $this->authorize('create', Cliente::class);

        // El contacto (teléfono/correo) se captura principalmente en la sala; el
        // cliente no lo precarga. El teléfono sugerido 77777777 vive en el alta de
        // sala (ver ClienteSucursalController).
        return view('clientes.form', $this->datosFormulario(new Cliente(['activo' => true])));
    }

    public function store(ClienteRequest $request): RedirectResponse
    {
        $this->authorize('create', Cliente::class);

        $cliente = Cliente::create($request->validated());

        // "Guardar y agregar primera sala": encadena el alta con el formulario de
        // sucursal, que es donde vive la ubicación operativa del cliente.
        if ($request->input('accion') === 'guardar_y_sala') {
            return redirect()
                ->route('clientes.sucursales.create', $cliente)
                ->with('status', 'Cliente creado. Agregue la primera sala.');
        }

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Cliente creado correctamente.');
    }

    public function show(Cliente $cliente): View
    {
        $this->authorize('view', $cliente);

        $cliente->load(['actividadEconomica', 'pais', 'departamento', 'municipio']);
        $cliente->load(['sucursales' => fn ($q) => $q->orderBy('nombre')]);
        // Perfil documental: casi ningún cliente tiene, y la ficha lo dice explícitamente
        // en vez de callarlo, para que se vea que existe la opción.
        $cliente->load(['perfilDocumento.tiposNc']);

        $actividades = $cliente->activities()->with('causer')->latest()->limit(30)->get();

        // Perfil de exportación: se carga SOLO para clientes de ese tipo, así que la
        // ficha de un cliente nacional hace exactamente las mismas consultas que antes.
        [$productosDisponibles, $otrosPerfilesExportacion] = $this->datosExportacion($cliente);

        return view('clientes.show', compact(
            'cliente', 'actividades', 'productosDisponibles', 'otrosPerfilesExportacion'
        ));
    }

    public function edit(Cliente $cliente): View
    {
        $this->authorize('update', $cliente);

        return view('clientes.form', $this->datosFormulario($cliente));
    }

    public function update(ClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $cliente->update($request->validated());

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Cliente actualizado correctamente.');
    }

    /** Activa o inactiva el cliente (queda registrado en auditoría). */
    public function toggleActivo(Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $cliente->update(['activo' => ! $cliente->activo]);

        return back()->with('status', $cliente->activo ? 'Cliente activado.' : 'Cliente inactivado.');
    }

    public function destroy(Cliente $cliente): RedirectResponse
    {
        $this->authorize('delete', $cliente);

        $cliente->delete(); // soft delete

        return redirect()
            ->route('clientes.index')
            ->with('status', 'Cliente eliminado.');
    }

    private function datosFormulario(Cliente $cliente): array
    {
        return [
            'cliente' => $cliente,
            // Un cliente ya guardado que todavía no tiene salas muestra su bloque de
            // ubicación abierto (aunque esté vacío). NO implica marcar "sin salas":
            // esa sigue siendo una decisión explícita del usuario.
            'clienteSinSalas' => $cliente->exists && $cliente->sucursales()->doesntExist(),
            // Compatibilidad: un cliente antiguo que ya tiene contacto u orden de
            // compra propios los sigue mostrando, aunque el alta nueva los oculte.
            'clienteTieneContacto' => $cliente->exists && (filled($cliente->telefono) || filled($cliente->correo)),
            'clienteTieneOc' => $cliente->exists && (bool) $cliente->requiere_orden_compra,
            'tiposCliente' => TipoCliente::opciones(),
            'tiposPersona' => TipoPersona::opciones(),
            'tiposDocumento' => TipoDocumentoCliente::opciones(),
            'tamaniosContribuyente' => TamanioContribuyente::opciones(),
            // CAT-016 para el select de condición de pago por defecto del cliente.
            'condicionesPago' => collect(CondicionPago::cases())
                ->mapWithKeys(fn (CondicionPago $c) => [$c->value => $c->label()])
                ->all(),
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            ...OpcionesUbicacion::todas(),
            // Para preseleccionar El Salvador en clientes nacionales (CAT-020: SV).
            'paisElSalvadorId' => Pais::where('codigo', 'SV')->value('id'),
        ];
    }

    /**
     * Datos del bloque «Exportación» de la ficha: catálogo todavía sin asignar y
     * otros perfiles desde los que se pueden copiar precios.
     *
     * Devuelve colecciones VACÍAS para cualquier cliente que no sea de exportación,
     * y sin consultar nada. Es deliberado: la ficha de un cliente nacional tiene que
     * ejecutar exactamente las mismas consultas que antes de este bloque.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function datosExportacion(Cliente $cliente): array
    {
        if (! ($cliente->tipo_cliente?->esExportacion() ?? false)) {
            return [collect(), collect()];
        }

        $cliente->load([
            'exportacionClientes' => fn ($q) => $q->orderBy('id'),
            'exportacionClientes.productos.producto:id,nombre_es,nombre_en,unidades_por_caja,precio_caja,activo',
        ]);

        $perfil = $cliente->exportacionClientes->first();

        if ($perfil === null) {
            return [collect(), collect()];
        }

        $yaAsignados = $perfil->productos->pluck('exportacion_producto_id');

        $disponibles = ExportacionProducto::query()
            ->where('activo', true)
            ->whereNotIn('id', $yaAsignados)
            ->orderBy('nombre_es')
            ->get(['id', 'nombre_es', 'precio_caja']);

        // Orígenes posibles para «copiar precios»: cualquier otro perfil que tenga al
        // menos un precio activo. El conteo se filtra en PHP y no con HAVING porque
        // HAVING sobre un alias de withCount() sin GROUP BY funciona en MySQL y
        // revienta en SQLite, que es el motor de las pruebas.
        $otros = ExportacionCliente::query()
            ->where('id', '!=', $perfil->id)
            ->with('cliente:id,nombre')
            ->withCount(['productos as productos_count' => fn ($q) => $q->where('activo', true)])
            ->get()
            ->filter(fn ($otro) => $otro->productos_count > 0)
            ->sortBy(fn ($otro) => $otro->nombreLegal())
            ->values();

        return [$disponibles, $otros];
    }
}
