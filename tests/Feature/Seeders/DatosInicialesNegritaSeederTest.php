<?php

namespace Tests\Feature\Seeders;

use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatosInicialesNegritaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_empresa_establecimiento_y_punto_venta(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $empresa = Empresa::where('razon_social', 'Dulces La Negrita, S.A. de C.V.')->first();
        $this->assertNotNull($empresa);
        $this->assertSame('Dulces La Negrita', $empresa->nombre_comercial);

        $estab = Establecimiento::where('empresa_id', $empresa->id)->where('codigo', 'M001')->first();
        $this->assertNotNull($estab);
        $this->assertSame('02', $estab->tipo_establecimiento->value); // Casa matriz

        $this->assertDatabaseHas('puntos_venta', ['establecimiento_id' => $estab->id, 'codigo' => 'P001']);
    }

    public function test_crea_correlativos_de_los_cuatro_tipos(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $estab = Establecimiento::where('codigo', 'M001')->firstOrFail();
        foreach (['01', '03', '05', '11'] as $tipo) {
            $this->assertDatabaseHas('correlativos', [
                'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'activo' => true,
            ]);
        }
        $this->assertSame(4, Correlativo::count());
    }

    public function test_crea_cliente_calleja_con_sucursales(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->first();
        $this->assertNotNull($calleja);
        $this->assertTrue((bool) $calleja->requiere_orden_compra);
        $this->assertSame('grande', $calleja->tamanio_contribuyente?->value);
        $this->assertTrue((bool) $calleja->es_agente_retencion);

        $salas = $calleja->sucursales->pluck('nombre');
        $this->assertCount(3, $salas);
        $this->assertTrue($salas->contains('Selectos Santa Rosa'));
        $this->assertTrue($salas->contains('Selectos Merliot'));
        $this->assertTrue($salas->contains('Selectos Cojutepeque'));
    }

    public function test_oficina_central_existente_se_actualiza_y_no_se_duplica(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        // Oficina Central ya creada (en otra ventana / manualmente), con valores por defecto.
        $calleja->sucursales()->create(['nombre' => 'Oficina Central', 'activo' => true]);

        // Segunda corrida del seeder: debe ACTUALIZARLA, no duplicarla.
        $this->seed(DatosInicialesNegritaSeeder::class);

        $oficinas = $calleja->sucursales()->where('nombre', 'Oficina Central')->get();
        $this->assertCount(1, $oficinas);
        $this->assertFalse($oficinas->first()->permite_ccf);
        $this->assertTrue($oficinas->first()->permite_nota_credito);
    }

    public function test_crea_cliente_de_exportacion(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertDatabaseHas('clientes', ['codigo' => 'EXP-001', 'tipo_cliente' => 'exportacion']);
    }

    public function test_crea_productos(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        foreach (['DUL-001', 'DUL-002', 'DUL-003', 'DUL-004'] as $codigo) {
            $this->assertDatabaseHas('productos', ['codigo' => $codigo, 'activo' => true]);
        }
        $this->assertSame('Pepitoria', Producto::where('codigo', 'DUL-001')->value('nombre'));
    }

    public function test_es_idempotente_y_no_reinicia_correlativos(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Simula consumo del correlativo de CCF.
        $ccf = Correlativo::where('tipo_dte', '03')->firstOrFail();
        $ccf->update(['ultimo_numero' => 7]);

        // Segunda corrida: no duplica ni reinicia.
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertSame(1, Empresa::count());
        $this->assertSame(1, Establecimiento::count());
        $this->assertSame(1, PuntoVenta::count());
        $this->assertSame(4, Correlativo::count());
        $this->assertSame(2, Cliente::count());                 // Calleja + exportación
        $this->assertSame(3, Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail()->sucursales()->count());
        $this->assertSame(23, Producto::count());               // 4 base + 19 reales de Calleja
        $this->assertSame(7, $ccf->refresh()->ultimo_numero, 'El correlativo no debe reiniciarse.');
    }

    // --- Productos reales de Calleja (por código de barra, precio especial) ---

    public function test_crea_productos_calleja_por_codigo_de_barra(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Se identifican por código de barra (no por la columna ignorada "B Item").
        foreach (['7412201700031', '7412201700079', '7412201700024', '7412201700135'] as $barra) {
            $this->assertDatabaseHas('productos', ['codigo_barra' => $barra, 'activo' => true]);
        }

        $canillitas = Producto::where('codigo_barra', '7412201700031')->firstOrFail();
        $this->assertSame('CANILLITAS', $canillitas->nombre);
        $this->assertSame('79873', $canillitas->codigo); // código interno de la tabla
    }

    public function test_no_usa_b_item_como_codigo_interno(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Códigos internos = los de la tabla de precios; los que no traían, generados (CAL-…).
        $this->assertSame('79866', Producto::where('codigo_barra', '7412201700024')->value('codigo')); // LECHE DE BURRA
        $this->assertSame('CAL-700135', Producto::where('codigo_barra', '7412201700135')->value('codigo')); // MIX (generado)
        $this->assertSame('CAL-700192', Producto::where('codigo_barra', '7412201700192')->value('codigo')); // DULCES DE ANIS (generado)
    }

    public function test_precio_especial_de_calleja_correcto(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        $canillitas = Producto::where('codigo_barra', '7412201700031')->firstOrFail();
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $canillitas->id,
            'cliente_id' => $calleja->id,
            'cliente_sucursal_id' => null,
            'precio' => '1.0500',
            'activo' => 1,
        ]);
    }

    public function test_producto_sin_precio_en_tabla_queda_en_1(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        // BESITOS no traía precio → $1.00.
        $besitos = Producto::where('codigo_barra', '7412201700284')->firstOrFail();
        $this->assertSame('BESITOS', $besitos->nombre);
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $besitos->id, 'cliente_id' => $calleja->id, 'precio' => '1.0000', 'activo' => 1,
        ]);
    }

    public function test_mix_codigo_barra_y_precio(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        $mix = Producto::where('codigo_barra', '7412201700135')->firstOrFail();
        $this->assertSame('MIX', $mix->nombre);
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $mix->id, 'cliente_id' => $calleja->id, 'precio' => '1.0400', 'activo' => 1,
        ]);
    }

    public function test_no_duplica_productos_ni_precios_al_correr_dos_veces(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertSame(1, Producto::where('codigo_barra', '7412201700031')->count());
        $this->assertSame(23, Producto::count());

        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $canillitas = Producto::where('codigo_barra', '7412201700031')->firstOrFail();
        $this->assertSame(1, \App\Models\ProductoPrecioCliente::where('producto_id', $canillitas->id)
            ->where('cliente_id', $calleja->id)->where('activo', true)->count());
    }

    public function test_actualiza_precio_especial_calleja_si_cambia(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $canillitas = Producto::where('codigo_barra', '7412201700031')->firstOrFail();

        // Alguien cambió el precio a mano…
        \App\Models\ProductoPrecioCliente::where('producto_id', $canillitas->id)
            ->where('cliente_id', $calleja->id)->update(['precio' => 9.99]);

        // …reseed lo restaura al valor de la tabla, sin duplicar.
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->assertSame('1.0500', \App\Models\ProductoPrecioCliente::where('producto_id', $canillitas->id)
            ->where('cliente_id', $calleja->id)->where('activo', true)->value('precio'));
        $this->assertSame(1, \App\Models\ProductoPrecioCliente::where('producto_id', $canillitas->id)
            ->where('cliente_id', $calleja->id)->count());
    }
}
