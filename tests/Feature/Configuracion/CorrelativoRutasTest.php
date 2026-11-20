<?php

namespace Tests\Feature\Configuracion;

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
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Rutas de Configuración › Correlativos, miradas por su efecto en la base.
 *
 * El correlativo es el registro que decide el número de cada factura. Borrarlo, bajarle el
 * último número o cambiarle el tipo, el establecimiento, el punto de venta o el ambiente
 * cuando ya emitió documentos hace que el siguiente choque con un número ya usado (error
 * al emitir) o que dos documentos queden con el mismo número ante Hacienda.
 */
class CorrelativoRutasTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        $this->en('2026-11-10 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function en(string $fechaHora): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHora, HoraNegocio::zona()));
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function sinPermiso(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function correlativo(array $datos = []): Correlativo
    {
        return Correlativo::create($datos + [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'serie' => null, 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /** Lo que manda el formulario al guardar, con los valores actuales del correlativo. */
    private function formulario(Correlativo $correlativo, array $cambios = []): array
    {
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

    /** Emite un CCF de verdad (borrador → generado), que consume el correlativo. */
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

    // ---------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------

    public function test_crear_un_correlativo_valido_lo_guarda_con_sus_datos(): void
    {
        $this->actingAs($this->admin())
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'serie' => 'A', 'ultimo_numero' => 7, 'activo' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.correlativos.index'));

        $this->assertDatabaseHas('correlativos', [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'serie' => 'A', 'ultimo_numero' => 7, 'activo' => true,
        ]);
    }

    public function test_la_misma_combinacion_no_se_crea_dos_veces(): void
    {
        $this->correlativo();

        $this->actingAs($this->admin())
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => '1',
            ])
            ->assertSessionHasErrors('tipo_dte_combo');

        $this->assertDatabaseCount('correlativos', 1);
    }

    public function test_el_punto_de_venta_tiene_que_ser_del_establecimiento_elegido(): void
    {
        $otro = Establecimiento::create([
            'empresa_id' => $this->estab->empresa_id, 'codigo' => 'S002', 'nombre' => 'Sucursal', 'activo' => true,
        ]);
        $pvAjeno = PuntoVenta::create(['establecimiento_id' => $otro->id, 'codigo' => 'P009', 'nombre' => 'Caja ajena', 'activo' => true]);

        $this->actingAs($this->admin())
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $pvAjeno->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => '1',
            ])
            ->assertSessionHasErrors('punto_venta_id');

        $this->assertDatabaseCount('correlativos', 0);
    }

    public function test_un_punto_de_venta_borrado_no_se_puede_elegir(): void
    {
        $borrado = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P008', 'nombre' => 'Caja vieja', 'activo' => true]);
        $borrado->delete();

        $this->actingAs($this->admin())
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $borrado->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => '1',
            ])
            ->assertSessionHasErrors('punto_venta_id');

        $this->assertDatabaseCount('correlativos', 0);
    }

    public function test_al_editar_tampoco_se_acepta_un_punto_de_venta_de_otro_establecimiento(): void
    {
        $correlativo = $this->correlativo();
        $otro = Establecimiento::create([
            'empresa_id' => $this->estab->empresa_id, 'codigo' => 'S002', 'nombre' => 'Sucursal', 'activo' => true,
        ]);
        $pvAjeno = PuntoVenta::create(['establecimiento_id' => $otro->id, 'codigo' => 'P009', 'nombre' => 'Caja ajena', 'activo' => true]);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['punto_venta_id' => $pvAjeno->id]))
            ->assertSessionHasErrors('punto_venta_id');

        $this->assertSame($this->pv->id, $correlativo->refresh()->punto_venta_id);
    }

    // ---------------------------------------------------------------------
    // update
    // ---------------------------------------------------------------------

    public function test_el_ultimo_numero_no_baja_del_ultimo_documento_emitido(): void
    {
        $correlativo = $this->correlativo(['ultimo_numero' => 4]);
        $dte = $this->emitirCcf();
        $this->assertStringEndsWith('000000000000005', $dte->numero_interno);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo->refresh(), ['ultimo_numero' => 3]))
            ->assertSessionHasErrors('ultimo_numero');

        $this->assertSame(5, $correlativo->refresh()->ultimo_numero);
    }

    public function test_el_ultimo_numero_puede_quedar_igual_o_subir(): void
    {
        $correlativo = $this->correlativo(['ultimo_numero' => 4]);
        $this->emitirCcf();

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo->refresh(), ['activo' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($correlativo->refresh()->activo);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['ultimo_numero' => 20]))
            ->assertSessionHasNoErrors();
        $this->assertSame(20, $correlativo->refresh()->ultimo_numero);
    }

    /**
     * Desde 2027 el número vuelve a 1 cada año: el piso es el del ejercicio en curso del
     * correlativo, no el de un año anterior. Si no, después del reinicio ya no se podría
     * guardar ningún cambio (ni desactivarlo).
     */
    public function test_despues_del_reinicio_anual_el_piso_es_el_del_ejercicio_nuevo(): void
    {
        $correlativo = $this->correlativo(['ultimo_numero' => 9]);
        $this->emitirCcf(); // 10 de 2026
        $this->en('2027-01-05 09:00');
        $nuevo = $this->emitirCcf(); // 1 de 2027
        $this->assertStringEndsWith('000000000000001', $nuevo->numero_interno);
        $this->assertSame(1, $correlativo->refresh()->ultimo_numero);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['activo' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($correlativo->refresh()->activo);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['ultimo_numero' => 0]))
            ->assertSessionHasErrors('ultimo_numero');
        $this->assertSame(1, $correlativo->refresh()->ultimo_numero);
    }

    public function test_con_documentos_emitidos_no_cambia_tipo_establecimiento_punto_de_venta_ni_ambiente(): void
    {
        $correlativo = $this->correlativo();
        $this->emitirCcf();
        $correlativo->refresh();

        $otro = Establecimiento::create([
            'empresa_id' => $this->estab->empresa_id, 'codigo' => 'S002', 'nombre' => 'Sucursal', 'activo' => true,
        ]);
        $pvOtro = PuntoVenta::create(['establecimiento_id' => $otro->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $pvMismo = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P003', 'nombre' => 'Caja 3', 'activo' => true]);

        $intentos = [
            'tipo_dte' => ['tipo_dte' => '01'],
            'ambiente' => ['ambiente' => '01'],
            'establecimiento_id' => ['establecimiento_id' => $otro->id, 'punto_venta_id' => $pvOtro->id],
            'punto_venta_id' => ['punto_venta_id' => $pvMismo->id],
        ];

        foreach ($intentos as $campo => $cambios) {
            $this->actingAs($this->admin())
                ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, $cambios))
                ->assertSessionHasErrors($campo);
        }

        $correlativo->refresh();
        $this->assertSame(TipoDte::CreditoFiscal, $correlativo->tipo_dte);
        $this->assertSame('00', $correlativo->ambiente->value);
        $this->assertSame($this->estab->id, $correlativo->establecimiento_id);
        $this->assertSame($this->pv->id, $correlativo->punto_venta_id);
    }

    public function test_sin_documentos_si_se_puede_cambiar_tipo_y_punto_de_venta(): void
    {
        $correlativo = $this->correlativo();
        $pvMismo = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P003', 'nombre' => 'Caja 3', 'activo' => true]);

        $this->actingAs($this->admin())
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, [
                'tipo_dte' => '01', 'punto_venta_id' => $pvMismo->id,
            ]))
            ->assertSessionHasNoErrors();

        $correlativo->refresh();
        $this->assertSame(TipoDte::Factura, $correlativo->tipo_dte);
        $this->assertSame($pvMismo->id, $correlativo->punto_venta_id);
    }

    public function test_el_cambio_del_ultimo_numero_queda_en_la_bitacora_con_quien_lo_hizo(): void
    {
        $correlativo = $this->correlativo(['ultimo_numero' => 10]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['ultimo_numero' => 25]))
            ->assertSessionHasNoErrors();

        $registro = Activity::query()
            ->where('log_name', 'configuracion')
            ->where('subject_type', Correlativo::class)
            ->where('subject_id', $correlativo->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame('modificó el correlativo', $registro->description);
        $this->assertSame($admin->id, (int) $registro->causer_id);
        $this->assertSame(10, $registro->properties['old']['ultimo_numero']);
        $this->assertSame(25, $registro->properties['attributes']['ultimo_numero']);
    }

    // ---------------------------------------------------------------------
    // destroy
    // ---------------------------------------------------------------------

    public function test_un_correlativo_con_documentos_emitidos_no_se_borra(): void
    {
        $correlativo = $this->correlativo();
        $dte = $this->emitirCcf();

        $this->actingAs($this->admin())
            ->from(route('configuracion.correlativos.index'))
            ->delete(route('configuracion.correlativos.destroy', $correlativo))
            ->assertRedirect(route('configuracion.correlativos.index'))
            ->assertSessionHas('error', 'Este correlativo ya tiene facturas emitidas. Desactivalo en su lugar.');

        $this->assertDatabaseHas('correlativos', ['id' => $correlativo->id]);
        $this->assertSame($correlativo->id, $dte->refresh()->correlativo_id);
    }

    public function test_un_correlativo_sin_documentos_se_borra(): void
    {
        $correlativo = $this->correlativo();

        $this->actingAs($this->admin())
            ->delete(route('configuracion.correlativos.destroy', $correlativo))
            ->assertRedirect(route('configuracion.correlativos.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('correlativos', ['id' => $correlativo->id]);
    }

    // ---------------------------------------------------------------------
    // permisos
    // ---------------------------------------------------------------------

    public function test_sin_permiso_de_configuracion_no_se_crea_ni_se_cambia_ni_se_borra(): void
    {
        $correlativo = $this->correlativo(['ultimo_numero' => 10]);
        $usuario = $this->sinPermiso();

        $this->actingAs($usuario)
            ->post(route('configuracion.correlativos.store'), [
                'tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => '1',
            ])
            ->assertForbidden();
        $this->actingAs($usuario)
            ->put(route('configuracion.correlativos.update', $correlativo), $this->formulario($correlativo, ['ultimo_numero' => 99]))
            ->assertForbidden();
        $this->actingAs($usuario)
            ->delete(route('configuracion.correlativos.destroy', $correlativo))
            ->assertForbidden();

        $this->assertDatabaseCount('correlativos', 1);
        $this->assertSame(10, $correlativo->refresh()->ultimo_numero);
    }
}
