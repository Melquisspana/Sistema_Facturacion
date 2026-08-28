<?php

namespace App\Support\Dte;

use App\Enums\AmbienteHacienda;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;

/**
 * CANDADO DEL ENDPOINT OFICIAL — un solo criterio para los cuatro servicios del MH
 * (autenticación, recepción, consulta e invalidación), en los DOS ambientes.
 *
 * POR QUÉ EXISTE. El aviso del Ministerio de Hacienda es que solo deben consumirse los
 * endpoints publicados. Hasta ahora cada servicio lo interpretaba por su cuenta y con
 * distinto rigor: la invalidación exigía la URL exacta en los dos ambientes, recepción
 * solo en producción, la consulta no exigía nada, y la autenticación comprobaba el
 * ambiente de pruebas con `str_starts_with` —que acepta
 * `https://apitest.dtes.mh.gob.sv.impostor.test`, porque efectivamente EMPIEZA por el
 * host oficial—. Cuatro criterios distintos para una sola regla es la forma segura de
 * que uno se quede atrás; por eso la regla vive aquí y los servicios la consultan.
 *
 * IGUALDAD EXACTA DE CADENA, nunca análisis de la URL. No se parsea el host ni se
 * comprueba "que termine en mh.gob.sv": esa clase de comprobación es justamente la que
 * deja pasar el subdominio impostor. Comparar la cadena completa contra la constante
 * oficial descarta de una sola vez el esquema, el host, el puerto, la ruta, el query
 * string y el fragmento, sin tener que enumerarlos ni acertar con una expresión
 * regular.
 *
 * APITEST TAMBIÉN ESTÁ CERRADO. Un envío a apitest es una operación REAL: sale de la
 * máquina y lleva credenciales y token de verdad. Los mocks locales no necesitan un
 * host alternativo —se hacen con `Http::fake()` en las pruebas o con el modo mock del
 * sistema, que no llega a construir ninguna petición—, así que permitir hosts
 * arbitrarios en pruebas solo servía para que un `url_base` mal puesto mandara
 * credenciales a cualquier sitio.
 *
 * ESTA CLASE NO HACE HTTP y no abre ningún candado: solo puede impedir una llamada.
 */
final class CandadoEndpointOficial
{
    /** Nombre del servicio en el mensaje de bloqueo (no es configuración). */
    public const AUTH = 'autenticación';

    public const RECEPCION = 'recepción';

    public const CONSULTA = 'consulta';

    public const ANULACION = 'anulación';

    /**
     * Razón del bloqueo, o null si la URL es la oficial exacta.
     *
     * Para los servicios que ACUMULAN razones (los `evaluarCandados()` de recepción e
     * invalidación, que devuelven la lista completa en vez de cortar en la primera).
     */
    public static function razon(
        AmbienteHacienda $ambiente,
        string $servicio,
        string $urlOficial,
        string $urlResuelta,
    ): ?string {
        if ($urlResuelta === $urlOficial) {
            return null;
        }

        // Se conserva el vocabulario que ya usaban recepción e invalidación
        // ("productivo exacto" / "de apitest exacto"): es el que leen los operadores en
        // el panel de candados y el que fijan las pruebas.
        $cual = $ambiente->esProduccion() ? 'el productivo exacto' : 'el de apitest exacto';

        return 'El endpoint de '.$servicio.' no es '.$cual.' ('.$urlOficial.'): '.$urlResuelta.'.';
    }

    /**
     * Igual que {@see razon()}, pero corta con excepción. Para los servicios que hacen
     * la comprobación en línea, justo antes de pedir credenciales o token.
     *
     * Lanza DteTransmisionDeshabilitadaException —la misma que el interruptor maestro—
     * a propósito: {@see \App\Services\Dte\DteTransmisionResiliente} la propaga tal cual
     * en vez de convertirla en un resultado, de modo que un endpoint bloqueado NUNCA
     * puede acabar traduciéndose en un reenvío.
     *
     * @throws DteTransmisionDeshabilitadaException si la URL no es la oficial exacta
     */
    public static function verificar(
        AmbienteHacienda $ambiente,
        string $servicio,
        string $urlOficial,
        string $urlResuelta,
    ): void {
        $razon = self::razon($ambiente, $servicio, $urlOficial, $urlResuelta);
        if ($razon !== null) {
            throw new DteTransmisionDeshabilitadaException(
                $razon.' No se hizo ninguna petición ni se enviaron credenciales.'
            );
        }
    }
}
