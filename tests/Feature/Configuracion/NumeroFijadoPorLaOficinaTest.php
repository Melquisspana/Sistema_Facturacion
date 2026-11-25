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
 * El último número que la oficina le pone a un correlativo sin documentos sirve para seguir
 * una numeración hecha fuera del sistema (por ejemplo, la de Conta Portable en una
 * contingencia). Esos números ya los tiene Hacienda aunque no estén en el sistema: el
 * siguiente documento no los puede repetir.
 */
class NumeroFijadoPorLaOficinaTest extends TestCase
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

    private function emitir(?PuntoVenta $caja = null): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => ($caja ?? $this->pv)->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    private function numero(int $n, string $caja = 'P001'): string
    {
        return 'DTE-03-M001'.$caja.'-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT);
    }

    /** Crea el correlativo por la pantalla, como la oficina. */
    private function crearCorrelativo(?int $puntoVentaId, int $ultimo): Correlativo
    {
        $this->actingAs($this->admin())->post(route('configuracion.correlativos.store'), [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $puntoVentaId ?? '',
            'ambiente' => '00', 'ultimo_numero' => (string) $ultimo, 'activo' => '1',
        ])->assertSessionHasNoErrors();

        return Correlativo::where('punto_venta_id', $puntoVentaId)->firstOrFail();
    }

    /** Lo edita por la pantalla, como la oficina. */
    private function editar(Correlativo $correlativo, array $cambios): void
    {
        $correlativo->refresh();
        $this->actingAs($this->admin())->put(route('configuracion.correlativos.update', $correlativo), $cambios + [
            'tipo_dte' => $correlativo->tipo_dte->value,
            'establecimiento_id' => $correlativo->establecimiento_id,
            'punto_venta_id' => $correlativo->punto_venta_id,
            'ambiente' => $correlativo->ambiente->value,
            'serie' => $correlativo->serie,
            'ultimo_numero' => $correlativo->ultimo_numero,
            'activo' => $correlativo->activo ? '1' : '0',
        ])->assertSessionHasNoErrors();
    }

    private function desactivar(Correlativo $correlativo): void
    {
        $this->editar($correlativo, ['activo' => '0']);
    }

    private function reinicios(Correlativo $correlativo): int
    {
        return Activity::query()
            ->where('subject_type', $correlativo->getMorphClass())
            ->where('subject_id', $correlativo->id)
            ->where('description', 'like', 'reinició%')
            ->count();
    }

    // ---------------------------------------------------------------------
    // Correlativo nuevo en 2027
    // ---------------------------------------------------------------------

    public function test_en_2027_un_correlativo_nuevo_respeta_el_numero_que_le_puso_la_oficina(): void
    {
        $this->en('2027-02-01 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 0);
        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
        $this->assertSame($this->numero(2), $this->emitir()->numero_control);

        // La caja numeró hasta el 10 fuera del sistema: la oficina crea uno general en el 10.
        $this->desactivar($caja);
        $general = $this->crearCorrelativo(null, 10);

        $this->assertSame($this->numero(11), $this->emitir()->numero_control);
        $this->assertSame(11, $general->refresh()->ultimo_numero);
        $this->assertSame(0, $this->reinicios($general), 'Un correlativo creado en 2027 no se reinicia por cambio de año.');
    }

    public function test_en_2027_con_la_serie_vacia_sigue_despues_del_numero_de_la_oficina(): void
    {
        $this->en('2027-02-01 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 50);

        $this->assertSame($this->numero(51), $this->emitir()->numero_control);
        $this->assertSame(51, $caja->refresh()->ultimo_numero);
        $this->assertSame(0, $this->reinicios($caja));
    }

    // ---------------------------------------------------------------------
    // Números usados fuera del sistema (correlativo sin documentos)
    // ---------------------------------------------------------------------

    public function test_un_correlativo_que_ya_numero_fuera_del_sistema_no_se_borra(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);

        $this->actingAs($this->admin())
            ->from(route('configuracion.correlativos.index'))
            ->delete(route('configuracion.correlativos.destroy', $caja))
            ->assertRedirect(route('configuracion.correlativos.index'))
            ->assertSessionHas('error', 'Este correlativo ya va en el número 500: esos números ya se usaron, aunque no estén en el sistema. Desactivalo en su lugar.');

        $this->assertDatabaseHas('correlativos', ['id' => $caja->id, 'ultimo_numero' => 500]);
    }

    /**
     * La caja se alineó a lo que numeró por fuera (500) y nunca emitió en el sistema. Si se
     * desactiva, como pide el aviso, y numera el correlativo general, sigue después del 500.
     */
    public function test_el_correlativo_general_no_repite_lo_que_la_caja_numero_fuera_del_sistema(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);
        $this->desactivar($caja);
        $general = $this->crearCorrelativo(null, 0);

        $this->assertSame($this->numero(501), $this->emitir()->numero_control);
        $this->assertSame(501, $general->refresh()->ultimo_numero);

        $salto = Activity::query()
            ->where('log_name', 'configuracion')
            ->where('subject_type', $general->getMorphClass())
            ->where('subject_id', $general->id)
            ->where('description', 'like', 'adelantó el correlativo%')
            ->sole();
        $this->assertSame(500, $salto->properties['mayor_usado_en_serie']);
    }

    /** Desde 2027 la numeración vuelve a 1: lo que la caja numeró el año anterior ya no cuenta. */
    public function test_lo_que_la_caja_numero_otro_anio_no_cuenta_para_el_general(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);
        $this->desactivar($caja);

        $this->en('2027-01-05 08:00:00');
        $this->crearCorrelativo(null, 0);

        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
    }

    // ---------------------------------------------------------------------
    // Correlativo con documentos del año anterior
    // ---------------------------------------------------------------------

    /**
     * La caja numeró en el sistema hasta el 800 de 2026. En enero de 2027 el sistema se cae y
     * Conta Portable emite del 1 al 30 de 2027. La oficina pone 30: el piso de 2026 no cuenta,
     * y el primer documento del sistema sale con el 31, no con el 1 que ya usó Conta Portable.
     */
    public function test_con_documentos_del_anio_anterior_la_oficina_fija_el_numero_del_anio_nuevo(): void
    {
        $this->en('2026-12-20 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 798);
        $this->emitir();
        $this->assertSame($this->numero(800), $this->emitir()->numero_control);

        $this->en('2027-01-04 09:00:00');
        $this->editar($caja, ['ultimo_numero' => '30']);

        $this->assertSame($this->numero(31), $this->emitir()->numero_control);
        $this->assertSame(31, $caja->refresh()->ultimo_numero);
        $this->assertSame(0, $this->reinicios($caja), 'El número que la oficina fijó en 2027 ya es de 2027.');
    }

    /** El piso sigue frenando: no se baja de un número que ya usó un documento de este año. */
    public function test_en_el_anio_nuevo_no_se_baja_de_lo_ya_emitido_ese_anio(): void
    {
        $this->en('2026-12-20 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 798);
        $this->emitir();

        $this->en('2027-01-04 09:00:00');
        $this->editar($caja, ['ultimo_numero' => '30']);
        $this->emitir(); // 31 de 2027

        $caja->refresh();
        $this->actingAs($this->admin())->put(route('configuracion.correlativos.update', $caja), [
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => '20', 'activo' => '1',
        ])->assertSessionHasErrors(['ultimo_numero' => 'No puede ser menor que 31: ese número ya lo usó un documento emitido con este correlativo.']);
        $this->assertSame(31, $caja->refresh()->ultimo_numero);
    }

    /**
     * Desactivarlo y volver a activarlo en enero de 2027 no cambia el número: sigue siendo el
     * que se fijó en 2026, y el primer documento de 2027 vuelve a 1, no sale con el 501.
     */
    public function test_desactivar_y_reactivar_en_2027_no_hace_seguir_el_numero_de_2026(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);

        $this->en('2027-01-05 08:00:00');
        $this->desactivar($caja);
        $this->editar($caja, ['activo' => '1', 'serie' => 'A']);

        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
        $this->assertSame(1, $this->reinicios($caja));
    }

    /** Lo mismo con el general: la caja que se desactiva en 2027 no le pasa su número de 2026. */
    public function test_la_caja_desactivada_en_2027_no_le_pasa_al_general_su_numero_de_2026(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);

        $this->en('2027-01-05 08:00:00');
        $this->desactivar($caja);
        $this->crearCorrelativo(null, 0);

        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
    }

    // ---------------------------------------------------------------------
    // Un correlativo general para varias cajas
    // ---------------------------------------------------------------------

    /**
     * Un solo correlativo general (sin punto de venta) para dos cajas: cada caja sigue su
     * propia serie y empieza el año en 1, sin huecos por lo que numeró la otra.
     */
    public function test_un_general_para_dos_cajas_numera_cada_caja_por_su_serie(): void
    {
        $this->en('2027-01-04 08:00:00');
        $p002 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $general = $this->crearCorrelativo(null, 0);

        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
        $this->assertSame($this->numero(1, 'P002'), $this->emitir($p002)->numero_control);
        $this->assertSame($this->numero(2), $this->emitir()->numero_control);
        $this->assertSame($this->numero(2, 'P002'), $this->emitir($p002)->numero_control);

        // Lo que el motor mueve no pasa por un cambio a mano en la auditoría.
        $this->assertSame(0, Activity::query()
            ->where('subject_type', $general->getMorphClass())
            ->where('subject_id', $general->id)
            ->where('description', 'modificó el correlativo')
            ->count());
    }

    /** Lo que la oficina le fija al general sí se respeta, en la caja que emite primero. */
    public function test_el_numero_que_la_oficina_le_fija_al_general_se_respeta(): void
    {
        $this->en('2027-01-04 08:00:00');
        $p002 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $this->crearCorrelativo(null, 40);

        $this->assertSame($this->numero(41), $this->emitir()->numero_control);
        $this->assertSame($this->numero(42), $this->emitir()->numero_control);
        $this->assertSame($this->numero(1, 'P002'), $this->emitir($p002)->numero_control);
    }

    /**
     * Los correlativos de antes de la bitácora no tienen su alta anotada. Un general alineado
     * entonces, sin documentos, sigue después de su número aunque la bitácora no lo explique.
     */
    public function test_un_general_alineado_sin_bitacora_sigue_despues_de_su_numero(): void
    {
        $this->en('2026-11-10 10:00:00');
        $general = $this->crearCorrelativo(null, 0);
        Correlativo::whereKey($general->id)->update(['ultimo_numero' => 50]);

        $this->assertSame($this->numero(51), $this->emitir()->numero_control);
    }

    /** Lo que se fijó en 2026 es de 2026: el primer documento de 2027 vuelve a 1. */
    public function test_un_numero_fijado_en_2026_sin_documentos_vuelve_a_uno_en_2027(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crearCorrelativo($this->pv->id, 500);

        $this->en('2027-01-05 08:00:00');
        $this->assertSame($this->numero(1), $this->emitir()->numero_control);
        $this->assertSame(1, $this->reinicios($caja));
    }
}
