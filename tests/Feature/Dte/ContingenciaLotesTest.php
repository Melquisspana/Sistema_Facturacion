<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\LoteDte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\LoteDteService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class ContingenciaLotesTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Contingencia $contingencia;

    private ContingenciaEvento $evento;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-13 11:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.transmision.enabled' => true, 'dte.transmision.dry_run' => false,
            'dte.transmision.real_confirmation' => true, 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer TOKEN-INVENTADO', 'dte.firma.mock' => false]);
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->emisor['empresa']->update(['nit' => '06141234561018', 'razon_social' => 'Emisor Inventado']);
        $this->contingencia = Contingencia::create(['tipo' => 3, 'origen' => 'manual', 'inicio' => '2026-10-13 08:00:00',
            'cese' => '2026-10-13 09:00:00', 'estado' => 'informada']);
        $this->evento = $this->contingencia->eventos()->create(['parte' => 1, 'codigo_generacion' => strtoupper((string) Str::uuid()),
            'estado' => 'recibido', 'sello_recibido' => 'SELLO-EVENTO-INVENTADO',
            'fecha_transmision' => '2026-10-13 09:30:00', 'fecha_procesamiento' => '2026-10-13 10:00:00']);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function simular(array $consulta = [], bool $conexion = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*recepcionlote' => fn ($r) => Http::response(['estado' => 'RECIBIDO', 'codigoLote' => 'LOTE-'.$r['idEnvio']]),
            '*consultadtelote/*' => $conexion ? Http::failedConnection() : Http::response($consulta),
        ]);
    }

    private function documento(string $estado = 'firmado', string $tipo = '01'): Dte
    {
        $dte = Dte::create(['tipo_dte' => $tipo, 'ambiente' => '00', 'estado' => $estado,
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id,
            'contingencia_id' => $this->contingencia->id, 'contingencia_evento_id' => $this->evento->id,
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'numero_control' => 'DTE-'.$tipo.'-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-13', 'hora_emision' => '08:30:00']);
        $dte->json_firmado_path = 'inventados/'.$dte->codigo_generacion.'.jws';
        $dte->save();
        Storage::put($dte->json_firmado_path, 'FIRMA.INVENTADA.'.$dte->codigo_generacion);

        return $dte;
    }

    private function service(): LoteDteService
    {
        return app(LoteDteService::class);
    }

    public function test_250_documentos_division_uuid_payload_y_no_duplicacion(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->documento();
        }
        $this->service()->enviar($this->evento);
        $lotes = LoteDte::orderBy('id')->get();
        $this->assertSame([100, 100, 50], $lotes->pluck('cantidad')->all());
        foreach ($lotes as $lote) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', $lote->id_envio);
            $this->assertSame($lote->cantidad, $lote->dtes()->count());
            Http::assertSent(fn ($r) => $r['idEnvio'] === $lote->id_envio && count($r['documentos']) === $lote->cantidad
                && $r['nitEmisor'] === '06141234561018' && $r['ambiente'] === '00' && $r['version'] === 1);
        }
        $this->service()->enviar($this->evento);
        $this->assertSame(3, LoteDte::count());
        Http::assertSentCount(3);
    }

    public function test_consulta_mixta_por_codigo_no_index_y_regulariza(): void
    {
        $a = $this->documento();
        $r = $this->documento();
        $this->service()->enviar($this->evento);
        $this->simular(['procesados' => [['codigoGeneracion' => $a->codigo_generacion, 'index' => 2,
            'selloRecibido' => 'SELLO-INVENTADO-A', 'fhProcesamiento' => '13/10/2026 11:05:00']],
            'rechazados' => [['codigoGeneracion' => $r->codigo_generacion, 'index' => 1, 'observaciones' => ['Motivo inventado']]]]);
        $lote = LoteDte::first();
        $this->assertSame('procesado', $this->service()->consultar($lote)['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $a->refresh()->estado);
        $this->assertSame('SELLO-INVENTADO-A', $a->sello_recepcion);
        $this->assertNotNull($a->fecha_procesamiento_mh);
        $this->assertSame(EstadoDte::Rechazado, $r->refresh()->estado);
        $this->assertSame(['Motivo inventado'], $r->respuesta_mh['observaciones']);
        $this->assertSame('regularizada', $this->contingencia->refresh()->estado);
        $this->assertSame(1, $lote->refresh()->procesados);
        $this->assertSame(1, $lote->rechazados);
        Storage::assertExists([$lote->respuesta_envio_path, $lote->respuesta_consulta_path]);
        $this->service()->consultar($lote);
        Http::assertSentCount(1);
    }

    public function test_consulta_vacia_en_proceso_y_error_conexion_se_repiten(): void
    {
        $dte = $this->documento();
        $this->service()->enviar($this->evento);
        $lote = LoteDte::first();
        foreach ([[], ['estado' => 'EN PROCESO', 'procesados' => [], 'rechazados' => []]] as $consulta) {
            $this->simular($consulta);
            $this->assertSame('recibido', $this->service()->consultar($lote)['resultado']);
            $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        }
        $this->simular([], true);
        $this->assertSame('error_conexion', $this->service()->consultar($lote)['resultado']);
        $this->assertSame('recibido', $lote->refresh()->estado);
        $this->assertSame(3, $lote->consultas);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
    }

    public function test_codigo_desconocido_duplicado_y_sin_sello_no_aplican(): void
    {
        $dte = $this->documento();
        $this->service()->enviar($this->evento);
        $this->simular(['procesados' => [
            ['codigoGeneracion' => 'DESCONOCIDO', 'index' => 1, 'selloRecibido' => 'OTRO'],
            ['codigoGeneracion' => $dte->codigo_generacion, 'selloRecibido' => 'SELLO'],
        ], 'rechazados' => [['codigoGeneracion' => $dte->codigo_generacion]]]);
        $this->service()->consultar(LoteDte::first());
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->simular(['procesados' => [['codigoGeneracion' => $dte->codigo_generacion]]]);
        $this->service()->consultar(LoteDte::first());
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
    }

    public function test_borde_72_h_desde_procesamiento_y_vencimiento(): void
    {
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'America/El_Salvador'));
        $this->assertSame('recibido', $this->service()->enviar($this->evento)[0]['resultado']);
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:01', 'America/El_Salvador'));
        try {
            $this->service()->enviar($this->evento);
            $this->fail('Debio bloquear el envio vencido');
        } catch (ValidationException) {
            $this->assertSame('vencida', $this->contingencia->refresh()->estado);
        }
        Http::assertSentCount(1);
        $this->get(route('facturacion.contingencia.pendientes'))->assertOk()->assertSee('Vencido');
    }

    public function test_plazo_usa_transmision_si_no_hay_procesamiento(): void
    {
        $this->evento->update(['fecha_procesamiento' => null]);
        $this->assertSame('2026-10-16 09:30:00', $this->service()->plazo($this->evento)->format('Y-m-d H:i:s'));
    }

    public function test_aceptados_no_se_reenvian_y_limite_configurable(): void
    {
        $aceptado = $this->documento('aceptado');
        for ($i = 0; $i < 3; $i++) {
            $this->documento();
        }
        config(['dte.contingencia.lote_max' => 2]);
        $this->service()->enviar($this->evento);
        $this->assertSame([2, 1], LoteDte::orderBy('id')->pluck('cantidad')->all());
        $this->assertNull($aceptado->refresh()->lote_dte_id);
    }

    public function test_evento_no_recibido_no_envia(): void
    {
        $this->documento();
        $this->evento->update(['estado' => 'preparado']);
        $this->expectException(ValidationException::class);
        try {
            $this->service()->enviar($this->evento);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_bandeja_permisos_contenido_y_interruptor(): void
    {
        $dte = $this->documento();
        $url = route('facturacion.contingencia.pendientes');
        $this->get($url)->assertOk()->assertSee('Pendientes de envío')->assertSee($dte->codigo_generacion)->assertSee('informado')->assertSee('72 h');
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));
        $this->get($url)->assertForbidden();
        config(['dte.contingencia.enabled' => false]);
        $this->get($url)->assertNotFound();
        $this->artisan('dte:contingencia-lotes')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_bandeja_sin_tabla_no_falla(): void
    {
        $real = Schema::getFacadeRoot();
        Schema::partialMock()->shouldReceive('hasTable')
            ->andReturnUsing(fn (string $tabla) => $tabla !== 'lotes_dte' && $real->hasTable($tabla));
        $this->get(route('facturacion.contingencia.pendientes'))->assertOk()->assertSee('falta habilitar');
    }

    public function test_emitir_corregido_reutiliza_ccf_y_codigo_nuevo_al_generar(): void
    {
        $dte = $this->documento('rechazado', '03');
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Inventado']);
        Dte::whereKey($dte->id)->update(['cliente_id' => $cliente->id, 'condicion_operacion' => 1, 'estado' => 'borrador']);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte->refresh(),
            Producto::factory()->create(['nombre' => 'Producto Inventado', 'precio_unitario' => 10]), cantidad: 2);
        Dte::whereKey($dte->id)->update(['estado' => 'rechazado']);
        $this->post(route('facturacion.contingencia.corregir', $dte))->assertRedirect();
        $nuevo = Dte::latest('id')->first();
        $this->assertNotSame($dte->id, $nuevo->id);
        $this->assertSame(EstadoDte::Borrador, $nuevo->estado);
        $this->assertSame($cliente->id, $nuevo->cliente_id);
        $this->assertSame(1, $nuevo->lineas()->count());
        $this->assertNull($nuevo->contingencia_id);
        $this->assertNull($nuevo->contingencia_evento_id);
        $this->assertNull($nuevo->lote_dte_id);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00', 'ultimo_numero' => 1, 'activo' => true]);
        app(DteGeneracionService::class)->generar($nuevo);
        $this->assertNotSame($dte->codigo_generacion, $nuevo->refresh()->codigo_generacion);
        $this->assertNotNull($nuevo->codigo_generacion);
        $sinDuplicador = $this->documento('rechazado');
        $this->get(route('facturacion.contingencia.pendientes'))->assertSee('todavía no tiene duplicador');
        $this->post(route('facturacion.contingencia.corregir', $sinDuplicador))->assertStatus(422);
    }

    public function test_tarea_envia_consulta_y_no_envia_vencidos(): void
    {
        $this->documento();
        $this->artisan('dte:contingencia-lotes')->assertSuccessful();
        $this->assertSame(1, LoteDte::count());
        $this->assertSame(1, LoteDte::first()->consultas);
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:01', 'America/El_Salvador'));
        $this->simular();
        $this->artisan('dte:contingencia-lotes')->assertSuccessful();
        $this->assertSame('vencida', $this->contingencia->refresh()->estado);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/recepcionlote'));
    }

    public function test_schedule_interruptor_y_sin_solapamiento(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'dte:contingencia-lotes'));
        $this->assertNotNull($evento);
        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        config(['dte.contingencia.enabled' => false]);
        $this->assertFalse($evento->filtersPass($this->app));
        config(['dte.contingencia.enabled' => true]);
        $this->assertTrue($evento->filtersPass($this->app));
    }

    public function test_timeout_y_respuesta_incierta_conservan_uuid_payload_y_vinculos(): void
    {
        $dte = $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*recepcionlote' => Http::failedConnection()]);
        $this->assertSame('error_conexion', $this->service()->enviar($this->evento)[0]['resultado']);
        $lote = LoteDte::first();
        $payload = Storage::get('dte/contingencia/lotes/'.$lote->id_envio.'.json');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*recepcionlote' => Http::response(['estado' => 'RECIBIDO'])]);
        $this->assertSame('respuesta_incierta', $this->service()->enviar($this->evento)[0]['resultado']);
        $this->assertSame('pendiente', $lote->refresh()->estado);
        Storage::assertExists($lote->respuesta_envio_path);
        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->evento)[0]['resultado']);
        $this->assertSame(1, LoteDte::count());
        $this->assertSame($lote->id, $dte->refresh()->lote_dte_id);
        Http::assertSent(fn ($r) => json_encode($r->data(), JSON_THROW_ON_ERROR) === $payload);
    }

    public function test_consulta_parcial_se_completa_sin_reaplicar_aceptado(): void
    {
        $a = $this->documento();
        $b = $this->documento();
        $this->service()->enviar($this->evento);
        $procesado = ['codigoGeneracion' => $a->codigo_generacion, 'selloRecibido' => 'SELLO-A'];
        $this->simular(['procesados' => [$procesado]]);
        $this->service()->consultar(LoteDte::first());
        $this->assertSame('recibido', LoteDte::first()->estado);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->simular(['procesados' => [$procesado, ['codigoGeneracion' => $b->codigo_generacion, 'selloRecibido' => 'SELLO-B']]]);
        $this->assertSame('procesado', $this->service()->consultar(LoteDte::first())['resultado']);
        $this->assertSame('SELLO-A', $a->refresh()->sello_recepcion);
        $this->assertSame('SELLO-B', $b->refresh()->sello_recepcion);
        $this->assertSame(2, LoteDte::first()->procesados);
    }

    public function test_candados_compartidos_bloquean_envio_y_consulta(): void
    {
        $this->documento();
        $this->service()->enviar($this->evento);
        $lote = LoteDte::first();
        $this->simular();
        foreach (['dte.transmision.dry_run' => true, 'dte.transmision.real_confirmation' => false,
            'dte.firma.mock' => true, 'dte.transmision.enabled' => false] as $clave => $valor) {
            $anterior = config($clave);
            config([$clave => $valor]);
            try {
                $this->service()->consultar($lote);
                $this->fail('Debio bloquear la consulta');
            } catch (ValidationException) {
                Http::assertNothingSent();
            } finally {
                config([$clave => $anterior]);
            }
        }
        config(['dte.ambientes.00.consulta_lote_url' => 'https://impostor.invalid/consulta']);
        try {
            $this->service()->consultar($lote);
            $this->fail('Debio bloquear endpoint');
        } catch (DteTransmisionDeshabilitadaException) {
            Http::assertNothingSent();
        }
        $this->documento();
        config(['dte.firma.mock' => true]);
        $this->expectException(ValidationException::class);
        try {
            $this->service()->enviar($this->evento);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_lote_rechazado_libera_documentos_y_reenvia_con_otro_uuid_conservando_evidencia(): void
    {
        $documentos = collect([$this->documento(), $this->documento()]);
        $rechazo = ['estado' => 'RECHAZADO', 'mensaje' => 'Lote de prueba rechazado',
            'observaciones' => ['Revisar estructura inventada', ['detalle' => 'Observacion inventada']]];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*recepcionlote' => Http::response($rechazo, 400)]);

        $this->assertSame('error', $this->service()->enviar($this->evento)[0]['resultado']);
        $primero = LoteDte::firstOrFail();
        $path = $primero->respuesta_envio_path;
        $this->assertSame('error', $primero->estado);
        $this->assertSame(2, $primero->cantidad);
        $this->assertSame($rechazo, $primero->respuesta_envio);
        Storage::assertExists($path);
        $evidencia = Storage::get($path);
        foreach ($documentos as $dte) {
            $this->assertNull($dte->refresh()->lote_dte_id);
            $this->assertSame(EstadoDte::Firmado, $dte->estado);
            $this->assertNull($dte->sello_recepcion);
        }
        $this->get(route('facturacion.contingencia.pendientes'))->assertOk()
            ->assertSee($primero->id_envio)->assertSee('Lote de prueba rechazado')
            ->assertSee('Revisar estructura inventada')->assertSee('Observacion inventada')
            ->assertSee('Enviar a Hacienda');

        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->evento)[0]['resultado']);
        $segundo = LoteDte::latest('id')->firstOrFail();
        $this->assertSame(2, LoteDte::count());
        $this->assertNotSame($primero->id_envio, $segundo->id_envio);
        $this->assertSame('recibido', $segundo->estado);
        $this->assertSame($documentos->pluck('id')->all(), $segundo->dtes()->orderBy('id')->pluck('id')->all());
        foreach ($documentos as $dte) {
            $this->assertSame($segundo->id, $dte->refresh()->lote_dte_id);
        }
        $this->assertSame('error', $primero->refresh()->estado);
        $this->assertSame(2, $primero->cantidad);
        $this->assertSame($rechazo, $primero->respuesta_envio);
        $this->assertSame($path, $primero->respuesta_envio_path);
        $this->assertSame($evidencia, Storage::get($path));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['idEnvio'] === $segundo->id_envio
            && $r['documentos'] === $documentos->map(fn ($dte) => Storage::get($dte->json_firmado_path))->all());
        $this->get(route('facturacion.contingencia.pendientes'))->assertOk()
            ->assertSee($primero->id_envio)->assertSee('Lote de prueba rechazado');
    }
}
