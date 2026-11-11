<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\LiquidacionSalida;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El jefe de vendedores termina días y rutas desde la hoja de la oficina, aunque no haya
 * ido (docs/PLAN_TABLERO_RUTAS.md, sección 3 y PR 7). Elige quién de la salida entrega el
 * dinero; lo recibe siempre la oficina. No reabre días ni toca gastos ajenos.
 * Datos ficticios.
 */
class JefeTerminaSalidaTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-11-04';

    private User $oficina;

    private User $jefe;

    private User $otroJefe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(18, 0));
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->oficina = User::factory()->create(['activo' => true, 'name' => 'Oficina Prueba'])->assignRole('facturacion');
        $this->jefe = $this->jefe('Luis Inventado', 'jefe1@example.test');
        $this->otroJefe = $this->jefe('Marta Supuesta', 'jefe2@example.test');
    }

    private function jefe(string $nombre, string $correo): User
    {
        return app(CrearUsuario::class)->crear([
            'name' => $nombre, 'email' => $correo, 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => RolSistema::JefeVendedores->value,
        ]);
    }

    /** Una salida en curso con su propio vendedor (el jefe no va). */
    private function salida(string $ruta): SalidaRuta
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $persona = PersonalRuta::create(['nombre' => 'Vendedor '.$ruta, 'user_id' => $vendedor->id]);
        $salida = SalidaRuta::create([
            'ruta_id' => Ruta::create(['nombre' => $ruta])->id, 'fecha_inicio' => self::FECHA,
            'fecha_fin_estimada' => '2026-11-05', 'estado' => EstadoSalidaRuta::EnCurso,
        ]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

        return $salida;
    }

    private function ccf(SalidaRuta $salida, ?string $forma): Dte
    {
        $sala = Cliente::factory()->create(['cobro_en_entrega' => $forma])
            ->sucursales()->create(['nombre' => 'Sala '.(Dte::count() + 1), 'ruta_id' => $salida->ruta_id]);
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 907 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26060232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => self::FECHA, 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);
    }

    /**
     * El día del vendedor: entrega un CCF en efectivo y otro con cheque y echa gasolina. Queda
     * además un registro viejo de CCF facturado después de entregar, que no lleva dinero.
     *
     * @return array<string, SalidaRutaEntrega>
     */
    private function diaDelVendedor(SalidaRuta $salida): array
    {
        $persona = $salida->personal()->sole();
        $this->actingAs($persona->user);
        $entregas = [];
        foreach (['efectivo' => 'efectivo', 'cheque' => 'cheque'] as $clave => $forma) {
            $dte = $this->ccf($salida, $forma);
            $entregas[$clave] = $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id]);
            $this->patch(route('rutas.mi-salida.update', [$salida, $entregas[$clave]]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        }
        $this->post(route('rutas.mi-salida.gastos.store', $salida), ['concepto' => 'gasolina', 'monto' => '10.00'])->assertSessionHasNoErrors();

        // Ya no se anotan nuevos (04/11/2026), pero los registros viejos siguen fuera del dinero.
        $despues = $this->ccf($salida, 'efectivo');
        $salida->entregas()->create([
            'dte_id' => $despues->id, 'cliente_sucursal_id' => $despues->cliente_sucursal_id,
            'resultado' => 'entregado', 'fecha_resultado' => self::FECHA, 'entregado_por_id' => $persona->id,
            'origen_registro' => OrigenRegistroEntrega::FacturadoDespues, 'registrado_por' => $this->oficina->id,
        ]);
        $this->assertSame(OrigenRegistroEntrega::FacturadoDespues, SalidaRutaEntrega::where('dte_id', $despues->id)->sole()->origen_registro);

        return $entregas;
    }

    /** «Terminar día» y «Terminar ruta» desde la hoja de la oficina, como `$quien`. */
    private function terminar(SalidaRuta $salida, User $quien): void
    {
        $entregas = $this->diaDelVendedor($salida);
        $persona = $salida->personal()->sole();
        $this->actingAs($quien);

        $this->get(route('rutas.salidas.cierre', ['salida' => $salida, 'fecha' => self::FECHA]))->assertOk()->assertSee('Terminar día');
        $this->post(route('rutas.salidas.cerrar', $salida), ['fecha' => self::FECHA, 'entregas' => [
            $entregas['efectivo']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo'],
            $entregas['cheque']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque'],
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('rutas.salidas.liquidacion', [$salida, self::FECHA]))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->get(route('rutas.salidas.terminar', $salida))->assertOk()->assertSee('¿Quién entrega el dinero?')->assertSee($persona->nombre);
        $this->post(route('rutas.salidas.liquidar', $salida), ['entrega_dinero_personal_id' => $persona->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.salidas.terminar', $salida));

        $salida->refresh();
        $this->assertTrue($salida->estaLiquidada());
        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->estado);
        $this->assertSame($persona->id, $salida->entrega_dinero_personal_id);
        $this->assertSame($quien->id, $salida->liquidada_por);
        $this->assertSame($quien->id, $salida->cierreDelDia(self::FECHA)->cerrado_por);
    }

    /** Todo el dinero que dejó la salida: el cierre del día y la liquidación. */
    private function dinero(SalidaRuta $salida): array
    {
        $totales = app(LiquidacionSalida::class)->totales($salida->fresh());
        unset($totales['dias']);

        return [
            'cierres' => $salida->cierres()->orderBy('id')->get()->map->only([
                'efectivo_esperado', 'efectivo_cobrado', 'cheques_esperado', 'cheques_cobrado',
                'transferencias_esperado', 'transferencias_cobrado', 'gastos_total', 'a_entregar', 'entrega_dinero_personal_id',
            ])->all(),
            'totales' => $totales,
        ];
    }

    public function test_dos_jefes_terminan_salidas_donde_no_fueron_con_los_mismos_montos_que_la_oficina(): void
    {
        $deLaOficina = $this->salida('Oriente');
        $delJefe = $this->salida('Occidente');
        $delOtroJefe = $this->salida('Centro');

        $this->terminar($deLaOficina, $this->oficina);
        $this->terminar($delJefe, $this->jefe);
        $this->terminar($delOtroJefe, $this->otroJefe);

        // Efectivo y cheque de $100.25, $10.00 de gasolina; el facturado después no suma.
        $dinero = $this->dinero($deLaOficina);
        $this->assertSame([10025, 10025, 0, 1000, 9025], [$dinero['totales']['efectivo'], $dinero['totales']['cheques'],
            $dinero['totales']['transferencias'], $dinero['totales']['gastos'], $dinero['totales']['a_entregar']]);
        $this->assertSame([[
            'efectivo_esperado' => '100.25', 'efectivo_cobrado' => '100.25', 'cheques_esperado' => '100.25', 'cheques_cobrado' => '100.25',
            'transferencias_esperado' => '0.00', 'transferencias_cobrado' => '0.00', 'gastos_total' => '10.00', 'a_entregar' => '90.25',
            'entrega_dinero_personal_id' => null,
        ]], array_map(fn ($c) => array_map(fn ($v) => $v === null ? null : (string) $v, $c), $dinero['cierres']));
        $this->assertEquals($dinero, $this->dinero($delJefe));
        $this->assertEquals($dinero, $this->dinero($delOtroJefe));
    }

    public function test_la_hoja_impresa_dice_que_recibe_la_oficina_y_nunca_el_jefe(): void
    {
        $salida = $this->salida('Oriente');
        $this->terminar($salida, $this->jefe);
        $persona = $salida->personal()->sole();

        $this->get(route('rutas.salidas.terminar', $salida))->assertOk()
            ->assertSee('Entrega el dinero: '.$persona->nombre)->assertSee('Recibe: Oficina');
        $pdf = $this->get(route('rutas.salidas.liquidacion-final', $salida))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('liquidacion-ruta-oriente-2026-11-04.pdf', $pdf->headers->get('content-disposition'));

        $html = view('rutas.liquidacion.pdf', [
            'salida' => $salida->fresh()->load(['ruta', 'personal', 'entregaDinero']), 'empresa' => Empresa::first(),
            'totales' => app(LiquidacionSalida::class)->totales($salida), 'entregadas' => app(LiquidacionSalida::class)->entregadas($salida),
            'noEntregadas' => app(LiquidacionSalida::class)->noEntregadas($salida), 'gastos' => $salida->gastos,
        ])->render();
        $this->assertStringContainsString('Entrega: '.$persona->nombre, $html);
        $this->assertStringContainsString('Recibe: Oficina', $html);
        $this->assertStringNotContainsString('Luis Inventado', $html);
    }

    public function test_quien_entrega_el_dinero_tiene_que_ir_en_la_salida(): void
    {
        $salida = $this->salida('Oriente');
        $entregas = $this->diaDelVendedor($salida);
        $this->actingAs($this->jefe)->post(route('rutas.salidas.cerrar', $salida), ['fecha' => self::FECHA, 'entregas' => [
            $entregas['efectivo']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo'],
            $entregas['cheque']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque'],
        ]])->assertSessionHasNoErrors();

        $fichaDelJefe = PersonalRuta::where('user_id', $this->jefe->id)->sole();
        $ajena = PersonalRuta::create(['nombre' => 'Persona Ajena']);
        $url = route('rutas.salidas.liquidar', $salida);
        $this->post($url)->assertSessionHasErrors('entrega_dinero_personal_id');
        // El jefe no fue: no puede anotarse como quien entrega, ni anotar a alguien de afuera.
        $this->post($url, ['entrega_dinero_personal_id' => $fichaDelJefe->id])->assertSessionHasErrors('entrega_dinero_personal_id');
        $this->post($url, ['entrega_dinero_personal_id' => $ajena->id])->assertSessionHasErrors('entrega_dinero_personal_id');

        $this->assertFalse($salida->refresh()->estaLiquidada());
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->estado);
    }

    public function test_no_reabre_dias_ni_anota_o_borra_gastos_de_salidas_ajenas(): void
    {
        $salida = $this->salida('Oriente');
        $entregas = $this->diaDelVendedor($salida);
        $gasto = $salida->gastos()->sole();

        $this->actingAs($this->jefe);
        $this->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertSee('Gastos de hoy')->assertDontSee('+ Agregar gasto')->assertDontSee('Borrar gasto');
        $this->get(route('rutas.salidas.cierre', $salida))->assertOk()->assertDontSee('+ Agregar gasto')->assertDontSee('Borrar gasto');
        $this->post(route('rutas.salidas.gastos.store', $salida), ['concepto' => 'parqueo', 'monto' => '1.00', 'fecha' => self::FECHA])->assertForbidden();
        $this->delete(route('rutas.salidas.gastos.destroy', [$salida, $gasto]))->assertForbidden();
        $this->assertSame([$gasto->id], $salida->gastos()->pluck('id')->all());

        $this->post(route('rutas.salidas.cerrar', $salida), ['fecha' => self::FECHA, 'entregas' => [
            $entregas['efectivo']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo'],
            $entregas['cheque']->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque'],
        ]])->assertSessionHasNoErrors();
        $this->get(route('rutas.salidas.cierre', $salida))->assertOk()->assertSee('Día terminado')->assertDontSee('Reabrir el día');
        $this->post(route('rutas.salidas.reabrir', [$salida, self::FECHA]), ['motivo' => 'Corregir el cobro'])->assertForbidden();
        $this->assertTrue($salida->diaCerrado(self::FECHA));

        // Tampoco cancela la salida.
        $this->patch(route('rutas.salidas.cancelar', $salida))->assertForbidden();

        // La oficina sí reabre.
        $this->actingAs($this->oficina)->get(route('rutas.salidas.cierre', $salida))->assertOk()->assertSee('Reabrir el día');
        $this->post(route('rutas.salidas.reabrir', [$salida, self::FECHA]), ['motivo' => 'Corregir el cobro'])->assertSessionHasNoErrors();
        $this->assertFalse($salida->diaCerrado(self::FECHA));
    }

    public function test_vendedor_y_contabilidad_siguen_sin_terminar_desde_la_oficina(): void
    {
        $salida = $this->salida('Oriente');
        $this->diaDelVendedor($salida);
        $delaSalida = $salida->personal()->sole()->user;
        $contabilidad = User::factory()->create(['activo' => true])->assignRole('contabilidad');

        foreach (['vendedor de la salida' => $delaSalida, 'contabilidad' => $contabilidad] as $quien => $usuario) {
            $this->actingAs($usuario);
            $this->get(route('rutas.salidas.cierre', $salida))->assertForbidden();
            $this->post(route('rutas.salidas.cerrar', $salida), ['fecha' => self::FECHA])->assertForbidden();
            $this->get(route('rutas.salidas.terminar', $salida))->assertForbidden();
            $this->post(route('rutas.salidas.liquidar', $salida))->assertForbidden();
        }

        $this->assertFalse($salida->diaCerrado(self::FECHA));
        $this->assertFalse($salida->refresh()->estaLiquidada());
    }
}
