<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroDocumentoProcedencia;
use App\Models\Cobros\CobroDocumentoRelacion;
use App\Models\Dte;
use App\Services\Cobros\AuditorEnviados\AnalizadorAuditoriaEnviados;
use App\Services\Cobros\ImportadorEnviados\AdjuntoImportable;
use App\Services\Cobros\ImportadorEnviados\ResultadoImportacionEnviados;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use App\Support\NumeroControl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Incorpora al seguimiento de cobros los CCF y NC de contabilidad (Conta) que salieron
 * por la cuenta Gmail conectada, leyendo el JSON del DTE adjunto a cada correo ENVIADO.
 *
 * Es la continuación de {@see AuditorEnviadosService}: misma consulta, mismo filtro por
 * identificador fiscal del receptor. La diferencia es que este SÍ puede escribir, y solo
 * cuando se le pide (`$aplicar`). Sin eso recorre y clasifica exactamente igual, sin
 * guardar una fila.
 *
 * ─────────────────────────────── Identidad fiscal ───────────────────────────────
 *
 * Un documento es su número de control normalizado ({@see IdentidadPpq}) Y su código de
 * generación. Los dos tienen que coincidir con lo que ya haya —en `dtes` o en
 * `cobro_documentos`—; si uno coincide y el otro no, es una EXCEPCIÓN reportada, no una
 * sobreescritura. Lo mismo si dos copias del mismo documento en el buzón se contradicen.
 *
 * ──────────────────────────── Lo que nunca se pisa ────────────────────────────
 *
 * De un documento que ya está en el seguimiento solo se completan datos VACÍOS (código,
 * sello, fecha, importe) y se agrega su procedencia. Estados de pago y presentación,
 * observaciones, albarán y revisión histórica no se tocan. Un DTE emitido por este
 * sistema no se importa: lo da de alta `cobros:sincronizar` con su propia fila de `dtes`.
 *
 * ─────────────────────────── Aceptación, no suposición ───────────────────────────
 *
 * Sin sello de recepción en el JSON no hay evidencia de que Hacienda lo aceptara, y no se
 * importa: se cuenta como omitido. El sello del JSON es un indicio, no una consulta en
 * línea al MH: un documento invalidado después puede seguir trayendo su sello.
 *
 * ──────────────────────────────── Relación NC→CCF ────────────────────────────────
 *
 * Solo la que declara `documentoRelacionado` en el JSON de la nota, por código de
 * generación, y todas las que declare. Si el CCF todavía no está en el seguimiento, la
 * relación se guarda sin destino y se resuelve en una corrida posterior. Que el barrido
 * no encuentre NC para un CCF NO prueba que no las tenga.
 */
class ImportadorEnviadosService
{
    private const PATRON_CODIGO = '/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/';

    private const TIPOS = ['03', '05'];

    public function __construct(
        private readonly GmailClient $gmail,
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly DteCorreoParser $parser,
        private readonly AuditorEnviadosService $auditor,
        private readonly AltaCobrosService $alta,
    ) {}

    /** Consulta de Gmail: la misma del auditor, para que los dos miren el mismo universo. */
    public function query(Carbon $desde, Carbon $hasta): string
    {
        return $this->auditor->query($desde, $hasta);
    }

    /**
     * @throws \RuntimeException si el cliente no tiene identificador fiscal o lo comparte
     *                           con otro cliente: en ninguno de los dos casos se puede decir
     *                           con seguridad de quién es un JSON.
     */
    public function importar(Cliente $cliente, Carbon $desde, Carbon $hasta, int $limite, bool $aplicar = false): ResultadoImportacionEnviados
    {
        $nit = $this->nitInequivoco($cliente);

        $r = new ResultadoImportacionEnviados;
        $r->aplicado = $aplicar;

        $adjuntos = $this->barrer($this->query($desde, $hasta), $limite, $r);
        $documentos = $this->agrupar($this->filtrar($adjuntos, $nit, $r), $r);

        // CCF primero: así una NC del mismo barrido encuentra su CCF ya dado de alta
        // (o, en seco, ya previsto).
        uasort($documentos, fn (array $a, array $b) => strcmp((string) $a[0]->tipoDte, (string) $b[0]->tipoDte));

        /** @var array<string, true> CCF que esta corrida crea (o, en seco, crearía) */
        $ccfNuevos = [];

        foreach ($documentos as $codigo => $copias) {
            $this->procesar($cliente, (string) $codigo, $copias, $aplicar, $r, $ccfNuevos);
        }

        $r->relacionesResueltas += $this->resolverPendientes($cliente, $aplicar, $ccfNuevos);

        return $r;
    }

    // ─────────────────────────────── lectura del buzón ───────────────────────────────

    /** @return array<int, AdjuntoImportable> */
    private function barrer(string $query, int $limite, ResultadoImportacionEnviados $r): array
    {
        $vistos = [];
        $adjuntos = [];
        $token = null;

        do {
            $pagina = $this->gmail->idsDeCobros($query, $token, 100);

            foreach ($pagina['ids'] as $id) {
                if (isset($vistos[$id])) {
                    $r->correosRepetidos++;

                    continue;
                }

                if (count($vistos) >= $limite) {
                    $r->truncado = true;
                    break 2;
                }

                $vistos[$id] = true;
                $r->correosRevisados++;
                array_push($adjuntos, ...$this->leerCorreo($id, $r));
            }

            $token = $pagina['siguiente'];
        } while (filled($token));

        if (filled($token)) {
            $r->truncado = true;
        }

        return $adjuntos;
    }

    /** @return array<int, AdjuntoImportable> */
    private function leerCorreo(string $messageId, ResultadoImportacionEnviados $r): array
    {
        $leidos = [];
        $habiaJson = false;

        foreach ($this->gmail->adjuntos($messageId) as $adjunto) {
            $nombre = (string) ($adjunto['filename'] ?? '');
            $mime = (string) ($adjunto['mime'] ?? '');
            $data = (string) ($adjunto['data'] ?? '');

            if (! str_ends_with(strtolower($nombre), '.json') && ! str_contains(strtolower($mime), 'json')) {
                continue;
            }
            $habiaJson = true;

            $decodificado = $this->decoder->decodificar($data, $mime, $nombre);

            if (! $decodificado['ok'] || ! is_array($decodificado['data'])) {
                $r->adjuntosIlegibles++;

                continue;
            }

            $json = $decodificado['data'];
            $base = $this->parser->desdeJson($json);
            $ids = $this->parser->identificadoresReceptor($json);

            $leidos[] = new AdjuntoImportable(
                messageId: $messageId,
                adjuntoNombre: $nombre !== '' ? mb_substr($nombre, 0, 160) : null,
                adjuntoHash: hash('sha256', $data),
                tipoDte: $base['tipoDte'],
                numeroControl: $base['numeroControl'] !== null ? trim($base['numeroControl']) : null,
                codigoGeneracion: $base['codigoGeneracion'] !== null ? strtoupper(trim($base['codigoGeneracion'])) : null,
                sello: $base['sello'] !== null ? trim($base['sello']) : null,
                fechaEmision: $this->fecha($base['fecha']),
                monto: $base['monto'],
                identificadoresReceptor: array_values(array_unique(array_filter([
                    AnalizadorAuditoriaEnviados::normalizarNit($ids['nit']),
                    AnalizadorAuditoriaEnviados::normalizarNit($ids['numDocumento']),
                ]))),
                relacionados: $this->parser->documentoRelacionado($json),
            );
        }

        if (! $habiaJson) {
            $r->correosSinJson++;
        }

        return $leidos;
    }

    // ─────────────────────────────── clasificación ───────────────────────────────

    /**
     * Deja solo los adjuntos de ESTE cliente, de tipo 03/05 y con identidad completa.
     *
     * @param  array<int, AdjuntoImportable>  $adjuntos
     * @return array<int, AdjuntoImportable>
     */
    private function filtrar(array $adjuntos, string $nit, ResultadoImportacionEnviados $r): array
    {
        $validos = [];

        foreach ($adjuntos as $a) {
            $referencia = $a->numeroControl ?? $a->codigoGeneracion ?? '(sin identificación)';

            if ($a->identificadoresReceptor === []) {
                $r->receptorNoIdentificable++;

                continue;
            }
            if (count($a->identificadoresReceptor) > 1) {
                if (in_array($nit, $a->identificadoresReceptor, true)) {
                    $r->excepcion($referencia, $a->messageId, 'El receptor declara dos identificadores fiscales distintos (nit y numDocumento): no se elige uno.');
                } else {
                    $r->receptorAjeno++;
                }

                continue;
            }
            if ($a->identificadoresReceptor[0] !== $nit) {
                $r->receptorAjeno++;

                continue;
            }
            if (! in_array($a->tipoDte, self::TIPOS, true)) {
                $r->tipoDistinto++;

                continue;
            }
            if (IdentidadPpq::normalizar($a->numeroControl) === null
                || $a->codigoGeneracion === null
                || ! preg_match(self::PATRON_CODIGO, $a->codigoGeneracion)) {
                $r->incompletos++;

                continue;
            }

            $partes = NumeroControl::desde($a->numeroControl);
            if ($partes !== null && $partes->tipoDte !== $a->tipoDte) {
                $r->excepcion($referencia, $a->messageId, "El número de control es de tipo {$partes->tipoDte} pero el JSON declara tipo {$a->tipoDte}.");

                continue;
            }

            $validos[] = $a;
        }

        return $validos;
    }

    /**
     * Agrupa por código de generación las copias CON SELLO de cada documento. Un documento
     * sin ninguna copia sellada no pasa; dos copias que se contradicen tampoco.
     *
     * @param  array<int, AdjuntoImportable>  $adjuntos
     * @return array<string, array<int, AdjuntoImportable>>
     */
    private function agrupar(array $adjuntos, ResultadoImportacionEnviados $r): array
    {
        $porCodigo = [];
        foreach ($adjuntos as $a) {
            $porCodigo[$a->codigoGeneracion][] = $a;
        }

        $grupos = [];
        foreach ($porCodigo as $codigo => $copias) {
            $selladas = array_values(array_filter($copias, fn (AdjuntoImportable $a) => filled($a->sello)));

            if ($selladas === []) {
                $r->omitidosSinSello++;

                continue;
            }

            $primera = $selladas[0];
            foreach ($selladas as $copia) {
                if (IdentidadPpq::normalizar($copia->numeroControl) !== IdentidadPpq::normalizar($primera->numeroControl)
                    || $copia->tipoDte !== $primera->tipoDte
                    || $copia->sello !== $primera->sello
                    || $copia->fechaEmision !== $primera->fechaEmision
                    || ! $this->mismoMonto($copia->monto, $primera->monto)) {
                    $r->excepcion((string) $codigo, $copia->messageId, 'Dos copias del mismo código de generación traen datos fiscales distintos: no se importa ninguna.');

                    continue 2;
                }
            }

            // Cuenta las copias repetidas, no las distintas huellas: el mismo archivo
            // adjunto a dos correos también es un reenvío.
            $r->reenvios += count($selladas) - 1;
            $grupos[(string) $codigo] = $selladas;
        }

        // El mismo número de control con dos códigos distintos: ninguno es confiable.
        $porNumero = [];
        foreach ($grupos as $codigo => $copias) {
            $porNumero[IdentidadPpq::normalizar($copias[0]->numeroControl)][] = $codigo;
        }
        foreach ($porNumero as $codigos) {
            if (count($codigos) > 1) {
                foreach ($codigos as $codigo) {
                    $r->excepcion($grupos[$codigo][0]->numeroControl, $grupos[$codigo][0]->messageId, 'El mismo número de control aparece con más de un código de generación en el buzón.');
                    unset($grupos[$codigo]);
                }
            }
        }

        return $grupos;
    }

    // ─────────────────────────────── alta / adopción ───────────────────────────────

    /**
     * @param  array<int, AdjuntoImportable>  $copias  todas selladas y coherentes entre sí
     * @param  array<string, true>  $ccfNuevos
     */
    private function procesar(Cliente $cliente, string $codigo, array $copias, bool $aplicar, ResultadoImportacionEnviados $r, array &$ccfNuevos): void
    {
        $doc = $copias[0];
        $clave = IdentidadPpq::normalizar($doc->numeroControl);

        $local = $this->contrastarConDtes($cliente, $doc, $clave, $codigo);
        if ($local === 'propio') {
            $r->propiosDelSistema++;

            return;
        }
        if ($local !== null) {
            $r->excepcion($doc->numeroControl, $doc->messageId, $local);

            return;
        }

        $existentes = CobroDocumento::query()
            ->where('numero_control_norm', $clave)
            ->orWhereRaw('UPPER(codigo_generacion) = ?', [$codigo])
            ->get();

        if ($existentes->count() > 1) {
            $r->excepcion($doc->numeroControl, $doc->messageId, 'El número de control y el código de generación apuntan a documentos distintos del seguimiento.');

            return;
        }

        $existente = $existentes->first();

        if ($existente !== null) {
            $conflicto = $this->contrastarConSeguimiento($cliente, $existente, $doc, $clave, $codigo);
            if ($conflicto !== null) {
                $r->excepcion($doc->numeroControl, $doc->messageId, $conflicto);

                return;
            }

            $r->yaExistian++;
            if ($existente->tipo_dte === '03' && blank($existente->codigo_generacion)) {
                // Al completarse su código, las NC que lo declaren podrán resolverse.
                $ccfNuevos[$codigo] = true;
            }
            if ($aplicar) {
                DB::transaction(function () use ($existente, $copias, $r) {
                    if ($this->completar($existente, $copias[0])) {
                        $r->completados++;
                    }
                    $this->registrarProcedencias($existente, $copias, $r);
                    if ($existente->tipo_dte === '05') {
                        $this->registrarRelaciones($existente, $copias[0], $r);
                    }
                });
            } else {
                if ($this->faltaAlgo($existente, $doc)) {
                    $r->completados++;
                }
                if ($existente->tipo_dte === '05') {
                    $this->preverRelaciones($existente, $doc, $r, $ccfNuevos);
                }
            }

            return;
        }

        $antecedente = $this->alta->antecedente($doc->numeroControl);
        $viejo = ! $antecedente['revisar'] && $this->esViejo($doc->fechaEmision);
        if ($antecedente['revisar'] || $viejo) {
            $r->revisionHistorica++;
        }

        $doc->tipoDte === '03' ? $r->ccfCreados++ : $r->ncCreadas++;
        if ($doc->tipoDte === '03') {
            $ccfNuevos[$codigo] = true;
        }

        if (! $aplicar) {
            if ($doc->tipoDte === '05') {
                $this->preverRelaciones(null, $doc, $r, $ccfNuevos, $cliente->id);
            }

            return;
        }

        try {
            DB::transaction(function () use ($cliente, $copias, $doc, $codigo, $antecedente, $viejo, $r) {
                $nuevo = CobroDocumento::create([
                    'cliente_id' => $cliente->id,
                    'origen' => OrigenCobroDocumento::Gmail->value,
                    'tipo_dte' => $doc->tipoDte,
                    'numero_control' => $doc->numeroControl,
                    'codigo_generacion' => $codigo,
                    'sello_recepcion' => $doc->sello,
                    'fecha_emision' => $doc->fechaEmision,
                    'monto' => $doc->monto,
                    'establecimiento_codigo' => NumeroControl::establecimiento($doc->numeroControl),
                    'punto_venta_codigo' => NumeroControl::puntoVenta($doc->numeroControl),
                    'revisar_historico' => $antecedente['revisar'] || $viejo,
                    'revisar_historico_motivo' => $antecedente['motivo'] ?? ($viejo ? AltaCobrosService::MOTIVO_SIN_ANTECEDENTE : null),
                ]);

                $this->registrarProcedencias($nuevo, $copias, $r);
                if ($doc->tipoDte === '05') {
                    $this->registrarRelaciones($nuevo, $doc, $r);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Otro proceso lo dio de alta entre la lectura y la escritura: no se fuerza.
            $doc->tipoDte === '03' ? $r->ccfCreados-- : $r->ncCreadas--;
            $r->excepcion($doc->numeroControl, $doc->messageId, 'Otro proceso dio de alta este documento durante la corrida; vuelva a ejecutar la importación.');
        }
    }

    /**
     * Compara contra los DTE de este sistema. 'propio' si es uno nuestro idéntico, un
     * motivo de conflicto si coincide solo a medias, null si no hay ninguno.
     */
    private function contrastarConDtes(Cliente $cliente, AdjuntoImportable $doc, string $clave, string $codigo): ?string
    {
        $locales = Dte::query()
            ->where(fn ($q) => $q
                ->whereRaw('UPPER(codigo_generacion) = ?', [$codigo])
                ->orWhere(IdentidadPpq::columnaNormalizada(), $clave))
            ->get(['id', 'cliente_id', 'numero_control', 'codigo_generacion', 'ambiente']);

        if ($locales->isEmpty()) {
            return null;
        }

        $identicos = $locales->filter(fn (Dte $d) => strtoupper((string) $d->codigo_generacion) === $codigo
            && IdentidadPpq::normalizar($d->numero_control) === $clave);

        if ($identicos->count() === $locales->count() && $locales->count() === 1) {
            return (int) $identicos->first()->cliente_id === (int) $cliente->id
                ? 'propio'
                : 'Coincide con un DTE de este sistema emitido a otro cliente.';
        }

        return 'Coincide solo en parte (número de control o código de generación) con un DTE de este sistema'
            .' (ambiente '.$locales->pluck('ambiente')->filter()->unique()->implode('/').'): revisar antes de importar.';
    }

    /** Motivo de conflicto con el documento ya existente en el seguimiento, o null si es el mismo. */
    private function contrastarConSeguimiento(Cliente $cliente, CobroDocumento $e, AdjuntoImportable $doc, string $clave, string $codigo): ?string
    {
        if ((int) $e->cliente_id !== (int) $cliente->id) {
            return 'Ya está en el seguimiento de otro cliente.';
        }
        if ($e->dte_id !== null) {
            return 'Ya está en el seguimiento ligado a un DTE de este sistema que no coincide con el JSON.';
        }
        if ($e->numero_control_norm !== $clave) {
            return "Su código de generación ya está en el seguimiento con otro número de control ({$e->numero_control}).";
        }
        if (filled($e->codigo_generacion) && strtoupper($e->codigo_generacion) !== $codigo) {
            return 'Su número de control ya está en el seguimiento con otro código de generación.';
        }
        if ($e->tipo_dte !== $doc->tipoDte) {
            return "Ya está en el seguimiento como tipo {$e->tipo_dte}.";
        }
        if (filled($e->sello_recepcion) && $e->sello_recepcion !== $doc->sello) {
            return 'Ya está en el seguimiento con otro sello de recepción.';
        }
        if ($e->fecha_emision !== null && $doc->fechaEmision !== null && $e->fecha_emision->toDateString() !== $doc->fechaEmision) {
            return 'Ya está en el seguimiento con otra fecha de emisión ('.$e->fecha_emision->toDateString().').';
        }
        if ($e->monto !== null && $doc->monto !== null && ! $this->mismoMonto((float) $e->monto, $doc->monto)) {
            return "Ya está en el seguimiento con otro importe ({$e->monto}).";
        }

        return null;
    }

    /** Solo lo VACÍO. Devuelve true si completó algo. */
    private function completar(CobroDocumento $e, AdjuntoImportable $doc): bool
    {
        $faltan = array_filter([
            'codigo_generacion' => blank($e->codigo_generacion) ? $doc->codigoGeneracion : null,
            'sello_recepcion' => blank($e->sello_recepcion) ? $doc->sello : null,
            'fecha_emision' => $e->fecha_emision === null ? $doc->fechaEmision : null,
            'monto' => $e->monto === null ? $doc->monto : null,
        ], fn ($v) => $v !== null);

        if ($faltan === []) {
            return false;
        }

        $e->forceFill($faltan)->save();

        return true;
    }

    private function faltaAlgo(CobroDocumento $e, AdjuntoImportable $doc): bool
    {
        return (blank($e->codigo_generacion) && $doc->codigoGeneracion !== null)
            || (blank($e->sello_recepcion) && $doc->sello !== null)
            || ($e->fecha_emision === null && $doc->fechaEmision !== null)
            || ($e->monto === null && $doc->monto !== null);
    }

    /** @param  array<int, AdjuntoImportable>  $copias */
    private function registrarProcedencias(CobroDocumento $documento, array $copias, ResultadoImportacionEnviados $r): void
    {
        foreach ($copias as $copia) {
            $procedencia = CobroDocumentoProcedencia::firstOrCreate(
                [
                    'cobro_documento_id' => $documento->id,
                    'gmail_message_id' => $copia->messageId,
                    'adjunto_hash' => $copia->adjuntoHash,
                ],
                [
                    'fuente' => CobroDocumentoProcedencia::FUENTE_GMAIL_ENVIADOS,
                    'adjunto_nombre' => $copia->adjuntoNombre,
                    'codigo_generacion' => $copia->codigoGeneracion,
                ],
            );

            if ($procedencia->wasRecentlyCreated) {
                $r->procedenciasNuevas++;
            }
        }
    }

    // ─────────────────────────────── relaciones NC→CCF ───────────────────────────────

    /** @return array<int, array{codigo: string, tipo: ?string, fecha: ?string}> */
    private function relacionesDeclaradas(AdjuntoImportable $nc, ResultadoImportacionEnviados $r): array
    {
        $validas = [];

        foreach ($nc->relacionados as $rel) {
            $codigo = strtoupper(trim((string) $rel['numeroDocumento']));

            if (! preg_match(self::PATRON_CODIGO, $codigo)) {
                $r->excepcion($nc->numeroControl, $nc->messageId, 'La NC declara un documento relacionado que no es un código de generación electrónico'
                    .($codigo !== '' ? " ({$codigo})" : '').': esa relación no se registra.');

                continue;
            }

            $validas[$codigo] = ['codigo' => $codigo, 'tipo' => $rel['tipoDocumento'], 'fecha' => $this->fecha($rel['fechaEmision'])];
        }

        if ($validas === []) {
            $r->ncSinRelacion++;
        }

        return array_values($validas);
    }

    private function registrarRelaciones(CobroDocumento $nc, AdjuntoImportable $doc, ResultadoImportacionEnviados $r): void
    {
        foreach ($this->relacionesDeclaradas($doc, $r) as $rel) {
            $relacion = CobroDocumentoRelacion::firstOrCreate(
                ['nc_cobro_documento_id' => $nc->id, 'codigo_generacion_relacionado' => $rel['codigo']],
                ['tipo_documento_relacionado' => $rel['tipo'], 'fecha_emision_relacionado' => $rel['fecha']],
            );
            if ($relacion->wasRecentlyCreated) {
                $r->relacionesNuevas++;
            }

            if ($relacion->ccf_cobro_documento_id === null) {
                $ccf = $this->ccfUnico((int) $nc->cliente_id, $rel['codigo']);
                if ($ccf !== null) {
                    $relacion->forceFill(['ccf_cobro_documento_id' => $ccf])->save();
                    $r->relacionesResueltas++;
                } else {
                    $r->relacionesSinCcf[$rel['codigo']] = true;
                }
            }
        }
    }

    /**
     * El equivalente en seco de {@see registrarRelaciones()}: mismos conteos, sin escribir.
     *
     * Una relación ya guardada y pendiente no se cuenta como resuelta acá: la cuenta
     * {@see resolverPendientes()} al final, una sola vez.
     *
     * @param  array<string, true>  $ccfNuevos
     */
    private function preverRelaciones(?CobroDocumento $nc, AdjuntoImportable $doc, ResultadoImportacionEnviados $r, array $ccfNuevos, ?int $clienteId = null): void
    {
        $clienteId ??= (int) $nc?->cliente_id;

        foreach ($this->relacionesDeclaradas($doc, $r) as $rel) {
            $guardada = $nc === null ? null : CobroDocumentoRelacion::where('nc_cobro_documento_id', $nc->id)
                ->where('codigo_generacion_relacionado', $rel['codigo'])
                ->first();

            if ($guardada?->ccf_cobro_documento_id !== null) {
                continue;
            }

            $resolveria = isset($ccfNuevos[$rel['codigo']]) || $this->ccfUnico($clienteId, $rel['codigo']) !== null;

            if ($guardada === null) {
                $r->relacionesNuevas++;
                if ($resolveria) {
                    $r->relacionesResueltas++;
                }
            }

            if (! $resolveria) {
                $r->relacionesSinCcf[$rel['codigo']] = true;
            }
        }
    }

    /**
     * Relaciones de este cliente que quedaron sin CCF en corridas anteriores y cuyo CCF ya
     * está en el seguimiento (p. ej. se incorporó a mano o llegó en otro rango). En seco
     * cuenta también las que resolvería un CCF que esta corrida crearía.
     *
     * @param  array<string, true>  $ccfNuevos
     */
    private function resolverPendientes(Cliente $cliente, bool $aplicar, array $ccfNuevos): int
    {
        $resueltas = 0;

        CobroDocumentoRelacion::query()
            ->whereNull('ccf_cobro_documento_id')
            ->whereIn('nc_cobro_documento_id', CobroDocumento::deCliente($cliente->id)->select('id'))
            ->chunkById(200, function ($relaciones) use ($cliente, $aplicar, $ccfNuevos, &$resueltas) {
                foreach ($relaciones as $relacion) {
                    $ccf = $this->ccfUnico($cliente->id, $relacion->codigo_generacion_relacionado);
                    if ($ccf === null) {
                        if (! $aplicar && isset($ccfNuevos[$relacion->codigo_generacion_relacionado])) {
                            $resueltas++;
                        }

                        continue;
                    }
                    if ($aplicar) {
                        $relacion->forceFill(['ccf_cobro_documento_id' => $ccf])->save();
                    }
                    $resueltas++;
                }
            });

        return $resueltas;
    }

    /** Id del ÚNICO CCF del cliente con ese código de generación, o null (ninguno o más de uno). */
    private function ccfUnico(int $clienteId, string $codigo): ?int
    {
        $ids = CobroDocumento::deCliente($clienteId)
            ->where('tipo_dte', '03')
            ->whereRaw('UPPER(codigo_generacion) = ?', [$codigo])
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    // ─────────────────────────────── apoyo ───────────────────────────────

    private function nitInequivoco(Cliente $cliente): string
    {
        $nit = AnalizadorAuditoriaEnviados::normalizarNit($cliente->num_documento);

        if ($nit === null) {
            throw new \RuntimeException(
                "El cliente {$cliente->nombre} (id {$cliente->id}) no tiene número de documento fiscal registrado; "
                .'no se puede importar con seguridad sin él (nunca se filtra por nombre).'
            );
        }

        $otros = Cliente::query()
            ->whereKeyNot($cliente->id)
            ->whereNotNull('num_documento')
            ->pluck('num_documento', 'id')
            ->filter(fn ($valor) => AnalizadorAuditoriaEnviados::normalizarNit($valor) === $nit);

        if ($otros->isNotEmpty()) {
            throw new \RuntimeException(
                'El identificador fiscal de este cliente también lo tiene(n) el/los cliente(s) id '
                .$otros->keys()->implode(', ').': no se puede decidir de quién es cada JSON. No se importó nada.'
            );
        }

        return $nit;
    }

    private function esViejo(?string $fecha): bool
    {
        if ($fecha === null) {
            return false;
        }

        $corte = Carbon::today()->subDays((int) config('cobros.dias_revision_historica', 30));

        return Carbon::createFromFormat('Y-m-d', $fecha)->startOfDay()->lt($corte);
    }

    private function fecha(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        [$a, $m, $d] = array_map('intval', explode('-', $valor));

        return checkdate($m, $d, $a) ? $valor : null;
    }

    private function mismoMonto(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return Dinero::comparar(Dinero::redondear($a), Dinero::redondear($b)) === 0;
    }
}
