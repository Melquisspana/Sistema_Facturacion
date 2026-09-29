<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Fase 2 — recurrencias.
 *
 * Lo que estas pruebas defienden, en orden de gravedad si se rompiera:
 *
 *  1. UNA OBLIGACIÓN RECURRENTE NACE IMPAGA. Generarla no crea pago, ni aplicación,
 *     ni comprobante. Que el alquiler se pague todos los meses no autoriza a asentar
 *     que este mes ya se pagó: eso sería declarar una salida de dinero que nadie
 *     hizo, y contaminaría el informe de pagos del período.
 *  2. UNA POR PERÍODO. Dos corridas, dos workers o un reintento no pueden dejar dos
 *     deudas por el mismo mes. Lo garantiza el índice único, no una comprobación.
 *  3. MONTO VARIABLE NO INVENTA CIFRAS. Genera una obligación «esperando monto»,
 *     que no suma pendiente ni vencido y no se puede pagar.
 *  4. PAUSAR, OMITIR Y CANCELAR NO BORRAN NADA. Frenan lo que viene; lo ya generado
 *     sigue exigible.
 *  5. EDITAR NO REESCRIBE EL PASADO. La versión nueva rige de acá en adelante.
 */
class RecurrenciaTest extends TestCase
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
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosAdministrar,
            PermisoSistema::GastosRecurrencias,
        ]));
    }

    /** @param  array<string, mixed>  $extra */
    private function datosRegla(User $u, array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(),
            'nombre' => 'Alquiler del local',
            'beneficiario' => 'Inmobiliaria Sur',
            'concepto' => 'Alquiler mensual',
            'categoria' => 'Alquiler',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'monto_modo' => 'fijo',
            'importe' => '400.00',
            'frecuencia' => 'mensual',
            'dia_mes' => 5,
            'dias_generar_antes' => 0,
            'vigente_desde' => '2026-03-01',
        ], $extra);
    }

    private function crearRegla(User $u, array $extra = []): Regla
    {
        return app(AdministrarReglas::class)->crear($u, $this->datosRegla($u, $extra));
    }

    private function generar(Regla $regla, string $hoy, ?User $u = null): array
    {
        return app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse($hoy)->startOfDay(), $u);
    }

    // ─────────────────────── La garantía que no se negocia ───────────────────────

    public function test_una_obligacion_recurrente_nace_sin_pagar(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);

        $this->generar($regla, '2026-03-05');

        $gasto = Gasto::firstOrFail();

        // Ni un pago, ni una aplicación, ni un comprobante. Nadie pagó nada.
        $this->assertSame(0, Pago::count());
        $this->assertSame(0, DB::table('gastos_pago_aplicaciones')->count());
        $this->assertSame(0, DB::table('gastos_adjuntos')->count());

        $resumen = app(SaldosGastos::class)->resumen($gasto, '2026-03-05');

        $this->assertSame('sin_pagos', $resumen['liquidacion']);
        $this->assertSame(40000, $resumen['pendiente']);
        $this->assertSame(0, $resumen['pagado']);
    }

    public function test_generar_no_marca_pagado_ni_cuando_la_regla_ya_genero_antes(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-02-01']);

        $this->generar($regla, '2026-02-05');
        $this->generar($regla, '2026-03-05');

        $this->assertSame(2, Gasto::count());
        $this->assertSame(0, Pago::count());

        foreach (Gasto::all() as $gasto) {
            $this->assertSame('sin_pagos', app(SaldosGastos::class)->resumen($gasto, '2026-03-05')['liquidacion']);
        }
    }

    // ──────────────────────────── Una por período ────────────────────────────

    public function test_correr_la_generacion_dos_veces_no_duplica_el_periodo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);

        $primera = $this->generar($regla, '2026-03-05');
        $segunda = $this->generar($regla, '2026-03-05');

        $this->assertSame(1, $primera['generadas']);
        $this->assertSame(0, $segunda['generadas']);
        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Ocurrencia::count());
    }

    public function test_el_indice_unico_impide_dos_ocurrencias_del_mismo_periodo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);
        $this->generar($regla, '2026-03-05');

        // Un camino que esquivara el servicio choca igual contra la base.
        $this->expectException(QueryException::class);

        Ocurrencia::create([
            'regla_id' => $regla->id,
            'periodo' => '2026-03',
            'vence' => '2026-03-05',
            'estado' => 'generada',
            'version_regla' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_la_clave_del_gasto_se_deriva_del_periodo_y_es_estable(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);
        $this->generar($regla, '2026-03-05');

        $clave = Gasto::firstOrFail()->clave;

        // Borrar la ocurrencia simula haberla perdido: el gasto sigue existiendo y no
        // se puede crear una deuda gemela por el mismo período.
        Ocurrencia::query()->delete();
        $resultado = $this->generar($regla, '2026-03-05');

        $this->assertSame(0, $resultado['generadas']);
        $this->assertSame(1, Gasto::count());
        $this->assertSame($clave, Gasto::firstOrFail()->clave);
        // Se reenganchó la ocurrencia en vez de dejar el período huérfano.
        $this->assertSame(1, Ocurrencia::count());
    }

    // ──────────────────────────── Las cuatro frecuencias ────────────────────────────

    public function test_mensual_genera_un_periodo_por_mes_con_su_clave_logica(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-02-01', 'dia_mes' => 10]);

        $this->generar($regla, '2026-04-10');

        $this->assertSame(['2026-02', '2026-03', '2026-04'], Ocurrencia::orderBy('vence')->pluck('periodo')->all());
        $this->assertSame(['2026-02-10', '2026-03-10', '2026-04-10'],
            Ocurrencia::orderBy('vence')->pluck('vence')->map(fn ($f) => $f->format('Y-m-d'))->all());
    }

    public function test_semanal_usa_la_semana_iso_y_no_el_ano_natural(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'frecuencia' => 'semanal', 'dia_semana' => 3, 'dia_mes' => null,
            'vigente_desde' => '2026-03-01',
        ]);

        $this->generar($regla, '2026-03-18');

        $periodos = Ocurrencia::orderBy('vence')->pluck('periodo')->all();

        // Todas con formato de semana ISO, y una por semana.
        $this->assertNotEmpty($periodos);
        foreach ($periodos as $periodo) {
            $this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $periodo);
        }
        $this->assertSame($periodos, array_values(array_unique($periodos)));

        // Todas caen en miércoles: es el día que pidió la regla.
        foreach (Ocurrencia::all() as $o) {
            $this->assertSame(3, CarbonImmutable::parse($o->vence)->dayOfWeekIso);
        }
    }

    public function test_quincenal_genera_dos_periodos_por_mes_y_no_los_confunde(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'frecuencia' => 'quincenal', 'dia_mes' => 15, 'dia_mes_2' => 31,
            'vigente_desde' => '2026-03-01',
        ]);

        $this->generar($regla, '2026-03-31');

        $this->assertSame(['2026-03-Q1', '2026-03-Q2'], Ocurrencia::orderBy('vence')->pluck('periodo')->all());
        $this->assertSame(['2026-03-15', '2026-03-31'],
            Ocurrencia::orderBy('vence')->pluck('vence')->map(fn ($f) => $f->format('Y-m-d'))->all());
    }

    public function test_la_segunda_quincena_con_ultimo_dia_cae_al_28_en_febrero(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'frecuencia' => 'quincenal',
            'dia_mes' => 15,
            'dia_mes_2' => CalendarioRecurrencia::ULTIMO_DIA,
            'vigente_desde' => '2026-02-01',
        ]);

        $this->generar($regla, '2026-02-28');

        $this->assertSame(['2026-02-15', '2026-02-28'],
            Ocurrencia::orderBy('vence')->pluck('vence')->map(fn ($f) => $f->format('Y-m-d'))->all());
        // Y sigue siendo la SEGUNDA quincena de febrero, no la primera de marzo.
        $this->assertSame('2026-02-Q2', Ocurrencia::orderByDesc('vence')->firstOrFail()->periodo);
    }

    public function test_quincenal_son_siempre_dos_por_mes_y_nunca_cada_catorce_dias(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'frecuencia' => 'quincenal',
            'dia_mes' => 15,
            'dia_mes_2' => CalendarioRecurrencia::ULTIMO_DIA,
            'vigente_desde' => '2026-01-01',
        ]);

        // Seis meses: cada 14 días darían 13 vencimientos y algún mes con tres.
        // Dos fechas del mes dan exactamente 12, dos por mes, siempre.
        $periodos = app(CalendarioRecurrencia::class)->periodos(
            $regla->calendario(),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-06-30'),
        );

        $this->assertCount(12, $periodos);

        $porMes = [];
        foreach ($periodos as $p) {
            $porMes[substr($p['periodo'], 0, 7)] = ($porMes[substr($p['periodo'], 0, 7)] ?? 0) + 1;
        }

        $this->assertSame([2, 2, 2, 2, 2, 2], array_values($porMes));
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], array_keys($porMes));
    }

    public function test_el_ultimo_dia_del_mes_se_nombra_como_tal_y_no_como_el_31(): void
    {
        $calendario = app(CalendarioRecurrencia::class);

        $this->assertSame(
            'Dos fechas del mes: el día 15 y el último día',
            $calendario->enPalabras([
                'frecuencia' => 'quincenal',
                'dia_mes' => 15,
                'dia_mes_2' => CalendarioRecurrencia::ULTIMO_DIA,
            ]),
        );

        // El selector ofrece la opción por su nombre, no obliga a escribir 31.
        $this->assertSame('Último día del mes', CalendarioRecurrencia::diasDelMes()[CalendarioRecurrencia::ULTIMO_DIA]);
        $this->assertSame('Día 15', CalendarioRecurrencia::diasDelMes()[15]);
    }

    public function test_anual_genera_un_solo_periodo_por_ano(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'frecuencia' => 'anual', 'mes' => 3, 'dia_mes' => 20,
            'vigente_desde' => '2026-01-01',
        ]);

        $this->generar($regla, '2026-03-20');

        $this->assertSame(['2026'], Ocurrencia::pluck('periodo')->all());
        $this->assertSame('2026-03-20', Ocurrencia::firstOrFail()->vence->format('Y-m-d'));
    }

    // ─────────────────── Días que no existen en el mes ───────────────────

    public function test_el_dia_31_cae_al_ultimo_dia_de_un_mes_de_30(): void
    {
        $calendario = app(CalendarioRecurrencia::class);

        $periodos = $calendario->periodos(
            ['frecuencia' => 'mensual', 'dia_mes' => 31],
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-04-30'),
        );

        $this->assertCount(1, $periodos);
        $this->assertSame('2026-04-30', $periodos[0]['vence']->toDateString());
        // Y NO se empuja al mes siguiente: la deuda no cambia de período.
        $this->assertSame('2026-04', $periodos[0]['periodo']);
    }

    public function test_el_29_de_febrero_cae_al_28_en_ano_no_bisiesto(): void
    {
        $calendario = app(CalendarioRecurrencia::class);

        $periodos = $calendario->periodos(
            ['frecuencia' => 'anual', 'mes' => 2, 'dia_mes' => 29],
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        );

        $this->assertSame('2026-02-28', $periodos[0]['vence']->toDateString());

        // En bisiesto sí existe el 29 y se respeta.
        $bisiesto = $calendario->periodos(
            ['frecuencia' => 'anual', 'mes' => 2, 'dia_mes' => 29],
            CarbonImmutable::parse('2028-01-01'),
            CarbonImmutable::parse('2028-12-31'),
        );

        $this->assertSame('2028-02-29', $bisiesto[0]['vence']->toDateString());
    }

    // ──────────────────────────── Monto fijo y variable ────────────────────────────

    public function test_monto_fijo_genera_la_obligacion_con_su_cuota_y_vencimiento(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['importe' => '250.50']);

        $this->generar($regla, '2026-03-05');

        $gasto = Gasto::firstOrFail();

        $this->assertSame('250.50', $gasto->importe);
        $this->assertCount(1, $gasto->cuotas);
        $this->assertSame('2026-03-05', $gasto->cuotas->first()->vence->format('Y-m-d'));
        // El período lógico queda registrado en el gasto, no solo en la ocurrencia.
        $this->assertSame('2026-03-01', $gasto->periodo_desde->format('Y-m-d'));
        $this->assertSame('2026-03-31', $gasto->periodo_hasta->format('Y-m-d'));
    }

    public function test_monto_variable_genera_una_obligacion_sin_importe_que_no_suma_deuda(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['monto_modo' => 'variable', 'importe' => null]);

        $this->generar($regla, '2026-03-05');

        $gasto = Gasto::firstOrFail();

        $this->assertNull($gasto->importe);
        $this->assertTrue($gasto->montoDesconocido());
        // Sin cuota: una cuota exige un importe, y no hay ninguno que poner.
        $this->assertCount(0, $gasto->cuotas);

        $resumen = app(SaldosGastos::class)->resumen($gasto, '2026-06-30');

        // Pendiente DESCONOCIDO y vencido CERO, aunque la fecha esperada ya pasó: no se
        // reclama una deuda cuya cifra nadie calculó.
        $this->assertNull($resumen['pendiente']);
        $this->assertSame(0, $resumen['vencido']);
        $this->assertSame('por_determinar', $resumen['liquidacion']);
    }

    public function test_el_vencimiento_esperado_del_monto_variable_queda_en_la_ocurrencia(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['monto_modo' => 'variable', 'importe' => null]);

        $this->generar($regla, '2026-03-05');

        $this->assertSame('2026-03-05', Ocurrencia::firstOrFail()->vence->format('Y-m-d'));
    }

    public function test_una_regla_de_monto_variable_no_guarda_importe_aunque_se_lo_manden(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['monto_modo' => 'variable', 'importe' => '999.00']);

        $this->assertNull($regla->importe);
    }

    // ──────────────────────────── Vigencia y ventana ────────────────────────────

    public function test_no_genera_despues_de_la_fecha_de_fin(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'vigente_desde' => '2026-03-01', 'vigente_hasta' => '2026-03-31', 'dia_mes' => 5,
        ]);

        $this->generar($regla, '2026-05-05');

        $this->assertSame(['2026-03'], Ocurrencia::pluck('periodo')->all());
    }

    public function test_no_genera_el_historico_entero_de_una_regla_vieja(): void
    {
        $u = $this->operador();
        // Vigente desde hace tres años: sin ventana, esto serían 36 deudas inventadas.
        $regla = $this->crearRegla($u, ['vigente_desde' => '2023-03-01', 'dia_mes' => 5]);

        $resultado = $this->generar($regla, '2026-03-05');

        // Solo lo que cabe en la ventana de recuperación (62 días).
        $this->assertLessThanOrEqual(3, $resultado['generadas']);
        $this->assertNotEmpty($resultado['fuera_de_ventana']);
        // Y lo que queda fuera se INFORMA, no se crea a escondidas.
        $this->assertSame(0, Ocurrencia::whereIn('periodo', $resultado['fuera_de_ventana'])->count());
    }

    public function test_la_anticipacion_adelanta_la_creacion_pero_no_el_vencimiento(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['dia_mes' => 20, 'dias_generar_antes' => 10]);

        $this->generar($regla, '2026-03-12');

        $gasto = Gasto::firstOrFail();

        $this->assertSame(1, Ocurrencia::count());
        // Se creó el 12, pero vence el 20: la anticipación no acorta el plazo.
        $this->assertSame('2026-03-20', $gasto->cuotas->first()->vence->format('Y-m-d'));
    }

    public function test_sin_anticipacion_no_se_adelanta_al_periodo_siguiente(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01', 'dia_mes' => 20]);

        $this->generar($regla, '2026-03-20');

        $this->assertSame(['2026-03'], Ocurrencia::pluck('periodo')->all());
    }

    // ──────────────────────────── Pausa, omisión, cancelación ────────────────────────────

    public function test_pausar_frena_la_generacion_y_no_toca_lo_ya_generado(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);
        $this->generar($regla, '2026-03-05');

        app(AdministrarReglas::class)->pausar($u, $regla, 'El local está cerrado por remodelación.');

        $resultado = $this->generar($regla->fresh(), '2026-04-05');

        $this->assertSame(0, $resultado['generadas']);
        $this->assertNotNull($resultado['motivo_omision']);
        // La obligación de marzo sigue existiendo y sigue debiéndose.
        $this->assertSame(1, Gasto::count());
        $this->assertSame(40000, app(SaldosGastos::class)->resumen(Gasto::firstOrFail(), '2026-04-05')['pendiente']);
    }

    public function test_reanudar_vuelve_a_generar(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);

        app(AdministrarReglas::class)->pausar($u, $regla, 'Pausa temporal por revisión.');
        app(AdministrarReglas::class)->reanudar($u->fresh(), $regla->fresh(), 'Volvimos a ocupar el local.');

        $resultado = $this->generar($regla->fresh(), '2026-03-05');

        $this->assertSame(1, $resultado['generadas']);
    }

    public function test_omitir_ocupa_el_periodo_y_la_generacion_no_lo_recrea(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);

        app(AdministrarReglas::class)->omitir($u, $regla, '2026-03', 'Este mes lo cubrió el propietario.');

        $resultado = $this->generar($regla, '2026-03-05');

        $this->assertSame(0, $resultado['generadas']);
        $this->assertSame(0, Gasto::count());

        $ocurrencia = Ocurrencia::firstOrFail();
        $this->assertTrue($ocurrencia->omitida());
        $this->assertSame('Este mes lo cubrió el propietario.', $ocurrencia->motivo);
    }

    public function test_omitir_un_periodo_ya_generado_se_rechaza_en_vez_de_borrar_la_deuda(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);
        $this->generar($regla, '2026-03-05');

        try {
            app(AdministrarReglas::class)->omitir($u, $regla, '2026-03', 'Me equivoqué al generarlo.');
            $this->fail('Omitir un período generado tendría que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya generó una obligación', $e->getMessage());
        }

        $this->assertSame(1, Gasto::count());
    }

    public function test_omitir_dos_veces_no_duplica_ni_falla(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);

        $primera = app(AdministrarReglas::class)->omitir($u, $regla, '2026-03', 'Motivo suficiente para omitir.');
        $segunda = app(AdministrarReglas::class)->omitir($u, $regla, '2026-03', 'Motivo suficiente para omitir.');

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, Ocurrencia::count());
    }

    public function test_omitir_exige_un_motivo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->omitir($u, $regla, '2026-03', '');
    }

    public function test_cancelar_cierra_la_regla_y_conserva_las_obligaciones(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);
        $this->generar($regla, '2026-03-05');

        app(AdministrarReglas::class)->cancelar($u, $regla, 'Se terminó el contrato de alquiler.');

        $this->assertSame('cancelada', $regla->fresh()->estado);
        $this->assertSame(0, $this->generar($regla->fresh(), '2026-04-05')['generadas']);
        $this->assertSame(1, Gasto::count());
    }

    // ──────────────────────────── Versiones ────────────────────────────

    public function test_editar_crea_una_version_nueva_y_no_cambia_lo_ya_generado(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01', 'importe' => '400.00']);
        $this->generar($regla, '2026-03-05');

        app(AdministrarReglas::class)->actualizar(
            $u,
            $regla->fresh(),
            $this->datosRegla($u, ['importe' => '450.00', 'vigente_desde' => '2026-03-01']),
            'El alquiler subió a 450 desde abril.',
        );

        $regla = $regla->fresh();

        $this->assertSame(2, $regla->version);
        $this->assertSame('450.00', $regla->importe);
        $this->assertSame([2, 1], $regla->versiones()->pluck('version')->all());

        // La obligación de marzo sigue valiendo 400: editar no reescribe el pasado.
        $this->assertSame('400.00', Gasto::firstOrFail()->importe);
        $this->assertSame(1, Ocurrencia::firstOrFail()->version_regla);
    }

    public function test_la_ocurrencia_nueva_nace_con_la_version_vigente(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);
        $this->generar($regla, '2026-03-05');

        app(AdministrarReglas::class)->actualizar(
            $u,
            $regla->fresh(),
            $this->datosRegla($u, ['importe' => '450.00', 'vigente_desde' => '2026-03-01']),
            'Ajuste anual del contrato.',
        );

        $this->generar($regla->fresh(), '2026-04-05');

        $this->assertSame(2, Ocurrencia::where('periodo', '2026-04')->firstOrFail()->version_regla);
        // La de abril nace con el importe nuevo; la de marzo conserva el viejo.
        $this->assertSame('450.00', Gasto::orderByDesc('id')->first()->importe);
        $this->assertSame('400.00', Gasto::orderBy('id')->first()->importe);
    }

    public function test_editar_exige_motivo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u);

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->actualizar($u, $regla, $this->datosRegla($u), '');
    }

    public function test_cambiar_la_frecuencia_de_una_regla_que_ya_genero_exige_fecha_de_corte(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => '2026-03-01']);
        $this->generar($regla, '2026-03-05');

        try {
            app(AdministrarReglas::class)->actualizar(
                $u,
                $regla->fresh(),
                $this->datosRegla($u, ['frecuencia' => 'semanal', 'dia_semana' => 1, 'vigente_desde' => '2026-03-01']),
                'Pasamos a pago semanal.',
            );
            $this->fail('Cambiar la frecuencia sin corte tendría que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('vigente desde', $e->getMessage());
        }
    }

    // ──────────────────────────── Validación y permisos ────────────────────────────

    public function test_un_monto_fijo_sin_importe_se_rechaza(): void
    {
        $u = $this->operador();

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->crear($u, $this->datosRegla($u, ['importe' => null]));
    }

    public function test_la_segunda_quincena_no_puede_ser_anterior_a_la_primera(): void
    {
        $u = $this->operador();

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->crear($u, $this->datosRegla($u, [
            'frecuencia' => 'quincenal', 'dia_mes' => 20, 'dia_mes_2' => 5,
        ]));
    }

    public function test_la_vigencia_no_puede_terminar_antes_de_empezar(): void
    {
        $u = $this->operador();

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->crear($u, $this->datosRegla($u, [
            'vigente_desde' => '2026-03-10', 'vigente_hasta' => '2026-03-01',
        ]));
    }

    public function test_sin_permiso_de_recurrencias_no_se_crea_una_regla(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer->value, PermisoSistema::GastosRegistrar->value]);

        $this->expectException(HttpException::class);
        app(AdministrarReglas::class)->crear($u, $this->datosRegla($u));
    }

    public function test_sin_alcance_personal_no_se_crea_una_regla_personal(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer->value, PermisoSistema::GastosRecurrencias->value]);

        try {
            app(AdministrarReglas::class)->crear($u, $this->datosRegla($u, ['ambito' => 'personal', 'persona' => 'Melqui']));
            $this->fail('Una regla personal sin alcance tendría que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('alcance sobre gastos personales', $e->getMessage());
        }
    }

    public function test_repetir_el_alta_con_la_misma_clave_no_crea_dos_reglas(): void
    {
        $u = $this->operador();
        $datos = $this->datosRegla($u);

        $primera = app(AdministrarReglas::class)->crear($u, $datos);
        $segunda = app(AdministrarReglas::class)->crear($u, $datos);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, Regla::count());
    }

    // ──────────────────────────── Pantallas ────────────────────────────

    public function test_el_listado_de_reglas_abre_y_muestra_la_regla(): void
    {
        $u = $this->operador();
        $this->crearRegla($u);

        $this->actingAs($u)->get(route('gastos.reglas.index'))
            ->assertOk()
            ->assertSee('Alquiler del local')
            ->assertSee('Cada mes, el día 5');
    }

    public function test_quien_solo_ve_empresa_no_recibe_reglas_personales(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, ['nombre' => 'Colegiatura', 'ambito' => 'personal', 'persona' => 'Melqui']);

        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->actingAs($soloEmpresa)->get(route('gastos.reglas.index'))
            ->assertOk()
            ->assertDontSee('Colegiatura');
    }

    public function test_la_ficha_de_una_regla_personal_no_se_abre_sin_alcance(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['ambito' => 'personal', 'persona' => 'Melqui']);

        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->actingAs($soloEmpresa)->get(route('gastos.reglas.show', $regla))->assertForbidden();
    }

    public function test_generar_desde_la_pantalla_crea_la_obligacion_impaga(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['vigente_desde' => now()->startOfMonth()->format('Y-m-d'), 'dia_mes' => 1]);

        $this->actingAs($u)
            ->post(route('gastos.reglas.generar', $regla))
            ->assertRedirect();

        $this->assertSame(1, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_sin_permiso_de_recurrencias_no_se_abre_el_alta(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->actingAs($u)->get(route('gastos.reglas.create'))->assertForbidden();
    }

    public function test_el_modulo_apagado_responde_404_en_las_reglas(): void
    {
        config()->set('gastos.enabled', false);
        $u = $this->operador();

        $this->actingAs($u)->get(route('gastos.reglas.index'))->assertNotFound();
    }

    // ──────────────────────────── El comando ────────────────────────────

    public function test_el_comando_sin_aplicar_no_escribe_nada(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, ['vigente_desde' => now()->startOfMonth()->format('Y-m-d'), 'dia_mes' => 1]);

        $this->artisan('gastos:generar-recurrentes')->assertSuccessful();

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Ocurrencia::count());
    }

    public function test_el_comando_con_aplicar_no_escribe_si_la_generacion_automatica_esta_apagada(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, ['vigente_desde' => now()->startOfMonth()->format('Y-m-d'), 'dia_mes' => 1]);

        config()->set('gastos.recurrencias.generacion_automatica', false);

        $this->artisan('gastos:generar-recurrentes --aplicar')->assertFailed();

        $this->assertSame(0, Gasto::count());
    }

    public function test_el_comando_con_aplicar_y_la_llave_encendida_genera(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, ['vigente_desde' => '2026-03-01', 'dia_mes' => 5]);

        config()->set('gastos.recurrencias.generacion_automatica', true);

        $this->artisan('gastos:generar-recurrentes --aplicar --hoy=2026-03-05')->assertSuccessful();

        $this->assertSame(1, Gasto::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_el_comando_no_corre_con_el_modulo_apagado(): void
    {
        config()->set('gastos.enabled', false);

        $this->artisan('gastos:generar-recurrentes')->assertFailed();
    }
}
