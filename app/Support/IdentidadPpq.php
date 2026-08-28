<?php

namespace App\Support;

use App\Services\Ppq\ConciliacionTxtParser;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * REGLA ÚNICA de IDENTIDAD de un documento dentro de PPQ: cuándo dos filas hablan del
 * mismo CCF/NC.
 *
 * ──────────────────────────── El problema que resuelve ────────────────────────────
 *
 * Un mismo documento puede estar en `ppq_items` de dos formas distintas:
 *
 *   · como SNAPSHOT de Gmail — `origen = 'gmail'`, `dte_id` en NULL, solo el número de
 *     control copiado del correo;
 *   · como vínculo LOCAL — `dte_id` apuntando a la fila de `dtes`.
 *
 * Hoy los 158 items existentes son todos del primer tipo: ninguno tiene `dte_id`. Por
 * eso cruzar solo por `dte_id` no encuentra nada y el sistema cree que un documento ya
 * cobrado nunca entró a PPQ. Con la búsqueda local primero eso deja de ser un detalle:
 * es el camino por el que ahora entra el trabajo diario.
 *
 * ───────────────────────────── Las dos llaves, en orden ─────────────────────────────
 *
 *   1. `dte_id` — vínculo explícito, el más fuerte. Hoy casi no existe en los datos,
 *      pero cuando existe manda.
 *   2. `numero_control` NORMALIZADO — la llave que hace el trabajo real. Funciona igual
 *      para los P001 históricos y para los P002 actuales, que es justo lo que hace
 *      falta: el número de control es lo único que ambos caminos comparten.
 *
 * ──────────────────── Lo que deliberadamente NO es identidad ────────────────────
 *
 * El correlativo suelto, la orden de compra, el monto y la sala. Ninguno identifica un
 * documento: una OC ampara varios CCF de la misma sala, dos documentos del mismo día
 * pueden compartir monto, y el correlativo `0986` existe tanto en P001 como en P002.
 * Casar por cualquiera de esos daría por cobrado algo que nadie cobró — que es
 * exactamente el error que este módulo no puede permitirse.
 *
 * ──────────────────────────── Una sola normalización ────────────────────────────
 *
 * No se inventa ninguna: se delega en {@see ConciliacionTxtParser::normalizarNumero()},
 * que es con la que PPQ ya cruza sus items contra el TXT de pagos de Calleja. Si algún
 * día cambia, cambia en un solo sitio y todo el módulo la sigue.
 */
final class IdentidadPpq
{
    /**
     * Separadores que se limpian en SQL para comparar números de control.
     *
     * `normalizar()` quita en PHP todo lo que no sea alfanumérico; en SQL hay que
     * enumerar, y estos son los separadores que un número de control puede llevar de
     * verdad: el que escribe el sistema (`-`) y los que puede teclear una persona al
     * cargar un histórico P001 a mano. La comparación se hace además contra el valor ya
     * normalizado en PHP, así que un carácter exótico solo haría que ese documento no se
     * encuentre —nunca que se encuentre el equivocado—.
     */
    private const SEPARADORES = ['-', ' ', '.', '/', '_'];

    /**
     * Número de control comparable: solo alfanuméricos en mayúscula, o `null` si no hay
     * número. Así `DTE-03-M001P002-000000000000986` y `DTE03M001P002000000000000986`
     * son el mismo documento.
     */
    public static function normalizar(?string $numeroControl): ?string
    {
        return ConciliacionTxtParser::normalizarNumero($numeroControl);
    }

    /**
     * Claves normalizadas ÚNICAS de una lista de números, descartando los vacíos.
     *
     * @param  iterable<int, string|null>  $numeros
     * @return array<int, string>
     */
    public static function claves(iterable $numeros): array
    {
        $claves = [];
        foreach ($numeros as $numero) {
            $clave = self::normalizar($numero);
            if ($clave !== null) {
                $claves[$clave] = true;
            }
        }

        return array_keys($claves);
    }

    /**
     * `numero_control` sin separadores y en mayúsculas, como expresión SQL, para
     * comparar NORMALIZANDO LOS DOS LADOS dentro de la consulta.
     *
     * Normalizar de un solo lado arregla una dirección y deja la otra rota: el mismo
     * documento aparece como `DTE-03-…-1090` escrito por el sistema y puede venir
     * tecleado como `DTE03…1090` en el alta manual de un P001.
     *
     * Se arma anidando `REPLACE` porque es lo único que MySQL y SQLite entienden igual
     * (la suite corre en SQLite y la operación en MySQL). Que esto impida usar un índice
     * no cambia nada en la práctica: `numero_control` solo existe como SEGUNDA columna
     * de `(ppq_lote_id, numero_control)`, así que una búsqueda por número suelto ya
     * recorría la tabla entera de todos modos.
     */
    public static function columnaNormalizada(string $columna = 'numero_control'): Expression
    {
        $expresion = $columna;

        foreach (self::SEPARADORES as $separador) {
            $expresion = "REPLACE({$expresion}, '{$separador}', '')";
        }

        return DB::raw("UPPER({$expresion})");
    }
}
