<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Services\Dte\DteInvalidacionMockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DteInvalidacionMockTest extends TestCase
{
    use RefreshDatabase;

    private const NC_CODIGO_GENERACION = '437F5D8B-A746-46E1-8A60-BF74C17FE309';

    private const NC_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH'; // 40 chars

    private const NC_NUMERO_CONTROL = 'DTE-05-M001P001-000000000000020';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('dte.invalidacion.mock', true);
    }

    /** NC tipo 05 PERSISTIDA y aceptada realmente por el MH (con evidencia de recepción). */
    private function ncAceptada(bool $aceptada = true): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343', 'correo' => 'melquicedeespana@gmail.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => $aceptada ? EstadoDte::Aceptado->value : EstadoDte::Generado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => self::NC_NUMERO_CONTROL,
            'codigo_generacion' => self::NC_CODIGO_GENERACION,
            'sello_recepcion' => $aceptada ? self::NC_SELLO : null,
            'respuesta_mh' => $aceptada ? ['estado' => 'PROCESADO', 'selloRecibido' => self::NC_SELLO] : null,
            'fecha_procesamiento_mh' => $aceptada ? '2026-06-30 22:48:44' : null,
            'fecha_emision' => '2026-06-30',
            'hora_emision' => '22:26:52',
        ]);
    }

    private function evento(TipoAnulacionMh $tipo = TipoAnulacionMh::RescindirOperacion): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: $tipo,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06141101690011',
        );
    }

    private function servicio(): DteInvalidacionMockService
    {
        return app(DteInvalidacionMockService::class);
    }

    public function test_firma_mock_genera_jws_marcado(): void
    {
        $dte = $this->ncAceptada();
        $r = $this->servicio()->firmarMock($dte, $this->evento());

        $this->assertTrue($r['mock']);
        $this->assertFalse($r['transmitido']);
        $this->assertStringContainsString('chars', $r['jws_preview']);
        $this->assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/',
            $r['codigo_generacion_evento']
        );
        $this->assertStringStartsWith('MOCK-INVAL-', $r['sello_invalidacion']);
    }

    public function test_dry_run_sin_guardar_no_persiste_ni_escribe_archivos(): void
    {
        $dte = $this->ncAceptada();
        $r = $this->servicio()->firmarMock($dte, $this->evento(), persistir: false);

        $this->assertFalse($r['persistido']);
        $this->assertNull($r['json_invalidacion_path']);

        $dte->refresh();
        $this->assertNull($dte->sello_invalidacion);
        $this->assertNull($dte->codigo_generacion_invalidacion);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_guardar_escribe_json_y_jws_en_rutas_nuevas(): void
    {
        $dte = $this->ncAceptada();
        $r = $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);

        $this->assertTrue($r['persistido']);
        Storage::disk('local')->assertExists($r['json_invalidacion_path']);
        Storage::disk('local')->assertExists($r['jws_invalidacion_path']);
        Storage::disk('local')->assertExists($r['respuesta_mh_invalidacion_path']);
        $this->assertStringStartsWith('dte/invalidacion/json/', $r['json_invalidacion_path']);
        $this->assertStringStartsWith('dte/invalidacion/firmados/', $r['jws_invalidacion_path']);

        // El JWS en disco es la firma mock marcada.
        $jws = Storage::disk('local')->get($r['jws_invalidacion_path']);
        $this->assertStringContainsString('MOCK-SIN-FIRMA-REAL', $jws);
    }

    public function test_persiste_solo_columnas_nuevas_de_invalidacion(): void
    {
        $dte = $this->ncAceptada();
        $this->servicio()->firmarMock($dte, $this->evento(TipoAnulacionMh::RescindirOperacion), persistir: true);

        $dte->refresh();
        $this->assertNotNull($dte->codigo_generacion_invalidacion);
        // El UUID del evento NO reutiliza el de la NC.
        $this->assertNotSame($dte->codigo_generacion, $dte->codigo_generacion_invalidacion);
        $this->assertSame(TipoAnulacionMh::RescindirOperacion, $dte->tipo_anulacion);
        $this->assertStringStartsWith('MOCK-INVAL-', $dte->sello_invalidacion);
        $this->assertNotNull($dte->fecha_invalidacion);
        $this->assertNotNull($dte->fecha_procesamiento_invalidacion);
        $this->assertIsArray($dte->respuesta_mh_invalidacion);
        $this->assertTrue($dte->respuesta_mh_invalidacion['_mock']);
    }

    public function test_no_cambia_el_estado_de_la_nc(): void
    {
        $dte = $this->ncAceptada();
        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);

        $dte->refresh();
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertFalse($dte->esAnulado());
    }

    public function test_no_toca_la_evidencia_de_recepcion_original(): void
    {
        $dte = $this->ncAceptada();
        $selloOriginal = $dte->sello_recepcion;
        $respuestaOriginal = $dte->respuesta_mh;
        $fechaOriginal = $dte->fecha_procesamiento_mh;

        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);

        $dte->refresh();
        $this->assertSame($selloOriginal, $dte->sello_recepcion);
        $this->assertSame($respuestaOriginal, $dte->respuesta_mh);
        $this->assertEquals($fechaOriginal, $dte->fecha_procesamiento_mh);
        // El sello de invalidación es una columna DISTINTA a la de recepción.
        $this->assertNotSame($dte->sello_recepcion, $dte->sello_invalidacion);
    }

    public function test_bloquea_invalidacion_duplicada(): void
    {
        $dte = $this->ncAceptada();
        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);
        $dte->refresh();

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);
    }

    public function test_bloquea_si_la_nc_no_esta_aceptada_realmente_por_mh(): void
    {
        $dte = $this->ncAceptada(aceptada: false); // generado, sin sello/fecha MH

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);
    }

    public function test_bloquea_aceptacion_mock(): void
    {
        $dte = $this->ncAceptada();
        $dte->sello_recepcion = 'MOCK-SIMULADO-ABCDEF0123456789';
        Dte::withoutEvents(fn () => $dte->save());

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->firmarMock($dte->refresh(), $this->evento(), persistir: true);
    }

    public function test_bloquea_si_mock_apagado_sin_confirmar(): void
    {
        config()->set('dte.invalidacion.mock', false);
        $dte = $this->ncAceptada();

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->firmarMock($dte, $this->evento(), persistir: true);
    }

    public function test_permite_mock_apagado_con_confirmacion_explicita(): void
    {
        config()->set('dte.invalidacion.mock', false);
        $dte = $this->ncAceptada();

        $r = $this->servicio()->firmarMock($dte, $this->evento(), persistir: true, permitirSinMock: true);

        $this->assertTrue($r['persistido']);
        $this->assertFalse($r['transmitido']);
    }
}
