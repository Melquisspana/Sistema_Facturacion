<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoRevisionDocumento;
use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Exceptions\Rutas\DocumentoNoVigenteException;
use App\Exceptions\Rutas\DocumentoYaAsignadoException;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Services\Ppq\PpqGmailService;
use App\Services\Rutas\AsignadorAutomaticoDocumentos;
use App\Services\Rutas\AsignadorDocumentos;
use App\Services\Rutas\CandidatosDocumentos;
use App\Support\IdentidadPpq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Documentos de una salida: agregarlos, quitarlos, moverlos y anotar sobre ellos
 * los dos hechos manuales (papel recibido, requiere NC).
 *
 * Este controlador NO decide nada por su cuenta: la unicidad, la auditoría y las
 * reglas de qué se puede tocar viven en {@see AsignadorDocumentos}. Acá solo se
 * valida la entrada y se traduce el resultado a un mensaje.
 *
 * Ninguna acción escribe en `dtes` ni en `ppq_albaranes`. El DTE original queda
 * exactamente como estaba.
 */
class SalidaDocumentoController extends Controller
{
    public function __construct(private readonly AsignadorDocumentos $asignador) {}

    // ------------------------------------------------------------- candidatos

    /** Pantalla «Documentos candidatos para esta ruta», con selección múltiple. */
    public function candidatos(Request $request, SalidaRuta $salida, CandidatosDocumentos $candidatos): View
    {
        $this->exigirEditable($salida);

        [$desde, $hasta] = $candidatos->ventana($salida);

        return view('rutas.salidas.candidatos', [
            'salida' => $salida->load('ruta'),
            'candidatos' => $candidatos->paraSalida($salida, $request->only(['q', 'sucursal_id'])),
            'sucursales' => $salida->ruta->sucursales()->orderBy('nombre')->get(['id', 'nombre']),
            'filtros' => $request->only(['q', 'sucursal_id']),
            'desde' => $desde,
            'hasta' => $hasta,
        ]);
    }

    /**
     * Agrega los CCF marcados (camino P002). Uno a uno y sin transacción global: si
     * un documento choca porque alguien acaba de metérselo a otra salida, se salta
     * ese y los demás entran. Un lote de 12 documentos no debería perderse entero
     * por uno.
     */
    public function store(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $this->exigirEditable($salida);

        $datos = $request->validate([
            'dtes' => ['required', 'array', 'min:1'],
            'dtes.*' => [Rule::exists('dtes', 'id')->where('tipo_dte', '03')],
        ], [
            'dtes.required' => 'No marcaste ningún documento.',
        ]);

        $documentos = Dte::whereIn('id', $datos['dtes'])->get();

        $agregados = 0;
        $choques = [];
        $rechazados = [];

        foreach ($documentos as $dte) {
            try {
                $this->asignador->traduciendoChoques(
                    fn () => $this->asignador->agregarDte($salida, $dte, $request->user()),
                    (string) $dte->numero_control,
                );
                $agregados++;
            } catch (DocumentoYaAsignadoException $e) {
                $choques[] = $dte->numero_control;
            } catch (DocumentoNoVigenteException $e) {
                // La pantalla de candidatos ya no los ofrece, pero el id viaja en un POST
                // y cualquiera puede cambiarlo: la comprobación que cuenta es la del
                // servicio. Se reporta por documento y los demás siguen entrando.
                $rechazados[] = $e->getMessage();
            }
        }

        $respuesta = redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', $this->mensajeDeAlta($agregados));

        $errores = array_merge(
            $choques === [] ? [] : ['No se agregaron (ya están en otra salida abierta): '.implode(', ', $choques)],
            $rechazados,
        );

        return $errores === []
            ? $respuesta
            : $respuesta->with('error', implode(' ', $errores));
    }

    // ------------------------------------------------------- P001 histórico

    /**
     * «Agregar documento histórico»: busca un CCF de la serie vieja P001 con la MISMA
     * maquinaria que PPQ (Gmail sobre los correos enviados). No se importa nada a
     * `dtes`; solo se toma lo que el correo ya dice para guardarlo como snapshot.
     *
     * Si Gmail no está disponible se degrada a captura manual del número de control,
     * que es el mínimo con el que el documento queda identificado.
     */
    public function historico(Request $request, SalidaRuta $salida, PpqGmailService $gmail): View
    {
        $this->exigirEditable($salida);

        $q = trim((string) $request->input('q', ''));
        $fichas = null;
        $gmailError = null;
        $gmailDisponible = $gmail->disponible();

        if ($gmailDisponible && $q !== '') {
            try {
                $resolucion = $gmail->resolverCcf($q);
                // Solo CCF: las NC no se «llevan en la ruta», se detectan solas después.
                $fichas = array_values(array_filter(
                    $resolucion['fichas'] ?? [],
                    fn ($f) => (($f['ccf']['tipoDte'] ?? '03') === '03'),
                ));
            } catch (GmailDesconectadoException $e) {
                $gmailDisponible = false;
                $gmailError = $e->getMessage();
            }
        }

        return view('rutas.salidas.historico', [
            'salida' => $salida->load('ruta'),
            'q' => $q,
            'fichas' => $fichas,
            'gmailDisponible' => $gmailDisponible,
            'gmailError' => $gmailError,
            'yaAsignados' => $this->controlesYaAsignados($fichas),
        ]);
    }

    /** Guarda el documento histórico elegido (o capturado a mano). */
    public function storeHistorico(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $this->exigirEditable($salida);

        $datos = $request->validate([
            'numero_control' => ['required', 'string', 'max:40'],
            'numero_orden_compra' => ['nullable', 'string', 'max:40'],
            'cliente_nombre' => ['nullable', 'string', 'max:255'],
            'sala_nombre' => ['nullable', 'string', 'max:255'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'fecha_documento' => ['nullable', 'date'],
        ], [
            'numero_control.required' => 'El número de control es lo mínimo para identificar el documento.',
        ]);

        // El número se recorta ACÁ, antes de buscar, y no solo al guardar.
        //
        // Antes la búsqueda usaba el texto crudo del formulario mientras el alta del
        // histórico sí aplicaba `trim()`. Con eso, pegar el número con un espacio al
        // final no encontraba el DTE y el documento se guardaba como histórico —con su
        // número ya recortado, o sea idéntico al real— sin ninguna señal de que algo
        // había fallado. El síntoma es de los peores: la pantalla queda sin sala, sin
        // fecha, sin albarán y sin PPQ, y el número se ve bien.
        $datos['numero_control'] = trim($datos['numero_control']);

        // Si ese número SÍ existe en `dtes`, no es un histórico: se agrega por el camino
        // normal para que quede con su `dte_id` y sus datos vivos, en vez de como una
        // copia congelada.
        //
        // La resolución pasa por {@see IdentidadPpq::dteLocal()} y no por un `where` suelto
        // sobre el número: el mismo número de control puede existir en PRUEBAS y en
        // PRODUCCIÓN, y un `first()` a secas devolvía el que la base tuviera más a mano.
        // Con eso, tipear el número de un CCF real podía terminar agregando su gemelo de
        // pruebas —con la sala, la fecha y el monto equivocados— sin ninguna señal.
        $dte = IdentidadPpq::dteLocal($datos['numero_control']);

        try {
            $documento = $this->asignador->traduciendoChoques(
                fn () => $dte !== null
                    ? $this->asignador->agregarDte($salida, $dte, $request->user())
                    : $this->asignador->agregarHistorico($salida, $datos, $request->user()),
                $datos['numero_control'],
            );
        } catch (DocumentoYaAsignadoException $e) {
            return back()->with('error', $e->getMessage());
        } catch (DocumentoNoVigenteException $e) {
            // Existe en el sistema pero no ampara nada. NO se cae al camino histórico: eso
            // guardaría una copia congelada de un documento que sí conocemos y que está
            // mal, y la pantalla lo mostraría como si fuera bueno.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', $documento->esHistorico()
                ? "Documento histórico {$documento->numero_control} agregado a la salida."
                : "El documento {$documento->numero_control} ya existía en el sistema: se agregó con sus datos reales, no como histórico.");
    }

    // -------------------------------------------------- automático (P002)

    /**
     * Corre el barrido automático a demanda. Es el mismo servicio que usa el comando:
     * mismas reglas, mismas negativas. Se ofrece como botón porque el barrido NO está
     * programado y no debe estarlo todavía.
     */
    public function asociarAutomatico(Request $request, SalidaRuta $salida, AsignadorAutomaticoDocumentos $automatico): RedirectResponse
    {
        $this->exigirEditable($salida);

        abort_unless(
            $salida->estado === EstadoSalidaRuta::EnCurso,
            403,
            'La asociación automática solo aplica a salidas EN CURSO: es lo que dice que el documento va en este viaje.'
        );

        $resultado = $automatico->barrer((int) config('rutas.asociacion_dias'), $request->user());

        $asignados = count($resultado[AsignadorAutomaticoDocumentos::ASIGNADO] ?? []);
        $ambiguos = count($resultado[AsignadorAutomaticoDocumentos::VARIAS_SALIDAS_EN_CURSO] ?? []);

        $mensaje = $asignados === 0
            ? 'No había documentos nuevos que cumplieran la regla.'
            : "{$asignados} documento(s) asociados automáticamente.";

        if ($ambiguos > 0) {
            $mensaje .= " {$ambiguos} quedaron sin asociar porque su ruta tiene más de una salida en curso: agregalos a mano.";
        }

        return back()->with('status', $mensaje);
    }

    // ------------------------------------------------- quitar / mover

    public function destroy(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $this->asignador->quitar($documento, $request->user());

        return back()->with('status', "Documento {$documento->numeroLegible()} quitado de la salida.");
    }

    public function mover(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $datos = $request->validate([
            'salida_destino_id' => ['required', Rule::exists('salidas_ruta', 'id')],
        ]);

        $destino = SalidaRuta::findOrFail($datos['salida_destino_id']);

        $this->asignador->mover($documento, $destino, $request->user());

        return redirect()
            ->route('rutas.salidas.show', $destino)
            ->with('status', "Documento {$documento->numeroLegible()} movido a «{$destino->descripcionCorta()}».");
    }

    // ------------------------------------------- documentación física

    public function documentacionFisica(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $datos = $request->validate([
            'documentos' => ['required', 'array', 'min:1'],
            'documentos.*' => ['integer'],
        ], [
            'documentos.required' => 'No marcaste ningún documento.',
        ]);

        $marcados = $this->asignador->marcarDocumentacionFisica($salida, $datos['documentos'], $request->user());

        return back()->with('status', match (true) {
            $marcados === 0 => 'Esos documentos ya tenían la documentación física registrada.',
            $marcados === 1 => 'Documentación física registrada para 1 documento.',
            default => "Documentación física registrada para {$marcados} documentos.",
        });
    }

    public function quitarDocumentacionFisica(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $this->asignador->desmarcarDocumentacionFisica($documento, $request->user());

        return back()->with('status', "Se deshizo la documentación física de {$documento->numeroLegible()}.");
    }

    // ------------------------------------------------------- requiere NC

    public function requiereNc(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $datos = $request->validate([
            'motivo_revision' => ['nullable', Rule::in(MotivoRevisionDocumento::valores())],
            'motivo_revision_nota' => ['nullable', 'string', 'max:300'],
        ]);

        $this->asignador->marcarRequiereNc(
            $documento,
            filled($datos['motivo_revision'] ?? null) ? MotivoRevisionDocumento::from($datos['motivo_revision']) : null,
            $datos['motivo_revision_nota'] ?? null,
            $request->user(),
        );

        return back()->with('status', "Documento {$documento->numeroLegible()} marcado como «requiere NC». Esto no emite ninguna nota de crédito.");
    }

    public function quitarRequiereNc(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $this->asignador->desmarcarRequiereNc($documento, $request->user());

        return back()->with('status', "Se quitó la marca «requiere NC» de {$documento->numeroLegible()}.");
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * El documento tiene que ser de ESTA salida. Si no, el enlace está viejo o alguien
     * está probando ids: 404 y no se toca nada.
     */
    private function exigirDeLaSalida(SalidaRuta $salida, SalidaRutaDocumento $documento): void
    {
        abort_unless($documento->salida_ruta_id === $salida->id, 404);
    }

    private function exigirEditable(SalidaRuta $salida): void
    {
        abort_unless(
            $salida->estado->esEditable(),
            403,
            'La lista de documentos de una salida finalizada o cancelada ya no se modifica.'
        );
    }

    private function mensajeDeAlta(int $agregados): string
    {
        return match (true) {
            $agregados === 0 => 'No se agregó ningún documento.',
            $agregados === 1 => '1 documento agregado a la salida.',
            default => "{$agregados} documentos agregados a la salida.",
        };
    }

    /**
     * Números de control (de las fichas de Gmail) que ya pertenecen a alguna salida
     * abierta, para marcarlos en pantalla en vez de dejar que el usuario intente
     * agregarlos y se lleve el error.
     *
     * @param  array<int, array<string, mixed>>|null  $fichas
     * @return array<int, string>
     */
    private function controlesYaAsignados(?array $fichas): array
    {
        if (blank($fichas)) {
            return [];
        }

        $controles = array_values(array_filter(array_map(fn ($f) => $f['ccf']['numeroControl'] ?? null, $fichas)));

        return $controles === []
            ? []
            : SalidaRutaDocumento::vigentes()->whereIn('numero_control', $controles)->pluck('numero_control')->all();
    }
}
