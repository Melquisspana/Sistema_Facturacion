<?php

namespace Tests\Feature\Configuracion;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
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
use App\Support\HoraNegocio;
use App\Support\Ubicacion\UbicacionCoherenteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La configuración fiscal usada como la oficina, de punta a punta: crea el establecimiento,
 * el punto de venta y el correlativo por las pantallas, emite documentos que consumen
 * números y después intenta todo lo que podría partir la numeración. Cada documento tiene
 * que salir con el número que sigue, sin repetir ninguno.
 */
class NumeracionFiscalRecorridoTest extends TestCase
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

    // ---------------------------------------------------------------------
    // El recorrido
    // ---------------------------------------------------------------------

    public function test_recorrido_de_la_oficina_de_punta_a_punta(): void
    {
        $admin = $this->admin();

        // 1. Establecimiento, punto de venta y correlativo, por las pantallas.
        $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), [
            'empresa_id' => $this->empresa->id, 'codigo' => 'S005', 'nombre' => 'Sucursal Sur',
            'tipo_establecimiento' => '01', ...UbicacionCoherenteFactory::tercia(), 'activo' => '1',
        ])->assertSessionHasNoErrors();
        $estab = Establecimiento::where('codigo', 'S005')->firstOrFail();

        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), [
            'establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja sur', 'activo' => '1',
        ])->assertSessionHasNoErrors();
        $pv = PuntoVenta::where('establecimiento_id', $estab->id)->firstOrFail();

        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), [
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => '0', 'activo' => '1',
        ])->assertSessionHasNoErrors();
        $correlativo = Correlativo::where('establecimiento_id', $estab->id)->firstOrFail();

        // 2. Tres CCF consumen el 1, el 2 y el 3.
        foreach ([1, 2, 3] as $n) {
            $this->assertSame($this->numero('S005P001', $n), $this->emitir($estab, $pv)->numero_control);
        }
        $this->assertSame(3, $correlativo->refresh()->ultimo_numero);

        // 3. Con documentos, nada de esto pasa.
        $otro = Establecimiento::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S006', 'nombre' => 'Sucursal Este', 'activo' => true]);
        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['ultimo_numero' => '2']))
            ->assertSessionHasErrors('ultimo_numero');
        $this->actingAs($admin)->delete(route('configuracion.correlativos.destroy', $correlativo))
            ->assertSessionHas('error');
        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), $this->formCorrelativo($correlativo, ['ultimo_numero' => '0']))
            ->assertSessionHasErrors('tipo_dte_combo');
        $this->actingAs($admin)->put(route('configuracion.puntos-venta.update', $pv), $this->formPuntoVenta($pv, ['codigo' => 'P002']))
            ->assertSessionHasErrors('codigo');
        $this->actingAs($admin)->put(route('configuracion.puntos-venta.update', $pv), $this->formPuntoVenta($pv, ['establecimiento_id' => $otro->id]))
            ->assertSessionHasErrors('establecimiento_id');
        $this->actingAs($admin)->delete(route('configuracion.puntos-venta.destroy', $pv))
            ->assertSessionHas('error');
        $this->actingAs($admin)->put(route('configuracion.establecimientos.update', $estab), $this->formEstablecimiento($estab, ['codigo' => 'S007']))
            ->assertSessionHasErrors('codigo');
        $this->actingAs($admin)->delete(route('configuracion.establecimientos.destroy', $estab))
            ->assertSessionHas('error');

        $this->assertSame(1, Correlativo::where('establecimiento_id', $estab->id)->count());
        $this->assertSame(3, $correlativo->refresh()->ultimo_numero);
        $this->assertSame('P001', $pv->refresh()->codigo);
        $this->assertSame($estab->id, $pv->establecimiento_id);
        $this->assertSame('S005', $estab->refresh()->codigo);
        $this->assertNotSoftDeleted($pv);
        $this->assertNotSoftDeleted($estab);

        // 4. Desactivado no emite; vuelto a activar, sigue en el 4.
        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['activo' => '0']))
            ->assertSessionHasNoErrors();
        try {
            $this->emitir($estab, $pv);
            $this->fail('Con el correlativo desactivado no tenía que emitir.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('No hay un correlativo válido', $e->getMessage());
        }

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['activo' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->numero('S005P001', 4), $this->emitir($estab, $pv)->numero_control);

        // 5. Subirlo a mano deja un hueco a propósito, y el siguiente sale después.
        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, ['ultimo_numero' => '10']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->numero('S005P001', 11), $this->emitir($estab, $pv)->numero_control);
    }

    public function test_sin_documentos_la_oficina_corrige_el_correlativo_libremente(): void
    {
        $admin = $this->admin();
        $correlativo = $this->correlativo(['ultimo_numero' => 10]);
        $p002 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $otro = Establecimiento::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S006', 'nombre' => 'Sucursal Este', 'activo' => true]);
        $pvOtro = PuntoVenta::create(['establecimiento_id' => $otro->id, 'codigo' => 'P001', 'nombre' => 'Caja este', 'activo' => true]);

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, [
            'tipo_dte' => '01', 'ambiente' => '01', 'punto_venta_id' => $p002->id, 'ultimo_numero' => '3',
        ]))->assertSessionHasNoErrors();
        $correlativo->refresh();
        $this->assertSame(TipoDte::Factura, $correlativo->tipo_dte);
        $this->assertSame('01', $correlativo->ambiente->value);
        $this->assertSame($p002->id, $correlativo->punto_venta_id);
        $this->assertSame(3, $correlativo->ultimo_numero);

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $correlativo), $this->formCorrelativo($correlativo, [
            'establecimiento_id' => $otro->id, 'punto_venta_id' => $pvOtro->id, 'ultimo_numero' => '0',
        ]))->assertSessionHasNoErrors();
        $this->assertSame($otro->id, $correlativo->refresh()->establecimiento_id);

        $this->actingAs($admin)->delete(route('configuracion.correlativos.destroy', $correlativo))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('correlativos', ['id' => $correlativo->id]);
    }

    // ---------------------------------------------------------------------
    // Fallas encontradas
    // ---------------------------------------------------------------------

    /**
     * Un correlativo sin punto de venta sirve para todos los del establecimiento. Si se crea
     * desde cero cuando el de la caja ya numeró, o si la caja vuelve a su propio correlativo
     * después, el siguiente documento no puede repetir un número ya usado en esa caja.
     */
    public function test_un_correlativo_general_no_repite_numeros_ya_usados_en_la_caja(): void
    {
        $admin = $this->admin();
        $propio = $this->correlativo();
        foreach ([1, 2, 3] as $n) {
            $this->assertSame($this->numero('M001P001', $n), $this->emitir($this->estab, $this->pv)->numero_control);
        }

        // Se desactiva el de la caja y se crea uno general desde cero.
        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $propio), $this->formCorrelativo($propio, ['activo' => '0']))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => '',
            'ambiente' => '00', 'ultimo_numero' => '0', 'activo' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->numero('M001P001', 4), $this->emitir($this->estab, $this->pv)->numero_control);

        // La caja vuelve a su correlativo, que se había quedado en el 3.
        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $propio), $this->formCorrelativo($propio, ['activo' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->numero('M001P001', 5), $this->emitir($this->estab, $this->pv)->numero_control);
    }

    /**
     * Desde 2027 un correlativo nuevo sin documentos arranca el año en cero aunque se le
     * ponga un número: igual tiene que seguir después de lo que ya numeró la caja ese año.
     */
    public function test_en_2027_un_correlativo_nuevo_sigue_despues_de_lo_que_la_caja_ya_numero_ese_anio(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2027-02-01 10:00', HoraNegocio::zona()));
        $propio = $this->correlativo();
        foreach ([1, 2] as $n) {
            $this->assertSame($this->numero('M001P001', $n), $this->emitir($this->estab, $this->pv)->numero_control);
        }

        $this->actingAs($admin)->put(route('configuracion.correlativos.update', $propio), $this->formCorrelativo($propio, ['activo' => '0']))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => '',
            'ambiente' => '00', 'ultimo_numero' => '2', 'activo' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->numero('M001P001', 3), $this->emitir($this->estab, $this->pv)->numero_control);
    }

    // ---------------------------------------------------------------------
    // Doble envío y valores raros
    // ---------------------------------------------------------------------

    public function test_el_doble_envio_no_duplica_nada(): void
    {
        $admin = $this->admin();
        $correlativo = [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => '',
            'ambiente' => '00', 'ultimo_numero' => '0', 'activo' => '1',
        ];
        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), $correlativo)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.correlativos.store'), $correlativo)->assertSessionHasErrors('tipo_dte_combo');

        $estab = ['empresa_id' => $this->empresa->id, 'codigo' => 'S004', 'nombre' => 'Sucursal', ...UbicacionCoherenteFactory::tercia(), 'activo' => '1'];
        $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), $estab)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.establecimientos.store'), $estab)->assertSessionHasErrors('codigo');

        $pv = ['establecimiento_id' => $this->estab->id, 'codigo' => 'P004', 'nombre' => 'Caja 4', 'activo' => '1'];
        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), $pv)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('configuracion.puntos-venta.store'), $pv)->assertSessionHasErrors('codigo');

        $this->assertSame(1, Correlativo::count());
        $this->assertSame(1, Establecimiento::where('codigo', 'S004')->count());
        $this->assertSame(1, PuntoVenta::where('codigo', 'P004')->count());

        // Borrar dos veces: la segunda ya no encuentra nada que borrar.
        $creado = Correlativo::firstOrFail();
        $this->actingAs($admin)->delete(route('configuracion.correlativos.destroy', $creado))->assertSessionHas('status');
        $this->actingAs($admin)->delete(route('configuracion.correlativos.destroy', $creado))->assertNotFound();
    }

    public function test_valores_raros_en_el_correlativo_no_guardan_nada(): void
    {
        $admin = $this->admin();
        $base = [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => '0', 'activo' => '1',
        ];
        $raros = [
            'ultimo_numero' => ['-1', 'abc', '1.5', '', '99999999999999999999'],
            'tipo_dte' => ['99', '', '03; drop'],
            'ambiente' => ['02', ''],
            'establecimiento_id' => ['999999', 'abc', ''],
            'punto_venta_id' => ['999999', 'abc'],
            'serie' => [str_repeat('A', 11)],
        ];

        foreach ($raros as $campo => $valores) {
            foreach ($valores as $valor) {
                $this->actingAs($admin)->post(route('configuracion.correlativos.store'), [$campo => $valor] + $base)
                    ->assertSessionHasErrors($campo);
            }
        }

        $this->assertSame(0, Correlativo::count());
    }

    public function test_sin_permiso_no_entra_ni_a_las_pantallas(): void
    {
        $usuario = User::factory()->create()->assignRole('facturacion');
        $correlativo = $this->correlativo();

        foreach ([
            route('configuracion.correlativos.index'),
            route('configuracion.correlativos.create'),
            route('configuracion.correlativos.edit', $correlativo),
            route('configuracion.establecimientos.edit', $this->estab),
            route('configuracion.puntos-venta.edit', $this->pv),
        ] as $url) {
            $this->actingAs($usuario)->get($url)->assertForbidden();
        }

        auth()->logout();
        $this->get(route('configuracion.correlativos.index'))->assertRedirect(route('login'));
    }
}
