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
use App\Enums\ModuloSistema;
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

    /** Días ISO (1 = lunes) del horario del lector de huella. */
    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * Datos del patrono para el contrato de trabajo: campo ⇒ [etiqueta, reglas].
     * Razón social y NIT no están: salen de la empresa emisora.
     */
    private const PATRONO = [
        'sexo' => ['Sexo del patrono', ['maxlen:20']],
        'fecha_nacimiento' => ['Fecha de nacimiento del patrono', ['fecha']],
        'estado_civil' => ['Estado civil del patrono', ['maxlen:30']],
        'profesion' => ['Profesión u oficio del patrono', ['maxlen:120']],
        'domicilio' => ['Domicilio del patrono', ['maxlen:180']],
        'residencia' => ['Residencia del patrono', ['maxlen:255']],
        'nacionalidad' => ['Nacionalidad del patrono', ['maxlen:60']],
        'dui' => ['DUI del patrono', ['maxlen:20']],
        'dui_expedido_en' => ['Lugar de expedición del DUI', ['maxlen:120']],
        'dui_expedido_el' => ['Fecha de expedición del DUI', ['fecha']],
        'actividad' => ['Actividad económica de la empresa', ['maxlen:255']],
        'ciudad' => ['Ciudad donde se firma', ['maxlen:120']],
        'direccion_pago' => ['Dirección donde se paga', ['maxlen:255']],
    ];

    /** Campos de {@see PATRONO} que son datos de la persona, no de la empresa. */
    private const PATRONO_PERSONALES = [
        'sexo', 'fecha_nacimiento', 'estado_civil', 'profesion', 'domicilio', 'residencia',
        'nacionalidad', 'dui', 'dui_expedido_en', 'dui_expedido_el',
    ];

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
                clave: 'ppq.gmail.label_ordenes',
                seccion: 'gmail',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Etiqueta de las órdenes de compra',
                descripcion: 'Etiqueta de Gmail donde caen las órdenes de compra del cliente.',
                claveConfig: 'ppq.gmail.label_ordenes',
                porDefecto: 'Ordenes',
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
                etiqueta: 'Tamaño de página (correos por petición)',
                descripcion: 'Cuántos correos se le piden al buzón en cada petición. NO es el máximo que se '
                    .'sincroniza: si un día tiene más correos que esto, se pide la página siguiente hasta '
                    .'agotar el día, así que subirlo o bajarlo no cambia CUÁNTOS correos entran, solo en '
                    .'cuántas peticiones. Bajarlo hace cada petición más liviana; subirlo, menos peticiones.',
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
            // SEGUNDO BUZÓN DE COMPRAS (opcional, temporal). Mismo patrón que el
            // principal: conexión N2, contraseña cifrada en su propia pantalla y
            // carpeta N1. Sin servidor está apagado. Las claves son las de config
            // (DOCUMENTOS_RECIBIDOS_MAIL2_*): sin nada guardado manda el .env.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.host',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Servidor del segundo buzón',
                descripcion: 'Servidor IMAP del segundo buzón. Vacío, el segundo buzón está apagado.',
                claveConfig: 'documentos_recibidos.buzon_adicional.host',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.port',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Puerto del segundo buzón',
                descripcion: 'Habitualmente 993 con SSL.',
                claveConfig: 'documentos_recibidos.buzon_adicional.port',
                porDefecto: 993,
                reglas: ['min:1', 'max:65535'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.encryption',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Seguridad de la conexión del segundo buzón',
                descripcion: 'SSL es lo habitual en el puerto 993.',
                claveConfig: 'documentos_recibidos.buzon_adicional.encryption',
                porDefecto: 'ssl',
                opciones: ['ssl', 'tls', 'ninguna'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.username',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Usuario del segundo buzón',
                descripcion: 'Dirección completa del segundo buzón.',
                claveConfig: 'documentos_recibidos.buzon_adicional.username',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.password',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Secreto,
                sensibilidad: Sensibilidad::SecretoCritico,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Contraseña del segundo buzón',
                descripcion: 'En Yahoo y Gmail es una contraseña de aplicación, no la del correo. Nunca se muestra.',
                claveConfig: 'documentos_recibidos.buzon_adicional.password',
            ),
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.buzon_adicional.folder',
                seccion: 'documentos_recibidos_adicional',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Carpeta del segundo buzón',
                descripcion: 'Tiene que ser distinta de la del buzón principal: el progreso se lleva por carpeta.',
                claveConfig: 'documentos_recibidos.buzon_adicional.folder',
                porDefecto: 'INBOX',
                reglas: ['maxlen:120'],
            ),

            // ================================================================
            // N2 — MÓDULOS. Prender y apagar Gastos, Producción, Asistencia,
            // Planilla y Pagos de planilla desde Configuración › Módulos.
            //
            // Uno por caso de App\Enums\ModuloSistema, que es la fuente única de
            // nombres, claves de config y valores por defecto. Fallback al .env de
            // siempre (GASTOS_ENABLED…): sin nada guardado, cada instalación sigue
            // como estaba. N2 porque apagar uno esconde un área entera; la pantalla
            // pide confirmación al apagar. Ningún dato se borra.
            // ================================================================
            ...array_map(static fn (ModuloSistema $modulo) => DefinicionAjuste::hacer(
                clave: $modulo->clave(),
                seccion: 'modulos',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: $modulo->etiqueta(),
                descripcion: $modulo->descripcion(),
                claveConfig: $modulo->claveConfig(),
                porDefecto: $modulo->porDefecto(),
            ), ModuloSistema::cases()),

            // ================================================================
            // OPCIONES DE CADA MÓDULO (Configuración › Módulos › …).
            //
            // Opciones de NEGOCIO que vivían solo en el .env: plazos, montos,
            // horarios, textos, correos de aviso y llaves de comportamiento. La
            // clave del ajuste es la MISMA de config(): sin nada guardado, cada
            // instalación sigue haciendo exactamente lo que dice su .env.
            //
            // Nivel: N2 lo que toca dinero, planilla o puede dejar a alguien sin
            // trabajar (una tarea automática que escribe sola, un corte de fechas
            // que esconde pendientes); N1 lo que se equivoca a la vista y se
            // corrige en el acto.
            //
            // QUÉ NO ENTRA, Y NO ES UN OLVIDO: carpetas del servidor
            // (`*.storage_dir`, plantilla de exportaciones), `rutas.ambiente_ccf`
            // y la contingencia (`dte.contingencia.*`), que son de Hacienda, y la
            // vigilancia de /salud (`alertas.salud.*`), que es infraestructura.
            // ================================================================

            // ---------------------------------------------------- Pronto pago
            DefinicionAjuste::hacer(
                clave: 'ppq.diferencia_coincide',
                seccion: 'ppq',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Diferencia que cuenta como «monto coincide» ($)',
                descripcion: 'Hasta esta diferencia entre el CCF o la nota y el albarán se da por igual (redondeo). La usan la conciliación de PPQ y el seguimiento de Cobros.',
                claveConfig: 'ppq.diferencia_coincide',
                porDefecto: '0.05',
                reglas: ['min:0', 'max:100'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.diferencia_pequena',
                seccion: 'ppq',
                tipo: TipoAjuste::Decimal,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Diferencia que cuenta como «pequeña» ($)',
                descripcion: 'Por encima de la anterior y hasta esta, la diferencia es pequeña; más arriba se marca como posible nota de crédito o devolución.',
                claveConfig: 'ppq.diferencia_pequena',
                porDefecto: '1.00',
                reglas: ['min:0', 'max:10000'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.sala_oc_prefix',
                seccion: 'ppq',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Dígitos antes de la sala en la orden de compra',
                descripcion: 'Cuántos dígitos del principio de la orden de compra se saltan (el año y mes, AAMM) antes de leer la sala.',
                claveConfig: 'ppq.sala_oc_prefix',
                porDefecto: 4,
                reglas: ['min:0', 'max:12'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.sala_oc_length',
                seccion: 'ppq',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Dígitos de la sala en la orden de compra',
                descripcion: 'Cuántos dígitos forman la sala, con su cero inicial (por ejemplo 0260).',
                claveConfig: 'ppq.sala_oc_length',
                porDefecto: 4,
                reglas: ['min:1', 'max:12'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.albaranes.tipo_entrega',
                seccion: 'ppq',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Código del albarán de entrega',
                descripcion: 'Con este código el cliente marca el albarán que prueba la entrega. Los de avería o devolución no cuentan como entrega.',
                claveConfig: 'ppq.albaranes.tipo_entrega',
                porDefecto: 'AC01',
                reglas: ['maxlen:10'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.codigo_proveedor',
                seccion: 'ppq',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Código de proveedor ante el cliente',
                descripcion: 'Con él se comprueba que cada fila del archivo de pagos sea de esta empresa y se nombra el Excel que se exporta. Sin él, esos archivos se rechazan.',
                claveConfig: 'ppq.codigo_proveedor',
                reglas: ['maxlen:30'],
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.albaranes.sincronizacion_automatica',
                seccion: 'ppq',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Traer los albaranes del correo solo, cada cinco minutos',
                descripcion: 'Encendida, el sistema lee los albaranes de Gmail por su cuenta y los guarda. Apagada, se traen a mano. Necesita la integración con Gmail encendida.',
                claveConfig: 'ppq.albaranes.sincronizacion_automatica',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'ppq.cliente_default_id',
                seccion: 'ppq',
                tipo: TipoAjuste::Cliente,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Cliente del pronto pago',
                descripcion: 'A este cliente se le crean los lotes y con él se cruzan las salas y las notas de crédito de pronto pago. Otro cliente manda los lotes nuevos a esa empresa.',
                claveConfig: 'ppq.cliente_default_id',
            ),

            // --------------------------------------------------------- Cobros
            DefinicionAjuste::hacer(
                clave: 'cobros.dias_revision_historica',
                seccion: 'cobros',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Días para mandar un documento viejo a revisión',
                descripcion: 'Un documento sin antecedentes y con más días que esto al entrar al seguimiento no se da por pendiente de cobro: queda para que una persona lo revise.',
                claveConfig: 'cobros.dias_revision_historica',
                porDefecto: 30,
                reglas: ['min:0', 'max:3650'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.inicio_seguimiento',
                seccion: 'cobros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Fecha desde la que el seguimiento lleva la historia',
                descripcion: 'Lo emitido antes de esta fecha y sin antecedentes queda en revisión histórica; lo posterior, nunca. Sin fecha, manda la regla de los días.',
                claveConfig: 'cobros.inicio_seguimiento',
                reglas: ['fecha'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.dias_albaran_reciente',
                seccion: 'cobros',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Días que un albarán se marca como reciente',
                descripcion: 'Desde la entrega o desde su última devolución, lo que sea más tarde.',
                claveConfig: 'cobros.dias_albaran_reciente',
                porDefecto: 3,
                reglas: ['min:0', 'max:365'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.alta.automatica',
                seccion: 'cobros',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Sumar solos al seguimiento los documentos aceptados',
                descripcion: 'Encendida, cada diez minutos entran al seguimiento los CCF y notas que Hacienda aceptó. Apagada, se suman con el botón de la pantalla.',
                claveConfig: 'cobros.alta.automatica',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.vinculacion.automatica',
                seccion: 'cobros',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Unir solo el albarán a su CCF',
                descripcion: 'En la corrida automática, une el albarán de entrega cuando hay uno solo y sin contradicciones. Antes de encenderla, revisá a mano qué uniría.',
                claveConfig: 'cobros.vinculacion.automatica',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.correo.enabled',
                seccion: 'cobros',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Leer las respuestas del cliente en el correo',
                descripcion: 'Permite leer los acuses y observaciones del cliente en Gmail cuando alguien lo pide. Solo lee: no responde ni mueve nada.',
                claveConfig: 'cobros.correo.enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.correo.automatica',
                seccion: 'cobros',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Leer las respuestas solo, cada media hora',
                descripcion: 'Encendida, el sistema lee las respuestas por su cuenta y aplica los acuses. Necesita encendida la lectura de arriba y la integración con Gmail.',
                claveConfig: 'cobros.correo.automatica',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.correo.query',
                seccion: 'cobros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Búsqueda de las respuestas en Gmail',
                descripcion: 'Consulta de Gmail con la que se encuentran los acuses del cliente.',
                claveConfig: 'cobros.correo.query',
                porDefecto: '("RECIBIDO" OR "OBSERVACIONES" OR "SOLICITUD DE QUEDAN")',
                reglas: ['maxlen:500'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.correo.limite',
                seccion: 'cobros',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Respuestas que se leen por vez',
                descripcion: 'Cuántos mensajes trae como mucho cada lectura.',
                claveConfig: 'cobros.correo.limite',
                porDefecto: 50,
                reglas: ['min:1', 'max:1000'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.txt_correo.remitente',
                seccion: 'cobros',
                tipo: TipoAjuste::Email,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Quién manda los TXT de pago',
                descripcion: 'Dirección exacta que manda los archivos de pago. Sin ella, el botón «Buscar TXT en el correo» no aparece.',
                claveConfig: 'cobros.txt_correo.remitente',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'cobros.txt_correo.carpeta',
                seccion: 'cobros',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Carpeta donde llegan los TXT',
                descripcion: 'Carpeta del buzón de compras donde se buscan los TXT. Es aparte de la carpeta de compras.',
                claveConfig: 'cobros.txt_correo.carpeta',
                porDefecto: 'INBOX',
                reglas: ['maxlen:120'],
            ),

            // ---------------------------------------------- Avisos de fallas
            DefinicionAjuste::hacer(
                clave: 'alertas.enabled',
                seccion: 'alertas',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Avisar por correo cuando algo falla',
                descripcion: 'Cada cinco minutos se revisa lo que puede estar mal y se escribe cuando algo se rompe, empeora o se arregla. Apagado, nadie se entera de una falla hasta que la ve.',
                claveConfig: 'alertas.enabled',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'alertas.correo',
                seccion: 'alertas',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'A quién se avisa',
                descripcion: 'Uno o varios correos separados por coma. Si nadie los lee, una falla pasa inadvertida.',
                claveConfig: 'alertas.correo',
                reglas: ['correos', 'maxlen:500'],
            ),
            DefinicionAjuste::hacer(
                clave: 'alertas.reaviso_minutos',
                seccion: 'alertas',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Minutos antes de volver a avisar',
                descripcion: 'Mientras una falla sigue abierta, se vuelve a avisar solo si empeoró y pasó este tiempo.',
                claveConfig: 'alertas.reaviso_minutos',
                porDefecto: 60,
                reglas: ['min:1', 'max:1440'],
            ),
            DefinicionAjuste::hacer(
                clave: 'alertas.tope_diario',
                seccion: 'alertas',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Correos por tipo de falla y por día',
                descripcion: 'Tope para que una falla que se abre y se cierra sin parar no llene el buzón.',
                claveConfig: 'alertas.tope_diario',
                porDefecto: 6,
                reglas: ['min:1', 'max:100'],
            ),
            DefinicionAjuste::hacer(
                clave: 'alertas.respaldo_hora_limite',
                seccion: 'alertas',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Hora límite del respaldo del día',
                descripcion: 'Si a esta hora no hay un respaldo válido de hoy, se avisa.',
                claveConfig: 'alertas.respaldo_hora_limite',
                porDefecto: '06:00',
                reglas: ['hora'],
            ),
            DefinicionAjuste::hacer(
                clave: 'alertas.lector.sin_ping_minutos',
                seccion: 'alertas',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Minutos que el lector de huella puede estar callado',
                descripcion: 'En horario laboral, pasado este tiempo sin señal del lector se avisa.',
                claveConfig: 'alertas.lector.sin_ping_minutos',
                porDefecto: 15,
                reglas: ['min:1', 'max:240'],
            ),
            ...array_map(static fn (int $dia, string $nombre) => DefinicionAjuste::hacer(
                clave: 'alertas.lector.horario.'.$dia,
                seccion: 'alertas',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Bajo,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Horario del lector: '.$nombre,
                descripcion: 'Desde y hasta, por ejemplo 08:00-17:00. Vacío: ese día no se vigila el lector.',
                claveConfig: 'alertas.lector.horario.'.$dia,
                reglas: ['rango_horas'],
            ), array_keys(self::DIAS), self::DIAS),

            // -------------------------------------------------------- Planilla
            DefinicionAjuste::hacer(
                clave: 'planilla.sector_salario_minimo',
                seccion: 'planilla',
                tipo: TipoAjuste::Enumerado,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Sector para el salario mínimo',
                descripcion: 'Sector de la empresa en la tabla oficial de salarios mínimos. Con él se controla que ningún sueldo quede por debajo del mínimo.',
                claveConfig: 'planilla.sector_salario_minimo',
                porDefecto: 'industria',
                opciones: ['comercio_servicios', 'maquila_textil', 'industria', 'ingenios_azucareros', 'agroindustria', 'beneficios_cafe', 'agropecuario', 'recoleccion_cana', 'recoleccion_cafe'],
            ),
            ...array_map(static fn (string $campo, array $dato) => DefinicionAjuste::hacer(
                clave: 'planilla.contrato.patrono.'.$campo,
                seccion: 'planilla',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: $dato[0],
                descripcion: 'Va en el contrato de trabajo. Sin este dato el contrato no se genera.',
                claveConfig: 'planilla.contrato.patrono.'.$campo,
                reglas: $dato[1],
                datoPersonal: in_array($campo, self::PATRONO_PERSONALES, true),
            ), array_keys(self::PATRONO), self::PATRONO),
            DefinicionAjuste::hacer(
                clave: 'planilla.contrato.modelos.produccion.lugar_trabajo',
                seccion: 'planilla',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Lugar de trabajo de producción',
                descripcion: 'Se propone en el contrato de quien trabaja en producción. Lo que ya está escrito en la ficha de cada persona no cambia.',
                claveConfig: 'planilla.contrato.modelos.produccion.lugar_trabajo',
                reglas: ['maxlen:255'],
            ),

            // ----------------------------------------------------------- Rutas
            DefinicionAjuste::hacer(
                clave: 'rutas.entregas_desde',
                seccion: 'rutas',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Controlar entregas desde',
                descripcion: 'Solo los CCF emitidos desde esta fecha cuentan como pendientes de entregar. Una fecha equivocada esconde entregas pendientes o llena la lista de viejas.',
                claveConfig: 'rutas.entregas_desde',
                porDefecto: '2026-09-28',
                reglas: ['fecha'],
            ),

            // ---------------------------------------------------------- Gastos
            DefinicionAjuste::hacer(
                clave: 'gastos.recurrencias.generacion_automatica',
                seccion: 'gastos',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Crear solos los gastos que se repiten',
                descripcion: 'Encendida, cada mañana se crean las deudas de las reglas de repetición. Apagada, se generan a mano desde la pantalla.',
                claveConfig: 'gastos.recurrencias.generacion_automatica',
                porDefecto: false,
            ),
            DefinicionAjuste::hacer(
                clave: 'gastos.avisos.automaticos',
                seccion: 'gastos',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Mandar solos los avisos de vencimiento',
                descripcion: 'Encendida, cada mañana se avisa de los pagos por vencer según lo que eligió cada persona.',
                claveConfig: 'gastos.avisos.automaticos',
                porDefecto: false,
            ),

            // --------------------------------------------------------- Compras
            // Sección propia y no `documentos_recibidos`: esa es la conexión al buzón
            // (Integraciones); esta es la tarea que lo lee sola.
            DefinicionAjuste::hacer(
                clave: 'documentos_recibidos.sincronizacion_automatica',
                seccion: 'compras',
                tipo: TipoAjuste::Booleano,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Bajar las compras del correo solo, cada quince minutos',
                descripcion: 'Encendida, el sistema lee el buzón de compras por su cuenta y guarda los documentos de los proveedores. Apagada, se bajan a mano desde Documentos recibidos. Necesita el buzón configurado en Integraciones.',
                claveConfig: 'documentos_recibidos.sincronizacion_automatica',
                porDefecto: false,
            ),

            // ------------------------------------------------------ Asistencia
            // Las leen el lector de huella en cada marca: pasan por la caché de
            // Ajustes, sin consulta propia. El rango incluye el 0 que ya admitía el
            // código (max(0, …)), para que un .env con 0 siga leyéndose.
            DefinicionAjuste::hacer(
                clave: 'asistencia.cooldown_segundos',
                seccion: 'asistencia',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N1,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Segundos de espera entre dos marcas de la misma persona',
                descripcion: 'Si alguien vuelve a poner el dedo antes de este tiempo, no se guarda otra marca: el lector le muestra la que ya hizo. Con 0 no se espera.',
                claveConfig: 'asistencia.cooldown_segundos',
                porDefecto: 90,
                reglas: ['min:0', 'max:600'],
            ),
            DefinicionAjuste::hacer(
                clave: 'asistencia.diferidas_max_dias',
                seccion: 'asistencia',
                tipo: TipoAjuste::Entero,
                sensibilidad: Sensibilidad::Interno,
                impacto: Impacto::Alto,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Días hacia atrás que se aceptan marcas hechas sin internet',
                descripcion: 'El lector guarda las marcas que hizo sin red y las manda después. Las más viejas que esto se rechazan. Cambia qué horas entran a la planilla.',
                claveConfig: 'asistencia.diferidas_max_dias',
                porDefecto: 31,
                reglas: ['min:0', 'max:60'],
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
            // EMPRESA EXPORTADORA (lista de empaque). Son datos DE LA EMPRESA,
            // no del cliente ni del embarque: el mismo número de registro FDA
            // vale para todas las listas y para todos los importadores.
            //
            // Vivían en tres sitios a la vez —config, el campo FDA de cada perfil
            // de cliente, y una copia congelada por lista—, así que editarlo en
            // uno lo dejaba viejo en los otros dos. Se declaran acá para que haya
            // UN lugar donde mirarlos.
            //
            // Se editan en Configuración › Módulos › Exportaciones. Sección propia
            // y no `fiscal_parametros`: son la identidad de la empresa en un
            // documento COMERCIAL, no un parámetro del DTE. Sus dos lectores
            // (App\Support\Exportaciones\DatosExportador y el formulario de una
            // lista nueva) pasan por Ajustes; el .env queda de respaldo.
            // ================================================================
            DefinicionAjuste::hacer(
                clave: 'exportaciones.fda_reg_number',
                seccion: 'exportaciones',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Número de registro FDA de la empresa',
                descripcion: 'Va en la casilla FDA de la lista de empaque. Es el de la EMPRESA exportadora; el del importador, si lo tiene, se guarda en la ficha de ese cliente.',
                claveConfig: 'exportaciones.fda_reg_number',
                porDefecto: '',
                reglas: ['maxlen:50'],
            ),
            DefinicionAjuste::hacer(
                clave: 'exportaciones.exportador_nombre',
                seccion: 'exportaciones',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Nombre del exportador (lista de empaque)',
                descripcion: 'Encabezado de la lista. Sin configurar se usa el valor histórico y, si tampoco existe, la razón social de la empresa emisora.',
                claveConfig: 'exportaciones.exportador_nombre',
                porDefecto: '',
                reglas: ['maxlen:255'],
            ),
            DefinicionAjuste::hacer(
                clave: 'exportaciones.exportador_direccion',
                seccion: 'exportaciones',
                tipo: TipoAjuste::Texto,
                sensibilidad: Sensibilidad::Publico,
                impacto: Impacto::Medio,
                nivel: NivelConfirmacion::N2,
                editabilidad: Editabilidad::Editable,
                persistencia: Persistencia::Nueva,
                etiqueta: 'Dirección del exportador (lista de empaque)',
                descripcion: 'Encabezado de la lista. Mismo respaldo que el nombre: valor histórico y, en su defecto, la dirección de la empresa emisora.',
                claveConfig: 'exportaciones.exportador_direccion',
                porDefecto: '',
                reglas: ['maxlen:255'],
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
                datoPersonal: true,
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
                datoPersonal: true,
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
