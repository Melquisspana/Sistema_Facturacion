<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SerializadorInvalidacionMhTest extends TestCase
{
    use RefreshDatabase;

    /** Datos reales de la NC #74 aceptada por apitest (sello/UUID/numero de control). */
    private const NC_CODIGO_GENERACION = '437F5D8B-A746-46E1-8A60-BF74C17FE309';

    private const NC_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH'; // 40 chars

    private const NC_NUMERO_CONTROL = 'DTE-05-M001P001-000000000000020'; // 31 chars

    /**
     * NC tipo 05 ACEPTADA REALMENTE por el MH (in memory: relaciones seteadas y campos
     * de aceptación reales), sin persistir el DTE (mismo espíritu que los tests de
     * serializadores). No toca el flujo de generación/firma/transmisión.
     */
    private function ncAceptada(bool $aceptada = true): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas',
            'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012',
            'nrc' => '1014765',
            'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com',
            'ambiente' => '00',
            'activo' => true,
        ]);
        $estab = Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true,
        ]);
        $pv = PuntoVenta::create([
            'establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true,
        ]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.',
            'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343',
            'correo' => 'melquicedeespana@gmail.com',
        ]);

        // DTE en memoria (no persistido): así el serializador lee atributos + relaciones
        // sin depender del observer de inmutabilidad ni del flujo de emisión.
        $dte = new Dte([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'ambiente' => AmbienteHacienda::Pruebas->value,
            'numero_control' => self::NC_NUMERO_CONTROL,
            'codigo_generacion' => self::NC_CODIGO_GENERACION,
            'sello_recepcion' => $aceptada ? self::NC_SELLO : null,
            'fecha_emision' => '2026-06-30',
            'hora_emision' => '22:26:52',
        ]);
        $dte->estado = $aceptada ? EstadoDte::Aceptado : EstadoDte::Generado;
        // aceptadoRealmentePorMh() exige huella de procesamiento real del MH.
        $dte->fecha_procesamiento_mh = $aceptada ? Carbon::parse('2026-06-30 22:48:44') : null;

        $estab->setRelation('empresa', $empresa);
        $dte->setRelation('establecimiento', $estab);
        $dte->setRelation('puntoVenta', $pv);
        $dte->setRelation('cliente', $cliente);

        return $dte;
    }

    private function evento(TipoAnulacionMh $tipo = TipoAnulacionMh::RescindirOperacion, ?string $reemplazo = null, ?string $motivo = null): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: $tipo,
            nombreResponsable: 'Melqui Administrador',
            tipoDocResponsable: '13',
            numDocResponsable: '040000000',
            nombreSolicita: 'Calleja Cuentas por Pagar',
            tipoDocSolicita: '36',
            numDocSolicita: '06141101690011',
            motivoAnulacion: $motivo,
            codigoGeneracionReemplazo: $reemplazo,
        );
    }

    public function test_evento_es_valido_contra_schema_v3(): void
    {
        $dte = $this->ncAceptada();
        $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

        $res = app(DteSchemaValidator::class)->validarInvalidacion($evento);

        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_usa_los_datos_reales_de_la_nc_en_el_bloque_documento(): void
    {
        $dte = $this->ncAceptada();
        $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

        $doc = $evento['documento'];
        $this->assertSame('05', $doc['tipoDte']);
        $this->assertSame(self::NC_CODIGO_GENERACION, $doc['codigoGeneracion']);
        $this->assertSame(self::NC_SELLO, $doc['selloRecibido']);
        $this->assertSame(self::NC_NUMERO_CONTROL, $doc['numeroControl']);
        $this->assertSame('2026-06-30', $doc['fecEmi']);
        // Receptor del DTE invalidado, NIT sin guiones.
        $this->assertSame('06141101690011', $doc['numDocumento']);
        $this->assertSame('Calleja, S.A. de C.V.', $doc['nombre']);
        // Emisor real.
        $this->assertSame('10132512610012', $evento['emisor']['nit']);
        $this->assertSame('M001', $evento['emisor']['codEstableMH']);
        $this->assertSame('P001', $evento['emisor']['codPuntoVentaMH']);
    }

    public function test_fecemi_del_evento_coincide_con_la_fecha_del_dte_no_con_now(): void
    {
        // REGRESIÓN (rechazo real anulardte codigoMsg 027 "[identificacion.fecEmi] DATO
        // NO COINCIDE CON DTE"): identificacion.fecEmi debe ser la fecha del DTE original
        // (2026-06-30), NO la fecha actual. Congelamos "now" en una fecha DISTINTA para
        // que el comportamiento anterior (now()) fallara.
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 09:30:00');
        try {
            $dte = $this->ncAceptada();
            $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

            $this->assertSame('2026-06-30', $evento['identificacion']['fecEmi'], 'fecEmi del evento debe ser la fecha del DTE, no now().');
            $this->assertSame($evento['documento']['fecEmi'], $evento['identificacion']['fecEmi'], 'identificacion.fecEmi y documento.fecEmi deben coincidir.');
            // horEmi SÍ es la del momento del evento (now); se documenta el comportamiento.
            $this->assertSame('09:30:00', $evento['identificacion']['horEmi']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_codigo_generacion_del_evento_es_uuid_nuevo_distinto_al_de_la_nc(): void
    {
        $dte = $this->ncAceptada();
        $serializador = app(SerializadorInvalidacionMh::class);

        $a = $serializador->serializar($dte, $this->evento());
        $b = $serializador->serializar($dte, $this->evento());

        $uuidEvento = $a['identificacion']['codigoGeneracion'];
        $this->assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/',
            $uuidEvento
        );
        $this->assertNotSame(self::NC_CODIGO_GENERACION, $uuidEvento, 'El UUID del evento no debe ser el de la NC.');
        // Cada corrida genera un UUID nuevo.
        $this->assertNotSame($uuidEvento, $b['identificacion']['codigoGeneracion']);
    }

    public function test_estructura_tiene_exactamente_los_cuatro_bloques_requeridos(): void
    {
        $dte = $this->ncAceptada();
        $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

        $this->assertSame(['identificacion', 'emisor', 'documento', 'motivo'], array_keys($evento));
        // identificacion del evento con version 3 y fusion null.
        $this->assertSame(3, $evento['identificacion']['version']);
        $this->assertNull($evento['identificacion']['fusion']);
        $this->assertSame(2, $evento['motivo']['tipoAnulacion']);
    }

    public function test_no_agrega_propiedades_extra_fuera_del_schema(): void
    {
        $dte = $this->ncAceptada();
        $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

        // additionalProperties:false en cada bloque → validar detecta cualquier extra.
        $evento['documento']['campoInventado'] = 'x';
        $res = app(DteSchemaValidator::class)->validarInvalidacion($evento);

        $this->assertFalse($res['valido']);
        $this->assertNotEmpty($res['errores']);
    }

    public function test_tipo_1_exige_documento_de_reemplazo(): void
    {
        $dte = $this->ncAceptada();

        // Sin reemplazo → falla claramente.
        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento(TipoAnulacionMh::ErrorInformacion));
            $this->fail('Debió lanzar DteNoSerializableException por falta de documento de reemplazo.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('reemplazo', implode(' ', $e->problemas));
        }

        // Con reemplazo válido → serializa y lo coloca en codigoGeneracionR.
        $reemplazo = 'A1B2C3D4-E5F6-4A8B-9C0D-1E2F3A4B5C6D';
        $evento = app(SerializadorInvalidacionMh::class)
            ->serializar($dte, $this->evento(TipoAnulacionMh::ErrorInformacion, reemplazo: $reemplazo));

        $this->assertSame($reemplazo, $evento['documento']['codigoGeneracionR']);
        $this->assertSame(1, $evento['motivo']['tipoAnulacion']);
    }

    public function test_tipo_2_no_lleva_documento_de_reemplazo(): void
    {
        $dte = $this->ncAceptada();
        $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento(TipoAnulacionMh::RescindirOperacion));

        $this->assertNull($evento['documento']['codigoGeneracionR']);
    }

    public function test_no_permite_invalidar_si_la_nc_no_esta_aceptada_por_mh(): void
    {
        $dte = $this->ncAceptada(aceptada: false); // estado generado, sin sello/fecha MH

        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());
            $this->fail('Debió lanzar DteNoSerializableException: la NC no está aceptada por el MH.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('aceptado realmente por Hacienda', implode(' ', $e->problemas));
        }
    }

    public function test_no_permite_invalidar_una_aceptacion_mock(): void
    {
        $dte = $this->ncAceptada();
        // Sello MOCK (aceptación simulada) → aceptadoRealmentePorMh() debe rechazarlo.
        $dte->sello_recepcion = 'MOCK-SIMULADO-ABCDEF0123456789';

        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());
            $this->fail('Debió rechazar una aceptación MOCK.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('aceptado realmente por Hacienda', implode(' ', $e->problemas));
        }
    }

    /**
     * REGRESIÓN: el .env de esta máquina tiene DTE_INVALIDACION_RESP_NOMBRE /
     * DTE_INVALIDACION_SOL_NOMBRE con "Elsa Fidelina HernÃ¡ndez CaÃ±as" — eso es
     * doble-codificación UTF-8 (bytes UTF-8 reinterpretados como Windows-1252 y
     * re-guardados como UTF-8) YA DENTRO del archivo .env, no un artefacto de la
     * consola de Windows. Esta prueba NO toca el .env: confirma que, cuando el nombre
     * llega CORRECTAMENTE codificado (como aquí, literal UTF-8 del propio archivo de
     * test), el serializador y el JSON final (mismo json_encode con
     * JSON_UNESCAPED_UNICODE que usa DteInvalidacionService::guardarArchivos) lo
     * conservan intacto y no introducen mojibake. El .env sigue pendiente de corregir
     * por separado (fuera del alcance de este cambio).
     */
    public function test_evento_serializado_conserva_utf8_correcto_en_nombre_responsable_y_solicitante(): void
    {
        $dte = $this->ncAceptada();
        $nombre = 'Elsa Fidelina Hernández Cañas';
        $evento = new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: $nombre, tipoDocResponsable: '36', numDocResponsable: '10132512610012',
            nombreSolicita: $nombre, tipoDocSolicita: '36', numDocSolicita: '10132512610012',
        );

        $eventoJson = app(SerializadorInvalidacionMh::class)->serializar($dte, $evento);

        $this->assertSame($nombre, $eventoJson['motivo']['nombreResponsable']);
        $this->assertSame($nombre, $eventoJson['motivo']['nombreSolicita']);

        $codificado = (string) json_encode($eventoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Elsa Fidelina Hernández Cañas', $codificado);
        // Patrón de mojibake típico de la doble codificación UTF-8/Windows-1252: NO debe
        // aparecer en el JSON generado por el código (sí aparece hoy en el .env real).
        $this->assertStringNotContainsString('Ã¡', $codificado);
        $this->assertStringNotContainsString('Ã±', $codificado);
    }
}
