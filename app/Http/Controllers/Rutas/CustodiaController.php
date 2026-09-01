<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\CustodiaDocumentoEvento;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Services\Rutas\Custodia;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Los hechos de CAMPO de la custodia del CCF físico: entregarlo, pasarlo a otra persona y
 * reportar que algo salió mal.
 *
 * La RECEPCIÓN en oficina no está acá y no es un olvido: quien llevaba el papel no puede
 * declarar que la oficina ya lo recibió. Vive en {@see RecepcionController}, con su propio
 * permiso y su propia pantalla.
 *
 * Este controlador no decide nada: valida la entrada y traduce el resultado a un mensaje.
 * Las reglas —persona activa, salida no cancelada, quién tiene qué— viven en
 * {@see Custodia}, que es el punto único de escritura.
 */
class CustodiaController extends Controller
{
    public function __construct(private readonly Custodia $custodia) {}

    /** Bodega entrega el documento impreso a quien sale. Primer eslabón de la cadena. */
    public function entregar(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $datos = $request->validate([
            'destino_personal_id' => ['required', 'integer', 'exists:rutas_personal,id'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [], ['destino_personal_id' => 'persona']);

        $destino = PersonalRuta::findOrFail($datos['destino_personal_id']);

        $this->sinPerderElPanel($documento, fn () => $this->custodia->entregar(
            $documento, $destino, $request->user(), $datos['observacion'] ?? null
        ));

        return back()->with('status', "{$documento->numeroLegible()} entregado a {$destino->nombre}.");
    }

    /**
     * El papel cambia de manos sin volver a la empresa: típicamente un vendedor se lo pasa
     * al responsable de la salida para que lo entregue todo junto al regresar.
     */
    public function transferir(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $datos = $request->validate([
            'destino_personal_id' => ['required', 'integer', 'exists:rutas_personal,id'],
            'observacion' => ['nullable', 'string', 'max:500'],
            // Quién tenía el papel según la pantalla desde la que se pulsó. Si para cuando
            // llega esto ya lo tiene otro, el servicio rechaza en vez de encadenar sobre un
            // origen que quien envió el formulario nunca vio.
            'custodio_esperado_id' => ['nullable', 'integer'],
        ], [], ['destino_personal_id' => 'persona']);

        $destino = PersonalRuta::findOrFail($datos['destino_personal_id']);

        $this->sinPerderElPanel($documento, fn () => $this->custodia->transferir(
            $documento,
            $destino,
            $request->user(),
            $datos['observacion'] ?? null,
            custodioEsperadoId: $datos['custodio_esperado_id'] ?? null,
        ));

        return back()->with('status', "{$documento->numeroLegible()} ahora lo lleva {$destino->nombre}.");
    }

    /** Se reporta un problema con el papel. La observación es obligatoria: sin ella no sirve. */
    public function incidencia(Request $request, SalidaRuta $salida, SalidaRutaDocumento $documento): RedirectResponse
    {
        $this->exigirDeLaSalida($salida, $documento);

        $datos = $request->validate([
            'observacion' => ['required', 'string', 'max:500'],
        ], [], ['observacion' => 'descripción']);

        $this->sinPerderElPanel($documento, fn () => $this->custodia->reportarIncidencia(
            $documento, $request->user(), $datos['observacion']
        ));

        return back()->with('status', "Incidencia registrada para {$documento->numeroLegible()}.");
    }

    /**
     * Anula un registro mal hecho. No lo borra: crea una anulación que lo compensa y exige
     * motivo. Si lo anulado era la recepción vigente, el documento vuelve a estar pendiente.
     *
     * Es una CORRECCIÓN ADMINISTRATIVA, no un hecho de campo: por eso tiene su propio permiso
     * (`rutas.custodia.corregir`), vive fuera del grupo de custodia y en la pantalla aparece
     * dentro del historial, no junto a entregar/transferir/reportar.
     */
    public function anular(Request $request, CustodiaDocumentoEvento $evento): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        // El documento se resuelve ANTES por si la anulación falla: es lo que permite
        // reabrir el panel correcto con el mensaje.
        $documento = $evento->documento;

        if ($documento === null) {
            $this->custodia->anular($evento, $datos['motivo'], $request->user());

            return back()->with('status', 'Registro anulado. Quedó anotado quién lo hizo y por qué.');
        }

        $this->sinPerderElPanel($documento, fn () => $this->custodia->anular(
            $evento, $datos['motivo'], $request->user()
        ));

        return back()->with('status', 'Registro anulado. Quedó anotado quién lo hizo y por qué.');
    }

    /**
     * El documento tiene que ser de ESTA salida. Si no, el enlace está viejo o alguien está
     * probando ids: 404 y no se toca nada.
     */
    private function exigirDeLaSalida(SalidaRuta $salida, SalidaRutaDocumento $documento): void
    {
        abort_unless($documento->salida_ruta_id === $salida->id, 404);
    }

    /**
     * Ejecuta el hecho y, si el servicio lo rechaza, deja anotado QUÉ documento falló.
     *
     * El panel de custodia va plegado y hay uno por documento. Sin esta marca, un error de
     * validación devolvería a una lista de treinta tarjetas cerradas con un mensaje arriba y
     * sin forma de saber a cuál se refería: la persona tendría que abrirlas de a una. Con
     * ella, la vista reabre exactamente el panel que falló.
     *
     * @param  Closure(): mixed  $hecho
     *
     * @throws ValidationException
     */
    private function sinPerderElPanel(SalidaRutaDocumento $documento, Closure $hecho): void
    {
        try {
            $hecho();
        } catch (ValidationException $e) {
            session()->flash('custodia_abierta', $documento->id);

            throw $e;
        }
    }
}
