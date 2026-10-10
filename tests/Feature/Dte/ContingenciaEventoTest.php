<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Models\User;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\DteSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class ContingenciaEventoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Contingencia $contingencia;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 11:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.invalidacion.responsable' => ['nombre' => 'Responsable Inventado', 'tipo_doc' => '13', 'num_doc' => '12345678-9'],
            'dte.invalidacion.cod_estable_mh' => '', 'dte.invalidacion.cod_punto_venta_mh' => '',
            'dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.transmision.enabled' => true, 'dte.transmision.dry_run' => false,
            'dte.transmision.real_confirmation' => true, 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer TOKEN-INVENTADO']);
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->emisor['empresa']->update(['razon_social' => 'Emisor Inventado', 'nit' => '06141234561018', 'direccion' => 'Calle Inventada 123']);
        $this->contingencia = Contingencia::create(['tipo' => 3, 'motivo' => 'Internet inventado interrumpido',
            'origen' => 'manual', 'inicio' => '2026-10-10 09:00:00', 'cese' => '2026-10-10 10:15:00', 'estado' => 'cerrada']);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function simular(string $estado = 'RECIBIDO', bool $conexion = false, ?string $recibido = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*consultadte*' => function ($request) use ($recibido) {
                return $request['codigoGeneracion'] === $recibido
                    ? Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DTE-INVENTADO', 'fhProcesamiento' => '10/10/2026 10:30:00'])
                    : Http::response([], 404);
            },
            '*fesv/contingencia' => $conexion ? Http::failedConnection() : Http::response([
                'estado' => $estado, 'selloRecibido' => $estado === 'RECIBIDO' ? 'SELLO-EVENTO-INVENTADO' : null,
                'fechaHora' => '10/10/2026 11:00:00', 'mensaje' => $estado === 'RECIBIDO' ? 'Recibido' : 'Responsable incorrecto',
                'observaciones' => $estado === 'RECIBIDO' ? [] : ['Corregir responsable'],
            ], $estado === 'RECIBIDO' ? 200 : 400),
        ]);
    }

    private function documento(): Dte
    {
        $dte = Dte::create(['tipo_dte' => '01', 'ambiente' => '00', 'estado' => 'firmado',
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id,
            'contingencia_id' => $this->contingencia->id, 'codigo_generacion' => strtoupper((string) Str::uuid()),
            'numero_control' => 'DTE-01-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-10', 'hora_emision' => '09:30:00']);

        return $dte;
    }

    private function service(): ContingenciaEventoService
    {
        return app(ContingenciaEventoService::class);
    }

    private function leerEvento(ContingenciaEvento $evento): array
    {
        return json_decode(Storage::get($evento->json_path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_esquema_division_1001_y_codigos_propios(): void
    {
        for ($i = 0; $i < 1001; $i++) {
            $this->documento();
        }
        $partes = $this->service()->preparar($this->contingencia);
        $this->assertCount(2, $partes);
        $this->assertNotSame($partes[0]->codigo_generacion, $partes[1]->codigo_generacion);
        foreach ([1000, 1] as $i => $cantidad) {
            $json = $this->leerEvento($partes[$i]);
            $this->assertCount($cantidad, $json['detalleDTE']);
            $this->assertSame(1, $json['detalleDTE'][0]['noItem']);
            $this->assertTrue(app(DteSchemaValidator::class)->validarContingencia($json)['valido']);
            $this->assertSame($cantidad, $partes[$i]->dtes()->count());
        }
        $this->assertCount(2, $this->service()->preparar($this->contingencia));
        Http::assertSentCount(1001);
    }

    public function test_recibido_guarda_evidencia_y_no_envia_dos_veces(): void
    {
        $this->documento();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $evento = ContingenciaEvento::first();
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertSame('SELLO-EVENTO-INVENTADO', $evento->sello_recibido);
        Storage::assertExists([$evento->json_path, $evento->jws_path, $evento->respuesta_mh_path]);
        $this->assertSame('FIRMA.INVENTADA.JWS', Storage::get($evento->jws_path));
        $this->assertSame('RECIBIDO', json_decode(Storage::get($evento->respuesta_mh_path), true)['estado']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia')
            && $request['nit'] === '06141234561018' && $request['documento'] === 'FIRMA.INVENTADA.JWS');
        $this->assertSame([], $this->service()->enviar($this->contingencia));
        Http::assertSentCount(3);
    }

    public function test_rechazo_visible_y_correccion_con_codigo_nuevo_en_24_horas(): void
    {
        $dte = $this->documento();
        $this->simular('RECHAZADO');
        $this->assertSame('rechazado', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $anterior = ContingenciaEvento::first();
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()->assertSee('Corregir responsable');
        config(['dte.invalidacion.responsable.nombre' => 'Responsable Corregido']);
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:59:59', 'America/El_Salvador'));
        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $nuevo = ContingenciaEvento::latest('id')->first();
        $this->assertNotSame($anterior->codigo_generacion, $nuevo->codigo_generacion);
        $this->assertSame('Responsable Corregido', $this->leerEvento($nuevo)['emisor']['nombreResponsable']);
        $this->assertSame('rechazado', $anterior->refresh()->estado);
        $this->assertSame($nuevo->id, $dte->refresh()->contingencia_evento_id);
        Storage::assertExists([$anterior->json_path, $anterior->jws_path, $anterior->respuesta_mh_path]);
    }

    public function test_envio_a_101459_del_dia_siguiente_en_plazo(): void
    {
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:14:59', 'America/El_Salvador'));
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
    }

    public function test_envio_a_101501_del_dia_siguiente_vencido(): void
    {
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:15:01', 'America/El_Salvador'));
        $this->expectException(ValidationException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('contingencia_eventos', 0);
        }
    }

    public function test_cruce_medianoche_local_y_plazo_exacto_inclusivo(): void
    {
        $this->contingencia->update(['cese' => '2026-10-10 23:59:59']);
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 23:59:59', 'America/El_Salvador'));
        $parte = $this->service()->preparar($this->contingencia)->first();
        $this->assertSame('2026-10-11', $this->leerEvento($parte)['identificacion']['fTransmision']);
        $this->assertSame('23:59:59', $this->leerEvento($parte)['identificacion']['hTransmision']);
        Carbon::setTestNow(Carbon::parse('2026-10-12 00:00:00', 'America/El_Salvador'));
        $this->expectException(ValidationException::class);
        $this->service()->enviar($this->contingencia);
    }

    public function test_informe_tecnico_desde_tres_dias_exactos_no_antes(): void
    {
        $this->contingencia->update(['cese' => null, 'estado' => 'activa']);
        Carbon::setTestNow(Carbon::parse('2026-10-13 08:59:59', 'America/El_Salvador'));
        $this->assertFalse($this->service()->requiereInformeTecnico($this->contingencia));
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'America/El_Salvador'));
        $this->assertTrue($this->service()->requiereInformeTecnico($this->contingencia));
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()->assertSee('Informe Técnico');
    }

    public function test_documento_recibido_se_excluye_y_acepta_por_camino_normal(): void
    {
        $recibido = $this->documento();
        $pendiente = $this->documento();
        $this->simular(recibido: $recibido->codigo_generacion);
        $parte = $this->service()->preparar($this->contingencia)->first();
        $this->assertSame(EstadoDte::Aceptado, $recibido->refresh()->estado);
        $this->assertSame('SELLO-DTE-INVENTADO', $recibido->sello_recepcion);
        $this->assertNotNull($recibido->fecha_procesamiento_mh);
        $this->assertNull($recibido->contingencia_id);
        $this->assertNull($recibido->contingencia_evento_id);
        $this->assertCount(1, $this->leerEvento($parte)['detalleDTE']);
        $this->assertSame($pendiente->codigo_generacion, $this->leerEvento($parte)['detalleDTE'][0]['codigoGeneracion']);
        $this->assertSame(2, $recibido->historial()->count());
    }

    public function test_error_conexion_no_cambia_parte_y_permite_reintentar(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->first();
        $antes = $parte->getAttributes();
        $this->simular(conexion: true);
        $this->assertSame('error_conexion', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame($antes, $parte->refresh()->getAttributes());
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertDatabaseCount('contingencia_eventos', 1);
        $this->assertSame($parte->codigo_generacion, ContingenciaEvento::first()->codigo_generacion);
    }

    public function test_sin_cese_no_firma_ni_consulta(): void
    {
        $this->contingencia->update(['cese' => null, 'estado' => 'activa']);
        $this->documento();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        Http::assertNothingSent();
    }

    public function test_rutas_sin_permiso_403_y_apagadas_404(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertForbidden();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertForbidden();
        config(['dte.contingencia.enabled' => false]);
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertNotFound();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_responsable_vacio_bloquea_antes_de_firmar(): void
    {
        $this->documento();
        config(['dte.invalidacion.responsable.nombre' => '']);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'firmardocumento') || str_ends_with($request->url(), '/contingencia'));
    }

    public function test_reenvio_fuera_de_plazo_del_rechazo_no_crea_otro_evento(): void
    {
        $this->documento();
        $this->simular('RECHAZADO');
        $this->service()->enviar($this->contingencia);
        Carbon::setTestNow(Carbon::parse('2026-10-11 11:00:01', 'America/El_Salvador'));
        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 1);
        Http::assertNothingSent();
    }

    public function test_todas_las_partes_deben_recibirse_para_marcar_informada(): void
    {
        for ($i = 0; $i < 1001; $i++) {
            $this->documento();
        }
        $partes = $this->service()->preparar($this->contingencia);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::sequence()
                ->push(['estado' => 'RECIBIDO', 'selloRecibido' => 'SELLO-PRIMERA-PARTE'])
                ->push(['estado' => 'RECHAZADO', 'mensaje' => 'Corregir segunda parte'], 400),
        ]);
        $resultados = $this->service()->enviar($this->contingencia);
        $this->assertSame(['recibido', 'rechazado'], array_column($resultados, 'resultado'));
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->simular();
        $this->service()->enviar($this->contingencia);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertSame($partes[0]->codigo_generacion, $this->service()->partesVigentes($this->contingencia)[0]->codigo_generacion);
        $this->assertDatabaseCount('contingencia_eventos', 3);
        Http::assertSentCount(3); // Solo consulta, firma y envio de la segunda parte.
    }

    public function test_unico_documento_ya_recibido_se_acepta_sin_evento_vacio(): void
    {
        $dte = $this->documento();
        $this->simular(recibido: $dte->codigo_generacion);
        $this->assertSame('sin_documentos', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertSentCount(1);
    }

    public function test_consulta_incierta_bloquea_inclusion(): void
    {
        $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*consultadte*' => Http::failedConnection()]);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertSentCount(1);
    }

    public function test_endpoint_no_oficial_se_bloquea_antes_de_token_o_firma(): void
    {
        $this->documento();
        config(['dte.ambientes.00.contingencia_url' => 'https://impostor.example.test/fesv/contingencia']);
        $this->expectException(DteTransmisionDeshabilitadaException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_respuesta_sin_sello_no_marca_recibido(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->first();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::response(['estado' => 'RECIBIDO']),
        ]);
        $this->assertSame('respuesta_incierta', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame('preparado', $parte->refresh()->estado);
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
    }

    public function test_servicio_apagado_no_prepara_ni_envia(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $this->expectException(HttpException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('contingencia_eventos', 0);
        }
    }
}
