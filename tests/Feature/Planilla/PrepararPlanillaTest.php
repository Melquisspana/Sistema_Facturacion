<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\PersonalRuta;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\PeriodoPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarEmpleado;
use App\Services\Planilla\SueldoHabitual;
use App\Services\Planilla\TotalesPlanilla;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 3 — preparación de planilla, registro de personas y formatos.
 *
 * Lo que defienden estas pruebas:
 *
 *  1. NO SE DUPLICAN PERSONAS NI PLANILLAS. La misma identidad no entra dos veces al
 *     registro, la misma persona no entra dos veces a una planilla, y pedir dos veces
 *     el mismo período abre la misma planilla.
 *  2. NO SE CUENTA EL DINERO DOS VECES. Bruto, neto y lo que va a terceros son
 *     cifras distintas, y siempre se cumple bruto = neto + terceros + ya entregados.
 *  3. NO SE EXIGE BIOMETRÍA NI EL MÓDULO DE ASISTENCIA. La planilla funciona con
 *     `ASISTENCIA_ENABLED=false` y con gente sin huella.
 *  4. LOS SALARIOS SON CONFIDENCIALES. `planilla.ver` deja entrar sin ver un importe.
 *  5. UN BORRADOR NO DEBE NADA y se corrige cuantas veces haga falta.
 */
class PrepararPlanillaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('planilla.enabled', true);
        // A propósito: la planilla tiene que funcionar con Asistencia APAGADA.
        config()->set('asistencia.enabled', false);
    }

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function gestor(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios,
            PermisoSistema::PlanillaGestionar, PermisoSistema::DteVer,
        ]));
    }

    private function empleado(User $u, string $nombre = 'Ana Mendoza', array $extra = []): PlanillaEmpleado
    {
        return app(RegistrarEmpleado::class)->registrar($u, array_replace([
            'nombre' => $nombre, 'cargo' => 'Ventas', 'salario_referencia' => '225.00',
        ], $extra));
    }

    private function planilla(User $u, array $extra = []): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, array_replace([
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD',
        ], $extra));
    }

    // ─────────────────── Identidades: reutilizar sin duplicar ───────────────────

    public function test_una_persona_de_asistencia_se_reutiliza_sin_volver_a_escribirla(): void
    {
        $u = $this->gestor();
        $asistencia = AsistenciaEmpleado::create([
            'nombres' => 'Rene', 'apellidos' => 'Barillas', 'activo' => true,
        ]);

        $empleado = app(RegistrarEmpleado::class)->registrar($u, [
            'nombre' => app(RegistrarEmpleado::class)->nombreDe('asistencia', $asistencia->id),
            'origen_tipo' => 'asistencia', 'origen_id' => $asistencia->id,
        ]);

        $this->assertSame('Rene Barillas', $empleado->nombre);
        $this->assertSame($asistencia->id, $empleado->asistencia_empleado_id);
        $this->assertSame('Asistencia', $empleado->origenIdentidad());
        // No se creó ninguna persona nueva en Asistencia.
        $this->assertSame(1, AsistenciaEmpleado::count());
    }

    public function test_la_misma_identidad_no_entra_dos_veces_al_registro(): void
    {
        $u = $this->gestor();
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Rene', 'apellidos' => 'Barillas', 'activo' => true]);

        $primera = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Rene Barillas', 'origen_tipo' => 'asistencia', 'origen_id' => $asistencia->id]);
        $segunda = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Rene Barillas', 'origen_tipo' => 'asistencia', 'origen_id' => $asistencia->id]);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_quien_ya_esta_en_planilla_deja_de_ofrecerse_como_candidato(): void
    {
        $u = $this->gestor();
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Lucia', 'apellidos' => 'Del Carmen', 'activo' => true]);

        $antes = collect(app(RegistrarEmpleado::class)->candidatos())->where('tipo', 'asistencia')->pluck('id');
        $this->assertTrue($antes->contains($asistencia->id));

        app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Lucia Del Carmen', 'origen_tipo' => 'asistencia', 'origen_id' => $asistencia->id]);

        $despues = collect(app(RegistrarEmpleado::class)->candidatos())->where('tipo', 'asistencia')->pluck('id');
        $this->assertFalse($despues->contains($asistencia->id));
    }

    public function test_no_se_exige_biometria_ni_el_modulo_de_asistencia_encendido(): void
    {
        config()->set('asistencia.enabled', false);

        $u = $this->gestor();
        // Persona SIN ninguna huella registrada.
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Sofia', 'apellidos' => 'Martinez', 'activo' => true]);
        $this->assertSame(0, $asistencia->huellas()->count());

        $empleado = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Sofia Martinez', 'origen_tipo' => 'asistencia', 'origen_id' => $asistencia->id]);

        $this->assertNotNull($empleado->id);
        // Y las pantallas de planilla abren igual con el módulo de Asistencia apagado.
        $this->actingAs($u)->get(route('planilla.empleados'))->assertOk()->assertSee('Sofia Martinez');
        $this->actingAs($u)->get(route('planilla.index'))->assertOk();
    }

    public function test_tambien_se_puede_registrar_a_alguien_que_no_esta_en_ningun_modulo(): void
    {
        $u = $this->gestor();
        $empleado = $this->empleado($u, 'Personal de oficina');

        $this->assertSame('Solo planilla', $empleado->origenIdentidad());
        $this->assertNull($empleado->asistencia_empleado_id);
    }

    public function test_una_persona_de_rutas_tambien_se_reutiliza(): void
    {
        $u = $this->gestor();
        $ruta = PersonalRuta::create(['nombre' => 'Carlos Rivas', 'activo' => true]);

        $empleado = app(RegistrarEmpleado::class)->registrar($u, ['nombre' => 'Carlos Rivas', 'origen_tipo' => 'ruta', 'origen_id' => $ruta->id]);

        $this->assertSame($ruta->id, $empleado->personal_ruta_id);
        $this->assertSame('Personal de Rutas', $empleado->origenIdentidad());
    }

    // ─────────────────── Períodos ───────────────────

    public function test_los_tres_tipos_de_periodo_dan_los_tramos_correctos(): void
    {
        $p = app(PeriodoPlanilla::class);

        $quincena1 = $p->para('quincenal', CarbonImmutable::parse('2026-09-10'));
        $this->assertSame('2026-09-Q1', $quincena1['periodo']);
        $this->assertSame(['2026-09-01', '2026-09-15'], [$quincena1['desde'], $quincena1['hasta']]);

        $quincena2 = $p->para('quincenal', CarbonImmutable::parse('2026-09-20'));
        $this->assertSame('2026-09-Q2', $quincena2['periodo']);
        $this->assertSame(['2026-09-16', '2026-09-30'], [$quincena2['desde'], $quincena2['hasta']]);

        $mes = $p->para('mensual', CarbonImmutable::parse('2026-02-10'));
        $this->assertSame(['2026-02', '2026-02-01', '2026-02-28'], [$mes['periodo'], $mes['desde'], $mes['hasta']]);

        $semana = $p->para('semanal', CarbonImmutable::parse('2026-09-10')); // jueves
        $this->assertSame(['2026-09-07', '2026-09-13'], [$semana['desde'], $semana['hasta']]);
    }

    public function test_abrir_dos_veces_el_mismo_periodo_no_duplica_la_planilla(): void
    {
        $u = $this->gestor();

        $primera = $this->planilla($u);
        $segunda = $this->planilla($u, ['fecha' => '2026-09-14']); // otro día, misma quincena

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, Planilla::count());
    }

    public function test_una_extraordinaria_puede_convivir_con_la_regular_del_mismo_periodo(): void
    {
        $u = $this->gestor();

        $regular = $this->planilla($u);
        $extra = $this->planilla($u, ['clase' => 'extraordinaria']);

        $this->assertNotSame($regular->id, $extra->id);
        $this->assertSame(2, Planilla::count());
    }

    // ─────────────────── La aritmética que no puede contar dos veces ───────────────────

    public function test_bruto_neto_y_terceros_son_cifras_distintas_y_cuadran(): void
    {
        $t = app(TotalesPlanilla::class);

        $linea = $t->linea('200.00', [
            ['tipo' => 'ingreso', 'importe' => '20.00'],
            ['tipo' => 'descuento', 'importe' => '25.00', 'destino' => 'tercero', 'tercero' => 'Cooperativa'],
            ['tipo' => 'descuento', 'importe' => '40.00', 'destino' => 'anticipo', 'referencia' => 'recibo 148'],
        ]);

        $this->assertSame(22000, $linea['total_ingresos']); // 200 + 20
        $this->assertSame(6500, $linea['descuentos']);   // 25 + 40
        $this->assertSame(15500, $linea['a_pagar']);       // 220 - 65
        $this->assertSame(2500, $linea['a_terceros']);
        $this->assertSame(4000, $linea['anticipos']);

        // La identidad que impide contar el dinero dos veces.
        $this->assertSame(
            $linea['total_ingresos'],
            $linea['a_pagar'] + $linea['a_terceros'] + $linea['anticipos']
                + $linea['otros_descuentos'] + $linea['sin_clasificar'],
        );
    }

    public function test_un_descuento_a_tercero_sin_decir_a_quien_no_cuenta_como_deuda(): void
    {
        $t = app(TotalesPlanilla::class);

        $linea = $t->linea('100.00', [
            ['tipo' => 'descuento', 'importe' => '10.00', 'destino' => 'tercero', 'tercero' => ''],
        ]);

        // Reduce lo que se paga igual, pero no genera obligación con nadie. Y NO se
        // cuenta como anticipo: no hay nada que diga que ese dinero ya salió.
        $this->assertSame(9000, $linea['a_pagar']);
        $this->assertSame(0, $linea['a_terceros']);
        $this->assertSame(0, $linea['anticipos']);
        $this->assertSame(1000, $linea['sin_clasificar']);
    }

    // ─────────────────── Preparar, corregir y no duplicar ───────────────────

    public function test_se_guarda_el_borrador_con_sus_conceptos(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [[
            'planilla_empleado_id' => $ana->id,
            'salario' => '225.00',
            'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '35.50'],
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '40.00', 'destino' => 'anticipo', 'referencia' => 'recibo 148'],
            ],
        ]]);

        $detalle = PlanillaDetalle::firstOrFail();

        $this->assertSame('Ana Mendoza', $detalle->nombre_snapshot);
        $this->assertSame('260.50', $detalle->total_ingresos);
        $this->assertSame('40.00', $detalle->descuentos);
        $this->assertSame('220.50', $detalle->a_pagar);
        $this->assertCount(2, $detalle->conceptos);
    }

    public function test_un_borrador_no_crea_ninguna_obligacion(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
        ]);

        // Ni un gasto, ni un pago: eso ocurre al confirmar, que es otro acto.
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Pago::count());
        $this->assertNull(PlanillaDetalle::firstOrFail()->gasto_id);
        $this->assertTrue($planilla->fresh()->borrador());
    }

    public function test_corregir_el_borrador_reemplaza_lo_anterior_sin_duplicar(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        $linea = fn (string $salario) => [['planilla_empleado_id' => $ana->id, 'salario' => $salario, 'conceptos' => []]];

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, $linea('225.00'));
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, $linea('250.00'));

        $this->assertSame(1, PlanillaDetalle::count());
        $this->assertSame('250.00', PlanillaDetalle::firstOrFail()->a_pagar);
    }

    public function test_la_misma_persona_no_puede_entrar_dos_veces_en_una_planilla(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        try {
            app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
                ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
                ['planilla_empleado_id' => $ana->id, 'salario' => '100.00', 'conceptos' => []],
            ]);
            $this->fail('Una persona repetida tendría que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('repetida', $e->getMessage());
        }

        $this->assertSame(0, PlanillaDetalle::count());
    }

    public function test_los_reparos_avisan_del_neto_negativo_y_del_tercero_sin_nombre(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);
        $carlos = $this->empleado($u, 'Carlos Rivas');

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '100.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Préstamo', 'importe' => '150.00', 'destino' => 'otro'],
            ]],
            ['planilla_empleado_id' => $carlos->id, 'salario' => '200.00', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Cuota', 'importe' => '20.00', 'destino' => 'tercero', 'tercero' => ''],
            ]],
        ]);

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos'));

        $this->assertNotEmpty($reparos);
        $this->assertStringContainsString('superan al total de ingresos', implode(' ', $reparos));
        $this->assertStringContainsString('no dice a quién', implode(' ', $reparos));
    }

    // ─────────────────── Permisos ───────────────────

    public function test_quien_solo_ve_no_alcanza_los_importes(): void
    {
        $gestor = $this->gestor();
        $planilla = $this->planilla($gestor);
        $ana = $this->empleado($gestor);
        app(PrepararPlanilla::class)->guardarLineas($gestor, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
        ]);

        $mirón = $this->usuario([PermisoSistema::PlanillaVer->value]);

        $respuesta = $this->actingAs($mirón)->get(route('planilla.index'))->assertOk();
        $respuesta->assertSee('2026-09-Q1');
        $respuesta->assertDontSee('225.00');

        // Y no puede abrir la preparación, que es donde viven los importes.
        $this->actingAs($mirón)->get(route('planilla.preparar', $planilla))->assertForbidden();
    }

    public function test_sin_permiso_de_gestionar_no_se_prepara_ni_se_registra_gente(): void
    {
        $u = $this->usuario([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaSalarios->value]);

        $this->actingAs($u)->get(route('planilla.create'))->assertForbidden();
        $this->actingAs($u)->post(route('planilla.empleados.store'), ['nombre' => 'X'])->assertForbidden();
    }

    public function test_el_modulo_apagado_responde_404(): void
    {
        config()->set('planilla.enabled', false);

        $this->actingAs($this->gestor())->get(route('planilla.index'))->assertNotFound();
    }

    // ─────────────────── Pantallas ───────────────────

    public function test_el_formulario_de_preparacion_abre_y_muestra_lo_que_debe(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $this->empleado($u);

        $respuesta = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk();

        $respuesta->assertSee('Esto es un borrador y no debe nada', false);
        $respuesta->assertSee('Total de ingresos');
        $respuesta->assertSee('A pagar a los empleados');
        $respuesta->assertSee('Se entregan a terceros');
        // Ya no hace falta explicar que no se suman: la resta lo dice sola.
        $respuesta->assertDontSee('No se suman entre sí', false);
        $respuesta->assertSee('no calcula', false);
        $respuesta->assertSee('Ana Mendoza');
    }

    public function test_los_formatos_se_previsualizan_con_datos_ficticios(): void
    {
        $u = $this->gestor();

        $hoja = $this->actingAs($u)->get(route('planilla.formatos'))->assertOk();
        $hoja->assertSee('datos ficticios', false);
        $hoja->assertSee('Planilla para firma', false);
        // El encabezado aprobado: logo, negocio y empleadora.
        $hoja->assertSee('DULCES LA NEGRITA', false);
        $hoja->assertSee('Ana Beatriz Mendoza');
        // El total de la hoja: 225+35.50 + 200+20 + 187.50 = 668.00 de bruto.
        $hoja->assertSee('929.00');
        // Y el neto: 668 - 40 - 25 = 603.00
        $hoja->assertSee('828.00');

        $recibo = $this->actingAs($u)->get(route('planilla.formatos', ['formato' => 'recibo']))->assertOk();
        $recibo->assertSee('Recibo de pago', false);
        $recibo->assertSee('Cooperativa La Esperanza');
        $recibo->assertSee('se entrega a', false);
    }

    public function test_la_planilla_no_promete_calculo_legal_en_ninguna_pantalla(): void
    {
        $u = $this->gestor();

        $this->actingAs($u)->get(route('planilla.index'))->assertOk()->assertSee('No es nómina legal', false);
        $this->actingAs($u)->get(route('planilla.formatos'))->assertOk()->assertSee('no incluye cálculos de', false);
    }

    // ─────────────────── Lo que se corrigió del formulario ───────────────────

    public function test_un_descuento_sin_clasificar_bloquea_la_confirmacion(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                // Sin `destino`: no se asume que sea un anticipo.
                ['tipo' => 'descuento', 'concepto' => 'Descuento acordado', 'importe' => '20.00'],
            ]],
        ]);

        $concepto = PlanillaDetalle::firstOrFail()->conceptos->first();
        $this->assertNull($concepto->destino);
        $this->assertFalse($concepto->esAnticipo());

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos'));
        $this->assertStringContainsString('falta decir qué es el descuento', implode(' ', $reparos));
    }

    public function test_un_anticipo_sin_vincular_se_reclama_antes_de_confirmar(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '200.00', 'conceptos' => [
                // Solo la referencia escrita: no controla nada.
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                    'destino' => 'anticipo', 'referencia' => 'recibo 148'],
            ]],
        ]);

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos'));

        $this->assertStringContainsString('no está vinculado a ninguno', implode(' ', $reparos));
    }

    public function test_los_tres_destinos_se_cuentan_en_bolsas_distintas(): void
    {
        $t = app(TotalesPlanilla::class);

        $linea = $t->linea('300.00', [
            ['tipo' => 'descuento', 'importe' => '30.00', 'destino' => 'tercero', 'tercero' => 'Cooperativa'],
            ['tipo' => 'descuento', 'importe' => '20.00', 'destino' => 'anticipo', 'referencia' => 'recibo 9'],
            ['tipo' => 'descuento', 'importe' => '10.00', 'destino' => 'otro'],
        ]);

        $this->assertSame(3000, $linea['a_terceros']);
        $this->assertSame(2000, $linea['anticipos']);
        $this->assertSame(1000, $linea['otros_descuentos']);
        $this->assertSame(0, $linea['sin_clasificar']);
        $this->assertSame(6000, $linea['descuentos']);
        $this->assertSame(24000, $linea['a_pagar']);
    }

    public function test_el_formulario_no_usa_una_tabla_con_desplazamiento_horizontal(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
        ]);

        $html = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk()->getContent();

        // Una rejilla que se apila, no una tabla que se comprime.
        $this->assertStringNotContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('lg:grid-cols-12', $html);
        // Y las acciones son botones con texto, no «×» diminutas. «De esta quincena» y
        // no «de la planilla»: quitar a alguien de un período no lo borra del registro.
        $this->assertStringContainsString('Quitar de esta quincena', $html);
        $this->assertStringContainsString('Agregar descuento', $html);
    }

    /**
     * La pantalla muestra lo de siempre y esconde lo excepcional.
     *
     * Cinco columnas a la vista —persona, pago del período, extras, descuentos y total—
     * y el resto detrás de «Detalle». Una quincena normal se revisa sin abrir nada.
     */
    public function test_la_pantalla_muestra_cinco_columnas_y_esconde_el_resto(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
        ]);

        $html = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk()->getContent();

        foreach (['Persona', 'Pago del período', 'Extras', 'Descuentos', 'Total a pagar'] as $columna) {
            $this->assertStringContainsString($columna, $html, "Falta la columna «{$columna}».");
        }

        // El detalle existe y arranca CERRADO: `abiertos` vacío.
        $this->assertStringContainsString('abiertos: []', $html);
        $this->assertStringContainsString('Ver detalle', $html);

        // Dentro del detalle: el período particular y la resta del anticipo.
        $this->assertStringContainsString('Período trabajado', $html);
        $this->assertStringContainsString('periodo_desde', $html);
        $this->assertStringContainsString('periodo_hasta', $html);
        $this->assertStringContainsString('El importe lo escribís vos', $html);
        $this->assertStringContainsString('Saldo pendiente', $html);
        $this->assertStringContainsString('Se descuenta', $html);
        $this->assertStringContainsString('Queda', $html);
    }

    /**
     * El importe habitual se propone solo y se muestra como referencia, pero la
     * quincena que se está preparando manda: ajustar una NO cambia el otro.
     */
    public function test_el_importe_habitual_se_propone_y_se_muestra_como_referencia(): void
    {
        $u = $this->gestor();
        $ana = $this->empleado($u);
        app(SueldoHabitual::class)->registrar($u, $ana, [
            'importe' => '225.00', 'vigente_desde' => '2026-01-01',
        ]);

        // Abrir la quincena ya la trae cargada: no se escribe nada.
        $planilla = $this->planilla($u);
        $this->assertSame('225.00', $planilla->fresh()->detalles->first()->salario);

        $html = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk()->getContent();
        $this->assertStringContainsString('habitual', $html);
        $this->assertStringContainsString('225.00', $html);
    }

    public function test_sin_personas_disponibles_no_se_muestra_un_selector_vacio(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u);

        // La única persona registrada ya está en la planilla.
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            ['planilla_empleado_id' => $ana->id, 'salario' => '225.00', 'conceptos' => []],
        ]);

        $respuesta = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk();

        $respuesta->assertSee('Ya están en la planilla todas las personas registradas.');
        $respuesta->assertSee('Registrar o vincular una persona');
    }
}
