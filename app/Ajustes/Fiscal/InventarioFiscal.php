<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Ajustes;
use App\Enums\AmbienteHacienda;

/**
 * Traduce los ajustes fiscales del catálogo a FILAS LEGIBLES, agrupadas por
 * pantalla.
 *
 * Es la única pieza que sabe, a la vez, tres cosas de cada parámetro fiscal: qué
 * vale hoy, cómo se lee ese valor en castellano, y qué se va a poder hacer con
 * él ({@see ClasificacionFiscal}). Tenerlas juntas es el punto: separadas, la
 * pantalla acabaría diciendo «dry_run: 1» —que no significa nada para quien
 * administra— y nadie sabría si ese 1 es el estado seguro o el peligroso.
 *
 * REGLAS
 *  - CERO red. Ni un ping al firmador ni un login al MH. Igual que el Resumen:
 *    una pantalla de estado que espera a un servicio externo deja de ser una
 *    pantalla de estado. Lo que hay que comprobar de verdad tiene su botón.
 *  - CERO secretos. Ninguna credencial está declarada en el catálogo, así que no
 *    hay por dónde leerlas; de las que existen se habla en
 *    {@see EstadoHaciendaApi}, y solo para decir si están y de dónde salen.
 *  - CERO escritura. Todo lo que sale de aquí es para mirarse.
 *
 * Las NOTAS son la mitad del valor de estas pantallas: un inventario que solo
 * lista valores no evita ni un error. Ahí es donde se dice que una clave está
 * duplicada, que nadie la lee, o que su nombre significa lo contrario de lo que
 * parece.
 */
class InventarioFiscal
{
    public function __construct(private readonly Ajustes $ajustes) {}

    // ------------------------------------------------------------- Hacienda

    /**
     * Ambiente, endpoints y parámetros de la conexión con el MH.
     *
     * @return array<int, AjusteFiscal>
     */
    public function hacienda(): array
    {
        $ambiente = (string) $this->ajustes->texto('dte.ambiente', '00');
        $etiquetaAmbiente = AmbienteHacienda::tryFrom($ambiente)?->label() ?? 'valor fuera de CAT-001';

        return [
            $this->fila('dte.ambiente', $ambiente.' — '.$etiquetaAmbiente, ClasificacionFiscal::CriticaN3, 'DTE_AMBIENTE',
                nota: 'Es el ambiente que viaja DENTRO del documento. No es el mismo ajuste que el de abajo.'),

            $this->fila('dte.transmision.ambiente', $this->ambienteCredenciales(), ClasificacionFiscal::CriticaN3, 'DTE_TRANSMISION_AMBIENTE',
                nota: 'Decide contra qué cuenta y qué host del MH se autentica. Cruzarlo con el de arriba manda un documento marcado como producción con credenciales de pruebas, o al revés.'),

            $this->fila('dte.transmision.url_base', $this->textoODefecto('dte.transmision.url_base', 'vacío — se usa el host oficial del ambiente'), ClasificacionFiscal::CriticaN3, 'DTE_TRANSMISION_URL'),
            $this->fila('dte.transmision.endpoint_auth', $this->textoODefecto('dte.transmision.endpoint_auth', 'sin definir'), ClasificacionFiscal::CriticaN3, 'DTE_TRANSMISION_ENDPOINT_AUTH'),
            $this->fila('dte.transmision.endpoint_recepcion', $this->textoODefecto('dte.transmision.endpoint_recepcion', 'sin definir'), ClasificacionFiscal::CriticaN3, 'DTE_TRANSMISION_ENDPOINT_RECEPCION'),
            $this->fila('dte.transmision.timeout', $this->ajustes->entero('dte.transmision.timeout', 8).' s', ClasificacionFiscal::EditableN2, 'DTE_TRANSMISION_TIMEOUT'),
            $this->fila('dte.transmision.user_agent', $this->textoODefecto('dte.transmision.user_agent', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_TRANSMISION_USER_AGENT'),

            // No está en el catálogo: es una URL por ambiente que solo consume la
            // invalidación. Se muestra aquí porque es el tercer endpoint del MH y
            // buscarlo en otra pantalla sería absurdo.
            AjusteFiscal::hacer(
                etiqueta: 'Ruta de invalidación (anulación)',
                valor: $this->urlAnulacion(),
                clasificacion: ClasificacionFiscal::CriticaN3,
                descripcion: 'Dirección a la que se manda el evento de invalidación. Vacía = se usa la oficial del ambiente del documento.',
                env: 'DTE_TEST_ANULACION_URL / DTE_PROD_ANULACION_URL',
                fuente: 'Archivo de configuración / .env',
            ),
        ];
    }

    // -------------------------------------------------------------- candados

    /**
     * Los candados fiscales, TODOS, en un solo sitio y agrupados por lo que
     * gobiernan. Hasta ahora vivían repartidos entre `evaluarCandados()`, el
     * servicio de autenticación y la invalidación, y no había ninguna pantalla
     * donde verlos juntos.
     *
     * @return array<string, array<int, AjusteFiscal>> grupo ⇒ filas
     */
    public function candados(): array
    {
        return [
            'Firma' => [
                $this->candado('dte.firma.enabled', 'DTE_FIRMA_ENABLED', abiertoEsRiesgo: true,
                    siTrue: 'Habilitada: los documentos se firman con el certificado del emisor.',
                    siFalse: 'Deshabilitada: se genera el documento pero no se firma.'),
                $this->candado('dte.firma.mock', 'DTE_FIRMADOR_MOCK', abiertoEsRiesgo: true,
                    siTrue: 'ACTIVO: la firma es ficticia y en pantalla se ve igual que una real.',
                    siFalse: 'Apagado: si se firma, se firma de verdad.'),
            ],
            'Transmisión' => [
                $this->candado('dte.transmision.enabled', 'DTE_TRANSMISION_ENABLED', abiertoEsRiesgo: true,
                    siTrue: 'Habilitada: el interruptor maestro del envío está abierto.',
                    siFalse: 'Deshabilitada: no se envía nada al Ministerio de Hacienda.'),
                $this->candado('dte.transmision.mock', 'MH_MOCK', abiertoEsRiesgo: true,
                    siTrue: 'ACTIVO: el sello de recepción es ficticio y en pantalla se ve igual que uno real.',
                    siFalse: 'Apagado: si se transmite, se transmite de verdad.'),
                $this->candado('dte.transmision.real_confirmation', 'DTE_TRANSMISION_REAL_CONFIRMATION', abiertoEsRiesgo: true,
                    siTrue: 'Confirmada: este candado ya no bloquea el envío real.',
                    siFalse: 'Sin confirmar: bloquea el envío real aunque la transmisión esté habilitada.'),
                $this->candado('dte.transmision.allow_production', 'DTE_TRANSMISION_ALLOW_PRODUCTION', abiertoEsRiesgo: true,
                    siTrue: 'Autorizada: se permite usar el ambiente de producción.',
                    siFalse: 'No autorizada: estando en producción no se transmite.'),
                // Invertido a propósito: aquí lo peligroso es el FALSE.
                $this->candado('dte.transmision.dry_run', 'DTE_TRANSMISION_DRY_RUN', abiertoEsRiesgo: false,
                    siTrue: 'Activo: nunca hay conexión real; se arma el envío y se descarta.',
                    siFalse: 'APAGADO: ya no hay ensayo; con el resto de candados abiertos, el envío es real.'),
                $this->candado('dte.transmision.test_enabled', 'DTE_TRANSMISION_TEST_ENABLED', abiertoEsRiesgo: true,
                    siTrue: 'ABIERTA: se transmite de verdad al ambiente de PRUEBAS del MH, saltándose los candados de producción.',
                    siFalse: 'Cerrada: la vía de pruebas no está abierta.'),
            ],
            'Convivencia con el sistema anterior' => [
                $this->candado('dte.transmision.sistema_actual_activo', 'DTE_SISTEMA_ACTUAL_ACTIVO', abiertoEsRiesgo: false,
                    siTrue: 'Sí: este sistema no transmite salvo en modo principal, para no duplicar correlativos.',
                    siFalse: 'No: se ha declarado que el sistema anterior ya no factura.'),
                AjusteFiscal::deEstado(
                    $this->ajustes->estadoParaPantalla('dte.transmision.modo_operacion'),
                    $this->modoOperacion(),
                    ClasificacionFiscal::CriticaN3,
                    env: 'DTE_MODO_OPERACION',
                    atencion: $this->ajustes->texto('dte.transmision.modo_operacion', 'paralelo') === 'principal',
                ),
            ],
            'Pruebas de acceso' => [
                // null: ninguna de las dos posiciones es un riesgo. Solo inicia sesión.
                $this->candado('dte.transmision.auth_test_real_enabled', 'DTE_AUTH_TEST_REAL_ENABLED', abiertoEsRiesgo: null,
                    siTrue: 'Permitida: se puede comprobar el acceso a apitest. Solo inicia sesión, no envía documentos.',
                    siFalse: 'No permitida: la prueba de conexión con pruebas no llega a intentar el acceso.'),
                $this->candado('dte.transmision.auth_test_prod_enabled', 'DTE_AUTH_TEST_PROD_ENABLED', abiertoEsRiesgo: true,
                    siTrue: 'Permitida: se puede comprobar contra la cuenta REAL si la credencial es de producción. Descarta el token y no envía documentos.',
                    siFalse: 'No permitida: no se intenta ningún acceso contra la cuenta real.'),
            ],
            'Invalidación' => [
                $this->candado('dte.invalidacion.mock', 'DTE_INVALIDACION_MOCK', abiertoEsRiesgo: true,
                    siTrue: 'ACTIVO: el sello de invalidación es ficticio y en pantalla se ve igual que uno real.',
                    siFalse: 'Apagado: si se invalida, se invalida de verdad.'),
                $this->candado('dte.invalidacion.real_confirmation', 'DTE_INVALIDACION_REAL_CONFIRMATION', abiertoEsRiesgo: true,
                    siTrue: 'Confirmada: este candado ya no bloquea el envío real del evento.',
                    siFalse: 'Sin confirmar: el evento de invalidación no se envía.'),
                $this->candado('dte.invalidacion.produccion_enabled', 'DTE_INVALIDACION_PRODUCCION_ENABLED', abiertoEsRiesgo: true,
                    siTrue: 'Autorizada: un documento de producción puede invalidarse si el resto de candados lo permite.',
                    siFalse: 'No autorizada: un documento de producción NUNCA puede invalidarse, sin importar el resto.'),
            ],
        ];
    }

    /**
     * Cuántos candados están en su posición de RIESGO. Es lo que va en la
     * cabecera: «3 de 13 abiertos» se entiende de un vistazo; trece filas, no.
     *
     * @return array{abiertos: int, total: int}
     */
    public function resumenCandados(): array
    {
        $abiertos = 0;
        $total = 0;

        foreach ($this->candados() as $filas) {
            foreach ($filas as $fila) {
                $total++;
                $abiertos += $fila->atencion ? 1 : 0;
            }
        }

        return ['abiertos' => $abiertos, 'total' => $total];
    }

    // ------------------------------------------------------------- firmador

    /** @return array<int, AjusteFiscal> */
    public function firmador(): array
    {
        return [
            $this->fila('dte.firmador.url', $this->textoODefecto('dte.firmador.url', 'sin definir'), ClasificacionFiscal::CriticaN3, 'DTE_FIRMADOR_URL',
                nota: 'Es N3 aunque sea un servicio local: a esta dirección se le manda la contraseña del certificado en cada firma.'),
            $this->fila('dte.firma.timeout', $this->ajustes->entero('dte.firma.timeout', 10).' s', ClasificacionFiscal::EditableN2, 'DTE_FIRMA_TIMEOUT'),
            $this->fila('dte.firma.nit', $this->textoODefecto('dte.firma.nit', 'sin definir'), ClasificacionFiscal::CriticaN3, 'DTE_FIRMA_NIT'),
            AjusteFiscal::hacer(
                etiqueta: 'Contraseña del certificado',
                valor: filled(config('dte.firma.cert_password')) ? 'Configurada' : 'Sin configurar',
                clasificacion: ClasificacionFiscal::SoloServidor,
                descripcion: 'Se manda al firmador en cada firma. No se muestra, no se edita desde la web y no se guarda en la base de datos.',
                env: 'DTE_CERT_PASSWORD',
                fuente: 'Archivo del servidor (.env)',
                atencion: blank(config('dte.firma.cert_password')) && (bool) config('dte.firma.enabled'),
            ),
        ];
    }

    // ------------------------------------------------------------ parámetros

    /** @return array<int, AjusteFiscal> */
    public function parametros(): array
    {
        return [
            $this->fila('dte.iva_tasa', $this->porcentaje('dte.iva_tasa'), ClasificacionFiscal::SoloLectura,
                nota: 'Escrito en el archivo de configuración, sin variable de entorno. Es la tasa legal, no una preferencia de la empresa.'),
            $this->fila('dte.retencion_iva_tasa', $this->porcentaje('dte.retencion_iva_tasa'), ClasificacionFiscal::SoloLectura,
                nota: 'Escrito en el archivo de configuración, sin variable de entorno.'),
            $this->fila('dte.retencion_iva_umbral', '$'.$this->ajustes->decimal('dte.retencion_iva_umbral', '0'), ClasificacionFiscal::EditableN2,
                nota: 'La retención aplica cuando la base gravada neta SUPERA este monto (no cuando lo iguala).'),
            $this->fila('dte.factura_consumidor_final.receptor_obligatorio_desde', '$'.$this->ajustes->decimal('dte.factura_consumidor_final.receptor_obligatorio_desde', '0'), ClasificacionFiscal::SoloLectura),
            $this->fila('dte.condicion_operacion_default_contribuyente', $this->condicionOperacion(), ClasificacionFiscal::EditableN2),
            $this->fila('dte.json.forma_pago_default', $this->textoODefecto('dte.json.forma_pago_default', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_FORMA_PAGO_DEFAULT'),
            $this->fila('dte.json.plazo_credito_default', $this->plazoCredito(), ClasificacionFiscal::EditableN2, 'DTE_PLAZO_CREDITO_DEFAULT'),
            $this->fila('dte.json.periodo_credito_default', (string) $this->ajustes->entero('dte.json.periodo_credito_default', 0), ClasificacionFiscal::EditableN2, 'DTE_PERIODO_CREDITO_DEFAULT'),
        ];
    }

    /** Valores con los que nace un borrador de factura de exportación. @return array<int, AjusteFiscal> */
    public function exportacion(): array
    {
        return [
            $this->fila('dte.exportacion.recinto_fiscal_default', $this->textoODefecto('dte.exportacion.recinto_fiscal_default', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_FEX_RECINTO_FISCAL_DEFAULT'),
            $this->fila('dte.exportacion.tipo_regimen_default', $this->textoODefecto('dte.exportacion.tipo_regimen_default', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_FEX_TIPO_REGIMEN_DEFAULT'),
            $this->fila('dte.exportacion.regimen_default', $this->textoODefecto('dte.exportacion.regimen_default', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_FEX_REGIMEN_DEFAULT'),
            $this->fila('dte.exportacion.cod_incoterms_default', $this->textoODefecto('dte.exportacion.cod_incoterms_default', 'sin definir'), ClasificacionFiscal::EditableN2, 'DTE_FEX_COD_INCOTERMS_DEFAULT'),
            AjusteFiscal::hacer(
                etiqueta: 'Tipo de ítem por defecto (exportación)',
                valor: ((int) config('dte.exportacion.tipo_item_expor_default', 1)) === 1 ? '1 — Bienes' : '2 — Servicios',
                clasificacion: ClasificacionFiscal::SoloLectura,
                descripcion: 'No viene de un catálogo del MH: son dos opciones fijas del sistema.',
                fuente: 'Archivo de configuración',
                nota: 'Escrito en el archivo de configuración, sin variable de entorno.',
            ),
        ];
    }

    // ----------------------------------------------------------- invalidación

    /** Quién realiza y quién solicita el evento. @return array<int, AjusteFiscal> */
    public function personasInvalidacion(): array
    {
        return [
            $this->fila('dte.invalidacion.responsable.nombre', $this->textoODefecto('dte.invalidacion.responsable.nombre', 'sin configurar'), ClasificacionFiscal::EditableN2, 'DTE_INVALIDACION_RESP_NOMBRE',
                atencion: blank($this->ajustes->texto('dte.invalidacion.responsable.nombre'))),
            $this->fila('dte.invalidacion.responsable.num_doc', $this->documento('dte.invalidacion.responsable.num_doc', 'dte.invalidacion.responsable.tipo_doc'), ClasificacionFiscal::EditableN2, 'DTE_INVALIDACION_RESP_NUM_DOC / _TIPO_DOC'),
            $this->fila('dte.invalidacion.solicita.nombre', $this->textoODefecto('dte.invalidacion.solicita.nombre', 'sin configurar'), ClasificacionFiscal::EditableN2, 'DTE_INVALIDACION_SOL_NOMBRE',
                atencion: blank($this->ajustes->texto('dte.invalidacion.solicita.nombre'))),
            $this->fila('dte.invalidacion.solicita.num_doc', $this->documento('dte.invalidacion.solicita.num_doc', 'dte.invalidacion.solicita.tipo_doc'), ClasificacionFiscal::EditableN2, 'DTE_INVALIDACION_SOL_NUM_DOC / _TIPO_DOC'),
        ];
    }

    /** Esquema, códigos del emisor y documentos blindados. @return array<int, AjusteFiscal> */
    public function tecnicosInvalidacion(): array
    {
        $numeros = (array) config('dte.invalidacion.protegidos_numero_control', []);
        $codigos = (array) config('dte.invalidacion.protegidos_codigo_generacion', []);
        $protegidos = count($numeros) + count($codigos);

        return [
            $this->fila('dte.invalidacion.version', (string) $this->ajustes->entero('dte.invalidacion.version', 3), ClasificacionFiscal::SoloLectura, 'DTE_INVALIDACION_VERSION'),
            AjusteFiscal::hacer(
                etiqueta: 'Códigos del emisor ante el MH',
                valor: $this->codigosEmisor(),
                clasificacion: ClasificacionFiscal::CriticaN3,
                descripcion: 'Solo se rellenan si se confirma que los códigos que el MH tiene registrados difieren de los internos del establecimiento y del punto de venta.',
                env: 'DTE_INVALIDACION_COD_ESTABLE_MH / _COD_PUNTO_VENTA_MH',
                fuente: 'Archivo de configuración / .env',
            ),
            AjusteFiscal::hacer(
                etiqueta: 'Documentos blindados como evidencia',
                valor: $protegidos === 0 ? 'Ninguno' : $protegidos.' documento(s)',
                clasificacion: ClasificacionFiscal::SoloServidor,
                descripcion: 'Documentos que NUNCA pueden invalidarse por esta vía, ni simulada ni real, y sin ningún interruptor que lo permita.',
                env: 'DTE_INVALIDACION_PROTEGIDOS_NUMERO_CONTROL / _CODIGO_GENERACION',
                fuente: 'Archivo del servidor (.env)',
                nota: 'Es una lista de excepciones absolutas: se administra en el servidor a propósito, para que ninguna pantalla pueda desblindar un documento.',
            ),
        ];
    }

    // ------------------------------------------------- configuración muerta

    /**
     * Claves que existen en `config/dte.php` y que NINGÚN consumidor lee, o que
     * dicen lo mismo que otra clave.
     *
     * Se listan en pantalla en vez de borrarse en silencio: quitarlas es un cambio
     * al motor fiscal y merece su propia revisión. Mientras tanto, lo peligroso no
     * es que sobren — es que alguien las edite creyendo que sirven para algo. Esta
     * lista es lo que impide eso.
     *
     * Verificado por búsqueda sobre app/, resources/, routes/ y database/.
     *
     * @return array<int, array{clave: string, problema: string}>
     */
    public function configuracionMuerta(): array
    {
        return [
            ['clave' => 'dte.correlativo.formato', 'problema' => 'Duplica dte.json.numero_control_formato, que es la que sí se lee. Nadie lee esta.'],
            ['clave' => 'dte.correlativo.longitud', 'problema' => 'Duplica dte.json.numero_control_longitud_correlativo, que es la que sí se lee. Nadie lee esta.'],
            ['clave' => 'dte.json.invalidacion_version', 'problema' => 'Duplica dte.invalidacion.version, que es la que sí se lee. Nadie lee esta.'],
            ['clave' => 'dte.firma.driver', 'problema' => 'Ningún consumidor. El firmador es siempre el local del MH; el valor no elige nada.'],
            ['clave' => 'dte.storage.pdf', 'problema' => 'Ningún consumidor: el PDF no se guarda en disco por esta ruta.'],
            ['clave' => 'dte.decimales.*', 'problema' => 'Ningún consumidor: el redondeo lo fija App\\Support\\Dinero.'],
            ['clave' => 'dte.tipos / dte.estados', 'problema' => 'Ningún consumidor: se derivan de los enums TipoDte y EstadoDte, que es de donde los leen todos. La clave version_esquema se ELIMINÓ de dte.tipos: contradecía a dte.json.versiones, que es la fuente autoritativa de la versión de esquema.'],
            ['clave' => 'dte.nota_credito.requiere_documento_relacionado_para_emision', 'problema' => 'Ningún consumidor: la regla está en el validador, no en esta clave.'],
            ['clave' => 'dte.ambientes.*.consulta_url', 'problema' => 'Ningún consumidor: no hay consulta de estado implementada. auth_url / recepcion_url / anulacion_url SÍ se leen: son el override de URL completa por ambiente que resuelve App\Support\Dte\EndpointsHacienda.'],
            ['clave' => 'DTE_TRANSMISION_USER / DTE_TRANSMISION_PASSWORD', 'problema' => 'Credenciales anteriores a separar producción de pruebas. YA NO autentican: el respaldo silencioso de producción se eliminó. Solo quedan como señal de diagnóstico (DteTransmisionService::authConfigurado y dte:seguridad-check). En una instalación nueva no se definen.'],
        ];
    }

    // ---------------------------------------------------------------- interno

    /** Fila a partir de una clave del catálogo. */
    private function fila(
        string $clave,
        string $valor,
        ClasificacionFiscal $clasificacion,
        ?string $env = null,
        ?string $nota = null,
        bool $atencion = false,
    ): AjusteFiscal {
        return AjusteFiscal::deEstado(
            $this->ajustes->estadoParaPantalla($clave),
            $valor,
            $clasificacion,
            env: $env,
            nota: $nota,
            atencion: $atencion,
        );
    }

    /**
     * Fila de un CANDADO.
     *
     * `$abiertoEsRiesgo` tiene TRES valores y no dos, porque la realidad tiene
     * tres:
     *
     *   true  → lo peligroso es encenderlo (la transmisión, los mocks);
     *   false → lo peligroso es APAGARLO (el modo de ensayo: apagarlo es lo que
     *           quita la red de seguridad);
     *   null  → ninguna de las dos posiciones merece un aviso (la prueba de acceso
     *           contra apitest solo inicia sesión; abrirla no acerca a nadie a
     *           emitir nada).
     *
     * Con un booleano, el tercer caso acababa contado como riesgo permanente y la
     * cabecera decía «1 de 13 abiertos» con todo cerrado — que es la forma más
     * rápida de que nadie vuelva a mirar ese número.
     */
    private function candado(
        string $clave,
        string $env,
        ?bool $abiertoEsRiesgo,
        string $siTrue,
        string $siFalse,
    ): AjusteFiscal {
        $activo = $this->ajustes->bool($clave);

        return AjusteFiscal::deEstado(
            $this->ajustes->estadoParaPantalla($clave),
            $activo ? $siTrue : $siFalse,
            ClasificacionFiscal::CriticaN3,
            env: $env,
            atencion: $abiertoEsRiesgo !== null && $activo === $abiertoEsRiesgo,
        );
    }

    private function textoODefecto(string $clave, string $vacio): string
    {
        $valor = trim((string) $this->ajustes->texto($clave, ''));

        return $valor !== '' ? $valor : $vacio;
    }

    private function porcentaje(string $clave): string
    {
        $tasa = (float) $this->ajustes->decimal($clave, '0');

        return rtrim(rtrim(number_format($tasa * 100, 2, '.', ''), '0'), '.').' %';
    }

    private function ambienteCredenciales(): string
    {
        return $this->ajustes->texto('dte.transmision.ambiente', 'testing') === 'produccion'
            ? 'produccion — cuenta REAL del Ministerio de Hacienda'
            : 'testing — cuenta de pruebas (apitest)';
    }

    private function modoOperacion(): string
    {
        return match ($this->ajustes->texto('dte.transmision.modo_operacion', 'paralelo')) {
            'paralelo' => 'paralelo — este sistema no transmite; solo genera, firma en local y ensaya.',
            'respaldo' => 'respaldo — transmite solo con confirmación manual y revisión de correlativos.',
            'principal' => 'principal — este sistema es el oficial.',
            default => 'valor desconocido: por seguridad se trata como paralelo.',
        };
    }

    private function condicionOperacion(): string
    {
        return match ($this->ajustes->texto('dte.condicion_operacion_default_contribuyente', '2')) {
            '1' => '1 — Contado',
            '2' => '2 — Crédito',
            '3' => '3 — Otro',
            default => 'valor fuera de CAT-016',
        };
    }

    private function plazoCredito(): string
    {
        return match ($this->ajustes->texto('dte.json.plazo_credito_default', '01')) {
            '01' => '01 — Días',
            '02' => '02 — Meses',
            '03' => '03 — Años',
            default => 'valor fuera de CAT-018',
        };
    }

    /**
     * Documento de una persona del evento de invalidación. El TIPO no está en el
     * catálogo (es un código de dos dígitos que no se administra por separado), así
     * que se lee de config y se traduce aquí para que la fila diga «NIT 0614-…» y
     * no «36 / 0614-…».
     */
    private function documento(string $claveNumero, string $claveTipoConfig): string
    {
        $numero = trim((string) $this->ajustes->texto($claveNumero, ''));

        if ($numero === '') {
            return 'sin configurar';
        }

        $tipo = trim((string) config($claveTipoConfig, ''));

        return match ($tipo) {
            '36' => 'NIT '.$numero,
            '13' => 'DUI '.$numero,
            '' => $numero.' (sin tipo de documento)',
            default => 'tipo '.$tipo.': '.$numero,
        };
    }

    private function codigosEmisor(): string
    {
        $establecimiento = trim((string) config('dte.invalidacion.cod_estable_mh', ''));
        $puntoVenta = trim((string) config('dte.invalidacion.cod_punto_venta_mh', ''));

        if ($establecimiento === '' && $puntoVenta === '') {
            return 'Sin sustituir: se usan los códigos internos del establecimiento y del punto de venta.';
        }

        return 'Establecimiento: '.($establecimiento !== '' ? $establecimiento : 'interno')
            .' · Punto de venta: '.($puntoVenta !== '' ? $puntoVenta : 'interno');
    }

    /** URL de invalidación del ambiente del DOCUMENTO, que es la que decide el servicio. */
    private function urlAnulacion(): string
    {
        $ambiente = (string) $this->ajustes->texto('dte.ambiente', '00');
        $url = trim((string) config('dte.ambientes.'.$ambiente.'.anulacion_url', ''));

        return $url !== '' ? $url : 'vacía — se usa la dirección oficial del ambiente '.$ambiente;
    }
}
