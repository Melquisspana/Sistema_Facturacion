<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_una_aceptacion_tardia_sobre_un_documento_rechazado_no_cambia_el_estado_y_queda_anotada(): void
    {
        $this->habilitar();
        $dte = $this->firmado();
        $copiaVieja = Dte::findOrFail($dte->id);

        Http::fakeSequence('*')
            ->push(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Código de generación ya existe', 'observaciones' => []], 400)
            ->push(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-0002', 'descripcionMsg' => 'RECIBIDO'], 200);

        $transmision = app(DteTransmisionService::class);
        $this->assertSame('rechazado', $transmision->transmitir($dte)['resultado']);

        $tardio = $transmision->transmitir($copiaVieja);

        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Rechazado, $guardado->estado);
        $this->assertNull($guardado->sello_recepcion);
        // No se inventa una transición que la máquina de estados no permite, pero el sello
        // que mandó Hacienda no se pierde: queda en la bitácora y en el aviso a la persona.
        $this->assertSame('rechazado', $tardio['resultado']);
        $this->assertStringContainsString('SELLO-DE-PRUEBA-0002', $tardio['mensaje']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'dte_transmision',
            'subject_id' => $dte->id,
            'description' => 'Llegó una respuesta tardía de Hacienda y se conservó el estado que ya tenía el documento.',
        ]);
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
