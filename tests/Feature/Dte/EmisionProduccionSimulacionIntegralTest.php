<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Jobs\EnviarDteCorreo;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\PreflightEmisionProduccionExportacion;
use App\Services\Dte\PreflightEmisionProduccionFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Simulación INTEGRAL, aislada en BD de tests con dobles/fakes (sin BD real, sin
 * credenciales, sin conexión real a Hacienda), del ciclo de vida completo de Factura
 * consumidor final (01) y Factura de exportación (11) en producción: correlativo
 * productivo desde CERO (ultimo_numero=0) → borrador → generado (recibe el número 1)
 * → preflight específico REAL (no mockeado) → frase EMITIR PRODUCCION obligatoria →
 * firmado (firmador mock) → transmitido (mock, "aceptado" simulado) → sello guardado
 * → PDF oficial disponible → correo SOLO bajo acción explícita (nunca automático) →
 * idempotencia. Confirma además que CCF y Nota de Crédito no se vieron afectados.
 *
 * Mismo patrón de aislamiento que tests/Feature/Dte/GenerarTransmitirProduccionTest.php
 * (CCF): dte.firma.mock + dte.transmision.mock evitan TODO HTTP real por diseño;
 * Http::fake() queda además como respaldo y se verifica con Http::assertNothingSent().
 */
class EmisionProduccionSimulacionIntegralTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();

        // Firmador y transmisión en modo MOCK: sin firmador Java real, sin HTTP a
        // Hacienda, sin credenciales. Sistema en ambiente de producción (para que la
        // Policy y el preflight específico evalúen el caso real que se quiere probar).
        // El resto de checks de infraestructura del preflight (worker/backup/candados/
        // credenciales validadas/correo no automático) se dejan en VERDE a propósito:
        // mismo patrón que tests/Feature/Dte/DtePreflightComandosTest.php::todoVerdeComun().
        config([
            'dte.ambiente' => '01',
            'dte.firma.enabled' => true,
            'dte.firma.mock' => true,
            'dte.transmision.enabled' => true,
            'dte.transmision.mock' => true,
            'dte.transmision.test_enabled' => false,
            'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false,
            'dte.transmision.allow_production' => true,
            'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'respaldo',
            'dte.transmision.ambiente' => 'produccion',
        ]);
        Configuracion::set('correo.auto_envio', false);
        Configuracion::set('produccion.auth_prod_validada', true);
        // WorkerHeartbeat::pulse() tiene un throttle de 15s DENTRO DEL PROCESO (propiedad
        // estática, no ligada al contenedor por test): sin olvidar() primero, el segundo
        // test que corre dentro de esos 15s heredaría el throttle del anterior y NO
        // escribiría en la cache (fresca) de ESTE test, dejando el check "worker" en rojo.
        \App\Support\WorkerHeartbeat::olvidar();
        \App\Support\WorkerHeartbeat::pulse();
        $nombreBackup = (string) config('backup.backup.name', config('app.name'));
        Storage::disk('local')->put($nombreBackup.'/hoy.zip', 'x');

        Http::fake(); // respaldo: si algo intentara HTTP real, queda interceptado (no debería pasar)
        Mail::fake();
        Queue::fake();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function correlativoProductivoEnCero(string $tipo): void
    {
        Correlativo::create([
            'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    private function facturaBorrador(): Dte
    {
        $this->correlativoProductivoEnCero('01');
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => '01',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 11.30, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    private function fexBorrador(): Dte
    {
        $this->correlativoProductivoEnCero('11');
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => '01',
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return $dte->refresh();
    }

    private function emitir(User $admin, Dte $dte, array $data = []): TestResponse
    {
        return $this->actingAs($admin)
            ->post(route('facturacion.generar-transmitir-produccion', $dte), $data + [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ]);
    }

    /** Recorre el ciclo completo y devuelve el DTE final (aceptado). Comparte los pasos 1-10 de ambos tipos. */
    private function correrCicloCompleto(Dte $dte, User $admin, string $tipoDte): Dte
    {
        $this->assertSame(EstadoDte::Borrador, $dte->estado);

        // 5. Preflight específico REAL (no mockeado): el correlativo recién creado (en
        // cero) ya figura como "existe"; el resto puede estar rojo (worker/backup/etc.),
        // eso no es lo que se está probando aquí.
        $preflight = $tipoDte === '01'
            ? app(PreflightEmisionProduccionFactura::class)->evaluar($dte)
            : app(PreflightEmisionProduccionExportacion::class)->evaluar($dte);
        $labels = array_column($preflight['checks'], 'label');
        $this->assertContains(
            $tipoDte === '01' ? 'Correlativo Factura producción existe' : 'Correlativo Exportación producción existe',
            $labels
        );

        // 6. Sin frase: no firma, no transmite, no encola correo.
        $this->emitir($admin, $dte, ['confirmacion_emision' => ''])->assertSessionHas('error');
        $this->assertSame(EstadoDte::Borrador, $dte->fresh()->estado);
        Queue::assertNothingPushed();

        // 7. Frase incorrecta: bloquea igual.
        $this->emitir($admin, $dte, ['confirmacion_emision' => 'emitir produccion'])->assertSessionHas('error');
        $this->assertSame(EstadoDte::Borrador, $dte->fresh()->estado);
        Queue::assertNothingPushed();

        // 8-9-10. Frase exacta: genera (correlativo → 1), firma (mock) y transmite
        // (mock, respuesta "aceptada" simulada) en una sola acción.
        $this->emitir($admin, $dte)->assertRedirect(route('facturacion.show', $dte));

        $dte->refresh();
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertStringEndsWith('000000000000001', (string) $dte->numero_control); // primer número real: 1
        $this->assertNotEmpty($dte->sello_recepcion);
        $this->assertStringStartsWith('MOCK-SIMULADO-', $dte->sello_recepcion); // sello de prueba, claramente marcado
        $this->assertNotNull($dte->json_firmado_path);

        // PDF oficial disponible (solo lectura).
        $this->actingAs($admin)->get(route('facturacion.pdf', $dte))->assertOk();

        // Correo: NO se encoló automáticamente al aceptar.
        Queue::assertNothingPushed();

        return $dte;
    }

    /**
     * El único tráfico HTTP permitido en TODO este archivo es el health-check GET
     * (/status) del firmador que hacen los preflights — nunca un POST de firma real
     * ni de recepción a Hacienda. Http::fake() intercepta cualquiera de los dos (no
     * llegarían a una URL real de todos modos), pero esta aserción confirma que el
     * único tipo de tráfico que efectivamente ocurrió fue el health-check de lectura.
     */
    private function assertSoloHealthChecksDelFirmador(): void
    {
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/status'));
        // Nunca un POST: ni firma real al firmador ni recepción real a Hacienda.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    // ---------- Factura consumidor final (01) ----------

    public function test_factura_ciclo_completo_produccion_desde_correlativo_cero(): void
    {
        $admin = $this->admin();
        $dte = $this->correrCicloCompleto($this->facturaBorrador(), $admin, '01');

        // Correo SOLO bajo acción explícita.
        $this->actingAs($admin)
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'cliente@example.com'])
            ->assertRedirect();
        Queue::assertPushed(EnviarDteCorreo::class);

        // Idempotencia: reintentar "emitir" sobre un documento YA aceptado lo bloquea la
        // Policy (403) antes de tocar nada — mismo criterio que ya protege a CCF
        // (GenerarTransmitirProduccionTest::test_aceptado_no_retransmite_bloqueado_por_policy).
        // No retransmite, no reconsume correlativo, no cambia el JWS.
        $numeroControlAntes = $dte->numero_control;
        $selloAntes = $dte->sello_recepcion;
        $jwsAntes = $dte->json_firmado_path;

        $this->emitir($admin, $dte)->assertForbidden();

        $dte->refresh();
        $this->assertSame($numeroControlAntes, $dte->numero_control);
        $this->assertSame($selloAntes, $dte->sello_recepcion);
        $this->assertSame($jwsAntes, $dte->json_firmado_path);
        $this->assertSame(1, (int) Correlativo::where('tipo_dte', '01')->where('ambiente', '01')->value('ultimo_numero'));

        $this->assertSoloHealthChecksDelFirmador();
    }

    // ---------- Factura de exportación (11) ----------

    public function test_fex_ciclo_completo_produccion_desde_correlativo_cero(): void
    {
        $admin = $this->admin();
        $dte = $this->correrCicloCompleto($this->fexBorrador(), $admin, '11');

        $this->actingAs($admin)
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'cliente@example.com'])
            ->assertRedirect();
        Queue::assertPushed(EnviarDteCorreo::class);

        $numeroControlAntes = $dte->numero_control;
        $selloAntes = $dte->sello_recepcion;
        $jwsAntes = $dte->json_firmado_path;

        $this->emitir($admin, $dte)->assertForbidden();

        $dte->refresh();
        $this->assertSame($numeroControlAntes, $dte->numero_control);
        $this->assertSame($selloAntes, $dte->sello_recepcion);
        $this->assertSame($jwsAntes, $dte->json_firmado_path);
        $this->assertSame(1, (int) Correlativo::where('tipo_dte', '11')->where('ambiente', '01')->value('ultimo_numero'));

        $this->assertSoloHealthChecksDelFirmador();
    }

    // ---------- Idempotencia dura: un aceptado nunca se retransmite por consola tampoco ----------

    public function test_factura_y_fex_aceptadas_no_se_retransmiten_por_consola(): void
    {
        $admin = $this->admin();
        $factura = $this->correrCicloCompleto($this->facturaBorrador(), $admin, '01');
        $fex = $this->correrCicloCompleto($this->fexBorrador(), $admin, '11');

        $this->artisan('dte:transmitir', ['dte' => $factura->id])->assertExitCode(1);
        $this->artisan('dte:transmitir', ['dte' => $fex->id])->assertExitCode(1);

        $this->assertSame(1, (int) Correlativo::where('tipo_dte', '01')->where('ambiente', '01')->value('ultimo_numero'));
        $this->assertSame(1, (int) Correlativo::where('tipo_dte', '11')->where('ambiente', '01')->value('ultimo_numero'));
        $this->assertSoloHealthChecksDelFirmador();
    }

    // ---------- CCF y Nota de Crédito: no afectados por esta habilitación ----------

    public function test_ccf_sigue_funcionando_igual_tras_la_habilitacion(): void
    {
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 1120, 'activo' => true,
        ]);
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => '01',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $admin = $this->admin();
        $this->assertTrue($admin->can('generarTransmitirProduccion', $dte));

        $this->emitir($admin, $dte)->assertRedirect(route('facturacion.show', $dte));

        $dte->refresh();
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertStringEndsWith('000000000001121', (string) $dte->numero_control); // continúa 1120 → 1121
        $this->assertSoloHealthChecksDelFirmador();
    }

    public function test_nota_credito_no_pasa_por_el_flujo_de_produccion_real_de_factura_fex(): void
    {
        // La Nota de Crédito NO está en TIPOS_EMISION_PRODUCCION: sigue su propio
        // flujo (firmarTransmitir genérico), no "Generar y transmitir producción".
        Correlativo::create([
            'tipo_dte' => '05', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 0, 'activo' => true,
        ]);
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = Dte::create([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'tipo_nota_credito' => 'devolucion_producto',
            'estado' => EstadoDte::Borrador->value,
            'ambiente' => '01',
            'fecha_emision' => now(),
            'hora_emision' => '10:00:00',
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'cliente_id' => $cliente->id,
        ]);
        $admin = $this->admin();

        $this->assertFalse($admin->can('generarTransmitirProduccion', $dte));
    }
}
