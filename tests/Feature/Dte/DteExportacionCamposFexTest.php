<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre la implementación de captura/validación/serialización de los campos FEX
 * (tipo 11) que el schema real del MH exige y que antes se enviaban como null:
 * tipoItemExpor, recintoFiscal, tipoRegimen, regimen, codIncoterms, descIncoterms.
 * Por-DTE (no por-emisor), ver auditoría FEX. NO habilita producción real ni toca
 * los gates de bloqueo del commit 04d32ff.
 */
class DteExportacionCamposFexTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    // Códigos REALES del catálogo oficial importado por CatalogosMhTablaSeeder (vía
    // PreparaEmisorDte::seedCatalogosDte()): CAT-027 '01' Terrestre San Bartolo,
    // CAT-033 'EX-1' Exportación Definitiva, CAT-028 '1000.000' Exportación Definitiva
    // Régimen Común, CAT-031 '09' FOB-Libre a bordo.
    private const RECINTO_FISCAL = '01';

    private const TIPO_REGIMEN = 'EX-1';

    private const REGIMEN = '1000.000';

    private const COD_INCOTERMS = '09';

    private const DESC_INCOTERMS_ESPERADA = 'FOB-Libre a bordo';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @param array<string, mixed> $override */
    private function datosFex(Cliente $cliente, array $override = []): array
    {
        return array_merge([
            'tipo_dte' => '11',
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'condicion_operacion' => 1,
            'flete' => 0,
            'seguro' => 0,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => self::RECINTO_FISCAL,
            'tipo_regimen' => self::TIPO_REGIMEN,
            'regimen' => self::REGIMEN,
            'cod_incoterms' => self::COD_INCOTERMS,
        ], $override);
    }

    // --- UI: selects poblados desde catalogos_mh ---

    public function test_formulario_exportacion_muestra_selects_fex_con_catalogos_reales(): void
    {
        Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            ->assertSee('name="tipo_item_expor"', false)
            ->assertSee('name="recinto_fiscal"', false)
            ->assertSee('name="tipo_regimen"', false)
            ->assertSee('name="regimen"', false)
            ->assertSee('name="cod_incoterms"', false)
            ->assertSee('Terrestre San Bartolo')      // CAT-027
            ->assertSee('Exportación Definitiva')      // CAT-033 (y también aparece en CAT-028)
            ->assertSee('FOB-Libre a bordo');           // CAT-031
    }

    // --- storeExportacion guarda los campos nuevos ---

    public function test_store_exportacion_guarda_campos_fex_y_resuelve_desc_incoterms_del_catalogo(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '11',
            'tipo_item_expor' => 1,
            'recinto_fiscal' => self::RECINTO_FISCAL,
            'tipo_regimen' => self::TIPO_REGIMEN,
            'regimen' => self::REGIMEN,
            'cod_incoterms' => self::COD_INCOTERMS,
            'desc_incoterms' => self::DESC_INCOTERMS_ESPERADA,
        ]);
    }

    public function test_store_exportacion_ignora_desc_incoterms_de_texto_libre_del_formulario(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();

        // El formulario no tiene campo desc_incoterms, pero si alguien lo inyectara en el
        // POST, el servidor debe seguir resolviéndolo del catálogo, no confiar en el texto.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, [
                'desc_incoterms' => 'TEXTO INVENTADO POR EL CLIENTE',
            ]))
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '11')->firstOrFail();
        $this->assertSame(self::DESC_INCOTERMS_ESPERADA, $dte->desc_incoterms);
        $this->assertNotSame('TEXTO INVENTADO POR EL CLIENTE', $dte->desc_incoterms);
    }

    // --- Validación: falla si falta cualquiera de los campos obligatorios ---

    public static function campoFexObligatorioProvider(): array
    {
        return [
            'tipo_item_expor' => ['tipo_item_expor'],
            'recinto_fiscal' => ['recinto_fiscal'],
            'tipo_regimen' => ['tipo_regimen'],
            'regimen' => ['regimen'],
            'cod_incoterms' => ['cod_incoterms'],
        ];
    }

    #[DataProvider('campoFexObligatorioProvider')]
    public function test_falla_si_falta_campo_fex_obligatorio(string $campo): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $datos = $this->datosFex($cliente);
        unset($datos[$campo]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $datos)
            ->assertSessionHasErrors($campo);

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_falla_con_codigo_de_catalogo_inexistente(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, ['recinto_fiscal' => 'ZZ']))
            ->assertSessionHasErrors('recinto_fiscal');

        $this->assertDatabaseCount('dtes', 0);
    }

    // --- CCF no se ve afectado por la exigencia nueva (solo aplica a tipo 11) ---

    public function test_ccf_no_exige_ni_guarda_campos_fex(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);

        $this->assertNotNull($dte->id);
        $this->assertNull($dte->recinto_fiscal);
        $this->assertNull($dte->tipo_regimen);
        $this->assertNull($dte->regimen);
        $this->assertNull($dte->cod_incoterms);
        $this->assertSame(1, $dte->tipo_item_expor); // default de columna, sin efecto para CCF
    }

    // --- Round-trip completo: generación real serializa con campos no-null y valida schema ---

    public function test_generacion_completa_de_exportacion_serializa_campos_reales_y_valida_contra_schema(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => self::RECINTO_FISCAL,
            'tipo_regimen' => self::TIPO_REGIMEN,
            'regimen' => self::REGIMEN,
            'cod_incoterms' => self::COD_INCOTERMS,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        // DteGeneracionService::generar() ya invoca DteJsonService internamente (asigna
        // numeración y guarda json_generado_path); llamar a DteJsonService::generar() de
        // nuevo aquí duplicaría la generación y fallaría ("ya tiene JSON generado").
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $oficial = json_decode(Storage::disk('local')->get($dte->json_generado_path), true);

        $this->assertSame(1, $oficial['emisor']['tipoItemExpor']);
        $this->assertSame(self::RECINTO_FISCAL, $oficial['emisor']['recintoFiscal']);
        $this->assertSame(self::TIPO_REGIMEN, $oficial['emisor']['tipoRegimen']);
        $this->assertSame(self::REGIMEN, $oficial['emisor']['regimen']);
        $this->assertSame(self::COD_INCOTERMS, $oficial['resumen']['codIncoterms']);
        $this->assertSame(self::DESC_INCOTERMS_ESPERADA, $oficial['resumen']['descIncoterms']);

        // No emite/firma/transmite: solo generó y validó el JSON preliminar contra el schema
        // (DteJsonService::generar ya lanza DteJsonInvalidoException si no valida; llegar
        // hasta acá sin excepción ES la prueba de que valida contra fe-fex-v3.json).
        $this->assertNotNull($dte->refresh()->json_generado_path);
        $this->assertNull($dte->sello_recepcion);
        $this->assertNull($dte->json_firmado_path);
    }
}
