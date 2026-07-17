<?php

namespace Tests\Feature\Clientes;

use App\Enums\TipoCliente;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\Pais;
use App\Models\User;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Database\Seeders\ActividadEconomicaSeeder;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Database\Seeders\PaisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Creación de los 4 Clientes DTE de exportación (Carolinas, Diamond Rocks, Solfi,
 * Distribuidora Cuscatlan) y el bloqueo por documento provisional de Diamond
 * Rocks. Reproduce en la BD aislada de tests los mismos datos reales creados en
 * producción, para verificar el comportamiento sin tocar la BD real.
 */
class ClienteExportacionDocumentoProvisionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PaisSeeder::class);
        $this->seed(ActividadEconomicaSeeder::class);
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** @return array{pais_id: int, actividad_id: int} */
    private function catalogos(): array
    {
        return [
            'pais_id' => Pais::where('codigo', 'US')->value('id'),
            'actividad_id' => ActividadEconomica::where('codigo', '46900')->value('id'),
        ];
    }

    /** @param array<string, mixed> $override */
    private function crearCliente(array $override = []): Cliente
    {
        ['pais_id' => $paisId, 'actividad_id' => $actividadId] = $this->catalogos();

        return Cliente::create(array_merge([
            'tipo_cliente' => TipoCliente::Exportacion->value,
            'tipo_persona' => 'juridica',
            'tipo_documento' => '37',
            'nombre' => 'Cliente de prueba',
            'nombre_comercial' => 'Cliente de prueba',
            'pais_id' => $paisId,
            'actividad_economica_id' => $actividadId,
            'direccion' => 'Dirección de prueba',
            'activo' => true,
        ], $override));
    }

    // ---------- 1-3: documentos exactos como texto ----------

    public function test_crea_los_cuatro_clientes_sin_convertir_documentos_a_numeros(): void
    {
        $carolinas = $this->crearCliente(['nombre' => 'CAROLINAS WHOLESALE LLC', 'num_documento' => '17169433']);
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $solfi = $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);
        $cuscatlan = $this->crearCliente(['nombre' => 'Distribuidora Cuscatlan Inc.', 'num_documento' => '01432900-0']);

        $this->assertSame('17169433', $carolinas->fresh()->num_documento);
        $this->assertSame('00000000000000', $diamond->fresh()->num_documento);
        $this->assertSame('06141211181057', $solfi->fresh()->num_documento);
        $this->assertSame('01432900-0', $cuscatlan->fresh()->num_documento);
        $this->assertIsString($diamond->fresh()->num_documento);
    }

    public function test_preserva_cero_inicial_de_solfi(): void
    {
        $solfi = $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);

        $this->assertSame('06141211181057', $solfi->fresh()->num_documento);
        $this->assertStringStartsWith('0', $solfi->fresh()->num_documento);
        $this->assertSame(14, strlen($solfi->fresh()->num_documento));
    }

    public function test_preserva_cero_inicial_y_guion_de_cuscatlan(): void
    {
        $cuscatlan = $this->crearCliente(['nombre' => 'Distribuidora Cuscatlan Inc.', 'num_documento' => '01432900-0']);

        $this->assertSame('01432900-0', $cuscatlan->fresh()->num_documento);
        $this->assertStringStartsWith('0', $cuscatlan->fresh()->num_documento);
        $this->assertStringContainsString('-', $cuscatlan->fresh()->num_documento);
    }

    // ---------- 4-5: documento provisional ----------

    public function test_diamond_queda_con_documento_provisional_exacto(): void
    {
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => Cliente::DOCUMENTO_PROVISIONAL]);

        $this->assertSame('00000000000000', $diamond->num_documento);
        $this->assertSame(Cliente::DOCUMENTO_PROVISIONAL, $diamond->num_documento);
    }

    public function test_tiene_documento_provisional_detecta_solo_diamond(): void
    {
        $carolinas = $this->crearCliente(['nombre' => 'CAROLINAS WHOLESALE LLC', 'num_documento' => '17169433']);
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $solfi = $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);
        $cuscatlan = $this->crearCliente(['nombre' => 'Distribuidora Cuscatlan Inc.', 'num_documento' => '01432900-0']);
        // Un contribuyente nacional con NIT casualmente todo ceros no debe marcarse
        // (el chequeo exige tipo_cliente = exportación, no solo el valor).
        $contribuyenteCeros = Cliente::factory()->contribuyente()->create(['num_documento' => '00000000000000']);

        $this->assertFalse($carolinas->tieneDocumentoProvisional());
        $this->assertTrue($diamond->tieneDocumentoProvisional());
        $this->assertFalse($solfi->tieneDocumentoProvisional());
        $this->assertFalse($cuscatlan->tieneDocumentoProvisional());
        $this->assertFalse($contribuyenteCeros->tieneDocumentoProvisional());
    }

    // ---------- 6-7: bloqueo de creación FEX y preflight ----------

    public function test_crear_fex_para_diamond_queda_bloqueado(): void
    {
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $expo = ExportacionCliente::create(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'cliente_id' => $diamond->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $expo->id, 'cliente_nombre' => $expo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        $this->expectException(ValidationException::class);
        app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    public function test_preflight_fex_para_diamond_queda_bloqueado(): void
    {
        $empresa = \App\Models\Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = \App\Models\Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = \App\Models\PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);

        $dte = app(\App\Services\Dte\DteBorradorService::class)->crearBorrador([
            'tipo_dte' => \App\Enums\TipoDte::FacturaExportacion,
            'cliente_id' => $diamond->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);

        $resultado = app(\App\Services\Dte\PreflightEmisionProduccionExportacion::class)->evaluar($dte);

        $this->assertFalse($resultado['puede']);
        $this->assertContains('Cliente sin documento provisional', $resultado['faltantes']);
    }

    // ---------- 8: corregir el documento elimina el bloqueo ----------

    public function test_corregir_documento_provisional_elimina_el_bloqueo(): void
    {
        $empresa = \App\Models\Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = \App\Models\Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = \App\Models\PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $expo = ExportacionCliente::create(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'cliente_id' => $diamond->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $expo->id, 'cliente_nombre' => $expo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        $diamond->update(['num_documento' => '99887766']);

        $this->assertFalse($diamond->fresh()->tieneDocumentoProvisional());
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista->fresh());
        $this->assertNotNull($dte->id);
    }

    // ---------- 9-11: validación de cliente para los otros tres ----------

    public function test_carolinas_pasa_la_validacion_de_cliente(): void
    {
        $this->validaClienteHttp('CAROLINAS WHOLESALE LLC', '17169433', 'carolinaswholesalellc@aol.com', '13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU');
    }

    public function test_solfi_pasa_la_validacion_de_cliente(): void
    {
        $this->validaClienteHttp('SOLFI GROUP INC.', '06141211181057', 'facturaelectronica@solfigroup.com', '2121 YATES AVE. COMMERCE, CA 90040');
    }

    public function test_distribuidora_cuscatlan_pasa_la_validacion_de_cliente(): void
    {
        $this->validaClienteHttp('Distribuidora Cuscatlan Inc.', '01432900-0', 'info@cuscatlanfoods.com', '6403 - C Ammendale Rd, Beltsville MD 20705');
    }

    private function validaClienteHttp(string $nombre, string $numDocumento, string $correo, string $direccion): void
    {
        ['pais_id' => $paisId, 'actividad_id' => $actividadId] = $this->catalogos();

        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), [
                'tipo_cliente' => 'exportacion',
                'tipo_persona' => 'juridica',
                'tipo_documento' => '37',
                'num_documento' => $numDocumento,
                'nombre' => $nombre,
                'nombre_comercial' => $nombre,
                'pais_id' => $paisId,
                'actividad_economica_id' => $actividadId,
                'direccion' => $direccion,
                'correo' => $correo,
                'activo' => '1',
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clientes', ['nombre' => $nombre, 'num_documento' => $numDocumento]);
    }

    // ---------- 12: los cuatro vinculados ----------

    public function test_los_cuatro_quedan_vinculados_correctamente(): void
    {
        $carolinas = $this->crearCliente(['nombre' => 'CAROLINAS WHOLESALE LLC', 'num_documento' => '17169433']);
        $diamond = $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $solfi = $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);
        $cuscatlan = $this->crearCliente(['nombre' => 'Distribuidora Cuscatlan Inc.', 'num_documento' => '01432900-0']);

        $expoCarolinas = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'activo' => true]);
        $expoDiamond = ExportacionCliente::create(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'activo' => true]);
        $expoSolfi = ExportacionCliente::create(['nombre' => 'SOLFI GROUP INC', 'activo' => true]);
        $expoCuscatlan = ExportacionCliente::create(['nombre' => 'Distribuidora Cuscatlan Inc.', 'activo' => true]);

        $expoCarolinas->update(['cliente_id' => $carolinas->id]);
        $expoDiamond->update(['cliente_id' => $diamond->id]);
        $expoSolfi->update(['cliente_id' => $solfi->id]);
        $expoCuscatlan->update(['cliente_id' => $cuscatlan->id]);

        $this->assertTrue($expoCarolinas->fresh()->cliente->is($carolinas));
        $this->assertTrue($expoDiamond->fresh()->cliente->is($diamond));
        $this->assertTrue($expoSolfi->fresh()->cliente->is($solfi));
        $this->assertTrue($expoCuscatlan->fresh()->cliente->is($cuscatlan));
    }

    // ---------- 13: sin duplicados (unicidad por documento+tipo) ----------

    public function test_no_permite_crear_cliente_duplicado_por_documento(): void
    {
        $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);

        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), [
                'tipo_cliente' => 'exportacion',
                'tipo_persona' => 'juridica',
                'tipo_documento' => '37',
                'num_documento' => '06141211181057',
                'nombre' => 'SOLFI GROUP INC. (duplicado)',
                'pais_id' => $this->catalogos()['pais_id'],
                'actividad_economica_id' => $this->catalogos()['actividad_id'],
                'direccion' => 'Otra dirección',
                'activo' => '1',
            ])
            ->assertSessionHasErrors('num_documento');

        $this->assertSame(1, Cliente::where('num_documento', '06141211181057')->count());
    }

    // ---------- 14-16: cero DTE / correlativos / firma / correo ----------

    public function test_crear_los_clientes_no_toca_dte_correlativos_ni_correo(): void
    {
        $antesDtes = Dte::count();
        $antesCorrelativos = Correlativo::count();
        $antesEnvios = DteEnvio::count();

        $this->crearCliente(['nombre' => 'CAROLINAS WHOLESALE LLC', 'num_documento' => '17169433']);
        $this->crearCliente(['nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.', 'num_documento' => '00000000000000']);
        $this->crearCliente(['nombre' => 'SOLFI GROUP INC.', 'num_documento' => '06141211181057']);
        $this->crearCliente(['nombre' => 'Distribuidora Cuscatlan Inc.', 'num_documento' => '01432900-0']);

        $this->assertSame($antesDtes, Dte::count());
        $this->assertSame($antesCorrelativos, Correlativo::count());
        $this->assertSame($antesEnvios, DteEnvio::count());
    }
}
