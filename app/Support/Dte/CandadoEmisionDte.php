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
    /**
     * Más que el ciclo más largo de un pedido: firma, tres envíos con sus consultas y,
     * si corresponde, la conversión a contingencia. Si el proceso muere, el candado
     * se libera solo al cumplirse este plazo.
     */
    private const SEGUNDOS = 180;

    public const OCUPADO = 'Este documento ya se está emitiendo. Esperá unos segundos y recargá.';

    public static function clave(Dte $dte): string
    {
        return 'dte-emision:'.$dte->id;
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
        $lock = Cache::lock(self::clave($dte), self::SEGUNDOS);
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
