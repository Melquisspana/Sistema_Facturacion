<?php

namespace App\Support\Dte;

use App\Exceptions\Dte\DteEmisionEnCursoException;
use App\Models\Dte;
use App\Services\Dte\DteInvalidacionService;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Candado POR DOCUMENTO para emitir: generar, firmar y transmitir un mismo DTE nunca
 * corren dos veces a la vez (otra pestaña, otro usuario, un reenvío con Hacienda lenta).
 *
 * Mismo patrón que la invalidación ({@see DteInvalidacionService::transmitir()}):
 * `Cache::lock` sin espera, `refresh()` después de tomarlo y liberación en `finally`.
 * Quien no consigue el candado no hace nada y recibe un aviso para reintentar.
 *
 * No reemplaza los bloqueos de fila: al generar y al guardar la respuesta de Hacienda
 * el documento se vuelve a leer bajo `lockForUpdate`, por si el candado venció.
 */
class CandadoEmisionDte
{
    /** Nunca menos que esto, por corta que sea la configuración. */
    private const PISO_SEGUNDOS = 180;

    /** La sonda de internet de la conversión a contingencia: 3 s, fijos en el código. */
    private const SONDA_SEGUNDOS = 3;

    public const OCUPADO = 'Este documento ya se está emitiendo. Esperá unos segundos y recargá.';

    public static function clave(Dte $dte): string
    {
        return 'dte-emision:'.$dte->id;
    }

    /**
     * Cuánto dura el candado. No se renueva, así que tiene que durar más que el pedido más
     * largo; si venciera a mitad, un segundo pedido entraría con el primero todavía
     * hablando con Hacienda. Sale de la misma configuración que alarga ese pedido.
     *
     * El peor ciclo de «generar y transmitir producción», con cada llamada agotando su
     * espera:
     *   · al firmador (`dte.firma.timeout`), 3: revisar que responde, firmar, y volver a
     *     firmar si el documento termina en contingencia;
     *   · a Hacienda (`dte.transmision.timeout`): pedir el token, más un envío y una
     *     consulta por cada intento (el inicial y `max_reenvios`): 2 × reenvíos + 3;
     *   · la sonda de internet antes de declarar contingencia.
     * De fábrica (10 s, 8 s, 2 reenvíos) son 89 s. Se toma el DOBLE, para las esperas de
     * la base y el trabajo propio, con un piso de 180 s.
     *
     * Si el proceso muere sin soltarlo, el candado se libera solo al cumplirse este plazo.
     */
    public static function segundos(): int
    {
        $firmador = max(1, (int) config('dte.firma.timeout', 10));
        $hacienda = max(1, (int) config('dte.transmision.timeout', 8));
        $reenvios = max(0, (int) config('dte.transmision.reintentos.max_reenvios', 2));

        $peorCiclo = 3 * $firmador + $hacienda * (2 * $reenvios + 3) + self::SONDA_SEGUNDOS;

        return max(self::PISO_SEGUNDOS, 2 * $peorCiclo);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     *
     * @throws DteEmisionEnCursoException si otro pedido tiene el candado
     */
    public static function ejecutar(Dte $dte, Closure $accion): mixed
    {
        $lock = Cache::lock(self::clave($dte), self::segundos());
        if (! $lock->get()) {
            throw new DteEmisionEnCursoException(self::OCUPADO);
        }

        try {
            // Lo que el pedido traía en memoria puede ser de antes de que otro terminara.
            $dte->refresh();

            return $accion();
        } finally {
            $lock->release();
        }
    }
}
