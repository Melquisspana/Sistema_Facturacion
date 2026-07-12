<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\PreflightEmisionProduccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Acción "Generar y transmitir producción" (controlador). Orquestación con el
 * preflight y los servicios en MODO MOCK (no HTTP, no firmador real, no Hacienda).
 * Verifica el gate del preflight, la barrera, la frase, el orden y que NO envía correo.
 */
class GenerarTransmitirProduccionTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Modo MOCK: firma y transmisión simuladas (sin firmador real ni HTTP a Hacienda).
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => true, 'dte.transmision.mock' => true]);
        Mail::fake();
        // El JSON generado debe existir en disco para que la firma (aun mock) pase sus precondiciones.
        Storage::fake('local');
        Storage::disk('local')->put('dte/json/x.json', '{"identificacion":{"numeroControl":"DTE-03-M001P001-000000000001094"}}');

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Preflight verde (mockeado) para aislar la orquestación del controlador. */
    private function preflightVerde(): void
    {
        $pf = Mockery::mock(PreflightEmisionProduccion::class);
        $pf->shouldReceive('evaluar')->andReturn(['puede' => true, 'checks' => [], 'faltantes' => []]);
        $pf->shouldReceive('resumen')->andReturn(['proximo_numero' => 1094]);
        $this->app->instance(PreflightEmisionProduccion::class, $pf);
    }

    private function preflightBloqueado(): void
    {
        $pf = Mockery::mock(PreflightEmisionProduccion::class);
        $pf->shouldReceive('evaluar')->andReturn(['puede' => false, 'checks' => [], 'faltantes' => ['Worker/cola activo']]);
        $pf->shouldReceive('resumen')->andReturn([]);
        $this->app->instance(PreflightEmisionProduccion::class, $pf);
    }

    private function ccf(string $estado = 'generado', array $extra = []): Dte
    {
        $dte = Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => PuntoVenta::first()->id,
            'tipo_dte' => '03', 'estado' => $estado, 'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-000000000001094',
            'codigo_generacion' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'fecha_emision' => now(), 'hora_emision' => '10:00:00',
            'total_gravado' => 100, 'iva' => 13, 'total_pagar' => 113,
        ]);
        // json_generado_path no es mass-assignable (lo setean los servicios): forceFill.
        if (! array_key_exists('json_generado_path', $extra)) {
            $dte->forceFill(['json_generado_path' => 'dte/json/x.json'])->save();
        }

        return $dte;
    }

    private function emitir(Dte $dte, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())
            ->post(route('facturacion.generar-transmitir-produccion', $dte), $data + [
                'barrera_conta' => 1, 'confirmacion_emision' => 'EMITIR PRODUCCION',
            ]);
    }

    // ---------- gates ----------

    public function test_bloquea_si_preflight_falla(): void
    {
        $this->preflightBloqueado();
        $ccf = $this->ccf('generado');

        $this->emitir($ccf)->assertSessionHas('error');

        $this->assertSame('generado', $ccf->fresh()->estado->value); // no cambió
        Mail::assertNothingSent();
    }

    public function test_bloquea_si_falta_frase_exacta(): void
    {
        $this->preflightVerde();
        $ccf = $this->ccf('generado');

        $this->emitir($ccf, ['confirmacion_emision' => 'emitir'])->assertSessionHas('error');

        $this->assertSame('generado', $ccf->fresh()->estado->value);
        Mail::assertNothingSent();
    }

    public function test_bloquea_si_barrera_no_marcada(): void
    {
        $this->preflightVerde();
        $ccf = $this->ccf('generado');

        $this->actingAs($this->admin())
            ->post(route('facturacion.generar-transmitir-produccion', $ccf), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertSessionHas('error');

        $this->assertSame('generado', $ccf->fresh()->estado->value);
    }

    // ---------- flujo ----------

    public function test_desde_generado_firma_y_transmite_y_no_envia_correo(): void
    {
        $this->preflightVerde();
        $ccf = $this->ccf('generado');

        $this->emitir($ccf)->assertRedirect(route('facturacion.show', $ccf));

        $ccf->refresh();
        $this->assertSame('aceptado', $ccf->estado->value);       // firmó + transmitió (mock)
        $this->assertNotEmpty($ccf->sello_recepcion);             // sello mock
        Mail::assertNothingSent();                                // NO envía correo
    }

    public function test_desde_borrador_genera_luego_firma_y_transmite(): void
    {
        $this->preflightVerde();
        // Mock del generador: transiciona a Generado (sin correr el schema real).
        $gen = Mockery::mock(DteGeneracionService::class);
        $gen->shouldReceive('generar')->once()->andReturnUsing(function (Dte $dte) {
            $dte->forceFill([
                'estado' => 'generado',
                'numero_control' => 'DTE-03-M001P001-000000000001094',
                'codigo_generacion' => (string) Str::uuid(),
                'json_generado_path' => 'dte/json/x.json',
            ])->save();

            return $dte;
        });
        $this->app->instance(DteGeneracionService::class, $gen);

        $ccf = $this->ccf('borrador', ['numero_control' => null, 'codigo_generacion' => null, 'json_generado_path' => null]);

        $this->emitir($ccf)->assertRedirect();

        $this->assertSame('aceptado', $ccf->fresh()->estado->value); // generó → firmó → transmitió
    }

    public function test_desde_generado_no_vuelve_a_generar(): void
    {
        $this->preflightVerde();
        $ccf = $this->ccf('generado');
        $numeroAntes = $ccf->numero_control;
        $codigoAntes = $ccf->codigo_generacion;

        $this->emitir($ccf)->assertRedirect();

        $ccf->refresh();
        // Aceptó, pero NO reasignó numeración: generar() no se ejecutó estando Generado.
        $this->assertSame('aceptado', $ccf->estado->value);
        $this->assertSame($numeroAntes, $ccf->numero_control);
        $this->assertSame($codigoAntes, $ccf->codigo_generacion);
    }

    public function test_aceptado_no_retransmite_bloqueado_por_policy(): void
    {
        $this->preflightVerde();
        // Un CCF ya aceptado (con sello) no pasa la policy → 403, no retransmite.
        $ccf = $this->ccf('aceptado', ['sello_recepcion' => '2026SELLOREAL']);

        $this->emitir($ccf)->assertForbidden();

        $this->assertSame('aceptado', $ccf->fresh()->estado->value);
        Mail::assertNothingSent();
    }

    public function test_no_toca_otro_ccf_1078(): void
    {
        $this->preflightVerde();
        $ccf1078 = $this->ccf('aceptado', [
            'numero_control' => 'DTE-03-M001P001-000000000001078', 'sello_recepcion' => '202697ABB5D1REAL',
        ]);
        $objetivo = $this->ccf('generado');

        $this->emitir($objetivo)->assertRedirect();

        $ccf1078->refresh();
        $this->assertSame('aceptado', $ccf1078->estado->value);
        $this->assertSame('202697ABB5D1REAL', $ccf1078->sello_recepcion);
        $this->assertSame('DTE-03-M001P001-000000000001078', $ccf1078->numero_control);
    }

    public function test_consulta_no_puede(): void
    {
        $this->preflightVerde();
        $ccf = $this->ccf('generado');

        $this->actingAs(User::factory()->create()->assignRole('consulta'))
            ->post(route('facturacion.generar-transmitir-produccion', $ccf), ['barrera_conta' => 1, 'confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertForbidden();
    }
}
