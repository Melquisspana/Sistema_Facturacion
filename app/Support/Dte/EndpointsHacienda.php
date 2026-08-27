<?php

namespace App\Support\Dte;

use App\Enums\AmbienteHacienda;

/**
 * FUENTE ÚNICA de las direcciones de los servicios del Ministerio de Hacienda.
 *
 * POR QUÉ EXISTE. Hasta ahora convivían dos mecanismos para resolver lo mismo:
 * `dte.ambientes.{00,01}.*_url` (URL completa por ambiente) y
 * `dte.transmision.url_base` + `endpoint_*` (host + ruta). Autenticación y recepción
 * usaban el segundo, invalidación el primero, y encima cada consumidor repetía su
 * propio fallback con las URLs escritas a mano —el mismo host aparecía literal en
 * cuatro archivos—. Un cambio de endpoint obligaba a acordarse de los cuatro; el que
 * se olvidara no fallaba, seguía apuntando al sitio viejo.
 *
 * Aquí se resuelven los tres endpoints por el MISMO camino, respetando el ambiente
 * CAT-001 ('00' pruebas / '01' producción).
 *
 * PRECEDENCIA (de mayor a menor), idéntica para auth, recepción y anulación:
 *   1. `dte.ambientes.{ambiente}.{auth|recepcion|anulacion}_url` → URL COMPLETA.
 *      Es el override más específico: gana sobre todo lo demás.
 *   2. `dte.transmision.url_base` → reemplaza SOLO el host.
 *   3. Host oficial incorporado ({@see HOST_PRUEBAS} / {@see HOST_PRODUCCION}).
 * La ruta sale de `dte.transmision.endpoint_{auth|recepcion|anulacion}`, y si esa
 * clave está vacía se usa la ruta incorporada. Sin barra final: el manual exige la
 * URL exacta (ej. `.../fesv/recepciondte`).
 *
 * LOS MÉTODOS `*Oficial()` IGNORAN TODA LA CONFIGURACIÓN a propósito. No son un
 * atajo: son la referencia contra la que se comparan las URLs resueltas antes de
 * tocar producción. Si un override apuntara a otro sitio, esa comparación es lo
 * único que lo detecta ({@see \App\Services\Dte\DteInvalidacionService}).
 *
 * ESTA CLASE NO HACE HTTP. Solo arma cadenas de texto.
 *
 * NOTA DE ALCANCE: los hosts y rutas incorporados son los que el sistema viene
 * usando (Manual Técnico del Sistema de Transmisión v2, el que está en
 * `docs/referencias/`). Tenerlos centralizados NO afirma que sigan vigentes: es
 * justamente para poder revisarlos y cambiarlos en un solo sitio cuando se
 * contraste contra el manual vigente.
 */
final class EndpointsHacienda
{
    /** Host oficial del ambiente de PRUEBAS (apitest). */
    public const HOST_PRUEBAS = 'https://apitest.dtes.mh.gob.sv';

    /** Host oficial del ambiente de PRODUCCIÓN. */
    public const HOST_PRODUCCION = 'https://api.dtes.mh.gob.sv';

    /** Ruta del servicio de autenticación (Manual 4.1). */
    public const PATH_AUTH = '/seguridad/auth';

    /** Ruta de recepción uno-a-uno (Manual 4.2.1). */
    public const PATH_RECEPCION = '/fesv/recepciondte';

    /** Ruta de consulta individual de un DTE ya transmitido. */
    public const PATH_CONSULTA = '/fesv/recepcion/consultadte';

    /** Ruta del evento de invalidación/anulación. */
    public const PATH_ANULACION = '/fesv/anulardte';

    /*
    | PENDIENTES DE LA FASE DE CONTINGENCIA — NO implementados a propósito.
    | Se dejan anotados aquí, y no como constantes, para que nadie los use por
    | descuido creyendo que hay algo detrás:
    |   POST /fesv/contingencia                            — evento de contingencia
    |   POST /fesv/recepcionlote                           — transmisión posterior por lote
    |   GET  /fesv/recepcion/consultadtelote/{codigoLote}  — estado del lote
    | Ver docs/TRANSMISION_DTE.md §2.
    */

    /**
     * Rótulos que `dte.transmision.ambiente` acepta como producción. Es un ajuste
     * operativo escrito a mano en el .env, así que se aceptan las formas que ya
     * venían soportándose; CUALQUIER otro valor cuenta como pruebas (fallar hacia
     * el lado seguro: un rótulo mal escrito no debe mandar tráfico a producción).
     */
    private const ROTULOS_PRODUCCION = ['produccion', 'production', 'prod', '01'];

    /**
     * Traduce el rótulo operativo de `dte.transmision.ambiente` ('testing' /
     * 'produccion') al ambiente CAT-001. Ante la duda, PRUEBAS.
     */
    public static function ambienteDesdeRotulo(?string $rotulo): AmbienteHacienda
    {
        $normalizado = strtolower(trim((string) $rotulo));

        return in_array($normalizado, self::ROTULOS_PRODUCCION, true)
            ? AmbienteHacienda::Produccion
            : AmbienteHacienda::Pruebas;
    }

    /** Ambiente de las CREDENCIALES de transmisión, leído de la configuración. */
    public static function ambienteTransmision(): AmbienteHacienda
    {
        return self::ambienteDesdeRotulo((string) config('dte.transmision.ambiente', 'testing'));
    }

    // ------------------------------------------------------------ oficiales

    /** Host oficial del ambiente, sin mirar configuración. */
    public static function hostOficial(AmbienteHacienda $ambiente): string
    {
        return $ambiente->esProduccion() ? self::HOST_PRODUCCION : self::HOST_PRUEBAS;
    }

    /** URL oficial de autenticación, sin overrides. */
    public static function authOficial(AmbienteHacienda $ambiente): string
    {
        return self::hostOficial($ambiente).self::PATH_AUTH;
    }

    /** URL oficial de recepción uno-a-uno, sin overrides. */
    public static function recepcionOficial(AmbienteHacienda $ambiente): string
    {
        return self::hostOficial($ambiente).self::PATH_RECEPCION;
    }

    /** URL oficial de consulta individual, sin overrides. */
    public static function consultaOficial(AmbienteHacienda $ambiente): string
    {
        return self::hostOficial($ambiente).self::PATH_CONSULTA;
    }

    /** URL oficial de invalidación/anulación, sin overrides. */
    public static function anulacionOficial(AmbienteHacienda $ambiente): string
    {
        return self::hostOficial($ambiente).self::PATH_ANULACION;
    }

    // ----------------------------------------------------------- resueltas

    /** URL de autenticación EFECTIVA para el ambiente dado. */
    public static function auth(AmbienteHacienda $ambiente): string
    {
        return self::resolver($ambiente, 'auth_url', 'endpoint_auth', self::PATH_AUTH);
    }

    /** URL de recepción uno-a-uno EFECTIVA para el ambiente dado. */
    public static function recepcion(AmbienteHacienda $ambiente): string
    {
        return self::resolver($ambiente, 'recepcion_url', 'endpoint_recepcion', self::PATH_RECEPCION);
    }

    /** URL de consulta individual EFECTIVA para el ambiente dado. */
    public static function consulta(AmbienteHacienda $ambiente): string
    {
        return self::resolver($ambiente, 'consulta_url', 'endpoint_consulta', self::PATH_CONSULTA);
    }

    /** URL de invalidación/anulación EFECTIVA para el ambiente dado. */
    public static function anulacion(AmbienteHacienda $ambiente): string
    {
        return self::resolver($ambiente, 'anulacion_url', 'endpoint_anulacion', self::PATH_ANULACION);
    }

    // ------------------------------------------------------------- interno

    /**
     * Único punto donde se aplica la precedencia descrita en la cabecera de la clase.
     *
     * @param  string  $claveAmbiente  clave dentro de `dte.ambientes.{00|01}` con la URL completa
     * @param  string  $claveEndpoint  clave dentro de `dte.transmision` con la ruta
     * @param  string  $pathPorDefecto ruta incorporada si la configuración no trae ninguna
     */
    private static function resolver(
        AmbienteHacienda $ambiente,
        string $claveAmbiente,
        string $claveEndpoint,
        string $pathPorDefecto,
    ): string {
        // 1) URL completa por ambiente: gana sobre todo.
        $completa = trim((string) config('dte.ambientes.'.$ambiente->value.'.'.$claveAmbiente, ''));
        if ($completa !== '') {
            return rtrim($completa, '/');
        }

        // 2) Host: override de url_base, o el host oficial del ambiente.
        $base = rtrim(trim((string) config('dte.transmision.url_base', '')), '/');
        if ($base === '') {
            $base = self::hostOficial($ambiente);
        }

        // 3) Ruta: la configurada, o la incorporada si viene vacía. Nunca queda un
        //    endpoint a medias (host suelto sin ruta), que era la forma silenciosa de
        //    terminar haciendo POST contra la raíz del servicio.
        $path = trim((string) config('dte.transmision.'.$claveEndpoint, ''));
        if (trim($path, '/') === '') {
            $path = $pathPorDefecto;
        }

        return rtrim($base.'/'.ltrim($path, '/'), '/');
    }
}
