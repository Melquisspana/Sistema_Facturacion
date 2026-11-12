<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\GeneracionException;
use App\Exceptions\Dte\TransicionInvalidaException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteStateMachine;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * EL MISMO DOCUMENTO, DOS PEDIDOS A LA VEZ.
 *
 * Emitir no tenía candado por documento: dos pedidos sobre el mismo DTE (otra pestaña,
 * otro usuario, un reenvío con Hacienda lenta) podían tomar dos números de control,
 * enviar dos veces el mismo código de generación o dejar «Rechazado» encima de un
 * «Aceptado».
 *
 * SQLite no reproduce dos procesos, así que la regla se prueba POR PASOS, con lo que
 * cada pedido tiene en la mano en ese momento:
 *   1. el segundo pedido encuentra el candado tomado y no hace nada;
 *   2. una respuesta tardía no pisa un estado que ya avanzó;
 *   3. generar con una copia vieja del borrador no cambia número ni código.
 * La espera real entre dos procesos (`Cache::lock`, `lockForUpdate`) la ejerce MySQL.
 *
 * Nada sale a la red: candado global de {@see TestCase} más `Http::fake()`.
 */
class EmisionCandadoPorDocumentoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const OCUPADO = 'Este documento ya se está emitiendo. Esperá unos segundos y recargá.';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Candados abiertos contra APITEST, con firma simulada. Producción no se toca. */
    private function habilitar(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.token', 'Bearer TOKEN_FAKE_NO_REAL');
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', true);
    }

    private function borrador(): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        return $dte->refresh();
    }

    private function generado(): Dte
    {
        $dte = $this->borrador();
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** Deja el documento FIRMADO, como si un intento anterior hubiera llegado hasta ahí. */
    private function firmado(): Dte
    {
        $dte = $this->generado();
        $ruta = 'dte/firmados/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.jws';
        Storage::disk('local')->put($ruta, 'eyJ.fake.jws.compacta');
        $dte->json_firmado_path = $ruta;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    /** El otro pedido: tiene el candado del documento y todavía no terminó. */
    private function otroPedidoEmitiendo(Dte $dte): void
    {
        $this->assertTrue(Cache::lock(CandadoEmisionDte::clave($dte), 60)->get());
    }

    private function candadoLibre(Dte $dte): bool
    {
        return (bool) Cache::lock(CandadoEmisionDte::clave($dte), 5)->get();
    }

    // ===================== 1) EL SEGUNDO PEDIDO ENCUENTRA EL CANDADO TOMADO

    public function test_firmar_y_transmitir_con_el_documento_ya_emitiendose_avisa_y_no_envia_nada(): void
    {
        $this->habilitar();
        Http::fake();
        $dte = $this->firmado();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('error', self::OCUPADO);

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
        $this->assertNull($dte->fresh()->sello_recepcion);
    }

    public function test_generar_con_el_documento_ya_emitiendose_avisa_y_no_gasta_numero(): void
    {
        $dte = $this->borrador();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->from(route('facturacion.edit', $dte))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.edit', $dte))
            ->assertSessionHasErrors(['generar' => self::OCUPADO]);

        $this->assertSame(EstadoDte::Borrador, $dte->fresh()->estado);
        $this->assertNull($dte->fresh()->numero_control);
        $this->assertSame(0, (int) Correlativo::first()->ultimo_numero);
    }

    public function test_generar_y_transmitir_produccion_con_el_documento_ya_emitiendose_avisa_y_no_hace_nada(): void
    {
        Http::fake();
        $dte = $this->borrador();
        Dte::whereKey($dte->id)->update(['ambiente' => '01']);
        $dte->refresh();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar-transmitir-produccion', $dte), [
                'barrera_conta' => 1, 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('error', self::OCUPADO);

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Borrador, $dte->fresh()->estado);
        $this->assertSame(0, (int) Correlativo::first()->ultimo_numero);
    }

    public function test_el_comando_de_transmitir_respeta_el_mismo_candado(): void
    {
        $this->habilitar();
        Http::fake();
        $dte = $this->firmado();
        $this->otroPedidoEmitiendo($dte);

        $this->artisan('dte:transmitir', ['dte' => $dte->id])
            ->expectsOutputToContain(self::OCUPADO)
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
    }

    public function test_al_terminar_la_emision_el_candado_queda_libre(): void
    {
        $this->habilitar();
        config()->set('dte.transmision.mock', true);
        $dte = $this->generado();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertSame(EstadoDte::Aceptado, $dte->fresh()->estado);
        $this->assertTrue($this->candadoLibre($dte));
    }

    public function test_si_la_emision_falla_el_candado_tambien_queda_libre(): void
    {
        // Transmisión cerrada por los candados de seguridad: el pedido termina con error.
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', true);
        $dte = $this->generado();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertSessionHas('error');

        $this->assertTrue($this->candadoLibre($dte));
    }

    // ===================== 2) UNA RESPUESTA TARDÍA NO PISA UN ESTADO QUE YA AVANZÓ

    public function test_un_rechazo_tardio_de_otro_pedido_no_convierte_en_rechazado_un_documento_aceptado(): void
    {
        $this->habilitar();
        $dte = $this->firmado();
        // El segundo pedido cargó el documento cuando todavía estaba firmado.
        $copiaVieja = Dte::findOrFail($dte->id);

        Http::fakeSequence('*')
            ->push(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0001', 'descripcionMsg' => 'RECIBIDO'], 200)
            ->push(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Código de generación ya existe', 'observaciones' => []], 400);

        $transmision = app(DteTransmisionService::class);
        $primero = $transmision->transmitir($dte);
        $this->assertSame('aceptado', $primero['resultado']);

        // La respuesta del segundo envío llega después y dice «rechazado».
        $tardio = $transmision->transmitir($copiaVieja);

        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertSame('SELLO-DE-PRUEBA-0001', $guardado->sello_recepcion);
        $this->assertSame('aceptado', $guardado->respuesta_mh['resultado']);
        // Quien hizo el segundo pedido ve lo que quedó guardado, no el rechazo tardío.
        $this->assertSame('aceptado', $tardio['resultado']);
        $this->assertSame('SELLO-DE-PRUEBA-0001', $tardio['sello']);
        // Ni una transición de más en el historial.
        $this->assertSame(0, $guardado->historial()->where('estado_nuevo', 'rechazado')->count());
        $this->assertSame(1, $guardado->historial()->where('estado_nuevo', 'aceptado')->count());
    }

    // ===================== 2b) PERO UN SELLO DE HACIENDA PARA EL MISMO CÓDIGO MANDA

    /**
     * Deja el documento RECHAZADO por el primer pedido y devuelve la copia que el segundo
     * cargó cuando todavía estaba firmado. La respuesta del segundo es la que se le pase.
     *
     * @param  array<string, mixed>  $respuestaTardia
     */
    private function rechazadoConRespuestaTardia(array $respuestaTardia, int $status = 200): Dte
    {
        $this->habilitar();
        $dte = $this->firmado();
        $copiaVieja = Dte::findOrFail($dte->id);

        // Después del rechazo, Hacienda contesta siempre lo mismo: sirve para reintentar.
        Http::fakeSequence('*')
            ->push(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Código de generación ya existe', 'observaciones' => []], 400)
            ->whenEmpty(Http::response($respuestaTardia, $status));

        $this->assertSame('rechazado', app(DteTransmisionService::class)->transmitir($dte)['resultado']);
        $this->assertSame(EstadoDte::Rechazado, $dte->fresh()->estado);

        return $copiaVieja;
    }

    public function test_una_aceptacion_tardia_con_sello_prevalece_sobre_el_rechazo_del_mismo_codigo(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO']);

        $tardio = app(DteTransmisionService::class)->transmitir($copiaVieja);

        // Hacienda tiene la venta aceptada: el sistema no puede seguir tratándola como rechazada.
        $guardado = Dte::findOrFail($copiaVieja->id);
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertSame('SELLO-DE-PRUEBA-0002', $guardado->sello_recepcion);
        $this->assertSame('aceptado', $tardio['resultado']);
        $this->assertSame('SELLO-DE-PRUEBA-0002', $tardio['sello']);

        // Por la máquina de estados, con su renglón en el historial: Rechazado → Aceptado.
        $this->assertSame(1, $guardado->historial()->where('estado_anterior', 'rechazado')->where('estado_nuevo', 'aceptado')->count());
        $this->assertSame(1, $guardado->historial()->where('estado_nuevo', 'aceptado')->count());

        // El rechazo anterior no se pierde: queda en la respuesta, en disco y en la bitácora.
        $this->assertTrue($guardado->respuesta_mh['aceptacion_tardia']);
        $this->assertSame('aceptado', $guardado->respuesta_mh['resultado']);
        $this->assertSame('Código de generación ya existe', $guardado->respuesta_mh['rechazo_anterior']['descripcionMsg']);
        Storage::disk('local')->assertExists($guardado->respuesta_mh['rechazo_anterior']['archivo']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'dte_transmision',
            'subject_id' => $guardado->id,
            'description' => 'Llegó una aceptación tardía de Hacienda con sello y prevaleció sobre el rechazo que tenía el documento.',
        ]);
    }

    public function test_lo_que_cuelga_de_aceptado_ocurre_una_sola_vez(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO']);
        $otraCopiaVieja = Dte::findOrFail($copiaVieja->id);
        // El alta en Seguimiento de cobros la dispara el paso a Aceptado: una vez, ni cero ni dos.
        $this->mock(AltaCobrosService::class, fn ($alta) => $alta->shouldReceive('darDeAlta')->once());

        app(DteTransmisionService::class)->transmitir($copiaVieja);
        // Otra respuesta tardía del mismo documento ya no cambia nada.
        $segunda = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($otraCopiaVieja, [
            'resultado' => 'aceptado', 'http_status' => 200, 'mensaje' => 'RECIBIDO', 'sello' => 'SELLO-DE-PRUEBA-0002',
        ]);

        $this->assertSame('aceptado', $segunda['resultado']);
        $this->assertSame(1, Dte::findOrFail($copiaVieja->id)->historial()->where('estado_nuevo', 'aceptado')->count());
    }

    public function test_un_sello_tardio_de_otro_codigo_de_generacion_no_prevalece(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0003', 'descripcionMsg' => 'RECIBIDO',
            'codigoGeneracion' => strtoupper((string) Str::uuid()),
        ]);

        $tardio = app(DteTransmisionService::class)->transmitir($copiaVieja);

        $guardado = Dte::findOrFail($copiaVieja->id);
        $this->assertSame(EstadoDte::Rechazado, $guardado->estado);
        $this->assertNull($guardado->sello_recepcion);
        $this->assertSame('rechazado', $tardio['resultado']);
        // El sello ajeno no se pierde: queda en la bitácora para revisarlo.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'dte_transmision',
            'subject_id' => $guardado->id,
            'description' => 'Llegó una respuesta tardía de Hacienda y se conservó el estado que ya tenía el documento.',
        ]);
    }

    public function test_una_aceptacion_tardia_sin_sello_no_prevalece(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO']);

        app(DteTransmisionService::class)->transmitir($copiaVieja);

        $this->assertSame(EstadoDte::Rechazado, Dte::findOrFail($copiaVieja->id)->estado);
    }

    public function test_la_aceptacion_tardia_devuelve_a_la_operacion_un_rechazado_que_se_habia_archivado(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO']);
        Dte::whereKey($copiaVieja->id)->update(['archivado' => true, 'archivado_en' => now()]);

        app(DteTransmisionService::class)->transmitir($copiaVieja);

        // Un aceptado escondido entre los archivados no se cobraría ni se vería en el día a día.
        $guardado = Dte::findOrFail($copiaVieja->id);
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertFalse($guardado->estaArchivado());
        $this->assertTrue($guardado->respuesta_mh['rechazo_anterior']['estaba_archivado']);
    }

    public function test_la_ficha_avisa_que_se_acepto_despues_de_rechazado_para_revisar_un_posible_duplicado(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO']);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('facturacion.show', $copiaVieja))
            ->assertOk()->assertDontSee('después de que acá figuró como rechazado');

        app(DteTransmisionService::class)->transmitir($copiaVieja);

        $this->actingAs($usuario)->get(route('facturacion.show', $copiaVieja))
            ->assertOk()
            ->assertSee('Hacienda aceptó este documento después de que acá figuró como rechazado')
            ->assertSee('facturada dos veces');
    }

    public function test_si_guardar_la_aceptacion_tardia_falla_y_se_reintenta_el_archivo_del_rechazo_sigue_intacto(): void
    {
        $copiaVieja = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO']);
        $archivoDelRechazo = Dte::findOrFail($copiaVieja->id)->respuesta_mh_path;
        $rechazoOriginal = Storage::disk('local')->get($archivoDelRechazo);
        $this->assertStringContainsString('Código de generación ya existe', $rechazoOriginal);

        // La base falla DESPUÉS de haber guardado la respuesta: los archivos no se deshacen solos.
        $maquinaQueFalla = new class extends DteStateMachine
        {
            public function aceptarTrasRechazo(Dte $dte, ?User $usuario = null, ?string $comentario = null): Dte
            {
                throw new \RuntimeException('Falla simulada al cambiar el estado.');
            }
        };
        try {
            (new DteTransmisionService(app(DteTransmisionAuthService::class), $maquinaQueFalla))->transmitir($copiaVieja);
            $this->fail('La falla simulada tenía que cortar la aceptación.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falla simulada al cambiar el estado.', $e->getMessage());
        }

        // La base volvió atrás, y el archivo del rechazo no se tocó.
        $trasLaFalla = Dte::findOrFail($copiaVieja->id);
        $this->assertSame(EstadoDte::Rechazado, $trasLaFalla->estado);
        $this->assertNull($trasLaFalla->sello_recepcion);
        $this->assertSame($archivoDelRechazo, $trasLaFalla->respuesta_mh_path);
        $this->assertSame($rechazoOriginal, Storage::disk('local')->get($archivoDelRechazo));

        // El reintento sí entra, y el rechazo sigue ahí, byte por byte.
        app(DteTransmisionService::class)->aplicarResultadoDeConsulta(Dte::findOrFail($copiaVieja->id), [
            'resultado' => 'aceptado', 'http_status' => 200, 'mensaje' => 'RECIBIDO', 'sello' => 'SELLO-DE-PRUEBA-0002',
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO'],
        ]);

        $guardado = Dte::findOrFail($copiaVieja->id);
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertSame($archivoDelRechazo, $guardado->respuesta_mh['rechazo_anterior']['archivo']);
        $this->assertSame($rechazoOriginal, Storage::disk('local')->get($archivoDelRechazo));
        // La aceptación va en su propio archivo: nunca encima del rechazo.
        $this->assertNotSame($archivoDelRechazo, $guardado->respuesta_mh_path);
        $this->assertStringContainsString('SELLO-DE-PRUEBA-0002', Storage::disk('local')->get($guardado->respuesta_mh_path));
    }

    public function test_la_maquina_de_estados_solo_deja_salir_de_rechazado_con_un_sello(): void
    {
        $maquina = app(DteStateMachine::class);
        $firmado = $this->firmado();

        // Desde otro estado, no: la salida es solo para un Rechazado.
        try {
            $maquina->aceptarTrasRechazo($firmado);
            $this->fail('Solo un documento rechazado puede aceptarse por esta vía.');
        } catch (TransicionInvalidaException) {
        }

        $rechazado = $this->rechazadoConRespuestaTardia(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO'])->fresh();

        // Sin sello, tampoco.
        try {
            $maquina->aceptarTrasRechazo($rechazado);
            $this->fail('Sin sello de recepción no hay aceptación.');
        } catch (TransicionInvalidaException) {
        }

        // Y la vía general sigue cerrada: Rechazado no tiene estados siguientes.
        try {
            $maquina->transicionar($rechazado, EstadoDte::Aceptado);
            $this->fail('La transición general no puede sacar un documento de Rechazado.');
        } catch (TransicionInvalidaException) {
        }

        $this->assertSame(EstadoDte::Rechazado, $rechazado->fresh()->estado);
    }

    // ===================== 3) GENERAR CON UNA COPIA VIEJA NO CAMBIA NÚMERO NI CÓDIGO

    public function test_generar_dos_veces_el_mismo_borrador_no_cambia_numero_ni_codigo(): void
    {
        $dte = $this->borrador();
        // El segundo pedido cargó el borrador antes de que el primero lo generara.
        $copiaVieja = Dte::findOrFail($dte->id);

        $generacion = app(DteGeneracionService::class);
        $generacion->generar($dte);
        $antes = Dte::findOrFail($dte->id);

        try {
            $generacion->generar($copiaVieja);
            $this->fail('El segundo pedido no debió generar el documento otra vez.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('Solo se puede generar un documento en borrador', $e->getMessage());
        }

        $despues = Dte::findOrFail($dte->id);
        $this->assertSame(EstadoDte::Generado, $despues->estado);
        $this->assertSame($antes->numero_control, $despues->numero_control);
        $this->assertSame($antes->codigo_generacion, $despues->codigo_generacion);
        $this->assertSame($antes->numero_interno, $despues->numero_interno);
        $this->assertSame($antes->json_generado_path, $despues->json_generado_path);
        $this->assertSame(1, (int) Correlativo::first()->ultimo_numero);
    }
}
