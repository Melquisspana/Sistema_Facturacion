<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteEvidenciaProtegidaException;
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
 * Guarda de EVIDENCIA (config dte.invalidacion.protegidos_numero_control /
 * protegidos_codigo_generacion): documentos marcados como evidencia (p.ej. el cierre
 * de la fase de pruebas P002 en APITEST: DTE #139/#140/#142/#143) NUNCA deben poder
 * invalidarse, ni mock ni real, sin excepción ni flag de override.
 */
class DteInvalidacionProteccionEvidenciaTest extends TestCase
{
    use RefreshDatabase;

    private const NC_CODIGO_GENERACION = '437F5D8B-A746-46E1-8A60-BF74C17FE309';

    private const NC_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH'; // 40 chars

    private const NC_NUMERO_CONTROL = 'DTE-05-M001P002-000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        // Arranca sin nada protegido; cada test que necesita protección la fija
        // explícitamente. Evita que el .env real de la máquina (que en APITEST sí
        // protege #139/#140/#142/#143) contamine los tests "no protegido".
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

    private function ncAceptada(): Dte
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
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => self::NC_NUMERO_CONTROL,
            'codigo_generacion' => self::NC_CODIGO_GENERACION,
            'sello_recepcion' => self::NC_SELLO,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => self::NC_SELLO],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
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

    private function protegerPorNumeroControl(): void
    {
        config()->set('dte.invalidacion.protegidos_numero_control', [self::NC_NUMERO_CONTROL]);
    }

    // ---------- 1) Un documento PROTEGIDO no puede invalidarse ----------

    public function test_mock_bloquea_documento_protegido_por_numero_control(): void
    {
        $this->protegerPorNumeroControl();
        $dte = $this->ncAceptada();

        $this->expectException(DteEvidenciaProtegidaException::class);
        app(DteInvalidacionMockService::class)->firmarMock($dte, $this->evento(), persistir: true, permitirSinMock: true);
    }

    public function test_mock_bloquea_documento_protegido_por_codigo_generacion(): void
    {
        config()->set('dte.invalidacion.protegidos_codigo_generacion', [self::NC_CODIGO_GENERACION]);
        $dte = $this->ncAceptada();

        $this->expectException(DteEvidenciaProtegidaException::class);
        app(DteInvalidacionMockService::class)->firmarMock($dte, $this->evento(), persistir: true, permitirSinMock: true);
    }

    public function test_real_bloquea_transmision_de_documento_protegido(): void
    {
        $this->protegerPorNumeroControl();
        $this->fakeHttp();
        $dte = $this->ncAceptada();

        $this->expectException(DteEvidenciaProtegidaException::class);
        try {
            app(DteInvalidacionService::class)->transmitir($dte, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_mock_no_persiste_nada_sobre_documento_protegido(): void
    {
        $this->protegerPorNumeroControl();
        $dte = $this->ncAceptada();

        try {
            app(DteInvalidacionMockService::class)->firmarMock($dte, $this->evento(), persistir: true, permitirSinMock: true);
        } catch (DteEvidenciaProtegidaException) {
            // esperado
        }

        $dte->refresh();
        $this->assertNull($dte->sello_invalidacion);
        $this->assertFalse($dte->tieneEventoInvalidacion());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    // ---------- 2) Un documento NO protegido sigue funcionando normalmente ----------

    public function test_documento_no_protegido_se_invalida_normalmente_en_mock(): void
    {
        // Protección configurada, pero apuntando a OTRO documento: no debe afectar a este.
        config()->set('dte.invalidacion.protegidos_numero_control', ['DTE-05-M001P001-000000000000099']);
        $dte = $this->ncAceptada();
        $this->assertFalse($dte->estaProtegidoComoEvidencia());

        $r = app(DteInvalidacionMockService::class)->firmarMock($dte, $this->evento(), persistir: true, permitirSinMock: true);

        $this->assertTrue($r['persistido']);
        $dte->refresh();
        $this->assertTrue($dte->tieneEventoInvalidacion());
    }

    public function test_documento_no_protegido_transmite_normalmente_en_real(): void
    {
        $this->fakeHttp();
        $dte = $this->ncAceptada(); // sin nada en dte.invalidacion.protegidos_*

        $r = app(DteInvalidacionService::class)->transmitir($dte, $this->evento(), true, true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['invalidado']);
    }

    // ---------- 3) Dry-run inspeccionable, pero la guarda no se puede evadir después ----------

    public function test_dry_run_es_inspeccionable_sobre_documento_protegido_sin_cambiar_estado(): void
    {
        $this->protegerPorNumeroControl();
        Http::fake();
        $dte = $this->ncAceptada();

        $d = app(DteInvalidacionService::class)->dryRun($dte, $this->evento(), true, true);

        $this->assertFalse($d['transmitiria']);
        $this->assertTrue($d['candados']['bloqueado']);
        $this->assertStringContainsString('PROTEGIDO', implode(' ', $d['candados']['razones']));
        Http::assertNothingSent();
        $dte->refresh();
        $this->assertNull($dte->sello_invalidacion);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
    }

    public function test_transmision_posterior_al_dry_run_sigue_bloqueada_para_documento_protegido(): void
    {
        $this->protegerPorNumeroControl();
        $this->fakeHttp();
        $dte = $this->ncAceptada();
        $servicio = app(DteInvalidacionService::class);

        // El dry-run "inspecciona" el evento sin lanzar excepción...
        $d = $servicio->dryRun($dte, $this->evento(), true, true);
        $this->assertTrue($d['candados']['bloqueado']);

        // ...pero una transmisión real inmediatamente después SIGUE bloqueada: la
        // guarda no depende de lo que haya mostrado el dry-run y no se puede evadir.
        $this->expectException(DteEvidenciaProtegidaException::class);
        try {
            $servicio->transmitir($dte, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---------- 4) La protección distingue AMBIENTE (caso real #139 apitest vs #145 producción) ----------

    /**
     * Mismo número de control que {@see NC_NUMERO_CONTROL}, pero un CCF de PRODUCCIÓN
     * (ambiente 01) — reproduce el caso real: el DTE #145 (CCF, ambiente 01) comparte
     * numero_control con el DTE #139 (evidencia APITEST, ambiente 00).
     */
    private function ccfAceptadaProduccion(): Dte
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
            'ambiente' => '01',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => self::NC_NUMERO_CONTROL,
            'codigo_generacion' => '641929A0-FB67-4A1D-AA2E-6C2D33E6355C',
            'sello_recepcion' => '2026386FB99EC82E45A3931C61E4A8EB331A5CIU',
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => '2026386FB99EC82E45A3931C61E4A8EB331A5CIU'],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    public function test_mismo_numero_control_en_ambiente_00_sigue_protegido(): void
    {
        // Regresión: el ambiente 00 (apitest) con el numero_control protegido debe
        // seguir bloqueado exactamente igual que antes de este cambio.
        $this->protegerPorNumeroControl();
        $apitest = $this->ncAceptada(); // ambiente 00

        $this->assertTrue($apitest->estaProtegidoComoEvidencia());
        $this->expectException(DteEvidenciaProtegidaException::class);
        app(DteInvalidacionMockService::class)->firmarMock($apitest, $this->evento(), persistir: true, permitirSinMock: true);
    }

    public function test_mismo_numero_control_en_ambiente_01_no_queda_protegido(): void
    {
        // El caso real #145: comparte numero_control con la evidencia APITEST (#139,
        // ambiente 00), pero es un DTE de PRODUCCIÓN (ambiente 01) — NO debe bloquearse.
        $this->protegerPorNumeroControl();
        $produccion = $this->ccfAceptadaProduccion(); // ambiente 01, mismo numero_control

        $this->assertFalse($produccion->estaProtegidoComoEvidencia());

        $this->fakeHttp();
        config()->set('dte.invalidacion.produccion_enabled', true);
        config()->set('dte.ambientes.01.anulacion_url', 'https://api.dtes.mh.gob.sv/fesv/anulardte');
        $c = app(DteInvalidacionService::class)->evaluarCandados($produccion, $this->evento(), true, true);
        $this->assertNotContains(
            'DTE PROTEGIDO como evidencia APITEST (config dte.invalidacion.protegidos_numero_control / '
            .'protegidos_codigo_generacion): no puede invalidarse por esta vía, sin excepción.',
            $c['razones']
        );
    }

    public function test_no_se_quita_la_proteccion_global_por_agregar_el_filtro_de_ambiente(): void
    {
        // Un DTE ambiente 00 CUALQUIERA que SÍ está en la lista sigue protegido: el
        // filtro de ambiente solo excluye producción, nunca "apaga" la protección de
        // apitest en general.
        $this->protegerPorNumeroControl();
        $otroApitest = $this->ncAceptada();
        $otroApitest->forceFill(['ambiente' => '00'])->saveQuietly();

        $this->assertTrue($otroApitest->fresh()->estaProtegidoComoEvidencia());
    }
}
