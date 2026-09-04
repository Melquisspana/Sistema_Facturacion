<?php

namespace App\Services\Ppq;

use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Support\IdentidadPpq;
use App\Support\OrdenCompra;
use App\Support\PpqElegibilidad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda de CCF/NC para el módulo PPQ. Solo CONSULTA documentos ya emitidos
 * (CCF tipo 03 y NC tipo 05); no toca la emisión.
 *
 * ───────────────────────── DOS búsquedas, no una ─────────────────────────
 *
 *   · {@see buscarExacto()} — el buscador PRINCIPAL. Devuelve UN documento o ninguno.
 *     Es el que se usa a diario: se teclea el número del CCF y sale ese CCF.
 *   · {@see buscar()} — la búsqueda AVANZADA. Devuelve una página de resultados
 *     filtrados por orden de compra, cliente, sala, fecha, monto, número de control o
 *     código de generación. Puede devolver varios, y eso está bien: se pide a propósito.
 *
 * ────────────── Por qué se separaron: el defecto que arreglan ──────────────
 *
 * Antes había una sola búsqueda y el término se comparaba así:
 *
 *     correlativoExacto(numero_control)
 *     OR codigo_generacion   LIKE %termino%
 *     OR sello_recepcion     LIKE %termino%
 *     OR numero_orden_compra LIKE %termino%
 *
 * La primera línea era exacta; las otras tres, subcadenas. `codigo_generacion` es un
 * UUID de 36 caracteres y `sello_recepcion` una cadena larga y aleatoria: buscar cuatro
 * dígitos DENTRO de ellos acierta por azar, y con suficientes documentos casi cualquier
 * correlativo aparece incrustado en algún UUID o algún sello. La orden de compra sumaba
 * lo suyo, porque una sola OC ampara varios CCF de la misma sala. De ahí salían los
 * cinco a ocho resultados «parecidos» que no tenían relación con lo buscado.
 *
 * El buscador exacto no compara contra ninguno de esos tres campos: solo contra el
 * número de control, y de forma exacta.
 */
class PpqBusquedaService
{
    /** Tipos de documento cobrables vía PPQ. */
    private const TIPOS = ['03', '05'];

    /**
     * Ancho máximo que se le supone a la secuencia final del número de control.
     * La norma son 15 dígitos, pero hay documentos históricos con 16, así que el
     * correlativo se busca probando anchos en vez de dar uno por sentado.
     */
    private const ANCHO_MAX_SECUENCIA = 20;

    /**
     * ¿Este documento local RESUELVE la búsqueda sin necesidad de Gmail?
     *
     * Es exactamente la misma pregunta que «¿se puede cobrar por PPQ?», y por eso la
     * respuesta la da {@see PpqElegibilidad} y no una condición propia de acá. Un
     * documento que no se puede agregar a un lote tampoco puede dar la búsqueda por
     * cerrada: puede seguir habiendo un histórico de Conta/P001 en el correo, que es
     * justo para lo que Gmail queda como respaldo.
     */
    public static function resuelveSinGmail(Dte $dte): bool
    {
        return PpqElegibilidad::esElegible($dte);
    }

    /**
     * ¿Alguno de los resultados ya cargados resuelve la búsqueda sin Gmail?
     *
     * Trabaja sobre la colección que la vista ya tiene en memoria: NO agrega ninguna
     * consulta a la búsqueda.
     *
     * @param  iterable<int, Dte>  $resultados
     */
    public function hayResolutivoLocal(iterable $resultados): bool
    {
        foreach ($resultados as $dte) {
            if (self::resuelveSinGmail($dte)) {
                return true;
            }
        }

        return false;
    }

    /**
     * EL BUSCADOR PRINCIPAL: un número, un documento. Devuelve `null` si no existe.
     *
     * Acepta las dos formas en que la gente escribe un número de CCF:
     *
     *   · el CORRELATIVO suelto («0986», «986») — es lo que se teclea a diario, los
     *     últimos dígitos del documento;
     *   · el NÚMERO DE CONTROL completo, con separadores o sin ellos
     *     («DTE-03-M001P002-000000000000986» o «DTE03M001P002000000000000986»).
     *
     * Lo que NO hace: buscar parecidos. No compara contra el código de generación, el
     * sello ni la orden de compra, que fue de donde salían los resultados ajenos. Un
     * número parcial no devuelve nada, porque un número parcial no es un documento.
     *
     * NORMALIZACIÓN SEGURA. Del correlativo se quitan los ceros a la izquierda —«0986» y
     * «986» son el mismo documento y el relleno lo pone el patrón—; del número de control
     * se quitan separadores y se compara en mayúsculas, reusando la misma normalización
     * con la que PPQ ya cruza sus items ({@see IdentidadPpq}). No se recorta ni se
     * completa nada más: cualquier otra «ayuda» convertiría dos documentos distintos en
     * uno solo.
     *
     * AMBIENTE. Los correlativos de pruebas y de producción cuentan por separado, así que
     * el mismo número puede existir en los dos. Gana SIEMPRE el fiscalmente vigente —el de
     * producción aceptado de verdad—, de modo que un documento simulado nunca puede
     * hacerse pasar por uno real. El de pruebas se sigue pudiendo encontrar cuando es el
     * único, y la pantalla lo muestra bloqueado.
     *
     * P001 / P002. Si el mismo correlativo existe en los dos puntos de venta, gana P002:
     * tras el cambio ambos comparten numeración y el vigente es el nuevo. Es la regla que
     * ya tenía la búsqueda y no cambia.
     */
    public function buscarExacto(string $numero, ?string $tipo = null): ?Dte
    {
        $numero = trim($numero);

        if ($numero === '') {
            return null;
        }

        $q = Dte::query()
            ->whereIn('tipo_dte', $tipo !== null && in_array($tipo, self::TIPOS, true) ? [$tipo] : self::TIPOS)
            ->noArchivados()
            ->with(['cliente:id,nombre,nombre_comercial', 'clienteSucursal:id,nombre,codigo'])
            // AMBIENTES SEPARADOS, con la misma regla que ya usa IdentidadPpq::dteLocal().
            //
            // No se filtra por el ambiente configurado, y a propósito: un documento de
            // pruebas o un borrador SÍ tiene que poder encontrarse —la pantalla lo muestra
            // bloqueado, porque esconderlo sería mentir sobre lo que existe—. Lo que no
            // puede pasar es que DESPLACE a uno real.
            //
            // Por eso el desempate es, en orden: primero la VIGENCIA FISCAL (producción +
            // aceptado de verdad ante Hacienda), que es lo que hace imposible que un
            // documento simulado le robe el lugar a uno real cuando comparten correlativo;
            // después el ambiente en el que se está trabajando; y a igualdad de todo, el
            // más reciente.
            ->orderByRaw(PpqElegibilidad::SQL_PRIORIDAD, PpqElegibilidad::bindingsPrioridad())
            ->orderByRaw('CASE WHEN ambiente = ? THEN 0 ELSE 1 END', [(string) config('dte.ambiente')])
            ->orderByDesc('id');

        // Solo dígitos (ya sin separadores) = correlativo suelto.
        $soloDigitos = preg_replace('/\D/', '', $numero);

        if ($soloDigitos !== '' && $soloDigitos === $numero) {
            $this->exactoPorCorrelativo($q, $soloDigitos);

            return $q->first();
        }

        // Cualquier otra cosa se trata como número de control completo, normalizado de
        // los dos lados para que dé igual cómo se hayan escrito los separadores.
        $clave = IdentidadPpq::normalizar($numero);

        if ($clave === null) {
            return null;
        }

        return $q->where(IdentidadPpq::columnaNormalizada(), $clave)->first();
    }

    /**
     * Acota la consulta al correlativo EXACTO, prefiriendo P002 cuando el mismo número
     * existe en los dos puntos de venta.
     */
    private function exactoPorCorrelativo(Builder $q, string $digitos): void
    {
        $hayEnP002 = (clone $q)
            ->reorder()
            ->where('numero_control', 'like', '%P002-%')
            ->where(fn (Builder $sub) => $this->correlativoExacto($sub, $digitos))
            ->exists();

        $q->where(function (Builder $sub) use ($digitos, $hayEnP002) {
            // El grupo va anidado: sin él, el `AND P002` se pegaría solo al último
            // patrón del OR en vez de a todos.
            $sub->where(fn (Builder $c) => $this->correlativoExacto($c, $digitos));

            if ($hayEnP002) {
                $sub->where('numero_control', 'like', '%P002-%');
            }
        });
    }

    /**
     * BÚSQUEDA AVANZADA: combina criterios y devuelve una página de resultados.
     *
     * @param  array<string, mixed>  $filtros  numero_control, codigo_generacion, oc,
     *                                         cliente, sala, albaran, fecha_desde,
     *                                         fecha_hasta, monto, tipo
     */
    public function buscar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        $q = Dte::query()
            ->whereIn('tipo_dte', self::TIPOS)
            // Los rechazados ARCHIVADOS están fuera de la operación: no aparecen en la
            // búsqueda rápida de cobro (se consultan por el filtro dedicado o Auditoría).
            ->noArchivados()
            ->with(['cliente:id,nombre,nombre_comercial', 'clienteSucursal:id,nombre,codigo'])
            // Los documentos ELEGIBLES para PPQ (producción + aceptados realmente por
            // Hacienda) van primero: son los que permiten no consultar Gmail y los únicos
            // que se pueden agregar a un lote. Es una PRIORIDAD, no un filtro: un borrador
            // o un documento de pruebas sigue apareciendo —más abajo y marcado como no
            // disponible—, porque esconderlo sería mentir sobre lo que existe.
            ->orderByRaw(PpqElegibilidad::SQL_PRIORIDAD, PpqElegibilidad::bindingsPrioridad())
            ->latest('fecha_emision');

        if (filled($filtros['oc'] ?? null)) {
            $oc = OrdenCompra::normalizar((string) $filtros['oc']);
            $q->where('numero_orden_compra', 'like', "%{$oc}%");
        }

        if (filled($filtros['albaran'] ?? null)) {
            $ocs = PpqAlbaran::where('numero_albaran', 'like', '%'.$filtros['albaran'].'%')
                ->pluck('numero_orden_compra')->filter()->all();
            $dteIds = PpqAlbaran::where('numero_albaran', 'like', '%'.$filtros['albaran'].'%')
                ->whereNotNull('dte_id')->pluck('dte_id')->all();
            $q->where(function (Builder $sub) use ($ocs, $dteIds) {
                if ($ocs !== []) {
                    $sub->whereIn('numero_orden_compra', $ocs);
                }
                if ($dteIds !== []) {
                    $sub->orWhereIn('id', $dteIds);
                }
                if ($ocs === [] && $dteIds === []) {
                    $sub->whereRaw('1 = 0'); // albarán sin coincidencia -> sin resultados
                }
            });
        }

        if (filled($filtros['sala'] ?? null)) {
            $sala = (string) $filtros['sala'];
            $q->whereHas('clienteSucursal', function (Builder $sub) use ($sala) {
                $sub->where('nombre', 'like', "%{$sala}%")->orWhere('codigo', $sala);
            });
        }

        if (filled($filtros['fecha_desde'] ?? null)) {
            $q->whereDate('fecha_emision', '>=', $filtros['fecha_desde']);
        }
        if (filled($filtros['fecha_hasta'] ?? null)) {
            $q->whereDate('fecha_emision', '<=', $filtros['fecha_hasta']);
        }

        if (filled($filtros['monto'] ?? null)) {
            $q->where('total_pagar', (float) $filtros['monto']);
        }

        if (in_array($filtros['tipo'] ?? null, self::TIPOS, true)) {
            $q->where('tipo_dte', $filtros['tipo']);
        }

        if (filled($filtros['cliente'] ?? null)) {
            $cliente = (string) $filtros['cliente'];
            $q->whereHas('cliente', function (Builder $sub) use ($cliente) {
                $sub->where('nombre', 'like', "%{$cliente}%")
                    ->orWhere('nombre_comercial', 'like', "%{$cliente}%")
                    ->orWhere('num_documento', 'like', "%{$cliente}%");
            });
        }

        // Código de generación: EXACTO. Es un UUID, y buscarlo por subcadena era una de
        // las tres fuentes de resultados ajenos (cuatro dígitos aciertan dentro de un
        // UUID por puro azar). Quien lo pega, lo pega entero.
        if (filled($filtros['codigo_generacion'] ?? null)) {
            $q->whereRaw('UPPER(codigo_generacion) = ?', [mb_strtoupper(trim((string) $filtros['codigo_generacion']))]);
        }

        // El número va AL FINAL para que la comprobación de «¿existe ya en P002?»
        // se haga sobre la misma búsqueda que el usuario está viendo —mismo tipo,
        // mismas fechas, sin archivados— y no sobre la tabla entera. Si se
        // resolviera antes, un documento P002 de otro tipo o ya archivado podría
        // esconder el P001 que sí correspondía mostrar.
        $this->aplicarNumeroControl($q, trim((string) ($filtros['numero_control'] ?? $filtros['q'] ?? '')));

        return $q->paginate($porPagina)->withQueryString();
    }

    /**
     * Número de control de la BÚSQUEDA AVANZADA: coincidencia exacta del correlativo si
     * viene suelto, o del número de control completo si viene entero.
     *
     * Sigue siendo exacto aunque esté en la avanzada. Lo que la avanzada permite es
     * COMBINAR criterios (número + sala + fecha), no aflojar la comparación: buscar
     * documentos «parecidos» a un número no le sirve a nadie, y era justo lo que devolvía
     * resultados ajenos.
     */
    private function aplicarNumeroControl(Builder $q, string $texto): void
    {
        if ($texto === '') {
            return;
        }

        if (ctype_digit($texto)) {
            $this->exactoPorCorrelativo($q, $texto);

            return;
        }

        $clave = IdentidadPpq::normalizar($texto);

        $clave === null
            ? $q->whereRaw('1 = 0')
            : $q->where(IdentidadPpq::columnaNormalizada(), $clave);
    }

    /**
     * El número de control TERMINA en ese correlativo, con su relleno de ceros
     * completo hasta el separador.
     *
     * Se prueba un patrón por ancho posible en vez de fijar 15 dígitos: el ancho
     * real varía entre documentos y darlo por sentado fue lo que dejó de
     * encontrar los de 16. Anclar en el guion es lo que da la exactitud —entre el
     * separador y el final solo puede haber ceros y el correlativo—, de modo que
     * `986` no casa con `...100986`.
     */
    private function correlativoExacto(Builder $q, string $digitos): void
    {
        // `0986` y `986` son el mismo correlativo: el relleno lo pone el patrón.
        $valor = ltrim($digitos, '0');

        if ($valor === '') {
            $valor = '0';
        }

        // `max()` evita que un término más largo que el ancho máximo genere un
        // rango descendente: en ese caso solo cabe su propio ancho.
        foreach (range(strlen($valor), max(strlen($valor), self::ANCHO_MAX_SECUENCIA)) as $i => $ancho) {
            $patron = '%-'.str_pad($valor, $ancho, '0', STR_PAD_LEFT);

            $i === 0
                ? $q->where('numero_control', 'like', $patron)
                : $q->orWhere('numero_control', 'like', $patron);
        }
    }

    /**
     * Qué documentos de la búsqueda YA están en algún lote PPQ, para avisar duplicados.
     *
     * Cruza por las dos llaves de {@see IdentidadPpq}: el vínculo explícito `dte_id` y
     * el número de control NORMALIZADO. La segunda es la que hace el trabajo: los 158
     * items que existen hoy vienen del barrido de Gmail y TODOS tienen `dte_id` en
     * NULL, así que cruzar solo por el vínculo no encontraba ninguno y un CCF ya
     * cobrado aparecía como si nunca hubiera entrado a un PPQ.
     *
     * Recibe los DTE enteros —no una lista de ids— porque hace falta su número de
     * control. Es UNA sola consulta para todos los resultados de la página.
     *
     * @param  iterable<int, Dte>  $dtes
     * @return array<int, int> dte_id => ppq_lote_id (un lote cualquiera donde ya está)
     */
    public function dtesYaUsados(iterable $dtes): array
    {
        $ids = [];
        $dtePorControl = [];

        foreach ($dtes as $dte) {
            $ids[] = (int) $dte->id;

            $clave = IdentidadPpq::normalizar($dte->numero_control);
            if ($clave !== null) {
                $dtePorControl[$clave] = (int) $dte->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $claves = array_keys($dtePorControl);

        $items = PpqItem::query()
            ->where(function (Builder $q) use ($ids, $claves) {
                $q->whereIn('dte_id', $ids);

                if ($claves !== []) {
                    $q->orWhereIn(IdentidadPpq::columnaNormalizada(), $claves);
                }
            })
            // El de id más bajo gana: cuando un documento está en varios lotes se avisa
            // del primero, que es lo que este aviso siempre significó («ya está en el
            // lote #N», no «está en estos N lotes»).
            ->orderBy('id')
            ->get(['id', 'ppq_lote_id', 'dte_id', 'numero_control']);

        $mapa = [];
        $conocidos = array_flip($ids);

        foreach ($items as $item) {
            // Llave 1: vínculo explícito.
            if ($item->dte_id !== null && isset($conocidos[$item->dte_id])) {
                $mapa[$item->dte_id] ??= (int) $item->ppq_lote_id;
            }

            // Llave 2: número de control normalizado (los snapshots de Gmail).
            $clave = IdentidadPpq::normalizar($item->numero_control);
            if ($clave !== null && isset($dtePorControl[$clave])) {
                $mapa[$dtePorControl[$clave]] ??= (int) $item->ppq_lote_id;
            }
        }

        return $mapa;
    }
}
