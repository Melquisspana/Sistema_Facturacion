<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use App\Support\HoraNegocio;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Listas de empaque de punta a punta, como las usa la oficina y por las mismas rutas
 * que la pantalla: armar la lista desde el formulario, facturarla, finalizarla y
 * duplicarla para el próximo embarque. Además se intenta romper lo que rodea a
 * «Duplicar»: dos clics seguidos, una lista archivada, editar la copia con las líneas
 * de otra lista y cambiar el catálogo cuando ya hay listas hechas. Datos ficticios.
 */
class ListaEmpaqueRecorridoOficinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', HoraNegocio::zona()));
        config(['exportaciones.fda_reg_number' => '', 'exportaciones.exportador_nombre' => '', 'exportaciones.exportador_direccion' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** Emisor con correlativo de FEX y catálogos de Hacienda: lo que pide «Facturar». */
    private function emisorParaFacturar(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function perfil(): ExportacionCliente
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);

        return ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
    }

    private function presentacion(string $nombre = 'Producto de prueba'): ExportacionProducto
    {
        return app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => $nombre, 'nombre_en' => 'Test product', 'categoria' => null],
            ['unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        )->presentaciones()->sole();
    }

    /** Lo que manda el formulario «Nueva lista de empaque» al guardar. */
    private function formularioNuevaLista(ExportacionCliente $perfil, array $items): array
    {
        return [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'Cliente de prueba',
            'cliente_direccion' => 'Dirección de prueba',
            'exportador_nombre' => 'Exportador de prueba',
            'exportador_direccion' => 'Dirección del exportador de prueba',
            'fda_reg_number' => '00000000001',
            'fecha' => '2026-11-20',
            'items' => $items,
        ];
    }

    private function armarLista(User $quien, ExportacionCliente $perfil, array $items): Exportacion
    {
        $antes = Exportacion::query()->pluck('id');
        $this->actingAs($quien)->post(route('facturacion.listas.store'), $this->formularioNuevaLista($perfil, $items))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Exportacion::query()->whereNotIn('id', $antes)->sole();
    }

    /** Lo que importa de cada línea: su copia del catálogo, sin ids ni fechas. */
    private function lineas(Exportacion $lista): array
    {
        return $lista->items()->orderBy('nombre_es')->orderBy('id')->get()
            ->map(fn (ExportacionItem $i) => collect($i->getAttributes())->except(['id', 'exportacion_id', 'created_at', 'updated_at'])->all())
            ->all();
    }

    private function idsEn(User $quien, string $estado = 'abiertas'): array
    {
        /** @var LengthAwarePaginator $listas */
        $listas = $this->actingAs($quien)->get(route('facturacion.listas.index', ['estado' => $estado]))->assertOk()->viewData('listas');

        return $listas->getCollection()->pluck('id')->all();
    }

    private function duplicar(User $quien, Exportacion $lista): Exportacion
    {
        $antes = Exportacion::query()->pluck('id');
        $this->actingAs($quien)->post(route('facturacion.listas.duplicar', $lista))
            ->assertSessionHas('status')
            ->assertRedirect();

        return Exportacion::query()->whereNotIn('id', $antes)->sole();
    }

    public function test_armar_facturar_finalizar_y_duplicar_deja_una_copia_en_borrador_que_se_factura_aparte(): void
    {
        $this->emisorParaFacturar();
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 140.00, 'activo' => true]);

        // 1. Armar la lista desde el formulario: el precio sale de la lista del cliente.
        $lista = $this->armarLista($usuario, $perfil, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->estado);
        $this->assertEquals(140.00, (float) $lista->items()->sole()->precio_caja);

        // 2. Facturarla y 3. finalizarla.
        $this->actingAs($usuario)->post(route('facturacion.listas.facturar-rapido', $lista))->assertRedirect();
        $fexOriginal = Dte::query()->where('tipo_dte', TipoDte::FacturaExportacion->value)->sole();
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('status');
        $lista->refresh();
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $lista->estado);
        $lineasOriginales = $this->lineas($lista);

        // Archivarla por la dirección vieja no hace nada: 409 y la lista sigue igual.
        $this->actingAs($usuario)->patch(route('exportaciones.archivar', $lista))->assertStatus(409);
        $this->assertFalse($lista->fresh()->archivada);

        // 4. Duplicarla para el próximo embarque.
        $copia = $this->duplicar($usuario, $lista);
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $copia->estado);
        $this->assertTrue($copia->puedeEditarse());
        $this->assertFalse($copia->archivada);
        $this->assertNull($copia->dte_id);
        $this->assertNull($copia->factura);
        $this->assertCount(0, $copia->dtes);
        $this->assertSame('2026-11-25', $copia->fecha->toDateString());
        $this->assertEquals($lineasOriginales, $this->lineas($copia));
        $this->assertContains($copia->id, $this->idsEn($usuario));
        $this->assertNotContains($lista->id, $this->idsEn($usuario));
        $this->actingAs($usuario)->get(route('facturacion.listas.edit', $copia))->assertOk();

        // Editar la copia (más cajas) no toca la original.
        $linea = $copia->items()->sole();
        $this->actingAs($usuario)->put(route('facturacion.listas.update', $copia), $this->formularioNuevaLista($perfil, [
            ['id' => $linea->id, 'cantidad_cajas' => 25, 'precio_caja' => '140.00'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(25, (int) $linea->fresh()->cantidad_cajas);
        $this->assertEquals($lineasOriginales, $this->lineas($lista->fresh()));

        // La copia se factura con su PROPIA FEX: no reusa la de la original.
        $this->actingAs($usuario)->post(route('facturacion.listas.facturar-rapido', $copia))->assertRedirect();
        $fexCopia = Dte::query()->where('tipo_dte', TipoDte::FacturaExportacion->value)->whereKeyNot($fexOriginal->id)->sole();
        $this->assertSame($fexCopia->id, $copia->fresh()->dte_id);
        $this->assertSame([$fexOriginal->id], $lista->fresh()->dtes->pluck('id')->all());
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $lista->fresh()->estado);
    }

    /**
     * Dos clics seguidos en «Duplicar» crean dos copias (igual que al duplicar un CCF;
     * frenarlo es una decisión pendiente). Lo que no puede pasar es que se mezclen:
     * cada copia tiene sus propias líneas y la original no cambia.
     */
    public function test_duplicar_dos_veces_seguidas_deja_dos_borradores_independientes(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $lista = $this->armarLista($usuario, $perfil, [['exportacion_producto_id' => $this->presentacion()->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $lineasOriginales = $this->lineas($lista);

        $primera = $this->duplicar($usuario, $lista);
        $segunda = $this->duplicar($usuario, $lista);

        foreach ([$primera, $segunda] as $copia) {
            $this->assertSame(Exportacion::ESTADO_BORRADOR, $copia->estado);
            $this->assertEquals($lineasOriginales, $this->lineas($copia));
        }
        $this->assertNotEquals($primera->items()->sole()->id, $segunda->items()->sole()->id);
        $this->assertSame(3, ExportacionItem::count());
        $this->assertEquals($lineasOriginales, $this->lineas($lista->fresh()));
        $this->assertEqualsCanonicalizing([$lista->id, $primera->id, $segunda->id], $this->idsEn($usuario));
    }

    /**
     * La única forma de archivar hoy: un administrador clasifica como «archivada» una
     * lista heredada del flujo anterior. Su copia nace como lista de trabajo normal.
     */
    public function test_una_lista_heredada_que_el_administrador_archiva_se_duplica_en_borrador_visible(): void
    {
        $usuario = $this->usuario();
        $admin = $this->usuario('administrador');
        $lista = $this->armarLista($usuario, $this->perfil(), [['exportacion_producto_id' => $this->presentacion()->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $lista->update(['estado' => 'aprobada', 'requiere_revision' => true, 'revision_motivo' => 'Estado del flujo anterior']);

        $this->actingAs($admin)->post(route('facturacion.listas.resolver-revision', $lista), [
            'clasificacion' => 'archivada',
            'motivo' => 'Embarque de prueba que nunca salió.',
        ])->assertSessionHas('status');
        $this->assertTrue($lista->fresh()->archivada);

        $copia = $this->duplicar($usuario, $lista->fresh());

        $this->assertSame(Exportacion::ESTADO_BORRADOR, $copia->estado);
        $this->assertFalse($copia->archivada);
        $this->assertFalse($copia->requiereRevision());
        $this->assertNull($copia->revision_resolucion);
        $this->assertNull($copia->revision_motivo);
        $this->assertNull($copia->dte_id);
        $this->assertContains($copia->id, $this->idsEn($usuario));
        $this->assertNotContains($lista->id, $this->idsEn($usuario));
        $this->assertContains($lista->id, $this->idsEn($usuario, 'archivadas'));
        $this->actingAs($usuario)->get(route('facturacion.listas.edit', $copia))->assertOk();
    }

    /**
     * Intento de romperla: guardar la copia mandando las líneas de OTRA lista (la
     * original ya finalizada). Lo seguro es negarlo sin tocar nada; antes la copia se
     * quedaba sin ningún producto y la pantalla decía «actualizada».
     */
    public function test_guardar_una_lista_con_lineas_de_otra_lista_se_niega_y_no_toca_ninguna(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $original = $this->armarLista($usuario, $perfil, [['exportacion_producto_id' => $this->presentacion()->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $original->update(['estado' => Exportacion::ESTADO_FINALIZADA, 'finalizada_en' => now()]);
        $copia = $this->duplicar($usuario, $original);
        $lineasOriginal = $this->lineas($original);
        $lineasCopia = $this->lineas($copia);

        $this->actingAs($usuario)->put(route('facturacion.listas.update', $copia), $this->formularioNuevaLista($perfil, [
            ['id' => $original->items()->sole()->id, 'cantidad_cajas' => 99, 'precio_caja' => '1.00'],
        ]))->assertSessionHasErrors('items');

        $this->assertEquals($lineasCopia, $this->lineas($copia->fresh()));
        $this->assertEquals($lineasOriginal, $this->lineas($original->fresh()));
    }

    /**
     * Cambiar una presentación (precio por caja y pesos) cuando ya hay listas hechas:
     * las listas guardan su copia —también en la impresión—, la próxima lista sale con
     * el dato nuevo, y hoy una COPIA de la lista vieja conserva la copia vieja.
     */
    public function test_cambiar_una_presentacion_no_cambia_las_listas_hechas_y_la_proxima_lleva_el_dato_nuevo(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        $vieja = $this->armarLista($usuario, $perfil, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $vieja->update(['estado' => Exportacion::ESTADO_FINALIZADA, 'finalizada_en' => now()]);
        $lineasViejas = $this->lineas($vieja);
        $this->assertEquals(13.0, (float) $vieja->items()->sole()->peso_neto_caja_kg);

        $this->actingAs($usuario)->put(route('productos.exportacion.update', $producto), [
            'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85,
            'peso_neto_caja_kg' => 15.5, 'peso_bruto_caja_kg' => '', 'precio_caja' => 150,
        ])->assertSessionHasNoErrors();

        $this->assertEquals($lineasViejas, $this->lineas($vieja->fresh()));
        $this->actingAs($usuario)->get(route('facturacion.listas.imprimir', $vieja))->assertOk()
            ->assertSee('136.80')->assertDontSee('150.00');

        $nueva = $this->armarLista($usuario, $perfil, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $linea = $nueva->items()->sole();
        $this->assertEquals(150.00, (float) $linea->precio_caja);
        $this->assertEquals(15.5, (float) $linea->peso_neto_caja_kg);
        $this->assertEquals(16.5, (float) $linea->peso_bruto_caja_kg);

        $copia = $this->duplicar($usuario, $vieja->fresh());
        $this->assertEquals($lineasViejas, $this->lineas($copia));
    }

    /**
     * FDA y exportador guardados en Configuración: el FDA de la copia es el de hoy,
     * porque en la lista no se puede escribir. El nombre del exportador, que sí se ve y
     * se cambia en el formulario, hoy sale de la lista vieja.
     */
    public function test_el_fda_guardado_sale_en_la_copia_y_el_exportador_conserva_el_de_la_lista_vieja(): void
    {
        $usuario = $this->usuario();
        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000001');
        $vieja = $this->armarLista($usuario, $this->perfil(), [['exportacion_producto_id' => $this->presentacion()->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000002');
        Ajustes::guardarComoSistema('exportaciones.exportador_nombre', 'Exportador nuevo de prueba');

        $copia = $this->duplicar($usuario, $vieja);

        $this->assertSame('00000000002', $copia->fda_reg_number);
        $this->assertSame('Exportador de prueba', $copia->exportador_nombre);
        $this->assertSame('00000000001', $vieja->fresh()->fda_reg_number);
        $this->actingAs($usuario)->get(route('facturacion.listas.imprimir', $copia))->assertOk()->assertSee('00000000002');
    }

    /**
     * Intento de romperla: el FDA y el número de factura no se escriben en la lista, pero
     * el campo oculto se puede cambiar a mano o venir de un formulario viejo abierto en
     * otra pestaña. Al crear, el FDA sale de Configuración y la factura queda vacía; al
     * guardar un borrador, la lista conserva los suyos.
     */
    public function test_el_fda_y_la_factura_que_manda_el_navegador_no_se_guardan(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        $tecleado = ['fda_reg_number' => '99999999999', 'factura' => 'DTE-11-M001P001-000000000000777'];
        $items = [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => '']];

        // Sin nada en Configuración, el FDA queda vacío.
        $antes = Exportacion::query()->pluck('id');
        $this->actingAs($usuario)->post(route('facturacion.listas.store'), $tecleado + $this->formularioNuevaLista($perfil, $items))
            ->assertSessionHasNoErrors();
        $sinConfigurar = Exportacion::query()->whereNotIn('id', $antes)->sole();
        $this->assertNull($sinConfigurar->fda_reg_number);
        $this->assertNull($sinConfigurar->factura);

        // Con el FDA en Configuración, la lista lleva ese y no el tecleado.
        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000002');
        $antes = Exportacion::query()->pluck('id');
        $this->actingAs($usuario)->post(route('facturacion.listas.store'), $tecleado + $this->formularioNuevaLista($perfil, $items))
            ->assertSessionHasNoErrors();
        $lista = Exportacion::query()->whereNotIn('id', $antes)->sole();
        $this->assertSame('00000000002', $lista->fda_reg_number);
        $this->assertNull($lista->factura);
        $this->actingAs($usuario)->get(route('facturacion.listas.imprimir', $lista))->assertOk()
            ->assertSee('00000000002')->assertDontSee('99999999999')->assertDontSee('000000000000777');

        // Un borrador con su FDA y su texto de factura históricos los conserva al guardarse.
        $lista->update(['fda_reg_number' => '00000000001', 'factura' => 'Factura anterior de prueba 12']);
        $this->actingAs($usuario)->put(route('facturacion.listas.update', $lista), $tecleado + $this->formularioNuevaLista($perfil, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 12, 'precio_caja' => ''],
        ]))->assertSessionHasNoErrors();
        $lista->refresh();
        $this->assertSame(12, (int) $lista->items()->sole()->cantidad_cajas);
        $this->assertSame('00000000001', $lista->fda_reg_number);
        $this->assertSame('Factura anterior de prueba 12', $lista->factura);
    }

    public function test_duplicar_no_crea_documentos_ni_gasta_numeros(): void
    {
        $this->emisorParaFacturar();
        $usuario = $this->usuario();
        $lista = $this->armarLista($usuario, $this->perfil(), [['exportacion_producto_id' => $this->presentacion()->id, 'cantidad_cajas' => 10, 'precio_caja' => '']]);
        $this->actingAs($usuario)->post(route('facturacion.listas.facturar-rapido', $lista))->assertRedirect();
        $dtes = Dte::count();
        $vinculos = DB::table('exportacion_dte')->count();
        $numero = (int) Correlativo::query()->where('tipo_dte', '11')->value('ultimo_numero');

        $this->duplicar($usuario, $lista->fresh());

        $this->assertSame($dtes, Dte::count());
        $this->assertSame($vinculos, DB::table('exportacion_dte')->count());
        $this->assertSame($numero, (int) Correlativo::query()->where('tipo_dte', '11')->value('ultimo_numero'));
    }
}
