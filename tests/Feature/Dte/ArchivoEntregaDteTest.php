<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\User;
use App\Services\Dte\ArchivoEntregaDteService;
use App\Services\Dte\DtePdfService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\ArchivoEntregaDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class ArchivoEntregaDteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['dte.storage.disk' => 'local']);
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->simularProduccionCorreo();
        Mail::fake();
        $this->mock(DtePdfService::class, function ($mock) {
            $mock->shouldReceive('bytes')->andReturn('%PDF ficticio');
        });
    }

    private function documento(): Dte
    {
        $dte = new Dte([
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'tipo_dte' => '03', 'estado' => EstadoDte::Aceptado, 'ambiente' => '00',
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
            'sello_recepcion' => '2026-SELLO-REAL', 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-10-03', 'hora_emision' => '10:00:00',
            'respuesta_mh' => ['selloRecibido' => '2026-SELLO-REAL'],
        ]);
        $dte->json_generado_path = 'dte/json/'.$dte->codigo_generacion.'.json';
        $dte->json_firmado_path = 'dte/firmados/'.$dte->codigo_generacion.'.jws';
        $dte->respuesta_mh_path = 'dte/respuestas/'.$dte->codigo_generacion.'.json';
        $dte->saveQuietly();
        $json = json_encode(['identificacion' => [
            'codigoGeneracion' => $dte->codigo_generacion, 'numeroControl' => $dte->numero_control,
            'tipoDte' => '03', 'ambiente' => '00', 'fecEmi' => '2026-10-03',
        ], 'resumen' => ['total' => 10, 'opcional' => null]], self::FLAGS);
        Storage::put($dte->json_generado_path, $json);
        Storage::put($dte->json_firmado_path, " \n".$this->jws($json)."\n");
        Storage::put($dte->respuesta_mh_path, json_encode(['codigoGeneracion' => $dte->codigo_generacion]));

        return $dte->refresh();
    }

    private function jws(string $json, array $header = ['alg' => 'RS512'], string $firma = 'ZmlybWEtZmljdGljaWE'): string
    {
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64(json_encode($header)).'.'.$b64($json).'.'.$firma;
    }

    private function enviar(Dte $dte): DteEnvio
    {
        $envio = $dte->envios()->create(['destinatario' => 'cliente@example.com', 'estado' => 'pendiente']);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        return $envio->refresh();
    }

    public function test_correo_entrega_firma_y_sello_en_la_raiz(): void
    {
        $dte = $this->documento();
        $this->enviar($dte);
        Mail::assertSent(DteCorreo::class, function ($mail) use ($dte) {
            $adjunto = $mail->adjuntosExtra[0];
            $json = json_decode($adjunto['contenido'], true);
            $this->assertArrayHasKey('firmaElectronica', $json);
            $this->assertSame($dte->sello_recepcion, $json['selloRecibido']);
            $this->assertSame($dte->codigo_generacion.'.json', $adjunto['nombre']);

            return true;
        });
    }

    public function test_ambas_descargas_entregan_firma_y_sello(): void
    {
        $dte = $this->documento();
        foreach (['facturacion.json.descargar', 'facturacion.reporte-contadora.json'] as $ruta) {
            $respuesta = $this->get(route($ruta, $dte))->assertOk();
            $json = json_decode($respuesta->streamedContent(), true);
            $this->assertArrayHasKey('firmaElectronica', $json);
            $this->assertSame($dte->sello_recepcion, $json['selloRecibido']);
        }
    }

    public function test_entrega_identica_y_evidencia_intacta(): void
    {
        $dte = $this->documento();
        $original = $dte->getRawOriginal();
        $archivos = Storage::allFiles();
        $bytes = array_map(fn ($ruta) => Storage::get($ruta), $archivos);
        $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
        $this->assertTrue($entrega->completo());
        $json = json_decode($entrega->contenido, true);
        $this->assertSame(trim(Storage::get($dte->json_firmado_path)), $json['firmaElectronica']);
        unset($json['firmaElectronica'], $json['selloRecibido']);
        $this->assertSame(Storage::get($dte->json_generado_path), json_encode($json, self::FLAGS));
        $payload = explode('.', trim(Storage::get($dte->json_firmado_path)))[1];
        $this->assertSame($json, json_decode(base64_decode(strtr($payload, '-_', '+/')), true));
        foreach (['facturacion.json.descargar', 'facturacion.reporte-contadora.json'] as $ruta) {
            $respuesta = $this->get(route($ruta, $dte))->assertDownload($entrega->nombre);
            $this->assertSame($entrega->contenido, $respuesta->streamedContent());
        }
        $this->enviar($dte);
        Mail::assertSent(DteCorreo::class, fn ($mail) => $mail->adjuntosExtra[0]['contenido'] === $entrega->contenido && $mail->adjuntosExtra[0]['nombre'] === $entrega->nombre);
        $this->assertSame($archivos, Storage::allFiles());
        $this->assertSame($bytes, array_map(fn ($ruta) => Storage::get($ruta), $archivos));
        $this->assertSame($original, $dte->refresh()->getRawOriginal());
        $this->assertSame(trim(Storage::get($dte->json_firmado_path)), app(DteTransmisionService::class)->prepararPayloadRecepcion($dte)['documento']);
        parse_str(parse_url((new DtePdfService)->urlConsultaQr($dte), PHP_URL_QUERY), $qr);
        $this->assertSame($json['identificacion']['codigoGeneracion'], $qr['codGen']);
        $this->assertSame($json['identificacion']['fecEmi'], $qr['fechaEmi']);
        $this->assertSame($json['identificacion']['ambiente'], $qr['ambiente']);
    }

    public function test_estructura_original_se_conserva_byte_a_byte(): void
    {
        // Objeto vacío y número con fracción cero: recodificar los alteraría.
        $dte = $this->documento();
        $original = '{"identificacion":{"codigoGeneracion":"'.$dte->codigo_generacion.'","numeroControl":"'
            .$dte->numero_control.'","tipoDte":"03","ambiente":"00","fecEmi":"2026-10-03"},"extension":{},"resumen":{"total":10.0}}';
        Storage::put($dte->json_generado_path, $original);
        Storage::put($dte->json_firmado_path, $this->jws($original));

        $entrega = app(ArchivoEntregaDteService::class)->construir($dte);

        $this->assertTrue($entrega->completo());
        $this->assertStringStartsWith(substr($original, 0, -1).',', $entrega->contenido);
        $this->assertSame($dte->sello_recepcion, json_decode($entrega->contenido, true)['selloRecibido']);
    }

    public function test_senales_no_fiscales_no_se_adjuntan(): void
    {
        foreach (['rechazado', 'invalidado', 'sello', 'ruta', 'alg', 'header', 'firma', 'respuesta'] as $senal) {
            $dte = $this->documento();
            $json = Storage::get($dte->json_generado_path);
            match ($senal) {
                'rechazado' => $dte->estado = EstadoDte::Rechazado,
                'invalidado' => $dte->estado = EstadoDte::Invalidado,
                'sello' => $dte->sello_recepcion = 'mock-simulado',
                'ruta' => $dte->json_firmado_path = 'ausente.mock.jws',
                'alg' => Storage::put($dte->json_firmado_path, $this->jws($json, ['alg' => 'none'])),
                'header' => Storage::put($dte->json_firmado_path, $this->jws($json, ['alg' => 'RS512', 'mock' => true])),
                'firma' => Storage::put($dte->json_firmado_path, $this->jws($json, firma: 'MOCK-SIN-FIRMA-REAL')),
                'respuesta' => Storage::put($dte->respuesta_mh_path, '{"_mock":true}'),
            };
            $dte->saveQuietly();
            $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
            $this->assertSame(ArchivoEntregaDte::NO_FISCAL, $entrega->estado, $senal);
            $this->assertNull($entrega->contenido);
            $envio = $this->enviar($dte);
            $this->assertSame('PDF', $envio->adjuntos);
            $this->assertStringContainsString('JSON fiscal no adjuntado', $envio->error);
            $this->assertStringNotContainsString('firmaElectronica', Storage::get($dte->json_generado_path));
        }
    }

    public function test_incompletos_acumulan_y_no_se_entregan(): void
    {
        foreach (['sello', 'firma', 'JSON', 'payload', 'respuesta', 'mezcla'] as $caso) {
            $dte = $this->documento();
            match ($caso) {
                'sello' => $dte->sello_recepcion = null,
                'firma' => Storage::delete($dte->json_firmado_path),
                'JSON' => Storage::delete($dte->json_generado_path),
                'payload' => Storage::put($dte->json_firmado_path, $this->jws('{"otro":true}')),
                'respuesta' => $dte->respuesta_mh = ['selloRecibido' => 'OTRO'],
                'mezcla' => Storage::put($dte->json_generado_path, '{"selloRecibido":"SELLO"}'),
            };
            $dte->saveQuietly();
            $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
            $this->assertSame(ArchivoEntregaDte::INCOMPLETO, $entrega->estado, $caso);
            $this->assertNull($entrega->contenido);
            $this->assertNotEmpty($entrega->faltantes);
            $esperado = match ($caso) {
                'sello' => 'sello de recepción', 'firma' => 'firma:', 'JSON' => 'JSON:',
                'payload' => 'payload del JWS', 'respuesta' => 'sello en respuesta_mh',
                'mezcla' => 'el JSON guardado ya trae firma/sello',
            };
            $this->assertStringContainsString($esperado, implode('; ', $entrega->faltantes));
            $this->assertNotEmpty($entrega->recuperacion);
            $envio = $this->enviar($dte);
            $this->assertSame('PDF', $envio->adjuntos);
            $this->assertStringContainsString('Entrega fiscal incompleta', $envio->error);
            $this->get(route('facturacion.json.descargar', $dte))->assertRedirect()->assertSessionHas('error');
            if ($dte->aceptadoRealmentePorMh()) {
                $this->get(route('facturacion.reporte-contadora.json', $dte))->assertRedirect()->assertSessionHas('error');
            }
        }
        $dte = $this->documento();
        Storage::delete([$dte->json_generado_path, $dte->json_firmado_path]);
        $dte->sello_recepcion = null;
        $this->assertCount(3, app(ArchivoEntregaDteService::class)->construir($dte)->faltantes);
    }

    public function test_comando_ignora_no_fiscales_y_devuelve_estado(): void
    {
        $completo = $this->documento();
        $this->artisan('dte:entrega-check')->assertExitCode(0);
        $mock = $this->documento();
        $mock->sello_recepcion = 'MOCK-SIMULADO';
        $mock->saveQuietly();
        $rechazado = $this->documento();
        $rechazado->estado = EstadoDte::Rechazado;
        $rechazado->saveQuietly();
        Storage::delete($completo->json_firmado_path);
        $this->artisan('dte:entrega-check')->expectsOutputToContain('DTE #'.$completo->id)->doesntExpectOutputToContain('DTE #'.$mock->id)->doesntExpectOutputToContain('DTE #'.$rechazado->id)->assertExitCode(1);
        $this->artisan('dte:entrega-check', ['dte' => $mock->id])->expectsOutputToContain('simulado (MOCK)')->assertExitCode(0);
    }

    public function test_equivalencia_recursiva_con_orden_numeros_y_null(): void
    {
        $dte = $this->documento();
        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $json['resumen'] = ['total' => 10.0];
        $json = array_reverse($json, true);
        Storage::put($dte->json_firmado_path, $this->jws(json_encode($json, JSON_PRESERVE_ZERO_FRACTION)));
        $this->assertTrue(app(ArchivoEntregaDteService::class)->construir($dte)->completo());
        $json['resumen']['total'] = 10 + 5e-10;
        Storage::put($dte->json_firmado_path, $this->jws(json_encode($json)));
        $this->assertTrue(app(ArchivoEntregaDteService::class)->construir($dte)->completo());
        foreach (['10', true, 10.01, [], (object) []] as $valor) {
            $json['resumen']['total'] = $valor;
            Storage::put($dte->json_firmado_path, $this->jws(json_encode($json)));
            $this->assertSame(ArchivoEntregaDte::INCOMPLETO, app(ArchivoEntregaDteService::class)->construir($dte)->estado);
        }
    }

    public function test_identificacion_y_respuesta_cruda_incoherentes(): void
    {
        foreach (['codigoGeneracion', 'numeroControl', 'tipoDte', 'respuesta'] as $campo) {
            $dte = $this->documento();
            if ($campo === 'respuesta') {
                Storage::put($dte->respuesta_mh_path, '{"codigoGeneracion":"OTRO"}');
            } else {
                $json = json_decode(Storage::get($dte->json_generado_path), true);
                $json['identificacion'][$campo] = 'OTRO';
                Storage::put($dte->json_generado_path, json_encode($json));
                Storage::put($dte->json_firmado_path, $this->jws(json_encode($json)));
            }
            $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
            $this->assertSame(ArchivoEntregaDte::INCOMPLETO, $entrega->estado);
            $this->assertStringContainsString($campo === 'respuesta' ? 'respuesta de Hacienda' : 'identificacion.'.$campo, $entrega->explicacion());
        }
    }

    public function test_json_invalido_y_firmas_invalidas(): void
    {
        $dte = $this->documento();
        foreach (['[]', 'null', 'sin JSON'] as $json) {
            Storage::put($dte->json_generado_path, $json);
            $this->assertStringContainsString('no es un objeto JSON', app(ArchivoEntregaDteService::class)->construir($dte)->explicacion());
        }
        $dte = $this->documento();
        foreach (['a.b', 'a.b.c.d', 'a..c', 'a.@@.c', 'a.bnVsbA.c', 'a.W10.c'] as $firma) {
            Storage::put($dte->json_firmado_path, $firma);
            $this->assertStringContainsString('no es JWS compacto', app(ArchivoEntregaDteService::class)->construir($dte)->explicacion());
        }
    }

    public function test_almacenamiento_fallido_se_distingue_de_ausencia(): void
    {
        $dte = $this->documento();
        config(['dte.storage.disk' => 'disco-inexistente']);
        $entrega = app(ArchivoEntregaDteService::class)->construir($dte);
        $this->assertSame(ArchivoEntregaDte::INCOMPLETO, $entrega->estado);
        $this->assertCount(2, $entrega->faltantes);
        $this->assertStringContainsString('Error de almacenamiento', $entrega->explicacion());
        $this->assertStringContainsString('disco-inexistente', $entrega->explicacion());
    }

    public function test_aceptado_sin_ruta_json_redirige_con_recuperacion(): void
    {
        $dte = $this->documento();
        $dte->json_generado_path = null;
        $dte->saveQuietly();
        $this->get(route('facturacion.json.descargar', $dte))->assertRedirect()
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'restaurar el archivo desde el respaldo'));
    }

    public function test_descarga_no_fiscal_conserva_json_tecnico(): void
    {
        foreach ([EstadoDte::Rechazado, EstadoDte::Invalidado, EstadoDte::Aceptado] as $estado) {
            $dte = $this->documento();
            $dte->estado = $estado;
            $dte->sello_recepcion = 'MOCK-SIMULADO';
            $dte->saveQuietly();
            $respuesta = $this->get(route('facturacion.json.descargar', $dte))->assertOk();
            $this->assertSame(Storage::get($dte->json_generado_path), $respuesta->streamedContent());
            $json = json_decode($respuesta->streamedContent(), true);
            $this->assertArrayNotHasKey('firmaElectronica', $json);
            $this->assertArrayNotHasKey('selloRecibido', $json);
        }
    }

    public function test_simulado_conserva_incidencia_fiscal_en_historial(): void
    {
        config(['mail.default' => 'log']);
        $dte = $this->documento();
        Storage::delete($dte->json_firmado_path);
        $envio = $this->enviar($dte);
        $this->assertSame('simulado', $envio->estado);
        $this->assertStringContainsString('Entrega fiscal incompleta', $envio->error);
        Mail::assertNothingSent();
    }

    public function test_qr_sin_datos_no_inventa_url(): void
    {
        $dte = $this->documento();
        $dte->sello_recepcion = null;
        $this->assertNull((new DtePdfService)->urlConsultaQr($dte));
    }
}
