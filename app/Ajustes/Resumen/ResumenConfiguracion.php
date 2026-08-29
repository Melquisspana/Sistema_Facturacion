<?php

namespace App\Ajustes\Resumen;

use App\Ajustes\Ajustes;
use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Ajustes\Correo\PruebaConexionSmtp;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Fiscal\EstadoFirmador;
use App\Ajustes\Fiscal\EstadoHaciendaApi;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Models\GmailCuenta;
use App\Models\RespaldoEjecucion;
use App\Models\VerificacionConfiguracion;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Sistema\NotificacionesRespaldo;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Compone la pantalla Resumen: el estado de configuración del sistema entero,
 * en una sola vista y SIN capacidad de cambiar nada.
 *
 * TRES REGLAS QUE GOBIERNAN TODO ESTE ARCHIVO
 * ------------------------------------------------------------------
 * 1. NUNCA hay red. Ni un ping al firmador, ni un login a Hacienda, ni una
 *    conexión SMTP. Es la misma decisión que ya tomó DiagnosticoSistemaService y
 *    por el mismo motivo: una pantalla de estado que se cuelga esperando a un
 *    servicio externo deja de ser una pantalla de estado. Lo que sí hubo se
 *    consulta en el historial de verificaciones, que alguien disparó a mano.
 *
 * 2. NUNCA hay secretos. Ni valores, ni longitudes, ni fragmentos. De una
 *    credencial solo se dice si está y de dónde sale.
 *
 * 3. NUNCA se inventa un estado. Si el sistema no puede saber si algo funciona
 *    —y sin red casi nunca puede—, la tarjeta dice qué está configurado y deja
 *    claro que eso no es lo mismo que "funciona". Un verde falso en esta pantalla
 *    es peor que no tener la pantalla.
 *
 * Las tarjetas sin pantalla propia todavía se muestran SIN botón. Un enlace muerto
 * enseña al usuario a desconfiar del resto de los enlaces.
 */
class ResumenConfiguracion
{
    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly ConfiguracionCorreoRuntime $correo,
        private readonly RegistroVerificaciones $verificaciones,
        private readonly DteTransmisionService $transmision,
        private readonly DteTransmisionAuthService $auth,
    ) {}

    /** @return array<int, TarjetaResumen> */
    public function tarjetas(): array
    {
        $ultimas = $this->verificaciones->ultimasDe([
            PruebaConexionSmtp::CLAVE,
            EstadoFirmador::CLAVE_VERIFICACION,
            EstadoHaciendaApi::CLAVE_VERIFICACION,
        ]);

        return [
            $this->ambienteFiscal(),
            $this->modoOperacion(),
            $this->firmador($ultimas[EstadoFirmador::CLAVE_VERIFICACION] ?? null),
            $this->apiHacienda($ultimas[EstadoHaciendaApi::CLAVE_VERIFICACION] ?? null),
            $this->smtp($ultimas[PruebaConexionSmtp::CLAVE] ?? null),
            $this->gmail(),
            $this->imap(),
            $this->respaldos(),
            $this->cola(),
            $this->planta(),
            $this->rutas(),
        ];
    }

    /**
     * Tarjetas que piden atención, para el aviso de cabecera.
     *
     * @return array<int, TarjetaResumen>
     */
    public function requierenAtencion(): array
    {
        return array_values(array_filter(
            $this->tarjetas(),
            static fn (TarjetaResumen $t) => $t->estado->requiereAtencion(),
        ));
    }

    // ------------------------------------------------------------ fiscal

    private function ambienteFiscal(): TarjetaResumen
    {
        $ambiente = (string) $this->ajustes->texto('dte.ambiente', '00');
        $esProduccion = $ambiente === '01';

        return new TarjetaResumen(
            clave: 'ambiente_fiscal',
            titulo: 'Ambiente fiscal',
            estado: EstadoTarjeta::SoloLectura,
            detalle: $esProduccion
                ? 'PRODUCCIÓN (01): los documentos que se emitan son fiscalmente válidos.'
                : 'PRUEBAS (00): los documentos NO tienen validez fiscal.',
            lineas: ['Código CAT-001: '.$ambiente],
            fuente: $this->fuente('dte.ambiente'),
            advertencia: 'Solo se cambia desde el archivo de configuración del servidor.',
            ruta: $this->ruta('configuracion.fiscal.hacienda'),
            etiquetaRuta: 'Ver detalle',
        );
    }

    private function modoOperacion(): TarjetaResumen
    {
        $estado = $this->transmision->estadoOperativo();

        return new TarjetaResumen(
            clave: 'modo_dte',
            titulo: 'Modo de operación DTE',
            // Se reutiliza el color que ya calcula el motor en vez de recalcularlo:
            // dos criterios distintos para "¿esto es peligroso?" acabarían diciendo
            // cosas distintas en dos pantallas del mismo sistema.
            estado: match ($estado['color'] ?? 'ok') {
                'critico' => EstadoTarjeta::Error,
                'advertencia' => EstadoTarjeta::Advertencia,
                default => EstadoTarjeta::SoloLectura,
            },
            detalle: (string) ($estado['detalle'] ?? ''),
            lineas: ['Modo: '.($estado['etiqueta'] ?? 'desconocido')],
            fuente: 'Archivo de configuración / .env',
        );
    }

    private function firmador(?VerificacionConfiguracion $ultima): TarjetaResumen
    {
        $habilitada = (bool) config('dte.firma.enabled', false);
        $mock = (bool) config('dte.firma.mock', false);
        $nit = (string) config('dte.firma.nit', '');

        $lineas = ['Servicio: '.(string) config('dte.firmador.url', 'sin definir')];
        $lineas[] = 'NIT del certificado: '.($nit !== '' ? $nit : 'sin definir');
        $lineas[] = 'Contraseña del certificado: '.(filled(config('dte.firma.cert_password')) ? 'configurada' : 'sin configurar');

        return new TarjetaResumen(
            clave: 'firmador',
            titulo: 'Firmador',
            estado: match (true) {
                $habilitada && $mock => EstadoTarjeta::Advertencia,
                $habilitada => EstadoTarjeta::Activo,
                default => EstadoTarjeta::Desactivado,
            },
            detalle: match (true) {
                $habilitada && $mock => 'Firma habilitada en MODO SIMULADO: los documentos llevan una firma ficticia, sin validez.',
                $habilitada => 'Firma electrónica habilitada con el firmador local del Ministerio de Hacienda.',
                default => 'Firma deshabilitada: el sistema genera el documento pero no lo firma.',
            },
            lineas: $lineas,
            fuente: 'Archivo de configuración / .env',
            ultimaVerificacion: $ultima?->created_at,
            resultadoVerificacion: $ultima?->resultado->etiqueta(),
            // Sin ping: lo único honesto que se puede decir es qué hay configurado.
            // Lo que sí hubo se lee del historial, que alguien disparó a mano desde
            // la pantalla del firmador.
            advertencia: 'Estado declarado en la configuración; esta pantalla no comprueba si el servicio responde.',
            ruta: $this->ruta('configuracion.fiscal.firmador'),
            etiquetaRuta: 'Ver detalle',
        );
    }

    private function apiHacienda(?VerificacionConfiguracion $ultima): TarjetaResumen
    {
        $habilitada = (bool) config('dte.transmision.enabled', false);
        $ambiente = strtolower((string) config('dte.transmision.ambiente', 'testing'));

        // fuenteCredenciales() responde si HAY credenciales y de qué juego salen,
        // nunca cuáles son. Por eso se usa esto y no se leen las claves acá.
        $fuente = $this->auth->fuenteCredenciales();

        [$estado, $detalle] = match ($fuente) {
            'ninguna' => [EstadoTarjeta::NoConfigurado, 'No hay credenciales configuradas para el ambiente activo.'],
            'parcial' => [EstadoTarjeta::Error, 'Configuración incompleta: hay usuario o contraseña, pero no las dos.'],
            'legacy' => [EstadoTarjeta::Advertencia, 'Credenciales de producción tomadas de las variables antiguas de respaldo.'],
            default => [EstadoTarjeta::Configurado, 'Credenciales configuradas para el ambiente activo.'],
        };

        return new TarjetaResumen(
            clave: 'hacienda',
            titulo: 'API Hacienda',
            estado: $estado,
            detalle: $detalle,
            lineas: [
                'Ambiente: '.($ambiente === 'produccion' ? 'PRODUCCIÓN' : 'APITEST (pruebas)'),
                'Transmisión: '.($habilitada ? 'habilitada' : 'deshabilitada'),
            ],
            fuente: 'Archivo de configuración / .env',
            ultimaVerificacion: $ultima?->created_at,
            resultadoVerificacion: $ultima?->resultado->etiqueta(),
            advertencia: 'Las credenciales del Ministerio de Hacienda no se editan desde la aplicación, y no es algo pendiente: con ellas se emiten documentos fiscales.',
            ruta: $this->ruta('configuracion.fiscal.hacienda'),
            etiquetaRuta: 'Ver detalle',
        );
    }

    // ------------------------------------------------------------- correo

    private function smtp(?VerificacionConfiguracion $ultima): TarjetaResumen
    {
        $estadoSmtp = $this->correo->estadoActual();
        $host = $estadoSmtp['host'];
        $configurado = filled($host);

        $lineas = [];
        if ($configurado) {
            $lineas[] = 'Servidor: '.$host.($estadoSmtp['port'] ? ':'.$estadoSmtp['port'] : '');
        }
        if (filled($estadoSmtp['username'])) {
            $lineas[] = 'Usuario: '.$estadoSmtp['username'];
        }
        // De la contraseña, solo si está. Nunca el valor, la longitud ni un trozo.
        $lineas[] = 'Contraseña: '.($estadoSmtp['password_configurada'] ? 'configurada' : 'sin configurar');
        if (filled($estadoSmtp['from_address'])) {
            $lineas[] = 'Remitente: '.$estadoSmtp['from_address'];
        }
        $lineas[] = 'Medio de envío activo: '.($estadoSmtp['mailer'] ?? 'sin definir');

        return new TarjetaResumen(
            clave: 'smtp',
            titulo: 'Servidor de correo (SMTP)',
            estado: match (true) {
                ! $configurado => EstadoTarjeta::NoConfigurado,
                $ultima !== null && ! $ultima->exitosa() => EstadoTarjeta::Error,
                default => EstadoTarjeta::Configurado,
            },
            detalle: $configurado
                ? 'Datos del servidor de correo saliente guardados.'
                : 'Sin servidor de correo configurado: los documentos no se pueden enviar por correo.',
            lineas: $lineas,
            fuente: $this->fuente('mail.smtp.host'),
            ultimaVerificacion: $ultima?->created_at,
            resultadoVerificacion: $ultima?->resultado->etiqueta(),
            advertencia: $ultima === null && $configurado
                ? 'Nunca se ha comprobado la conexión con este servidor.'
                : ($ultima !== null && ! $ultima->exitosa() ? $ultima->mensaje : null),
            ruta: $this->ruta('configuracion.correo.edit'),
            etiquetaRuta: 'Configurar',
        );
    }

    private function gmail(): TarjetaResumen
    {
        $cuenta = $this->intentar(static fn () => GmailCuenta::query()->latest('id')->first());
        $credenciales = filled(config('ppq.gmail.client_id')) && filled(config('ppq.gmail.client_secret'));

        return new TarjetaResumen(
            clave: 'gmail',
            titulo: 'Gmail (Pronto pago)',
            estado: match (true) {
                $cuenta !== null => EstadoTarjeta::Configurado,
                ! $credenciales => EstadoTarjeta::NoConfigurado,
                default => EstadoTarjeta::Advertencia,
            },
            detalle: match (true) {
                $cuenta !== null => 'Buzón conectado para la lectura de albaranes.',
                ! $credenciales => 'Faltan las credenciales de la aplicación de Google.',
                default => 'Credenciales presentes, pero ninguna cuenta conectada todavía.',
            },
            lineas: array_filter([
                $cuenta !== null ? 'Cuenta: '.$cuenta->email : null,
                'Credenciales de la aplicación: '.($credenciales ? 'configuradas' : 'sin configurar'),
            ]),
            fuente: 'Archivo de configuración / .env + base de datos',
            // La conexión de Gmail vive en PPQ; el Resumen enlaza a lo que existe.
            ruta: $this->ruta('ppq.gmail.conectar'),
            etiquetaRuta: 'Conectar',
        );
    }

    private function imap(): TarjetaResumen
    {
        $driver = strtolower((string) config('documentos_recibidos.mail.driver', 'none'));
        $host = (string) config('documentos_recibidos.mail.host', '');
        $usuario = (string) config('documentos_recibidos.mail.username', '');
        $password = (string) config('documentos_recibidos.mail.password', '');
        $completo = $driver === 'imap' && filled($host) && filled($usuario) && filled($password);

        return new TarjetaResumen(
            clave: 'imap',
            titulo: 'Buzón de compras (IMAP)',
            estado: match (true) {
                $driver !== 'imap' => EstadoTarjeta::Desactivado,
                $completo => EstadoTarjeta::Configurado,
                default => EstadoTarjeta::NoConfigurado,
            },
            detalle: match (true) {
                $driver !== 'imap' => 'Lectura del buzón desactivada: los documentos recibidos se cargan a mano.',
                $completo => 'Buzón configurado para la lectura de documentos de compra.',
                default => 'Lectura activada pero faltan datos de conexión.',
            },
            lineas: array_filter([
                filled($host) ? 'Servidor: '.$host.':'.(int) config('documentos_recibidos.mail.port') : null,
                filled($usuario) ? 'Usuario: '.$usuario : null,
                'Contraseña: '.(filled($password) ? 'configurada' : 'sin configurar'),
            ]),
            fuente: 'Archivo de configuración / .env',
        );
    }

    // ------------------------------------------------------------ sistema

    private function respaldos(): TarjetaResumen
    {
        $ultima = $this->intentar(static fn () => RespaldoEjecucion::ultima());
        $hayHoy = (bool) $this->intentar(static fn () => RespaldoEjecucion::hayValidoHoy(), false);
        $avisos = NotificacionesRespaldo::configurado();

        $lineas = [];
        if ($ultima !== null) {
            $lineas[] = 'Última ejecución: '.$ultima->terminado_en?->format('d/m/Y H:i').
                ' ('.($ultima->exitoso ? 'correcta' : 'con error').')';
        }
        $lineas[] = 'Avisos por correo: '.($avisos ? 'configurados' : 'sin configurar');
        $lineas[] = 'Retención: '.$this->ajustes->entero('respaldos.dias_retencion', 30).' días';

        return new TarjetaResumen(
            clave: 'respaldos',
            titulo: 'Respaldos',
            estado: match (true) {
                ! $hayHoy => EstadoTarjeta::Error,
                ! $avisos => EstadoTarjeta::Advertencia,
                default => EstadoTarjeta::Activo,
            },
            detalle: $hayHoy
                ? 'Hay un respaldo válido de hoy.'
                : 'No hay ningún respaldo válido registrado hoy.',
            lineas: $lineas,
            fuente: $this->fuente('respaldos.dias_retencion'),
            ultimaVerificacion: $ultima?->terminado_en,
            resultadoVerificacion: $ultima === null ? null : ($ultima->exitoso ? 'Correcto' : 'Con error'),
            advertencia: $avisos ? null : 'Si un respaldo falla, nadie recibirá el aviso.',
        );
    }

    private function cola(): TarjetaResumen
    {
        $worker = WorkerHeartbeat::diagnostico();
        $fallidos = (int) $this->intentar(static fn () => DB::table('failed_jobs')->count(), 0);

        return new TarjetaResumen(
            clave: 'cola',
            titulo: 'Cola de trabajos',
            estado: match (true) {
                $fallidos > 0 => EstadoTarjeta::Error,
                ($worker['nivel'] ?? '') === 'critico' => EstadoTarjeta::Error,
                ($worker['nivel'] ?? '') === 'advertencia' => EstadoTarjeta::Advertencia,
                default => EstadoTarjeta::Activo,
            },
            detalle: (string) ($worker['mensaje'] ?? 'Sin información del worker.'),
            lineas: [
                'Conexión: '.(string) config('queue.default'),
                'Trabajos fallidos: '.$fallidos,
            ],
            fuente: 'Archivo de configuración / .env',
            advertencia: $fallidos > 0
                ? 'Hay trabajos fallidos: puede haber correos que nunca salieron.'
                : null,
        );
    }

    // ------------------------------------------------------------- módulos

    private function planta(): TarjetaResumen
    {
        $activo = (bool) config('planta.enabled', false);

        return new TarjetaResumen(
            clave: 'planta',
            titulo: 'Producción (Planta)',
            estado: $activo ? EstadoTarjeta::Activo : EstadoTarjeta::Desactivado,
            detalle: $activo
                ? 'Módulo activo: el área aparece en el selector y sus pantallas responden.'
                : 'Módulo desactivado: sus pantallas responden 404 para todos los roles.',
            fuente: 'Archivo de configuración / .env',
        );
    }

    private function rutas(): TarjetaResumen
    {
        // El módulo no tiene interruptor propio: existe siempre y se gobierna por
        // permisos. Decirlo es más útil que fabricar un "activo" que no representa
        // ninguna decisión de configuración.
        return new TarjetaResumen(
            clave: 'rutas',
            titulo: 'Rutas / Cobros',
            estado: EstadoTarjeta::Activo,
            detalle: 'Módulo disponible; el acceso lo gobiernan los permisos de cada rol.',
            lineas: [
                'Serie con asignación automática: '.(string) config('rutas.punto_venta_automatico', 'sin definir'),
                'Ventana de candidatos: '.(int) config('rutas.candidatos_dias_antes').' / '
                    .(int) config('rutas.candidatos_dias_despues').' días',
            ],
            fuente: 'Archivo de configuración / .env',
        );
    }

    // ---------------------------------------------------------------- interno

    /** Rótulo humano de la fuente de un ajuste registrado. */
    private function fuente(string $clave): string
    {
        return match ($this->ajustes->fuente($clave)) {
            FuenteAjuste::BaseDeDatos, FuenteAjuste::BaseDeDatosLegacy => 'Guardado en la aplicación',
            FuenteAjuste::Configuracion => 'Archivo de configuración / .env',
            FuenteAjuste::Defecto => 'Valor por defecto del sistema',
            FuenteAjuste::NoConfigurado => 'Sin configurar',
        };
    }

    /** URL de una ruta, o null si no existe todavía. Evita enlaces muertos. */
    private function ruta(string $nombre): ?string
    {
        return Route::has($nombre) ? route($nombre) : null;
    }

    /**
     * Ejecuta una consulta de estado tolerando el fallo.
     *
     * El Resumen es una pantalla de diagnóstico: si la tabla de una tarjeta no
     * existe todavía o la consulta revienta, lo que NO puede pasar es que se caiga
     * la pantalla entera y deje al administrador sin ver el resto del sistema.
     */
    private function intentar(callable $consulta, mixed $porDefecto = null): mixed
    {
        try {
            return $consulta();
        } catch (Throwable) {
            return $porDefecto;
        }
    }
}
