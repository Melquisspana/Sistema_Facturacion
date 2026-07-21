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
use App\Services\Dte\DteInvalidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Advertencia + confirmación reforzada cuando el documento a invalidar ya tiene una
 * Nota de Crédito relacionada (vía dte_relacionado_id): NO es un bloqueo automático
 * permanente (no hay base fiscal confirmada para prohibirlo del todo), sino un candado
 * más que exige --confirmo-nc-relacionada / el checkbox equivalente, igual que los
 * demás candados de doble confirmación del módulo.
 */
class DteInvalidacionNcRelacionadaTest extends TestCase
{
    use RefreshDatabase;

    private const CCF_CODIGO_GENERACION = '437F5D8B-A746-46E1-8A60-BF74C17FE309';

    private const CCF_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH'; // 40 chars

    private const CCF_NUMERO_CONTROL = 'DTE-03-M001P002-000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        // Nada protegido por defecto en este archivo (se prueba aparte en
        // DteInvalidacionProteccionEvidenciaTest); evita que el .env real de la
        // máquina (que en APITEST sí protege #139/#140/#142/#143) contamine el test.
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '10132512610012');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
    }

    private function fakeHttp(): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-X', 'descripcionMsg' => 'ok', 'fhProcesamiento' => '01/07/2026 10:00:00'], 200),
        ]);
    }

    private function ccfAceptado(): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343', 'correo' => 'melquicedeespana@gmail.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => self::CCF_NUMERO_CONTROL,
            'codigo_generacion' => self::CCF_CODIGO_GENERACION,
            'sello_recepcion' => self::CCF_SELLO,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => self::CCF_SELLO],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    /** NC (tipo 05) emitida contra el CCF, referenciándolo por dte_relacionado_id. */
    private function crearNcRelacionada(Dte $ccf, EstadoDte $estado = EstadoDte::Aceptado): Dte
    {
        return Dte::create([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => $estado->value,
            'ambiente' => '00',
            'establecimiento_id' => $ccf->establecimiento_id, 'punto_venta_id' => $ccf->punto_venta_id, 'cliente_id' => $ccf->cliente_id,
            'dte_relacionado_id' => $ccf->id,
            'numero_control' => 'DTE-05-M001P002-000000000000001',
            'codigo_generacion' => 'F2889F37-FC94-49E7-A206-864ADBE5C00C',
            'sello_recepcion' => $estado === EstadoDte::Aceptado ? 'F2889F37FC9449E7A206864ADBE5C00C000000' : null,
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:40:00',
        ]);
    }

    private function evento(): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06141101690011',
        );
    }

    // ---------- Detección de la relación ----------

    public function test_dte_sin_nc_relacionada_no_tiene_advertencia(): void
    {
        $ccf = $this->ccfAceptado();

        $this->assertFalse($ccf->tieneNotaCreditoRelacionada());
        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true);
        $this->assertFalse($c['bloqueado']);
    }

    public function test_dte_con_nc_relacionada_en_borrador_no_cuenta(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf, EstadoDte::Borrador);

        $this->assertFalse($ccf->tieneNotaCreditoRelacionada());
    }

    public function test_dte_con_nc_relacionada_aceptada_bloquea_evaluarcandados_sin_confirmar(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);

        $this->assertTrue($ccf->tieneNotaCreditoRelacionada());
        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertStringContainsString('Nota de Crédito relacionada', implode(' ', $c['razones']));
    }

    public function test_confirmo_nc_relacionada_permite_pasar_el_candado(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);

        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true, confirmoNcRelacionada: true);
        $this->assertFalse($c['bloqueado']);
    }

    // ---------- Comando/servicio real ----------

    public function test_real_bloquea_transmision_con_nc_relacionada_sin_confirmar(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);
        $this->fakeHttp();

        $this->expectException(DteInvalidacionException::class);
        try {
            app(DteInvalidacionService::class)->transmitir($ccf, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_real_transmite_con_nc_relacionada_confirmada_explicitamente(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);
        $this->fakeHttp();

        $r = app(DteInvalidacionService::class)->transmitir($ccf, $this->evento(), true, true, confirmoNcRelacionada: true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['invalidado']);
    }

    // ---------- Mock ----------

    public function test_mock_bloquea_con_nc_relacionada_sin_confirmar(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);

        $this->expectException(DteInvalidacionException::class);
        app(DteInvalidacionMockService::class)->firmarMock($ccf, $this->evento(), persistir: true, permitirSinMock: true);
    }

    public function test_mock_persiste_con_nc_relacionada_confirmada(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNcRelacionada($ccf);

        $r = app(DteInvalidacionMockService::class)->firmarMock(
            $ccf, $this->evento(), persistir: true, permitirSinMock: true, permitirNcRelacionada: true
        );

        $this->assertTrue($r['persistido']);
    }
}
