<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteGeneracionTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);

        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);
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

    private function correlativo(string $tipo, Establecimiento $estab, PuntoVenta $pv): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    private function borradorConLinea(TipoDte $tipo, Establecimiento $estab, PuntoVenta $pv, ?Cliente $cliente, array $extra = []): Dte
    {
        $dte = $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ], $extra));

        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return $dte->refresh();
    }

    public function test_generar_ccf_borrador_valido(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->generacion->generar($dte);

        $dte->refresh();
        $this->assertSame(EstadoDte::Generado, $dte->estado);
        $this->assertSame('INT-03-M001P001-000000000000001', $dte->numero_interno);
    }

    public function test_generar_factura_borrador_valido(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('01', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::Factura, $estab, $pv, null);

        $this->generacion->generar($dte);

        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
        $this->assertStringStartsWith('INT-01-', $dte->numero_interno);
    }

    public function test_generar_exportacion_borrador_valido(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('11', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::FacturaExportacion, $estab, $pv, Cliente::factory()->exportacion()->create());

        $this->generacion->generar($dte);

        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
        $this->assertStringStartsWith('INT-11-', $dte->numero_interno);
    }

    public function test_no_generar_sin_lineas(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $this->expectException(GeneracionException::class);
        $this->generacion->generar($dte);
    }

    public function test_no_generar_si_no_es_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());
        $this->generacion->generar($dte);

        $this->expectException(GeneracionException::class);
        $this->generacion->generar($dte->refresh());
    }

    public function test_no_generar_si_falta_correlativo(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // Sin crear correlativo para tipo 03.
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->expectException(GeneracionException::class);
        $this->generacion->generar($dte);
    }

    public function test_consumir_correlativo_incrementa_ultimo_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $correlativo = $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->generacion->generar($dte);

        $this->assertSame(1, $correlativo->refresh()->ultimo_numero);
    }

    public function test_dos_generaciones_no_usan_el_mismo_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $correlativo = $this->correlativo('03', $estab, $pv);
        $cliente = Cliente::factory()->contribuyente()->create();

        $a = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, $cliente);
        $b = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, $cliente);

        $this->generacion->generar($a);
        $this->generacion->generar($b);

        $this->assertNotSame($a->refresh()->numero_interno, $b->refresh()->numero_interno);
        $this->assertSame(2, $correlativo->refresh()->ultimo_numero);
    }

    public function test_al_generar_registra_historial(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->generacion->generar($dte);

        $this->assertDatabaseHas('dte_estado_historial', [
            'dte_id' => $dte->id,
            'estado_anterior' => 'borrador',
            'estado_nuevo' => 'generado',
        ]);
    }

    public function test_despues_de_generar_no_se_puede_editar_cabecera(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());
        $this->generacion->generar($dte);

        $this->expectException(DocumentoInmutableException::class);
        $dte->refresh()->update(['observaciones' => 'cambio no permitido']);
    }

    public function test_despues_de_generar_no_se_pueden_modificar_lineas(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());
        $this->generacion->generar($dte);

        $producto = Producto::factory()->create(['tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->expectException(DocumentoInmutableException::class);
        $this->borradores->agregarLineaDesdeProducto($dte->refresh(), $producto, cantidad: 1);
    }

    public function test_boton_generar_visible_solo_en_borrador_para_gestor(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Generar');
    }

    public function test_consulta_y_contador_no_pueden_generar(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->actingAs($this->usuario('consulta'))->post(route('facturacion.generar', $dte))->assertForbidden();
        $this->actingAs($this->usuario('contador'))->post(route('facturacion.generar', $dte))->assertForbidden();

        $this->assertSame(EstadoDte::Borrador, $dte->refresh()->estado);
    }

    public function test_facturacion_genera_por_la_ruta_y_redirige_a_show(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $this->correlativo('03', $estab, $pv);
        $dte = $this->borradorConLinea(TipoDte::CreditoFiscal, $estab, $pv, Cliente::factory()->contribuyente()->create());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
    }
}
