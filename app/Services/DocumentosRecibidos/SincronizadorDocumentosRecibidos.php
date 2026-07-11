<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sincroniza los DOCUMENTOS RECIBIDOS desde el buzón configurado (Yahoo/IMAP), a
 * través del contrato MailboxClient. NO depende de Gmail/PPQ.
 *
 * Es MANUAL (se dispara con un botón), NUNCA automático. Garantías fase 1:
 *  - SOLO LECTURA del buzón (el lector no borra, no mueve, no marca leído).
 *  - No reenvía ni envía correos. No toca DTE emitidos ni correlativos.
 *  - Deduplica por id de mensaje y por código de generación (no reprocesa).
 *
 * Reutiliza el decodificador y el parser de adjuntos DTE (utilidades de parsing,
 * no fuentes de correo).
 */
class SincronizadorDocumentosRecibidos
{
    public function __construct(
        private readonly MailboxClient $buzon,
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly ParserDocumentoRecibido $parser,
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
     * Revisa el buzón y crea registros locales para los correos con DTE nuevos.
     *
     * INCREMENTAL (default): busca solo DESDE la fecha del último documento guardado
     * (prefiere fecha_correo; si no, created_at), inclusive ese mismo día. Si no hay
     * ningún registro, usa un rango inicial razonable (últimos 30 días). El modo
     * HISTÓRICO ($incremental=false) revisa todo el buzón (más lento).
     *
     * @return array{disponible: bool, carpeta: string, desde: ?string, incremental: bool, revisados: int, nuevos: int, duplicados: int, sin_datos: int, error: ?string}
     */
    public function sincronizar(bool $incremental = true): array
    {
        $carpeta = (string) config('documentos_recibidos.mail.folder', 'INBOX');
        $base = ['disponible' => true, 'carpeta' => $carpeta, 'desde' => null, 'incremental' => $incremental,
            'revisados' => 0, 'nuevos' => 0, 'duplicados' => 0, 'sin_datos' => 0, 'error' => null];

        if (! $this->buzon->disponible()) {
            return array_merge($base, ['disponible' => false,
                'error' => 'El correo de documentos recibidos (Yahoo/IMAP) no está configurado. Configurá las variables DOCUMENTOS_RECIBIDOS_MAIL_* para habilitar la revisión.']);
        }

        // Fecha incremental: desde el último documento (o últimos 30 días si no hay).
        $desde = $incremental ? $this->fechaDesde() : null;
        $resumen = array_merge($base, ['desde' => $desde?->format('Y-m-d')]);

        try {
            $limite = (int) config('documentos_recibidos.limite', 30);
            $mensajes = $this->buzon->mensajesConAdjuntos($limite, $desde);
        } catch (Throwable $e) {
            return array_merge($resumen, ['error' => 'No se pudo leer el correo: '.$e->getMessage()]);
        }

        foreach ($mensajes as $mensaje) {
            $resumen['revisados']++;
            $id = (string) ($mensaje['id'] ?? '');
            if ($id === '' || DocumentoRecibido::where('gmail_message_id', $id)->exists()) {
                $resumen['duplicados']++;

                continue;
            }

            try {
                match ($this->procesarMensaje($id, $mensaje)) {
                    'nuevo' => $resumen['nuevos']++,
                    'duplicado' => $resumen['duplicados']++,
                    default => $resumen['sin_datos']++,
                };
            } catch (Throwable) {
                $resumen['sin_datos']++;
            }
        }

        return $resumen;
    }

    /**
     * Procesa un mensaje ya normalizado (con adjuntos): detecta PDF/JSON, extrae
     * datos del DTE y crea el registro (o lo omite si ya existe por código).
     *
     * @param  array{asunto?: ?string, remitente?: ?string, fecha?: ?string, adjuntos?: array<int, array{filename?: string, mime?: string, data?: string}>}  $mensaje
     * @return string 'nuevo' | 'duplicado' | 'sin_datos'
     */
    private function procesarMensaje(string $id, array $mensaje): string
    {
        $adjuntos = (array) ($mensaje['adjuntos'] ?? []);

        $tienePdf = false;
        $tieneJson = false;
        $datos = [];
        $jsonAdjunto = null;

        foreach ($adjuntos as $a) {
            $nombre = strtolower((string) ($a['filename'] ?? ''));
            $mime = strtolower((string) ($a['mime'] ?? ''));
            if (str_ends_with($nombre, '.pdf') || str_contains($mime, 'pdf')) {
                $tienePdf = true;
            }
            if (str_ends_with($nombre, '.json') || str_contains($mime, 'json')) {
                $tieneJson = true;
                if ($datos === []) {
                    $dec = $this->decoder->decodificar((string) ($a['data'] ?? ''), $mime, (string) ($a['filename'] ?? ''));
                    if (! empty($dec['ok']) && is_array($dec['data'])) {
                        $datos = $this->parser->extraer($dec['data']);
                        $jsonAdjunto = $a['filename'] ?? null;
                    }
                }
            }
        }

        if (! $tienePdf && ! $tieneJson) {
            return 'sin_datos';
        }

        $codigo = $datos['codigo_generacion'] ?? null;
        if ($codigo !== null && DocumentoRecibido::where('codigo_generacion', $codigo)->exists()) {
            return 'duplicado';
        }

        // Guardar adjuntos localmente para el futuro envío a contabilidad (no se
        // reenvía nada ahora). Solo lectura del buzón; escritura en disco local.
        $rutas = $this->guardarAdjuntos($id, $adjuntos);

        DocumentoRecibido::create([
            'gmail_message_id' => $id,
            'origen_email' => $this->buzon->fuente(),
            'asunto' => $mensaje['asunto'] ?? null,
            'remitente' => $mensaje['remitente'] ?? null,
            'fecha_correo' => $this->fecha($mensaje['fecha'] ?? null),
            // Fecha de emisión del DTE (fecEmi del JSON), si vino.
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
            'metadata_json' => [
                'fuente' => $this->buzon->fuente(),
                'adjuntos' => array_map(fn ($a) => ['filename' => $a['filename'] ?? null, 'mime' => $a['mime'] ?? null], $adjuntos),
                'archivos' => $rutas,
                'json_adjunto' => $jsonAdjunto,
            ],
        ]);

        return 'nuevo';
    }

    /**
     * Guarda los adjuntos en disco local. Devuelve rutas relativas. No sube nada.
     *
     * @param  array<int, array{filename?: string, mime?: string, data?: string}>  $adjuntos
     * @return array<int, string>
     */
    private function guardarAdjuntos(string $id, array $adjuntos): array
    {
        $base = trim((string) config('documentos_recibidos.storage_dir', 'documentos-recibidos'), '/').'/'.$this->carpeta($id);
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

    private function carpeta(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'msg';
    }

    /**
     * Día desde el que revisar (incremental): el del último documento guardado
     * (prefiere fecha_correo; si no, created_at), al inicio del día para incluirlo
     * completo. Sin registros: últimos 30 días.
     */
    private function fechaDesde(): Carbon
    {
        $ultimo = DocumentoRecibido::orderByRaw('COALESCE(fecha_correo, created_at) DESC')->first();
        if ($ultimo === null) {
            return now()->subDays(30)->startOfDay();
        }

        $ref = $ultimo->fecha_correo ?? $ultimo->created_at;

        return Carbon::parse($ref)->startOfDay();
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
