<?php

namespace App\Http\Controllers\Ppq;

use App\Enums\EstadoPpq;
use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\PpqBusquedaService;
use App\Services\Ppq\PpqGmailService;
use App\Services\Ppq\SalaResolver;
use App\Services\Rutas\AlbaranLocalizador;
use App\Support\PpqElegibilidad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Búsqueda de CCF/NC para PPQ. Solo consulta; desde aquí se agregan a un lote.
 *
 * ─────────────────────────── DOS buscadores ───────────────────────────
 *
 *   · PRINCIPAL (`q`) — un número, un documento. Es el trabajo diario: se teclea el
 *     número del CCF y sale ESE CCF, o «no encontrado». Nunca devuelve parecidos.
 *   · AVANZADA (`numero_control`, `codigo_generacion`, `oc`, `cliente`, `sala`,
 *     `fecha_*`, `monto`) — plegada, para cuando no se tiene el número a mano. Ahí sí
 *     tiene sentido devolver varios, paginados y por fecha reciente.
 *
 * ORDEN DE LAS FUENTES: la base local manda. Gmail solo se consulta cuando la base no
 * tiene el documento —el caso real son los históricos de Conta/P001 que este sistema
 * nunca emitió— y NUNCA cuando ya hubo coincidencia exacta local.
 */
class PpqBusquedaController extends Controller
{
    public function index(Request $request, PpqBusquedaService $busqueda, PpqGmailService $gmail, SalaResolver $salaResolver): View
    {
        $filtros = $request->only([
            'q', 'oc', 'albaran', 'sala', 'cliente', 'numero_control', 'codigo_generacion',
            'fecha_desde', 'fecha_hasta', 'monto', 'tipo',
        ]);
        $q = trim((string) ($filtros['q'] ?? ''));

        // Búsqueda POR TIPO de documento (no se mezclan): por defecto CCF (03). El usuario
        // primero agrega todos los CCF y luego cambia a Nota de crédito (05) para agregar las NC.
        $tipo = in_array($filtros['tipo'] ?? null, ['03', '05'], true) ? $filtros['tipo'] : '03';
        $filtros['tipo'] = $tipo;
        // Los criterios de la AVANZADA, sin contar el buscador exacto.
        $filtrosAvanzados = collect($filtros)->except(['tipo', 'q'])->filter(fn ($v) => filled($v));
        $hayAvanzados = $filtrosAvanzados->isNotEmpty();

        // --------------------------- BASE LOCAL PRIMERO ---------------------------
        //
        // La búsqueda arranca SIEMPRE en la base local, esté Gmail conectado o no.
        // Antes era al revés (Gmail principal, local solo como respaldo), y eso hacía que
        // buscar un CCF emitido por este mismo sistema —cuyo sello, cliente, sala y albarán
        // ya están guardados— saliera igual a la red a bajar y parsear el correo que lo
        // llevaba adjunto. La base ya sabía la respuesta.
        // BUSCADOR PRINCIPAL: un número, un documento. Devuelve el DTE o null; nunca una
        // lista de parecidos. Es lo que se usa a diario.
        $exacto = $q !== '' ? $busqueda->buscarExacto($q, $tipo) : null;

        // BUSCADOR AVANZADO: solo cuando se pidieron criterios avanzados. Los dos no se
        // mezclan en la misma lista: si hay número exacto manda el número, y si no lo hay
        // el usuario está usando la avanzada a propósito.
        $resultados = $hayAvanzados ? $busqueda->buscar($filtros) : null;

        // Un documento local de PRODUCCIÓN, ACEPTADO REALMENTE por Hacienda y no
        // archivado resuelve la ficha entera: cierra la búsqueda y Gmail no se consulta.
        // Lo que NO la cierra: no encontrar nada, o encontrar solo borradores, documentos
        // de pruebas o sellos MOCK —ahí puede seguir habiendo un histórico de Conta/P001
        // en el correo, que es justo para lo que Gmail queda.
        //
        // Con coincidencia EXACTA local no se consulta el correo, sin importar nada más:
        // ya sabemos cuál es el documento.
        $resueltoLocalmente = ($exacto !== null && PpqBusquedaService::resuelveSinGmail($exacto))
            || ($resultados !== null && $busqueda->hayResolutivoLocal($resultados));

        // ------------------- GMAIL: SOLO COMO FALLBACK -------------------
        $gmailDisponible = $gmail->disponible();
        $gmailConsultado = false;
        $gmailError = null;
        $resolucion = null;
        // Gmail solo entra si la base local NO resolvió. «Resolver» es más que encontrar
        // algo con ese número: un borrador, un documento de pruebas o un sello MOCK
        // coinciden por número pero NO son el documento que se busca, y el real puede
        // seguir estando solo en el correo como histórico de Conta/P001. Cerrar la
        // búsqueda con uno de esos escondería el documento verdadero.
        //
        // Con una coincidencia exacta ELEGIBLE, en cambio, la red no se toca.
        if ($gmailDisponible && $q !== '' && ! $resueltoLocalmente) {
            $gmailConsultado = true;
            try {
                $resolucion = $gmail->resolverCcf($q);
            } catch (GmailDesconectadoException $e) {
                // Token revocado a mitad de la búsqueda: no rompe nada. Lo local ya se
                // resolvió arriba y se muestra igual.
                $gmailDisponible = false;
                $gmailError = $e->getMessage();
            }
        }
        $fichasGmail = $resolucion['fichas'] ?? null;
        $gmailDebug = $resolucion['debug'] ?? null;

        // Documentos locales que NO se dibujan porque la ficha de Gmail los cubre mejor
        // (ver completarFichasGmail). Vacío siempre que no se haya consultado Gmail.
        // Para decidir qué locales tapa Gmail hay que mirar TODOS los que se van a
        // dibujar, incluido el resultado exacto: si no, el mismo documento saldría dos
        // veces —la ficha local y la del correo—, que es justo el duplicado que este
        // mecanismo existe para evitar.
        $localesDibujados = collect($resultados !== null ? $resultados->items() : []);
        if ($exacto !== null) {
            $localesDibujados->push($exacto);
        }

        $localesOcultos = [];
        if (is_array($fichasGmail)) {
            $fichasGmail = $this->completarFichasGmail($fichasGmail, $tipo, $localesDibujados, $salaResolver);
            $localesOcultos = $this->localesCubiertosPorGmail($fichasGmail, $localesDibujados);
        }

        // Para avisar duplicados: qué DTE de los resultados ya está en algún lote. Se
        // pasan los documentos enteros porque el cruce necesita su número de control:
        // los items históricos vienen de Gmail y no tienen `dte_id`.
        // Para el aviso de duplicado: en qué lote está ya cada documento mostrado. Cubre
        // tanto el resultado exacto como los de la avanzada.
        // Se cruza sobre la MISMA colección que se va a dibujar. (`items()` y no
        // `collect($resultados)`: sobre un paginador, collect() llama a toArray() y
        // devuelve la estructura JSON del paginador, no los documentos.)
        $yaUsados = $localesDibujados->isEmpty() ? [] : $busqueda->dtesYaUsados($localesDibujados);

        // El lote donde YA está el documento exacto, si está en alguno. La vista lo usa
        // para mostrar «Ya está en PPQ — lote X» con su enlace y bloquear que se agregue
        // otra vez, en vez de ofrecer alternativas que nadie pidió.
        $loteDelExacto = $exacto !== null && isset($yaUsados[$exacto->id])
            ? PpqLote::find($yaUsados[$exacto->id])
            : null;

        // Albaranes vinculados a los resultados (por dte_id directo o por OC).
        [$albaranesPorDte, $albaranesPorOc] = $this->albaranesDe($resultados);
        if ($exacto !== null) {
            [$albExacto, $albExactoOc] = $this->albaranesDe(collect([$exacto]));
            $albaranesPorDte += $albExacto;
            $albaranesPorOc += $albExactoOc;
        }

        // Lotes editables a los que se puede agregar (borrador/listo).
        $lotesAbiertos = $this->lotesAbiertos();

        // Lote ACTIVO: al llegar desde un lote (?lote=ID) se agrega DIRECTO a él (sin elegir
        // de la lista). Debe existir y ser editable; si no, se ignora (cae al flujo normal).
        $loteActivo = null;
        if ($request->filled('lote')) {
            $candidato = PpqLote::find($request->integer('lote'));
            $loteActivo = ($candidato && $candidato->esEditable()) ? $candidato : null;
        }

        return view('ppq.busqueda', [
            'filtros' => $filtros,
            'tipo' => $tipo,
            'exacto' => $exacto,
            'loteDelExacto' => $loteDelExacto,
            'buscoExacto' => $q !== '',
            'resultados' => $resultados,
            'fichasGmail' => $fichasGmail,
            'gmailDebug' => $gmailDebug,
            'gmailDisponible' => $gmailDisponible,
            'gmailConsultado' => $gmailConsultado,
            'resueltoLocalmente' => $resueltoLocalmente,
            'localesOcultos' => $localesOcultos,
            'gmailError' => $gmailError,
            'gmailConfigurado' => app(GmailClient::class)->configurado(),
            'yaUsados' => $yaUsados,
            'albaranesPorDte' => $albaranesPorDte,
            'albaranesPorOc' => $albaranesPorOc,
            'hayAvanzados' => $hayAvanzados,
            'lotesAbiertos' => $lotesAbiertos,
            'loteActivo' => $loteActivo,
        ]);
    }

    /**
     * Deja listas las fichas que vinieron de Gmail: filtra por tipo, quita las que YA
     * están resueltas localmente y completa sala, sello y CCF relacionado.
     *
     * Todo lo que necesita la base se pide EN BLOQUE (dos consultas fijas para el lote
     * entero de fichas, no una por ficha). Antes cada ficha disparaba su propia consulta
     * de sello y, si era NC, otra de CCF relacionado.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @param  LengthAwarePaginator|null  $resultados
     * @return array<int, array<string, mixed>>
     */
    private function completarFichasGmail(array $fichas, string $tipo, $resultados, SalaResolver $salaResolver): array
    {
        // Solo el tipo elegido: aunque un CCF y una NC compartan correlativo, no se mezclan.
        $fichas = array_values(array_filter(
            $fichas,
            fn ($f) => (($f['ccf']['tipoDte'] ?? '03') === $tipo),
        ));

        // DESEMPATE ENTRE FUENTES, cuando el mismo documento sale de las dos.
        //
        // Gana el local SI ES ELEGIBLE: trae los datos autoritativos (cliente, sucursal,
        // sello confirmado) y el vínculo por `dte_id`, así que la copia de Gmail sobra.
        //
        // Pero si el local NO es elegible, gana Gmail, y esa asimetría es el punto. El
        // caso real son los P001 que emitió ContaPortable: este sistema los tiene
        // registrados en estado «generado» —nunca los transmitió, por eso no tienen
        // sello— mientras que el correo de Conta SÍ trae el sello real. Ahí el registro
        // local es un espejo incompleto y el correo es la evidencia buena. Descartar la
        // ficha de Gmail dejaría el documento INALCANZABLE: la única tarjeta visible
        // sería la local, marcada «No disponible para PPQ» y sin botones. La tarjeta
        // local que sobra se oculta después, en localesCubiertosPorGmail().
        //
        // La rama de «local elegible» es hoy DEFENSIVA: Gmail solo se consulta cuando
        // ningún resultado de la página era elegible, así que en la práctica no se
        // alcanza. Se deja escrita igual para que la regla completa viva en el código y
        // siga valiendo si algún día cambia cuándo se consulta Gmail.
        $yaLocales = $this->clavesLocales($resultados);
        if ($yaLocales !== []) {
            $fichas = array_values(array_filter($fichas, function (array $f) use ($yaLocales) {
                foreach ([$f['ccf']['codigoGeneracion'] ?? null, $f['ccf']['numeroControl'] ?? null] as $clave) {
                    if (filled($clave) && isset($yaLocales[strtoupper(trim((string) $clave))])) {
                        return false;
                    }
                }

                return true;
            }));
        }

        if ($fichas === []) {
            return $fichas;
        }

        $sellos = $this->sellosLocales($fichas);
        $relacionados = $this->ccfRelacionadosPorOc($fichas);

        foreach ($fichas as $i => $f) {
            // Para las NC: sugerir el CCF original que comparte la misma orden de compra.
            if (($f['ccf']['tipoDte'] ?? null) === '05') {
                $oc = trim((string) ($f['ccf']['ordenCompra'] ?? ''));
                $fichas[$i]['ccfRelacionado'] = $oc !== '' ? ($relacionados[$oc] ?? null) : null;
            }

            // Nombre comercial de la sala: viene en el propio DTE (receptor.nombreComercial).
            // Si por alguna razón no estuviera, se cae al DTE local por código/control/OC.
            if (blank($f['ccf']['salaNombre'] ?? null)) {
                $fichas[$i]['ccf']['salaNombre'] = $salaResolver->nombre(
                    $f['ccf']['ordenCompra'] ?? null,
                    $f['ccf']['codigoGeneracion'] ?? null,
                    $f['ccf']['numeroControl'] ?? null,
                );
            }

            // El JSON enviado por correo puede no incluir el sello devuelto posteriormente
            // por Hacienda. Si falta, se completa desde el DTE local aceptado de
            // producción, sin modificar el documento.
            if (filled($f['ccf']['sello'] ?? null)) {
                continue;
            }

            foreach ([$f['ccf']['codigoGeneracion'] ?? null, $f['ccf']['numeroControl'] ?? null] as $clave) {
                $clave = strtoupper(trim((string) $clave));
                if ($clave === '' || ! isset($sellos[$clave])) {
                    continue;
                }

                $fichas[$i]['ccf']['sello'] = $sellos[$clave]['sello_recepcion'];
                $fichas[$i]['dte_id'] = $sellos[$clave]['id'];
                break;
            }
        }

        return $fichas;
    }

    /**
     * Identificadores (código de generación y número de control) de los documentos
     * locales ELEGIBLES que la búsqueda ya devolvió, en mayúsculas, para descartar sus
     * copias de Gmail.
     *
     * Solo los elegibles: un local que no se puede cobrar no tiene autoridad para tapar
     * la evidencia del correo. No consulta nada; usa lo que ya está en memoria.
     *
     * @param  LengthAwarePaginator|null  $resultados
     * @return array<string, true>
     */
    private function clavesLocales($resultados): array
    {
        if (! $resultados) {
            return [];
        }

        $claves = [];
        foreach ($resultados as $dte) {
            if (! PpqElegibilidad::esElegible($dte)) {
                continue;
            }

            foreach ([$dte->codigo_generacion, $dte->numero_control] as $clave) {
                if (filled($clave)) {
                    $claves[strtoupper(trim((string) $clave))] = true;
                }
            }
        }

        return $claves;
    }

    /**
     * IDs de los documentos locales cuya ficha NO hay que dibujar porque una ficha de
     * Gmail ya representa el mismo documento, y mejor.
     *
     * Es la otra mitad del desempate: acá solo pueden caer locales NO elegibles, porque
     * los elegibles ya eliminaron su copia de Gmail antes (ver clavesLocales()). Sin
     * esto se verían dos tarjetas del mismo documento, la local bloqueada y la de Gmail
     * agregable, que es justo el duplicado que no queremos.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @param  LengthAwarePaginator|null  $resultados
     * @return array<int, int>
     */
    private function localesCubiertosPorGmail(array $fichas, $resultados): array
    {
        if ($fichas === [] || ! $resultados) {
            return [];
        }

        $deGmail = [];
        foreach ($fichas as $f) {
            foreach ([$f['ccf']['codigoGeneracion'] ?? null, $f['ccf']['numeroControl'] ?? null] as $clave) {
                if (filled($clave)) {
                    $deGmail[strtoupper(trim((string) $clave))] = true;
                }
            }
        }

        $ocultos = [];
        foreach ($resultados as $dte) {
            foreach ([$dte->codigo_generacion, $dte->numero_control] as $clave) {
                if (filled($clave) && isset($deGmail[strtoupper(trim((string) $clave))])) {
                    $ocultos[] = (int) $dte->id;
                    break;
                }
            }
        }

        return $ocultos;
    }

    /**
     * Sellos de recepción locales para las fichas de Gmail, EN UNA SOLA consulta,
     * indexados por código de generación y por número de control (ambos en mayúsculas).
     *
     * Solo cuentan los documentos de producción aceptados REALMENTE por Hacienda: un
     * sello es la prueba de la aceptación y no se toma de un borrador ni de un MOCK.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @return array<string, array{id: int, sello_recepcion: string}>
     */
    private function sellosLocales(array $fichas): array
    {
        $codigos = [];
        $controles = [];
        foreach ($fichas as $f) {
            if (filled($f['ccf']['sello'] ?? null)) {
                continue; // el correo ya traía el sello: no hace falta buscarlo
            }
            if (filled($f['ccf']['codigoGeneracion'] ?? null)) {
                $codigos[] = trim((string) $f['ccf']['codigoGeneracion']);
            }
            if (filled($f['ccf']['numeroControl'] ?? null)) {
                $controles[] = trim((string) $f['ccf']['numeroControl']);
            }
        }

        if ($codigos === [] && $controles === []) {
            return [];
        }

        $dtes = Dte::query()
            ->aceptadoRealMh()
            ->produccion()
            ->where(function ($query) use ($codigos, $controles) {
                if ($codigos !== []) {
                    $query->whereIn('codigo_generacion', $codigos);
                }
                if ($controles !== []) {
                    $query->orWhereIn('numero_control', $controles);
                }
            })
            ->orderBy('id') // el de id más alto gana: se escribe de último
            ->get(['id', 'codigo_generacion', 'numero_control', 'sello_recepcion']);

        $mapa = [];
        foreach ($dtes as $dte) {
            foreach ([$dte->codigo_generacion, $dte->numero_control] as $clave) {
                if (filled($clave)) {
                    $mapa[strtoupper(trim((string) $clave))] = [
                        'id' => (int) $dte->id,
                        'sello_recepcion' => (string) $dte->sello_recepcion,
                    ];
                }
            }
        }

        return $mapa;
    }

    /**
     * Número de control del CCF (tipo 03) que comparte la orden de compra de cada NC,
     * para mostrar la relación sugerida. UNA consulta para todas las fichas.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @return array<string, string> orden de compra => número de control del CCF
     */
    private function ccfRelacionadosPorOc(array $fichas): array
    {
        $ocs = [];
        foreach ($fichas as $f) {
            if (($f['ccf']['tipoDte'] ?? null) !== '05') {
                continue;
            }
            $oc = trim((string) ($f['ccf']['ordenCompra'] ?? ''));
            if ($oc !== '') {
                $ocs[] = $oc;
            }
        }

        if ($ocs === []) {
            return [];
        }

        return Dte::where('tipo_dte', '03')
            ->whereIn('numero_orden_compra', array_values(array_unique($ocs)))
            ->orderBy('id') // el de id más alto gana: pisa a los anteriores
            ->pluck('numero_control', 'numero_orden_compra')
            ->filter()
            ->all();
    }

    /**
     * Búsqueda MANUAL de albarán por fecha (cuando no se encontró por OC): lista los
     * albaranes del label Calleja_Albaranes recibidos ese día para que el usuario
     * elija el correcto y lo vincule al documento. Conserva el contexto del CCF/NC.
     */
    public function albaranesPorFecha(Request $request, PpqGmailService $gmail, SalaResolver $salaResolver): View
    {
        // Contexto del documento que se está conciliando (se reenvía a "agregar").
        $doc = $request->only([
            'origen', 'dte_id', 'numero_control', 'codigo_generacion', 'sello_recepcion',
            'tipo_dte', 'fecha_documento', 'numero_orden_compra', 'monto_dte', 'gmail_message_id', 'q',
        ]);

        $fecha = $request->input('fecha') ?: ($doc['fecha_documento'] ?? null);
        $fechaValida = $fecha ? rescue(fn () => Carbon::parse($fecha)->toDateString(), null, false) : null;

        // Nombre comercial del documento (para snapshotearlo al vincular): DTE local o mapa.
        $docSalaNombre = $salaResolver->nombre(
            $doc['numero_orden_compra'] ?? null,
            $doc['codigo_generacion'] ?? null,
            $doc['numero_control'] ?? null,
        );

        $gmailDisponible = $gmail->disponible();
        $gmailError = null;
        $candidatos = null;
        if ($gmailDisponible && $fechaValida) {
            try {
                $candidatos = $gmail->albaranesDeFecha($fechaValida);
            } catch (GmailDesconectadoException $e) {
                $gmailDisponible = false;
                $gmailError = $e->getMessage();
            }
        }

        return view('ppq.albaran-por-fecha', [
            'doc' => $doc,
            'docSalaNombre' => $docSalaNombre,
            'fecha' => $fechaValida,
            'candidatos' => $candidatos,
            'gmailDisponible' => $gmailDisponible,
            'gmailError' => $gmailError,
            'lotesAbiertos' => $this->lotesAbiertos(),
        ]);
    }

    /** Lotes editables (borrador/listo) a los que se puede agregar documentos. */
    private function lotesAbiertos()
    {
        return PpqLote::whereIn('estado', [EstadoPpq::Borrador->value, EstadoPpq::Listo->value])
            ->latest()
            ->get(['id', 'referencia', 'fecha', 'estado']);
    }

    /**
     * Albaranes de los resultados, por `dte_id` y por orden de compra.
     *
     * La regla de emparejamiento vive ahora en {@see AlbaranLocalizador},
     * que es la MISMA que usa el seguimiento documental de Rutas / Cobros. Se movió
     * para que exista un solo lugar donde decidir qué albarán le toca a un documento:
     * dos copias de esta regla acabarían respondiendo distinto en dos pantallas.
     * El comportamiento de esta búsqueda no cambia.
     *
     * @return array{0: array<int, PpqAlbaran>, 1: array<string, PpqAlbaran>}
     */
    private function albaranesDe($resultados): array
    {
        if (! $resultados || $resultados->isEmpty()) {
            return [[], []];
        }

        return app(AlbaranLocalizador::class)->indices(
            $resultados->pluck('id')->all(),
            $resultados->pluck('numero_orden_compra')->filter()->all(),
        );
    }
}
