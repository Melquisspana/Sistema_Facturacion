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
 * Valores que la configuración fiscal aceptaba y que después no dejaban emitir o rompían
 * la pantalla: códigos que Hacienda no acepta, el código de uno borrado, un último número
 * de más de 15 cifras y un punto de venta en un establecimiento borrado.
 */
class ConfiguracionFiscalValoresTest extends TestCase
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

    /** Emite un CCF de verdad (borrador → generado) en ese establecimiento y punto de venta. */
    private function emitir(Establecimiento $estab, PuntoVenta $pv): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    private function numero(string $serie, int $n): string
    {
        return 'DTE-03-'.$serie.'-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT);
    }

    /** Lo que manda el formulario del correlativo, con sus valores actuales. */
    private function formCorrelativo(Correlativo $correlativo, array $cambios = []): array
    {
        $correlativo->refresh();

        return $cambios + [
            'tipo_dte' => $correlativo->tipo_dte->value,
            'establecimiento_id' => $correlativo->establecimiento_id,
            'punto_venta_id' => $correlativo->punto_venta_id,
            'ambiente' => $correlativo->ambiente->value,
            'serie' => $correlativo->serie,
            'ultimo_numero' => $correlativo->ultimo_numero,
            'activo' => $correlativo->activo ? '1' : '0',
        ];
    }

    private function formEstablecimiento(Establecimiento $estab, array $cambios = []): array
    {
        return $cambios + [
            'empresa_id' => $estab->empresa_id,
            'codigo' => $estab->codigo,
            'nombre' => $estab->nombre,
            'tipo_establecimiento' => $estab->tipo_establecimiento?->value,
            ...UbicacionCoherenteFactory::tercia(),
            'activo' => '1',
        ];
    }

    private function formPuntoVenta(PuntoVenta $pv, array $cambios = []): array
    {
        return $cambios + [
            'establecimiento_id' => $pv->establecimiento_id,
            'codigo' => $pv->codigo,
            'nombre' => $pv->nombre,
            'activo' => '1',
        ];
    }

    private function correlativo(array $datos = []): Correlativo
    {
        return Correlativo::create($datos + [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /**
     * El código de uno borrado sigue ocupado en la base, y es el que tiene registrado Hacienda.
     * Al crearlo otra vez no se choca contra la base: se recupera el borrado con los datos nuevos.
     */
    public function test_crear_con_el_codigo_de_uno_borrado_lo_recupera_con_los_datos_nuevos(): void
    {
        $admin = $this->admin();
        $cerrado = Establecimiento::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S008', 'nombre' => 'Sucursal cerrada', 'activo' => true]);
        $cajaVieja = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P008', 'nombre' => 'Caja vieja', 'activo' => true]);
        $this->actingAs($admin)->delete(route('configuracion.establecimientos.destroy', $cerrado))->assertSessionHas('status');
        $this->actingAs($admin)->delete(route('configuracion.puntos-venta.destroy', $cajaVieja))->assertSessionHas('status');

        $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), [
            'empresa_id' => $this->empresa->id, 'codigo' => 'S008', 'nombre' => 'Sucursal nueva',
            ...UbicacionCoherenteFactory::tercia(), 'activo' => '1',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Se recuperó el establecimiento S008, que se había borrado, con los datos nuevos.');
        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), [
            'establecimiento_id' => $this->estab->id, 'codigo' => 'P008', 'nombre' => 'Caja nueva', 'activo' => '1',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Se recuperó el punto de venta P008, que se había borrado, con los datos nuevos.');

        $this->assertFalse($cerrado->fresh()->trashed());
        $this->assertSame('Sucursal nueva', $cerrado->fresh()->nombre);
        $this->assertFalse($cajaVieja->fresh()->trashed());
        $this->assertSame('Caja nueva', $cajaVieja->fresh()->nombre);
        $this->assertSame(1, Establecimiento::withTrashed()->where('codigo', 'S008')->count());
        $this->assertSame(1, PuntoVenta::withTrashed()->where('codigo', 'P008')->count());
    }

    /** Darle a otro el código de uno borrado no rompe: el aviso dice qué hacer. */
    public function test_darle_a_otro_el_codigo_de_uno_borrado_dice_que_hacer(): void
    {
        $admin = $this->admin();
        $cerrado = Establecimiento::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S008', 'nombre' => 'Sucursal cerrada', 'activo' => true]);
        $cajaVieja = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P008', 'nombre' => 'Caja vieja', 'activo' => true]);
        $this->actingAs($admin)->delete(route('configuracion.establecimientos.destroy', $cerrado))->assertSessionHas('status');
        $this->actingAs($admin)->delete(route('configuracion.puntos-venta.destroy', $cajaVieja))->assertSessionHas('status');

        $this->actingAs($admin)->put(route('configuracion.establecimientos.update', $this->estab), $this->formEstablecimiento($this->estab, ['codigo' => 'S008']))
            ->assertSessionHasErrors(['codigo' => 'Ese código era de un establecimiento que se borró. Para volver a usarlo, creá un establecimiento con ese código: se recupera el que se borró.']);
        $this->actingAs($admin)->put(route('configuracion.puntos-venta.update', $this->pv), $this->formPuntoVenta($this->pv, ['codigo' => 'P008']))
            ->assertSessionHasErrors(['codigo' => 'Ese código era de un punto de venta que se borró. Para volver a usarlo, creá un punto de venta con ese código: se recupera el que se borró.']);

        $this->assertSame('M001', $this->estab->refresh()->codigo);
        $this->assertSame('P001', $this->pv->refresh()->codigo);
        $this->assertTrue($cerrado->fresh()->trashed());
        $this->assertTrue($cajaVieja->fresh()->trashed());
    }

    /**
     * Hacienda solo acepta números de control con el establecimiento como una letra (M, S, B
     * o P) y tres cifras, y el punto de venta como P y tres cifras. Con otro código ningún
     * documento se podía generar después.
     */
    public function test_un_codigo_que_hacienda_no_acepta_se_rechaza_al_guardar(): void
    {
        $admin = $this->admin();

        foreach (['X001', 'M01', 'm001', 'M 01', '1'] as $codigo) {
            $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), [
                'empresa_id' => $this->empresa->id, 'codigo' => $codigo, 'nombre' => 'Sucursal',
                ...UbicacionCoherenteFactory::tercia(), 'activo' => '1',
            ])->assertSessionHasErrors('codigo');
        }
        foreach (['C001', 'P1', 'p002', '0001'] as $codigo) {
            $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), [
                'establecimiento_id' => $this->estab->id, 'codigo' => $codigo, 'nombre' => 'Caja', 'activo' => '1',
            ])->assertSessionHasErrors('codigo');
        }
        $this->actingAs($admin)->put(route('configuracion.establecimientos.update', $this->estab), $this->formEstablecimiento($this->estab, ['codigo' => 'Z001']))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Establecimiento::count());
        $this->assertSame(1, PuntoVenta::count());
        $this->assertSame('M001', $this->estab->refresh()->codigo);

        // Los válidos sí entran.
        $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), [
            'empresa_id' => $this->empresa->id, 'codigo' => 'B002', 'nombre' => 'Bodega',
            ...UbicacionCoherenteFactory::tercia(), 'activo' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), [
            'establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => '1',
        ])->assertSessionHasNoErrors();
    }

    /** El número de control lleva 15 cifras: un último número más largo no deja emitir nada. */
    public function test_el_ultimo_numero_no_pasa_de_quince_cifras(): void
    {
        $admin = $this->admin();
        $correlativo = $this->correlativo();

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['ultimo_numero' => '1000000000000000']))
            ->assertSessionHasErrors('ultimo_numero');
        $this->assertSame(0, $correlativo->refresh()->ultimo_numero);

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['ultimo_numero' => '999999999999998']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->numero('M001P001', 999999999999999), $this->emitir($this->estab, $this->pv)->numero_control);
    }

    public function test_no_se_crea_un_punto_de_venta_en_un_establecimiento_borrado(): void
    {
        $admin = $this->admin();
        $cerrado = Establecimiento::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S008', 'nombre' => 'Sucursal cerrada', 'activo' => true]);
        $this->actingAs($admin)->delete(route('configuracion.establecimientos.destroy', $cerrado))->assertSessionHas('status');

        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), [
            'establecimiento_id' => $cerrado->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => '1',
        ])->assertSessionHasErrors('establecimiento_id');

        $this->assertSame(0, PuntoVenta::where('establecimiento_id', $cerrado->id)->count());
    }
}
