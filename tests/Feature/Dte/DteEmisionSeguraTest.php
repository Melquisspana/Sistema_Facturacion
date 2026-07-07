<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Refuerzo del MODO PARALELO SEGURO: el sistema nuevo no debe emitir real por accidente.
 *  - El banner/aviso deja claro que NO emite producción.
 *  - `firmarTransmitir` exige la frase EXACTA "EMITIR PRODUCCION" SOLO cuando una emisión
 *    real a producción es posible (candados abiertos + ambiente producción). En modo seguro
 *    la frase NO se pide (no estorba). El servidor RE-VALIDA (no depende del JS).
 *
 * No transmite ni firma real: los casos que pasan la guardia usan mocks y/o fallan aguas
 * abajo por precondiciones, sin tocar Hacienda.
 */
class DteEmisionSeguraTest extends TestCase
{
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

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
    }

    private function gestor(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    /** CCF en estado GENERADO (lo mínimo que la policy exige para firmarTransmitir). Sin líneas
     *  ni JSON: si la guardia lo deja pasar, falla aguas abajo sin firmar/transmitir de verdad. */
    private function ccfGenerado(): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_dte' => TipoDte::CreditoFiscal,
            'estado' => EstadoDte::Generado,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'numero_control' => 'DTE-03-M001P001-000000000009001',
            'codigo_generacion' => (string) \Illuminate\Support\Str::uuid(),
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 10.0,
        ]);
    }

    /** Abre TODOS los candados a producción: emisión real posible AHORA. Los mocks quedan en
     *  true para que, si la guardia deja pasar, NO se toque Hacienda (firma/transmisión simuladas). */
    private function abrirProduccionConMocks(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.test_enabled', false);
        // Si la guardia deja pasar, que la firma/transmisión sean simuladas (sin red).
        config()->set('dte.firma.mock', true);
        config()->set('dte.transmision.mock', true);
    }

    // --- Banner / aviso de modo seguro ---

    public function test_ficha_muestra_modo_seguro_y_no_emite_produccion(): void
    {
        // Config por defecto = PARALELO SEGURO.
        $this->actingAs($this->gestor())
            ->get(route('facturacion.show', $this->ccfGenerado()))
            ->assertOk()
            ->assertSee('MODO PARALELO SEGURO')
            ->assertSee('NO EMITE PRODUCCIÓN');
    }

    // --- Guardia de la frase EMITIR PRODUCCION ---

    public function test_emision_real_sin_frase_se_bloquea_y_no_cambia_estado(): void
    {
        $this->abrirProduccionConMocks();
        $dte = $this->ccfGenerado();

        $this->actingAs($this->gestor())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertStringContainsString('Emisión a PRODUCCIÓN bloqueada', (string) session('error'));
        $this->assertSame(EstadoDte::Generado, $dte->fresh()->estado); // intacto: no firmó ni transmitió
        $this->assertNull($dte->fresh()->sello_recepcion);
    }

    public function test_emision_real_con_frase_incorrecta_se_bloquea(): void
    {
        $this->abrirProduccionConMocks();
        $dte = $this->ccfGenerado();

        $this->actingAs($this->gestor())
            ->post(route('facturacion.firmar-transmitir', $dte), ['confirmacion_emision' => 'emitir produccion'])
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertStringContainsString('Emisión a PRODUCCIÓN bloqueada', (string) session('error'));
        $this->assertSame(EstadoDte::Generado, $dte->fresh()->estado);
    }

    public function test_emision_real_con_frase_exacta_pasa_la_guardia(): void
    {
        $this->abrirProduccionConMocks();
        $dte = $this->ccfGenerado();

        $this->actingAs($this->gestor())
            ->post(route('facturacion.firmar-transmitir', $dte), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertRedirect(route('facturacion.show', $dte));

        // La guardia NO bloqueó (pasó la frase). Lo que ocurra después es aguas abajo, pero
        // nunca el mensaje de bloqueo por falta de frase.
        $this->assertStringNotContainsString('Emisión a PRODUCCIÓN bloqueada', (string) session('error'));
    }

    public function test_modo_seguro_no_exige_frase(): void
    {
        // Config por defecto = PARALELO SEGURO: la guardia de frase NO aplica.
        config()->set('dte.firma.mock', true);
        config()->set('dte.transmision.mock', true);
        $dte = $this->ccfGenerado();

        $this->actingAs($this->gestor())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        // No se pide la frase en modo seguro (nunca aparece el bloqueo de producción).
        $this->assertStringNotContainsString('Emisión a PRODUCCIÓN bloqueada', (string) session('error'));
    }
}
