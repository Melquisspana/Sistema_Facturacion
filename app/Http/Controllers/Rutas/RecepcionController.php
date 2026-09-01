<?php

namespace App\Http\Controllers\Rutas;

use App\Exceptions\Rutas\DocumentoYaRecibidoException;
use App\Http\Controllers\Controller;
use App\Models\SalidaRutaDocumento;
use App\Services\Rutas\Custodia;
use App\Services\Rutas\RecepcionDocumentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recepción de CCF firmados: la pantalla de quien recibe en oficina.
 *
 * ─────────────────────── Para qué está optimizada ───────────────────────
 *
 * Para hacer lo mismo muchas veces seguidas. Un vendedor vuelve con veinte papeles y
 * alguien los pasa uno por uno: se escanea, se confirma, el foco vuelve al campo. Cada clic
 * de más se multiplica por veinte.
 *
 * El escáner no necesita integración: teclea el contenido y manda Enter, así que la pantalla
 * es un campo de texto con foco automático. Lo que se escriba lo interpreta
 * {@see RecepcionDocumentos}, que prueba número de control, código de generación, número del
 * sistema y últimos dígitos, en ese orden.
 *
 * ─────────────────────── Lo que NO hace ───────────────────────
 *
 * No adivina cuando la búsqueda es ambigua: muestra los candidatos y espera. Registrar la
 * devolución del papel equivocado es peor que no registrar ninguna.
 *
 * No marca nada como pagado. Que el papel haya vuelto habilita el cobro; no es el cobro.
 *
 * Y no la puede usar quien llevaba el documento: `rutas.recepcion` es un permiso distinto de
 * `rutas.custodia.registrar` justamente para eso.
 */
class RecepcionController extends Controller
{
    public function index(Request $request, RecepcionDocumentos $buscador): View
    {
        $texto = trim((string) $request->input('q', ''));

        ['documentos' => $documentos, 'estado' => $estado] = $texto === ''
            ? ['documentos' => collect(), 'estado' => 'vacio']
            : $buscador->resolver($texto);

        return view('rutas.recepcion.index', [
            'q' => $texto,
            'documentos' => $documentos,
            'estado' => $estado,
            // Lo recibido hoy, para que quien está pasando papeles vea avanzar la pila y
            // pueda detectar de inmediato si registró algo por error.
            'recibidosHoy' => SalidaRutaDocumento::query()
                ->conDocumentacionFisica()
                ->whereDate('documentacion_fisica_recibida_at', today())
                ->with(['dte:id,numero_control,total_pagar', 'documentacionRecibidaPor:id,name', 'salida.ruta:id,nombre'])
                ->orderByDesc('documentacion_fisica_recibida_at')
                ->limit(15)
                ->get(),
        ]);
    }

    /** Recepción de UN documento. El caso normal. */
    public function recibir(Request $request, Custodia $custodia): RedirectResponse
    {
        $datos = $request->validate([
            'documento_id' => ['required', 'integer', 'exists:salida_ruta_documentos,id'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $documento = SalidaRutaDocumento::findOrFail($datos['documento_id']);

        try {
            $custodia->recibir($documento, $request->user(), $datos['observacion'] ?? null);
        } catch (DocumentoYaRecibidoException $e) {
            // No es un fallo: es lo que pasa al escanear dos veces el mismo papel. Se dice
            // cuándo y quién lo recibió, y no se cambia nada.
            return $this->volver($request)->with('error', $e->getMessage());
        }

        return $this->volver($request)
            ->with('status', 'Recibido: '.$documento->numeroLegible().'. Escaneá el siguiente.');
    }

    /**
     * Recepción por LOTE.
     *
     * La pantalla muestra antes exactamente qué documentos se van a confirmar: una operación
     * que toca varias filas a la vez no puede pedir un acto de fe. Acá solo llegan los ids
     * que la persona ya vio marcados.
     *
     * Uno a uno y sin transacción global a propósito, igual que el alta de documentos en una
     * salida: si uno choca porque alguien lo recibió hace un segundo, ese se salta y los
     * demás entran. Veinte papeles no se pierden por uno.
     */
    public function recibirLote(Request $request, Custodia $custodia): RedirectResponse
    {
        $datos = $request->validate([
            'documentos' => ['required', 'array', 'min:1'],
            'documentos.*' => ['integer', 'exists:salida_ruta_documentos,id'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [], ['documentos' => 'documentos']);

        $documentos = SalidaRutaDocumento::whereIn('id', $datos['documentos'])->get();

        $recibidos = 0;
        $repetidos = [];

        foreach ($documentos as $documento) {
            try {
                $custodia->recibir($documento, $request->user(), $datos['observacion'] ?? null);
                $recibidos++;
            } catch (DocumentoYaRecibidoException $e) {
                $repetidos[] = $documento->numeroLegible();
            }
        }

        $respuesta = $this->volver($request)->with('status', match ($recibidos) {
            0 => 'No se registró ninguna recepción.',
            1 => '1 documento recibido.',
            default => "{$recibidos} documentos recibidos.",
        });

        return $repetidos === []
            ? $respuesta
            : $respuesta->with('error', 'Ya estaban recibidos: '.implode(', ', $repetidos).'.');
    }

    /**
     * Vuelve a la pantalla dejando el campo de búsqueda limpio.
     *
     * Es deliberado: después de confirmar, lo siguiente es escanear otro papel, no volver a
     * ver el que se acaba de guardar. Conservar el texto obligaría a borrarlo a mano veinte
     * veces seguidas.
     */
    private function volver(Request $request): RedirectResponse
    {
        return redirect()->route('rutas.recepcion.index');
    }
}
