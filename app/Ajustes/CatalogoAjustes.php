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
            // N1 — Contabilidad. YA MIGRADAS a `ajustes_sistema`.
            //
            // Conservan `claveLegacy` a propósito: es la LECTURA DE TRANSICIÓN
            // (ver Ajustes::overrideAlmacenado). Se escribe siempre en la tabla
            // nueva; la anterior solo se consulta mientras la nueva no tenga nada,
            // que es la ventana entre subir el código y correr la migración de
            // datos. Sin eso, en esos minutos el correo de contabilidad volvería a
            // "sin configurar" y las copias dejarían de salir sin que nadie hubiera
            // tocado nada. Cuando el despliegue esté migrado, se quita.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'contabilidad.correo',
                seccion: 'contabilidad',
                tipo: TipoAjuste::Email,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
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
                persistencia: Persistencia::Nueva,
                etiqueta: 'Enviar copia oculta a contabilidad',
                descripcion: 'Agrega el correo de contabilidad como BCC en el envío manual de un DTE. No envía nada por sí solo.',
                claveLegacy: 'contabilidad.enviar_copia',
                porDefecto: false,
            ),

            // ================================================================
            // N1 — Correo de DTE. Misma mudanza y misma lectura de transición.
            //
            // Migrarlas tiene un efecto que no se ve en la pantalla: mientras
            // vivían en `configuraciones`, las leía una caché ESTÁTICA de proceso,
            // así que cambiar el auto-envío no llegaba al worker de colas hasta
            // reiniciarlo. En `ajustes_sistema` la caché es del store compartido y
            // versionada, y el cambio llega al worker sin reiniciar nada.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'correo.auto_envio',
                seccion: 'correo',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
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
                persistencia: Persistencia::Nueva,
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
                persistencia: Persistencia::Nueva,
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
            // INTEGRACIÓN GMAIL (Prontos Pagos).
            //
            // Lo que la aplicación necesita para pedirle permiso a Google y para
            // saber dónde buscar. Los TOKENS de la cuenta conectada no están acá:
            // viven en `gmail_cuentas`, ya cifrados, y no son configuración —
            // nadie los escribe a mano, los emite Google.
            //
            // Las credenciales son N2 (equivocarse deja el módulo sin poder
            // autenticar) y los parámetros de búsqueda N1 (equivocarse hace que
            // una búsqueda no encuentre nada, y se ve en el acto).
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.enabled',
                seccion: 'gmail',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Integración con Gmail activada',
                descripcion: 'Apagada, el módulo no intenta conectarse ni buscar correos. No borra nada de lo ya descargado.',
                claveConfig: 'ppq.gmail.enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.client_id',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'ID de cliente de Google',
                descripcion: 'Identificador público de la aplicación en la consola de Google. No es un secreto.',
                claveConfig: 'ppq.gmail.client_id',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.client_secret',
                seccion: 'gmail',
                tipo: TipoAjuste::Secreto,
                sensibilidad: Sensibilidad::SecretoCritico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Secreto de cliente de Google',
                descripcion: 'Con él, cualquiera puede pedirle a Google tokens en nombre de esta aplicación. Nunca se muestra.',
                claveConfig: 'ppq.gmail.client_secret',
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.redirect_uri',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'URL de retorno autorizada',
                descripcion: 'Tiene que coincidir EXACTAMENTE con la registrada en la consola de Google, o el permiso se rechaza.',
                claveConfig: 'ppq.gmail.redirect_uri',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.label_albaranes',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Etiqueta de los albaranes',
                descripcion: 'Etiqueta de Gmail donde el cliente deja los albaranes.',
                claveConfig: 'ppq.gmail.label_albaranes',
                porDefecto: 'Calleja_Albaranes',
                reglas: ['maxlen:120'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.enviados_query',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Búsqueda de documentos enviados',
                descripcion: 'Consulta de Gmail con la que se localizan los CCF y notas de crédito ya enviados. Se le agrega el número buscado.',
                claveConfig: 'ppq.gmail.enviados_query',
                porDefecto: 'in:sent',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.dte_adjunto_query',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Filtro de adjunto del documento',
                descripcion: 'Acota la búsqueda a correos con adjunto real de documento, para que un número corto no gane sobre el correo correcto.',
                claveConfig: 'ppq.gmail.dte_adjunto_query',
                porDefecto: '(filename:json OR filename:pdf)',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.gmail.storage_dir',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                // RUTA DEL SERVIDOR: se muestra, no se edita. Cambiarla desde una
                // pantalla no mueve los archivos ya descargados — los deja
                // huérfanos en la carpeta anterior mientras la aplicación busca en
                // otra, y el síntoma es "desaparecieron los albaranes".
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Carpeta de descargas',
                descripcion: 'Dónde se guardan los adjuntos bajados de Gmail. Se administra en el servidor.',
                claveConfig: 'ppq.gmail.storage_dir',
                porDefecto: 'ppq/gmail',
            ),

            // ================================================================
            // INTEGRACIÓN IMAP (Documentos recibidos / compras).
            //
            // Buzón del que se LEEN los documentos que mandan los proveedores. El
            // lector es de solo lectura: no borra, no mueve y no marca como leído.
            //
            // Credenciales y conexión son N2; los parámetros de lectura (carpeta,
            // filtro, tiempos, tope) son N1 porque su peor resultado es una
            // sincronización que trae de más o de menos, visible en el acto.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.driver',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Lectura del buzón',
                descripcion: 'Con «ninguna», los documentos de compra se cargan a mano y no se consulta ningún buzón.',
                claveConfig: 'documentos_recibidos.mail.driver',
                porDefecto: 'imap',
                opciones: ['imap', 'none'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.host',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Servidor',
                descripcion: 'Servidor IMAP del buzón (por ejemplo imap.mail.yahoo.com).',
                claveConfig: 'documentos_recibidos.mail.host',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.port',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Puerto',
                descripcion: 'Habitualmente 993 con SSL.',
                claveConfig: 'documentos_recibidos.mail.port',
                porDefecto: 993,
                reglas: ['min:1', 'max:65535'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.encryption',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Seguridad de la conexión',
                descripcion: 'SSL es lo habitual en el puerto 993.',
                claveConfig: 'documentos_recibidos.mail.encryption',
                // Los tres valores que el lector distingue de verdad: cualquier otro
                // texto acabaría comportándose como «ninguna» sin decirlo.
                porDefecto: 'ssl',
                opciones: ['ssl', 'tls', 'ninguna'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.username',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Usuario',
                descripcion: 'Dirección completa del buzón de compras.',
                claveConfig: 'documentos_recibidos.mail.username',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.password',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Secreto,
                sensibilidad: Sensibilidad::SecretoCritico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Contraseña del buzón',
                descripcion: 'En Yahoo y Gmail es una contraseña de aplicación, no la del correo. Nunca se muestra.',
                claveConfig: 'documentos_recibidos.mail.password',
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.folder',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Carpeta',
                descripcion: 'Carpeta del buzón que se revisa.',
                claveConfig: 'documentos_recibidos.mail.folder',
                porDefecto: 'INBOX',
                reglas: ['maxlen:120'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.search',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Filtro de búsqueda',
                descripcion: 'Criterio IMAP de los correos a revisar. ALL revisa toda la carpeta.',
                claveConfig: 'documentos_recibidos.mail.search',
                porDefecto: 'ALL',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.timeout',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Tiempo de espera (segundos)',
                descripcion: 'Cuánto se espera a que el servidor responda antes de darse por vencido.',
                claveConfig: 'documentos_recibidos.mail.timeout',
                porDefecto: 15,
                reglas: ['min:1', 'max:120'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.limite',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Correos por sincronización',
                descripcion: 'Tope de correos que revisa cada sincronización manual.',
                claveConfig: 'documentos_recibidos.limite',
                porDefecto: 30,
                reglas: ['min:1', 'max:500'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.storage_dir',
                seccion: 'documentos_recibidos',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                // Misma razón que la carpeta de Gmail: cambiarla no mueve los
                // adjuntos ya guardados, los deja huérfanos.
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Carpeta de adjuntos',
                descripcion: 'Dónde se guardan los adjuntos descargados. Se administra en el servidor.',
                claveConfig: 'documentos_recibidos.storage_dir',
                porDefecto: 'documentos-recibidos',
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

            // ================================================================
            // N3 — HACIENDA / API. Igual que los cuatro de arriba: DECLARADAS y
            // clasificadas, SIN dónde escribirse (Persistencia::Ninguna).
            //
            // Las URLs y los endpoints son N3 y no N2 por un motivo concreto: son
            // A DÓNDE se manda un documento firmado. Una pantalla que los cambie
            // es una pantalla capaz de desviar documentos fiscales a otro host, y
            // para operarla basta con la sesión abierta de un administrador. Las
            // CREDENCIALES ni siquiera están declaradas: ver el comentario del
            // final de este bloque.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.url_base',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'URL base del Ministerio de Hacienda',
                descripcion: 'Host de los servicios del MH. Vacío = se usa el host oficial que corresponda al ambiente (apitest o api).',
                claveConfig: 'dte.transmision.url_base',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.endpoint_auth',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Ruta de autenticación',
                descripcion: 'Ruta del servicio de seguridad del MH donde se pide el token.',
                claveConfig: 'dte.transmision.endpoint_auth',
                porDefecto: '/seguridad/auth',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.endpoint_recepcion',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Ruta de recepción',
                descripcion: 'Ruta donde se entregan los documentos firmados. Si se deja vacía se usa la ruta incorporada, para que nunca quede a medias; pendiente de contrastar contra el manual técnico vigente.',
                claveConfig: 'dte.transmision.endpoint_recepcion',
                porDefecto: '/fesv/recepciondte',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.timeout',
                seccion: 'fiscal',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Tiempo de espera de Hacienda (segundos)',
                descripcion: 'Cuánto se espera una respuesta del MH antes de darla por perdida. El manual técnico habla de unos 8 s antes de reintentar.',
                claveConfig: 'dte.transmision.timeout',
                porDefecto: 8,
                reglas: ['min:1', 'max:120'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.user_agent',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Identificador del sistema (User-Agent)',
                descripcion: 'Cabecera que exigen los servicios de recepción del MH para identificar al sistema emisor.',
                claveConfig: 'dte.transmision.user_agent',
                porDefecto: 'DulcesLaNegrita-DTE/1.0',
                reglas: ['maxlen:120'],
            ),

            // ================================================================
            // N3 — CANDADOS FISCALES. Cada uno de estos interruptores, abierto,
            // acerca al sistema a mandar algo real a Hacienda. Se declaran para
            // poder INVENTARIARLOS en pantalla —hoy no hay ninguna vista que los
            // muestre juntos, y esa es media auditoría perdida— pero ninguno tiene
            // dónde escribirse. Abrirlos desde la web exigiría la ceremonia N3
            // completa, que no está conectada a ninguna acción real todavía.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.firma.mock',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Firma simulada (mock)',
                descripcion: 'Genera una firma FICTICIA sin firmador ni certificado. En pantalla se ve igual que una firma real y no vale ante Hacienda.',
                claveConfig: 'dte.firma.mock',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.mock',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Respuesta de Hacienda simulada (mock)',
                descripcion: 'Devuelve un sello de recepción FICTICIO sin conectar con el MH. En pantalla se ve igual que un sello real.',
                claveConfig: 'dte.transmision.mock',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.real_confirmation',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Confirmación de transmisión real',
                descripcion: 'Segundo interruptor del envío real. Sin él, la transmisión queda bloqueada aunque esté habilitada.',
                claveConfig: 'dte.transmision.real_confirmation',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.allow_production',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Producción autorizada',
                descripcion: 'Autoriza el uso del ambiente de producción. Sin él, estando en producción no se transmite nada.',
                claveConfig: 'dte.transmision.allow_production',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.dry_run',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Modo de ensayo (dry-run)',
                descripcion: 'Con el ensayo ACTIVO nunca hay conexión real: se arma el envío y se descarta. Es el candado que hay que APAGAR para transmitir.',
                claveConfig: 'dte.transmision.dry_run',
                porDefecto: true,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.test_enabled',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Vía dedicada de pruebas (apitest)',
                descripcion: 'Permite transmitir DE VERDAD al ambiente de PRUEBAS del MH saltándose los candados de producción. No afecta a producción.',
                claveConfig: 'dte.transmision.test_enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.auth_test_real_enabled',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Prueba de acceso a pruebas (apitest)',
                descripcion: 'Permite comprobar el acceso contra apitest. Solo inicia sesión: no envía ningún documento.',
                claveConfig: 'dte.transmision.auth_test_real_enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.auth_test_prod_enabled',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Prueba de acceso a producción',
                descripcion: 'Permite comprobar contra la cuenta REAL si la credencial es de producción. Solo inicia sesión, descarta el token y no envía ningún documento.',
                claveConfig: 'dte.transmision.auth_test_prod_enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.sistema_actual_activo',
                seccion: 'fiscal',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'El sistema anterior sigue facturando',
                descripcion: 'Mientras esté activo, este sistema no transmite salvo en modo principal: evita duplicar correlativos y documentos entre los dos sistemas.',
                claveConfig: 'dte.transmision.sistema_actual_activo',
                porDefecto: true,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.transmision.modo_operacion',
                seccion: 'fiscal',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Modo de operación',
                descripcion: 'paralelo = este sistema no transmite; respaldo = transmite solo con confirmación manual; principal = es el sistema oficial.',
                claveConfig: 'dte.transmision.modo_operacion',
                porDefecto: 'paralelo',
                opciones: ['paralelo', 'respaldo', 'principal'],
            ),

            // ================================================================
            // N3 — CERTIFICADO Y FIRMADOR.
            //
            // `dte.firmador.url` es N3 y no N2 aunque apunte a un servicio LOCAL:
            // a esa URL se le manda, en cada firma, la contraseña del certificado
            // (campo `passwordPri`). Cambiarla desde una pantalla web es poder
            // redirigir esa contraseña a otro destino. Es el mismo motivo por el
            // que DTE_CERT_PASSWORD no está declarada en este catálogo.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.firmador.url',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Dirección del firmador',
                descripcion: 'Servicio local del MH que firma con el certificado. Recibe la contraseña del certificado en cada firma.',
                claveConfig: 'dte.firmador.url',
                porDefecto: 'http://localhost:8080/firmardocumento',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.firma.timeout',
                seccion: 'fiscal',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Tiempo de espera del firmador (segundos)',
                descripcion: 'Cuánto se espera al firmador local antes de dar la firma por perdida.',
                claveConfig: 'dte.firma.timeout',
                porDefecto: 10,
                reglas: ['min:1', 'max:120'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.firma.nit',
                seccion: 'fiscal',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'NIT del certificado',
                descripcion: 'Decide QUÉ certificado usa el firmador (el archivo se llama NIT.crt). Si no coincide con el NIT del emisor, se firma con el certificado de otro contribuyente.',
                claveConfig: 'dte.firma.nit',
                reglas: ['maxlen:20'],
            ),

            // ================================================================
            // PARÁMETROS FISCALES. Aquí la clasificación deja de ser uniforme:
            //
            //  - las TASAS y los UMBRALES LEGALES (IVA 13 %, retención 1 %, los
            //    25 000 del receptor identificado) son solo lectura: no son una
            //    preferencia de la empresa, y una pantalla que los edite invita a
            //    "probar" con el impuesto de documentos reales;
            //  - los DEFAULTS DE OPERACIÓN (forma de pago, plazo, régimen de
            //    exportación) sí son decisiones del negocio y acabarán siendo
            //    campos con confirmación.
            //
            // Ninguno está abierto todavía: sus consumidores leen config() y
            // abrirlos sin cambiar esos consumidores daría una pantalla que
            // aparenta cambiar algo sin cambiar nada.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.iva_tasa',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Tasa de IVA',
                descripcion: 'Tasa legal del IVA aplicada por la calculadora del documento.',
                claveConfig: 'dte.iva_tasa',
                porDefecto: '0.13',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.retencion_iva_tasa',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Tasa de retención de IVA',
                descripcion: 'Retención aplicada en crédito fiscal a agentes de retención.',
                claveConfig: 'dte.retencion_iva_tasa',
                porDefecto: '0.01',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.retencion_iva_umbral',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Monto desde el que se retiene',
                descripcion: 'Base gravada neta (sin IVA, después de descuentos) que hay que SUPERAR para que se aplique la retención.',
                claveConfig: 'dte.retencion_iva_umbral',
                porDefecto: '100',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.factura_consumidor_final.receptor_obligatorio_desde',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Monto desde el que se exige identificar al cliente',
                descripcion: 'En factura de consumidor final, por encima de este total hay que consignar nombre y documento del receptor. Umbral estricto: exactamente el monto NO lo exige.',
                claveConfig: 'dte.factura_consumidor_final.receptor_obligatorio_desde',
                porDefecto: '25000',
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.condicion_operacion_default_contribuyente',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Condición de operación por defecto (contribuyentes)',
                descripcion: 'CAT-016. Se usa en crédito fiscal cuando ni el cliente ni la sucursal definen una: 1 contado, 2 crédito, 3 otro.',
                claveConfig: 'dte.condicion_operacion_default_contribuyente',
                porDefecto: '2',
                opciones: ['1', '2', '3'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.json.forma_pago_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Forma de pago por defecto',
                descripcion: 'CAT-017. Se usa cuando el documento no especifica una. 01 = billetes y monedas.',
                claveConfig: 'dte.json.forma_pago_default',
                porDefecto: '01',
                reglas: ['maxlen:2'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.json.plazo_credito_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Unidad del plazo de crédito',
                descripcion: 'CAT-018. El MH exige plazo y período cuando la operación es a crédito. 01 = días, 02 = meses, 03 = años.',
                claveConfig: 'dte.json.plazo_credito_default',
                porDefecto: '01',
                opciones: ['01', '02', '03'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.json.periodo_credito_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Plazo de crédito por defecto',
                descripcion: 'Cantidad, en la unidad de arriba. Debe corresponder al plazo de crédito realmente acordado.',
                claveConfig: 'dte.json.periodo_credito_default',
                porDefecto: 30,
                reglas: ['min:1', 'max:3650'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.exportacion.recinto_fiscal_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Recinto fiscal por defecto (exportación)',
                descripcion: 'CAT-027. Valor con el que nace un borrador de factura de exportación; el usuario puede cambiarlo antes de generar.',
                claveConfig: 'dte.exportacion.recinto_fiscal_default',
                porDefecto: '01',
                reglas: ['maxlen:4'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.exportacion.tipo_regimen_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Tipo de régimen por defecto (exportación)',
                descripcion: 'CAT-033. EX-1 = exportación definitiva.',
                claveConfig: 'dte.exportacion.tipo_regimen_default',
                porDefecto: 'EX-1',
                reglas: ['maxlen:10'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.exportacion.regimen_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Régimen por defecto (exportación)',
                descripcion: 'CAT-028. 1000.000 = exportación definitiva, régimen común.',
                claveConfig: 'dte.exportacion.regimen_default',
                porDefecto: '1000.000',
                reglas: ['maxlen:20'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.exportacion.cod_incoterms_default',
                seccion: 'fiscal_parametros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Incoterm por defecto (exportación)',
                descripcion: 'CAT-031. 09 = FOB, libre a bordo.',
                claveConfig: 'dte.exportacion.cod_incoterms_default',
                porDefecto: '09',
                reglas: ['maxlen:4'],
            ),

            // ================================================================
            // INVALIDACIÓN. Los datos del responsable y del solicitante son
            // obligatorios en el esquema del MH y hoy se leen del archivo del
            // servidor: son datos de PERSONAS, cambian con la plantilla de la
            // empresa y no tienen nada que hacer en un .env. Son los mejores
            // candidatos a abrirse primero de todo este bloque.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.real_confirmation',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Confirmación de invalidación real',
                descripcion: 'Candado del envío real del evento de invalidación. Aplica tanto a pruebas como a producción.',
                claveConfig: 'dte.invalidacion.real_confirmation',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.produccion_enabled',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Invalidación en producción autorizada',
                descripcion: 'Mientras esté cerrado, un documento de producción NUNCA puede invalidarse, sin importar el resto de candados.',
                claveConfig: 'dte.invalidacion.produccion_enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.mock',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::FiscalCritico,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Invalidación simulada (mock)',
                descripcion: 'Genera un sello de invalidación FICTICIO sin firmador ni MH. En pantalla se ve igual que uno real.',
                claveConfig: 'dte.invalidacion.mock',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.version',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N3,
                editabilidad: Editabilidad::SoloLectura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Versión del esquema de invalidación',
                descripcion: 'La fija el esquema oficial publicado por el MH, no la empresa.',
                claveConfig: 'dte.invalidacion.version',
                porDefecto: 3,
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.responsable.nombre',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Responsable de la invalidación',
                descripcion: 'Persona que REALIZA el evento. Obligatorio en el esquema del MH.',
                claveConfig: 'dte.invalidacion.responsable.nombre',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.responsable.num_doc',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Documento del responsable',
                descripcion: 'Número del documento del responsable (CAT-022: 36 = NIT, 13 = DUI).',
                claveConfig: 'dte.invalidacion.responsable.num_doc',
                reglas: ['maxlen:25'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.solicita.nombre',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Solicitante de la invalidación',
                descripcion: 'Persona que SOLICITA el evento. Obligatorio en el esquema del MH.',
                claveConfig: 'dte.invalidacion.solicita.nombre',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'dte.invalidacion.solicita.num_doc',
                seccion: 'fiscal_invalidacion',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Futura,
                persistencia: Persistencia::Ninguna,
                etiqueta: 'Documento del solicitante',
                descripcion: 'Número del documento del solicitante (CAT-022: 36 = NIT, 13 = DUI).',
                claveConfig: 'dte.invalidacion.solicita.num_doc',
                reglas: ['maxlen:25'],
            ),

            // ================================================================
            // QUÉ NO ENTRA ACÁ, Y NO ES UN OLVIDO
            //
            //  - DTE_PROD_USER / DTE_PROD_PASSWORD / DTE_TEST_USER /
            //    DTE_TEST_PASSWORD / DTE_TRANSMISION_TOKEN: son las llaves de la
            //    cuenta de la empresa en el Ministerio de Hacienda.
            //  - DTE_CERT_PASSWORD: la contraseña del certificado de firma.
            //
            // Podrían guardarse cifradas, como ya se guardan la del SMTP y la de
            // Gmail. La diferencia no es técnica: es qué habilita quien las
            // obtiene. Con la contraseña del SMTP se manda correo en nombre de la
            // empresa; con estas se EMITEN DOCUMENTOS FISCALES en su nombre. Una
            // pantalla que las escriba convierte «una sesión de administrador
            // abierta» en «capacidad de facturar», y ninguna ceremonia arregla eso
            // —la ceremonia protege del error, no de quien ya entró—.
            //
            // La pantalla SÍ dice si están configuradas y de qué juego de
            // variables salen (ver DteTransmisionAuthService::fuenteCredenciales()),
            // que es lo que hace falta para administrarlas sin poder tocarlas.
            // ================================================================
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
