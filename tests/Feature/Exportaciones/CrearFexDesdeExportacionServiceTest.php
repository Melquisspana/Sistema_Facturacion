<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoCliente;
use App\Enums\TipoDte;
use App\Exceptions\Exportaciones\FexYaExisteException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionItem;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integración completa Lista de Empaque -> Factura de Exportación (FEX):
 * CrearFexDesdeExportacionService, la acción web, la interfaz y el snapshot
 * independiente. NO firma, NO transmite, NO consume correlativo, NO envía correo.
 */
class CrearFexDesdeExportacionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    /** @return array{clienteDte: Cliente, clienteExpo: ExportacionCliente} */
    private function clienteVinculado(): array
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create([
            'nombre' => 'CAROLINAS WHOLESALE LLC',
            'direccion' => '11235 SOMERSET, BELTSVILLE, MD 20705 EEUU',
            'cliente_id' => $clienteDte->id,
            'activo' => true,
        ]);

        return compact('clienteDte', 'clienteExpo');
    }

    private function item(Exportacion $e, array $extra = []): ExportacionItem
    {
        return $e->items()->create($extra + [
            'nombre_es' => 'Canillitas 85 g',
            'nombre_en' => 'Little canes 85 g',
            'unidad' => 'Bolsa',
            'unidades_por_caja' => 144,
            'cantidad_cajas' => 10,
            'precio_caja' => 18.00,
            'gramos_por_unidad' => 85,
            'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12,
            'peso_bruto_caja_kg' => 13,
            'peso_neto_caja_lb' => 26,
            'peso_bruto_caja_lb' => 28,
        ]);
    }

    private function lista(ExportacionCliente $clienteExpo, string $estado = 'aprobada'): Exportacion
    {
        return Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id,
            'cliente_nombre' => $clienteExpo->nombre,
            'cliente_direccion' => $clienteExpo->direccion,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-17',
            'estado' => $estado,
        ]);
    }

    // ---------- 1-7, 11-13: creación completa y mapeo exacto ----------

    public function test_crea_fex_desde_lista_copiando_cliente_descripcion_cantidad_precio(): void
    {
        $this->emisor();
        ['clienteDte' => $clienteDte, 'clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame(TipoDte::FacturaExportacion, $dte->tipo_dte);
        $this->assertSame($clienteDte->id, $dte->cliente_id);
        $this->assertCount(1, $dte->lineas);

        $linea = $dte->lineas->first();
        $this->assertNull($linea->producto_id);
        $this->assertSame('Canillitas 85 g / Little canes 85 g - 144 units', $linea->descripcion);
        $this->assertSame('10.0000', $linea->cantidad); // cajas, NO cajas*unidades_por_caja
        $this->assertSame('18.000000', $linea->precio_unitario); // precio por CAJA, no por bolsa
        $this->assertSame('99', $linea->unidad_codigo);
        $this->assertSame('180.00', $linea->total_linea); // 10 * 18.00

        $this->assertSame(1, $dte->tipo_item_expor);
        // Recinto fiscal por defecto: San Bartolo (config('dte.exportacion.recinto_fiscal_default')).
        $this->assertSame('01', $dte->recinto_fiscal);
        $this->assertSame('EX-1', $dte->tipo_regimen);
        $this->assertSame('1000.000', $dte->regimen);
        $this->assertSame('09', $dte->cod_incoterms);
        $this->assertNotNull($dte->desc_incoterms);

        $lista->refresh();
        $this->assertSame($dte->id, $lista->dte_id);
    }

    public function test_suma_varias_lineas(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['nombre_es' => 'Canillitas 85 g', 'cantidad_cajas' => 10, 'precio_caja' => 18.00]);
        $this->item($lista, ['nombre_es' => 'Dulce de nance', 'cantidad_cajas' => 5, 'precio_caja' => 20.00]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertCount(2, $dte->lineas);
        // 180.00 + 100.00
        $this->assertSame('280.00', $dte->total_exportacion);
        $this->assertSame('280.00', $dte->total_pagar);
        $this->assertSame('0.00', $dte->iva);
    }

    // ---------- 8-10: no convertir, no precio por bolsa, no precio actual ----------

    public function test_no_convierte_cajas_a_unidades(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['cantidad_cajas' => 10, 'unidades_por_caja' => 144, 'precio_caja' => 18.00]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame('10.0000', $dte->lineas->first()->cantidad);
        $this->assertNotSame('1440.0000', $dte->lineas->first()->cantidad);
    }

    public function test_no_usa_precio_por_bolsa(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['unidades_por_caja' => 144, 'precio_caja' => 18.00]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame('18.000000', $dte->lineas->first()->precio_unitario);
    }

    public function test_no_sustituye_precio_snapshot_por_precio_actual(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $item = $this->item($lista, ['precio_caja' => 18.00]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);
        $lineaId = $dte->lineas->first()->id;

        // Cambia el precio del ITEM DESPUÉS de crear la FEX: la línea ya creada no cambia.
        $item->update(['precio_caja' => 999.99]);

        $this->assertSame('18.000000', DteLinea::find($lineaId)->precio_unitario);
    }

    // ---------- 14-19: sin correlativo, sin numeración oficial, sin firma/correo ----------

    public function test_no_consume_correlativo_ni_asigna_numeracion_oficial(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame(0, Correlativo::where('tipo_dte', '11')->value('ultimo_numero'));
        $this->assertNull($dte->numero_control);
        $this->assertNull($dte->codigo_generacion);
        $this->assertNull($dte->json_generado_path);
        $this->assertNull($dte->json_firmado_path);
        $this->assertNull($dte->sello_recepcion);
        $this->assertNull($dte->respuesta_mh);
        $this->assertNull($dte->fecha_procesamiento_mh);
    }

    public function test_no_crea_historial_de_firma_ni_transmision(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertCount(1, $dte->historial);
        $this->assertSame(\App\Enums\EstadoDte::Borrador, $dte->historial->first()->estado_nuevo);
    }

    public function test_no_envia_correo(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame(0, DteEnvio::count());
    }

    // ---------- 20-21: no duplicar / concurrencia ----------

    public function test_no_duplica_fex_para_la_misma_lista(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $primero = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->expectException(FexYaExisteException::class);
        try {
            app(CrearFexDesdeExportacionService::class)->crear($lista->fresh());
        } finally {
            $this->assertSame(1, Dte::where('tipo_dte', '11')->count());
            $this->assertSame($primero->id, $lista->fresh()->dte_id);
        }
    }

    public function test_segunda_solicitud_ve_dte_id_ya_puesto_y_no_crea_otro(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        app(CrearFexDesdeExportacionService::class)->crear($lista);
        $totalAntes = Dte::count();

        try {
            app(CrearFexDesdeExportacionService::class)->crear(Exportacion::find($lista->id));
            $this->fail('Debió lanzar FexYaExisteException.');
        } catch (FexYaExisteException $e) {
            $this->assertSame($lista->fresh()->dte_id, $e->dteId);
        }

        $this->assertSame($totalAntes, Dte::count());
    }

    // ---------- 22: rollback completo si una línea falla ----------

    public function test_rollback_completo_si_falla_una_linea(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['nombre_es' => 'Producto 1']);
        $this->item($lista, ['nombre_es' => 'Producto 2']);

        $totalDtesAntes = Dte::count();
        $totalLineasAntes = DteLinea::count();

        // Subclase real (mismas dependencias resueltas del contenedor): crearBorrador()
        // corre SIN modificar; agregarLineaLibre() falla en la SEGUNDA línea, simulando
        // un error a mitad de la copia.
        $fake = new class(
            app(\App\Services\Dte\CalculadoraDte::class),
            app(\App\Services\Dte\SnapshotProductoService::class),
            app(\App\Services\Dte\DteStateMachine::class),
            app(\App\Services\Dte\PrecioProductoResolver::class),
        ) extends DteBorradorService {
            public int $llamadas = 0;

            public function agregarLineaLibre(Dte $dte, array $datos): DteLinea
            {
                $this->llamadas++;
                if ($this->llamadas === 2) {
                    throw new \RuntimeException('Falla simulada en la segunda línea.');
                }

                return parent::agregarLineaLibre($dte, $datos);
            }
        };
        $this->app->instance(DteBorradorService::class, $fake);

        try {
            app(CrearFexDesdeExportacionService::class)->crear($lista);
            $this->fail('Debió propagar la excepción simulada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falla simulada en la segunda línea.', $e->getMessage());
        }

        // Rollback COMPLETO: ni el DTE (con su primera línea) ni la vinculación quedaron.
        $this->assertSame($totalDtesAntes, Dte::count());
        $this->assertSame($totalLineasAntes, DteLinea::count());
        $this->assertNull($lista->fresh()->dte_id);
    }

    // ---------- 23-27: bloqueos de validación ----------

    public function test_bloquea_lista_sin_lineas(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);

        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    public function test_bloquea_linea_con_cantidad_cero(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['cantidad_cajas' => 0]);

        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    /**
     * exportacion_items.precio_caja es NOT NULL en la BD (no se puede persistir
     * null); el chequeo defensivo del servicio ("no NULL ni negativo") se
     * verifica aquí por su rama alcanzable: un precio negativo.
     */
    public function test_bloquea_linea_con_precio_negativo(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista, ['precio_caja' => -5.00]);

        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    public function test_bloquea_cliente_no_vinculado(): void
    {
        $this->emisor();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'activo' => true]);
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    public function test_bloquea_cliente_dte_que_no_es_de_exportacion(): void
    {
        $this->emisor();
        $clienteDte = Cliente::factory()->contribuyente()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $this->assertSame(TipoCliente::Contribuyente, $clienteDte->tipo_cliente);
        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    // ---------- 28-29: botones en la interfaz ----------

    public function test_boton_crear_fex_no_aparece_sin_cliente_vinculado(): void
    {
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'activo' => true]);
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Cliente DTE no vinculado')
            ->assertDontSee(route('exportaciones.crear-fex', $lista), false);
    }

    public function test_boton_crear_fex_deshabilitado_sin_lineas(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('La Lista necesita productos antes de crear la FEX');
    }

    public function test_boton_crear_fex_activo_cuando_corresponde(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Crear factura de exportación')
            ->assertSee(route('exportaciones.crear-fex', $lista), false);
    }

    public function test_abrir_fex_aparece_tras_crearla(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);
        app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista->fresh()))
            ->assertOk()
            ->assertSee('Abrir factura de exportación')
            ->assertDontSee('Crear factura de exportación');
    }

    // ---------- acción web ----------

    public function test_accion_web_crea_fex_y_redirige_al_editor(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->post(route('exportaciones.crear-fex', $lista))
            ->assertRedirect()
            ->assertSessionHas('status', 'Factura de exportación creada desde la Lista de Empaque.');

        $lista->refresh();
        $this->assertNotNull($lista->dte_id);
        $this->assertSame(1, Dte::where('tipo_dte', '11')->count());
    }

    public function test_accion_web_redirige_a_fex_existente_sin_duplicar(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->actingAs($this->usuario())
            ->post(route('exportaciones.crear-fex', $lista->fresh()))
            ->assertRedirect(route('facturacion.edit', $dte));

        $this->assertSame(1, Dte::where('tipo_dte', '11')->count());
    }

    // ---------- 30-32: snapshot independiente ----------

    public function test_cambiar_cantidad_cajas_en_item_no_cambia_dte_lineas(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $item = $this->item($lista, ['cantidad_cajas' => 10]);
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);
        $lineaId = $dte->lineas->first()->id;

        $item->update(['cantidad_cajas' => 999]);

        $this->assertSame('10.0000', DteLinea::find($lineaId)->cantidad);
    }

    public function test_editar_dte_lineas_no_cambia_exportacion_items(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $item = $this->item($lista, ['cantidad_cajas' => 10, 'precio_caja' => 18.00]);
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);
        $linea = $dte->lineas->first();

        app(DteBorradorService::class)->actualizarLinea($linea, ['cantidad' => 5, 'precio_unitario' => 25]);

        $item->refresh();
        $this->assertSame(10, $item->cantidad_cajas);
        $this->assertEquals(18.00, (float) $item->precio_caja);
    }

    public function test_editar_lista_no_modifica_fex_ya_creada(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $item = $this->item($lista, ['nombre_es' => 'Original']);
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);
        $lineaId = $dte->lineas->first()->id;

        $item->update(['nombre_es' => 'Modificado']);

        $this->assertStringContainsString('Original', DteLinea::find($lineaId)->descripcion);
    }

    // ---------- 33: no se puede volver a copiar tras estar vinculada ----------

    public function test_no_vuelve_a_copiar_lineas_una_vez_vinculada(): void
    {
        $this->emisor();
        ['clienteExpo' => $clienteExpo] = $this->clienteVinculado();
        $lista = $this->lista($clienteExpo);
        $this->item($lista);
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);
        $dte->estado = \App\Enums\EstadoDte::Generado;
        $dte->save();

        $this->expectException(FexYaExisteException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista->fresh());
    }
}
