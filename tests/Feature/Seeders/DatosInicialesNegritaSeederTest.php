<?php

namespace Tests\Feature\Seeders;

use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
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

        // Repo público: el seeder trae una empresa de ejemplo, no la real.
        $empresa = Empresa::where('razon_social', 'Empresa de Ejemplo, S.A. de C.V.')->first();
        $this->assertNotNull($empresa);
        $this->assertSame('Empresa de Ejemplo', $empresa->nombre_comercial);

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
        $this->assertSame(23, Producto::count());               // 4 base + 19 de ejemplo con precio especial
        $this->assertSame(7, $ccf->refresh()->ultimo_numero, 'El correlativo no debe reiniciarse.');
    }

    // --- Productos de ejemplo con precio especial (por código de barra) ---

    /**
     * El repositorio es público: el catálogo con precio especial que trae el seeder es
     * inventado. Nombres «Producto de ejemplo N» y códigos de barra del rango 200…, que
     * GS1 reserva para uso interno y no corresponde a ningún producto del comercio.
     */
    public function test_el_catalogo_con_precio_especial_es_de_ejemplo(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        $conPrecioEspecial = Producto::whereIn('id', ProductoPrecioCliente::where('cliente_id', $calleja->id)->select('producto_id'))
            ->where('codigo', 'not like', 'DUL-%')->get();

        $this->assertCount(19, $conPrecioEspecial);
        foreach ($conPrecioEspecial as $producto) {
            $this->assertMatchesRegularExpression('/^Producto de ejemplo \d+$/', $producto->nombre);
            $this->assertMatchesRegularExpression('/^2000000001\d{3}$/', $producto->codigo_barra);
        }
    }

    public function test_crea_productos_de_ejemplo_por_codigo_de_barra(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        foreach (['2000000001001', '2000000001002', '2000000001014', '2000000001015'] as $barra) {
            $this->assertDatabaseHas('productos', ['codigo_barra' => $barra, 'activo' => true]);
        }

        $primero = Producto::where('codigo_barra', '2000000001001')->firstOrFail();
        $this->assertSame('Producto de ejemplo 1', $primero->nombre);
        $this->assertSame('EJ-0001', $primero->codigo); // código interno declarado
    }

    public function test_genera_un_codigo_interno_cuando_el_producto_no_lo_trae(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertSame('EJ-0014', Producto::where('codigo_barra', '2000000001014')->value('codigo'));
        $this->assertSame('CAL-001015', Producto::where('codigo_barra', '2000000001015')->value('codigo')); // generado
        $this->assertSame('CAL-001016', Producto::where('codigo_barra', '2000000001016')->value('codigo')); // generado
    }

    public function test_precio_especial_de_calleja_correcto(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        $primero = Producto::where('codigo_barra', '2000000001001')->firstOrFail();
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $primero->id,
            'cliente_id' => $calleja->id,
            'cliente_sucursal_id' => null,
            'precio' => '1.1000',
            'activo' => 1,
        ]);
    }

    public function test_producto_sin_precio_propio_queda_en_1(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();

        $sinPrecio = Producto::where('codigo_barra', '2000000001019')->firstOrFail();
        $this->assertSame('Producto de ejemplo 19', $sinPrecio->nombre);
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $sinPrecio->id, 'cliente_id' => $calleja->id, 'precio' => '1.0000', 'activo' => 1,
        ]);
    }

    public function test_no_duplica_productos_ni_precios_al_correr_dos_veces(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertSame(1, Producto::where('codigo_barra', '2000000001001')->count());
        $this->assertSame(23, Producto::count());

        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $primero = Producto::where('codigo_barra', '2000000001001')->firstOrFail();
        $this->assertSame(1, ProductoPrecioCliente::where('producto_id', $primero->id)
            ->where('cliente_id', $calleja->id)->where('activo', true)->count());
    }

    public function test_actualiza_precio_especial_calleja_si_cambia(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $primero = Producto::where('codigo_barra', '2000000001001')->firstOrFail();

        // Alguien cambió el precio a mano…
        ProductoPrecioCliente::where('producto_id', $primero->id)
            ->where('cliente_id', $calleja->id)->update(['precio' => 9.99]);

        // …reseed lo restaura al valor del seeder, sin duplicar.
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->assertSame('1.1000', ProductoPrecioCliente::where('producto_id', $primero->id)
            ->where('cliente_id', $calleja->id)->where('activo', true)->value('precio'));
        $this->assertSame(1, ProductoPrecioCliente::where('producto_id', $primero->id)
            ->where('cliente_id', $calleja->id)->count());
    }

    public function test_si_ya_hay_empresa_emisora_no_crea_otra_ni_le_cambia_los_datos(): void
    {
        $real = Empresa::create(['razon_social' => 'Empresa ya cargada, S.A. de C.V.', 'nombre_comercial' => 'Nombre ya cargado', 'nit' => '0614-111111-111-1', 'activo' => true]);

        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->assertSame(1, Empresa::count());
        $this->assertSame('Nombre ya cargado', $real->refresh()->nombre_comercial);
        $this->assertSame('0614-111111-111-1', $real->nit);
        $this->assertDatabaseHas('establecimientos', ['empresa_id' => $real->id, 'codigo' => 'M001']);
    }
}
