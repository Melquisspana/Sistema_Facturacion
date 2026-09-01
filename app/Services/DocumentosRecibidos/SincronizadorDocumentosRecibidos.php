<?php

namespace App\Services\DocumentosRecibidos;

use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonException;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\Buzon\IdentidadCorreo;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sincroniza los DOCUMENTOS RECIBIDOS (compras) desde el buzón Yahoo/IMAP a través del
 * contrato {@see MailboxClient}. NO depende de Gmail/PPQ.
 *
 * SOLO LECTURA del buzón: no borra, no mueve, no marca leído, no reenvía. No toca DTE
 * emitidos ni correlativos. Escribe únicamente en `documentos_recibidos`, en el disco
 * local (adjuntos) y en la tabla de progreso.
 *
 * CÓMO RECORRE, y por qué así. Día por día, y dentro de cada día por páginas de UID
 * ASCENDENTE hasta agotarlo. El recorrido anterior pedía "los 30 más recientes" de todo
 * el buzón: lo que caía por debajo del corte era siempre lo más viejo, y como la marca
 * incremental avanzaba igual, esos correos quedaban del lado ya cubierto y no se leían
 * nunca más. Leyendo un día completo antes de darlo por cubierto, un día que no se pudo
 * agotar NO avanza la marca y la corrida siguiente vuelve a él.
 *
 * IDEMPOTENTE en tres niveles: identidad estable del correo
 * ({@see IdentidadCorreo}), identidad histórica de las filas anteriores a la migración,
 * y `codigo_generacion` del DTE. Repetir el mismo rango deja exactamente el mismo
 * resultado.
 */
class SincronizadorDocumentosRecibidos
{
    /**
     * Tope de páginas por día. Es una red de seguridad, no un límite de negocio: con
     * `--limite` razonable ningún día real llega acá. Si se alcanzara, el día queda
     * `parcial` (nunca `completo`) y la corrida siguiente lo continúa.
     */
    private const MAX_PAGINAS_POR_DIA = 200;

    public function __construct(
        private readonly MailboxClient $buzon,
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly ParserDocumentoRecibido $parser,
        private readonly ClasificadorDocumentoRecibido $clasificador,
        private readonly FiltroExclusionCorreo $filtro,
        private readonly ProgresoSincronizacionCompras $progreso,
        private readonly ConfiguracionDocumentosRecibidos $configuracion,
    ) {}

    public function disponible(): bool
    {
        return $this->buzon->disponible();
    }

    public function fuente(): string
    {
        return $this->buzon->fuente();
    }

    /**
     * Recorre el rango [desde, hasta] día por día.
     *
     * @param  int  $limitePagina  máximo de correos por página (no por día: el día se agota)
     * @param  bool  $aplicar  sin esto no escribe NADA (ni documentos ni progreso)
     */
    public function sincronizarRango(Carbon $desde, Carbon $hasta, int $limitePagina, bool $aplicar): ResumenSincronizacion
    {
        $carpeta = $this->carpeta();

        if (! $this->buzon->disponible()) {
            return new ResumenSincronizacion(
                desenlace: ResumenSincronizacion::SIN_CONFIGURAR,
                carpeta: $carpeta,
                error: 'El correo de compras (Yahoo/IMAP) no está configurado. Configuralo en Configuración > Integraciones > Documentos recibidos.',
            );
        }

        // 1) Metadatos ANTES de leer nada: confirma que el buzón responde y acepta las
        //    credenciales, y trae el UIDVALIDITY contra el que se valida el progreso.
        try {
            $estado = $this->buzon->estado();
        } catch (BuzonException $e) {
            return $this->resumenDeFalla($e, $carpeta, $desde, $hasta);
        }

        // A partir de acá manda la carpeta que el lector ABRIÓ de verdad, no la que dice
        // la configuración. Si alguien cambió el ajuste sin reiniciar nada, el progreso
        // tiene que quedar anotado bajo la carpeta que se leyó: si no, los cursores de
        // una carpeta se aplicarían a los UID de otra.
        $carpeta = $estado->carpeta;

        // 2) Si la carpeta se reconstruyó, los cursores guardados apuntan a otros
        //    correos. Reanudar desde ellos saltearía documentos reales, así que la
        //    corrida se DETIENE y lo dice, en vez de avanzar sobre una referencia falsa.
        $conflicto = $this->progreso->uidValidityEnConflicto($carpeta, $estado->uidValidity);
        if ($conflicto !== null) {
            return new ResumenSincronizacion(
                desenlace: ResumenSincronizacion::UID_VALIDITY_CAMBIADO,
                carpeta: $carpeta,
                desde: $desde->toDateString(),
                hasta: $hasta->toDateString(),
                error: 'La carpeta '.$carpeta.' se reconstruyó en el servidor (UIDVALIDITY '.$conflicto
                    .' → '.$estado->uidValidity.'). El progreso por UID ya no aplica: los UID guardados apuntan a otros correos. '
                    .'Corré con --reiniciar-uid-validity para soltar los cursores y volver a recorrer los días; no se borra ningún documento.',
            );
        }

        $total = ['correos' => 0, 'nuevos' => 0, 'duplicados' => 0, 'descartados' => 0, 'rechazados' => 0];
        $completos = [];
        $incompletos = [];

        foreach ($this->progreso->dias($desde, $hasta) as $fecha) {
            $dia = Carbon::parse($fecha)->startOfDay();

            try {
                $resultadoDia = $this->recorrerDia($dia, $carpeta, $estado->uidValidity, $limitePagina, $aplicar);
            } catch (BuzonException $e) {
                // El buzón se cayó a mitad. Lo ya procesado quedó guardado (es
                // idempotente), este día queda marcado con su motivo y la corrida
                // TERMINA: seguir con los demás días contra un buzón caído solo
                // produciría más errores y ocultaría el primero.
                if ($aplicar) {
                    $this->progreso->marcarError($dia, $carpeta, $estado->uidValidity, null, $e->getMessage());
                }
                $incompletos[] = $fecha;

                return $this->resumenDeFalla($e, $carpeta, $desde, $hasta, $total, $completos, $incompletos, $aplicar);
            }

            foreach ($total as $k => $v) {
                $total[$k] = $v + $resultadoDia['conteos'][$k];
            }
            $resultadoDia['completo'] ? $completos[] = $fecha : $incompletos[] = $fecha;
        }

        $desenlace = match (true) {
            $incompletos !== [] => ResumenSincronizacion::INCOMPLETA,
            $total['nuevos'] === 0 => ResumenSincronizacion::SIN_NOVEDADES,
            default => ResumenSincronizacion::COMPLETA,
        };

        return new ResumenSincronizacion(
            desenlace: $desenlace,
            carpeta: $carpeta,
            desde: $desde->toDateString(),
            hasta: $hasta->toDateString(),
            correos: $total['correos'],
            nuevos: $total['nuevos'],
            duplicados: $total['duplicados'],
            descartados: $total['descartados'],
            rechazados: $total['rechazados'],
            diasCompletos: $completos,
            diasIncompletos: $incompletos,
            aplicado: $aplicar,
        );
    }

    /**
     * Recorre UN día hasta agotarlo, paginando por UID ascendente.
     *
     * El día solo se declara COMPLETO si el buzón dijo que no quedaba nada más. Si el
     * límite se alcanzó (`truncada`) o se llegó al tope de páginas, queda `parcial` con
     * su cursor: la marca no lo pasa y la corrida siguiente lo continúa desde ahí.
     *
     * @return array{completo: bool, conteos: array<string, int>}
     *
     * @throws BuzonException
     */
    private function recorrerDia(Carbon $dia, string $carpeta, ?int $uidValidity, int $limitePagina, bool $aplicar): array
    {
        $vacio = ['correos' => 0, 'nuevos' => 0, 'duplicados' => 0, 'descartados' => 0, 'rechazados' => 0];
        $totalDia = $vacio;

        // Reanudación: se arranca después del último UID ya leído de este día.
        $cursor = $aplicar ? $this->progreso->cursorDe($dia, $carpeta) : null;
        $completo = false;

        for ($pagina = 0; $pagina < self::MAX_PAGINAS_POR_DIA; $pagina++) {
            $resultado = $this->buzon->mensajesDelDia($dia, $limitePagina, $cursor);

            // Conteos de ESTA página. La fila de progreso los suma, así que pasarle el
            // acumulado del día contaría cada página tantas veces como páginas queden.
            $conteos = $vacio;
            foreach ($resultado->mensajes as $mensaje) {
                $conteos['correos']++;
                $clave = $this->procesarMensaje($mensaje, $carpeta, $uidValidity, $aplicar);
                $conteos[$clave]++;
            }
            foreach ($conteos as $k => $v) {
                $totalDia[$k] += $v;
            }

            if ($resultado->ultimoUid !== null) {
                $cursor = $resultado->ultimoUid;
            }

            // Persistir DESPUÉS de cada página, no al final del día: si la corrida muere
            // en la página 7, las 6 anteriores no se vuelven a leer.
            if ($aplicar) {
                $resultado->truncada
                    ? $this->progreso->marcarParcial($dia, $carpeta, $uidValidity, $cursor, $conteos)
                    : $this->progreso->marcarCompleto($dia, $carpeta, $uidValidity, $cursor, $conteos);
            }

            if (! $resultado->truncada) {
                $completo = true;
                break;
            }
        }

        return ['completo' => $completo, 'conteos' => $totalDia];
    }

    /**
     * Procesa un mensaje normalizado: identidad, deduplicación, parseo, exclusión y
     * alta. Devuelve la clave del contador que corresponde.
     *
     * @param  array<string, mixed>  $mensaje
     * @return 'nuevos'|'duplicados'|'descartados'|'rechazados'
     */
    private function procesarMensaje(array $mensaje, string $carpeta, ?int $uidValidity, bool $aplicar): string
    {
        ['identidad' => $identidad, 'message_id' => $messageId] = IdentidadCorreo::para($mensaje);
        $uid = isset($mensaje['uid']) ? (int) $mensaje['uid'] : null;

        $existente = $this->buscarExistente($identidad, $uid);
        if ($existente !== null) {
            // El correo ya estaba, pero puede haberse movido de carpeta o tener otro UID
            // tras una reconstrucción del buzón. Se refresca DÓNDE está (diagnóstico),
            // nunca QUÉ es: la identidad no se toca.
            if ($aplicar) {
                $this->refrescarUbicacion($existente, $identidad, $messageId, $carpeta, $uid, $uidValidity);
            }

            return 'duplicados';
        }

        try {
            return $this->registrar($mensaje, $identidad, $messageId, $carpeta, $uid, $uidValidity, $aplicar);
        } catch (Throwable $e) {
            // Un fallo al guardar (disco, permisos, adjunto corrupto) YA NO se confunde
            // con "no tenía DTE legible": se cuenta como rechazado y queda en el log con
            // el motivo. Como la fila no se creó, el reintento lo vuelve a procesar.
            Log::warning('documentos_recibidos.correo_rechazado', [
                'identidad' => $identidad,
                'carpeta' => $carpeta,
                'uid' => $uid,
                'asunto' => $mensaje['asunto'] ?? null,
                'motivo' => $e->getMessage(),
            ]);

            return 'rechazados';
        }
    }

    /**
     * Documento ya registrado para este correo, por cualquiera de los dos caminos.
     *
     * El segundo camino es la compatibilidad con las filas anteriores a la migración de
     * identidad: guardaban el UID crudo en `gmail_message_id` y todavía no tienen
     * `identidad`. Se acota a ESAS filas (`identidad IS NULL`) a propósito — así un UID
     * repetido de otra carpeta no puede hacerse pasar por un documento nuevo ya visto.
     */
    private function buscarExistente(string $identidad, ?int $uid): ?DocumentoRecibido
    {
        return DocumentoRecibido::query()
            ->where(function ($q) use ($identidad, $uid) {
                $q->where('identidad', $identidad);
                if ($uid !== null) {
                    $q->orWhere(fn ($sub) => $sub->whereNull('identidad')->where('gmail_message_id', (string) $uid));
                }
            })
            ->first();
    }

    /**
     * Actualiza dónde vive el correo hoy, sin tocar su contenido ni su identidad.
     *
     * Es lo que hace que mover un correo de carpeta no genere un duplicado: se
     * reconoce por el `Message-ID`, y lo único que cambia es el UID y la carpeta que
     * quedan anotados para diagnóstico. De paso adopta las filas históricas, que
     * llegan acá con `identidad` en NULL.
     */
    private function refrescarUbicacion(DocumentoRecibido $doc, string $identidad, ?string $messageId, string $carpeta, ?int $uid, ?int $uidValidity): void
    {
        $doc->forceFill(array_filter([
            'identidad' => $doc->identidad ?: $identidad,
            'message_id' => $doc->message_id ?: $messageId,
            'buzon_carpeta' => $carpeta,
            'uid' => $uid,
            'uid_validity' => $uidValidity,
        ], fn ($v) => $v !== null))->save();
    }

    /**
     * Clasifica y crea el registro local del correo.
     *
     * @param  array<string, mixed>  $mensaje
     * @return 'nuevos'|'duplicados'|'descartados'|'rechazados'
     */
    private function registrar(array $mensaje, string $identidad, ?string $messageId, string $carpeta, ?int $uid, ?int $uidValidity, bool $aplicar): string
    {
        $adjuntos = (array) ($mensaje['adjuntos'] ?? []);

        $tienePdf = false;
        $tieneJson = false;
        $datos = [];
        $jsonAdjunto = null;
        $pdfAdjunto = null;
        $decodeFallido = null;

        foreach ($adjuntos as $a) {
            $nombre = (string) ($a['filename'] ?? '');
            $nombreMin = strtolower($nombre);
            $mime = strtolower((string) ($a['mime'] ?? ''));
            if (str_ends_with($nombreMin, '.pdf') || str_contains($mime, 'pdf')) {
                $tienePdf = true;
                $pdfAdjunto ??= $nombre;
            }
            if (str_ends_with($nombreMin, '.json') || str_contains($mime, 'json')) {
                $tieneJson = true;
                // Sigue intentando con el siguiente .json si el anterior no decodificó.
                if ($datos === []) {
                    $dec = $this->decoder->decodificar((string) ($a['data'] ?? ''), $mime, $nombre);
                    if (! empty($dec['ok']) && is_array($dec['data'])) {
                        $datos = $this->parser->extraer($dec['data']);
                        $jsonAdjunto = $nombre;
                        $decodeFallido = null;
                    } else {
                        $decodeFallido = $this->clasificador->diagnosticoDecode($dec, $nombre);
                    }
                }
            }
        }

        if (! $tienePdf && ! $tieneJson) {
            return 'rechazados';
        }

        $codigo = $datos['codigo_generacion'] ?? null;
        if ($codigo !== null && DocumentoRecibido::where('codigo_generacion', $codigo)->exists()) {
            return 'duplicados';
        }

        [$clasificacion, $diagnostico] = $this->clasificador->clasificar(
            $tieneJson, $datos, $decodeFallido, (string) ($mensaje['asunto'] ?? ''), (string) $pdfAdjunto,
        );

        // Exclusión de correos NO-DTE (estado de cuenta, orden de compra, PDF-only sin
        // DTE): se evalúa DESPUÉS de clasificar y ANTES de guardar adjuntos o crear la
        // fila. Un JSON DTE válido nunca llega acá. No se toca el buzón; solo se
        // registra en log el motivo (metadatos, sin contenido).
        $nombresAdjuntos = array_values(array_filter(array_map(
            fn ($a) => (string) ($a['filename'] ?? ''), $adjuntos
        ), fn ($n) => $n !== ''));
        $exclusion = $this->filtro->evaluar($clasificacion, (string) ($mensaje['asunto'] ?? ''), $nombresAdjuntos);
        if ($exclusion !== null) {
            Log::info('documentos_recibidos.correo_descartado', [
                'identidad' => $identidad,
                'remitente' => $mensaje['remitente'] ?? null,
                'asunto' => $mensaje['asunto'] ?? null,
                'adjuntos' => $nombresAdjuntos,
                'regla' => $exclusion['regla'],
                'motivo' => $exclusion['motivo'],
            ]);

            return 'descartados';
        }

        // Dry-run: se leyó y se clasificó todo (para que el informe sea real), pero no
        // se escribe ni el adjunto ni la fila.
        if (! $aplicar) {
            return 'nuevos';
        }

        // Los adjuntos se guardan bajo la IDENTIDAD del correo, no bajo el UID: la
        // carpeta del documento deja de moverse cuando el correo cambia de carpeta.
        $rutas = $this->guardarAdjuntos($identidad, $adjuntos);

        DocumentoRecibido::create([
            'identidad' => $identidad,
            'message_id' => $messageId,
            'buzon_carpeta' => $carpeta,
            'uid' => $uid,
            'uid_validity' => $uidValidity,
            // Se sigue escribiendo el UID acá para no romper la unicidad histórica ni las
            // pantallas que lo muestran; ya NO es la clave de deduplicación.
            'gmail_message_id' => $uid !== null ? (string) $uid : $identidad,
            'origen_email' => $this->buzon->fuente(),
            'asunto' => $mensaje['asunto'] ?? null,
            'remitente' => $mensaje['remitente'] ?? null,
            'fecha_correo' => $this->fecha($mensaje['fecha'] ?? null),
            // Fecha de emisión del DTE (fecEmi del JSON): la FISCAL, la que decide a qué
            // período contable pertenece la compra.
            'fecha_dte' => $this->fecha($datos['fecha'] ?? null),
            'tipo_documento' => $datos['tipo_documento'] ?? null,
            'numero_control' => $datos['numero_control'] ?? null,
            'codigo_generacion' => $codigo,
            'sello_recepcion' => $datos['sello_recepcion'] ?? null,
            'emisor_nombre' => $datos['emisor_nombre'] ?? null,
            'emisor_nit' => $datos['emisor_nit'] ?? null,
            'emisor_nrc' => $datos['emisor_nrc'] ?? null,
            'total' => $datos['total'] ?? null,
            'tiene_pdf' => $tienePdf,
            'tiene_json' => $tieneJson,
            'estado' => 'pendiente',
            'clasificacion' => $clasificacion,
            'clasificacion_diagnostico' => $diagnostico,
            'metadata_json' => [
                'fuente' => $this->buzon->fuente(),
                'adjuntos' => array_map(fn ($a) => ['filename' => $a['filename'] ?? null, 'mime' => $a['mime'] ?? null], $adjuntos),
                'archivos' => $rutas,
                'json_adjunto' => $jsonAdjunto,
            ],
        ]);

        return 'nuevos';
    }

    /**
     * Guarda los adjuntos en disco local. Devuelve rutas relativas. No sube nada.
     *
     * @param  array<int, array{filename?: string, mime?: string, data?: string}>  $adjuntos
     * @return array<int, string>
     */
    private function guardarAdjuntos(string $identidad, array $adjuntos): array
    {
        $base = trim((string) config('documentos_recibidos.storage_dir', 'documentos-recibidos'), '/').'/'.$this->carpetaDe($identidad);
        $rutas = [];
        foreach ($adjuntos as $a) {
            $nombre = (string) ($a['filename'] ?? '');
            $data = (string) ($a['data'] ?? '');
            if ($nombre === '' || $data === '') {
                continue;
            }
            $seguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre);
            $ruta = $base.'/'.$seguro;
            Storage::disk('local')->put($ruta, $data);
            $rutas[] = $ruta;
        }

        return $rutas;
    }

    /**
     * Nombre de carpeta a partir de la identidad. Un Message-ID puede ser largo y traer
     * cualquier cosa, así que se acorta con un hash estable en vez de recortarlo (dos
     * identidades distintas podrían compartir prefijo y pisarse los adjuntos).
     */
    private function carpetaDe(string $identidad): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9._-]+/', '_', $identidad) ?: 'msg';

        return strlen($limpio) <= 60 ? $limpio : substr($limpio, 0, 40).'-'.substr(sha1($identidad), 0, 12);
    }

    /** @param  array<string, int>  $total */
    private function resumenDeFalla(
        BuzonException $e, string $carpeta, Carbon $desde, Carbon $hasta,
        array $total = [], array $completos = [], array $incompletos = [], bool $aplicar = false,
    ): ResumenSincronizacion {
        return new ResumenSincronizacion(
            desenlace: $e instanceof AutenticacionBuzonException
                ? ResumenSincronizacion::AUTENTICACION_FALLIDA
                : ResumenSincronizacion::BUZON_INACCESIBLE,
            carpeta: $carpeta,
            desde: $desde->toDateString(),
            hasta: $hasta->toDateString(),
            correos: $total['correos'] ?? 0,
            nuevos: $total['nuevos'] ?? 0,
            duplicados: $total['duplicados'] ?? 0,
            descartados: $total['descartados'] ?? 0,
            rechazados: $total['rechazados'] ?? 0,
            diasCompletos: $completos,
            diasIncompletos: $incompletos,
            error: $e->getMessage(),
            aplicado: $aplicar,
        );
    }

    private function carpeta(): string
    {
        return $this->configuracion->carpeta();
    }

    private function fecha(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
