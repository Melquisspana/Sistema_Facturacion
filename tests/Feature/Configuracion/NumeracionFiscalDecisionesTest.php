<?php

namespace Tests\Feature\Configuracion;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
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
use App\Support\HoraNegocio;
use App\Support\Ubicacion\UbicacionCoherenteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Decisiones de la numeración fiscal, por su efecto:
 *  - El código del establecimiento o del punto de venta solo queda fijo cuando ya hay
 *    documentos con número (generados o emitidos): un borrador todavía no lo lleva.
 *  - Para borrar o mover cuenta cualquier documento, borradores incluidos.
 *  - Un establecimiento con documentos no cambia de empresa, y uno borrado no recibe
 *    correlativos nuevos.
 */
class NumeracionFiscalDecisionesTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Empresa $empresa;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['empresa' => $this->empresa, 'estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', HoraNegocio::zona()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function datosEstablecimiento(array $cambios = []): array
    {
        return $cambios + [
            'empresa_id' => $this->estab->empresa_id,
            'codigo' => $this->estab->codigo,
            'nombre' => $this->estab->nombre,
            ...UbicacionCoherenteFactory::tercia(),
            'activo' => '1',
        ];
    }

    private function datosPuntoVenta(array $cambios = []): array
    {
        return $cambios + [
            'establecimiento_id' => $this->pv->establecimiento_id,
            'codigo' => $this->pv->codigo,
            'nombre' => $this->pv->nombre,
            'activo' => '1',
        ];
    }

    private function correlativo(): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /** Un CCF en borrador en M001/P001: todavía sin número. */
    private function borrador(): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    private function otraEmpresa(): Empresa
    {
        return Empresa::create([
            'razon_social' => 'Empresa de prueba dos', 'nit' => '06149999999999', 'nrc' => '7654321',
            'ambiente' => '00', 'activo' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // a) código: solo cuentan los documentos con número
    // ---------------------------------------------------------------------

    public function test_con_solo_un_borrador_el_codigo_del_establecimiento_si_cambia_y_el_borrador_sale_con_el_nuevo(): void
    {
        $this->correlativo();
        $borrador = $this->borrador();
        $this->assertNull($borrador->numero_control);

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['codigo' => 'M002']))
            ->assertSessionHasNoErrors();

        $this->assertSame('M002', $this->estab->refresh()->codigo);
        $this->assertStringContainsString('M002P001', (string) $this->generar($borrador)->numero_control);
    }

    public function test_con_solo_un_borrador_el_codigo_del_punto_de_venta_si_cambia(): void
    {
        $this->borrador();

        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta(['codigo' => 'P007']))
            ->assertSessionHasNoErrors();

        $this->assertSame('P007', $this->pv->refresh()->codigo);
    }

    public function test_un_documento_generado_si_fija_los_dos_codigos(): void
    {
        $this->correlativo();
        $this->generar($this->borrador());

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['codigo' => 'M002']))
            ->assertSessionHasErrors('codigo');
        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta(['codigo' => 'P007']))
            ->assertSessionHasErrors('codigo');

        $this->assertSame('M001', $this->estab->refresh()->codigo);
        $this->assertSame('P001', $this->pv->refresh()->codigo);
    }

    // ---------------------------------------------------------------------
    // a) borrar o mover: cuenta cualquier documento
    // ---------------------------------------------------------------------

    public function test_con_solo_un_borrador_el_punto_de_venta_no_se_borra_ni_se_mueve(): void
    {
        $this->borrador();
        $otro = Establecimiento::create([
            'empresa_id' => $this->empresa->id, 'codigo' => 'S009', 'nombre' => 'Sucursal Norte', 'activo' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('configuracion.puntos-venta.destroy', $this->pv))
            ->assertSessionHas('error');
        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta(['establecimiento_id' => $otro->id]))
            ->assertSessionHasErrors('establecimiento_id');

        $this->assertNotSoftDeleted($this->pv);
        $this->assertSame($this->estab->id, $this->pv->refresh()->establecimiento_id);
    }

    public function test_con_solo_un_borrador_el_establecimiento_no_se_borra(): void
    {
        $this->borrador();
        $this->pv->delete();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $this->estab))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->estab);
    }

    // ---------------------------------------------------------------------
    // c) empresa del establecimiento
    // ---------------------------------------------------------------------

    public function test_con_documentos_el_establecimiento_no_cambia_de_empresa(): void
    {
        $this->correlativo();
        $this->generar($this->borrador());
        $otra = $this->otraEmpresa();

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['empresa_id' => $otra->id]))
            ->assertSessionHasErrors('empresa_id');

        $this->assertSame($this->empresa->id, $this->estab->refresh()->empresa_id);
    }

    public function test_con_solo_un_borrador_el_establecimiento_tampoco_cambia_de_empresa(): void
    {
        $this->borrador();
        $otra = $this->otraEmpresa();

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['empresa_id' => $otra->id]))
            ->assertSessionHasErrors('empresa_id');

        $this->assertSame($this->empresa->id, $this->estab->refresh()->empresa_id);
    }

    public function test_sin_documentos_el_establecimiento_si_cambia_de_empresa(): void
    {
        $otra = $this->otraEmpresa();

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['empresa_id' => $otra->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($otra->id, $this->estab->refresh()->empresa_id);
    }

    // ---------------------------------------------------------------------
    // c) correlativo de un establecimiento borrado
    // ---------------------------------------------------------------------

    public function test_no_se_crea_un_correlativo_para_un_establecimiento_borrado(): void
    {
        $borrado = Establecimiento::create([
            'empresa_id' => $this->empresa->id, 'codigo' => 'S008', 'nombre' => 'Sucursal cerrada', 'activo' => true,
        ]);
        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $borrado))
            ->assertSessionHas('status');

        $this->actingAs($this->admin())
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '03', 'establecimiento_id' => $borrado->id, 'punto_venta_id' => '',
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => '1',
            ])
            ->assertSessionHasErrors('establecimiento_id');

        $this->assertSame(0, Correlativo::where('establecimiento_id', $borrado->id)->count());
    }
}
