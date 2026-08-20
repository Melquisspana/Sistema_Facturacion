<?php

namespace App\Ajustes;

use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\Editabilidad;
use App\Ajustes\Definicion\Impacto;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\Definicion\Sensibilidad;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\AjusteDesconocidoException;
use App\Support\Dte\PlantillaCorreo;

/**
 * LISTA BLANCA de ajustes administrables. Un ajuste que no esté declarado acá
 * NO EXISTE: no se lee, no se escribe, y pedirlo lanza
 * {@see AjusteDesconocidoException}.
 *
 * Esto no es burocracia. Sin lista blanca, `Ajustes::get($request->clave)` deja
 * que el navegador elija qué trozo de configuración leer, y el día que esta capa
 * guarde contraseñas de SMTP y del certificado de firma, "qué claves existen" es
 * exactamente la superficie de ataque. La alternativa —una lista negra— falla
 * hacia el lado equivocado: olvidarse de agregar una clave nueva significaría
 * exponerla.
 *
 * ALCANCE DE ESTA FASE: el catálogo es MÍNIMO y representativo a propósito. Sirve
 * para ejercitar las tres estrategias de persistencia, los tres niveles de
 * confirmación y el camino de los secretos. Las ~140 claves del inventario se
 * incorporan por tandas, cada una con su pantalla y sus pruebas.
 *
 * QUÉ NO DEBE ENTRAR ACÁ NUNCA: APP_KEY, DB_*, credenciales del túnel, SESSION_*,
 * QUEUE_CONNECTION, CACHE_STORE, el disco principal de filesystems y cualquier
 * ruta del servidor. Son configuración de INFRAESTRUCTURA: cambiarlas desde una
 * pantalla web deja la aplicación sin forma de volver atrás por esa misma
 * pantalla. Ver docs/CENTRO_CONFIGURACION.md.
 */
class CatalogoAjustes
{
    /** @var array<string, DefinicionAjuste>|null */
    private static ?array $definiciones = null;

    /** @return array<string, DefinicionAjuste> */
    public function todos(): array
    {
        return self::$definiciones ??= $this->construir();
    }

    public function existe(string $clave): bool
    {
        return isset($this->todos()[$clave]);
    }

    /** @throws AjusteDesconocidoException */
    public function definicion(string $clave): DefinicionAjuste
    {
        return $this->todos()[$clave] ?? throw AjusteDesconocidoException::para($clave);
    }

    /** @return array<int, string> */
    public function claves(): array
    {
        return array_keys($this->todos());
    }

    /** @return array<string, DefinicionAjuste> */
    public function porSeccion(string $seccion): array
    {
        return array_filter($this->todos(), static fn (DefinicionAjuste $d) => $d->seccion === $seccion);
    }

    /** @return array<string, DefinicionAjuste> */
    public function porNivel(NivelConfirmacion $nivel): array
    {
        return array_filter($this->todos(), static fn (DefinicionAjuste $d) => $d->nivel === $nivel);
    }

    /** Solo para pruebas: obliga a reconstruir el catálogo memorizado. */
    public static function olvidar(): void
    {
        self::$definiciones = null;
    }

    // ------------------------------------------------------------- catálogo

    /** @return array<string, DefinicionAjuste> */
    private function construir(): array
    {
        $definiciones = [
            // ================================================================
            // N1 — Contabilidad. Persisten en la tabla `configuraciones`
            // existente: son las claves que hoy leen cuatro consumidores y el
            // objetivo de esta fase es unificar la LECTURA, no mover los datos.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'contabilidad.correo',
                seccion: 'contabilidad',
                tipo: TipoAjuste::Email,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Legacy,
                etiqueta: 'Correo de contabilidad',
                descripcion: 'Destinatario del paquete mensual, de los documentos enviados a la contadora y de la copia oculta de los DTE.',
                claveLegacy: 'contabilidad.correo',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'contabilidad.enviar_copia',
                seccion: 'contabilidad',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Legacy,
                etiqueta: 'Enviar copia oculta a contabilidad',
                descripcion: 'Agrega el correo de contabilidad como BCC en el envío manual de un DTE. No envía nada por sí solo.',
                claveLegacy: 'contabilidad.enviar_copia',
                porDefecto: false,
            ),

            // ================================================================
            // N1 — Correo de DTE. Misma estrategia: tabla anterior.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'correo.auto_envio',
                seccion: 'correo',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Legacy,
                etiqueta: 'Envío automático del DTE al cliente',
                descripcion: 'Encola el correo al aceptar el documento, sin intervención del usuario.',
                claveLegacy: 'correo.auto_envio',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'correo.adjuntar_jws',
                seccion: 'correo',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Bajo,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Legacy,
                etiqueta: 'Adjuntar el JWS firmado',
                descripcion: 'Incluye el documento firmado (.jws) además del PDF y el JSON.',
                claveLegacy: 'correo.adjuntar_jws',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'correo.plantilla',
                seccion: 'correo',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Bajo,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Legacy,
                etiqueta: 'Plantilla del correo de DTE',
                descripcion: 'Cuerpo del mensaje que acompaña al documento.',
                claveLegacy: 'correo.plantilla',
                porDefecto: PlantillaCorreo::DEFAULT,
                reglas: ['maxlen:5000'],
            ),

            // ================================================================
            // N2 — Respaldos. Persisten en la tabla NUEVA, con fallback
            // explícito a config/.env: hoy el valor sale de ahí y no se copia.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'respaldos.notificaciones.correo',
                seccion: 'respaldos',
                tipo: TipoAjuste::Email,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Correo de avisos de respaldo',
                descripcion: 'Destinatario de los avisos de respaldo correcto, fallido o vencido. Si nadie lo lee, un respaldo roto pasa inadvertido.',
                claveConfig: 'backup.notifications.mail.to',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'respaldos.dias_retencion',
                seccion: 'respaldos',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Días de retención de respaldos automáticos',
                descripcion: 'Bajarlo BORRA respaldos existentes en la siguiente limpieza. Mínimo razonable: 14 días.',
                claveConfig: 'backup_diario.dias_retencion',
                porDefecto: 30,
                reglas: ['min:1', 'max:3650'],
            ),

            // ================================================================
            // N2 — Servidor SMTP. Persisten en la tabla NUEVA con fallback
            // explícito a config/.env: hoy el valor sale de ahí y NO se copia.
            // Mientras nadie guarde un override, el correo se comporta
            // exactamente igual que antes de que existiera esta sección.
            //
            // Todos son N2 y no N1: una dirección mal escrita acá no rompe una
            // pantalla, hace que deje de salir el DTE al cliente — y eso se
            // descubre tarde, cuando el cliente reclama que no le llegó.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'mail.mailer',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                // SOLO LECTURA, y es una decisión deliberada, no una fase pendiente.
                //
                // Qué transporte usa el correo ya lo gobiernan tres mecanismos que
                // existen y funcionan: MAIL_MAILER en el .env, la segunda barrera de
                // AppServiceProvider (fuera de producción fuerza `log`) y
                // CandadoCorreoReal. Dejar que esta capa lo escribiera añadiría una
                // CUARTA autoridad sobre el interruptor más peligroso del módulo, y
                // sus dos modos de fallo son "sale correo real cuando no debía" y
                // "deja de salir sin que nadie lo note".
                //
                // Registrarlo igualmente sí sirve: la pantalla puede decir con qué
                // transporte se está enviando AHORA. Cambiarlo sigue siendo una
                // decisión de .env, con acceso al servidor.
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Medio de envío',
                descripcion: 'El servidor SMTP entrega de verdad; «log» escribe el correo en el registro sin enviarlo. Fuera de producción el candado de correo fuerza «log» sea cual sea este valor.',
                claveConfig: 'mail.default',
                // Los dos que este despliegue usa de verdad. La lista acota lo que la
                // pantalla sabe rotular; no habilita ninguna escritura.
                opciones: ['smtp', 'log', 'array'],
            ),
            DefinicionAjuste::hacer(
                clave: 'mail.smtp.host',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Servidor',
                descripcion: 'Nombre del servidor de correo saliente (por ejemplo smtp.gmail.com).',
                claveConfig: 'mail.mailers.smtp.host',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'mail.smtp.port',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Puerto',
                descripcion: 'Habitualmente 587 (STARTTLS) o 465 (TLS implícito).',
                claveConfig: 'mail.mailers.smtp.port',
                reglas: ['min:1', 'max:65535'],
            ),
            DefinicionAjuste::hacer(
                clave: 'mail.smtp.scheme',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Seguridad de la conexión',
                descripcion: 'La opción automática deja que decida el puerto (465 = TLS implícito; el resto, STARTTLS). Es lo que hace el mailer cuando no se le indica nada.',
                claveConfig: 'mail.mailers.smtp.scheme',
                // 'auto' NO es un valor del mailer: es la AUSENCIA de valor. Se
                // modela como opción porque en un <select> "automático" es una
                // elección real del administrador, y ConfiguracionCorreoRuntime la
                // traduce a "no fijar scheme" para que Laravel lo derive del puerto.
                //
                // Las otras dos son las ÚNICAS que entiende el transporte de Symfony
                // (ver MailManager::createSmtpTransport). Los nombres del Laravel
                // viejo —'tls' y 'ssl'— ya no los lee nadie: registrarlos habría
                // creado un ajuste que no cambia nada y que parece que sí.
                porDefecto: 'auto',
                opciones: ['auto', 'smtp', 'smtps'],
            ),
            DefinicionAjuste::hacer(
                clave: 'mail.smtp.username',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Usuario',
                descripcion: 'Usuario con el que se autentica el envío. En Gmail y similares es la dirección completa.',
                claveConfig: 'mail.mailers.smtp.username',
                reglas: ['maxlen:255'],
            ),

            // ================================================================
            // N2 — Secreto. Se DECLARA, no se migra: el valor sigue saliendo del
            // .env mientras nadie guarde un override desde la pantalla.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'mail.smtp.password',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Secreto,
                sensibilidad: Sensibilidad::SecretoCritico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Contraseña del servidor SMTP',
                descripcion: 'Solo la usa el transporte de correo. Nunca se muestra ni se registra: de ella solo se sabe si está configurada y desde dónde.',
                claveConfig: 'mail.mailers.smtp.password',
            ),

            // ================================================================
            // N2 — Remitente. Lo que ve el cliente en su bandeja.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'mail.from.address',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Email,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Correo remitente',
                descripcion: 'Dirección desde la que salen los documentos. Muchos servidores rechazan el envío si no coincide con el usuario autenticado.',
                claveConfig: 'mail.from.address',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'mail.from.name',
                seccion: 'correo_saliente',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Nombre remitente',
                descripcion: 'Nombre visible del remitente en la bandeja del cliente.',
                claveConfig: 'mail.from.name',
                reglas: ['maxlen:120'],
            ),

            // ================================================================
            // N3 — FISCAL CRÍTICO. SOLO DEFINICIÓN Y LECTURA en esta fase.
            //
            // Persistencia::Ninguna + Editabilidad::Futura significa que el
            // registry ya sabe que son críticas —y puede decirlo en pantalla—
            // pero la capa NO tiene dónde escribirlas: no hay ruta que pueda
            // cambiar el ambiente fiscal, ni por error ni a propósito. Abrirlas
            // exige, además del permiso `configuracion.critica`, la ceremonia
            // fuerte (frase exacta, reautenticación, precondiciones) que se
            // construye en la fase siguiente.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.ambiente',
                seccion: 'fiscal',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Ambiente del Ministerio de Hacienda',
                descripcion: 'CAT-001: 00 = pruebas, 01 = producción. Cambiarlo altera la numeración de control de TODOS los documentos.',
                claveConfig: 'dte.ambiente',
                porDefecto: '00',
                opciones: ['00', '01'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.ambiente',
                seccion: 'fiscal',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Ambiente de transmisión',
                descripcion: 'Rótulo operativo (testing / produccion) que decide contra qué host y con qué credenciales se autentica la transmisión.',
                claveConfig: 'dte.transmision.ambiente',
                porDefecto: 'testing',
                opciones: ['testing', 'produccion'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.firma.enabled',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Firma electrónica habilitada',
                descripcion: 'Interruptor maestro de la firma con el certificado del emisor.',
                claveConfig: 'dte.firma.enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.enabled',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Transmisión a Hacienda habilitada',
                descripcion: 'Interruptor maestro del envío de documentos al Ministerio de Hacienda.',
                claveConfig: 'dte.transmision.enabled',
                porDefecto: false,
            ),
        ];

        $porClave = [];

        foreach ($definiciones as $definicion) {
            if (isset($porClave[$definicion->clave])) {
                throw new \LogicException("El ajuste «{$definicion->clave}» está declarado dos veces en el catálogo.");
            }
            $porClave[$definicion->clave] = $definicion;
        }

        return $porClave;
    }
}
