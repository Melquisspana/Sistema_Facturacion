<?php

namespace App\Services\Dte;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;

/**
 * POLÍTICA DE REINTENTOS previa a contingencia.
 *
 * EL PROBLEMA QUE RESUELVE NO ES "QUE ENTRE". Es no duplicar. Cuando recepción no
 * responde, el DTE pudo haber sido recibido igual: la petición se perdió de vuelta, no
 * de ida. Reenviar a ciegas en ese caso transmite dos veces el mismo hecho económico,
 * con dos códigos de generación distintos, y eso no se arregla borrando nada — se
 * arregla invalidando ante Hacienda. Por eso, antes de CADA reenvío se consulta.
 *
 * LOS DOS CASOS DEL MANUAL V2.0
 *   Caso 1 — recepción no responde tras el umbral (8 s).
 *   Caso 2 — el firmador falla y no procesa la respuesta del servicio de recepción.
 *   Los dos terminan en la misma pregunta —¿entró o no?— y en la misma respuesta:
 *   consultar, y reenviar como máximo 2 veces.
 *
 *   En ESTA arquitectura la firma y la transmisión son pasos separados: el firmador no
 *   está en el camino de la respuesta de recepción. Así que el Caso 2 se materializa
 *   como un documento que llega YA FIRMADO de un intento anterior cuyo desenlace nadie
 *   conoce — el usuario que vuelve a pulsar el botón. Para ése se consulta ANTES del
 *   primer envío (`$estadoIncierto`).
 *
 *   Lo que NO es el Caso 2: que la firma falle y no llegue a producir un JWS. Ahí no
 *   hubo envío que consultar, y preguntarle a Hacienda por un documento que nunca salió
 *   solo agrega una petición inútil. Ese camino lanza su excepción de firma y no entra
 *   acá (ver DteController::procesarFirmaTransmision).
 *
 * EL CICLO
 *   1. Enviar.
 *   2. ¿Respuesta definitiva (aceptado / rechazado)? → terminado, se aplica y se sale.
 *   3. ¿Silencio (timeout / conexión caída)? → CONSULTAR el estado.
 *        - El MH lo tiene → esa es la respuesta buena. Se aplica. NO se reenvía.
 *        - El MH no lo tiene → reenviar (paso 1), si quedan reenvíos.
 *        - No se pudo DETERMINAR → NO se reenvía. Se corta con
 *          'estado_recepcion_incierto'.
 *   4. Sin reenvíos → 'reintentos_agotados'.
 *
 * LA REGLA QUE GOBIERNA TODO: **solo se reenvía cuando se ha DETERMINADO que el
 * documento no fue recibido.** El manual manda reenviar «si no ha sido recibido», y una
 * consulta que falla no demuestra eso: no demuestra nada. Los dos errores posibles no
 * son simétricos —no enviar se arregla enviando más tarde; enviar dos veces deja un
 * duplicado emitido, con numeración oficial gastada, que solo se corrige invalidando
 * ante Hacienda—. Ante la duda, siempre detenerse.
 *
 * Y "consulta que falla" incluye la que ni siquiera se puede formular: si consultar
 * lanza una excepción de precondición, tampoco se determinó nada, así que tampoco se
 * envía. Lo único que se deja pasar hacia arriba es el candado
 * ({@see DteTransmisionDeshabilitadaException}): un candado no se degrada a resultado,
 * porque quien lo abre es una persona, no un reintento.
 *
 * EL TOPE SON 2 REENVÍOS, Y SE CUENTAN SOBRE EL DOCUMENTO, NO SOBRE LA LLAMADA. En el
 * camino normal esta clase hace el envío inicial y hasta 2 reenvíos: 3 en total. En el
 * Caso 2 el envío inicial YA OCURRIÓ —fuera de esta invocación, en el intento cuyo
 * desenlace se desconoce—, así que cuando la consulta previa confirma que el MH no lo
 * tiene, a esta llamada le quedan 2 envíos, no 3. Contarlo de otro modo dejaría 4
 * transmisiones del mismo documento repartidas entre dos pulsaciones del botón, que es
 * exactamente lo que el tope existe para impedir.
 *
 * QUÉ **NO** HACE, a propósito:
 *   - NO activa contingencia. NO crea ni transmite evento de contingencia. Cuando se
 *     agotan los reintentos devuelve un resultado explícito que dice que la
 *     contingencia haría falta, y ahí se detiene: es una decisión con requisitos
 *     propios, diferida a su fase.
 *   - NO regenera el código de generación ni el número de control. Se reenvía el
 *     MISMO documento; regenerarlos convertiría un reintento en un documento nuevo.
 *   - NO inventa candados: todo pasa por {@see DteTransmisionService::transmitir()},
 *     que sigue evaluando los suyos en cada intento.
 *
 * DECISIÓN SOBRE QUÉ CUENTA COMO "SIN RESPUESTA". Solo 'error_conexion' (timeout o
 * conexión caída). Un token rechazado, un HTTP 500 o un JSON roto SON respuestas: el
 * servidor contestó. Reintentarlos no es la política del manual, es insistir, y en el
 * caso del 500 podría duplicar. Se devuelven tal cual, sin reenviar.
 */
class DteTransmisionResiliente
{
    /** Resultados del MH que cierran el ciclo: ya no hay nada que reintentar. */
    private const DEFINITIVOS = ['aceptado', 'rechazado'];

    /** Único resultado que significa "no hubo respuesta" y habilita la consulta. */
    private const SIN_RESPUESTA = 'error_conexion';

    /** Única respuesta de consulta que DEMUESTRA que el MH no tiene el documento. */
    private const NO_RECIBIDO = 'no_encontrado';

    /**
     * No se pudo DETERMINAR si Hacienda tiene el documento. Vale tanto para la consulta
     * previa como para la del bucle: el vocabulario es uno solo a propósito, porque la
     * situación —y la decisión— son la misma en los dos sitios.
     */
    public const ESTADO_INCIERTO = 'estado_recepcion_incierto';

    /**
     * Nombre del campo que acompaña SIEMPRE a ESTADO_INCIERTO y dice por qué se cortó:
     * la consulta no llegó a determinar nada (falló, no concluyó, o no se pudo ni
     * formular). Va aparte de `resultado` porque quien lee el resultado necesita las dos
     * cosas —qué pasa con el documento, y qué pasó con la consulta— y deducir la segunda
     * a partir de la primera es justo el atajo que produjo el error que esto corrige.
     */
    public const CONSULTA_NO_DISPONIBLE = 'consulta_no_disponible';

    public function __construct(
        private readonly DteTransmisionService $transmision,
        private readonly DteConsultaService $consulta,
    ) {}

    /**
     * Transmite aplicando la política de reintentos.
     *
     * @return array{
     *     resultado: string, http_status: int|null, mensaje: string, sello: string|null,
     *     envios: int, consultas: int, contingencia_requerida: bool,
     *     consulta_no_disponible: bool, consulta_resultado: string|null,
     *     traza: array<int, string>
     * }
     *
     * `resultado` añade a los de {@see DteTransmisionService::transmitir()}:
     *   'reintentos_agotados'        → se agotaron los reenvíos sin respuesta definitiva.
     *                                  `contingencia_requerida` = true.
     *   'estado_recepcion_incierto'  → no se pudo determinar si Hacienda lo tiene. NO se
     *                                  envió ni se reenvió, para no arriesgar un
     *                                  duplicado. El documento queda intacto y NO se
     *                                  activa contingencia. Va SIEMPRE acompañado de
     *                                  `consulta_no_disponible` = true, y del resultado
     *                                  crudo de la consulta en `consulta_resultado`
     *                                  (null si la consulta no se pudo ni formular).
     *
     * @param  bool  $estadoIncierto  CASO 2 del manual. `true` cuando el documento llega
     *                                YA firmado de un intento anterior cuyo desenlace no
     *                                se conoce: entonces se CONSULTA antes del primer
     *                                envío, no solo tras un timeout.
     *
     * @throws DteTransmisionDeshabilitadaException si los candados bloquean el envío
     */
    public function transmitir(Dte $dte, bool $estadoIncierto = false): array
    {
        // Política apagada: comportamiento idéntico al anterior, un solo envío.
        if (! (bool) config('dte.transmision.reintentos.enabled', true)) {
            return $this->conMetadatos($this->transmision->transmitir($dte), 1, 0, [], false);
        }

        $maxReenvios = max(0, (int) config('dte.transmision.reintentos.max_reenvios', 2));
        $traza = [];
        $envios = 0;
        $consultas = 0;

        // ¿El envío inicial ya lo gastó un intento anterior? Solo se sabe cuando la
        // consulta previa lo DETERMINA; mientras no, esta llamada dispone del ciclo
        // completo (inicial + reenvíos).
        $yaHuboEnvioPrevio = false;

        // --- CASO 2 del manual: el desenlace del intento anterior es incierto. ---
        // En MOCK no se pregunta: no hay nada real que consultar y hacerlo sacaría una
        // petición a la red en el único modo que promete no tocarla.
        if ($estadoIncierto && ! (bool) config('dte.transmision.mock', false)) {
            $consultas++;

            try {
                $previa = $this->consulta->consultar($dte);
            } catch (DteTransmisionDeshabilitadaException $e) {
                // Candado cerrado: se propaga tal cual, no se disfraza de resultado.
                throw $e;
            } catch (DteTransmisionException $e) {
                // No se pudo ni formular la consulta (falta NIT, falta código de
                // generación). Tampoco se determinó nada → misma decisión: no se envía.
                $traza[] = 'consulta previa no se pudo hacer: NO se envía';

                return $this->corteIncierto(
                    'No se pudo consultar en Hacienda si este DTE ya fue recibido, y no es seguro '
                        .'reenviarlo hasta saberlo: podría duplicarse.',
                    $e->getMessage(), null, null, 0, $consultas, $traza,
                );
            }

            $traza[] = 'consulta previa (estado incierto) → '.$previa['resultado']
                .($previa['recibido'] ? ' (RECIBIDO)' : '');

            if ($previa['recibido']) {
                // Ya había entrado en el intento anterior. Enviar de nuevo lo duplicaría.
                $aplicado = $this->transmision->aplicarResultadoDeConsulta($dte, $previa);
                $traza[] = 'ya estaba recibido: no se envía';

                return $this->conMetadatos($aplicado, 0, $consultas, $traza, false);
            }

            // La consulta no concluyó. NO se envía.
            //
            // El manual manda reenviar cuando se DETERMINA que no fue recibido, y una
            // consulta que falla no determina nada: no demuestra que Hacienda no lo
            // tenga. Enviar aquí sería apostar, y el lado malo de la apuesta es un
            // documento duplicado —ya emitido, con numeración oficial gastada, que solo
            // se corrige invalidando ante Hacienda—. El otro lado es un envío que se
            // hace más tarde. No son comparables.
            if ($previa['resultado'] !== self::NO_RECIBIDO) {
                $traza[] = 'estado previo indeterminado: NO se envía';

                return $this->corteIncierto(
                    'No se pudo determinar en Hacienda si este DTE ya fue recibido, y no es seguro '
                        .'reenviarlo hasta saberlo: podría duplicarse.',
                    $previa['mensaje'], $previa['http_status'], $previa['resultado'], 0, $consultas, $traza,
                );
            }

            // DETERMINADO que no entró: se puede reenviar sin duplicar. Pero el envío
            // del intento anterior ya gastó el primero de los tres, así que a esta
            // llamada le quedan los reenvíos, no el ciclo completo.
            $yaHuboEnvioPrevio = true;
            $traza[] = 'confirmado NO recibido: quedan '.$maxReenvios.' reenvíos';
        }

        // Envío inicial + hasta $maxReenvios reenvíos —o SOLO los reenvíos, si el
        // inicial ya lo hizo el intento anterior—. El bucle NO puede pasar de ahí: el
        // límite es la condición, no un `break` que alguien pueda mover.
        $enviosPermitidos = $yaHuboEnvioPrevio ? $maxReenvios : $maxReenvios + 1;

        for ($intento = 1; $intento <= $enviosPermitidos; $intento++) {
            $envios++;
            $numeroReenvio = $yaHuboEnvioPrevio ? $intento : $intento - 1;
            $etiqueta = $numeroReenvio === 0 ? 'envío inicial' : 'reenvío '.$numeroReenvio.'/'.$maxReenvios;

            $r = $this->transmision->transmitir($dte);
            $traza[] = $etiqueta.' → '.$r['resultado'];

            // Respuesta definitiva: ya la aplicó transmitir(). Terminado.
            if (in_array($r['resultado'], self::DEFINITIVOS, true)) {
                return $this->conMetadatos($r, $envios, $consultas, $traza, false);
            }

            // Cualquier otra respuesta que NO sea silencio: el servidor contestó algo.
            // No se reintenta (ver decisión en la cabecera de la clase).
            if ($r['resultado'] !== self::SIN_RESPUESTA) {
                return $this->conMetadatos($r, $envios, $consultas, $traza, false);
            }

            // --- Silencio. Antes de decidir nada, preguntar si entró. ---
            $consultas++;

            try {
                $c = $this->consulta->consultar($dte);
            } catch (DteTransmisionDeshabilitadaException $e) {
                throw $e;
            } catch (DteTransmisionException $e) {
                // Mismo criterio que en la consulta previa: sin consulta no hay
                // determinación, y sin determinación no se reenvía. Acá pesa más
                // todavía, porque el envío que acaba de quedarse mudo pudo entrar.
                $traza[] = 'consulta no se pudo hacer: se corta sin reenviar';

                return $this->corteIncierto(
                    'No se pudo consultar en Hacienda si el DTE fue recibido, y no es seguro '
                        .'reenviarlo hasta saberlo: podría duplicarse.',
                    $e->getMessage(), null, null, $envios, $consultas, $traza,
                );
            }

            $traza[] = 'consulta → '.$c['resultado'].($c['recibido'] ? ' (RECIBIDO)' : '');

            if ($c['recibido']) {
                // Entró. Esta es la respuesta buena: se aplica por el camino normal
                // (sello, historial, estado) y NO se reenvía.
                $aplicado = $this->transmision->aplicarResultadoDeConsulta($dte, $c);
                $traza[] = 'estado tomado de la consulta: no se reenvía';

                return $this->conMetadatos($aplicado, $envios, $consultas, $traza, false);
            }

            if ($c['resultado'] !== self::NO_RECIBIDO) {
                // Mismo criterio que en la consulta previa, y por la misma razón: sin
                // determinar el estado no se reenvía. Acá el envío acaba de salir, así
                // que el riesgo de duplicar es todavía más directo.
                $traza[] = 'estado indeterminado: se corta sin reenviar';

                return $this->corteIncierto(
                    'No se pudo determinar en Hacienda si el DTE fue recibido, y no es seguro '
                        .'reenviarlo hasta saberlo: podría duplicarse.',
                    $c['mensaje'], $c['http_status'], $c['resultado'], $envios, $consultas, $traza,
                );
            }

            // Confirmado NO recibido: el siguiente giro del bucle reenvía (si queda).
            $traza[] = 'confirmado NO recibido: se puede reenviar sin duplicar';
        }

        // Se agotaron los reenvíos y el MH nunca confirmó haberlo recibido. `$envios`
        // puede ser 0: con max_reenvios=0 y un Caso 2 confirmado no queda ningún envío
        // permitido, y decir "se hicieron 0 envíos" sin más sonaría a fallo del sistema
        // cuando en realidad es el tope haciendo su trabajo.
        return $this->conMetadatos([
            'resultado' => 'reintentos_agotados',
            'http_status' => null,
            'mensaje' => 'Reintentos agotados — contingencia requerida. '
                .($envios === 0
                    ? 'No quedaba ningún reenvío permitido (max_reenvios='.$maxReenvios.') y Hacienda '
                        .'no tiene el documento.'
                    : 'Se hicieron '.$envios.' envíos y Hacienda no confirmó la recepción en ninguno.')
                .' El documento sigue sin transmitir; NO se activó ningún evento de contingencia '
                .'(fase pendiente).',
            'sello' => null,
        ], $envios, $consultas, $traza, true);
    }

    // ---------------------------------------------------------------- interno

    /**
     * Corte por estado indeterminado: NO se envió (ni se reenvió) nada más, NO se tocó
     * el documento —ni código de generación ni número de control— y NO se activó
     * contingencia. Es un único sitio a propósito: los dos puntos de corte de la clase
     * dicen lo mismo porque deciden lo mismo, y tenerlo escrito dos veces fue lo que
     * permitió que uno de ellos se desviara del otro.
     *
     * @param  string|null  $consultaResultado  resultado crudo de la consulta, o null si
     *                                          no se pudo ni formular.
     * @param  array<int, string>  $traza
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, envios: int, consultas: int, contingencia_requerida: bool, consulta_no_disponible: bool, consulta_resultado: string|null, traza: array<int, string>}
     */
    private function corteIncierto(
        string $encabezado,
        string $detalle,
        ?int $httpStatus,
        ?string $consultaResultado,
        int $envios,
        int $consultas,
        array $traza,
    ): array {
        return $this->conMetadatos([
            'resultado' => self::ESTADO_INCIERTO,
            'http_status' => $httpStatus,
            'mensaje' => $encabezado.' No se envió nada nuevo, no se cambió el documento y NO se '
                .'activó contingencia. Reintentá cuando el servicio de consulta responda. '
                .'Detalle: '.$detalle,
            'sello' => null,
        ], $envios, $consultas, $traza, false, true, $consultaResultado);
    }

    /**
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null}  $r
     * @param  array<int, string>  $traza
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, envios: int, consultas: int, contingencia_requerida: bool, consulta_no_disponible: bool, consulta_resultado: string|null, traza: array<int, string>}
     */
    private function conMetadatos(
        array $r,
        int $envios,
        int $consultas,
        array $traza,
        bool $contingencia,
        bool $consultaNoDisponible = false,
        ?string $consultaResultado = null,
    ): array {
        return array_merge($r, [
            'envios' => $envios,
            'consultas' => $consultas,
            'contingencia_requerida' => $contingencia,
            self::CONSULTA_NO_DISPONIBLE => $consultaNoDisponible,
            'consulta_resultado' => $consultaResultado,
            'traza' => $traza,
        ]);
    }
}
