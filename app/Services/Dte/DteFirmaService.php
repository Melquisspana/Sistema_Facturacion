<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Models\Dte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Firma del JSON oficial de un DTE — FASE DE PREPARACIÓN.
 *
 * En esta fase el servicio NO firma ni transmite nada: deja diseñado y validado
 * todo el camino previo a la firma (precondiciones, lectura del JSON generado,
 * configuración) y se detiene de forma segura antes de ejecutar la firma real.
 *
 * Diseño previsto para la fase futura (cuando 'dte.firma.enabled' = true):
 *  1. Leer el JSON oficial de json_generado_path (ya validado contra el schema).
 *  2. Enviar al firmador local del MH (config 'firmador.url') el payload
 *     { nit, activo, passwordPri, dteJson } usando el NIT del emisor y la clave
 *     del certificado (DTE_CERT_PASSWORD, nunca en el repo).
 *  3. Recibir el documento firmado (JWS compacto) que devuelve el firmador.
 *  4. Validar que el firmado CORRESPONDA al DTE (el codigoGeneracion del payload
 *     del JWS coincide con $dte->codigo_generacion).
 *  5. Guardar el JWS en disco y setear json_firmado_path (en transacción).
 *
 * Al firmar OK avanza el estado Generado → Firmado por la DteStateMachine (con
 * historial). Mantiene json_firmado_path como evidencia.
 *
 * Lo que NUNCA hace (no es su responsabilidad):
 *  - NO transmite a Hacienda · NO guarda sello_recepcion · NO marca como aceptado.
 *    (El envío y la aceptación viven en la fase de transmisión.)
 */
class DteFirmaService
{
    public function __construct(private readonly DteStateMachine $maquina) {}

    /**
     * Diagnóstico de SOLO LECTURA: ¿está el DTE listo para firmar? No toca BD,
     * no lee credenciales, no firma. Lo usa el comando `dte:firma-check`.
     *
     * @return array{
     *     listo: bool,
     *     checks: array<int, array{ok: bool, etiqueta: string, detalle: string}>,
     *     problemas: array<int, string>
     * }
     */
    public function diagnosticar(Dte $dte): array
    {
        $checks = [];
        $problemas = [];

        $add = function (bool $ok, string $etiqueta, string $detalle) use (&$checks, &$problemas) {
            $checks[] = ['ok' => $ok, 'etiqueta' => $etiqueta, 'detalle' => $detalle];
            if (! $ok) {
                $problemas[] = $etiqueta.': '.$detalle;
            }
        };

        // 1. Estado generado (no borrador, no ya emitido).
        $esGenerado = $dte->estado === EstadoDte::Generado;
        $add($esGenerado, 'Estado generado', $esGenerado
            ? 'El documento está en estado generado.'
            : 'El estado actual es '.$dte->estado->label().' (se requiere generado).');

        // 2. Numeración oficial asignada.
        $tieneNumeracion = filled($dte->numero_control) && filled($dte->codigo_generacion);
        $add($tieneNumeracion, 'Numeración oficial', $tieneNumeracion
            ? 'numero_control y codigo_generacion presentes.'
            : 'Falta numero_control y/o codigo_generacion (genere primero el JSON).');

        // 3. Tiene json_generado_path.
        $tieneJsonPath = filled($dte->json_generado_path);
        $add($tieneJsonPath, 'JSON generado (ruta)', $tieneJsonPath
            ? $dte->json_generado_path
            : 'No tiene json_generado_path (genere el JSON oficial preliminar primero).');

        // 4. El archivo JSON existe en disco.
        $disco = (string) config('dte.storage.disk', 'local');
        $archivoExiste = $tieneJsonPath && $this->rutaJsonValida($dte->json_generado_path)
            && Storage::disk($disco)->exists($this->normalizar($dte->json_generado_path));
        $add($archivoExiste, 'Archivo JSON en disco', $archivoExiste
            ? 'El archivo del JSON generado existe.'
            : 'No se encontró el archivo del JSON generado en el almacenamiento.');

        // 5. NO debe estar ya firmado.
        $sinFirmar = blank($dte->json_firmado_path);
        $add($sinFirmar, 'Sin firmar todavía', $sinFirmar
            ? 'json_firmado_path vacío (aún no firmado).'
            : 'Ya tiene json_firmado_path ('.$dte->json_firmado_path.'); requeriría --force.');

        // 6. Configuración de firma presente (URL del firmador + bloque de firma).
        $urlFirmador = (string) config('dte.firmador.url', '');
        $configOk = filled($urlFirmador);
        $habilitada = (bool) config('dte.firma.enabled', false);
        $add($configOk, 'Configuración del firmador', $configOk
            ? 'firmador.url='.$urlFirmador.' · firma '.($habilitada ? 'HABILITADA' : 'deshabilitada (preparación)')
            : 'Falta dte.firmador.url (configure DTE_FIRMADOR_URL).');

        return [
            'listo' => $problemas === [],
            'checks' => $checks,
            'problemas' => $problemas,
        ];
    }

    /**
     * Health check del firmador local (GET al status). SOLO LECTURA: no firma,
     * no envía JSON, no usa contraseñas, no toca BD. Maneja timeout/conexión
     * rechazada sin romper feo.
     *
     * @return array{
     *     disponible: bool,
     *     url: string,
     *     status: int|null,
     *     mensaje: string
     * }
     */
    public function healthCheck(): array
    {
        $url = $this->urlStatus();
        $timeout = (int) config('dte.firma.timeout', 10);

        try {
            $resp = Http::timeout($timeout)->acceptJson()->get($url);
            $cuerpo = trim($resp->body());

            return [
                'disponible' => $resp->successful(),
                'url' => $url,
                'status' => $resp->status(),
                'mensaje' => $cuerpo !== '' ? $cuerpo : 'Respuesta vacía.',
            ];
        } catch (Throwable $e) {
            // Conexión rechazada, timeout, DNS, etc.: no se propaga, se reporta.
            return [
                'disponible' => false,
                'url' => $url,
                'status' => null,
                'mensaje' => 'No se pudo conectar con el firmador: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Prueba CONTROLADA del endpoint POST de firma con un payload FAKE/sandbox.
     * NO firma ningún DTE real: usa NIT de prueba, contraseña fake y un dteJson
     * mínimo inventado. Se ESPERA un error controlado del firmador (certificado/
     * llave no encontrada, datos requeridos, password incorrecto): eso confirma
     * que el endpoint está vivo y procesa POST.
     *
     * No lee DTE, no toca BD, no usa json_generado_path, no usa certificados ni
     * contraseñas reales. No depende de 'dte.firma.enabled'.
     *
     * @return array{
     *     url: string,
     *     procesa: bool,
     *     http_status: int|null,
     *     firmador_status: string|null,
     *     codigo: string|null,
     *     mensaje: string|null,
     *     firmo: bool,
     *     payload: array<string, mixed>
     * }
     */
    public function postTest(): array
    {
        $url = $this->urlFirma();
        $timeout = (int) config('dte.firma.timeout', 10);

        // Payload CLARAMENTE de prueba. Nada real: ni NIT, ni clave, ni DTE.
        $payload = [
            'nit' => '00000000000000',              // 14 dígitos, formato válido pero sin certificado
            'activo' => true,
            'passwordPri' => 'FAKE_PASSWORD_NO_REAL', // contraseña fake, no real
            'dteJson' => [
                '_prueba' => 'PAYLOAD DE PRUEBA - NO ES UN DTE REAL',
                'identificacion' => ['version' => 0, 'ambiente' => '00', 'tipoDte' => '00'],
            ],
        ];

        try {
            $resp = Http::timeout($timeout)->acceptJson()->post($url, $payload);
        } catch (Throwable $e) {
            return [
                'url' => $url,
                'procesa' => false,
                'http_status' => null,
                'firmador_status' => null,
                'codigo' => null,
                'mensaje' => 'No se pudo conectar con el firmador: '.$e->getMessage(),
                'firmo' => false,
                'payload' => $this->enmascarar($payload),
            ];
        }

        $cuerpo = $resp->json();
        $firmadorStatus = is_array($cuerpo) ? ($cuerpo['status'] ?? null) : null;
        $codigo = null;
        $mensaje = null;

        if (is_array($cuerpo)) {
            $body = $cuerpo['body'] ?? null;
            if (is_array($body)) {
                $codigo = isset($body['codigo']) ? (string) $body['codigo'] : null;
                $mensaje = isset($body['mensaje']) ? (string) $body['mensaje'] : null;
            } elseif (is_string($body)) {
                $mensaje = 'El firmador devolvió un documento firmado (JWS).';
            }
        } else {
            $mensaje = trim((string) $resp->body());
        }

        // El endpoint "procesa" si respondió HTTP 200 (el firmador responde 200
        // incluso para errores lógicos). 'firmo' solo si devolvió status OK.
        $firmo = $firmadorStatus === 'OK';
        $procesa = $resp->successful();

        return [
            'url' => $url,
            'procesa' => $procesa,
            'http_status' => $resp->status(),
            'firmador_status' => $firmadorStatus,
            'codigo' => $codigo,
            'mensaje' => $mensaje !== null && $mensaje !== '' ? $mensaje : 'Sin mensaje.',
            'firmo' => $firmo,
            'payload' => $this->enmascarar($payload),
        ];
    }

    /** Oculta la contraseña en el resumen del payload (aunque sea fake). */
    private function enmascarar(array $payload): array
    {
        $payload['passwordPri'] = '***';

        return $payload;
    }

    /**
     * Endpoint POST del firmador. ÚNICA fuente de la URL del firmador:
     * `config('dte.firmador.url')` (ej. http://localhost:8080/firmardocumento). Se le
     * agrega la barra final que usa el firmador del MH (.../firmardocumento/).
     */
    private function urlFirma(): string
    {
        return rtrim((string) config('dte.firmador.url'), '/').'/';
    }

    /** Health check (GET): el mismo endpoint del firmador + /status. */
    private function urlStatus(): string
    {
        return rtrim((string) config('dte.firmador.url'), '/').'/status';
    }

    /**
     * Firma LOCAL del JSON oficial del DTE con el firmador del MH. Toma el JSON
     * generado, lo envía al firmador local, recibe el JWS firmado y lo guarda en
     * dte/firmados/ + json_firmado_path. Todo en transacción.
     *
     * Firmar localmente NO es emitir: NO transmite a Hacienda, NO guarda sello,
     * NO cambia el estado a aceptado. La contraseña del certificado se lee de
     * .env y NUNCA se imprime ni se incluye en mensajes de error.
     *
     * @return array{ruta: string, numeroControl: ?string, codigoGeneracion: ?string}
     *
     * @throws DteFirmaException             si falla una precondición o el firmador rechaza
     * @throws DteFirmaDeshabilitadaException si la firma no está habilitada
     */
    public function firmar(Dte $dte, bool $force = false): array
    {
        $this->verificarPrecondiciones($dte, $force);

        // MODO MOCK (local): simula la firma sin firmador real, sin certificado ni
        // claves. Genera un JWS FICTICIO marcado. No vale ante Hacienda. Se omite el
        // interruptor maestro y las credenciales a propósito (es una simulación).
        if ((bool) config('dte.firma.mock', false)) {
            return $this->firmarMock($dte);
        }

        // Interruptor maestro: sin esto no se firma.
        if (! (bool) config('dte.firma.enabled', false)) {
            throw new DteFirmaDeshabilitadaException(
                'La firma está deshabilitada (dte.firma.enabled=false). El documento está '
                .'listo para firmar, pero la firma no está habilitada. No se firmó nada.'
            );
        }

        // Credenciales desde .env (nunca desde código). No se imprimen.
        $nit = trim((string) config('dte.firma.nit', ''));
        $password = (string) config('dte.firma.cert_password', '');
        if ($nit === '') {
            throw new DteFirmaException('Falta el NIT del emisor para firmar (configure DTE_FIRMA_NIT en .env).');
        }
        if ($password === '') {
            throw new DteFirmaException('Falta la contraseña del certificado para firmar (configure DTE_CERT_PASSWORD en .env).');
        }

        // Leer el JSON oficial ya generado (validado contra el schema).
        $disco = (string) config('dte.storage.disk', 'local');
        $rutaJson = $this->normalizar($dte->json_generado_path);
        $dteJson = json_decode((string) Storage::disk($disco)->get($rutaJson), true);
        if (! is_array($dteJson)) {
            throw new DteFirmaException('El JSON generado no se pudo leer o decodificar.');
        }

        // Todo en transacción: si algo falla, no queda json_firmado_path a medias.
        return DB::transaction(function () use ($dte, $nit, $password, $dteJson, $disco) {
            $jws = $this->enviarAlFirmador($nit, $password, $dteJson);

            if (trim($jws) === '') {
                throw new DteFirmaException('El firmador no devolvió un documento firmado (JWS vacío).');
            }

            $carpeta = trim((string) config('dte.storage.firmados', 'dte/firmados'), '/');
            $ruta = $carpeta.'/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.jws';
            Storage::disk($disco)->put($ruta, $jws);

            // Evidencia (flag) + avance de estado por la máquina: Generado → Firmado.
            // NO toca sello, NO transmite.
            $dte->json_firmado_path = $ruta;
            $dte->save();
            $this->maquina->transicionar($dte, EstadoDte::Firmado, null, 'Firma local del DTE (sin transmisión)');

            activity('dte_firma')
                ->performedOn($dte)
                ->withProperties([
                    'numero_control' => $dte->numero_control,
                    'codigo_generacion' => $dte->codigo_generacion,
                    'ruta' => $ruta,
                ])
                ->log('firmó localmente el DTE (sin transmisión a Hacienda)');

            return [
                'ruta' => $ruta,
                'numeroControl' => $dte->numero_control,
                'codigoGeneracion' => $dte->codigo_generacion,
            ];
        });
    }

    /**
     * Firma REAL de un JSON arbitrario con el firmador local del MH y devuelve el JWS.
     * Pensado para EVENTOS que no son un DTE con estado/almacenamiento propio (p. ej. el
     * evento de invalidación). NO toca BD, NO cambia estado, NO guarda archivos: solo
     * firma y devuelve. La contraseña se lee de .env y NUNCA se imprime.
     *
     * No aplica el modo mock (dte.firma.mock): el mock del evento de invalidación vive en
     * su propio servicio de Fase C. Este método es exclusivamente la firma REAL.
     *
     * @param  array<string, mixed>  $json
     *
     * @throws DteFirmaException             si faltan credenciales o el firmador rechaza
     * @throws DteFirmaDeshabilitadaException si la firma no está habilitada
     */
    public function firmarJson(array $json): string
    {
        if (! (bool) config('dte.firma.enabled', false)) {
            throw new DteFirmaDeshabilitadaException(
                'La firma está deshabilitada (dte.firma.enabled=false). No se firmó el evento.'
            );
        }

        $nit = trim((string) config('dte.firma.nit', ''));
        $password = (string) config('dte.firma.cert_password', '');
        if ($nit === '') {
            throw new DteFirmaException('Falta el NIT del emisor para firmar (configure DTE_FIRMA_NIT en .env).');
        }
        if ($password === '') {
            throw new DteFirmaException('Falta la contraseña del certificado para firmar (configure DTE_CERT_PASSWORD en .env).');
        }

        $jws = $this->enviarAlFirmador($nit, $password, $json);
        if (trim($jws) === '') {
            throw new DteFirmaException('El firmador no devolvió un documento firmado (JWS vacío).');
        }

        return $jws;
    }

    /**
     * Firma SIMULADA (modo mock local). Genera un JWS FICTICIO claramente marcado y
     * lo guarda igual que la firma real (json_firmado_path), pero SIN firmador, SIN
     * certificado y SIN claves. No cambia el estado ni transmite (mismo contrato que
     * la firma real en esta fase). El JWS NO es válido ante Hacienda.
     *
     * @return array{ruta: string, numeroControl: ?string, codigoGeneracion: ?string, mock: bool}
     */
    private function firmarMock(Dte $dte): array
    {
        $b64 = fn (array $d) => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
        $header = $b64(['alg' => 'none', 'mock' => true]);
        $payload = $b64([
            'mock' => true,
            'aviso' => 'FIRMA SIMULADA (MOCK) - NO VÁLIDA ANTE HACIENDA',
            'codigoGeneracion' => $dte->codigo_generacion,
            'numeroControl' => $dte->numero_control,
            'fecha' => now()->toIso8601String(),
        ]);
        $jws = $header.'.'.$payload.'.MOCK-SIN-FIRMA-REAL';

        return DB::transaction(function () use ($dte, $jws) {
            $disco = (string) config('dte.storage.disk', 'local');
            $carpeta = trim((string) config('dte.storage.firmados', 'dte/firmados'), '/');
            $ruta = $carpeta.'/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.mock.jws';
            Storage::disk($disco)->put($ruta, $jws);

            $dte->json_firmado_path = $ruta;
            $dte->save();
            // El mock respeta el flujo real: Generado → Firmado por la máquina de estados.
            $this->maquina->transicionar($dte, EstadoDte::Firmado, null, 'Firma MOCK del DTE (simulada)');

            activity('dte_firma')
                ->performedOn($dte)
                ->withProperties(['ruta' => $ruta, 'mock' => true])
                ->log('firmó el DTE en modo MOCK (simulado, sin firmador ni claves)');

            return [
                'ruta' => $ruta,
                'numeroControl' => $dte->numero_control,
                'codigoGeneracion' => $dte->codigo_generacion,
                'mock' => true,
            ];
        });
    }

    /**
     * POST al firmador local. Devuelve el JWS (body) si status=OK; lanza
     * DteFirmaException con el código/mensaje del firmador si rechaza.
     * NUNCA incluye el payload ni la contraseña en los mensajes de error.
     *
     * @param  array<string, mixed>  $dteJson
     */
    private function enviarAlFirmador(string $nit, string $password, array $dteJson): string
    {
        $url = $this->urlFirma();
        $timeout = (int) config('dte.firma.timeout', 10);

        $payload = [
            'nit' => $nit,
            'activo' => true,
            'passwordPri' => $password, // no se loguea
            'dteJson' => $dteJson,
        ];

        try {
            $resp = Http::timeout($timeout)->acceptJson()->post($url, $payload);
        } catch (Throwable $e) {
            throw new DteFirmaException('No se pudo conectar con el firmador local: '.$e->getMessage());
        }

        $cuerpo = $resp->json();
        if (! $resp->successful() || ! is_array($cuerpo)) {
            throw new DteFirmaException('El firmador respondió HTTP '.$resp->status().' sin un cuerpo válido.');
        }

        if (($cuerpo['status'] ?? null) !== 'OK') {
            $body = $cuerpo['body'] ?? null;
            $codigo = is_array($body) ? ($body['codigo'] ?? '?') : '?';
            $mensaje = is_array($body) ? ($body['mensaje'] ?? 'sin mensaje') : (string) $body;
            throw new DteFirmaException('El firmador rechazó la firma (código '.$codigo.'): '.$mensaje);
        }

        return (string) ($cuerpo['body'] ?? '');
    }

    /**
     * Precondiciones de firma (mismas que valida `diagnosticar`, pero lanzando).
     *
     * @throws DteFirmaException
     */
    private function verificarPrecondiciones(Dte $dte, bool $force): void
    {
        if ($dte->estado !== EstadoDte::Generado) {
            throw new DteFirmaException('Solo se puede firmar un documento en estado generado (actual: '.$dte->estado->label().').');
        }
        if (blank($dte->json_generado_path)) {
            throw new DteFirmaException('El documento no tiene JSON generado (json_generado_path vacío). Genere el JSON oficial primero.');
        }
        if (! $force && filled($dte->json_firmado_path)) {
            throw new DteFirmaException('El documento ya está firmado ('.$dte->json_firmado_path.'). Use force solo para re-firmar.');
        }

        $disco = (string) config('dte.storage.disk', 'local');
        if (! $this->rutaJsonValida($dte->json_generado_path)
            || ! Storage::disk($disco)->exists($this->normalizar($dte->json_generado_path))) {
            throw new DteFirmaException('No se encontró el archivo del JSON generado en el almacenamiento. Regenere el JSON.');
        }
    }

    /** La ruta debe quedar dentro de la carpeta oficial de JSON, sin path traversal. */
    private function rutaJsonValida(?string $ruta): bool
    {
        $carpeta = trim((string) config('dte.storage.json', 'dte/json'), '/');
        $norm = $this->normalizar($ruta);

        return $norm !== '' && ! str_contains($norm, '..') && str_starts_with($norm, $carpeta.'/');
    }

    private function normalizar(?string $ruta): string
    {
        return ltrim(str_replace('\\', '/', (string) $ruta), '/');
    }
}
