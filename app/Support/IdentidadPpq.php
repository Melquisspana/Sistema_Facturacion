<?php

namespace App\Support;

use App\Models\Dte;
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
 * ─────────────── El ambiente: la mitad que le faltaba a la identidad LOCAL ───────────────
 *
 * Para un documento LOCAL, el número de control por sí solo NO alcanza. Desde que la
 * unicidad de `dtes` pasó a ser `(ambiente, numero_control)` —los correlativos de pruebas
 * y de producción cuentan desde cero por separado—, el mismo número puede pertenecer a
 * dos documentos distintos: uno real y uno de prueba. Buscar solo por número puede
 * devolver el de pruebas y hacer que un documento simulado bloquee, cobre o le preste sus
 * datos a uno de producción.
 *
 * Por eso {@see dteLocal()} nunca resuelve a ciegas. Y por eso la normalización SIN
 * ambiente sigue existiendo y no se toca: es la que cruza con los SNAPSHOTS de Gmail, que
 * no tienen ambiente que ofrecer y nunca lo van a tener. Son dos preguntas distintas:
 * «¿qué fila de `dtes` es esta?» necesita el ambiente; «¿estos dos renglones hablan del
 * mismo documento?» no puede exigirlo sin dejar fuera a todos los históricos.
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
     * El DTE LOCAL que corresponde a un número de control, resuelto SIN ambigüedad de
     * ambiente. `null` si no existe ninguno.
     *
     * ─────────────────────────── Cómo desempata ───────────────────────────
     *
     * Con `$ambiente` dado, no hay nada que desempatar: se busca exactamente ese par, que
     * es el único de la tabla.
     *
     * Sin ambiente —el caso de quien teclea o pega un número y no sabe ni tiene por qué
     * saber en qué ambiente vive— se ordena por dos criterios, en este orden:
     *
     *   1. VIGENCIA FISCAL: el documento que existe de verdad ante Hacienda gana. Es lo
     *      que hace imposible que un documento de PRUEBAS le robe el lugar a uno real,
     *      que es el caso que motivó todo esto.
     *   2. AMBIENTE OPERATIVO de esta instalación: entre dos igualmente vigentes —o
     *      igualmente no vigentes—, manda el del ambiente en el que se está trabajando.
     *
     * Y a igualdad de todo, el más reciente. Nunca devuelve dos: quien llama necesita una
     * respuesta o ninguna, y una lista lo obligaría a inventar su propio desempate.
     *
     * La comparación normaliza LOS DOS LADOS, así que encuentra el documento tanto si el
     * número viene como lo escribe el sistema (`DTE-03-…-1090`) como si alguien lo pegó
     * sin separadores (`DTE03…1090`).
     */
    public static function dteLocal(?string $numeroControl, ?string $ambiente = null): ?Dte
    {
        $clave = self::normalizar($numeroControl);

        if ($clave === null) {
            return null;
        }

        return Dte::query()
            ->where(self::columnaNormalizada(), $clave)
            ->when($ambiente !== null, fn ($q) => $q->where('ambiente', $ambiente))
            ->when($ambiente === null, fn ($q) => $q
                ->orderByRaw(VigenciaFiscalDte::SQL_PRIORIDAD, VigenciaFiscalDte::bindingsPrioridad())
                ->orderByRaw('CASE WHEN ambiente = ? THEN 0 ELSE 1 END', [(string) config('dte.ambiente')]))
            ->orderByDesc('id')
            ->first();
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
