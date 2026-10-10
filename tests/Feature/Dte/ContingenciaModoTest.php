<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\ArchivoEntregaDteService;
use App\Services\Dte\BusquedaCcfParaNotaCredito;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\DteTransmisionResiliente;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\ArchivoEntregaDte;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class ContingenciaModoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local']);
        Http::fake();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:30:00', 'America/El_Salvador'));
        $this->admin = User::factory()->create()->assignRole('administrador');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activar(int $tipo = 3, ?string $motivo = 'Internet de prueba interrumpido'): Contingencia
    {
        return app(ContingenciaService::class)->activar($tipo, $motivo, 'manual', $this->admin);
    }

    public function test_activar_y_terminar_con_permiso_y_auditoria(): void
    {
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $activa = app(ContingenciaService::class)->activa();
        $this->assertSame('10:30', $activa->inicio->format('H:i'));
        $this->assertSame($this->admin->id, $activa->activada_por);
        Carbon::setTestNow(Carbon::parse('2026-10-09 11:00:00', 'America/El_Salvador'));
        $this->post(route('facturacion.contingencia.terminar'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(app(ContingenciaService::class)->activa());
        $this->assertSame('cerrada', $activa->refresh()->estado);
        $this->assertSame('11:00', $activa->cese->format('H:i'));
        $this->assertSame($this->admin->id, $activa->cerrada_por);
        $this->assertDatabaseCount('contingencia_eventos', 0);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'dte_contingencia', 'description' => 'Contingencia activada']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'dte_contingencia', 'description' => 'Contingencia terminada']);
        Http::assertNothingSent();
    }

    public function test_solo_administradores_pueden_activar_o_terminar(): void
    {
        $this->activar();
        foreach (['facturacion', 'jefatura', 'contabilidad', 'produccion'] as $rol) {
            $this->actingAs(User::factory()->create()->assignRole($rol));
            $this->post(route('facturacion.contingencia.activar'), ['tipo' => 1])->assertForbidden();
            $this->post(route('facturacion.contingencia.terminar'))->assertForbidden();
        }
        $this->assertSame('activa', Contingencia::first()->estado);
    }

    public function test_segunda_activacion_rechazada(): void
    {
        $this->activar();
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 1])->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencias', 1);
    }

    private function datosContingenciaActiva(): array
    {
        return ['tipo' => 3, 'motivo' => 'Interrupción inventada', 'origen' => 'manual',
            'inicio' => now(), 'estado' => 'activa', 'activa_unica' => 1];
    }

    public function test_activa_insertada_directamente_se_rechaza_en_la_consulta_previa(): void
    {
        DB::table('contingencias')->insert($this->datosContingenciaActiva());
        $dispatcher = Contingencia::getEventDispatcher();
        Contingencia::setEventDispatcher(clone $dispatcher);
        $intentoCreacion = false;
        try {
            Contingencia::creating(function () use (&$intentoCreacion) {
                $intentoCreacion = true;
            });
            try {
                $this->activar();
                $this->fail('Se permitió activar otra contingencia.');
            } catch (ValidationException $e) {
                $this->assertSame(['contingencia' => ['Ya hay una contingencia activa. Terminá la anterior antes de activar otra.']], $e->errors());
            }
        } finally {
            Contingencia::setEventDispatcher($dispatcher);
        }
        $this->assertFalse($intentoCreacion);
        $this->assertDatabaseCount('contingencias', 1);
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'dte_contingencia']);
    }

    public function test_indice_unico_impide_un_segundo_insert_activo(): void
    {
        DB::table('contingencias')->insert($this->datosContingenciaActiva());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('contingencias')->insert($this->datosContingenciaActiva());
    }

    public function test_carrera_despues_de_la_consulta_previa_se_traduce_a_validacion(): void
    {
        // El INSERT directo evita recursión y ocurre después del SELECT previo.
        // Aislar el dispatcher conserva los listeners originales al terminar.
        $dispatcher = Contingencia::getEventDispatcher();
        Contingencia::setEventDispatcher(clone $dispatcher);
        $insertada = false;
        try {
            Contingencia::creating(function () use (&$insertada) {
                DB::table('contingencias')->insert($this->datosContingenciaActiva());
                $insertada = true;
            });
            try {
                $this->activar();
                $this->fail('Se permitió activar otra contingencia en la carrera.');
            } catch (ValidationException $e) {
                $this->assertTrue($insertada);
                $this->assertSame(['contingencia' => ['Ya hay una contingencia activa. Terminá la anterior antes de activar otra.']], $e->errors());
            }
        } finally {
            Contingencia::setEventDispatcher($dispatcher);
        }
        // La inserción simulada comparte la transacción y también se revierte.
        $this->assertDatabaseCount('contingencias', 0);
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'dte_contingencia']);
    }

    public function test_validacion_de_tipo_y_motivo(): void
    {
        foreach ([0, 6, 'x'] as $tipo) {
            $this->post(route('facturacion.contingencia.activar'), ['tipo' => $tipo])->assertSessionHasErrors('tipo');
        }
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 5])->assertSessionHasErrors('motivo');
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 5, 'motivo' => '   '])->assertSessionHasErrors('motivo');
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 1, 'motivo' => str_repeat('a', 501)])->assertSessionHasErrors('motivo');
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 5, 'motivo' => str_repeat('á', 500)])->assertSessionHasNoErrors();
    }

    private function generar(TipoDte $tipo): Dte
    {
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv, 'empresa' => $empresa] = $this->crearEmisorDte();
        $empresa->update(['razon_social' => 'Emisor Inventado de Prueba', 'nombre_comercial' => 'Emisor Inventado', 'nit' => '06141234561018']);
        Correlativo::create(['tipo_dte' => $tipo->value, 'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $cliente = $tipo === TipoDte::FacturaExportacion ? Cliente::factory()->exportacion()->create() : Cliente::factory()->contribuyente()->create();
        $datos = ['tipo_dte' => $tipo, 'cliente_id' => $cliente, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
        if ($tipo === TipoDte::FacturaExportacion) {
            $datos += ['tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09'];
        }
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador($datos);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return app(DteGeneracionService::class)->generar($dte->refresh());
    }

    private function firmar(Dte $dte, bool $desdeUi = false): void
    {
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.firmador.url' => 'http://firmador.example.test/firmardocumento']);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jws = $b64('{"alg":"RS512"}').'.'.$b64(Storage::get($dte->json_generado_path)).'.'.$b64('firma-inventada');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['firmador.example.test/*' => Http::response(['status' => 'OK', 'body' => $jws])]);
        if ($desdeUi) {
            $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()
                ->assertSessionHas('status', 'Documento emitido en contingencia, pendiente de sello de recepción.');
        } else {
            app(DteFirmaService::class)->firmar($dte);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://firmador.example.test/firmardocumento/');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $dte->refresh();
    }

    public function test_fe_en_contingencia_valida_firma_entrega_y_pdf(): void
    {
        $this->verificarDocumento(TipoDte::Factura);
    }

    public function test_ccf_en_contingencia_valida_firma_entrega_y_pdf(): void
    {
        $this->verificarDocumento(TipoDte::CreditoFiscal);
    }

    public function test_fex_en_contingencia_valida_firma_entrega_y_pdf(): void
    {
        $this->verificarDocumento(TipoDte::FacturaExportacion);
    }

    public function test_nc_sobre_ccf_sellado_se_emite_en_contingencia(): void
    {
        $ccf = $this->generar(TipoDte::CreditoFiscal);
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->sello_recepcion = 'SELLO-INVENTADO-DE-PRUEBA';
        $ccf->fecha_procesamiento_mh = now();
        $ccf->saveQuietly();
        $contingencia = $this->activar();
        Correlativo::create(['tipo_dte' => '05', 'establecimiento_id' => $ccf->establecimiento_id,
            'punto_venta_id' => $ccf->punto_venta_id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $borradores = app(DteBorradorService::class);
        $nc = $borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Descuento inventado']);
        $borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Descuento de prueba', 'monto' => 1, 'tipo_impuesto' => 'gravado']);
        app(DteGeneracionService::class)->generar($nc->refresh());
        $json = json_decode(Storage::get($nc->json_generado_path), true);
        $this->assertSame(2, $json['identificacion']['tipoModelo']);
        $this->assertSame(2, $json['identificacion']['tipoOperacion']);
        $this->assertSame(3, $json['identificacion']['tipoContingencia']);
        $this->assertSame($contingencia->motivo, $json['identificacion']['motivoContin']);
        $this->assertSame($contingencia->id, $nc->contingencia_id);
        $this->assertTrue(app(DteSchemaValidator::class)->validar($json, TipoDte::NotaCredito)['valido']);
        Http::assertNothingSent();
        $this->firmar($nc, true);
        $this->assertTrue($nc->esTransitorio());
        $this->assertSame(ArchivoEntregaDte::TRANSITORIO, app(ArchivoEntregaDteService::class)->construir($nc)->estado);
        Http::assertNothingSent();
    }

    private function verificarDocumento(TipoDte $tipo): void
    {
        $contingencia = $this->activar(5, 'Falla de prueba');
        $dte = $this->generar($tipo);
        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $this->assertSame(2, $json['identificacion']['tipoModelo']);
        $this->assertSame(2, $json['identificacion']['tipoOperacion']);
        $this->assertSame(5, $json['identificacion']['tipoContingencia']);
        $this->assertSame('Falla de prueba', $json['identificacion']['motivoContin']);
        $this->assertSame($contingencia->id, $dte->contingencia_id);
        $this->assertTrue(app(DteSchemaValidator::class)->validar($json, $tipo)['valido']);
        Http::assertNothingSent();
        $this->firmar($dte, true);
        $this->assertTrue($dte->esTransitorio());
        $antes = $dte->getRawOriginal();
        $evidencia = Storage::get($dte->json_firmado_path);
        app(ContingenciaService::class)->terminar($this->admin);
        $html = app(DtePdfService::class)->html($dte->refresh());
        $this->assertStringContainsString('Documento emitido en contingencia, pendiente de sello de recepción', $html);
        $this->assertStringNotContainsString('alt="QR oficial"', $html);
        $this->assertNull(app(DtePdfService::class)->urlConsultaQr($dte));
        $this->assertSame($antes, $dte->refresh()->getRawOriginal());
        $this->assertSame($evidencia, Storage::get($dte->json_firmado_path));
        $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
        $this->assertSame(ArchivoEntregaDte::TRANSITORIO, $entrega->estado);
        $this->assertFalse($entrega->completo());
        $this->assertTrue($entrega->entregable());
        $entregado = json_decode($entrega->contenido, true);
        $this->assertSame($evidencia, $entregado['firmaElectronica']);
        $this->assertArrayNotHasKey('selloRecibido', $entregado);
        $descarga = $this->get(route('facturacion.json.descargar', $dte))->assertOk();
        $this->assertSame($entregado, json_decode($descarga->streamedContent(), true));
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()->assertSessionHas('status');
        try {
            app(DteTransmisionResiliente::class)->transmitir($dte, true);
            $this->fail('La política de reintentos admitió un transitorio.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('evento y lote', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->expectException(DteTransmisionException::class);
        $this->expectExceptionMessage('evento y lote');
        app(DteTransmisionService::class)->transmitir($dte);
    }

    public function test_invalidacion_y_nc_de_ccf_transitorio_bloqueadas(): void
    {
        $this->activar();
        $dte = $this->generar(TipoDte::CreditoFiscal);
        $this->firmar($dte);
        $evento = new EventoInvalidacionData(TipoAnulacionMh::from(2));
        $problemas = app(ValidadorReglasInvalidacion::class)->problemas($dte, $evento);
        $this->assertStringContainsString('transitorio', implode(' ', $problemas));
        $this->assertNull(app(BusquedaCcfParaNotaCredito::class)->seleccionable($dte->id));
        $this->assertFalse($this->admin->can('transmitirInvalidacion', $dte));
        $nota = new Dte(['tipo_dte' => '05', 'estado' => EstadoDte::Firmado, 'dte_relacionado_id' => $dte->id]);
        try {
            app(DteTransmisionService::class)->transmitir($nota);
            $this->fail('Se permitió emitir una NC sobre un CCF transitorio.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('CCF es transitorio', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('transitorio');
        app(DteBorradorService::class)->crearNotaCredito($dte);
    }

    public function test_campos_fiscales_y_vinculo_inmutables_despues_de_generar(): void
    {
        $this->activar();
        $dte = $this->generar(TipoDte::Factura);
        foreach (['tipo_modelo' => 1, 'tipo_operacion' => 1, 'tipo_contingencia' => 1, 'motivo_contingencia' => 'Otro', 'contingencia_id' => null] as $campo => $valor) {
            try {
                $dte->$campo = $valor;
                $dte->save();
                $this->fail('Se permitió cambiar '.$campo);
            } catch (DocumentoInmutableException $e) {
                $this->assertStringContainsString($campo, $e->getMessage());
                $dte->refresh();
            }
        }
    }

    public function test_correo_entrega_json_transitorio_con_firma_sin_sello(): void
    {
        $this->activar();
        $dte = $this->generar(TipoDte::Factura);
        $this->firmar($dte);
        $this->simularProduccionCorreo();
        Mail::fake();
        $this->mock(DtePdfService::class, fn ($mock) => $mock->shouldReceive('bytes')->andReturn('%PDF inventado'));
        $envio = $dte->envios()->create(['destinatario' => 'cliente-inventado@example.test', 'estado' => 'pendiente']);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));
        Mail::assertSent(DteCorreo::class, function ($correo) {
            $json = json_decode($correo->adjuntosExtra[0]['contenido'], true);
            $this->assertArrayHasKey('firmaElectronica', $json);
            $this->assertArrayNotHasKey('selloRecibido', $json);

            return true;
        });
        Http::assertNothingSent();
    }

    public function test_cese_invalido_no_cierra_y_se_puede_activar_otra_despues(): void
    {
        $contingencia = $this->activar();
        foreach (['2026-10-09 10:29:59', '2026-10-09 10:30:01'] as $hora) {
            try {
                app(ContingenciaService::class)->terminar($this->admin, Carbon::parse($hora, 'America/El_Salvador'));
                $this->fail('Se permitió un cese fuera del intervalo.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cese', $e->errors());
                $this->assertSame('activa', $contingencia->refresh()->estado);
            }
        }
        app(ContingenciaService::class)->terminar($this->admin);
        $this->assertNull($contingencia->refresh()->activa_unica);
        $nueva = $this->activar();
        $this->assertSame(1, $nueva->activa_unica);
        $this->assertNotSame($contingencia->id, $nueva->id);
        $this->assertSame('cerrada', $contingencia->refresh()->estado);
        app(ContingenciaService::class)->terminar($this->admin);
        $this->assertNull($nueva->refresh()->activa_unica);
        $this->assertSame(1, $this->activar()->activa_unica);
        $this->assertSame(2, Contingencia::whereNull('activa_unica')->count());
    }

    public function test_interruptor_apagado_no_modifica_generacion_ni_muestra_aviso(): void
    {
        $this->activar();
        config(['dte.contingencia.enabled' => false]);
        $this->post(route('facturacion.contingencia.activar'), ['tipo' => 1])->assertNotFound();
        $this->post(route('facturacion.contingencia.terminar'))->assertNotFound();
        $this->assertNull(app(ContingenciaService::class)->activa());
        $this->assertSame('', trim(view('components.aviso-contingencia')->render()));
        $dte = $this->generar(TipoDte::Factura);
        $this->assertNull($dte->contingencia_id);
        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $this->assertSame(1, $json['identificacion']['tipoModelo']);
        $this->assertSame(1, $json['identificacion']['tipoOperacion']);
        Http::assertNothingSent();
    }

    public function test_aviso_seguro_sin_tabla_y_con_permiso(): void
    {
        $contingencia = $this->activar();
        $html = view('components.aviso-contingencia')->render();
        $this->assertStringContainsString('Modo contingencia desde las 10:30', $html);
        $this->assertStringContainsString('Terminar contingencia', $html);
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));
        $this->assertStringNotContainsString('Terminar contingencia', view('components.aviso-contingencia')->render());
        $contingencia->delete();
        Schema::drop('contingencias');
        $this->assertNull(app(ContingenciaService::class)->activa());
        $this->assertSame('', trim(view('components.aviso-contingencia')->render()));
        $this->withoutVite();
        $this->assertStringContainsString('Contenido de prueba', view('layouts.app', ['slot' => new HtmlString('Contenido de prueba')])->render());
    }
}
