<?php

namespace App\Services\Ppq;

use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Models\PpqAlbaran;
use App\Models\PpqSala;
use App\Services\Rutas\AlbaranLocalizador;
use App\Support\Albaran;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Orquesta el flujo de PPQ sobre Gmail, que desde la Fase 1 es la fuente de
 * FALLBACK —no la principal—: acá se llega cuando la base local no tiene un
 * documento que resuelva la búsqueda (típicamente un histórico de Conta/P001).
 *
 *  1. Buscar el CCF/NC en correos ENVIADOS por su número (últimos 4 / control).
 *  2. Extraer del JSON adjunto: control, código, sello, OC, monto, fecha, sala.
 *  3. Filtrar las menciones y deduplicar los reenvíos.
 *  4. Recién entonces, resolver el albarán: PRIMERO en `ppq_albaranes` y solo si
 *     ahí no está, en el label Calleja_Albaranes.
 *  5. Devolver la ficha lista (CCF + albarán + diferencia) para "Agregar a PPQ".
 *
 * El orden de los pasos 3 y 4 no es cosmético. Antes el albarán se buscaba dentro
 * del bucle de correos, así que un CCF reenviado cuatro veces disparaba cuatro
 * búsquedas en Gmail y se tiraban tres; y los correos que solo MENCIONABAN el
 * número —un Excel de cobro— también pagaban su búsqueda antes de ser descartados.
 * Resolviendo después del filtro se busca el albarán una vez por documento real.
 *
 * No escribe DTE ni items; solo lee y arma el resultado. La persistencia (lote/item)
 * la hace el flujo de PPQ cuando el usuario confirma.
 */
class PpqGmailService
{
    /** Fuentes posibles del albarán de una ficha (se muestran en la interfaz). */
    public const ALBARAN_LOCAL = 'local';

    public const ALBARAN_GMAIL = 'gmail';

    /**
     * El localizador NO es un parámetro obligatorio a propósito: varios tests de PPQ
     * construyen este servicio a mano con sus cuatro dobles, y esa es justo la lógica
     * que esta fase no debe reescribir. Se puede inyectar cuando interesa y, si no, se
     * resuelve del contenedor la primera vez que hace falta.
     */
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly DteCorreoParser $parser,
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly AlbaranParser $albaranParser,
        private ?AlbaranLocalizador $albaranesLocales = null,
    ) {}

    private function albaranesLocales(): AlbaranLocalizador
    {
        return $this->albaranesLocales ??= app(AlbaranLocalizador::class);
    }

    public function disponible(): bool
    {
        return $this->gmail->disponible();
    }

    /**
     * Resuelve uno o varios CCF/NC desde Gmail a partir del número buscado.
     * Devuelve también una traza de debug: NO descarta correos en silencio, así que
     * si el correo se encuentra pero el JSON no se puede parsear, queda registrado.
     *
     * @return array{fichas: array<int, array<string, mixed>>, debug: array<string, mixed>}
     */
    public function resolverCcf(string $numero): array
    {
        $busqueda = $this->gmail->buscarEnviadosDetallado($numero);
        $debug = [
            'numero' => $numero,
            'variante_usada' => $busqueda['variante'],
            'query' => $busqueda['query'],
            'intentos' => $busqueda['intentos'],
            'correos' => count($busqueda['resultados']),
            'detalle' => [],
        ];

        $fichas = [];
        foreach ($busqueda['resultados'] as $correo) {
            $det = [
                'id' => $correo['id'],
                'asunto' => $correo['asunto'] ?? null,
                'fecha' => $correo['fecha'] ?? null,
                'adjuntos' => [],
                'json_detectado' => false,
                'numero_control' => null,
                'error' => null,
            ];

            try {
                $adjuntos = $this->gmail->adjuntos($correo['id']);
                $det['adjuntos'] = array_map(fn ($a) => ['filename' => $a['filename'], 'mime' => $a['mime']], $adjuntos);

                $dteJson = null;
                foreach ($adjuntos as $a) {
                    $esJson = str_contains(strtolower($a['mime']), 'json') || str_ends_with(strtolower($a['filename']), '.json');
                    if (! $esJson) {
                        continue;
                    }
                    $det['json_detectado'] = true;

                    // Guardar copia temporal del JSON crudo para inspección.
                    $det['archivo'] = $this->guardarCopia($correo['id'], $a['filename'], $a['data']);

                    $dec = $this->decoder->decodificar($a['data'], $a['mime'], $a['filename']);
                    $det['json_info'] = $dec['info'];
                    $det['encoding_usado'] = $dec['encoding_usado'];
                    $det['encoding_intentos'] = $dec['intentos'];
                    if ($dec['ok']) {
                        $dteJson = $dec['data'];
                        break;
                    }
                    $det['error'] = $dec['error'];
                }

                if ($dteJson === null) {
                    $det['error'] ??= 'No se encontró un adjunto JSON legible en el correo.';
                    $debug['detalle'][] = $det;

                    continue;
                }

                $ccf = $this->parser->desdeJson($dteJson);
                $det['numero_control'] = $ccf['numeroControl'];
                if (blank($ccf['numeroControl'])) {
                    $det['error'] = 'El parser no extrajo numeroControl del JSON (estructura inesperada).';
                    $debug['detalle'][] = $det;

                    continue;
                }

                // El albarán y el mapa de salas se resuelven DESPUÉS del filtro/dedup:
                // acá todavía no se sabe si este correo es el documento buscado.
                $fichas[] = [
                    'origen' => 'gmail',
                    'gmail_message_id' => $correo['id'],
                    'ccf' => $ccf,
                    'albaran' => null,
                    'albaran_fuente' => null,
                    'diferencia' => null,
                ];
            } catch (GmailDesconectadoException $e) {
                // Gmail se desconectó a mitad de la búsqueda: no lo tratamos como un
                // error puntual de ESTE correo, sino que dejamos que suba para que el
                // llamador degrade a la búsqueda local (banner "Gmail desconectado").
                throw $e;
            } catch (\Throwable $e) {
                $det['error'] = 'Excepción al procesar el correo: '.$e->getMessage();
            }

            $debug['detalle'][] = $det;
        }

        // Un correo puede MENCIONAR el número buscado (asunto/cuerpo/PDF indexado) sin ser
        // ese DTE, y un mismo DTE reenviado llega como varios correos: nos quedamos solo con
        // los DTE cuyo control REAL termina en lo buscado y contamos cada DTE una sola vez.
        $fichas = $this->filtrarPorNumeroYDeduplicar($fichas, $numero);
        $fichas = $this->completarAlbaranes($fichas);
        $this->recordarSalas($fichas);
        $debug['fichas'] = count($fichas);
        $debug['albaranes'] = [
            'locales' => count(array_filter($fichas, fn ($f) => ($f['albaran_fuente'] ?? null) === self::ALBARAN_LOCAL)),
            'gmail' => count(array_filter($fichas, fn ($f) => ($f['albaran_fuente'] ?? null) === self::ALBARAN_GMAIL)),
        ];

        return ['fichas' => $fichas, 'debug' => $debug];
    }

    /**
     * Deja solo las fichas que REALMENTE corresponden al número buscado y elimina los
     * duplicados (reenvíos del mismo DTE):
     *  - Filtro: el número de control real del JSON debe TERMINAR en los dígitos buscados
     *    (descarta correos que solo mencionan el número, como un Excel de cobro o un QUEDAN).
     *  - Dedup: por código de generación (único por DTE); si falta, por el número de control.
     *    Así un CCF enviado/reenviado 4 veces cuenta como UNA sola ficha.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @return array<int, array<string, mixed>>
     */
    private function filtrarPorNumeroYDeduplicar(array $fichas, string $numero): array
    {
        $buscado = preg_replace('/\D/', '', $numero);
        $salidaP002 = [];
        $salidaP001 = [];
        $vistos = [];

        $secuencia = $buscado !== ''
            ? str_pad((string) ((int) $buscado), 15, '0', STR_PAD_LEFT)
            : null;

        foreach ($fichas as $ficha) {
            $control = strtoupper((string) ($ficha['ccf']['numeroControl'] ?? ''));
            $controlDigitos = preg_replace('/\D/', '', $control);

            if ($secuencia !== null && ! str_ends_with($control, '-'.$secuencia)) {
                continue;
            }

            $clave = ((string) ($ficha['ccf']['codigoGeneracion'] ?? '')) ?: $control;
            if ($clave !== '' && isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;

            if (str_contains($control, 'M001P002-')) {
                $salidaP002[] = $ficha;
            } elseif (str_contains($control, 'M001P001-')) {
                $salidaP001[] = $ficha;
            }
        }

        // El sistema nuevo P002 tiene prioridad. Solo si no existe se devuelve P001.
        return array_values($salidaP002 !== [] ? $salidaP002 : $salidaP001);
    }

    /**
     * Resuelve el albarán de cada ficha YA filtrada: `ppq_albaranes` PRIMERO y Gmail
     * solo para las que ahí no están.
     *
     * La consulta local es UNA sola para todas las fichas (el localizador arma los dos
     * índices de una pasada), así que agregar fichas no agrega consultas. Se delega en
     * {@see AlbaranLocalizador} a propósito: es la misma regla —`dte_id` primero, orden
     * de compra después— que ya usan la búsqueda local de PPQ y el seguimiento de Rutas.
     * Acá solo puede aplicar la segunda llave: un documento que viene de Gmail no tiene
     * `dte_id` local que ofrecer.
     *
     * Las NC (tipo 05) quedan fuera, como siempre: no traen albarán por correo y
     * comparten OC con el CCF original, así que el suyo se captura a mano.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     * @return array<int, array<string, mixed>>
     */
    private function completarAlbaranes(array $fichas): array
    {
        $ocs = [];
        foreach ($fichas as $ficha) {
            $oc = $this->ocConAlbaran($ficha);
            if ($oc !== null) {
                $ocs[] = $oc;
            }
        }

        [, $porOrden] = $ocs === [] ? [[], []] : $this->albaranesLocales()->indices([], $ocs);

        foreach ($fichas as $i => $ficha) {
            $oc = $this->ocConAlbaran($ficha);
            if ($oc === null) {
                continue;
            }

            // `porOrden` guarda una resolución, no un albarán suelto: para una misma OC
            // puede haber varios albaranes y solo cuenta el de ENTREGA cuando es único.
            // Si la resolución no es inequívoca se cae a la búsqueda por correo, que es
            // lo mismo que hacía antes cuando no encontraba nada localmente.
            $local = ($porOrden[$oc] ?? null)?->albaran;

            // Ya sincronizado: no se vuelve a bajar ni a parsear el correo del albarán.
            $albaran = $local !== null
                ? $this->desdeAlbaranLocal($local, $oc)
                : $this->buscarAlbaranPorOc(
                    $oc,
                    $ficha['ccf']['fecha'] ?? null,
                    isset($ficha['ccf']['monto']) ? (float) $ficha['ccf']['monto'] : null,
                );

            $montoCcf = $ficha['ccf']['monto'] ?? null;

            $fichas[$i]['albaran'] = $albaran;
            $fichas[$i]['albaran_fuente'] = $albaran['fuente'] ?? null;
            $fichas[$i]['diferencia'] = ($albaran !== null && $albaran['monto'] !== null && $montoCcf !== null)
                ? round((float) $montoCcf - (float) $albaran['monto'], 2)
                : null;
        }

        return $fichas;
    }

    /**
     * Orden de compra de una ficha que ADMITE búsqueda automática de albarán, o null
     * si es una NC o no trae OC.
     *
     * @param  array<string, mixed>  $ficha
     */
    private function ocConAlbaran(array $ficha): ?string
    {
        if (($ficha['ccf']['tipoDte'] ?? null) === '05') {
            return null;
        }

        $oc = trim((string) ($ficha['ccf']['ordenCompra'] ?? ''));

        return $oc !== '' ? $oc : null;
    }

    /**
     * Un albarán de `ppq_albaranes` en el MISMO formato que devuelve la búsqueda por
     * Gmail, para que la vista y la conciliación no tengan que distinguir de dónde
     * salió. Lo único que cambia es `fuente`, que es lo que se muestra al usuario.
     *
     * @return array<string, mixed>
     */
    private function desdeAlbaranLocal(PpqAlbaran $albaran, string $oc): array
    {
        $sala = $albaran->sala_codigo ?: OrdenCompra::salaDesde($oc);

        return [
            'ppq_albaran_id' => $albaran->id,
            'gmail_message_id' => $albaran->gmail_message_id,
            'numero_albaran' => $albaran->numero_albaran,
            'orden_compra' => $albaran->numero_orden_compra ?: $oc,
            'sala' => $sala,
            'nombre_sala' => Sala::nombre($sala),
            'monto' => $albaran->monto_albaran !== null ? (float) $albaran->monto_albaran : null,
            'fecha' => optional($albaran->fecha_albaran)->toDateString(),
            'fuente' => self::ALBARAN_LOCAL,
            'debug' => ['fuente' => 'ppq_albaranes', 'albaran_id' => $albaran->id, 'origen' => $albaran->origen],
        ];
    }

    /**
     * Enriquece el mapa auxiliar de PPQ (`ppq_salas`, NO fiscal) con el nombre comercial
     * del receptor. Solo con las fichas que sobrevivieron al filtro: antes se escribía
     * también la sala de los correos que apenas mencionaban el número.
     *
     * @param  array<int, array<string, mixed>>  $fichas
     */
    private function recordarSalas(array $fichas): void
    {
        foreach ($fichas as $ficha) {
            PpqSala::recordar(
                OrdenCompra::salaDesde($ficha['ccf']['ordenCompra'] ?? null),
                $ficha['ccf']['salaNombre'] ?? null,
                'ccf_json',
            );
        }
    }

    /**
     * Busca el albarán correspondiente a una OC en el label Calleja_Albaranes.
     *
     * Estrategia:
     *  1. Búsqueda directa por OC (Gmail indexa el texto del PDF).
     *  2. Variantes de OC (con/sin ceros) por si el índice no casa el número exacto.
     *  3. FALLBACK por fecha+sala+monto: si hay fecha del CCF, lista los albaranes de ese
     *     día y elige el que calza en SALA (código de la OC) y MONTO (si se conoce). Así
     *     ya no hace falta buscar a mano cuando el índice de Gmail no encuentra la OC.
     * No duplica: la persistencia posterior usa firstOrCreate por número+OC.
     *
     * @return array<string, mixed>|null
     */
    public function buscarAlbaranPorOc(string $oc, ?string $fecha = null, ?float $monto = null): ?array
    {
        $oc = trim($oc);

        // 1) y 2) Búsqueda directa por OC y variantes.
        $correo = null;
        foreach ($this->variantesOc($oc) as $variante) {
            $correos = $this->gmail->buscarAlbaranes($variante, 5);
            if ($correos !== []) {
                $correo = $correos[0];
                break;
            }
        }

        // 3) Fallback por fecha+sala+monto (solo lectura de Gmail).
        if ($correo === null) {
            return $this->albaranPorFechaSalaMonto($oc, $fecha, $monto);
        }

        $adjuntos = $this->gmail->adjuntos($correo['id']);

        $debug = [
            'asunto' => $correo['asunto'] ?? null,
            'adjuntos' => array_map(fn ($a) => ['filename' => $a['filename'], 'mime' => $a['mime'], 'size' => strlen($a['data'])], $adjuntos),
            'pdf_parseado' => false,
            'parser' => null,
        ];

        // El albarán de Calleja viene como PDF: extraer texto y parsear monto/fecha/nº.
        $datosPdf = null;
        foreach ($adjuntos as $a) {
            $esPdf = str_contains(strtolower($a['mime']), 'pdf') || str_ends_with(strtolower($a['filename']), '.pdf');
            if (! $esPdf) {
                continue;
            }
            $debug['pdf_parseado'] = true;
            $debug['archivo'] = $this->guardarCopia($correo['id'], $a['filename'], $a['data']);
            $datosPdf = $this->albaranParser->desdePdf($a['data']);
            $debug['parser'] = $datosPdf['debug'];
            break;
        }
        // Si no hay PDF, intentar un JSON adjunto.
        if ($datosPdf === null) {
            $json = $this->jsonDteDeAdjuntos($correo['id']);
            if ($json !== null) {
                $p = $this->parser->desdeJson($json);
                $datosPdf = ['numero' => null, 'fecha' => $p['fecha'], 'oc' => $p['ordenCompra'], 'monto' => $p['monto']];
            }
        }

        return [
            'gmail_message_id' => $correo['id'],
            'numero_albaran' => Albaran::numeroLimpio($correo['asunto'] ?? null, $datosPdf['numero'] ?? null, $correo['snippet'] ?? null),
            'orden_compra' => ($datosPdf['oc'] ?? null) ?: $oc,
            'sala' => OrdenCompra::salaDesde($oc),
            'nombre_sala' => $datosPdf['nombre_sala'] ?? null,
            'monto' => $datosPdf['monto'] ?? null,
            'fecha' => ($datosPdf['fecha'] ?? null) ?: ($correo['fecha'] ?? null),
            'fuente' => self::ALBARAN_GMAIL,
            'debug' => $debug,
        ];
    }

    /**
     * Variantes de OC para la búsqueda en Gmail (el índice puede no casar el número exacto):
     * la OC tal cual y sin ceros a la izquierda. Solo dígitos. Sin duplicados.
     *
     * @return array<int, string>
     */
    private function variantesOc(string $oc): array
    {
        $digitos = preg_replace('/\D/', '', $oc);
        $set = array_filter([$digitos, ltrim($digitos, '0')], fn ($v) => $v !== '');

        return array_values(array_unique($set));
    }

    /**
     * FALLBACK del albarán por fecha + sala + monto cuando la búsqueda por OC no encontró
     * nada. Lista los albaranes del día del CCF y elige el candidato cuya SALA (código de la
     * OC) coincide y cuyo MONTO es el más cercano al del CCF (si se conoce). Devuelve null si
     * no hay fecha o ningún candidato de la misma sala. NO adivina entre salas distintas.
     *
     * @return array<string, mixed>|null
     */
    private function albaranPorFechaSalaMonto(string $oc, ?string $fecha, ?float $monto): ?array
    {
        $fechaValida = $fecha ? rescue(fn () => Carbon::parse($fecha)->toDateString(), null, false) : null;
        if ($fechaValida === null) {
            return null;
        }

        $salaOc = OrdenCompra::salaDesde($oc);
        $candidatos = collect($this->albaranesDeFecha($fechaValida))
            // Solo candidatos de la MISMA sala (por el nº de albarán o por su OC).
            ->filter(function (array $c) use ($salaOc) {
                $salaCand = Albaran::salaDesdeNumero($c['numero_albaran'] ?? null) ?: ($c['sala'] ?? null);

                return $salaOc !== null && Sala::normalizar($salaCand) === $salaOc;
            })
            ->values();

        if ($candidatos->isEmpty()) {
            return null;
        }

        // Con monto conocido, el candidato de monto más cercano; si no, el primero de la sala.
        $elegido = $monto !== null
            ? $candidatos->sortBy(fn (array $c) => abs(((float) ($c['monto'] ?? 0)) - $monto))->first()
            : $candidatos->first();

        return [
            'gmail_message_id' => $elegido['gmail_message_id'] ?? null,
            'numero_albaran' => $elegido['numero_albaran'] ?? null,
            'orden_compra' => $elegido['orden_compra'] ?: $oc,
            'sala' => $salaOc,
            'nombre_sala' => $elegido['nombre_sala'] ?? null,
            'monto' => $elegido['monto'] ?? null,
            'fecha' => $elegido['fecha'] ?? $fechaValida,
            'fuente' => self::ALBARAN_GMAIL,
            'debug' => ['fallback' => 'fecha+sala+monto', 'fecha' => $fechaValida, 'sala' => $salaOc, 'candidatos' => $candidatos->count()],
        ];
    }

    /**
     * ¿La última llamada a albaranesDeFecha() quedó truncada por el límite? Se consulta
     * justo después: si es true, ese día tiene más correos de los que se leyeron.
     */
    public function ultimaBusquedaTruncada(): bool
    {
        return $this->gmail->ultimaBusquedaTruncada();
    }

    /**
     * Lista los albaranes del label de Calleja recibidos en una fecha (YYYY-MM-DD),
     * parseando cada PDF para sacar número/OC/sala/monto/fecha + el asunto del correo.
     * Sirve para la búsqueda manual cuando no se encontró el albarán por OC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function albaranesDeFecha(string $fecha, int $limite = 40): array
    {
        $correos = $this->gmail->buscarAlbaranesPorFecha($fecha, $limite);

        return collect($correos)
            // El label puede traer otros documentos (p. ej. QUEDAN); solo albaranes.
            ->filter(fn (array $c) => str_contains(mb_strtolower($c['asunto'] ?? ''), 'albar'))
            ->map(fn (array $c) => $this->datosAlbaranDeCorreo($c))
            ->unique('gmail_message_id')
            ->values()
            ->all();
    }

    /**
     * Extrae los datos de un correo de albarán (PDF o JSON adjunto) en el formato de
     * candidato para mostrar/seleccionar.
     *
     * @param  array<string, mixed>  $correo
     * @return array<string, mixed>
     */
    private function datosAlbaranDeCorreo(array $correo): array
    {
        $datosPdf = null;
        // El PDF se lleva junto con los datos parseados: es la EVIDENCIA de la entrega y
        // hasta ahora se descartaba después de sacarle el número y el monto con
        // expresiones regulares. Quien lo guarda es {@see AlbaranPersistidor}, no esta
        // clase: leer un correo no debe escribir en disco, y así una consulta de solo
        // lectura sigue sin dejar rastro.
        $archivoNombre = null;
        $archivoContenido = null;

        foreach ($this->gmail->adjuntos($correo['id']) as $a) {
            $esPdf = str_contains(strtolower($a['mime']), 'pdf') || str_ends_with(strtolower($a['filename']), '.pdf');
            if ($esPdf) {
                $archivoNombre = $a['filename'];
                $archivoContenido = $a['data'];
                $datosPdf = $this->albaranParser->desdePdf($a['data']);
                break;
            }
        }
        if ($datosPdf === null) {
            $json = $this->jsonDteDeAdjuntos($correo['id']);
            if ($json !== null) {
                $p = $this->parser->desdeJson($json);
                $datosPdf = ['numero' => null, 'fecha' => $p['fecha'], 'oc' => $p['ordenCompra'], 'monto' => $p['monto']];
            }
        }

        $oc = $datosPdf['oc'] ?? null;
        $numero = Albaran::numeroLimpio($correo['asunto'] ?? null, $datosPdf['numero'] ?? null, $correo['snippet'] ?? null);
        // Sala desde la OC si se pudo parsear; si no, del 2º segmento del número de albarán.
        $sala = OrdenCompra::salaDesde($oc) ?: Albaran::salaDesdeNumero($numero);

        return [
            'gmail_message_id' => $correo['id'],
            'numero_albaran' => $numero,
            'orden_compra' => $oc,
            'sala' => $sala,
            // Nombre de sala: del texto del PDF si viene; si no, del mapa auxiliar por código.
            'nombre_sala' => ($datosPdf['nombre_sala'] ?? null) ?: Sala::nombre($sala),
            'monto' => $datosPdf['monto'] ?? null,
            'fecha' => ($datosPdf['fecha'] ?? null) ?: ($correo['fecha'] ?? null),
            'asunto' => $correo['asunto'] ?? null,
            // Solo los BYTES DEL ADJUNTO. Nunca tokens, credenciales ni la traza de la
            // consulta a Gmail, que puede llevar dentro los parámetros de la búsqueda.
            'archivo_nombre' => $archivoNombre,
            'archivo_contenido' => $archivoContenido,
        ];
    }

    /**
     * Primer adjunto JSON de un mensaje, decodificado. Null si no hay.
     *
     * @return array<string, mixed>|null
     */
    private function jsonDteDeAdjuntos(string $messageId): ?array
    {
        foreach ($this->gmail->adjuntos($messageId) as $adj) {
            $esJson = str_contains(strtolower($adj['mime']), 'json')
                || str_ends_with(strtolower($adj['filename']), '.json');
            if (! $esJson) {
                continue;
            }
            $dec = $this->decoder->decodificar($adj['data'], $adj['mime'], $adj['filename']);
            if ($dec['ok']) {
                return $dec['data'];
            }
        }

        return null;
    }

    /** Guarda una copia temporal del adjunto crudo para inspección. Devuelve la ruta. */
    private function guardarCopia(string $messageId, string $filename, string $data): string
    {
        $dir = trim((string) config('ppq.gmail.storage_dir', 'ppq/gmail'), '/').'/inspect';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $messageId.'-'.$filename);
        $ruta = $dir.'/'.$safe;
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $data);

        return $ruta;
    }
}
