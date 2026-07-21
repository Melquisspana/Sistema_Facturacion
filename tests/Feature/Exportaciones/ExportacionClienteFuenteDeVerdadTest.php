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
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
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
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'CAROLINAS WHOLESALE LLC']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'alias interno carolinas',
            'direccion' => $clienteDte->direccion, 'activo' => true,
        ]);

        $this->assertSame('CAROLINAS WHOLESALE LLC', $clienteExpo->nombreLegal());

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.clientes.show', $clienteExpo))
            ->assertOk()
            ->assertSee('CAROLINAS WHOLESALE LLC')
            ->assertSee('Nombre legal')
            ->assertSee('alias interno carolinas'); // nombre operativo, mostrado aparte
    }

    public function test_direccion_fiscal_se_muestra_desde_el_cliente_maestro(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['direccion' => '13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU']);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'CAROLINAS', 'direccion' => null, 'activo' => true,
        ]);

        $this->assertSame('13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU', $clienteExpo->direccionFiscal());

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.clientes.show', $clienteExpo))
            ->assertOk()
            ->assertSee('Dirección fiscal')
            ->assertSee('13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU');
    }

    // ---------- 3: dirección de entrega/bodega, opcional y separada ----------

    public function test_direccion_entrega_bodega_solo_se_muestra_cuando_difiere(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['direccion' => 'MISMA DIRECCION 123']);

        // Caso A: idéntica a la fiscal -> no hay dirección de entrega/bodega propia.
        $sinBodegaPropia = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'X', 'direccion' => 'MISMA DIRECCION 123', 'activo' => true,
        ]);
        $this->assertNull($sinBodegaPropia->direccionEntregaBodega());

        // Caso B: distinta -> es una dirección de entrega/bodega real, se expone.
        $conBodegaPropia = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'Y', 'direccion' => 'BODEGA DISTINTA 456', 'activo' => true,
        ]);
        $this->assertSame('BODEGA DISTINTA 456', $conBodegaPropia->direccionEntregaBodega());

        $resp = $this->actingAs($this->usuario())
            ->get(route('exportaciones.clientes.show', $conBodegaPropia))
            ->assertOk();
        $resp->assertSee('Dirección de entrega/bodega');
        $resp->assertSee('BODEGA DISTINTA 456');
    }

    // ---------- 4: no se puede desincronizar nombre/dirección fiscal desde esta pantalla ----------

    public function test_no_se_puede_desincronizar_nombre_ni_direccion_fiscal_desde_clientes_y_precios(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create([
            'nombre' => 'NOMBRE LEGAL ORIGINAL', 'direccion' => 'DIRECCION FISCAL ORIGINAL',
        ]);
        $clienteExpo = ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => 'alias original', 'direccion' => null, 'activo' => true,
        ]);

        // Intento de "editar" el perfil de exportación con valores que, si se
        // filtraran hacia el Cliente maestro, lo desincronizarían.
        $this->actingAs($this->usuario())
            ->put(route('exportaciones.clientes.update', $clienteExpo), [
                'nombre' => 'intento de cambiar el nombre legal',
                'direccion' => 'intento de cambiar la direccion fiscal',
                'activo' => true,
            ])
            ->assertRedirect(route('exportaciones.clientes.show', $clienteExpo));

        // El Cliente maestro es inmune: la pantalla nunca toca sus columnas.
        $clienteDte->refresh();
        $this->assertSame('NOMBRE LEGAL ORIGINAL', $clienteDte->nombre);
        $this->assertSame('DIRECCION FISCAL ORIGINAL', $clienteDte->direccion);

        // Lo único que cambió es el perfil operativo propio.
        $clienteExpo->refresh();
        $this->assertSame('intento de cambiar el nombre legal', $clienteExpo->nombre);
        $this->assertSame('NOMBRE LEGAL ORIGINAL', $clienteExpo->nombreLegal()); // sigue resolviendo al maestro
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
            'cliente_id' => $diamond->id, 'nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'activo' => true,
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
            'cliente_id' => $diamond->id, 'nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.clientes.show', $clienteExpo))
            ->assertOk()
            ->assertSee('Documento provisional');
    }

    // ---------- 8: solo clientes de exportación activos aparecen al crear lista ----------

    public function test_solo_clientes_exportacion_activos_aparecen_al_crear_lista(): void
    {
        ExportacionCliente::create(['nombre' => 'CLIENTE ACTIVO VISIBLE', 'activo' => true]);
        ExportacionCliente::create(['nombre' => 'CLIENTE INACTIVO OCULTO', 'activo' => false]);

        $resp = $this->actingAs($this->usuario())
            ->get(route('exportaciones.create'))
            ->assertOk();

        $resp->assertSee('CLIENTE ACTIVO VISIBLE');
        $resp->assertDontSee('CLIENTE INACTIVO OCULTO');
    }
}
