<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\ListaEmpaqueExcelBuilder;
use App\Services\Exportaciones\ListaEmpaqueExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El Excel de la lista de empaque se genera SIN PLANTILLA.
 *
 * Antes se cargaba `storage/app/templates/exportaciones/lista_empaque.xlsx` y se
 * rellenaban sus celdas. Ese archivo no está en el disco ni en el repositorio, así
 * que la única salida del módulo llevaba meses lanzando una excepción que nadie
 * veía porque nunca se creó una lista. Un formato que depende de un binario no
 * versionado no es reproducible ni se puede probar.
 *
 * Estas pruebas fijan la estructura real del archivo —celdas concretas, textos,
 * cantidades y fórmulas— para que el formato no se pueda romper en silencio.
 */
class ListaEmpaqueExcelTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{establecimiento_id: int, punto_venta_id: int} */
    private function emisor(): array
    {
        // Propiedad de instancia y NO `static`: PHPUnit reutiliza el proceso pero
        // RefreshDatabase vacía la base entre pruebas, así que unos ids cacheados en
        // una estática apuntarían a filas que ya no existen y la FK reventaría en la
        // segunda prueba del archivo.
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function lista(array $extra = []): Exportacion
    {
        $lista = Exportacion::create($extra + [
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'cliente_direccion' => '11235 SOMERSET, BELTSVILLE, MD 20705 EEUU',
            'exportador_nombre' => 'ELSA FIDELINA HERNANDEZ DE ESPAÑA',
            'exportador_direccion' => 'Hacienda Santa Barbara, Olocuilta, La Paz',
            'fda_reg_number' => '12015435846',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $lista->items()->create([
            'nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy box', 'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 144.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        $lista->items()->create([
            'nombre_es' => 'Caja de nance', 'nombre_en' => 'Yellow cherry candy', 'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 216, 'cantidad_cajas' => 4, 'precio_caja' => 172.80,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        return $lista->fresh();
    }

    /** Genera el archivo y devuelve la hoja para inspeccionarla celda a celda. */
    private function hojaDe(Exportacion $lista): Worksheet
    {
        $ruta = app(ListaEmpaqueExcelService::class)->generar($lista);
        $this->assertFileExists($ruta);
        $this->assertGreaterThan(0, filesize($ruta), 'el archivo no puede salir vacío');

        $hoja = IOFactory::load($ruta)->getSheetByName('Lista');
        @unlink($ruta);

        $this->assertNotNull($hoja, 'el libro debe traer la hoja «Lista»');

        return $hoja;
    }

    // --------------------------------------------------- sin plantilla en el disco

    public function test_se_genera_aunque_no_exista_ninguna_plantilla(): void
    {
        // Se apunta a propósito a un archivo que no existe: el generador ya no lo lee.
        config(['exportaciones.plantilla' => 'templates/exportaciones/no-existe.xlsx']);

        $hoja = $this->hojaDe($this->lista());

        $this->assertSame('LISTA DE EMPAQUE / PACKING LIST', $hoja->getCell('B1')->getValue());
    }

    public function test_el_encabezado_lleva_exportador_cliente_fecha_y_fda(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame('ELSA FIDELINA HERNANDEZ DE ESPAÑA', $hoja->getCell('C2')->getValue());
        $this->assertSame('Hacienda Santa Barbara, Olocuilta, La Paz', $hoja->getCell('C3')->getValue());
        $this->assertSame('CAROLINAS WHOLESALE LLC', $hoja->getCell('L2')->getValue());
        $this->assertSame('11235 SOMERSET, BELTSVILLE, MD 20705 EEUU', $hoja->getCell('L3')->getValue());

        // El FDA va como TEXTO: un registro que empiece por cero no puede perderlo.
        $this->assertSame('12015435846', $hoja->getCell('L5')->getValue());
        $this->assertIsString($hoja->getCell('L5')->getValue());

        // La fecha va como fecha real de Excel, no como cadena.
        $this->assertIsNumeric($hoja->getCell('C5')->getValue());
    }

    public function test_las_cabeceras_de_columna_son_bilingues(): void
    {
        $hoja = $this->hojaDe($this->lista());

        $this->assertSame('CAJAS', $hoja->getCell('B7')->getValue());
        $this->assertSame('BOXES', $hoja->getCell('B8')->getValue());
        $this->assertSame('DESCRIPCIÓN', $hoja->getCell('C7')->getValue());
        $this->assertSame('DESCRIPTION', $hoja->getCell('C8')->getValue());
        $this->assertSame('TOTAL BRUTO LB', $hoja->getCell('T7')->getValue());
    }

    public function test_cada_producto_ocupa_su_fila_con_sus_cantidades_y_sus_formulas(): void
    {
        $hoja = $this->hojaDe($this->lista());

        // Primera fila de producto: la 9, igual que en el formato histórico.
        $this->assertSame(10, $hoja->getCell('B9')->getValue());
        $this->assertSame('Caja de camote \\ Sweet potato candy box', $hoja->getCell('C9')->getValue());
        $this->assertSame('Bolsa 12X18', $hoja->getCell('D9')->getValue());
        $this->assertSame(144, $hoja->getCell('E9')->getValue());
        $this->assertEqualsWithDelta(144.00, (float) $hoja->getCell('J9')->getValue(), 0.001);
        $this->assertEqualsWithDelta(19.40, (float) $hoja->getCell('L9')->getValue(), 0.001);

        // Segunda fila.
        $this->assertSame(4, $hoja->getCell('B10')->getValue());
        $this->assertSame('Caja de nance \\ Yellow cherry candy', $hoja->getCell('C10')->getValue());

        // Las FÓRMULAS se conservan: el archivo se edita a mano en Excel y cambiar las
        // cajas tiene que recalcular los totales. Un archivo de solo valores rompería eso.
        $this->assertSame('=B9*E9', $hoja->getCell('I9')->getValue());
        $this->assertSame('=B9*J9', $hoja->getCell('K9')->getValue());
        $this->assertSame('=B10*R10', $hoja->getCell('T10')->getValue());
    }

    public function test_la_fila_de_totales_suma_el_rango_real_de_productos(): void
    {
        $hoja = $this->hojaDe($this->lista());

        // Dos productos -> filas 9 y 10 -> totales en la 11.
        $this->assertSame('TOTALES / TOTALS', $hoja->getCell('C11')->getValue());
        $this->assertSame('=SUM(B9:B10)', $hoja->getCell('B11')->getValue());
        $this->assertSame('=SUM(K9:K10)', $hoja->getCell('K11')->getValue());
        $this->assertSame('=SUM(T9:T10)', $hoja->getCell('T11')->getValue());
    }

    public function test_una_lista_larga_extiende_el_rango_de_la_suma(): void
    {
        $lista = $this->lista();

        for ($i = 0; $i < 30; $i++) {
            $lista->items()->create([
                'nombre_es' => 'Extra '.$i, 'nombre_en' => 'Extra '.$i, 'unidad' => 'Bolsa',
                'unidades_por_caja' => 100, 'cantidad_cajas' => 1, 'precio_caja' => 10,
                'gramos_por_unidad' => 10, 'onzas_por_unidad' => 1,
                'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 2,
                'peso_neto_caja_lb' => 2, 'peso_bruto_caja_lb' => 4,
            ]);
        }

        // 32 productos: filas 9..40, totales en la 41. El formato anterior sólo tenía
        // 24 filas y había que insertar filas a mano dentro del rango para no romper
        // los =SUM; generándolo desde cero el problema no existe.
        $hoja = $this->hojaDe($lista->fresh());

        $this->assertSame('Extra 29 \ Extra 29', $hoja->getCell('C40')->getValue());
        $this->assertSame('TOTALES / TOTALS', $hoja->getCell('C41')->getValue());
        $this->assertSame('=SUM(B9:B40)', $hoja->getCell('B41')->getValue());
    }

    // ------------------------------------------------------- una y varias facturas

    public function test_la_casilla_de_factura_trae_el_numero_del_dte(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $lista = $this->lista(['exportacion_cliente_id' => $perfil->id, 'factura' => 'TEXTO VIEJO']);

        $fex = Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'cliente_id' => $cliente->id,
            'estado' => 'generado', 'ambiente' => '00', 'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00',
            'numero_control' => 'DTE-11-M001P001-000000000000001', 'total_pagar' => 100,
        ]);
        $lista->dtes()->attach($fex->id, ['principal' => true]);

        $hoja = $this->hojaDe($lista->fresh());

        $this->assertSame('DTE-11-M001P001-000000000000001', $hoja->getCell('C4')->getValue());
    }

    public function test_con_varias_facturas_la_casilla_las_lista_todas(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $lista = $this->lista(['exportacion_cliente_id' => $perfil->id]);

        foreach (['000000000000001', '000000000000002'] as $i => $sufijo) {
            $fex = Dte::create($this->emisor() + [
                'tipo_dte' => TipoDte::FacturaExportacion->value, 'cliente_id' => $cliente->id,
                'estado' => 'generado', 'ambiente' => '00', 'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00',
                'numero_control' => 'DTE-11-M001P001-'.$sufijo, 'total_pagar' => 100,
            ]);
            $lista->dtes()->attach($fex->id, ['principal' => $i === 0]);
        }

        $hoja = $this->hojaDe($lista->fresh());

        $this->assertSame(
            'DTE-11-M001P001-000000000000001 · DTE-11-M001P001-000000000000002',
            $hoja->getCell('C4')->getValue()
        );
    }

    // -------------------------------------------------------- fallos y descarga

    public function test_una_lista_sin_productos_no_genera_archivo_y_lo_dice(): void
    {
        $lista = Exportacion::create([
            'cliente_nombre' => 'CLIENTE', 'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista))
            ->assertSessionHas('error');
    }

    /**
     * Un fallo de almacenamiento se ve como un mensaje, nunca como una descarga
     * vacía: un .xlsx de 0 bytes se abre como «archivo dañado» y manda a buscar el
     * problema al lado equivocado.
     */
    public function test_un_fallo_de_almacenamiento_se_muestra_y_no_devuelve_una_descarga_vacia(): void
    {
        $lista = $this->lista();

        $this->app->bind(ListaEmpaqueExcelService::class, fn () => new class(app(ListaEmpaqueExcelBuilder::class)) extends ListaEmpaqueExcelService
        {
            public function generar(Exportacion $exportacion): string
            {
                throw new \RuntimeException('El Excel de la lista de empaque se generó vacío y no se entregó.');
            }
        });

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista));

        $this->assertStringContainsString('se generó vacío', session('error'));
    }

    public function test_la_descarga_entrega_un_xlsx_con_nombre_util(): void
    {
        $lista = $this->lista();

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.excel', $lista))
            ->assertOk();

        $this->assertStringContainsString(
            'lista-empaque-'.$lista->id.'-2026-09-01.xlsx',
            $resp->headers->get('content-disposition')
        );
    }

    // ----------------------------------------------------------------- imprimible

    public function test_la_version_imprimible_trae_los_datos_y_los_totales(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario())->get(route('facturacion.listas.imprimir', $lista))->assertOk()
            ->assertSee('LISTA DE EMPAQUE / PACKING LIST')
            ->assertSee('CAROLINAS WHOLESALE LLC')
            ->assertSee('Caja de camote \\ Sweet potato candy box')
            ->assertSee('12015435846')
            // Totales: 10 + 4 cajas.
            ->assertSee('TOTALES / TOTALS')
            ->assertSee('14');
    }
}
