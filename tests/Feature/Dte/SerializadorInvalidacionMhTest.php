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
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SerializadorInvalidacionMhTest extends TestCase
{
    use RefreshDatabase;

    /** Datos reales de la NC #74 aceptada por apitest (sello/UUID/numero de control). */
    private const NC_CODIGO_GENERACION = '00000000-0000-4000-8000-000000000010';

    private const NC_SELLO = '2026000000000000000000000000000000000003'; // 40 chars

    private const NC_NUMERO_CONTROL = 'DTE-05-M001P001-000000000000020'; // 31 chars

    /**
     * NC tipo 05 ACEPTADA REALMENTE por el MH (in memory: relaciones seteadas y campos
     * de aceptación reales), sin persistir el DTE (mismo espíritu que los tests de
     * serializadores). No toca el flujo de generación/firma/transmisión.
     */
    private function ncAceptada(bool $aceptada = true): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Titular de Ejemplo',
            'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '06140000000901',
            'nrc' => '1000017',
            'telefono' => '22220000',
            'correo' => 'facturacion@example.com',
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
            'num_documento' => '0614-555555-101-5',
            'telefono' => '22220001',
            'correo' => 'responsable@example.com',
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
        $dte->fecha_procesamiento_mh = $aceptada ? HoraNegocio::ahora()->format('Y-m-d H:i:s') : null;

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
            numDocSolicita: '06145555551015',
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
        $this->assertSame('06145555551015', $doc['numDocumento']);
        $this->assertSame('Calleja, S.A. de C.V.', $doc['nombre']);
        // Emisor real.
        $this->assertSame('06140000000901', $evento['emisor']['nit']);
        $this->assertSame('M001', $evento['emisor']['codEstableMH']);
        $this->assertSame('P001', $evento['emisor']['codPuntoVentaMH']);
    }

    public function test_fecemi_del_evento_coincide_con_la_fecha_del_dte_no_con_now(): void
    {
        // REGRESIÓN (rechazo real anulardte codigoMsg 027 "[identificacion.fecEmi] DATO
        // NO COINCIDE CON DTE"): identificacion.fecEmi debe ser la fecha del DTE original
        // (2026-06-30), NO la fecha actual. Congelamos "now" en una fecha DISTINTA para
        // que el comportamiento anterior (now()) fallara.
        Carbon::setTestNow(Carbon::parse('2026-10-04 01:00:00', 'UTC'));
        try {
            $dte = $this->ncAceptada();
            $evento = app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento());

            $this->assertSame('2026-06-30', $evento['identificacion']['fecEmi'], 'fecEmi del evento debe ser la fecha del DTE, no now().');
            $this->assertSame($evento['documento']['fecEmi'], $evento['identificacion']['fecEmi'], 'identificacion.fecEmi y documento.fecEmi deben coincidir.');
            // horEmi SÍ es la del momento del evento (now); se documenta el comportamiento.
            $this->assertSame('19:00:00', $evento['identificacion']['horEmi']);
        } finally {
            Carbon::setTestNow();
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

    /**
     * CAMBIO DE EXPECTATIVA (Manual Funcional v2.0, págs. impresas 13-16).
     *
     * Antes: `test_tipo_1_exige_documento_de_reemplazo` afirmaba que CUALQUIER motivo 1
     * exigía sustituto, y lo comprobaba justamente sobre una NC 05 — el único de los
     * cuatro tipos para el que la regla NO aplica. La fila NC de la matriz lleva
     * `codigoGeneracionR` en null en los TRES motivos: primero se invalida la nota y la
     * corrección se emite después.
     *
     * Ahora: sobre una NC, el motivo 1 serializa null y un sustituto enviado se RECHAZA.
     * La regla del motivo 1 con sustituto se prueba sobre FE/CCF/FEX en
     * {@see MatrizInvalidacionHaciendaTest}, que es donde sí corresponde.
     */
    public function test_nc_motivo_1_no_lleva_sustituto_y_serializa_null(): void
    {
        $dte = $this->ncAceptada();

        $evento = app(SerializadorInvalidacionMh::class)
            ->serializar($dte, $this->evento(TipoAnulacionMh::ErrorInformacion));

        $this->assertNull($evento['documento']['codigoGeneracionR']);
        $this->assertSame(1, $evento['motivo']['tipoAnulacion']);
    }

    public function test_nc_motivo_1_rechaza_un_sustituto_enviado(): void
    {
        $dte = $this->ncAceptada();

        try {
            app(SerializadorInvalidacionMh::class)->serializar(
                $dte,
                $this->evento(TipoAnulacionMh::ErrorInformacion, reemplazo: '00000000-0000-4000-8000-000000000015')
            );
            $this->fail('Debió rechazar el sustituto: la NC no admite documento de reemplazo en ningún motivo.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('no admite documento de reemplazo', implode(' ', $e->problemas));
        }
    }

    public function test_nc_motivo_3_exige_motivo_en_texto_y_sigue_sin_sustituto(): void
    {
        $dte = $this->ncAceptada();

        // Sin texto → falla.
        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $this->evento(TipoAnulacionMh::Otro));
            $this->fail('El motivo 3 exige texto.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('texto', implode(' ', $e->problemas));
        }

        // Con texto → serializa, y el sustituto sigue en null (fila NC de la matriz).
        $evento = app(SerializadorInvalidacionMh::class)
            ->serializar($dte, $this->evento(TipoAnulacionMh::Otro, motivo: 'Nota emitida por duplicado.'));

        $this->assertNull($evento['documento']['codigoGeneracionR']);
        $this->assertSame('Nota emitida por duplicado.', $evento['motivo']['motivoAnulacion']);
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
     * DTE_INVALIDACION_SOL_NOMBRE con "Titular Ejemplo PeÃ±a" — eso es
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
        $nombre = 'Titular de Ejemplo';
        $evento = new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: $nombre, tipoDocResponsable: '36', numDocResponsable: '06140000000901',
            nombreSolicita: $nombre, tipoDocSolicita: '36', numDocSolicita: '06140000000901',
        );

        $eventoJson = app(SerializadorInvalidacionMh::class)->serializar($dte, $evento);

        $this->assertSame($nombre, $eventoJson['motivo']['nombreResponsable']);
        $this->assertSame($nombre, $eventoJson['motivo']['nombreSolicita']);

        $codificado = (string) json_encode($eventoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Titular de Ejemplo', $codificado);
        // Patrón de mojibake típico de la doble codificación UTF-8/Windows-1252: NO debe
        // aparecer en el JSON generado por el código (sí aparece hoy en el .env real).
        $this->assertStringNotContainsString('Ã¡', $codificado);
        $this->assertStringNotContainsString('Ã±', $codificado);
    }
}
