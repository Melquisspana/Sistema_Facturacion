<?php

namespace Tests\Feature\Dte;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pantalla "Invalidaciones": lista de documentos ACEPTADOS que se pueden invalidar,
 * con acción hacia la ficha. SOLO LECTURA: no invalida ni transmite nada.
 */
class InvalidacionesUiTest extends TestCase
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
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function dte(string $estado, string $numero, string $tipo = '03', ?string $sello = '2026SELLOREAL0001'): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => PuntoVenta::first()->id,
            'tipo_dte' => $tipo, 'estado' => $estado, 'ambiente' => '01',
            'numero_control' => $numero, 'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => $sello, 'fecha_emision' => now(), 'hora_emision' => '10:00:00',
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.'])->id,
            'total_pagar' => 182.28,
        ]);
    }

    public function test_lista_muestra_aceptado_con_accion_invalidar(): void
    {
        $ccf = $this->dte('aceptado', 'DTE-03-M001P001-000000000001094');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.invalidaciones'))
            ->assertOk()
            ->assertSee('Invalidar')
            ->assertSee('DTE-03-M001P001-000000000001094')
            ->assertSee('Calleja, S.A. de C.V.')
            // Acción hacia la ficha (donde vive el panel de invalidación).
            ->assertSee(route('facturacion.show', $ccf), false)
            ->assertSee('Invalidar');
    }

    public function test_no_lista_borradores_ni_sin_sello(): void
    {
        $this->dte('aceptado', 'DTE-03-M001P001-000000000001094'); // sí (aceptado con sello)
        $this->dte('borrador', 'INT-03-1', '03', null);            // no (borrador, sin sello)
        $this->dte('generado', 'INT-03-2', '03', null);            // no (generado, sin sello)

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.invalidaciones'))->assertOk()->getContent();

        $this->assertStringContainsString('DTE-03-M001P001-000000000001094', $html);
        $this->assertStringNotContainsString('INT-03-1', $html);
        $this->assertStringNotContainsString('INT-03-2', $html);
    }

    public function test_filtra_por_numero(): void
    {
        $this->dte('aceptado', 'DTE-03-M001P001-000000000001078');
        $this->dte('aceptado', 'DTE-03-M001P001-000000000001094');

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.invalidaciones', ['q' => '1094']))->assertOk()->getContent();

        $this->assertStringContainsString('000000000001094', $html);
        $this->assertStringNotContainsString('000000000001078', $html);
    }

    public function test_pantalla_no_invalida_nada(): void
    {
        $ccf = $this->dte('aceptado', 'DTE-03-M001P001-000000000001094');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.invalidaciones'))->assertOk();

        // El documento sigue aceptado: la pantalla es solo lectura.
        $this->assertSame('aceptado', $ccf->fresh()->estado->value);
    }
}
