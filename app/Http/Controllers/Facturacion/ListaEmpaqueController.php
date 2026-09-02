<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\TipoDte;
use App\Exceptions\Exportaciones\FexYaExisteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionRequest;
use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use App\Services\Exportaciones\ListaEmpaqueExcelService;
use App\Services\Exportaciones\VincularFexALista;
use App\Support\Exportaciones\DatosExportador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Listas de empaque, dentro de Ventas y facturación.
 *
 * FLUJO CORTO, cuatro pasos y ningún estado intermedio:
 *
 *   1. Preparar la lista (borrador): cliente, fecha y productos con sus cajas.
 *   2. Facturar: una o varias FEX vinculadas. Se crean por el flujo real de
 *      Facturación —`facturacion.create-exportacion`—, que NO se duplica acá.
 *   3. Generar documentos: Excel de la lista y el PDF de cada factura, que ya lo
 *      produce Facturación.
 *   4. Finalizar.
 *
 * No hay cola logística, aduana, tránsito ni aprobaciones intermedias.
 *
 * LO QUE SE TOMA SOLO, sin teclearlo: cliente y dirección (del directorio), FDA y
 * datos del exportador (de Configuración), presentación, pesos y precios (del
 * catálogo, como snapshot) y los NÚMEROS DE FACTURA (de las FEX vinculadas).
 *
 * FINALIZAR Y CORREGIR. Una lista se finaliza a mano y solo si ya tiene factura;
 * a partir de ahí no se edita, ni se borra, ni cambian sus vínculos. Corregirla
 * exige REABRIRLA con un motivo, y esa reapertura queda registrada en la
 * auditoría con quién y por qué. Editar en silencio un documento cerrado es
 * justamente lo que esto impide.
 */
class ListaEmpaqueController extends Controller
{
    public function __construct(
        private readonly VincularFexALista $vinculos,
        private readonly DatosExportador $exportador,
    ) {}

    public function index(Request $request): View
    {
        $estado = (string) $request->input('estado', 'abiertas');

        if (! in_array($estado, ['abiertas', 'finalizadas', 'archivadas', 'todas'], true)) {
            $estado = 'abiertas';
        }

        $listas = Exportacion::query()
            ->withCount('items')
            ->with(['cliente.cliente:id,nombre', 'dtes:id,numero_control,estado,tipo_dte'])
            // ARCHIVADAS. El flujo nuevo no archiva nada —no hay acción para hacerlo—,
            // pero la columna existe y una instalación anterior puede tener listas de
            // prueba marcadas así. Se siguen ocultando del listado normal, porque
            // resucitarlas de golpe sería justamente reinterpretar un dato histórico;
            // se ven con su propio filtro o con «Todas», y por URL directa siempre.
            ->when(in_array($estado, ['abiertas', 'finalizadas'], true), fn ($q) => $q->where('archivada', false))
            ->when($estado === 'archivadas', fn ($q) => $q->where('archivada', true))
            // «Abiertas» = todo lo que no está finalizado, incluidos los estados
            // heredados. Se pregunta por exclusión y no por igualdad a 'borrador', o
            // una lista antigua en 'aprobada' desaparecería del listado por defecto.
            ->when($estado === 'abiertas', fn ($q) => $q->where('estado', '!=', Exportacion::ESTADO_FINALIZADA))
            ->when($estado === 'finalizadas', fn ($q) => $q->where('estado', Exportacion::ESTADO_FINALIZADA))
            ->when($request->boolean('revision'), fn ($q) => $q->where('requiere_revision', true))
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('cliente_nombre', 'like', $buscar)
                    ->orWhere('factura', 'like', $buscar));
            })
            ->latest('fecha')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('facturacion.listas.index', [
            'listas' => $listas,
            'estado' => $estado,
            'soloRevision' => $request->boolean('revision'),
            'pendientesRevision' => Exportacion::where('requiere_revision', true)->count(),
            'archivadas' => Exportacion::where('archivada', true)->count(),
        ]);
    }

    public function create(): View
    {
        $this->autorizarGestion();

        return view('facturacion.listas.create', [
            'productos' => $this->productosParaFormulario(),
            'clientes' => $this->clientesParaFormulario(),
            'defaults' => $this->exportador->paraEncabezado(),
        ]);
    }

    public function store(ExportacionRequest $request): RedirectResponse
    {
        $this->autorizarGestion();

        $datos = $request->validated();
        $avisos = [];

        $lista = DB::transaction(function () use ($datos, &$avisos) {
            $lista = Exportacion::create(
                collect($datos)->except('items')->all() + ['estado' => Exportacion::ESTADO_BORRADOR]
            );
            $avisos = $this->crearItems($lista, $datos['items']);

            return $lista;
        });

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Lista de empaque creada.')
            ->with('aviso_precios', $this->mensajeAvisoPrecios($avisos));
    }

    public function show(Exportacion $lista): View
    {
        $lista->load([
            'items.producto:id,nombre_es,activo',
            'cliente.cliente',
            'dtes',
        ]);

        return view('facturacion.listas.show', [
            'lista' => $lista,
            'facturas' => $lista->facturas(),
            // Candidatas a vincular: FEX del mismo cliente que todavía no pertenecen a
            // ninguna lista. Se ofrecen solo si la lista se puede editar.
            'fexVinculables' => $lista->puedeEditarse() ? $this->fexVinculables($lista) : collect(),
        ]);
    }

    public function edit(Exportacion $lista): View
    {
        $this->autorizarGestion();
        $this->exigirEditable($lista);

        $lista->load('items');

        return view('facturacion.listas.edit', [
            'lista' => $lista,
            'productos' => $this->productosParaFormulario(),
            'clientes' => $this->clientesParaFormulario(),
        ]);
    }

    public function update(ExportacionRequest $request, Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();
        $this->exigirEditable($lista);

        $datos = $request->validated();
        $avisos = [];

        DB::transaction(function () use ($lista, $datos, &$avisos) {
            $lista->update(collect($datos)->except('items')->all());

            // Items existentes (traen id): solo cambia la cantidad y se CONSERVA el
            // snapshot. Items nuevos: snapshot del catálogo de hoy. Los no enviados se quitan.
            $enviados = collect($datos['items']);
            $idsConservar = $enviados->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $lista->items()->whereNotIn('id', $idsConservar)->delete();

            foreach ($enviados as $item) {
                if (! empty($item['id'])) {
                    $lista->items()
                        ->whereKey((int) $item['id'])
                        ->update(['cantidad_cajas' => (int) $item['cantidad_cajas']]);
                }
            }

            $nuevos = $enviados->filter(fn ($item) => empty($item['id']))->values()->all();
            $avisos = $this->crearItems($lista, $nuevos);
        });

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Lista de empaque actualizada.')
            ->with('aviso_precios', $this->mensajeAvisoPrecios($avisos));
    }

    /**
     * Borrado. Solo en borrador y sin ninguna factura vinculada: una lista que ya
     * produjo una FEX es el respaldo de un documento fiscal y no se borra nunca.
     */
    public function destroy(Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();

        if ($lista->requiereRevision()) {
            return $this->error($lista, 'No se puede eliminar: la lista viene del flujo anterior y está pendiente de clasificar. Un administrador tiene que resolverla primero.');
        }

        if ($lista->estaFinalizada()) {
            return $this->error($lista, 'No se puede eliminar una lista finalizada. Reabrila primero si de verdad hay que corregirla.');
        }

        if ($lista->facturas()->isNotEmpty()) {
            return $this->error($lista, 'No se puede eliminar: esta lista ya tiene factura(s) de exportación vinculada(s). Quitá primero el vínculo si corresponde, o conservá ambos documentos.');
        }

        $lista->delete();

        return redirect()
            ->route('facturacion.listas.index')
            ->with('status', 'Lista de empaque eliminada.');
    }

    /** Copia con los mismos productos (snapshot tal cual) y fecha de hoy, en borrador. */
    public function duplicar(Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();

        $copia = DB::transaction(function () use ($lista) {
            $copia = $lista->replicate(['dte_id', 'finalizada_en', 'finalizada_por_user_id', 'requiere_revision', 'revision_motivo']);
            $copia->fecha = now()->toDateString();
            $copia->estado = Exportacion::ESTADO_BORRADOR;
            $copia->dte_id = null;
            $copia->finalizada_en = null;
            $copia->finalizada_por_user_id = null;
            $copia->requiere_revision = false;
            $copia->revision_motivo = null;
            // El número de factura ya no se copia: se deriva de las FEX que se vinculen
            // a la copia, y copiar el de otra lista es exactamente el error que se quiso
            // eliminar al dejar de teclearlo.
            $copia->factura = null;
            $copia->save();

            foreach ($lista->items as $item) {
                $nuevo = $item->replicate();
                $nuevo->exportacion_id = $copia->id;
                $nuevo->save();
            }

            return $copia;
        });

        return redirect()
            ->route('facturacion.listas.show', $copia)
            ->with('status', "Lista duplicada desde la #{$lista->id}. Revisá la fecha y volvé a facturarla.");
    }

    // ------------------------------------------------------------------ facturas

    /**
     * Lleva al formulario REAL de factura de exportación con la lista en contexto.
     *
     * No crea el documento acá: `facturacion.create-exportacion` sigue siendo el
     * único formulario de alta de una FEX, con sus catálogos aduaneros, su emisor y
     * sus validaciones. Al guardarlo, `DteController::storeExportacion` vincula la
     * factura recién creada con esta lista.
     */
    public function iniciarFactura(Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();
        $this->exigirEditable($lista);

        $clienteId = $lista->cliente?->cliente_id;

        if ($clienteId === null) {
            return $this->error($lista, 'La lista no tiene un cliente del directorio vinculado. Habilitá al cliente para exportación desde su ficha antes de facturar.');
        }

        return redirect()->route('facturacion.create-exportacion', [
            'lista' => $lista->id,
            'cliente_id' => $clienteId,
        ]);
    }

    /**
     * Atajo que arma el borrador FEX copiando las líneas de la lista, para el caso
     * habitual en que la factura es exactamente la lista. Sigue siendo el servicio
     * de siempre; el formulario completo está a un clic en «Facturar en el editor».
     */
    public function crearFexRapida(Exportacion $lista, Request $request, CrearFexDesdeExportacionService $service): RedirectResponse
    {
        $this->autorizarGestion();
        $this->exigirEditable($lista);

        try {
            $dte = $service->crear($lista, $request->user());
        } catch (FexYaExisteException $e) {
            return redirect()
                ->route('facturacion.edit', $e->dteId)
                ->with('status', 'Esta lista ya tiene una factura de exportación creada.');
        } catch (ValidationException $e) {
            return $this->error($lista, 'No se pudo crear la factura de exportación: '.implode(' ', collect($e->errors())->flatten()->all()));
        }

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Factura de exportación creada desde la lista de empaque.');
    }

    public function vincularFactura(Request $request, Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();

        $datos = $request->validate([
            'dte_id' => ['required', 'integer', 'exists:dtes,id'],
        ], [], ['dte_id' => 'factura de exportación']);

        try {
            $this->vinculos->vincular($lista, Dte::findOrFail($datos['dte_id']));
        } catch (ValidationException $e) {
            return $this->error($lista, implode(' ', collect($e->errors())->flatten()->all()));
        }

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Factura vinculada. El número de la lista se actualizó solo.');
    }

    public function desvincularFactura(Exportacion $lista, Dte $dte): RedirectResponse
    {
        $this->autorizarGestion();

        try {
            $this->vinculos->desvincular($lista, $dte);
        } catch (ValidationException $e) {
            return $this->error($lista, implode(' ', collect($e->errors())->flatten()->all()));
        }

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Factura desvinculada de la lista. El documento fiscal no se tocó.');
    }

    // -------------------------------------------------------- finalizar / reabrir

    public function finalizar(Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();

        if ($lista->estaFinalizada()) {
            return $this->error($lista, 'Esta lista ya está finalizada.');
        }

        if ($lista->requiereRevision()) {
            return $this->error($lista, 'No se puede finalizar: la lista viene del flujo anterior y está pendiente de clasificar. Un administrador tiene que resolverla primero.');
        }

        if (! $lista->puedeFinalizarse()) {
            return $this->error($lista, $lista->tieneFex()
                ? 'Para finalizar, la lista necesita al menos una factura VIGENTE. Las que tiene están rechazadas o invalidadas y no respaldan el embarque.'
                : 'Para finalizar la lista tiene que tener al menos una factura de exportación vinculada. Facturala primero.');
        }

        $lista->update([
            'estado' => Exportacion::ESTADO_FINALIZADA,
            'finalizada_en' => now(),
            'finalizada_por_user_id' => request()->user()?->id,
            // Finalizar a mano ES la revisión que pedía un estado heredado: quien cierra
            // la lista está confirmando qué era.
            'requiere_revision' => false,
            'revision_motivo' => null,
        ]);

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Lista finalizada. Ya no se edita: para corregirla hay que reabrirla indicando el motivo.');
    }

    /**
     * Corrección controlada de una lista finalizada. Exige un motivo escrito, que se
     * guarda en la lista y viaja a la bitácora de actividad junto con quién lo hizo.
     */
    public function reabrir(Request $request, Exportacion $lista): RedirectResponse
    {
        $this->autorizarGestion();

        if (! $lista->estaFinalizada()) {
            return $this->error($lista, 'Esta lista no está finalizada.');
        }

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'motivo.required' => 'Escribí por qué hay que reabrir la lista: queda registrado en la auditoría.',
            'motivo.min' => 'El motivo es demasiado corto para que sirva de explicación (mínimo 10 caracteres).',
        ], ['motivo' => 'motivo']);

        $lista->update([
            'estado' => Exportacion::ESTADO_BORRADOR,
            'finalizada_en' => null,
            'finalizada_por_user_id' => null,
            'revision_motivo' => $datos['motivo'],
        ]);

        activity('lista_empaque')
            ->performedOn($lista)
            ->causedBy($request->user())
            ->withProperties(['motivo' => $datos['motivo']])
            ->log('reabrió la lista de empaque finalizada');

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Lista reabierta. Quedó registrado quién la reabrió y por qué.');
    }

    // -------------------------------------------- clasificar una lista heredada

    /**
     * Resuelve una lista heredada del flujo anterior: la clasifica como borrador,
     * finalizada o archivada.
     *
     * Es una acción ADMINISTRATIVA y AUDITADA, y por eso pide tres cosas:
     *
     *   · rol de administrador — no basta con `exportaciones.gestionar`: quien
     *     opera todos los días no debería poder desbloquear un documento histórico
     *     que quizá alguien cerró;
     *   · una clasificación explícita, de una lista cerrada de tres;
     *   · un motivo escrito, que se guarda y viaja a la bitácora.
     *
     * El estado original NUNCA se pierde: quedó capturado en
     * `revision_estado_original` y se conserva junto con quién resolvió y cuándo.
     */
    public function resolverRevision(Request $request, Exportacion $lista): RedirectResponse
    {
        $this->autorizarResolucionDeRevision();

        if (! $lista->requiereRevision()) {
            return $this->error($lista, 'Esta lista no está pendiente de clasificar.');
        }

        $datos = $request->validate([
            'clasificacion' => ['required', 'string', 'in:'.implode(',', Exportacion::RESOLUCIONES)],
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'clasificacion.required' => 'Elegí cómo queda clasificada la lista.',
            'clasificacion.in' => 'Esa clasificación no existe: solo borrador, finalizada o archivada.',
            'motivo.required' => 'Escribí en qué te basaste para clasificarla: queda registrado en la auditoría.',
            'motivo.min' => 'El motivo es demasiado corto para que sirva de explicación (mínimo 10 caracteres).',
        ], ['clasificacion' => 'clasificación', 'motivo' => 'motivo']);

        $original = $lista->estadoOriginalHeredado();
        $clasificacion = $datos['clasificacion'];

        // Finalizarla exige lo mismo que finalizar cualquier otra: una factura que
        // respalde el embarque. Sin eso, «finalizada» sería una etiqueta vacía.
        if ($clasificacion === Exportacion::ESTADO_FINALIZADA && $lista->facturasVigentes()->isEmpty()) {
            return $this->error($lista, 'No se puede clasificar como finalizada: la lista no tiene ninguna factura de exportación vigente que la respalde. Clasificala como borrador y facturala, o archivala.');
        }

        $cambios = [
            'requiere_revision' => false,
            'revision_motivo' => $datos['motivo'],
            'revision_estado_original' => $original,
            'revision_resuelta_en' => now(),
            'revision_resuelta_por_user_id' => $request->user()?->id,
            'revision_resolucion' => $clasificacion,
        ];

        $cambios += match ($clasificacion) {
            Exportacion::ESTADO_FINALIZADA => [
                'estado' => Exportacion::ESTADO_FINALIZADA,
                'finalizada_en' => now(),
                'finalizada_por_user_id' => $request->user()?->id,
            ],
            // Archivar NO cambia el estado: `archivada` es un eje aparte y sobrescribir
            // el estado histórico perdería el dato que la marca existía para proteger.
            'archivada' => ['archivada' => true, 'archivada_en' => now()],
            default => ['estado' => Exportacion::ESTADO_BORRADOR],
        };

        $lista->update($cambios);

        activity('lista_empaque')
            ->performedOn($lista)
            ->causedBy($request->user())
            ->withProperties([
                'estado_original' => $original,
                'clasificacion' => $clasificacion,
                'motivo' => $datos['motivo'],
            ])
            ->log('clasificó una lista de empaque heredada');

        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('status', 'Lista clasificada como «'.$clasificacion.'». Quedó registrado el estado original («'.$original.'»), quién la resolvió y por qué.');
    }

    // ------------------------------------------------------------------ archivos

    /** Excel de la lista, generado por completo desde el código (sin plantilla). */
    public function excel(Exportacion $lista, ListaEmpaqueExcelService $service): BinaryFileResponse|RedirectResponse
    {
        if ($lista->items()->count() === 0) {
            return $this->error($lista, 'La lista no tiene productos para generar el Excel.');
        }

        try {
            $ruta = $service->generar($lista);
        } catch (\RuntimeException $e) {
            // El motivo real llega a la pantalla. Nunca se devuelve una descarga vacía:
            // un .xlsx de 0 bytes se abre como «archivo dañado» y manda a buscar el
            // problema al lado equivocado.
            return $this->error($lista, $e->getMessage());
        }

        return response()->download($ruta, $service->nombreArchivo($lista))->deleteFileAfterSend();
    }

    /**
     * Versión imprimible de la lista.
     *
     * Es una vista HTML con hoja de impresión, igual que la impresión de documentos
     * de Facturación. NO se agrega una segunda tubería de PDF: la que existe está
     * construida alrededor del DTE (su plantilla, su QR, sus sellos) y reaprovecharla
     * para un documento comercial distinto obligaría a duplicarla a medias.
     */
    public function imprimir(Exportacion $lista): View
    {
        $lista->load(['items', 'cliente.cliente']);

        return view('facturacion.listas.imprimir', [
            'lista' => $lista,
            'facturas' => $lista->facturas(),
        ]);
    }

    // -------------------------------------------------------------------- interno

    private function autorizarGestion(): void
    {
        abort_unless(request()->user()?->can('exportaciones.gestionar'), 403);
    }

    /**
     * Clasificar una lista heredada es más que gestionarla: decide qué fue un
     * documento que quizá alguien cerró hace meses. Se reserva al administrador, y
     * no al permiso de operación diaria, a propósito.
     */
    private function autorizarResolucionDeRevision(): void
    {
        abort_unless(request()->user()?->hasRole('administrador'), 403);
    }

    /**
     * Puerta única de escritura. Dice exactamente POR QUÉ está bloqueada —finalizada
     * o pendiente de clasificar— en vez de un «no se puede» genérico que obliga a
     * adivinar cuál de las dos cosas pasa.
     */
    private function exigirEditable(Exportacion $lista): void
    {
        if ($lista->puedeEditarse()) {
            return;
        }

        throw ValidationException::withMessages([
            'estado' => $lista->motivoBloqueo() ?? 'La lista no se puede modificar.',
        ]);
    }

    private function error(Exportacion $lista, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('facturacion.listas.show', $lista)
            ->with('error', $mensaje);
    }

    /**
     * Facturas de exportación que se pueden vincular a mano: del mismo cliente y sin
     * lista propia todavía. Es la vía para el caso en que la FEX se creó antes que la
     * lista, o desde el editor sin pasar por acá.
     */
    private function fexVinculables(Exportacion $lista): Collection
    {
        $clienteId = $lista->cliente?->cliente_id;

        if ($clienteId === null) {
            return collect();
        }

        return Dte::query()
            ->where('tipo_dte', TipoDte::FacturaExportacion->value)
            ->where('cliente_id', $clienteId)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('exportacion_dte')
                ->whereColumn('exportacion_dte.dte_id', 'dtes.id'))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'numero_control', 'estado', 'fecha_emision', 'total_pagar']);
    }

    /**
     * Crea items copiando el snapshot del catálogo. El precio sale PRIMERO de la
     * lista del cliente; si no hay, cae al precio base y se devuelve el aviso. Sin
     * ningún precio, error de validación: nunca se factura a cero por descuido.
     *
     * @return list<string> nombres de productos que usaron precio base
     */
    private function crearItems(Exportacion $lista, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $cliente = $lista->exportacion_cliente_id !== null
            ? ExportacionCliente::with('productos')->find($lista->exportacion_cliente_id)
            : null;

        // Un mismo producto repetido en el formulario se consolida sumando cajas.
        $porProducto = [];
        foreach ($items as $item) {
            $id = (int) $item['exportacion_producto_id'];
            $porProducto[$id] = ($porProducto[$id] ?? 0) + (int) $item['cantidad_cajas'];
        }

        $conPrecioBase = [];

        foreach ($porProducto as $productoId => $cantidad) {
            $producto = ExportacionProducto::findOrFail($productoId);
            $precio = $cliente?->precioPara($producto->id);

            if ($precio === null) {
                if ($producto->precio_caja === null) {
                    throw ValidationException::withMessages([
                        'items' => "«{$producto->nombre_es}» no tiene precio para este cliente ni precio base: asignale un precio antes de usarlo.",
                    ]);
                }
                $precio = (float) $producto->precio_caja;
                $conPrecioBase[] = $producto->nombre_es;
            }

            ExportacionItem::create([
                'exportacion_id' => $lista->id,
                'exportacion_producto_id' => $producto->id,
                'cantidad_cajas' => $cantidad,
                'precio_caja' => $precio,
            ] + $producto->datosSnapshot());
        }

        return $conPrecioBase;
    }

    private function mensajeAvisoPrecios(array $conPrecioBase): ?string
    {
        if ($conPrecioBase === []) {
            return null;
        }

        return 'Ojo: se usó el PRECIO BASE del catálogo (el cliente no tiene precio propio) para: '
            .implode(', ', $conPrecioBase).'.';
    }

    private function productosParaFormulario(): Collection
    {
        return ExportacionProducto::where('activo', true)
            ->orderBy('nombre_es')
            ->get(['id', 'nombre_es', 'nombre_en', 'unidad', 'unidades_por_caja', 'precio_caja', 'peso_neto_caja_kg', 'peso_bruto_caja_kg']);
    }

    private function clientesParaFormulario(): Collection
    {
        return ExportacionCliente::where('activo', true)
            ->with(['cliente:id,nombre,direccion', 'productos' => fn ($q) => $q->where('activo', true)])
            ->get()
            ->sortBy(fn (ExportacionCliente $c) => $c->nombreLegal())
            ->values();
    }
}
