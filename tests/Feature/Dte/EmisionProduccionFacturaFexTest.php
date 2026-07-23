<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Factura consumidor final (01) y Factura de exportación (11) quedaron habilitadas
 * OPERATIVAMENTE al mismo nivel que CCF: preflight específico integrado en
 * "Generar y transmitir producción", guardia de frase EMITIR PRODUCCION, y los
 * mismos guards generales de estado/ambiente/credenciales que ya protegían a CCF.
 * Esta suite prueba la integración nueva SIN transmitir nunca a Hacienda de verdad
 * (Http::fake() en todos los casos que podrían llegar a intentar HTTP).
 */
class EmisionProduccionFacturaFexTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function correlativo(string $tipo, string $ambiente): void
    {
        Correlativo::create([
            'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => $ambiente, 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /**
     * @param  string  $ambiente  Ambiente del DOCUMENTO (independiente del ambiente del
     *                            SISTEMA en config('dte.ambiente')). Default '01': casos
     *                            que representan un candidato real a producción.
     */
    private function facturaGenerada(string $ambiente = '01'): Dte
    {
        $this->correlativo('01', $ambiente);
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => $ambiente,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function fexGenerada(string $ambiente = '01'): Dte
    {
        $this->correlativo('11', $ambiente);
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => $ambiente,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    // --- Preflight específico integrado (no CCF genérico) ---

    public function test_generar_transmitir_produccion_factura_usa_preflight_de_factura_y_bloquea_si_falta_info(): void
    {
        Http::fake();
        // Ambiente NO productivo a propósito: el preflight de Factura corta por
        // "Ambiente producción (01) activo", igual que el de CCF cortaría para un CCF.
        config()->set('dte.ambiente', '00');
        $factura = $this->facturaGenerada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.generar-transmitir-produccion', $factura), [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertStringContainsString('Emisión a producción bloqueada por el preflight', $error);
        Http::assertNothingSent();
        $factura->refresh();
        $this->assertSame(EstadoDte::Generado, $factura->estado);
        $this->assertNull($factura->sello_recepcion);
    }

    public function test_generar_transmitir_produccion_fex_usa_preflight_de_fex_y_bloquea_si_falta_info(): void
    {
        Http::fake();
        config()->set('dte.ambiente', '00');
        $fex = $this->fexGenerada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.generar-transmitir-produccion', $fex), [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertStringContainsString('Emisión a producción bloqueada por el preflight', $error);
        Http::assertNothingSent();
        $fex->refresh();
        $this->assertSame(EstadoDte::Generado, $fex->estado);
        $this->assertNull($fex->sello_recepcion);
    }

    // --- Frase EMITIR PRODUCCION obligatoria en el flujo de preparación real ---

    public function test_generar_transmitir_produccion_sin_frase_exacta_bloquea_factura_y_fex(): void
    {
        Http::fake();
        // NOTA: con dte.ambiente=00 el preflight ya bloquea antes de llegar a la frase;
        // este test confirma la validación de la CASILLA de confirmación (paso previo
        // a la frase), que tampoco depende del tipo de documento.
        config()->set('dte.ambiente', '00');
        $factura = $this->facturaGenerada();
        $fex = $this->fexGenerada();

        foreach ([$factura, $fex] as $dte) {
            $this->actingAs($this->usuario('administrador'))
                ->post(route('facturacion.generar-transmitir-produccion', $dte), [])
                ->assertSessionHas('error');
        }

        Http::assertNothingSent();
    }

    // --- Guard general de ESTADO (Policy), igual para los tres tipos ---

    public function test_no_se_firma_una_factura_rechazada_ni_una_fex_rechazada(): void
    {
        $factura = $this->facturaGenerada();
        $factura->forceFill(['estado' => EstadoDte::Rechazado])->save();
        $fex = $this->fexGenerada();
        $fex->forceFill(['estado' => EstadoDte::Rechazado])->save();

        $admin = $this->usuario('administrador');
        $this->assertFalse($admin->can('firmarTransmitir', $factura));
        $this->assertFalse($admin->can('firmarTransmitir', $fex));
    }

    public function test_no_se_retransmite_una_factura_ni_una_fex_ya_aceptadas(): void
    {
        $factura = $this->facturaGenerada();
        $factura->forceFill(['estado' => EstadoDte::Aceptado, 'sello_recepcion' => 'SELLO-FAKE-001'])->save();
        $fex = $this->fexGenerada();
        $fex->forceFill(['estado' => EstadoDte::Aceptado, 'sello_recepcion' => 'SELLO-FAKE-002'])->save();

        $admin = $this->usuario('administrador');
        $this->assertFalse($admin->can('firmarTransmitir', $factura));
        $this->assertFalse($admin->can('firmarTransmitir', $fex));
        $this->assertFalse($admin->can('generarTransmitirProduccion', $factura));
        $this->assertFalse($admin->can('generarTransmitirProduccion', $fex));

        // Refuerzo en runtime: la Policy ya deniega el acceso a la ruta (403) antes de
        // que el controlador llegue siquiera a su propia idempotencia dura interna.
        Http::fake();
        $this->actingAs($admin)
            ->post(route('facturacion.firmar-transmitir', $factura), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_no_se_transmite_factura_ni_fex_si_no_estan_firmadas(): void
    {
        // estado=Generado (sin JWS): dte:transmitir debe rechazar antes de cualquier HTTP.
        Http::fake();
        $factura = $this->facturaGenerada();
        $fex = $this->fexGenerada();

        $this->artisan('dte:transmitir', ['dte' => $factura->id])->assertExitCode(1);
        $this->artisan('dte:transmitir', ['dte' => $fex->id])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull($factura->refresh()->sello_recepcion);
        $this->assertNull($fex->refresh()->sello_recepcion);
    }

    // --- Documento ambiente 00 no puede ir a producción (guard general, no por tipo) ---

    public function test_documento_ambiente_00_no_transmite_aunque_este_firmado(): void
    {
        Http::fake();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.enabled', true);

        $factura = $this->facturaGenerada('00');
        $factura->forceFill(['estado' => EstadoDte::Firmado, 'json_firmado_path' => 'dte/firmados/fake.jws'])->save();

        // ambiente del DOCUMENTO sigue en "00" (testing): el candado general de
        // transmisión real exige ambiente=01 del sistema Y candados coherentes; no es
        // un guard especial de Factura, es el mismo que ya protegía a CCF.
        $this->assertSame('00', $factura->ambiente->value);

        $this->artisan('dte:transmitir', ['dte' => $factura->id]);

        // El resultado exacto depende de los candados (bloqueado o transitorio), pero en
        // NINGÚN caso debe quedar con sello (no se aceptó nada real).
        $this->assertNull($factura->refresh()->sello_recepcion);
    }

    /**
     * Refuerzo encontrado en la auditoría de preparación productiva: ni la Policy
     * original ni el preflight de tipo miraban el ambiente del DOCUMENTO (solo el
     * ambiente del SISTEMA vía config('dte.ambiente')). Un documento de PRUEBAS podía
     * en teoría llegar a la pantalla/acción "Generar y transmitir producción" mientras
     * el sistema está en modo producción. Se agregó un check explícito en
     * DtePolicy::generarTransmitirProduccion — este test confirma que, con el sistema
     * en ambiente 01 (producción activa), un documento ambiente 00 NUNCA es candidato.
     */
    public function test_documento_ambiente_00_nunca_es_candidato_productivo_aunque_el_sistema_este_en_produccion(): void
    {
        config()->set('dte.ambiente', '01'); // sistema en modo producción
        $factura = $this->facturaGenerada('00');
        $fex = $this->fexGenerada('00');
        $admin = $this->usuario('administrador');

        $this->assertFalse($admin->can('generarTransmitirProduccion', $factura));
        $this->assertFalse($admin->can('generarTransmitirProduccion', $fex));

        Http::fake();
        $this->actingAs($admin)
            ->post(route('facturacion.generar-transmitir-produccion', $factura), [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])->assertForbidden();
        $this->actingAs($admin)
            ->post(route('facturacion.generar-transmitir-produccion', $fex), [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * Aislado: la ÚNICA condición que falta es el correlativo productivo (todo lo demás
     * en verde). El check debe bloquear con un mensaje CLARO y comprensible, nunca un
     * error 500 / excepción sin manejar.
     */
    public function test_preflight_bloquea_con_mensaje_claro_cuando_falta_el_correlativo_productivo(): void
    {
        config([
            'dte.ambiente' => '01',
            'dte.transmision.enabled' => true,
            'dte.transmision.mock' => true,
            'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false,
            'dte.transmision.allow_production' => true,
            'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'respaldo',
            'dte.transmision.ambiente' => 'produccion',
        ]);
        \App\Models\Configuracion::set('produccion.auth_prod_validada', true);
        \App\Models\Configuracion::set('correo.auto_envio', false);
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        Http::fake([rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200)]);

        // Correlativo tipo 01/11 productivo (ambiente 01) deliberadamente AUSENTE:
        // no se crea ninguna fila para esa combinación (única condición roja esperada).
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => '01',
        ]);
        $this->correlativo('01', '00'); // fila de pruebas irrelevante, solo para no chocar con otros checks
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        // Documento se queda en BORRADOR: el controlador lo genera (consume correlativo
        // tipo 01) recién en el paso 4, DESPUÉS del preflight — el preflight en sí evalúa
        // sobre el borrador (aún sin numero_control), leyendo directo el correlativo 01/01.

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)
            ->post(route('facturacion.generar-transmitir-produccion', $dte), [
                'barrera_conta' => '1', 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ])
            ->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertStringContainsString('Correlativo Factura producción (P002) existe', $error);
        // La única llamada HTTP posible es el health-check GET del firmador (parte del
        // preflight); nunca un POST de firma o de recepción a Hacienda.
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/status'));
        Http::assertSentCount(1);
        $dte->refresh();
        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertNull($dte->numero_control);
    }
}
