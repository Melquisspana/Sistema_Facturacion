<?php

namespace Tests\Feature\Productos;

use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\UnidadMedida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comando `productos:reconciliar-nacional`: deja activos los productos nacionales con su
 * precio SIN IVA, mantiene MIX activo (otras tiendas) y archiva (activo=false) los que ya
 * no se venden, sin tocar precios especiales ni líneas históricas. Dry-run por defecto.
 */
class ReconciliarCatalogoNacionalTest extends TestCase
{
    use RefreshDatabase;

    private int $unidadId;

    protected function setUp(): void
    {
        parent::setUp();
        // Unidad con código CAT-014 válido (como "Unidad" 59 en producción).
        $this->unidadId = UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'activo' => true])->id;
    }

    private function producto(string $codigo, string $barra, string $nombre, string $precio, bool $activo = true): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'codigo_barra' => $barra,
            'nombre' => $nombre,
            'tipo_producto' => '1',
            'unidad_medida_id' => $this->unidadId,
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
            'activo' => $activo,
        ]);
    }

    /** Un producto nacional, MIX, y los 3 a archivar. */
    private function sembrarCatalogo(): void
    {
        $this->producto('P-CAN', '7412201700031', 'CANILLITAS', '1.0500');   // nacional
        $this->producto('P-MIX', '7412201700135', 'MIX', '1.0400');          // se mantiene activo
        $this->producto('P-ANI', '7412201700192', 'DULCES DE ANIS', '1.0000');
        $this->producto('P-CDC', '7412201700048', 'CONSERVA DE COCO', '1.0000');
        $this->producto('P-MAZ', '7412201700115', 'MAZAPÁN', '1.0000');
    }

    public function test_dry_run_no_cambia_nada(): void
    {
        $this->sembrarCatalogo();

        $this->artisan('productos:reconciliar-nacional')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        // Nada cambió: los 3 siguen activos.
        $this->assertTrue(Producto::where('codigo_barra', '7412201700192')->value('activo'));
        $this->assertTrue(Producto::where('codigo_barra', '7412201700048')->value('activo'));
        $this->assertTrue(Producto::where('codigo_barra', '7412201700115')->value('activo'));
    }

    public function test_apply_archiva_los_tres_y_mantiene_activos_los_demas(): void
    {
        $this->sembrarCatalogo();

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        // Nacional y MIX quedan activos.
        $this->assertTrue(Producto::where('codigo_barra', '7412201700031')->value('activo'), 'CANILLITAS activo');
        $this->assertTrue(Producto::where('codigo_barra', '7412201700135')->value('activo'), 'MIX activo');
        // Los 3 quedan archivados (activo=false), NO borrados.
        foreach (['7412201700192', '7412201700048', '7412201700115'] as $barra) {
            $this->assertFalse(Producto::where('codigo_barra', $barra)->value('activo'), "archivado $barra");
            $this->assertNotNull(Producto::where('codigo_barra', $barra)->first(), "no borrado $barra");
        }
    }

    public function test_apply_fija_el_precio_sin_iva_de_los_nacionales(): void
    {
        // CANILLITAS con precio incorrecto → el comando lo corrige a 1.0500 sin IVA.
        $this->producto('P-CAN', '7412201700031', 'CANILLITAS', '9.9900');

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        $this->assertSame('1.0500', (string) Producto::where('codigo_barra', '7412201700031')->value('precio_unitario'));
    }

    public function test_reactiva_un_nacional_que_estaba_inactivo(): void
    {
        $this->producto('P-LEC', '7412201700024', 'LECHE DE BURRA', '1.0900', activo: false);

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        $this->assertTrue(Producto::where('codigo_barra', '7412201700024')->value('activo'));
    }

    public function test_no_archiva_si_tiene_precio_especial_de_otro_cliente(): void
    {
        $anis = $this->producto('P-ANI', '7412201700192', 'DULCES DE ANIS', '1.0000');
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Tienda Los Andes']);
        ProductoPrecioCliente::create([
            'producto_id' => $anis->id, 'cliente_id' => $otro->id,
            'precio' => '1.1000', 'activo' => true,
        ]);

        $this->artisan('productos:reconciliar-nacional --apply')
            ->expectsOutputToContain('OMITIDO')
            ->assertExitCode(0);

        // No se archivó: tiene uso en otro cliente.
        $this->assertTrue(Producto::where('codigo_barra', '7412201700192')->value('activo'));
    }

    public function test_no_toca_precios_especiales_de_calleja(): void
    {
        $calleja = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $maz = $this->producto('P-MAZ', '7412201700115', 'MAZAPÁN', '1.0000');
        $pp = ProductoPrecioCliente::create([
            'producto_id' => $maz->id, 'cliente_id' => $calleja->id,
            'precio' => '1.0000', 'activo' => true,
        ]);

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        // El producto se archiva, pero su precio especial de Calleja queda intacto.
        $this->assertFalse(Producto::where('codigo_barra', '7412201700115')->value('activo'));
        $this->assertTrue(ProductoPrecioCliente::find($pp->id)->activo, 'precio especial Calleja intacto');
    }

    public function test_no_afecta_lineas_historicas_de_dte(): void
    {
        // Un producto archivado no debe romper ni alterar líneas de DTE ya existentes
        // (usan snapshot propio). Se simula una línea con snapshot y se archiva el producto.
        $anis = $this->producto('P-ANI', '7412201700192', 'DULCES DE ANIS', '1.0000');
        $lineaAntes = \App\Models\DteLinea::query()->count();

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        // El comando no crea ni borra líneas de DTE.
        $this->assertSame($lineaAntes, \App\Models\DteLinea::query()->count());
        $this->assertNotNull(Producto::where('codigo_barra', '7412201700192')->first()); // sigue existiendo
    }

    public function test_es_idempotente(): void
    {
        $this->sembrarCatalogo();

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);
        $estado1 = Producto::orderBy('codigo_barra')->get(['codigo_barra', 'activo', 'precio_unitario'])->toArray();

        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);
        $estado2 = Producto::orderBy('codigo_barra')->get(['codigo_barra', 'activo', 'precio_unitario'])->toArray();

        $this->assertSame($estado1, $estado2, 'segunda corrida no cambia nada');
    }

    // --- Precios especiales de Calleja (--precios-especiales-calleja) ---

    /**
     * Crea Calleja + los 4 productos (MIX + 3 archivados) con precio especial de Calleja,
     * y un producto con precio especial de OTRO cliente para verificar que no se toca.
     *
     * @return array{calleja: Cliente, otro: Cliente, ppc: array<string, ProductoPrecioCliente>}
     */
    private function sembrarPreciosCalleja(): array
    {
        $calleja = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Tienda Los Andes']);

        $defs = [
            'mix' => ['7412201700135', 'MIX', '1.0400'],
            'ani' => ['7412201700192', 'DULCES DE ANIS', '1.0000'],
            'cdc' => ['7412201700048', 'CONSERVA DE COCO', '1.0000'],
            'maz' => ['7412201700115', 'MAZAPÁN', '1.0000'],
        ];
        $ppc = [];
        foreach ($defs as $k => [$barra, $nombre, $precio]) {
            $p = $this->producto('P-'.strtoupper($k), $barra, $nombre, $precio);
            $ppc[$k] = ProductoPrecioCliente::create([
                'producto_id' => $p->id, 'cliente_id' => $calleja->id, 'precio' => $precio, 'activo' => true,
            ]);
        }
        // Precio especial de OTRO cliente para MIX: debe quedar intacto.
        $ppc['otro_mix'] = ProductoPrecioCliente::create([
            'producto_id' => Producto::where('codigo_barra', '7412201700135')->value('id'),
            'cliente_id' => $otro->id, 'precio' => '1.1000', 'activo' => true,
        ]);

        return ['calleja' => $calleja, 'otro' => $otro, 'ppc' => $ppc];
    }

    public function test_dry_run_no_desactiva_precios_especiales(): void
    {
        $d = $this->sembrarPreciosCalleja();

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        foreach (['mix', 'ani', 'cdc', 'maz'] as $k) {
            $this->assertTrue(ProductoPrecioCliente::find($d['ppc'][$k]->id)->activo, "$k sigue activo en dry-run");
        }
    }

    public function test_apply_desactiva_los_cuatro_precios_de_calleja(): void
    {
        $d = $this->sembrarPreciosCalleja();

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')->assertExitCode(0);

        // Los 4 precios especiales de Calleja quedan inactivos (no borrados).
        foreach (['mix', 'ani', 'cdc', 'maz'] as $k) {
            $pp = ProductoPrecioCliente::find($d['ppc'][$k]->id);
            $this->assertNotNull($pp, "$k no borrado");
            $this->assertFalse($pp->activo, "$k desactivado");
        }
    }

    public function test_no_toca_precios_de_otros_clientes(): void
    {
        $d = $this->sembrarPreciosCalleja();

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')->assertExitCode(0);

        // El precio especial de MIX del OTRO cliente queda intacto.
        $this->assertTrue(ProductoPrecioCliente::find($d['ppc']['otro_mix']->id)->activo, 'otro cliente intacto');
    }

    public function test_mix_sigue_activo_como_producto_al_desactivar_su_precio_calleja(): void
    {
        $this->sembrarPreciosCalleja();

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')->assertExitCode(0);

        // MIX (producto) sigue activo; solo se desactivó su precio especial de Calleja.
        $this->assertTrue(Producto::where('codigo_barra', '7412201700135')->value('activo'), 'MIX producto activo');
    }

    public function test_precios_calleja_es_idempotente(): void
    {
        $d = $this->sembrarPreciosCalleja();

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')->assertExitCode(0);
        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')->assertExitCode(0);

        foreach (['mix', 'ani', 'cdc', 'maz'] as $k) {
            $this->assertFalse(ProductoPrecioCliente::find($d['ppc'][$k]->id)->activo);
        }
        $this->assertTrue(ProductoPrecioCliente::find($d['ppc']['otro_mix']->id)->activo);
    }

    public function test_sin_calleja_no_toca_ningun_precio_especial(): void
    {
        // Sin cliente Calleja, la opción falla de forma segura y no toca nada.
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Tienda Los Andes']);
        $p = $this->producto('P-MIX', '7412201700135', 'MIX', '1.0400');
        $pp = ProductoPrecioCliente::create(['producto_id' => $p->id, 'cliente_id' => $otro->id, 'precio' => '1.1000', 'activo' => true]);

        $this->artisan('productos:reconciliar-nacional --precios-especiales-calleja --apply')
            ->expectsOutputToContain('No se encontró un cliente Calleja')
            ->assertExitCode(1);

        $this->assertTrue(ProductoPrecioCliente::find($pp->id)->activo, 'nada se tocó sin Calleja');
    }

    public function test_sin_el_flag_no_toca_precios_especiales(): void
    {
        $d = $this->sembrarPreciosCalleja();

        // Reconciliación de productos SIN el flag: los precios especiales quedan intactos.
        $this->artisan('productos:reconciliar-nacional --apply')->assertExitCode(0);

        foreach (['mix', 'ani', 'cdc', 'maz', 'otro_mix'] as $k) {
            $this->assertTrue(ProductoPrecioCliente::find($d['ppc'][$k]->id)->activo, "$k intacto sin flag");
        }
    }
}
