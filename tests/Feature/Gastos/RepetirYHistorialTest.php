<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use App\Services\Gastos\Recurrencia\RepetirGasto;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Este gasto se repite» e «Historial de pagos».
 *
 * Lo que defienden estas pruebas:
 *
 *  1. CONVERTIR UN GASTO NO LO DUPLICA NI LO TOCA. El gasto original queda como el
 *     período que le toca, con sus pagos intactos, y la generación nunca crea un
 *     gemelo para esa fecha. Si esto se rompiera, alguien pagaría dos veces el mismo
 *     alquiler.
 *  2. NO SE INVENTAN HISTÓRICOS. Convertir el gasto de septiembre no crea los de
 *     enero a agosto.
 *  3. NO SE VUELVE A PEDIR EL IMPORTE, pero SÍ se puede elegir si de ahora en
 *     adelante será fijo o cambiará.
 *  4. EL HISTORIAL DE PAGOS RESPETA EL ALCANCE. Un pago mixto se muestra a quien no
 *     lo alcanza entero con su subtotal, jamás con el total.
 */
class RepetirYHistorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('gastos.enabled', true);
    }

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function operador(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::GastosRecurrencias, PermisoSistema::DteVer,
        ]));
    }

    private function soloEmpresa(): User
    {
        return $this->usuario([PermisoSistema::GastosVer->value, PermisoSistema::GastosPagosRegistrar->value]);
    }

    /** @param  array<int, array{0: string, 1: ?string}>  $cuotas */
    private function gasto(User $u, array $extra = [], array $cuotas = [['400.00', '2026-09-15']]): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Inmobiliaria Sur', 'concepto' => 'Alquiler del local',
            'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '400.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ], $extra));

        foreach ($cuotas as $i => [$importe, $vence]) {
            $gasto->cuotas()->create(['numero' => $i + 1, 'importe' => $importe, 'vence' => $vence]);
        }

        return $gasto->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function repetir(User $u, Gasto $g, array $extra = []): Regla
    {
        return app(RepetirGasto::class)->desdeGasto($u, $g, array_replace([
            'frecuencia' => 'mensual',
            'monto_modo' => 'fijo',
            'dia_mes' => 15,
            'dias_generar_antes' => 0,
        ], $extra));
    }

    private function generar(Regla $regla, string $hoy, ?User $u = null): array
    {
        return app(GenerarObligaciones::class)->paraRegla($regla->fresh(), CarbonImmutable::parse($hoy)->startOfDay(), $u);
    }

    // ─────────────────── Convertir un gasto que ya existe ───────────────────

    public function test_convertir_un_gasto_no_lo_duplica_ni_crea_otro_para_su_periodo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $regla = $this->repetir($u, $gasto);

        $this->assertSame(1, Gasto::count());
        $ocurrencia = Ocurrencia::where('regla_id', $regla->id)->firstOrFail();
        $this->assertSame('2026-09', $ocurrencia->periodo);
        $this->assertSame($gasto->id, $ocurrencia->gasto_id);

        // Y la generación, ese mismo día, no crea nada: el período está ocupado.
        $this->assertSame(0, $this->generar($regla, '2026-09-15')['generadas']);
        $this->assertSame(1, Gasto::count());
    }

    public function test_convertir_un_gasto_pagado_no_toca_sus_pagos(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '400.00', 'fecha' => '2026-09-05',
            'metodo' => 'transferencia', 'pagado_por' => $u->id,
            'sin_comprobante' => 'Comprobante pendiente de recibir.',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '400.00']]);

        $antes = app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-09-20');

        $this->repetir($u, $gasto);

        $despues = app(SaldosGastos::class)->resumen($gasto->fresh(), '2026-09-20');

        $this->assertSame($antes, $despues);
        $this->assertSame(1, Pago::count());
        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
        $this->assertSame('pagada', $despues['liquidacion']);
    }

    public function test_convertir_no_inventa_los_meses_anteriores(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $regla = $this->repetir($u, $gasto);

        // La vigencia arranca en el período del propio gasto: no hay nada antes.
        $this->assertSame('2026-09-01', $regla->vigente_desde->format('Y-m-d'));

        $resultado = $this->generar($regla, '2026-09-15');

        $this->assertSame(0, $resultado['generadas']);
        $this->assertSame([], $resultado['fuera_de_ventana']);
        $this->assertSame(1, Ocurrencia::count());
    }

    public function test_el_periodo_siguiente_si_se_crea_y_nace_sin_pagar(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $regla = $this->repetir($u, $gasto);

        $resultado = $this->generar($regla, '2026-10-15', $u);

        $this->assertSame(1, $resultado['generadas']);
        $this->assertSame(['2026-10'], $resultado['periodos']);

        $nuevo = Gasto::where('id', '!=', $gasto->id)->firstOrFail();
        $this->assertSame('400.00', $nuevo->importe);
        $this->assertSame(0, app(SaldosGastos::class)->resumen($nuevo, '2026-10-15')['pagado']);
        // El pago del primero sigue siendo del primero.
        $this->assertSame(0, DB::table('gastos_pago_aplicaciones')->count());
    }

    public function test_repetir_dos_veces_no_crea_dos_repeticiones(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $primera = $this->repetir($u, $gasto);
        $segunda = $this->repetir($u, $gasto);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, Regla::count());
        $this->assertSame(1, Ocurrencia::count());
    }

    public function test_un_gasto_con_varias_cuotas_queda_fuera_y_se_explica(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, [], [['200.00', '2026-09-15'], ['200.00', '2026-10-15']]);

        $motivo = app(RepetirGasto::class)->motivoNoRepetible($gasto);
        $this->assertNotNull($motivo);
        $this->assertStringContainsString('varias cuotas', $motivo);

        try {
            $this->repetir($u, $gasto);
            $this->fail('Un gasto en cuotas no tendría que poder repetirse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('plan de pagos', $e->getMessage());
        }

        $this->assertSame(0, Regla::count());
    }

    public function test_un_gasto_que_ya_viene_de_una_repeticion_no_se_repite_otra_vez(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $regla = $this->repetir($u, $gasto);
        $this->generar($regla, '2026-10-15', $u);

        $generado = Gasto::where('id', '!=', $gasto->id)->firstOrFail();

        $this->assertNotNull(app(RepetirGasto::class)->motivoNoRepetible($generado));
        $this->assertSame($regla->id, app(RepetirGasto::class)->reglaDe($generado)?->id);
    }

    // ─────────────────── Fijo o variable, sin volver a pedir el importe ───────────────────

    public function test_se_puede_elegir_que_cambie_aunque_este_gasto_ya_tenga_monto(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $regla = $this->repetir($u, $gasto, ['monto_modo' => 'variable']);

        $this->assertSame('variable', $regla->monto_modo);
        $this->assertNull($regla->importe);
        // El gasto de ahora conserva SU importe: cambiar el futuro no reescribe esto.
        $this->assertSame('400.00', $gasto->fresh()->importe);

        $this->generar($regla, '2026-10-15', $u);
        $siguiente = Gasto::where('id', '!=', $gasto->id)->firstOrFail();
        $this->assertNull($siguiente->importe);
    }

    public function test_con_importe_fijo_se_toma_el_del_gasto_sin_preguntarlo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['importe' => '512.75'], [['512.75', '2026-09-15']]);

        $regla = $this->repetir($u, $gasto, ['monto_modo' => 'fijo']);

        $this->assertSame('512.75', $regla->importe);
    }

    public function test_un_gasto_sin_monto_no_puede_repetirse_con_importe_fijo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['importe' => null], []);

        try {
            $this->repetir($u, $gasto, ['monto_modo' => 'fijo']);
            $this->fail('Sin importe no se puede repetir con uno fijo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('todavía no tiene importe', $e->getMessage());
        }

        // Pero sí «cambia cada vez», que es lo honesto.
        $regla = $this->repetir($u, $gasto, ['monto_modo' => 'variable', 'dia_mes' => 10]);
        $this->assertSame('variable', $regla->monto_modo);
    }

    // ─────────────────── El próximo vencimiento, a la vista ───────────────────

    public function test_se_puede_saber_cuando_vence_el_proximo(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);
        $regla = $this->repetir($u, $gasto);

        $proximo = app(RepetirGasto::class)->proximoVencimiento($regla, CarbonImmutable::parse('2026-09-20'));

        $this->assertSame('2026-10', $proximo['periodo']);
        $this->assertSame('2026-10-15', $proximo['vence']);
    }

    public function test_la_ficha_de_la_repeticion_muestra_el_proximo_vencimiento(): void
    {
        $u = $this->operador();
        $regla = $this->repetir($u, $this->gasto($u));

        $this->actingAs($u)->get(route('gastos.reglas.show', $regla))
            ->assertOk()
            ->assertSee('El próximo vence');
    }

    // ─────────────────── Desde la pantalla ───────────────────

    public function test_la_ficha_del_gasto_ofrece_repetirlo_y_la_accion_funciona(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->get(route('gastos.show', $gasto))
            ->assertOk()
            ->assertSee('¿Este gasto se repite?');

        $this->actingAs($u)->post(route('gastos.repetir', $gasto), [
            'repeticion' => ['frecuencia' => 'mensual', 'monto_modo' => 'fijo', 'dia_mes' => 15, 'dias_generar_antes' => 0],
        ])->assertRedirect();

        $this->assertSame(1, Regla::count());
        $this->assertSame(1, Gasto::count());
    }

    public function test_sin_permiso_de_repeticiones_no_se_puede_convertir(): void
    {
        $u = $this->soloEmpresa();
        $gasto = $this->gasto($u);

        $this->actingAs($u)->post(route('gastos.repetir', $gasto), [
            'repeticion' => ['frecuencia' => 'mensual', 'monto_modo' => 'fijo', 'dia_mes' => 15, 'dias_generar_antes' => 0],
        ])->assertForbidden();

        $this->assertSame(0, Regla::count());
    }

    public function test_registrar_un_gasto_marcando_que_se_repite_crea_las_dos_cosas(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->post(route('gastos.store'), [
            'clave' => (string) Str::uuid(),
            'beneficiario' => 'Inmobiliaria Sur', 'concepto' => 'Alquiler del local',
            'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '400.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'monto_pendiente' => 0, 'ya_pagado' => 0,
            'cuotas' => [['importe' => '400.00', 'vence' => '2026-09-15']],
            'se_repite' => 1,
            'repeticion' => ['frecuencia' => 'mensual', 'monto_modo' => 'fijo', 'dia_mes' => 15, 'dias_generar_antes' => 0],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Regla::count());
        // El gasto recién registrado ES el primer período: no hay un segundo gasto.
        $this->assertSame(Gasto::firstOrFail()->id, Ocurrencia::firstOrFail()->gasto_id);
    }

    // ─────────────────── Historial de pagos ───────────────────

    private function pagoMixto(User $duena): Pago
    {
        $empresa = $this->gasto($duena, ['concepto' => 'Internet'], [['40.00', '2026-09-01']]);
        $personal = $this->gasto($duena, [
            'concepto' => 'Colegiatura', 'ambito' => 'personal', 'persona' => 'Melqui',
            'importe' => '60.00',
        ], [['60.00', '2026-09-01']]);

        return app(RegistrarPago::class)->registrar($duena, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-09-02',
            'metodo' => 'transferencia', 'pagado_por' => $duena->id, 'referencia' => 'TRF-9001',
            'sin_comprobante' => 'Sin comprobante todavía.',
        ], [
            ['cuota_id' => $empresa->cuotas->first()->id, 'importe' => '40.00'],
            ['cuota_id' => $personal->cuotas->first()->id, 'importe' => '60.00'],
        ]);
    }

    public function test_el_historial_muestra_el_subtotal_y_nunca_el_total_de_un_pago_mixto(): void
    {
        $duena = $this->operador();
        $this->pagoMixto($duena);

        $soloEmpresa = $this->soloEmpresa();
        $fila = app(ConsultaGastos::class)->pagosVisibles($soloEmpresa, [])->firstOrFail();

        $this->assertSame(4000, (int) $fila->importe_visible);
        $this->assertSame(1, (int) $fila->gastos_visibles);
        $this->assertSame(2, (int) $fila->gastos_totales);

        // Y la pantalla no deja escapar ni el total ni la referencia.
        $respuesta = $this->actingAs($soloEmpresa)->get(route('gastos.pagos.index'))->assertOk();
        $respuesta->assertSee('40.00');
        $respuesta->assertDontSee('100.00');
        $respuesta->assertDontSee('TRF-9001');
        $respuesta->assertDontSee('Colegiatura');
    }

    public function test_quien_alcanza_todo_si_ve_el_pago_entero(): void
    {
        $duena = $this->operador();
        $this->pagoMixto($duena);

        $fila = app(ConsultaGastos::class)->pagosVisibles($duena, [])->firstOrFail();

        $this->assertSame(10000, (int) $fila->importe_visible);
        $this->assertSame(2, (int) $fila->gastos_visibles);
        $this->assertSame(2, (int) $fila->gastos_totales);

        $this->actingAs($duena)->get(route('gastos.pagos.index'))->assertOk()->assertSee('TRF-9001');
    }

    public function test_un_pago_de_otro_ambito_no_aparece_en_absoluto(): void
    {
        $duena = $this->operador();
        $personal = $this->gasto($duena, [
            'concepto' => 'Colegiatura', 'ambito' => 'personal', 'persona' => 'Melqui', 'importe' => '60.00',
        ], [['60.00', '2026-09-01']]);

        app(RegistrarPago::class)->registrar($duena, [
            'clave' => (string) Str::uuid(), 'importe' => '60.00', 'fecha' => '2026-09-02',
            'metodo' => 'efectivo', 'pagado_por' => $duena->id, 'sin_comprobante' => 'Sin comprobante.',
        ], [['cuota_id' => $personal->cuotas->first()->id, 'importe' => '60.00']]);

        $this->assertCount(0, app(ConsultaGastos::class)->pagosVisibles($this->soloEmpresa(), []));
    }

    public function test_el_historial_distingue_los_revertidos_y_no_los_suma(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Agua'], [['100.00', '2026-09-01']]);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-09-02',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Sin comprobante.',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        app(RegistrarPago::class)->revertir($u, $pago, 'Se registró sobre el gasto equivocado.');

        $totales = app(ConsultaGastos::class)->totalesPagos($u, [])->firstOrFail();

        $this->assertSame(0, (int) $totales->vigente);
        $this->assertSame(10000, (int) $totales->revertido);
        $this->assertSame(1, (int) $totales->pagos_revertidos);

        $this->actingAs($u)->get(route('gastos.pagos.index'))->assertOk()->assertSee('Revertido');

        // Y se pueden aislar.
        $this->assertCount(0, app(ConsultaGastos::class)->pagosVisibles($u, ['revertidos' => 'excluir']));
        $this->assertCount(1, app(ConsultaGastos::class)->pagosVisibles($u, ['revertidos' => 'solo']));
    }

    public function test_el_historial_separa_las_monedas_y_no_las_suma(): void
    {
        $u = $this->operador();
        $usd = $this->gasto($u, ['concepto' => 'En dólares'], [['100.00', '2026-09-01']]);
        $eur = $this->gasto($u, ['concepto' => 'En euros', 'moneda' => 'EUR'], [['50.00', '2026-09-01']]);

        foreach ([[$usd, 'USD', '100.00'], [$eur, 'EUR', '50.00']] as [$g, $moneda, $importe]) {
            $pago = Pago::create([
                'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
                'beneficiario' => $g->beneficiario, 'moneda' => $moneda, 'importe' => $importe,
                'fecha' => '2026-09-02', 'metodo' => 'efectivo',
                'pagado_por' => $u->id, 'registrado_por' => $u->id,
            ]);
            DB::table('gastos_pago_aplicaciones')->insert([
                'pago_id' => $pago->id, 'cuota_id' => $g->cuotas->first()->id, 'importe' => $importe,
            ]);
        }

        $totales = app(ConsultaGastos::class)->totalesPagos($u, [])->keyBy('moneda');

        $this->assertSame(['EUR', 'USD'], $totales->keys()->sort()->values()->all());
        $this->assertSame(10000, (int) $totales['USD']->vigente);
        $this->assertSame(5000, (int) $totales['EUR']->vigente);
    }

    public function test_el_historial_abre_y_filtra(): void
    {
        $u = $this->operador();
        $gasto = $this->gasto($u, ['concepto' => 'Agua'], [['100.00', '2026-09-01']]);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '100.00', 'fecha' => '2026-09-02',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Sin comprobante.',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $this->actingAs($u)->get(route('gastos.pagos.index'))->assertOk()->assertSee('Inmobiliaria Sur');
        $this->actingAs($u)->get(route('gastos.pagos.index', ['desde' => '2026-10-01']))
            ->assertOk()
            ->assertSee('Ningún pago coincide');
    }
}
