<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteStateMachine;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteExportacionUiTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PreparaEmisorDte;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
        // Necesario desde que recinto_fiscal/tipo_regimen/regimen/cod_incoterms se
        // validan contra catalogos_mh (CAT-027/028/031/033) al crear una exportación.
        $this->seed(CatalogosMhTablaSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return compact('estab', 'pv');
    }

    /**
     * Códigos REALES del catálogo oficial (importado por CatalogosMhTablaSeeder), usados
     * como default en los tests que no son sobre estos campos en sí: CAT-027 '01' Terrestre
     * San Bartolo, CAT-033 'EX-1' Exportación Definitiva, CAT-028 '1000.000' Exportación
     * Definitiva Régimen Común, CAT-031 '09' FOB-Libre a bordo.
     *
     * @param  array<string, mixed>  $override
     */
    private function datosFex(Cliente $cliente, Establecimiento $estab, PuntoVenta $pv, array $override = []): array
    {
        return array_merge([
            'tipo_dte' => '11',
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'condicion_operacion' => 1,
            'descuento_global' => 0,
            'flete' => 0,
            'seguro' => 0,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ], $override);
    }

    private function borradorFex(Cliente $cliente, Establecimiento $estab, PuntoVenta $pv, array $extra = []): Dte
    {
        return app(DteBorradorService::class)->crearBorrador(array_merge([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ], $extra));
    }

    public function test_administrador_puede_crear_exportacion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '11', 'estado' => 'borrador', 'cliente_id' => $cliente->id]);
    }

    public function test_facturacion_puede_crear_exportacion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '11']);
    }

    public function test_consulta_no_puede_crear_exportacion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();
        $consulta = $this->usuario('consulta');

        $this->actingAs($consulta)->get(route('facturacion.create-exportacion'))->assertForbidden();
        $this->actingAs($consulta)->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))->assertForbidden();

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_exportacion_exige_cliente_de_exportacion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        // Sin cliente_id.
        $datos = $this->datosFex(new Cliente(['id' => 0]), $estab, $pv);
        unset($datos['cliente_id']);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $datos)
            ->assertSessionHasErrors('cliente_id');

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_exportacion_rechaza_cliente_nacional(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $nacional = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($nacional, $estab, $pv))
            ->assertSessionHasErrors('cliente_id');

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_exportacion_no_exige_orden_de_compra(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['requiere_orden_compra' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '11', 'numero_orden_compra' => null]);
    }

    public function test_exportacion_no_aplica_retencion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['es_agente_retencion' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '11')->firstOrFail();
        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_flete_y_seguro_se_guardan_y_calculan(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv, ['flete' => 5, 'seguro' => 2]))
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '11')->firstOrFail();
        $this->assertSame('5.00', $dte->flete);
        $this->assertSame('2.00', $dte->seguro);

        // 10 × 1.15 = 11.50 exportado, IVA 0, + flete 5 + seguro 2 = 18.50.
        $producto = Producto::factory()->create(['precio_unitario' => 1.15, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        $dte->refresh();
        $this->assertSame('11.50', $dte->total_exportacion);
        $this->assertSame('0.00', $dte->iva);
        $this->assertSame('18.50', $dte->total_pagar);
    }

    public function test_agregar_producto_calcula_iva_cero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = $this->borradorFex($cliente, $estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 1.15, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 10])
            ->assertRedirect();

        $dte->refresh();
        $this->assertSame('11.50', $dte->total_exportacion);
        $this->assertSame('0.00', $dte->total_gravado);
        $this->assertSame('0.00', $dte->iva);
        $this->assertSame('11.50', $dte->total_pagar);
        $this->assertSame('11.50', $dte->lineas->first()->venta_exportacion);
        $this->assertSame('0.00', $dte->lineas->first()->iva_linea);
    }

    public function test_no_se_puede_editar_exportacion_no_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = $this->borradorFex($cliente, $estab, $pv);

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertForbidden();
    }

    // --- UX: correlativo automático, descuento/condición desde el cliente ---

    public function test_formulario_no_muestra_correlativo_ni_descuento(): void
    {
        $this->emisor();
        Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            ->assertDontSee('name="correlativo_id"', false)
            ->assertDontSee('name="descuento_global"', false)
            // Emisor único (1 establecimiento/1 PV): selects ocultos, ver DteEmisorUnicoTest.
            ->assertSee('name="flete"', false)   // flete sí se mantiene
            ->assertSee('name="seguro"', false); // seguro sí se mantiene
    }

    public function test_usa_descuento_default_del_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['descuento_global_default' => 5]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        // El descuento es PORCENTAJE (5 = 5%), no monto fijo.
        $this->assertSame('5.00', Dte::where('tipo_dte', '11')->firstOrFail()->descuento_porcentaje_aplicado);
    }

    public function test_usa_descuento_default_de_sucursal_si_existe(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['descuento_global_default' => 5]);
        $sucursal = \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'descuento_global_default' => 10]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv, ['cliente_sucursal_id' => $sucursal->id]))
            ->assertRedirect();

        $this->assertSame('10.00', Dte::where('tipo_dte', '11')->firstOrFail()->descuento_porcentaje_aplicado); // la sala manda (10%)
    }

    public function test_usa_condicion_default_del_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['condicion_operacion_default' => 1]); // Contado

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '11', 'cliente_id' => $cliente->id, 'condicion_operacion' => 1]);
    }

    public function test_exportacion_no_aplica_retencion_aunque_cliente_sea_agente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['es_agente_retencion' => true]);
        $dte = $this->borradorFex($cliente, $estab, $pv);

        $producto = Producto::factory()->create(['precio_unitario' => 50, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 10); // base 500

        $dte->refresh();
        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
        $this->assertSame('0.00', $dte->iva); // exportación: IVA 0
    }

    public function test_descuento_aplicado_se_congela_en_el_dte(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['descuento_global_default' => 7]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, $estab, $pv))
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '11')->firstOrFail();
        // El porcentaje aplicado queda registrado (7%). Cambiar el default del
        // cliente DESPUÉS no toca el valor ya guardado en el documento.
        $cliente->update(['descuento_global_default' => 99]);
        $this->assertSame('7.00', $dte->refresh()->descuento_porcentaje_aplicado);
    }

    // --- FEX habilitada operativamente: aparece como opción NORMAL, igual que CCF ---

    public function test_pagina_creacion_no_muestra_avisos_de_flujo_pendiente_ni_bloqueado(): void
    {
        $this->emisor();
        Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            // Ninguno de los avisos "en revisión" / "validada en apitest" / "producción
            // bloqueada" debe aparecer: FEX ya está habilitada al mismo nivel que CCF.
            ->assertDontSee('Flujo pendiente de validación para producción real. No emitir sin revisión técnica.')
            ->assertDontSee('Flujo validado en APITEST')
            ->assertDontSee('Producción bloqueada')
            ->assertDontSee('Validada en APITEST')
            ->assertDontSee('En revisión');
    }

    public function test_listado_muestra_fex_como_opcion_normal_sin_badge(): void
    {
        $this->emisor();

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->getContent();

        // Igual que "Nuevo CCF": el link existe, sin ningún badge/etiqueta especial.
        $this->assertStringContainsString('Nueva factura exportación', $html);
        $this->assertStringNotContainsString('En revisión', $html);
        $this->assertStringNotContainsString('Validada en APITEST', $html);
        $this->assertStringNotContainsString('Producción bloqueada', $html);
    }

    // --- Valores fiscales predeterminados (por código de catálogo, no por ID ni texto libre) ---

    /** Confirma que, dentro del <select id="$selectId">, solo la opción value="$valor" trae "selected". */
    private function assertOpcionSeleccionada(string $html, string $selectId, string $valor): void
    {
        $this->assertMatchesRegularExpression('/<select id="'.preg_quote($selectId, '/').'".*?<\/select>/s', $html);
        preg_match('/<select id="'.preg_quote($selectId, '/').'".*?<\/select>/s', $html, $bloque);
        preg_match_all('/<option value="([^"]*)"([^>]*)>/', $bloque[0], $opciones, PREG_SET_ORDER);

        $this->assertNotEmpty($opciones, "No se encontraron <option> dentro de #{$selectId}");
        foreach ($opciones as $opcion) {
            [, $valorOpcion, $atributos] = $opcion;
            if ($valorOpcion === $valor) {
                $this->assertStringContainsString('selected', $atributos, "La opción '{$valor}' de #{$selectId} debería estar preseleccionada.");
            } elseif ($valorOpcion !== '') {
                $this->assertStringNotContainsString('selected', $atributos, "La opción '{$valorOpcion}' de #{$selectId} NO debería estar preseleccionada.");
            }
        }
    }

    public function test_formulario_precarga_valores_fiscales_predeterminados_por_codigo(): void
    {
        $this->emisor();
        Cliente::factory()->exportacion()->create();

        $html = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            ->getContent();

        $this->assertOpcionSeleccionada($html, 'tipo_item_expor', '1');
        // Recinto fiscal por defecto: San Bartolo (config('dte.exportacion.recinto_fiscal_default')).
        $this->assertOpcionSeleccionada($html, 'recinto_fiscal', '01');
        $this->assertOpcionSeleccionada($html, 'tipo_regimen', 'EX-1');
        $this->assertOpcionSeleccionada($html, 'regimen', '1000.000');
        $this->assertOpcionSeleccionada($html, 'cod_incoterms', '09');
    }

    public function test_formulario_permite_elegir_otros_valores_fiscales_distintos_del_default(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create();

        // Códigos REALES alternativos del catálogo oficial, distintos de los predeterminados:
        // CAT-011 tipo 2 (Servicios), CAT-027 '02' (Marítima de Acajutla),
        // CAT-033 'EX-2' (Exportación Temporal), CAT-028 '1040.000', CAT-031 '01' (EXW-En fábrica).
        $datos = $this->datosFex($cliente, $estab, $pv, [
            'tipo_item_expor' => 2,
            'recinto_fiscal' => '02',
            'tipo_regimen' => 'EX-2',
            'regimen' => '1040.000',
            'cod_incoterms' => '01',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $datos)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $dte = Dte::where('tipo_dte', '11')->firstOrFail();
        $this->assertSame(2, $dte->tipo_item_expor);
        $this->assertSame('02', $dte->recinto_fiscal);
        $this->assertSame('EX-2', $dte->tipo_regimen);
        $this->assertSame('1040.000', $dte->regimen);
        $this->assertSame('01', $dte->cod_incoterms);
    }

    // --- Guards de producción (deben seguir intactos: solo cambiaron textos/UX) ---

    /**
     * FEX ahora puede llegar al MISMO flujo de "Generar y transmitir producción" que
     * CCF (antes exclusivo de CreditoFiscal en DtePolicy). Esto NO emite nada: solo
     * confirma que la política de autorización ya no lo bloquea por tipo.
     */
    public function test_fex_puede_llegar_al_flujo_de_preparacion_productiva_del_ccf(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $cliente = Cliente::factory()->exportacion()->create();
        // ambiente 01 explícito: la Policy exige que el DOCUMENTO sea de producción
        // (no solo el sistema) para ser candidato a "Generar y transmitir producción".
        $dte = $this->borradorFex($cliente, $estab, $pv, ['ambiente' => '01']);
        $admin = $this->usuario('administrador');

        $this->assertTrue($admin->can('generarTransmitirProduccion', $dte));
    }
}
