<?php

namespace App\Services\Ppq;

use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Services\Cobros\AplicadorPagosTxt;

/**
 * Verifica que TODAS las filas del TXT de pagos traigan el código de proveedor
 * esperado, antes de que {@see ConciliadorPpq} o
 * {@see AplicadorPagosTxt} registren un solo pago, una NC
 * aplicada o un ajuste QD.
 *
 * Los pagos de este circuito Calleja son siempre del proveedor 000123. Una fila con un código distinto —o sin
 * código— no es un pago suyo, así que el archivo se rechaza ENTERO en cuanto aparece
 * la primera: coincidir de número de documento con algo local no prueba que sea el
 * mismo emisor, y aplicar solo las filas «buenas» dejaría un archivo a medio procesar
 * sin que nadie lo haya decidido.
 */
class ValidadorCodigoProveedorTxt
{
    public const CODIGO_CALLEJA = '000123';

    /**
     * @param  array<int, array<string, mixed>>  $filas  salida de ConciliacionTxtParser::parse()
     *
     * @throws ArchivoProveedorInvalidoException
     */
    public function verificar(array $filas): void
    {
        foreach ($filas as $fila) {
            $codigo = self::codigoDe($fila);

            if ($codigo !== self::CODIGO_CALLEJA) {
                throw new ArchivoProveedorInvalidoException(self::CODIGO_CALLEJA, $codigo, (int) ($fila['linea'] ?? 0));
            }
        }
    }

    /** El código de proveedor es la primera columna del TXT; el parser solo guarda `raw`. */
    private static function codigoDe(array $fila): string
    {
        $raw = (string) ($fila['raw'] ?? '');
        $primero = explode(';', $raw, 2)[0] ?? '';

        return trim($primero);
    }
}
