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
 * Rutas de Configuración › Establecimientos y Puntos de venta, miradas por su efecto.
 *
 * Los códigos del establecimiento (M001) y del punto de venta (P001) van dentro del número
 * de control de cada documento. Anular un documento viejo toma el código ACTUAL; si cambió,
 * la anulación sale con otro número. Y si se borran con documentos, el número de control
 * se arma con códigos de relleno.
 */
class EstablecimientoPuntoVentaRutasTest extends TestCase
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

    private function sinPermiso(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function datosEstablecimiento(array $cambios = []): array
    {
        return $cambios + [
            'empresa_id' => $this->empresa->id,
            'codigo' => 'S002',
            'nombre' => 'Sucursal Centro',
            ...UbicacionCoherenteFactory::tercia(),
            'activo' => '1',
        ];
    }

    private function datosPuntoVenta(PuntoVenta $pv, array $cambios = []): array
    {
        return $cambios + [
            'establecimiento_id' => $pv->establecimiento_id,
            'codigo' => $pv->codigo,
            'nombre' => $pv->nombre,
            'descripcion' => $pv->descripcion,
            'activo' => $pv->activo ? '1' : '0',
        ];
    }

    private function correlativo(): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /** Emite un CCF de verdad en M001/P001: queda con número de control. */
    private function emitirCcf(): Dte
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
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    private function otroEstablecimiento(): Establecimiento
    {
        return Establecimiento::create([
            'empresa_id' => $this->empresa->id, 'codigo' => 'S009', 'nombre' => 'Sucursal Norte', 'activo' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // establecimientos.store
    // ---------------------------------------------------------------------

    public function test_crear_un_establecimiento_valido_lo_guarda_con_codigo_y_distrito(): void
    {
        $datos = $this->datosEstablecimiento();
        $this->assertNotNull($datos['distrito_id']);

        $this->actingAs($this->admin())
            ->post(route('configuracion.establecimientos.store'), $datos)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.establecimientos.index'));

        $this->assertDatabaseHas('establecimientos', [
            'empresa_id' => $this->empresa->id, 'codigo' => 'S002', 'nombre' => 'Sucursal Centro',
            'distrito_id' => $datos['distrito_id'], 'deleted_at' => null,
        ]);
    }

    public function test_el_codigo_del_establecimiento_no_se_repite_en_la_misma_empresa(): void
    {
        $this->actingAs($this->admin())
            ->post(route('configuracion.establecimientos.store'), $this->datosEstablecimiento(['codigo' => 'M001']))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Establecimiento::where('codigo', 'M001')->count());
    }

    // ---------------------------------------------------------------------
    // establecimientos.update
    // ---------------------------------------------------------------------

    public function test_con_documentos_el_codigo_del_establecimiento_no_cambia(): void
    {
        $this->correlativo();
        $dte = $this->emitirCcf();
        $this->assertStringContainsString('M001P001', (string) $dte->numero_control);

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento([
                'codigo' => 'M002', 'nombre' => 'Casa Matriz',
            ]))
            ->assertSessionHasErrors('codigo');

        $this->assertSame('M001', $this->estab->refresh()->codigo);
    }

    public function test_con_documentos_si_se_cambian_los_demas_datos_del_establecimiento(): void
    {
        $this->correlativo();
        $this->emitirCcf();

        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento([
                'codigo' => 'M001', 'nombre' => 'Casa Matriz Renovada',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Casa Matriz Renovada', $this->estab->refresh()->nombre);
    }

    public function test_sin_documentos_el_codigo_del_establecimiento_si_cambia(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento([
                'codigo' => 'M002', 'nombre' => 'Casa Matriz',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('M002', $this->estab->refresh()->codigo);
    }

    // ---------------------------------------------------------------------
    // establecimientos.destroy
    // ---------------------------------------------------------------------

    public function test_un_establecimiento_con_puntos_de_venta_no_se_borra(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $this->estab))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->estab);
    }

    public function test_un_establecimiento_con_correlativos_no_se_borra(): void
    {
        $this->correlativo();
        $this->pv->delete();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $this->estab))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->estab);
    }

    /**
     * Con documentos emitidos pero ya sin punto de venta ni correlativo (como queda hoy
     * si se borran esos dos primero), el establecimiento tampoco se borra.
     */
    public function test_un_establecimiento_con_documentos_no_se_borra(): void
    {
        $correlativo = $this->correlativo();
        $this->emitirCcf();
        $correlativo->delete();
        $this->pv->delete();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $this->estab))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->estab);
    }

    public function test_un_establecimiento_sin_nada_se_borra_suave(): void
    {
        $vacio = $this->otroEstablecimiento();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.establecimientos.destroy', $vacio))
            ->assertRedirect(route('configuracion.establecimientos.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($vacio);
    }

    // ---------------------------------------------------------------------
    // puntos-venta.store
    // ---------------------------------------------------------------------

    public function test_crear_un_punto_de_venta_valido_lo_guarda(): void
    {
        $this->actingAs($this->admin())
            ->post(route('configuracion.puntos-venta.store'), [
                'establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.puntos-venta.index'));

        $this->assertDatabaseHas('puntos_venta', [
            'establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'deleted_at' => null,
        ]);
    }

    public function test_el_codigo_del_punto_de_venta_no_se_repite_en_el_mismo_establecimiento(): void
    {
        $this->actingAs($this->admin())
            ->post(route('configuracion.puntos-venta.store'), [
                'establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Otra caja', 'activo' => '1',
            ])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, PuntoVenta::where('establecimiento_id', $this->estab->id)->where('codigo', 'P001')->count());
    }

    // ---------------------------------------------------------------------
    // puntos-venta.update
    // ---------------------------------------------------------------------

    public function test_con_documentos_el_punto_de_venta_no_cambia_de_codigo(): void
    {
        $this->correlativo();
        $this->emitirCcf();

        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta($this->pv, ['codigo' => 'P002']))
            ->assertSessionHasErrors('codigo');

        $this->assertSame('P001', $this->pv->refresh()->codigo);
    }

    public function test_con_documentos_el_punto_de_venta_no_se_mueve_de_establecimiento(): void
    {
        $correlativo = $this->correlativo();
        $this->emitirCcf();
        $correlativo->delete(); // solo quedan los documentos
        $otro = $this->otroEstablecimiento();

        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta($this->pv, ['establecimiento_id' => $otro->id]))
            ->assertSessionHasErrors('establecimiento_id');

        $this->assertSame($this->estab->id, $this->pv->refresh()->establecimiento_id);
    }

    public function test_con_correlativos_el_punto_de_venta_no_se_mueve_de_establecimiento(): void
    {
        $this->correlativo();
        $otro = $this->otroEstablecimiento();

        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta($this->pv, ['establecimiento_id' => $otro->id]))
            ->assertSessionHasErrors('establecimiento_id');

        $this->assertSame($this->estab->id, $this->pv->refresh()->establecimiento_id);
    }

    public function test_sin_correlativos_ni_documentos_el_punto_de_venta_se_mueve_y_cambia_de_codigo(): void
    {
        $otro = $this->otroEstablecimiento();

        $this->actingAs($this->admin())
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta($this->pv, [
                'establecimiento_id' => $otro->id, 'codigo' => 'P005',
            ]))
            ->assertSessionHasNoErrors();

        $this->pv->refresh();
        $this->assertSame($otro->id, $this->pv->establecimiento_id);
        $this->assertSame('P005', $this->pv->codigo);
    }

    // ---------------------------------------------------------------------
    // puntos-venta.destroy
    // ---------------------------------------------------------------------

    public function test_un_punto_de_venta_con_correlativos_no_se_borra(): void
    {
        $this->correlativo();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.puntos-venta.destroy', $this->pv))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->pv);
    }

    public function test_un_punto_de_venta_con_documentos_no_se_borra(): void
    {
        $correlativo = $this->correlativo();
        $this->emitirCcf();
        $correlativo->delete(); // solo quedan los documentos

        $this->actingAs($this->admin())
            ->delete(route('configuracion.puntos-venta.destroy', $this->pv))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->pv);
    }

    public function test_un_punto_de_venta_sin_nada_se_borra_suave(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('configuracion.puntos-venta.destroy', $this->pv))
            ->assertRedirect(route('configuracion.puntos-venta.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($this->pv);
    }

    // ---------------------------------------------------------------------
    // permisos
    // ---------------------------------------------------------------------

    public function test_sin_permiso_de_configuracion_no_se_toca_ningun_establecimiento(): void
    {
        $usuario = $this->sinPermiso();
        $vacio = $this->otroEstablecimiento();

        $this->actingAs($usuario)
            ->post(route('configuracion.establecimientos.store'), $this->datosEstablecimiento())
            ->assertForbidden();
        $this->actingAs($usuario)
            ->put(route('configuracion.establecimientos.update', $this->estab), $this->datosEstablecimiento(['codigo' => 'M777']))
            ->assertForbidden();
        $this->actingAs($usuario)
            ->delete(route('configuracion.establecimientos.destroy', $vacio))
            ->assertForbidden();

        $this->assertSame(2, Establecimiento::count());
        $this->assertSame('M001', $this->estab->refresh()->codigo);
        $this->assertNotSoftDeleted($vacio);
    }

    public function test_sin_permiso_de_configuracion_no_se_toca_ningun_punto_de_venta(): void
    {
        $usuario = $this->sinPermiso();

        $this->actingAs($usuario)
            ->post(route('configuracion.puntos-venta.store'), [
                'establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => '1',
            ])
            ->assertForbidden();
        $this->actingAs($usuario)
            ->put(route('configuracion.puntos-venta.update', $this->pv), $this->datosPuntoVenta($this->pv, ['codigo' => 'P777']))
            ->assertForbidden();
        $this->actingAs($usuario)
            ->delete(route('configuracion.puntos-venta.destroy', $this->pv))
            ->assertForbidden();

        $this->assertSame(1, PuntoVenta::count());
        $this->assertSame('P001', $this->pv->refresh()->codigo);
        $this->assertNotSoftDeleted($this->pv);
    }
}
