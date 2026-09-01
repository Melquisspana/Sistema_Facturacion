<?php

namespace App\Services\DocumentosRecibidos;

use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\Buzon\EstadoBuzon;
use App\Services\DocumentosRecibidos\Buzon\PaginaMensajes;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Lector IMAP de SOLO LECTURA para el buzón de compras (Yahoo).
 *
 * Garantías que NO cambian:
 *  - Abre el buzón en modo OP_READONLY.
 *  - Lee cuerpos con FT_PEEK: NO marca los correos como leídos.
 *  - NUNCA borra (imap_delete), NUNCA mueve (imap_mail_move) ni cambia flags.
 *  - Las credenciales vienen SOLO del Centro de Configuración y no se registran.
 *
 * QUÉ CAMBIÓ, y por qué:
 *
 *  1. **Se lee por día y por página, en UID ASCENDENTE.** La versión anterior hacía
 *     `imap_search(...ALL)`, `rsort($ids)` y `array_slice($ids, 0, 30)`: siempre los 30
 *     UID más altos. Sin cursor, "Revisar histórico" releía exactamente los mismos
 *     correos cada vez y no podía retroceder nunca. Ahora la ventana se acota del lado
 *     del servidor (`SINCE`/`BEFORE` de un día) y se pagina con `$desdeUid`.
 *  2. **Los fallos son excepciones.** Antes `catch (Throwable) { return []; }` convertía
 *     una contraseña vencida en una revisión exitosa sin novedades. Ahora se distingue
 *     credenciales rechazadas de buzón inaccesible, y ambas salen a la superficie.
 *  3. **Se devuelve el `Message-ID`, el UID y el `UIDVALIDITY`** para que la identidad
 *     del correo deje de ser el UID crudo ({@see Buzon\IdentidadCorreo}).
 */
class ImapMailboxClient implements MailboxClient
{
    /** Fragmentos del error de IMAP que significan "credenciales rechazadas". */
    private const SENALES_AUTENTICACION = [
        'authenticationfailed', 'authentication failed', 'invalid credentials',
        'login failure', 'login failed', 'authenticate', 'auth error', 'bad credentials',
    ];

    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct(ConfiguracionDocumentosRecibidos $configuracion)
    {
        $this->cfg = $configuracion->paraLector();
    }

    public function disponible(): bool
    {
        return function_exists('imap_open')
            && strtolower((string) ($this->cfg['driver'] ?? '')) === 'imap'
            && filled($this->cfg['host'] ?? null)
            && filled($this->cfg['username'] ?? null)
            && filled($this->cfg['password'] ?? null);
    }

    public function fuente(): string
    {
        if (! function_exists('imap_open')) {
            return 'IMAP no soportado por el servidor (falta la extensión imap de PHP)';
        }
        $host = (string) ($this->cfg['host'] ?? '');
        $user = (string) ($this->cfg['username'] ?? '');

        // El usuario es un correo (no secreto); la contraseña NUNCA se muestra.
        return $this->disponible()
            ? 'IMAP '.$user.($host !== '' ? ' ('.$host.')' : '')
            : 'Correo Yahoo/IMAP sin configurar';
    }

    public function estado(): EstadoBuzon
    {
        $conn = $this->abrir();

        try {
            $status = @imap_status($conn, $this->buzon(), SA_UIDVALIDITY | SA_MESSAGES);

            return new EstadoBuzon(
                carpeta: $this->carpeta(),
                uidValidity: isset($status->uidvalidity) ? (int) $status->uidvalidity : null,
                mensajes: isset($status->messages) ? (int) $status->messages : 0,
            );
        } finally {
            $this->cerrar($conn);
        }
    }

    public function mensajesDelDia(CarbonInterface $dia, int $limite, ?int $desdeUid = null): PaginaMensajes
    {
        $limite = max(1, $limite);
        $conn = $this->abrir();

        try {
            // Ventana CERRADA de un día, resuelta por el servidor. IMAP compara contra la
            // fecha interna del mensaje: SINCE es >= y BEFORE es < , así que un día es
            // [dia, dia+1). Formato obligatorio: "07-Aug-2026".
            $criterio = 'SINCE "'.$dia->copy()->startOfDay()->format('d-M-Y').'"'
                .' BEFORE "'.$dia->copy()->startOfDay()->addDay()->format('d-M-Y').'"';

            $ids = @imap_search($conn, $criterio, SE_UID);

            // imap_search devuelve false tanto cuando no hay coincidencias como cuando
            // falla. Se distingue mirando el buffer de errores: sin error, es un día
            // legítimamente vacío; con error, el día NO se pudo leer y no puede darse
            // por completo.
            if ($ids === false) {
                $this->abortarSiHuboError('No se pudo buscar en el buzón');

                return PaginaMensajes::vacia();
            }

            $ids = array_map('intval', (array) $ids);
            sort($ids, SORT_NUMERIC); // ASCENDENTE: el corte deja afuera lo MÁS NUEVO

            if ($desdeUid !== null) {
                $ids = array_values(array_filter($ids, fn (int $uid) => $uid > $desdeUid));
            }

            // Hay más UID en este día de los que entran en la página: el día queda
            // truncado y quien lo llame NO puede declararlo completo.
            $truncada = count($ids) > $limite;
            $ids = array_slice($ids, 0, $limite);

            $mensajes = [];
            $ultimoUid = null;
            foreach ($ids as $uid) {
                // El cursor avanza con TODO UID leído, tenga adjuntos o no: si solo
                // avanzara con los que sirven, un bloque de correos sin adjunto haría
                // que la página siguiente los volviera a mirar para siempre.
                $ultimoUid = $uid;
                $mensaje = $this->leerMensaje($conn, $uid);
                if ($mensaje !== null && $mensaje['adjuntos'] !== []) {
                    $mensajes[] = $mensaje;
                }
            }

            return new PaginaMensajes($mensajes, $truncada, $ultimoUid);
        } catch (BuzonException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BuzonInaccesibleException(
                'La lectura del buzón se cortó: '.BuzonException::sanear($e->getMessage(), $this->password()),
                previous: $e,
            );
        } finally {
            $this->cerrar($conn);
        }
    }

    /**
     * Abre la conexión IMAP en SOLO LECTURA.
     *
     * @throws AutenticacionBuzonException|BuzonInaccesibleException
     */
    private function abrir()
    {
        if (! function_exists('imap_open')) {
            throw new BuzonInaccesibleException('Este servidor no tiene la extensión IMAP de PHP.');
        }
        if (! $this->disponible()) {
            throw new BuzonInaccesibleException('El buzón de compras no está configurado (servidor, usuario o contraseña).');
        }

        if (is_int($this->cfg['timeout'] ?? null)) {
            @imap_timeout(IMAP_OPENTIMEOUT, (int) $this->cfg['timeout']);
        }

        // imap_open no lanza: deja el motivo en un buffer global. Se vacía antes para no
        // leer el error de otra llamada.
        @imap_errors();

        try {
            // OP_READONLY: no marca leído; no permite escritura destructiva.
            $conn = @imap_open($this->buzon(), (string) $this->cfg['username'], (string) $this->cfg['password'], OP_READONLY, 1);
        } catch (Throwable $e) {
            throw new BuzonInaccesibleException(
                'No se pudo abrir el buzón: '.BuzonException::sanear($e->getMessage(), $this->password()),
                previous: $e,
            );
        }

        if ($conn === false) {
            $this->abortarSiHuboError('No se pudo abrir el buzón');

            throw new BuzonInaccesibleException('El servidor rechazó la conexión sin dar un motivo.');
        }

        return $conn;
    }

    /**
     * Lanza la excepción que corresponda si el buffer de IMAP trae un error.
     *
     * Distinguir credenciales de red no es cosmético: con "autenticación fallida" el
     * operador tiene que ir a Configuración, y reintentar no sirve; con "inaccesible"
     * el reintento es exactamente lo que corresponde.
     *
     * @throws AutenticacionBuzonException|BuzonInaccesibleException
     */
    private function abortarSiHuboError(string $prefijo): void
    {
        $errores = @imap_errors() ?: [];
        if ($errores === []) {
            return;
        }

        $motivo = BuzonException::sanear((string) end($errores), $this->password());

        $comparable = mb_strtolower($motivo);
        foreach (self::SENALES_AUTENTICACION as $senal) {
            if (str_contains($comparable, $senal)) {
                throw new AutenticacionBuzonException(
                    'El buzón rechazó las credenciales: '.$motivo
                    .'. Revisá usuario y contraseña en Configuración > Integraciones > Documentos recibidos.'
                );
            }
        }

        throw new BuzonInaccesibleException($prefijo.': '.$motivo);
    }

    /** Cierra sin expunge (no borra nada) y vacía el buffer de errores. */
    private function cerrar($conn): void
    {
        if ($conn !== false && $conn !== null) {
            @imap_close($conn);
        }
        @imap_errors();
    }

    /** Cadena de buzón: `{host:puerto/imap/ssl/readonly}CARPETA`. */
    private function buzon(): string
    {
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?? 993);
        $enc = strtolower((string) ($this->cfg['encryption'] ?? 'ssl'));
        $flags = '/imap'.($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '')).'/readonly';

        return '{'.$host.':'.$port.$flags.'}'.$this->carpeta();
    }

    private function carpeta(): string
    {
        return (string) ($this->cfg['folder'] ?? 'INBOX');
    }

    private function password(): string
    {
        return (string) ($this->cfg['password'] ?? '');
    }

    /**
     * Lee un mensaje por UID y devuelve sus adjuntos PDF/JSON/XML (con FT_PEEK, sin
     * marcar leído), más los datos de identidad.
     *
     * @return array<string, mixed>|null
     */
    private function leerMensaje($conn, int $uid): ?array
    {
        $overview = @imap_fetch_overview($conn, (string) $uid, FT_UID);
        $info = $overview[0] ?? null;
        $estructura = @imap_fetchstructure($conn, $uid, FT_UID);
        if (! $estructura) {
            return null;
        }

        $adjuntos = [];
        $this->recorrerPartes($estructura, function ($parte, string $seccion) use (&$adjuntos, $conn, $uid) {
            $nombre = $this->nombreAdjunto($parte);
            if ($nombre === null) {
                return;
            }
            $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'json', 'xml'], true)) {
                return;
            }
            // FT_PEEK: leer SIN marcar el correo como leído.
            $raw = @imap_fetchbody($conn, $uid, $seccion, FT_UID | FT_PEEK);
            $data = $this->decodificarParte((string) $raw, (int) ($parte->encoding ?? 0));
            $adjuntos[] = [
                'filename' => $nombre,
                'mime' => $this->mime($parte),
                'data' => $data,
            ];
        });

        return [
            'uid' => $uid,
            // `message_id` es la identidad real del correo: viaja con él y no cambia al
            // moverlo de carpeta ni al reconstruir el buzón.
            'message_id' => isset($info->message_id) ? (string) $info->message_id : null,
            'asunto' => isset($info->subject) ? $this->decodeMime((string) $info->subject) : null,
            'remitente' => isset($info->from) ? $this->decodeMime((string) $info->from) : null,
            'fecha' => isset($info->date) ? (string) $info->date : null,
            'adjuntos' => $adjuntos,
        ];
    }

    private function recorrerPartes(object $estructura, callable $cb, string $prefijo = ''): void
    {
        if (! isset($estructura->parts) || ! is_array($estructura->parts)) {
            // Mensaje de una sola parte.
            if ($prefijo === '') {
                $cb($estructura, '1');
            }

            return;
        }
        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijo === '' ? (string) ($i + 1) : $prefijo.'.'.($i + 1);
            $cb($parte, $seccion);
            if (isset($parte->parts) && is_array($parte->parts)) {
                $this->recorrerPartes($parte, $cb, $seccion);
            }
        }
    }

    private function nombreAdjunto(object $parte): ?string
    {
        foreach (['dparameters', 'parameters'] as $grupo) {
            foreach ((array) ($parte->$grupo ?? []) as $p) {
                $attr = strtolower((string) ($p->attribute ?? ''));
                if (in_array($attr, ['filename', 'name'], true) && filled($p->value ?? null)) {
                    return $this->decodeMime((string) $p->value);
                }
            }
        }

        return null;
    }

    private function mime(object $parte): string
    {
        $tipos = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $tipo = $tipos[$parte->type ?? 7] ?? 'application';
        $sub = strtolower((string) ($parte->subtype ?? 'octet-stream'));

        return $tipo.'/'.$sub;
    }

    private function decodificarParte(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw),      // BASE64
            4 => (string) quoted_printable_decode($raw), // QUOTED-PRINTABLE
            default => $raw,
        };
    }

    private function decodeMime(string $s): string
    {
        $out = '';
        foreach ((array) imap_mime_header_decode($s) as $part) {
            $out .= (string) $part->text;
        }

        return $out !== '' ? $out : $s;
    }
}
