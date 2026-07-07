<?php

namespace App\Services\Ppq;

use App\Models\GmailCuenta;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Illuminate\Support\Carbon;

/**
 * Acceso a Gmail (OAuth2, solo lectura) para el módulo PPQ. Lee los CCF/NC de los
 * correos ENVIADOS y los albaranes del label de Calleja. Credenciales y tokens
 * SIEMPRE desde .env / BD cifrada, NUNCA en logs.
 *
 * Esta clase encapsula la mecánica de Gmail (OAuth + búsqueda + adjuntos). La
 * orquestación (resolver CCF, hacer match con el albarán) vive en PpqGmailService.
 */
class GmailClient
{
    /** ¿Está la integración configurada Y hay una cuenta conectada? */
    public function disponible(): bool
    {
        return $this->configurado() && optional(GmailCuenta::actual())->conectada() === true;
    }

    /** ¿Están las credenciales OAuth en config (client_id/secret/redirect)? */
    public function configurado(): bool
    {
        return (bool) config('ppq.gmail.enabled')
            && filled(config('ppq.gmail.client_id'))
            && filled(config('ppq.gmail.client_secret'))
            && filled(config('ppq.gmail.redirect_uri'));
    }

    /** URL de consentimiento OAuth (paso 1 de la conexión). */
    public function authUrl(): string
    {
        return $this->clienteBase()->createAuthUrl();
    }

    /**
     * Intercambia el código de autorización por tokens y los guarda (cifrados).
     * Devuelve la cuenta conectada.
     */
    public function conectar(string $codigo, ?int $userId = null): GmailCuenta
    {
        $client = $this->clienteBase();
        $token = $client->fetchAccessTokenWithAuthCode($codigo);
        if (isset($token['error'])) {
            throw new \RuntimeException('No se pudo autorizar Gmail: '.$token['error']);
        }
        $client->setAccessToken($token);

        $email = null;
        try {
            $email = (new Gmail($client))->users->getProfile('me')->getEmailAddress();
        } catch (\Throwable) {
            // El correo es informativo; si falla, igual guardamos los tokens.
        }

        return $this->guardarToken($token, $email, $userId);
    }

    /**
     * Busca en correos ENVIADOS el CCF/NC. El usuario escribe solo los últimos
     * dígitos (ej. "1011"); Gmail tokeniza por palabra completa, así que se prueban
     * automáticamente variantes (crudo, dígitos, padded a 15 y 16) y se devuelve la
     * PRIMERA que da resultados — sin que el usuario escriba formatos especiales.
     */
    public function buscarEnviados(string $numero, int $limite = 15): array
    {
        return $this->buscarEnviadosDetallado($numero, $limite)['resultados'];
    }

    /**
     * Igual que buscarEnviados pero informa QUÉ variante/QUÉ query devolvió el
     * resultado (para el debug de la búsqueda normal).
     *
     * Estrategia en dos pasos para no quedarse con correos que solo MENCIONAN el
     * número (Excel de cobro, plantillas de QUEDAN/NC) en lugar del DTE:
     *  1. PRECISO: cada variante exige un adjunto de DTE (JSON o PDF). Así la variante
     *     padded (nº de control completo) puede ganar aunque el número corto choque
     *     con un Excel de Prontos Pagos que lo lleva en una celda.
     *  2. FALLBACK: si NINGUNA variante trajo un correo con JSON/PDF, se reintenta sin
     *     el filtro, solo para poder DIAGNOSTICAR ("correo encontrado pero sin adjunto
     *     DTE legible") en vez de reportar que no hay nada.
     *
     * @return array{variante: ?string, query: ?string, resultados: array<int, array<string, mixed>>, intentos: array<int, array{query: string, total: int}>}
     */
    public function buscarEnviadosDetallado(string $numero, int $limite = 15): array
    {
        $base = trim((string) config('ppq.gmail.enviados_query', 'in:sent'));
        $filtroDte = trim((string) config('ppq.gmail.dte_adjunto_query', '(filename:json OR filename:pdf)'));
        $variantes = $this->variantesNumero($numero);
        $intentos = [];

        // Paso 1: exigir adjunto de DTE (descarta Excel/plantillas de cobro).
        if ($filtroDte !== '') {
            foreach ($variantes as $variante) {
                $q = trim($base.' '.$variante.' '.$filtroDte);
                $res = $this->listar($q, $limite);
                $intentos[] = ['query' => $q, 'total' => count($res)];
                if ($res !== []) {
                    return ['variante' => $variante, 'query' => $q, 'resultados' => $res, 'intentos' => $intentos];
                }
            }
        }

        // Paso 2 (fallback): sin filtro, para diagnosticar correos sin adjunto DTE.
        foreach ($variantes as $variante) {
            $q = trim($base.' '.$variante);
            $res = $this->listar($q, $limite);
            $intentos[] = ['query' => $q, 'total' => count($res)];
            if ($res !== []) {
                return ['variante' => $variante, 'query' => $q, 'resultados' => $res, 'intentos' => $intentos];
            }
        }

        return ['variante' => null, 'query' => null, 'resultados' => [], 'intentos' => $intentos];
    }

    /** Busca albaranes en el label de Calleja, opcionalmente filtrando por texto (OC). */
    public function buscarAlbaranes(string $filtroTexto = '', int $limite = 20): array
    {
        $label = config('ppq.gmail.label_albaranes', 'Calleja_Albaranes');
        $q = trim('label:'.$label.' '.$filtroTexto);

        return $this->listar($q, $limite);
    }

    /**
     * Albaranes del label de Calleja recibidos en una fecha dada (YYYY-MM-DD). Usa
     * el rango Gmail `after:Y/m/d before:(día+1)` para acotar al día completo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarAlbaranesPorFecha(string $fecha, int $limite = 40): array
    {
        $dia = \Illuminate\Support\Carbon::parse($fecha);
        $filtro = 'after:'.$dia->format('Y/m/d').' before:'.$dia->copy()->addDay()->format('Y/m/d');

        return $this->buscarAlbaranes($filtro, $limite);
    }

    /**
     * Diagnóstico de búsqueda: prueba varios queries (incluyendo variantes del
     * número) y devuelve, por cada uno, el query exacto, el estimado de resultados
     * y el detalle de los primeros 10 (asunto, fecha, snippet y adjuntos). NO baja
     * el contenido de los adjuntos, solo sus nombres/tipos.
     *
     * @return array{numero: string, variantes: array<int, string>, consultas: array<int, array<string, mixed>>}
     */
    public function diagnosticar(string $numero, int $limite = 10): array
    {
        $gmail = new Gmail($this->clienteAutenticado());
        $variantes = $this->variantesNumero($numero);
        $label = config('ppq.gmail.label_albaranes', 'Calleja_Albaranes');

        // Queries a probar (etiqueta => query Gmail).
        $queries = [
            'Enviados + número tal cual' => 'in:sent '.$numero,
        ];
        foreach ($variantes as $v) {
            if ($v !== $numero) {
                $queries['Enviados + variante '.$v] = 'in:sent '.$v;
            }
        }
        $queries['Enviados + número entre comillas'] = 'in:sent "'.$numero.'"';
        $queries['En cualquier carpeta + número'] = 'in:anywhere '.$numero;
        $queries['Solo «in:sent» (ver total de enviados)'] = 'in:sent';
        $queries['Label albaranes + número'] = 'label:'.$label.' '.$numero;

        $consultas = [];
        foreach ($queries as $etiqueta => $q) {
            $consultas[] = $this->ejecutarConsultaDiag($gmail, $etiqueta, $q, $limite);
        }

        return ['numero' => $numero, 'variantes' => $variantes, 'consultas' => $consultas];
    }

    /** @return array<int, string> variantes de número a buscar (crudo, dígitos, padded 15/16) */
    public function variantesNumero(string $numero): array
    {
        $digitos = preg_replace('/\D/', '', $numero);
        $sinCeros = ltrim($digitos, '0') ?: '0';
        $set = [
            $numero,
            $digitos,
            str_pad($sinCeros, 15, '0', STR_PAD_LEFT),
            str_pad($sinCeros, 16, '0', STR_PAD_LEFT),
        ];

        return array_values(array_unique(array_filter($set, fn ($v) => $v !== '')));
    }

    /** @return array<string, mixed> */
    private function ejecutarConsultaDiag(Gmail $gmail, string $etiqueta, string $q, int $limite): array
    {
        try {
            $lista = $gmail->users_messages->listUsersMessages('me', ['q' => $q, 'maxResults' => $limite]);
        } catch (\Throwable $e) {
            return ['etiqueta' => $etiqueta, 'query' => $q, 'error' => $e->getMessage(), 'estimado' => null, 'resultados' => []];
        }

        $resultados = [];
        foreach ($lista->getMessages() ?? [] as $m) {
            $full = $gmail->users_messages->get('me', $m->getId(), ['format' => 'full']);
            $adjuntos = [];
            $this->recorrerPartes($full->getPayload(), function ($p) use (&$adjuntos) {
                if ($p->getFilename()) {
                    $adjuntos[] = ['filename' => $p->getFilename(), 'mime' => (string) $p->getMimeType()];
                }
            });
            $resultados[] = [
                'id' => $m->getId(),
                'asunto' => $this->header($full, 'Subject'),
                'fecha' => $this->header($full, 'Date'),
                'snippet' => mb_substr((string) $full->getSnippet(), 0, 160),
                'adjuntos' => $adjuntos,
            ];
        }

        return [
            'etiqueta' => $etiqueta,
            'query' => $q,
            'estimado' => $lista->getResultSizeEstimate(),
            'resultados' => $resultados,
        ];
    }

    /**
     * Adjuntos de un mensaje. @return array<int, array{filename: string, mime: string, data: string}>
     */
    public function adjuntos(string $messageId): array
    {
        $gmail = new Gmail($this->clienteAutenticado());
        $mensaje = $gmail->users_messages->get('me', $messageId, ['format' => 'full']);
        $salida = [];
        $this->recorrerPartes($mensaje->getPayload(), function ($parte) use (&$salida, $gmail, $messageId) {
            $filename = $parte->getFilename();
            $body = $parte->getBody();
            if (! $filename || ! $body || ! $body->getAttachmentId()) {
                return;
            }
            $adj = $gmail->users_messages_attachments->get('me', $messageId, $body->getAttachmentId());
            $salida[] = [
                'filename' => $filename,
                'mime' => (string) $parte->getMimeType(),
                'data' => $this->decodeUrl((string) $adj->getData()),
            ];
        });

        return $salida;
    }

    // ---------------------------------------------------------------- internos

    /** @return array<int, array{id: string, snippet: string}> */
    protected function listar(string $q, int $limite): array
    {
        $gmail = new Gmail($this->clienteAutenticado());
        $lista = $gmail->users_messages->listUsersMessages('me', ['q' => $q, 'maxResults' => $limite]);
        $salida = [];
        foreach ($lista->getMessages() ?? [] as $m) {
            $full = $gmail->users_messages->get('me', $m->getId(), ['format' => 'metadata', 'metadataHeaders' => ['Subject', 'Date']]);
            $salida[] = [
                'id' => $m->getId(),
                'snippet' => (string) $full->getSnippet(),
                'asunto' => $this->header($full, 'Subject'),
                'fecha' => $this->header($full, 'Date'),
            ];
        }

        return $salida;
    }

    private function clienteBase(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId((string) config('ppq.gmail.client_id'));
        $client->setClientSecret((string) config('ppq.gmail.client_secret'));
        $client->setRedirectUri((string) config('ppq.gmail.redirect_uri'));
        $client->setScopes([Gmail::GMAIL_READONLY]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    /** Cliente con token válido (refresca y persiste si expiró). */
    private function clienteAutenticado(): GoogleClient
    {
        $cuenta = GmailCuenta::actual();
        if (! $cuenta || ! $cuenta->conectada()) {
            throw new \RuntimeException('Gmail no está conectado. Autorizá la cuenta primero.');
        }

        $client = $this->clienteBase();
        $token = json_decode((string) $cuenta->access_token, true) ?: [];
        if ($token !== []) {
            $client->setAccessToken($token);
        }

        if ($client->isAccessTokenExpired() && filled($cuenta->refresh_token)) {
            $nuevo = $client->fetchAccessTokenWithRefreshToken($cuenta->refresh_token);
            if (! isset($nuevo['error'])) {
                $nuevo['refresh_token'] ??= $cuenta->refresh_token; // Google no lo reenvía
                $this->guardarToken($nuevo, $cuenta->email, $cuenta->conectado_por);
                $client->setAccessToken($nuevo);
            }
        }

        return $client;
    }

    private function guardarToken(array $token, ?string $email, ?int $userId): GmailCuenta
    {
        $cuenta = GmailCuenta::actual() ?? new GmailCuenta();
        $cuenta->fill([
            'email' => $email ?? $cuenta->email,
            'access_token' => json_encode($token),
            'refresh_token' => $token['refresh_token'] ?? $cuenta->refresh_token,
            'expires_at' => isset($token['expires_in']) ? Carbon::now()->addSeconds((int) $token['expires_in']) : null,
            'scopes' => Gmail::GMAIL_READONLY,
            'conectado_por' => $userId ?? $cuenta->conectado_por,
        ]);
        $cuenta->save();

        return $cuenta;
    }

    private function recorrerPartes(?object $parte, callable $cb): void
    {
        if (! $parte) {
            return;
        }
        $cb($parte);
        foreach ($parte->getParts() ?? [] as $sub) {
            $this->recorrerPartes($sub, $cb);
        }
    }

    private function header(object $mensaje, string $nombre): ?string
    {
        foreach ($mensaje->getPayload()?->getHeaders() ?? [] as $h) {
            if (strcasecmp($h->getName(), $nombre) === 0) {
                return $h->getValue();
            }
        }

        return null;
    }

    private function decodeUrl(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
