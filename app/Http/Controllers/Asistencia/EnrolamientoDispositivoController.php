<?php

namespace App\Http\Controllers\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AutenticaDispositivoAsistencia;
use App\Http\Requests\Asistencia\IndiceSensorRequest;
use App\Http\Requests\Asistencia\ProgresoEnrolamientoRequest;
use App\Http\Requests\Asistencia\ResultadoEnrolamientoRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Services\Asistencia\ResolverEnrolamiento;
use App\Services\Asistencia\SincronizarIndiceSensor;
use App\Services\Asistencia\TomarOrdenEnrolamiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * El lado del LECTOR en el enrolamiento. Cuatro endpoints, todos máquina a
 * máquina, todos con el MISMO candado que ya usa la marcación.
 *
 * ──────────────── Por qué el lector pregunta y el servidor no llama ────────────────
 *
 * Porque push exigiría inventar una credencial en dirección contraria —sin ella
 * cualquiera en la LAN dispararía enrolamientos—, depender de una IP que el DHCP
 * cambia, y montar un servidor HTTP dentro del ESP32 mientras maneja el AS608 y la
 * pantalla. El sondeo reutiliza la autenticación que ya funciona, sobrevive al NAT
 * y seguirá funcionando el día que este servidor viva en otro sitio.
 *
 * El precio es la latencia, y no importa: enrolar es un acto supervisado, con
 * alguien de pie frente al lector. Tres segundos no se notan.
 *
 * ──────────────────────── Ninguna orden ajena ────────────────────────
 *
 * Cada endpoint resuelve el lector desde el token y comprueba que la orden le
 * pertenece. Con dos o tres lectores, ninguno puede ejecutar —ni mirar— lo que le
 * tocaba a otro, aunque conozca el identificador.
 *
 * ────────────────────────── Contrato estable ──────────────────────────
 *
 * Todas las respuestas traen `ok`; los fallos traen además `motivo`, un código
 * cerrado ({@see MotivoFalloEnrolamiento}) sobre el que el firmware ramifica. El
 * texto de `mensaje` es para la pantalla y puede cambiar; el `motivo`, no.
 */
class EnrolamientoDispositivoController extends Controller
{
    /**
     * SONDEO. Es lo que el lector llama cada pocos segundos mientras está ocioso.
     *
     * Devuelve el token de la orden — la ÚNICA vez que ese valor sale del
     * servidor. No se puede recuperar después.
     */
    public function pendiente(TomarOrdenEnrolamiento $tomar): JsonResponse
    {
        $lector = $this->lector();
        $tomada = $tomar($lector);

        if ($tomada === null) {
            // Se aprovecha para decirle si necesita sincronizar. Así el firmware no
            // tiene que acordarse de hacerlo por su cuenta: en cuanto ve esto,
            // reporta su índice y a partir de ahí se pueden crear órdenes.
            return response()->json([
                'ok' => true,
                'hay_orden' => false,
                'sincronizar_indice' => ! $lector->tieneIndiceSincronizado(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'hay_orden' => true,
            'orden' => $tomada['orden']->paraDispositivo() + ['token' => $tomada['token']],
        ]);
    }

    /**
     * PROGRESO. Opcional para el firmware: solo mueve la orden a `en_curso` y
     * refresca su vencimiento, para que capturar una huella con alguien que tarda
     * en colocar el dedo no expire a mitad.
     *
     * No crea nada y no puede completar nada.
     */
    public function progreso(ProgresoEnrolamientoRequest $request, AsistenciaOrdenEnrolamiento $orden): JsonResponse
    {
        if ($fallo = $this->rechazarSiNoLeCorresponde($request, $orden)) {
            return $fallo;
        }

        if ($orden->estado->esFinal() || $orden->estaVencida()) {
            return $this->cerrada($orden);
        }

        $orden->update([
            'estado' => EstadoOrdenEnrolamiento::EnCurso,
            'detalle' => $request->etapaLegible(),
            // Se estira la ventana: mientras el lector reporta, hay alguien delante.
            'expira_at' => Carbon::now()->addMinutes(AsistenciaOrdenEnrolamiento::MINUTOS_DE_VIDA),
        ]);

        return response()->json([
            'ok' => true,
            'estado' => $orden->estado->value,
            'expira_en' => $orden->refresh()->segundosParaExpirar(),
        ]);
    }

    /**
     * RESULTADO. El acto: el lector dice que grabó, o que no pudo.
     *
     * Es IDEMPOTENTE. El ESP32 puede grabar la plantilla, mandar esto y perder la
     * respuesta; al reintentar obtiene el mismo desenlace, no un error ni una
     * segunda asignación.
     */
    public function resultado(ResultadoEnrolamientoRequest $request, AsistenciaOrdenEnrolamiento $orden, ResolverEnrolamiento $resolver): JsonResponse
    {
        if ($fallo = $this->rechazarSiNoLeCorresponde($request, $orden)) {
            return $fallo;
        }

        $orden = $request->boolean('exito')
            ? $resolver->completar($orden, $request->integer('fingerprint_id'))
            : $resolver->fallarDesdeDispositivo(
                $orden,
                $request->motivo(),
                $request->input('detalle'),
                $request->indiceSensor(),
            );

        return response()->json($this->desenlace($orden));
    }

    /**
     * ÍNDICE DEL SENSOR. Lo que el AS608 dice tener: capacidad y ranuras con
     * plantilla física.
     *
     * Sin esto el servidor elige a ciegas y choca con las plantillas heredadas. El
     * lector lo manda al arrancar y cada vez que el sondeo se lo pide.
     */
    public function indiceSensor(IndiceSensorRequest $request, SincronizarIndiceSensor $sincronizar): JsonResponse
    {
        $lector = $sincronizar(
            $this->lector(),
            $request->integer('capacidad'),
            $request->input('ocupadas', []),
        );

        return response()->json([
            'ok' => true,
            'capacidad' => $lector->capacidad_sensor,
            'ocupadas' => count($lector->ranurasOcupadasEnSensor()),
            'sincronizado_at' => $lector->indice_sincronizado_at?->toIso8601String(),
        ]);
    }

    // ---------------------------------------------------------------- interno

    /** El lector ya autenticado por el middleware. */
    private function lector(): AsistenciaDispositivo
    {
        return request()->attributes->get(AutenticaDispositivoAsistencia::ATRIBUTO);
    }

    /**
     * Dos comprobaciones que van juntas siempre: que la orden sea de ESTE lector y
     * que el token sea el suyo.
     *
     * La respuesta es la MISMA en los dos casos —404, sin distinguir— para que
     * nadie pueda averiguar desde la red qué órdenes existen probando
     * identificadores.
     */
    private function rechazarSiNoLeCorresponde($request, AsistenciaOrdenEnrolamiento $orden): ?JsonResponse
    {
        $esSuya = $orden->asistencia_dispositivo_id === $this->lector()->id;
        $tokenOk = $orden->tokenCoincide((string) $request->input('token', ''));

        if ($esSuya && $tokenOk) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'motivo' => 'orden_no_valida',
            'mensaje' => 'Esa orden no existe o no es de este lector.',
        ], Response::HTTP_NOT_FOUND);
    }

    private function cerrada(AsistenciaOrdenEnrolamiento $orden): JsonResponse
    {
        return response()->json($this->desenlace($orden), Response::HTTP_CONFLICT);
    }

    /** @return array<string, mixed> */
    private function desenlace(AsistenciaOrdenEnrolamiento $orden): array
    {
        $cuerpo = [
            'ok' => $orden->estado === EstadoOrdenEnrolamiento::Completada,
            'estado' => $orden->estado->value,
            'mensaje' => $orden->estado === EstadoOrdenEnrolamiento::Completada
                ? 'Huella de '.($orden->empleado?->nombreCorto() ?? 'la persona').' registrada en la ranura '.$orden->ranura_reservada
                : ($orden->detalle ?? $orden->estado->explicacion()),
        ];

        if ($orden->motivo_fallo !== null) {
            $cuerpo['motivo'] = $orden->motivo_fallo->value;
        }

        if ($orden->asistencia_huella_id !== null) {
            $cuerpo['huella_id'] = $orden->asistencia_huella_id;
        }

        // Cuando el conflicto con una plantilla heredada produjo una orden nueva,
        // se le dice al lector para que siga sin esperar al siguiente sondeo.
        if ($orden->relationLoaded('reintento') && $orden->getRelation('reintento') !== null) {
            $cuerpo['reintento'] = ['orden_id' => $orden->getRelation('reintento')->id];
        }

        return $cuerpo;
    }
}
