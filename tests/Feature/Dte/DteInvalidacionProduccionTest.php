<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Services\Dte\DteInvalidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Candado DEDICADO de producción (dte.invalidacion.produccion_enabled): un DTE con
 * ambiente='01' (CAT-001) solo puede invalidarse de verdad si, ADEMÁS de todos los
 * candados que ya exigía apitest, este flag está en true Y el endpoint resuelto es
 * EXACTAMENTE https://api.dtes.mh.gob.sv/fesv/anulardte. Apitest sigue funcionando
 * exactamente igual que antes (ver DteInvalidacionRealTest). Ningún test de esta clase
 * hace una transmisión real: los casos "permitidos" se verifican solo por dry-run
 * interno (evaluarCandados/dryRun), nunca por transmitir().
 */
class DteInvalidacionProduccionTest extends TestCase
{
    use RefreshDatabase;

    private const CCF_CODIGO_GENERACION = 'A3C1F2B4-9D3E-4C77-8B12-0F5E6A7D8C90';

    private const CCF_SELLO = '2026386FB99EC82E45A3931C61E4A8EB331A5CIU';

    private const CCF_NUMERO_CONTROL = 'DTE-03-M001P099-000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        // Nada protegido por defecto (evita que el .env real de la máquina, que protege
        // números de control distintos, contamine estos tests).
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '10132512610012');
        config()->set('dte.firma.cert_password', 'secreto');
        // Candado dedicado de producción: apagado por defecto en cada test (cada uno
        // lo enciende explícitamente si lo necesita).
        config()->set('dte.invalidacion.produccion_enabled', false);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        config()->set('dte.ambientes.01.anulacion_url', '');
    }

    private function ccfAceptado(string $ambiente): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P099', 'nombre' => 'Caja Prod Test', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343', 'correo' => 'melquicedeespana@gmail.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => $ambiente,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => self::CCF_NUMERO_CONTROL,
            'codigo_generacion' => self::CCF_CODIGO_GENERACION,
            'sello_recepcion' => self::CCF_SELLO,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => self::CCF_SELLO],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    private function evento(TipoAnulacionMh $tipo = TipoAnulacionMh::RescindirOperacion, ?string $motivo = null): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: $tipo,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06141101690011',
            motivoAnulacion: $motivo,
        );
    }

    private function servicio(): DteInvalidacionService
    {
        return app(DteInvalidacionService::class);
    }

    // ---------- Producción bloqueada por defecto ----------

    public function test_produccion_bloqueada_por_defecto(): void
    {
        Http::fake();
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);

        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertStringContainsString('DTE_INVALIDACION_PRODUCCION_ENABLED', implode(' ', $c['razones']));

        $this->expectException(DteInvalidacionException::class);
        try {
            $this->servicio()->transmitir($dte, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---------- Producción bloqueada con URL de apitest ----------

    public function test_produccion_bloqueada_con_url_apitest(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.produccion_enabled', true);
        config()->set('dte.ambientes.01.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);

        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertStringContainsString('productivo exacto', implode(' ', $c['razones']));
        Http::assertNothingSent();
    }

    // ---------- Producción bloqueada con URL productiva pero flag=false ----------

    public function test_produccion_bloqueada_con_url_productiva_pero_flag_false(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.produccion_enabled', false);
        config()->set('dte.ambientes.01.anulacion_url', 'https://api.dtes.mh.gob.sv/fesv/anulardte');
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);

        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertStringContainsString('DTE_INVALIDACION_PRODUCCION_ENABLED', implode(' ', $c['razones']));
        Http::assertNothingSent();
    }

    // ---------- Producción permitida SOLO en dry-run interno (nunca transmitir()) ----------

    public function test_produccion_permitida_en_dry_run_con_flag_true_y_url_exacta(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.produccion_enabled', true);
        config()->set('dte.ambientes.01.anulacion_url', 'https://api.dtes.mh.gob.sv/fesv/anulardte');
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);

        $d = $this->servicio()->dryRun($dte, $this->evento(), true, true);

        $this->assertFalse($d['candados']['bloqueado'], implode(' | ', $d['candados']['razones']));
        $this->assertTrue($d['transmitiria']);
        $this->assertSame('https://api.dtes.mh.gob.sv/fesv/anulardte', $d['endpoint']);
        $this->assertSame('01', $d['ambiente']);
        Http::assertNothingSent();
    }

    // ---------- Apitest sigue permitido exactamente igual que antes ----------

    public function test_apitest_sigue_permitido_igual_que_antes(): void
    {
        Http::fake();
        $dte = $this->ccfAceptado(AmbienteHacienda::Pruebas->value);

        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertFalse($c['bloqueado'], implode(' | ', $c['razones']));
        Http::assertNothingSent();
    }

    // ---------- Guardas universales que NO se relajan por habilitar producción ----------

    /**
     * La protección de evidencia es un mecanismo ESPECÍFICO de apitest (ambiente '00'):
     * un DTE de PRODUCCIÓN nunca queda protegido por esta vía, aunque su numero_control
     * coincida con el de un documento protegido de apitest (ver Dte::estaProtegidoComoEvidencia()
     * y tests\Feature\Dte\DteInvalidacionProteccionEvidenciaTest para la cobertura
     * dedicada de este comportamiento). Con producción habilitada, este candado
     * simplemente no aplica: el resto de candados universales sí siguen exigiéndose
     * (ver test_dte_con_evento_previo_sigue_bloqueado_en_produccion, más abajo).
     */
    public function test_proteccion_apitest_no_bloquea_dte_de_produccion_aunque_comparta_numero_control(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.produccion_enabled', true);
        config()->set('dte.ambientes.01.anulacion_url', 'https://api.dtes.mh.gob.sv/fesv/anulardte');
        config()->set('dte.invalidacion.protegidos_numero_control', [self::CCF_NUMERO_CONTROL]);
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);

        $this->assertFalse($dte->estaProtegidoComoEvidencia());
        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertNotContains(
            'DTE PROTEGIDO como evidencia APITEST (config dte.invalidacion.protegidos_numero_control / '
            .'protegidos_codigo_generacion): no puede invalidarse por esta vía, sin excepción.',
            $c['razones']
        );
    }

    public function test_dte_con_evento_previo_sigue_bloqueado_en_produccion(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.produccion_enabled', true);
        config()->set('dte.ambientes.01.anulacion_url', 'https://api.dtes.mh.gob.sv/fesv/anulardte');
        $dte = $this->ccfAceptado(AmbienteHacienda::Produccion->value);
        $dte->sello_invalidacion = 'SELLO-INVAL-YA';
        $dte->save();

        $c = $this->servicio()->evaluarCandados($dte->refresh(), $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertContains('El DTE ya tiene un evento de invalidación o está invalidado.', $c['razones']);
        Http::assertNothingSent();
    }

    // ---------- Tipo 3 (Otro) exige motivo, en cualquier ambiente ----------

    public function test_tipo3_sin_motivo_falla(): void
    {
        Http::fake();
        $dte = $this->ccfAceptado(AmbienteHacienda::Pruebas->value);

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->dryRun($dte, $this->evento(TipoAnulacionMh::Otro), true, true);
    }

    public function test_tipo3_con_motivo_valido_pasa(): void
    {
        Http::fake();
        $dte = $this->ccfAceptado(AmbienteHacienda::Pruebas->value);
        $evento = $this->evento(TipoAnulacionMh::Otro, 'Documento emitido por duplicidad. La operación válida permanece '
            .'respaldada por el DTE emitido y entregado desde Conta.');

        $d = $this->servicio()->dryRun($dte, $evento, true, true);

        $this->assertTrue($d['schema']['valido'], implode(' | ', $d['schema']['errores']));
        $this->assertFalse($d['candados']['bloqueado'], implode(' | ', $d['candados']['razones']));
        $this->assertSame(3, $d['evento']['motivo']['tipoAnulacion']);
        Http::assertNothingSent();
    }
}
