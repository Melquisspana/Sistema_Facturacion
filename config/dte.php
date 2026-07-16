<?php

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;

/*
|--------------------------------------------------------------------------
| Configuración del motor de Documentos Tributarios Electrónicos (DTE)
|--------------------------------------------------------------------------
|
| Este archivo deja preparada la configuración del DTE para las fases
| posteriores (motor, firma y envío a Hacienda). En la Fase 1 NO se usan
| credenciales reales: todas las URLs y secretos se leen del .env con
| valores vacíos por defecto. Nunca escribir credenciales aquí.
|
*/

// Tipos de DTE habilitados, indexados por su código MH.
$tiposDte = [];
foreach (TipoDte::habilitados() as $tipo) {
    $tiposDte[$tipo->value] = [
        'codigo' => $tipo->value,
        'nombre' => $tipo->label(),
        'version_esquema' => $tipo->versionEsquema(),
    ];
}

// Estados posibles del documento, indexados por su valor.
$estadosDte = [];
foreach (EstadoDte::cases() as $estado) {
    $estadosDte[$estado->value] = $estado->label();
}

return [

    /*
    | Ambiente activo: '00' = pruebas, '01' = producción (CAT-001).
    | Se controla por .env para no mezclar pruebas con producción.
    */
    'ambiente' => env('DTE_AMBIENTE', AmbienteHacienda::Pruebas->value),

    /*
    | Endpoints del Ministerio de Hacienda por ambiente. Vacíos por ahora;
    | se completarán con las URLs oficiales en la fase de integración.
    */
    'ambientes' => [
        AmbienteHacienda::Pruebas->value => [
            'nombre' => 'Pruebas',
            'auth_url' => env('DTE_TEST_AUTH_URL', ''),
            'recepcion_url' => env('DTE_TEST_RECEPCION_URL', ''),
            'anulacion_url' => env('DTE_TEST_ANULACION_URL', ''),
            'consulta_url' => env('DTE_TEST_CONSULTA_URL', ''),
        ],
        AmbienteHacienda::Produccion->value => [
            'nombre' => 'Producción',
            'auth_url' => env('DTE_PROD_AUTH_URL', ''),
            'recepcion_url' => env('DTE_PROD_RECEPCION_URL', ''),
            'anulacion_url' => env('DTE_PROD_ANULACION_URL', ''),
            'consulta_url' => env('DTE_PROD_CONSULTA_URL', ''),
        ],
    ],

    /*
    | Firmador oficial del MH (servicio local). FUENTE ÚNICA de la URL del firmador:
    | DteFirmaService usa SIEMPRE esta URL (POST = .../firmardocumento/, health =
    | .../firmardocumento/status). No hay otra URL del firmador en el servicio.
    */
    'firmador' => [
        'url' => env('DTE_FIRMADOR_URL', 'http://localhost:8113/firmardocumento'),
    ],

    /*
    | Firma del DTE — FASE DE PREPARACIÓN. DESHABILITADA por defecto: el sistema
    | NO firma documentos todavía. Cuando se habilite, DteFirmaService usará el
    | firmador local del MH ('firmador.url') con el NIT del emisor y la clave del
    | certificado (DTE_CERT_PASSWORD, en 'credenciales'). Solo placeholders aquí:
    | NUNCA escribir certificados ni passwords en el repositorio (van en .env).
    */
    'firma' => [
        // Interruptor maestro. En esta fase debe quedar en false (no se firma).
        'enabled' => (bool) env('DTE_FIRMA_ENABLED', false),
        // MODO MOCK (solo local): si true, DteFirmaService SIMULA la firma sin
        // firmador real, sin certificado y sin claves: genera un JWS FICTICIO
        // claramente marcado. No vale ante Hacienda. Para probar el flujo en local.
        'mock' => (bool) env('DTE_FIRMADOR_MOCK', false),
        // Driver previsto: el firmador local del MH (Java) expuesto por HTTP.
        'driver' => env('DTE_FIRMA_DRIVER', 'firmador_mh_local'),
        // La URL del firmador es ÚNICA y vive en 'dte.firmador.url' (arriba). Aquí ya
        // NO se define url/status_path/endpoint_path para no divergir.
        // Timeout (segundos) de las llamadas HTTP al firmador.
        'timeout' => (int) env('DTE_FIRMA_TIMEOUT', 10),
        // NIT del emisor (solo dígitos). Define el nombre <NIT>.crt del certificado.
        'nit' => env('DTE_FIRMA_NIT', ''),
        // Contraseña del certificado de firma. SIEMPRE desde .env, NUNCA en el repo
        // ni en logs. Se envía al firmador en cada request (passwordPri).
        'cert_password' => env('DTE_CERT_PASSWORD', ''),
    ],

    /*
    | Transmisión del DTE firmado a Hacienda (recepción) — FASE DE PREPARACIÓN.
    | DESHABILITADA por defecto: el sistema NO transmite nada. Cuando se habilite,
    | DteTransmisionService hará el POST de recepción. Endpoints VACÍOS por ahora.
    | Credenciales/tokens SIEMPRE desde .env, NUNCA en el repo ni en logs.
    */
    'transmision' => [
        // Interruptor maestro. En esta fase debe quedar en false (no se transmite).
        'enabled' => (bool) env('DTE_TRANSMISION_ENABLED', false),
        // MODO MOCK (solo local): si true, DteTransmisionService SIMULA la respuesta
        // de Hacienda sin credenciales, sin token y sin HTTP real: devuelve un
        // resultado "aceptado" con un sello FICTICIO marcado. No equivale a una
        // transmisión real. Para probar el flujo en local sin producción.
        'mock' => (bool) env('MH_MOCK', false),

        // CANDADO DEDICADO DE PRUEBAS: habilita la transmisión REAL pero SOLO contra
        // el ambiente de pruebas (apitest). Cuando ambiente='testing' y este flag es
        // true, se permite el envío a apitest sin exigir los candados de producción
        // (real_confirmation / modo principal / dry_run). NO afecta a producción: si
        // ambiente='produccion' este flag se ignora y siguen mandando los candados de
        // abajo. Default false.
        'test_enabled' => (bool) env('DTE_TRANSMISION_TEST_ENABLED', false),

        // --- Candados de seguridad ANTES de cualquier transmisión real ---
        // Confirmación explícita de transmisión real (doble interruptor).
        'real_confirmation' => (bool) env('DTE_TRANSMISION_REAL_CONFIRMATION', false),
        // Autorización para usar el ambiente de producción.
        'allow_production' => (bool) env('DTE_TRANSMISION_ALLOW_PRODUCTION', false),
        // Modo dry-run: si true, NUNCA se hace HTTP real (solo simulación/diagnóstico).
        'dry_run' => (bool) env('DTE_TRANSMISION_DRY_RUN', true),
        // Candado SOLO para `dte:auth-test`: permite intentar el login real contra el
        // ambiente de PRUEBAS (apitest). No habilita transmisión de DTE. Default false.
        'auth_test_real_enabled' => (bool) env('DTE_AUTH_TEST_REAL_ENABLED', false),
        // Candado SEPARADO para `dte:auth-test --prod`: permite intentar el login real
        // contra el ambiente de PRODUCCIÓN (api.dtes.mh.gob.sv) SOLO para verificar si
        // la credencial es de producción. Es login-only: NO cachea el token, NO lo
        // devuelve, NO habilita transmisión de DTE. Default false.
        'auth_test_prod_enabled' => (bool) env('DTE_AUTH_TEST_PROD_ENABLED', false),
        // El SISTEMA ACTUAL de facturación sigue siendo el oficial en uso: bloquea la
        // transmisión real (salvo modo 'principal') para evitar duplicar documentos,
        // correlativos o transmisiones entre el sistema actual y el nuevo.
        'sistema_actual_activo' => (bool) env('DTE_SISTEMA_ACTUAL_ACTIVO', true),
        // Modo de operación del sistema NUEVO respecto del sistema actual:
        //  - 'paralelo'  : el actual factura oficialmente; el nuevo solo genera JSON,
        //                  firma local y dry-run. NO transmite (bloqueado siempre).
        //  - 'respaldo'  : el nuevo solo transmite con confirmación manual fuerte
        //                  (real_confirmation) y revisión de correlativos.
        //  - 'principal' : el nuevo sería el oficial. NO usar hasta definir migración.
        'modo_operacion' => env('DTE_MODO_OPERACION', 'paralelo'),
        // Ambiente lógico de transmisión (testing/produccion). No confundir con el
        // 'ambiente' MH del DTE ('00'/'01'); este es solo un rótulo operativo.
        'ambiente' => env('DTE_TRANSMISION_AMBIENTE', 'testing'),
        // URL base del servicio del MH (vacía hasta integración real). Si queda vacía,
        // el servicio de auth usa el host por defecto según el ambiente (apitest/api).
        'url_base' => env('DTE_TRANSMISION_URL', ''),
        // Path del endpoint de autenticación (POST form-urlencoded user+pwd).
        'endpoint_auth' => env('DTE_TRANSMISION_ENDPOINT_AUTH', '/seguridad/auth'),
        // Path del endpoint de recepción (POST). Vacío hasta confirmar manual técnico.
        'endpoint_recepcion' => env('DTE_TRANSMISION_ENDPOINT_RECEPCION', ''),
        // Timeout (segundos) de las llamadas HTTP a recepción. El manual define un
        // umbral de ~8s antes de aplicar la política de reintentos.
        'timeout' => (int) env('DTE_TRANSMISION_TIMEOUT', 15),
        // User-Agent requerido por los servicios de recepción del MH.
        'user_agent' => env('DTE_TRANSMISION_USER_AGENT', 'DulcesLaNegrita-DTE/1.0'),
        // Credenciales/token de la API de Hacienda. Solo desde .env, nunca en código.
        // El token se OBTIENE del servicio de autenticación (/seguridad/auth) con
        // usuario_api + password (form-urlencoded). Vigencia: pruebas 48h, prod 24h.
        'usuario_api' => env('DTE_TRANSMISION_USER', ''),
        'password' => env('DTE_TRANSMISION_PASSWORD', ''),
        'token' => env('DTE_TRANSMISION_TOKEN', ''),

        // Credenciales SEPARADAS por ambiente (producción y apitest/homologación son
        // cuentas DISTINTAS en Hacienda). Usadas por DteTransmisionAuthService para
        // elegir el par correcto según dte.transmision.ambiente:
        //  - producción: cae de vuelta a DTE_TRANSMISION_USER/PASSWORD (arriba) mientras
        //    no se definan DTE_PROD_*, para no romper lo que ya funciona hoy con CCF real.
        //  - testing: SIN fallback. Si faltan, el login se bloquea antes de cualquier HTTP.
        'usuario_produccion' => env('DTE_PROD_USER', env('DTE_TRANSMISION_USER', '')),
        'password_produccion' => env('DTE_PROD_PASSWORD', env('DTE_TRANSMISION_PASSWORD', '')),
        'usuario_testing' => env('DTE_TEST_USER', ''),
        'password_testing' => env('DTE_TEST_PASSWORD', ''),
    ],

    /*
    | Evento de INVALIDACIÓN oficial (anulación de un DTE ya aceptado por el MH).
    | FASE DE PREPARACIÓN: solo se genera y valida el evento JSON; NO se firma ni se
    | transmite a /fesv/anulardte todavía. Los datos del responsable/solicitante son
    | obligatorios en el schema y hoy NO están en la BD: se leen de .env.
    |
    | TODO (pendiente de confirmar en el Manual Técnico del MH, no está en texto en el
    | repo): qué tipoAnulacion (CAT-024) corresponde para una NC tipo 05 aceptada y si
    | aplica ventana de tiempo. Mientras no se confirme, el tipo se pasa EXPLÍCITO.
    */
    'invalidacion' => [
        // Versión del schema del evento (invalidacion-schema-v3.json).
        'version' => (int) env('DTE_INVALIDACION_VERSION', 3),
        // MODO MOCK (Fase C): firma SIMULADA del evento, sin firmador real y sin
        // transmitir. Genera un JWS ficticio y un sello de invalidación marcado. NO
        // vale ante Hacienda. La firma/transmisión real llega en fases posteriores.
        'mock' => (bool) env('DTE_INVALIDACION_MOCK', false),
        // CANDADO de la transmisión REAL del evento a /fesv/anulardte (Fase D). Debe ser
        // true (además del mock apagado y los flags del comando) para transmitir de
        // verdad contra apitest. Nunca habilita producción. Default false.
        'real_confirmation' => (bool) env('DTE_INVALIDACION_REAL_CONFIRMATION', false),
        // Overrides de los códigos MH del emisor SOLO si se confirma que difieren de
        // los internos (M001/P001). Vacío = usar los internos del establecimiento/PV.
        'cod_estable_mh' => env('DTE_INVALIDACION_COD_ESTABLE_MH', ''),
        'cod_punto_venta_mh' => env('DTE_INVALIDACION_COD_PUNTO_VENTA_MH', ''),
        // Datos de quien REALIZA el evento (responsable). Obligatorios en el schema.
        // TODO: completar con datos reales antes de invalidar de verdad.
        'responsable' => [
            'nombre' => env('DTE_INVALIDACION_RESP_NOMBRE', ''),
            'tipo_doc' => env('DTE_INVALIDACION_RESP_TIPO_DOC', ''),   // CAT-022 (36=NIT, 13=DUI)
            'num_doc' => env('DTE_INVALIDACION_RESP_NUM_DOC', ''),
        ],
        // Datos de quien SOLICITA el evento. Obligatorios en el schema.
        'solicita' => [
            'nombre' => env('DTE_INVALIDACION_SOL_NOMBRE', ''),
            'tipo_doc' => env('DTE_INVALIDACION_SOL_TIPO_DOC', ''),
            'num_doc' => env('DTE_INVALIDACION_SOL_NUM_DOC', ''),
        ],
    ],

    /*
    | Credenciales y secretos: SIEMPRE desde .env, nunca en el repositorio.
    | Vacías en Fase 1.
    */
    'credenciales' => [
        'usuario_api' => env('DTE_API_USER', ''),
        'password_api' => env('DTE_API_PASSWORD', ''),
        'password_certificado' => env('DTE_CERT_PASSWORD', ''),
    ],

    'tipos' => $tiposDte,

    'estados' => $estadosDte,

    /*
    | Parámetros fiscales y de cálculo.
    */
    'iva_tasa' => 0.13,

    /*
    | Tasa de retención de IVA (CCF a agentes de retención). Inicialmente 1%.
    | Configurable sin tocar la calculadora. La BASE de la retención es el
    | gravado NETO sin IVA (después de descuentos); decisión pendiente de
    | confirmación final con el contador / documentación oficial del MH.
    */
    'retencion_iva_tasa' => 0.01,

    /*
    | Umbral de base gravada NETA (sin IVA, después de descuentos) a partir del
    | cual aplica la retención de IVA en CCF a agentes de retención. La retención
    | aplica cuando la base gravada neta SUPERA este monto. Editable.
    */
    'retencion_iva_umbral' => 100,

    /*
    | Factura de consumidor final (01): monto a partir del cual se exige receptor
    | identificado (nombre + documento). Confirmado: $25,000.00. Es un umbral ESTRICTO
    | ("mayor que", no "mayor o igual"): exactamente $25,000.00 NO exige receptor;
    | $25,000.01 SÍ lo exige (ValidacionPreJsonService usa total_pagar > este valor).
    | Pendiente aparte (no relacionado a este umbral): si la factura se usará para
    | deducción de costos/gastos del cliente, hoy no hay campo/checkbox para eso.
    | Esto NO habilita producción para el tipo 01 (bloqueada por otros candados ya
    | existentes).
    */
    'factura_consumidor_final' => [
        'receptor_obligatorio_desde' => 25000.00,
    ],

    /*
    | Condición de operación por defecto para CCF a contribuyentes cuando ni el
    | cliente ni la sucursal definen una (CAT-016: 1 contado, 2 crédito, 3 otro).
    | Editable.
    */
    'condicion_operacion_default_contribuyente' => 2, // Crédito

    /*
    | Reglas internas de la nota de crédito. La emisión oficial ante el MH puede
    | exigir documento relacionado; mientras se confirma el schema, este flag
    | permite bloquear la generación de JSON de una NC sin documento relacionado.
    */
    'nota_credito' => [
        'requiere_documento_relacionado_para_emision' => true,
    ],

    /*
    | Presentación del PDF / representación gráfica preliminar (solo estética).
    */
    'pdf' => [
        // Logo del emisor (PNG/JPG/SVG). Si el archivo no existe, el PDF no se rompe:
        // se muestra solo el texto. Cambiá la ruta acá o por .env (DTE_PDF_LOGO_PATH).
        'logo_path' => env('DTE_PDF_LOGO_PATH', public_path('images/dte/logo-transparent.png')),
        // URL base de la consulta pública del MH para el QR OFICIAL (solo se usa cuando
        // existe sello de recepción; nunca se inventa un QR).
        'consulta_qr_url' => env('DTE_PDF_QR_URL', 'https://admin.factura.gob.sv/consultaPublica'),
    ],

    /*
    | Parámetros preparatorios del JSON oficial (NO se usan todavía para emitir).
    | Editables; sujetos a validación contra los JSON Schema oficiales del MH
    | cuando se versionen en el proyecto.
    */
    'json' => [

        // Versión del esquema por tipo de DTE (CAT-002), según el archivo oficial
        // colocado en resources/dte/schemas/<tipo>/.
        'versiones' => [
            '01' => 2, // Factura            (fe-f-v2.json)
            '03' => 4, // Crédito Fiscal     (fe-ccf-v4.json)
            '05' => 3, // Nota de Crédito    (fe-nc-v3.json) — el MH acepta v3 para tipo 05
            '11' => 3, // Factura Exportación (fe-fex-v3.json)
        ],

        // Esquema del evento de INVALIDACIÓN (no es un tipo de DTE; se usará en la
        // fase de anulación oficial). Archivo: resources/dte/schemas/invalidacion/.
        'invalidacion_version' => 3, // invalidacion-schema-v3.json

        // identificacion: valores por defecto (1 = normal).
        'tipo_modelo' => 1,     // 1 normal, 2 contingencia
        'tipo_operacion' => 1,  // 1 normal (transmisión normal), 2 contingencia

        // Códigos de tributo (CAT-015). IVA 13% = "20".
        'tributos' => [
            'iva' => '20',
        ],

        // Forma de pago por defecto (CAT-017) cuando el documento no especifica una.
        // "01" = Billetes y monedas. Se usa para construir resumen.pagos del CCF.
        'forma_pago_default' => env('DTE_FORMA_PAGO_DEFAULT', '01'),

        // Plazo del crédito para resumen.pagos cuando la operación es A CRÉDITO
        // (condicionOperacion=2). El MH exige plazo+periodo para crédito:
        //   plazo  = código CAT-018 (01=Días, 02=Meses, 03=Años)
        //   periodo = cantidad (número entero > 0)
        // Default 30 días; ajustar al plazo real de crédito acordado.
        'plazo_credito_default' => env('DTE_PLAZO_CREDITO_DEFAULT', '01'),
        'periodo_credito_default' => (int) env('DTE_PERIODO_CREDITO_DEFAULT', 30),

        /*
        | Formato base del número de control. Partes:
        |  {tipo}          → 2 dígitos (CAT-002)
        |  {establecimiento} {puntoVenta} → 4 + 4 caracteres (códigos MH)
        |  {correlativo}    → 15 dígitos
        | Ej.: DTE-03-M001P001-000000000000001
        | NOTA: preparatorio; el número de control OFICIAL se confirma contra el
        | esquema/normativa MH antes de emitir.
        */
        'numero_control_formato' => 'DTE-{tipo}-{establecimiento}{puntoVenta}-{correlativo}',
        'numero_control_longitud_correlativo' => 15,
    ],

    'decimales' => [
        'montos' => 2,      // Totales y montos monetarios
        'cantidades' => 8,  // Cantidades por línea
        'precios' => 8,     // Precios unitarios
    ],

    /*
    | Formato del número de control y longitud del correlativo.
    | Ej: DTE-03-M001P001-000000000000001
    */
    'correlativo' => [
        'longitud' => 15,
        'formato' => 'DTE-{tipo}-{establecimiento}{puntoVenta}-{correlativo}',
    ],

    /*
    | Rutas (relativas al disco privado) donde se guardarán los archivos del DTE.
    | Se crearán y usarán en fases posteriores.
    */
    'storage' => [
        'disk' => env('DTE_STORAGE_DISK', 'local'),
        'json' => 'dte/json',
        'firmados' => 'dte/firmados',
        'pdf' => 'dte/pdf',
        'respuestas' => 'dte/respuestas',
        // Evento de invalidación (JSON, JWS firmado y respuesta del MH), separados de
        // los archivos del DTE original.
        'invalidacion_json' => 'dte/invalidacion/json',
        'invalidacion_firmados' => 'dte/invalidacion/firmados',
        'invalidacion_respuestas' => 'dte/invalidacion/respuestas',
    ],
];
