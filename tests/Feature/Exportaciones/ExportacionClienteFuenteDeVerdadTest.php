<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\User;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Consolidación de "Clientes de exportación" alrededor del Cliente maestro: el
 * Cliente DTE vinculado es la fuente de verdad para nombre legal, documento
 * fiscal y dirección fiscal. ExportacionCliente solo guarda datos propios del
 * perfil de exportación (nombre operativo, dirección de entrega/bodega, FDA,
 * precios). No cambia snapshots de listas históricas ni cómo la FEX resuelve
 * su receptor.
 */
class ExportacionClienteFuenteDeVerdadTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contabilidad', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    // ---------- 1-2: nombre legal y dirección fiscal desde el Cliente maestro ----------

    public function test_nombre_legal_se_muestra_desde_el_cliente_maestro(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'IMPORTADOR NORTE LLC']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'alias interno carolinas',
            'direccion' => $clienteDte->direccion, 'activo' => true,
        ]);

        $this->assertSame('IMPORTADOR NORTE LLC', $clienteExpo->nombreLegal());

        // La ficha del cliente es la unica pantalla: muestra el nombre legal y, debajo,
        // el bloque de exportacion. El alias interno del perfil ya NO se pide ni se
        // muestra como si fuera un nombre alternativo — esa era la fuente del desfase.
        $this->actingAs($this->usuario())
            ->get(route('clientes.show', $clienteDte))
            ->assertOk()
            ->assertSee('IMPORTADOR NORTE LLC')
            ->assertSee('Exportación')
            ->assertDontSee('alias interno carolinas');
    }

    public function test_direccion_fiscal_se_muestra_desde_el_cliente_maestro(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['direccion' => '100 Main St. Springfield, MD 20000 EEUU']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'CAROLINAS', 'direccion' => null, 'activo' => true,
        ]);

        $this->assertSame('100 Main St. Springfield, MD 20000 EEUU', $clienteExpo->direccionFiscal());

        $this->actingAs($this->usuario())
            ->get(route('clientes.show', $clienteDte))
            ->assertOk()
            ->assertSee('100 Main St. Springfield, MD 20000 EEUU');
    }

    // ---------- 3: el perfil ya no tiene dirección ni contacto propios ----------

    /**
     * La dirección de entrega y el contacto del embarque que quedaron guardados en
     * perfiles viejos no se muestran: la ficha solo enseña los datos del cliente.
     */
    public function test_la_ficha_no_muestra_direccion_ni_contacto_viejos_del_perfil(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['direccion' => 'DIRECCION FISCAL 123']);
        ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'Y', 'activo' => true,
            'direccion' => 'BODEGA VIEJA 456', 'contacto' => 'contacto-viejo@ejemplo.test',
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.show', $clienteDte))
            ->assertOk()
            ->assertSee('DIRECCION FISCAL 123')
            ->assertDontSee('BODEGA VIEJA 456')
            ->assertDontSee('contacto-viejo@ejemplo.test');
    }

    // ---------- 5: la FEX usa el Cliente maestro ----------

    public function test_fex_usa_el_cliente_maestro_no_el_perfil_de_exportacion(): void
    {
        $this->seedCatalogosDte();
        $this->crearEmisorDte();

        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'CLIENTE MAESTRO SA']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'alias operativo distinto', 'direccion' => 'otra direccion', 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id, 'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-21', 'estado' => 'borrador',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Producto', 'nombre_en' => 'Product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 10, 'cantidad_cajas' => 2, 'precio_caja' => 5,
            'gramos_por_unidad' => 10, 'onzas_por_unidad' => 1,
            'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1, 'peso_neto_caja_lb' => 2, 'peso_bruto_caja_lb' => 2,
        ]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista->fresh());

        $this->assertSame($clienteDte->id, $dte->cliente_id);
        $this->assertSame('CLIENTE MAESTRO SA', $dte->cliente->nombre);
    }

    // ---------- 6: listas históricas conservan snapshot ----------

    public function test_listas_historicas_conservan_su_snapshot_pase_lo_que_pase_despues(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'NOMBRE AL MOMENTO DE CREAR']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'NOMBRE AL MOMENTO DE CREAR', 'direccion' => 'DIRECCION ORIGINAL', 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id,
            'cliente_nombre' => 'NOMBRE AL MOMENTO DE CREAR', 'cliente_direccion' => 'DIRECCION ORIGINAL',
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-21', 'estado' => 'borrador',
        ]);

        // Cambian los datos "en vivo" del cliente maestro Y del perfil de exportación.
        $clienteDte->update(['nombre' => 'NOMBRE NUEVO DESPUES']);
        $clienteExpo->update(['nombre' => 'alias nuevo', 'direccion' => 'DIRECCION NUEVA']);

        // El snapshot de la lista ya creada NO se mueve.
        $lista->refresh();
        $this->assertSame('NOMBRE AL MOMENTO DE CREAR', $lista->cliente_nombre);
        $this->assertSame('DIRECCION ORIGINAL', $lista->cliente_direccion);
    }

    // ---------- 7: documento provisional de Diamond Rocks sigue bloqueando FEX ----------

    public function test_documento_provisional_bloquea_fex_y_se_advierte_en_la_interfaz(): void
    {
        $this->seedCatalogosDte();
        $this->crearEmisorDte();

        $diamond = Cliente::factory()->exportacion()->create(['num_documento' => Cliente::DOCUMENTO_PROVISIONAL]);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $diamond->id, 'nombre' => 'IMPORTADOR ESTE INC.', 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id, 'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-21', 'estado' => 'borrador',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Producto', 'nombre_en' => 'Product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 10, 'cantidad_cajas' => 2, 'precio_caja' => 5,
            'gramos_por_unidad' => 10, 'onzas_por_unidad' => 1,
            'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1, 'peso_neto_caja_lb' => 2, 'peso_bruto_caja_lb' => 2,
        ]);

        $this->assertTrue($clienteExpo->tieneDocumentoFiscalProvisional());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(CrearFexDesdeExportacionService::class)->crear($lista->fresh());
        } finally {
            $this->assertSame(0, Dte::where('tipo_dte', TipoDte::FacturaExportacion->value)->count());
        }
    }

    public function test_documento_provisional_se_advierte_visiblemente_en_clientes_y_precios(): void
    {
        $diamond = Cliente::factory()->exportacion()->create(['num_documento' => Cliente::DOCUMENTO_PROVISIONAL]);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $diamond->id, 'nombre' => 'IMPORTADOR ESTE INC.', 'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.show', $diamond))
            ->assertOk()
            ->assertSee('Documento fiscal provisional')
            ->assertSee('no se le puede facturar');
    }

    // ---------- 8: solo clientes de exportación activos aparecen al crear lista ----------

    public function test_solo_clientes_exportacion_activos_aparecen_al_crear_lista(): void
    {
        ExportacionCliente::create(['nombre' => 'CLIENTE ACTIVO VISIBLE', 'activo' => true]);
        ExportacionCliente::create(['nombre' => 'CLIENTE INACTIVO OCULTO', 'activo' => false]);

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.create'))
            ->assertOk();

        $resp->assertSee('CLIENTE ACTIVO VISIBLE');
        $resp->assertDontSee('CLIENTE INACTIVO OCULTO');
    }
}
