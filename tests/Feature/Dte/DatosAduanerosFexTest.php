<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\CatalogoMh;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Sección A del ticket "FEX #131": datos aduaneros editables en borrador,
 * bloqueados fuera de borrador, y San Bartolo como recinto fiscal por defecto
 * de una FEX nueva (manual o desde Lista de Empaque). NO toca la FEX #131 real:
 * usa exclusivamente la base de datos de pruebas (sqlite en memoria).
 */
class DatosAduanerosFexTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function fexBorrador(): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $cliente = Cliente::factory()->exportacion()->create();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
    }

    private function datosValidos(): array
    {
        // Recinto/incoterm DISTINTOS a los iniciales del borrador, para probar
        // que el usuario puede cambiar el valor (no solo re-guardar el mismo).
        return [
            'tipo_item_expor' => 2, // Servicios
            'recinto_fiscal' => '08', // Terrestre Anguiatú
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '10', // CFR
        ];
    }

    // --- Item 11: FEX borrador muestra Datos aduaneros editables ---

    public function test_editor_muestra_la_seccion_datos_aduaneros_para_fex_en_borrador(): void
    {
        $dte = $this->fexBorrador();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Datos aduaneros')
            ->assertSee(route('facturacion.datos-aduaneros.update', $dte), false);
    }

    // --- Guardado: valida, resuelve desc_incoterms server-side, no genera JSON ni consume correlativo ---

    public function test_guardar_datos_aduaneros_actualiza_el_borrador_y_resuelve_desc_incoterms(): void
    {
        $dte = $this->fexBorrador();
        $correlativo = Correlativo::where('tipo_dte', '11')->first();

        $this->actingAs($this->usuario())
            ->patch(route('facturacion.datos-aduaneros.update', $dte), $this->datosValidos())
            ->assertRedirect(route('facturacion.edit', $dte));

        $dte->refresh();
        $this->assertSame(2, $dte->tipo_item_expor);
        $this->assertSame('08', $dte->recinto_fiscal);
        $this->assertSame('10', $dte->cod_incoterms);
        // Item 17: desc_incoterms se resuelve SERVER-SIDE desde CAT-031 (no viene del form).
        $this->assertSame(
            CatalogoMh::where('cat', '031')->where('codigo', '10')->value('valor'),
            $dte->desc_incoterms
        );
        $this->assertSame(\App\Enums\EstadoDte::Borrador, $dte->estado);
        $this->assertNull($dte->json_generado_path);
        $correlativo->refresh();
        $this->assertSame(0, $correlativo->ultimo_numero, 'Guardar datos aduaneros no debe consumir correlativo.');
    }

    // --- Item 14: el usuario puede escoger otro recinto válido ---

    public function test_usuario_puede_elegir_otro_recinto_fiscal_valido(): void
    {
        $dte = $this->fexBorrador();
        $otroRecinto = CatalogoMh::where('cat', '027')->where('codigo', '!=', '01')->first();

        $this->actingAs($this->usuario())
            ->patch(route('facturacion.datos-aduaneros.update', $dte), array_merge($this->datosValidos(), ['recinto_fiscal' => $otroRecinto->codigo]))
            ->assertRedirect();

        $this->assertSame($otroRecinto->codigo, $dte->fresh()->recinto_fiscal);
    }

    // --- Item 15: un recinto inválido se rechaza ---

    public function test_recinto_fiscal_invalido_se_rechaza(): void
    {
        $dte = $this->fexBorrador();

        $this->actingAs($this->usuario())
            ->from(route('facturacion.edit', $dte))
            ->patch(route('facturacion.datos-aduaneros.update', $dte), array_merge($this->datosValidos(), ['recinto_fiscal' => 'NO-EXISTE']))
            ->assertRedirect(route('facturacion.edit', $dte))
            ->assertSessionHasErrors('recinto_fiscal');

        $this->assertSame('01', $dte->fresh()->recinto_fiscal, 'El borrador no debe cambiar si el código es inválido.');
    }

    // --- Item 16: régimen e incoterm inválidos se rechazan ---

    public function test_regimen_invalido_se_rechaza(): void
    {
        $dte = $this->fexBorrador();

        $this->actingAs($this->usuario())
            ->from(route('facturacion.edit', $dte))
            ->patch(route('facturacion.datos-aduaneros.update', $dte), array_merge($this->datosValidos(), ['regimen' => 'NO-EXISTE']))
            ->assertSessionHasErrors('regimen');

        $this->assertSame('1000.000', $dte->fresh()->regimen);
    }

    public function test_incoterm_invalido_se_rechaza(): void
    {
        $dte = $this->fexBorrador();

        $this->actingAs($this->usuario())
            ->from(route('facturacion.edit', $dte))
            ->patch(route('facturacion.datos-aduaneros.update', $dte), array_merge($this->datosValidos(), ['cod_incoterms' => 'NO-EXISTE']))
            ->assertSessionHasErrors('cod_incoterms');

        $this->assertSame('09', $dte->fresh()->cod_incoterms);
    }

    // --- Item 12: FEX generada no permite editar esos campos ---

    public function test_fex_generada_no_permite_editar_datos_aduaneros(): void
    {
        $dte = $this->fexBorrador();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => \App\Enums\TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertForbidden();

        $this->actingAs($this->usuario())
            ->patch(route('facturacion.datos-aduaneros.update', $dte), $this->datosValidos())
            ->assertForbidden();

        $this->assertSame('01', $dte->fresh()->recinto_fiscal, 'Un DTE ya generado no debe poder cambiar sus datos aduaneros.');
    }

    // --- Item 13: San Bartolo es el default de una FEX nueva ---

    public function test_fex_creada_desde_lista_de_empaque_usa_san_bartolo_como_recinto_por_defecto(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $cliente = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'Cliente San Bartolo', 'cliente_id' => $cliente->id, 'activo' => true]);
        $lista = Exportacion::create(['exportacion_cliente_id' => $clienteExpo->id, 'cliente_nombre' => $clienteExpo->nombre, 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);
        $lista->items()->create(['nombre_es' => 'Caja X', 'nombre_en' => 'Box X', 'unidad' => 'Bolsa', 'unidades_por_caja' => 10, 'cantidad_cajas' => 2, 'precio_caja' => 10.00, 'gramos_por_unidad' => 10, 'onzas_por_unidad' => 0.35, 'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1.1, 'peso_neto_caja_lb' => 2.2, 'peso_bruto_caja_lb' => 2.4]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame(config('dte.exportacion.recinto_fiscal_default'), $dte->recinto_fiscal);
        $this->assertSame('01', $dte->recinto_fiscal);
        $this->assertSame(
            CatalogoMh::where('cat', '027')->where('codigo', '01')->value('valor'),
            'Terrestre San Bartolo'
        );
    }

    public function test_formulario_manual_de_fex_preselecciona_san_bartolo(): void
    {
        Cliente::factory()->exportacion()->create();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            ->getContent();

        // El option del recinto San Bartolo (01) debe venir marcado como seleccionado por defecto.
        $this->assertMatchesRegularExpression(
            '/<option value="01"\s+selected>\s*Terrestre San Bartolo\s*<\/option>/u',
            $html
        );
    }
}
