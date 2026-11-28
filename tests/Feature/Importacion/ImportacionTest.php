<?php

namespace Tests\Feature\Importacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Importacion\ExportadorDatos;
use App\Services\Importacion\ImportadorProductosPrecios;
use App\Services\Importacion\ImportadorSalas;
use App\Services\Importacion\ResultadoImportacion;
use App\Support\Ubicacion\CoherenciaUbicacion;
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
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
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
        return $this->csv(<<<'CSV'
        No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento
        1,Súper Selectos Escalón,Col. Escalón,Centro,San Salvador,San Salvador
        2,Súper Selectos Soyapango,Av. Roosevelt,Norte,Soyapango,San Salvador
        CSV);
    }

    private function csvPrecios(): string
    {
        return $this->csv(<<<'CSV'
        Código interno,Código de barra,Descripción de producto,Factor de empaque,Fecha de inicio,Costo nuevo / unidad libra / precio
        79873,7412201700031,CANILLITAS,BOLSA,2026-01-01,1.0500
        106753,7412201700079,COCO RALLADO,BOLSA,,1.0000
        999,888,SIN PRECIO,BOLSA,,
        CSV);
    }

    private function csvSalasConTitulo(): string
    {
        return $this->csv(<<<'CSV'
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

        $csv = $this->csv(<<<'CSV'
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
        $csv = $this->csv(<<<'CSV'
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
        $csv = $this->csv(<<<'CSV'
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
        $csv = $this->csv(<<<'CSV'
        foo,bar,baz
        1,2,3
        CSV);

        $resultado = app(ImportadorSalas::class)->importar($calleja, $csv);

        $this->assertSame(1, $resultado->errores);
        $this->assertCount(0, $calleja->refresh()->sucursales);
        $this->assertStringContainsString('No se encontró la fila de encabezados', $resultado->detalles[0]['detalle']);
    }

    /** Importa una sola sala con la ubicación dada y devuelve la sucursal. */
    private function importarUnaSala(Cliente $cliente, string $municipio, string $distrito = '', string $departamento = 'San Salvador'): ClienteSucursal
    {
        // Campos entre comillas para que las comas internas (ej. "San ,Miguel") no rompan el CSV.
        $csv = $this->csv("No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento\n1,\"Sala X\",\"Dir\",\"{$distrito}\",\"{$municipio}\",\"{$departamento}\"\n");
        app(ImportadorSalas::class)->importar($cliente, $csv);

        return $cliente->refresh()->sucursales()->where('nombre', 'Sala X')->firstOrFail();
    }

    public function test_san_salvador_centro_se_mapea(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'San Salvador Centro', '', 'San Salvador');
        $this->assertSame('San Salvador', Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_santa_ana_centro_se_mapea_a_santa_ana(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'Santa Ana Centro', '', 'Santa Ana');
        $this->assertSame('Santa Ana', Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_san_miguel_centro_con_coma_se_mapea(): void
    {
        // Municipio escrito como "San ,Miguel Centro".
        $sucursal = $this->importarUnaSala($this->calleja(), 'San ,Miguel Centro', '', 'San Miguel');
        $this->assertSame('San Miguel', Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_sonsonate_centro_se_mapea(): void
    {
        $sucursal = $this->importarUnaSala($this->calleja(), 'Sonsonate Centro', '', 'Sonsonate');
        $this->assertSame('Sonsonate', Municipio::find($sucursal->municipio_id)?->nombre);
    }

    public function test_distrito_tiene_prioridad_sobre_municipio_nuevo(): void
    {
        // Distrito = Santa Tecla, Municipio = La Libertad Sur → usa Santa Tecla (distrito).
        $sucursal = $this->importarUnaSala($this->calleja(), 'La Libertad Sur', 'Santa Tecla', 'La Libertad');
        $this->assertSame('Santa Tecla', Municipio::find($sucursal->municipio_id)?->nombre);
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

    // --- Distrito (CAT-008): el CCF v4 lo exige en la sala ---

    private function clienteFicticio(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Distribuidora Ficticia, S.A. de C.V.']);
    }

    /**
     * Importa una fila y devuelve el resultado y la sala guardada.
     *
     * @return array{0: ResultadoImportacion, 1: ClienteSucursal}
     */
    private function importarFila(Cliente $cliente, string $distrito, string $municipio, string $departamento, string $nombre = 'Sala Prueba'): array
    {
        $csv = $this->csv("No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento\n1,\"{$nombre}\",\"Calle Ficticia 1\",\"{$distrito}\",\"{$municipio}\",\"{$departamento}\"\n");
        $resultado = app(ImportadorSalas::class)->importar($cliente, $csv);

        return [$resultado, $cliente->sucursales()->where('nombre', $nombre)->firstOrFail()];
    }

    public function test_sala_con_distrito_en_el_archivo_queda_enlazada(): void
    {
        [$resultado, $sala] = $this->importarFila($this->clienteFicticio(), 'Santa Tecla', 'La Libertad Sur', 'La Libertad');

        $this->assertSame('Santa Tecla', $sala->distrito?->nombre);
        $this->assertSame('La Libertad', $sala->departamento?->nombre);
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala));
        $this->assertSame(0, $resultado->sinDistrito);
    }

    public function test_sin_distrito_el_municipio_viejo_que_hoy_es_distrito_lo_enlaza(): void
    {
        // Antes de 2024 «Soyapango» era municipio; hoy es distrito de San Salvador Este.
        [, $sala] = $this->importarFila($this->clienteFicticio(), '', 'Soyapango', 'San Salvador');

        $this->assertSame('Soyapango', $sala->distrito?->nombre);
        $this->assertSame('San Salvador Este', $sala->distrito?->municipio);
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala));
    }

    public function test_el_distrito_completa_el_municipio_y_el_departamento_que_faltan(): void
    {
        // «Guacotecti» no está en el catálogo de municipios y es un nombre único en el país.
        [$resultado, $sala] = $this->importarFila($this->clienteFicticio(), 'Guacotecti', '', '');

        $this->assertSame('Guacotecti', $sala->distrito?->nombre);
        $this->assertSame('Cabañas', $sala->departamento?->nombre);
        $this->assertSame('Cabañas Este', $sala->municipio?->nombreFiscal());
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala));
        $this->assertStringContainsString('Municipio tomado del distrito: Guacotecti', $resultado->detalles[0]['detalle']);
    }

    public function test_distrito_de_otro_departamento_no_se_enlaza(): void
    {
        // «Santa Tecla» es de La Libertad; la fila dice San Salvador.
        [$resultado, $sala] = $this->importarFila($this->clienteFicticio(), 'Santa Tecla', 'San Salvador Centro', 'San Salvador');

        $this->assertNull($sala->distrito_id);
        $this->assertSame('San Salvador', $sala->departamento?->nombre);
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala), 'Nunca se guarda un trío incoherente.');
        $detalle = $resultado->detalles[0]['detalle'];
        $this->assertStringContainsString('Distrito «Santa Tecla» no está en el departamento indicado', $detalle);
        $this->assertStringContainsString('Sin distrito: completalo en la sala antes de facturar (el CCF lo exige)', $detalle);
    }

    public function test_trio_incoherente_queda_sin_distrito_y_con_advertencia(): void
    {
        // «Ilobasco» es distrito de Cabañas Oeste, no del municipio Cabañas Este que dice la fila.
        [$resultado, $sala] = $this->importarFila($this->clienteFicticio(), 'Ilobasco', 'Cabañas Este', 'Cabañas');

        $this->assertNull($sala->distrito_id);
        $this->assertSame('Cabañas Este', $sala->municipio?->nombreFiscal());
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala), 'Nunca se guarda un trío incoherente.');
        $detalle = $resultado->detalles[0]['detalle'];
        $this->assertStringContainsString('Distrito no enlazado: El distrito «Ilobasco» pertenece a «Cabañas Oeste», no al municipio «Cabañas Este»', $detalle);
        $this->assertStringContainsString('Sin distrito: completalo en la sala antes de facturar (el CCF lo exige)', $detalle);
        $this->assertSame(1, $resultado->sinDistrito);
    }

    public function test_sala_existente_no_pierde_su_distrito_si_la_fila_no_trae_uno(): void
    {
        $cliente = $this->clienteFicticio();
        [, $sala] = $this->importarFila($cliente, 'Soyapango', 'San Salvador Este', 'San Salvador');
        $distritoId = $sala->distrito_id;
        $this->assertNotNull($distritoId);

        // «San Salvador Centro» agrupa varios distritos: con esa fila no hay distrito que enlazar.
        [$resultado, $sala] = $this->importarFila($cliente, '', 'San Salvador Centro', 'San Salvador');

        $this->assertSame(1, $resultado->actualizadas);
        $this->assertSame($distritoId, $sala->distrito_id);
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala), 'Nunca se guarda un trío incoherente.');
        $this->assertSame(0, $resultado->sinDistrito);
        $this->assertStringNotContainsString('Sin distrito', $resultado->detalles[0]['detalle']);
    }

    public function test_resumen_cuenta_las_salas_sin_distrito(): void
    {
        $cliente = $this->clienteFicticio();
        $archivo = UploadedFile::fake()->createWithContent('salas.csv', <<<'CSV'
        No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento
        1,Sala Prueba Uno,Calle 1,Santa Tecla,La Libertad Sur,La Libertad
        2,Sala Prueba Dos,Calle 2,,San Salvador Centro,San Salvador
        3,Sala Prueba Tres,Calle 3,Distrito Inventado,Pueblo Inventado,Departamento Inventado
        CSV);
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)
            ->post(route('importaciones.salas.importar'), ['cliente_id' => $cliente->id, 'archivo' => $archivo])
            ->assertSessionHas('resumen.sin_distrito', 2);

        $this->actingAs($admin)
            ->get(route('importaciones.index'))
            ->assertOk()
            ->assertSeeText('Sin distrito: 2');
    }

    public function test_distrito_ambiguo_sin_departamento_no_se_enlaza(): void
    {
        // «San Isidro» es distrito de Cabañas y de Morazán.
        $cliente = $this->clienteFicticio();
        [$resultado, $sala] = $this->importarFila($cliente, 'San Isidro', '', '');

        $this->assertNull($sala->distrito_id);
        $this->assertStringContainsString('Distrito ambiguo: «San Isidro» existe en más de un departamento', $resultado->detalles[0]['detalle']);

        // Con el departamento deja de ser ambiguo y queda enlazado.
        [, $otra] = $this->importarFila($cliente, 'San Isidro', '', 'Cabañas', 'Sala Prueba Dos');
        $this->assertSame('Cabañas Este', $otra->distrito?->municipio);
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

        $empresa = Empresa::create(['razon_social' => 'X', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);

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
        $this->actingAs($this->usuario('jefatura'))->get(route('importaciones.index'))->assertForbidden();
        $this->actingAs($this->usuario('administrador'))->get(route('importaciones.index'))->assertOk();
    }

    public function test_solo_admin_descarga_plantilla_de_salas(): void
    {
        $this->get(route('importaciones.salas.plantilla'))->assertRedirect('/login');
        $this->actingAs($this->usuario('jefatura'))->get(route('importaciones.salas.plantilla'))->assertForbidden();

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
