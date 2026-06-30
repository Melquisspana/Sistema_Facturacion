<?php

namespace Tests\Feature\Importacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Importacion\ExportadorDatos;
use App\Services\Importacion\ImportadorProductosPrecios;
use App\Services\Importacion\ImportadorSalas;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportacionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    private function csv(string $contenido): string
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'imp_'.uniqid().'.csv';
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function csvSalas(): string
    {
        return $this->csv(<<<CSV
        No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento
        1,Súper Selectos Escalón,Col. Escalón,Centro,San Salvador,San Salvador
        2,Súper Selectos Soyapango,Av. Roosevelt,Norte,Soyapango,San Salvador
        CSV);
    }

    private function csvPrecios(): string
    {
        return $this->csv(<<<CSV
        Código interno,Código de barra,Descripción de producto,Factor de empaque,Fecha de inicio,Costo nuevo / unidad libra / precio
        79873,7412201700031,CANILLITAS,BOLSA,2026-01-01,1.0500
        106753,7412201700079,COCO RALLADO,BOLSA,,1.0000
        999,888,SIN PRECIO,BOLSA,,
        CSV);
    }

    private function csvSalasConTitulo(): string
    {
        return $this->csv(<<<CSV
        LISTADO DE SALAS CON SU RESPECTIVA DIRECCION,,,,,
        No.,NOMBRE COMERCIAL,DIRECCIÓN,DISTRITO,MUNICIPIO,DEPARTAMENTO
        1,Súper Selectos Escalón,"Col. Escalón, Calle 1",Centro,San Salvador,San Salvador
        2,Súper Selectos Soyapango,Av. Roosevelt,Norte,Soyapango,San Salvador
        CSV);
    }

    // --- Salas ---

    public function test_importar_salas_crea_sucursales(): void
    {
        $calleja = $this->calleja();

        $resultado = app(ImportadorSalas::class)->importar($calleja, $this->csvSalas());

        $this->assertSame(2, $resultado->leidas);
        $this->assertSame(2, $resultado->creadas);
        $this->assertCount(2, $calleja->refresh()->sucursales);
        $this->assertTrue($calleja->sucursales->pluck('nombre')->contains('Súper Selectos Escalón'));
    }

    public function test_importar_salas_asigna_codigo_de_sala(): void
    {
        $calleja = $this->calleja();

        $csv = $this->csv(<<<CSV
        No.,Nombre comercial,Código de sala,Dirección,Distrito,Municipio,Departamento
        1,Súper Selectos Escalón,230,Col. Escalón,Centro,San Salvador,San Salvador
        CSV);

        app(ImportadorSalas::class)->importar($calleja, $csv);

        // El código se normaliza a 4 dígitos con cero inicial (230 -> 0230).
        $this->assertSame('0230', $calleja->sucursales()->where('nombre', 'Súper Selectos Escalón')->value('codigo'));
    }

    public function test_importar_salas_con_fila_de_titulo_antes_del_encabezado(): void
    {
        $calleja = $this->calleja();

        $resultado = app(ImportadorSalas::class)->importar($calleja, $this->csvSalasConTitulo());

        $this->assertSame(0, $resultado->errores);
        $this->assertSame(2, $resultado->leidas);   // no cuenta el título
        $this->assertSame(2, $resultado->creadas);
        $this->assertCount(2, $calleja->refresh()->sucursales);
    }

    public function test_importar_salas_encabezados_mayusculas_y_acentos(): void
    {
        $calleja = $this->calleja();
        $csv = $this->csv(<<<CSV
        NO.,NOMBRE COMERCIAL,DIRECCION,DISTRITO,MUNICIPIO,DEPARTAMENTO
        1,Sala Uno,Dir 1,Centro,San Salvador,San Salvador
        2,Sala Dos,Dir 2,Norte,Soyapango,San Salvador
        CSV);

        $resultado = app(ImportadorSalas::class)->importar($calleja, $csv);

        $this->assertSame(2, $resultado->creadas);
        $this->assertTrue($calleja->refresh()->sucursales->pluck('nombre')->contains('Sala Uno'));
    }

    public function test_importar_salas_ignora_filas_vacias(): void
    {
        $calleja = $this->calleja();
        $csv = $this->csv(<<<CSV
        LISTADO DE SALAS,,,,,
        No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento
        ,,,,,
        1,Sala Llena,Dir,Centro,San Salvador,San Salvador
        CSV);

        $resultado = app(ImportadorSalas::class)->importar($calleja, $csv);

        $this->assertSame(1, $resultado->leidas);   // la fila vacía no se cuenta
        $this->assertSame(1, $resultado->creadas);
    }

    public function test_importar_salas_sin_encabezado_muestra_error(): void
    {
        $calleja = $this->calleja();
        $csv = $this->csv(<<<CSV
        foo,bar,baz
        1,2,3
        CSV);

        $resultado = app(ImportadorSalas::class)->importar($calleja, $csv);

        $this->assertSame(1, $resultado->errores);
        $this->assertCount(0, $calleja->refresh()->sucursales);
        $this->assertStringContainsString('No se encontró la fila de encabezados', $resultado->detalles[0]['detalle']);
    }

    /** Importa una sola sala con la ubicación dada y devuelve la sucursal. */
    private function importarUnaSala(\App\Models\Cliente $cliente, string $municipio, string $distrito = '', string $departamento = 'San Salvador'): \App\Models\ClienteSucursal
    {
        // Campos entre comillas para que las comas internas (ej. "San ,Miguel") no rompan el CSV.
        $csv = $this->csv("No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento\n1,\"Sala X\",\"Dir\",\"{$distrito}\",\"{$municipio}\",\"{$departamento}\"\n");
        app(ImportadorSalas::class)->importar($cliente, $csv);

        return $cliente->refresh()->sucursales()->where('nombre', 'Sala X')->firstOrFail();
    }

    public function test_san_salvador_centro_se_mapea(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'San Salvador Centro', '', 'San Salvador');
        $this->assertSame('San Salvador', \App\Models\Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_santa_ana_centro_se_mapea_a_santa_ana(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'Santa Ana Centro', '', 'Santa Ana');
        $this->assertSame('Santa Ana', \App\Models\Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_san_miguel_centro_con_coma_se_mapea(): void
    {
        // Municipio escrito como "San ,Miguel Centro".
        $sucursal = $this->importarUnaSala($this->calleja(), 'San ,Miguel Centro', '', 'San Miguel');
        $this->assertSame('San Miguel', \App\Models\Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_sonsonate_centro_se_mapea(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'Sonsonate Centro', '', 'Sonsonate');
        $this->assertSame('Sonsonate', \App\Models\Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_distrito_tiene_prioridad_sobre_municipio_nuevo(): void
    {
        // Distrito = Santa Tecla, Municipio = La Libertad Sur → usa Santa Tecla (distrito).
        $sucursal = $this->importarUnaSala($this->calleja(), 'La Libertad Sur', 'Santa Tecla', 'La Libertad');
        $this->assertSame('Santa Tecla', \App\Models\Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_sin_equivalencia_segura_crea_sucursal_con_advertencia(): void
    {
        $calleja = $this->calleja();
        $csv = $this->csv("No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento\n1,Sala Rara,\"Col. Centro, #5\",Mi Distrito,Pueblo Inexistente XYZ,Departamento Raro\n");

        $resultado = app(ImportadorSalas::class)->importar($calleja, $csv);

        // 1) La sucursal se crea igual.
        $this->assertSame(1, $resultado->creadas);
        $sucursal = $calleja->refresh()->sucursales()->where('nombre', 'Sala Rara')->firstOrFail();
        $this->assertNull($sucursal->municipio_id);
        $this->assertNull($sucursal->departamento_id);

        // 2) Los datos originales quedan guardados (dirección + texto en observaciones).
        $this->assertSame('Col. Centro, #5', $sucursal->direccion);
        $this->assertStringContainsString('Mi Distrito', $sucursal->observaciones);
        $this->assertStringContainsString('Pueblo Inexistente XYZ', $sucursal->observaciones);
        $this->assertStringContainsString('Departamento Raro', $sucursal->observaciones);

        // 3) El reporte muestra la advertencia claramente.
        $this->assertGreaterThanOrEqual(1, $resultado->advertencias);
        $detalle = $resultado->detalles[0]['detalle'];
        $this->assertStringContainsString('Municipio no encontrado: Pueblo Inexistente XYZ', $detalle);
        $this->assertStringContainsString('Departamento no encontrado: Departamento Raro', $detalle);
    }

    public function test_importar_salas_dos_veces_no_duplica(): void
    {
        $calleja = $this->calleja();
        $ruta = $this->csvSalas();

        app(ImportadorSalas::class)->importar($calleja, $ruta);
        $segunda = app(ImportadorSalas::class)->importar($calleja, $this->csvSalas());

        $this->assertSame(2, $segunda->actualizadas);
        $this->assertCount(2, $calleja->refresh()->sucursales);
    }

    // --- Productos / precios ---

    public function test_importar_productos_crea_con_codigo_y_barra(): void
    {
        $calleja = $this->calleja();

        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $this->assertDatabaseHas('productos', ['codigo' => '79873', 'codigo_barra' => '7412201700031', 'nombre' => 'CANILLITAS']);
        $this->assertDatabaseHas('productos', ['codigo' => '106753', 'codigo_barra' => '7412201700079']);
    }

    public function test_importar_precios_crea_precio_especial_calleja(): void
    {
        $calleja = $this->calleja();

        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $producto = Producto::where('codigo', '79873')->firstOrFail();
        $this->assertDatabaseHas('producto_precios_cliente', [
            'producto_id' => $producto->id, 'cliente_id' => $calleja->id, 'precio' => 1.0500, 'activo' => true,
        ]);
    }

    public function test_producto_sin_precio_numerico_se_ignora(): void
    {
        $calleja = $this->calleja();

        $resultado = app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $this->assertSame(1, $resultado->ignoradas);
        $this->assertDatabaseMissing('productos', ['codigo' => '999']);
    }

    public function test_importar_precios_dos_veces_no_duplica(): void
    {
        $calleja = $this->calleja();
        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());
        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $this->assertSame(2, Producto::whereIn('codigo', ['79873', '106753'])->count());
        $this->assertSame(2, ProductoPrecioCliente::where('cliente_id', $calleja->id)->count());
    }

    public function test_precio_calleja_tiene_prioridad_al_facturar(): void
    {
        $calleja = $this->calleja();
        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());
        $producto = Producto::where('codigo', '79873')->firstOrFail();

        $empresa = \App\Models\Empresa::create(['razon_social' => 'X', 'ambiente' => '00', 'activo' => true]);
        $estab = \App\Models\Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $pv = \App\Models\PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $calleja,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $linea = $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->assertSame('1.050000', $linea->precio_unitario);
    }

    // --- Exportación ---

    public function test_exportar_salas_devuelve_datos(): void
    {
        $calleja = $this->calleja();
        app(ImportadorSalas::class)->importar($calleja, $this->csvSalas());

        $csv = app(ExportadorDatos::class)->salasCsv($calleja);

        $this->assertStringContainsString('cliente,sucursal,codigo,direccion', $csv);
        $this->assertStringContainsString('Súper Selectos Escalón', $csv);
    }

    public function test_exportar_precios_devuelve_datos(): void
    {
        $calleja = $this->calleja();
        app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $csv = app(ExportadorDatos::class)->preciosCsv($calleja);

        $this->assertStringContainsString('codigo_interno,codigo_barra,producto', $csv);
        $this->assertStringContainsString('79873', $csv);
        $this->assertStringContainsString('CANILLITAS', $csv);
    }

    // --- Seguridad y flujo HTTP ---

    public function test_solo_admin_accede(): void
    {
        // Invitado primero (sin sesión activa).
        $this->get(route('importaciones.index'))->assertRedirect('/login');
        $this->actingAs($this->usuario('facturacion'))->get(route('importaciones.index'))->assertForbidden();
        $this->actingAs($this->usuario('consulta'))->get(route('importaciones.index'))->assertForbidden();
        $this->actingAs($this->usuario('administrador'))->get(route('importaciones.index'))->assertOk();
    }

    public function test_solo_admin_descarga_plantilla_de_salas(): void
    {
        $this->get(route('importaciones.salas.plantilla'))->assertRedirect('/login');
        $this->actingAs($this->usuario('consulta'))->get(route('importaciones.salas.plantilla'))->assertForbidden();

        $respuesta = $this->actingAs($this->usuario('administrador'))->get(route('importaciones.salas.plantilla'));
        $respuesta->assertOk();
        $contenido = $respuesta->streamedContent();
        foreach (['Nombre comercial', 'Dirección', 'Distrito', 'Municipio', 'Departamento', 'Requiere orden compra'] as $encabezado) {
            $this->assertStringContainsString($encabezado, $contenido);
        }
    }

    public function test_solo_admin_descarga_plantilla_de_productos(): void
    {
        $this->actingAs($this->usuario('facturacion'))->get(route('importaciones.precios.plantilla'))->assertForbidden();

        $respuesta = $this->actingAs($this->usuario('administrador'))->get(route('importaciones.precios.plantilla'));
        $respuesta->assertOk();
        $contenido = $respuesta->streamedContent();
        foreach (['Código interno', 'Código de barra', 'Descripción de producto', 'Factor de empaque', 'Fecha de inicio', 'Costo nuevo / unidad libra / precio'] as $encabezado) {
            $this->assertStringContainsString($encabezado, $contenido);
        }
    }

    public function test_admin_importa_salas_por_la_ruta(): void
    {
        $calleja = $this->calleja();
        $archivo = UploadedFile::fake()->createWithContent('salas.csv', file_get_contents($this->csvSalas()));

        $this->actingAs($this->usuario('administrador'))
            ->post(route('importaciones.salas.importar'), ['cliente_id' => $calleja->id, 'archivo' => $archivo])
            ->assertRedirect()
            ->assertSessionHas('resumen');

        $this->assertCount(2, $calleja->refresh()->sucursales);
    }

    // --- Reporte detallado ---

    public function test_resultado_incluye_detalle_por_fila(): void
    {
        $calleja = $this->calleja();

        $resultado = app(ImportadorSalas::class)->importar($calleja, $this->csvSalas());

        $this->assertCount(2, $resultado->detalles);
        $this->assertSame('creado', $resultado->detalles[0]['accion']);
        $this->assertSame(1, $resultado->detalles[0]['fila']);
        $this->assertSame('Súper Selectos Escalón', $resultado->detalles[0]['nombre']);
    }

    public function test_producto_sin_precio_aparece_como_ignorado_en_detalle(): void
    {
        $calleja = $this->calleja();

        $resultado = app(ImportadorProductosPrecios::class)->importar($calleja, $this->csvPrecios());

        $ignorados = array_filter($resultado->detalles, fn ($d) => $d['accion'] === 'ignorado');
        $this->assertCount(1, $ignorados);
        $fila = array_values($ignorados)[0];
        $this->assertSame('SIN PRECIO', $fila['nombre']);
        $this->assertStringContainsString('Precio vacío', $fila['detalle']);
    }

    public function test_reporte_se_muestra_en_pantalla(): void
    {
        $calleja = $this->calleja();
        $archivo = UploadedFile::fake()->createWithContent('salas.csv', file_get_contents($this->csvSalas()));
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)
            ->post(route('importaciones.salas.importar'), ['cliente_id' => $calleja->id, 'archivo' => $archivo]);

        $this->actingAs($admin)
            ->get(route('importaciones.index'))
            ->assertOk()
            ->assertSee('Creadas')
            ->assertSee('Súper Selectos Escalón')
            ->assertSee('Creado');
    }
}
