<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\PanelGastos;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La pantalla principal de Gastos y pagos — primer corte.
 *
 * Lo que defienden estas pruebas, en orden de daño si se rompiera:
 *
 *  1. NO SE INVENTA DEUDA HACIA ATRÁS. Una regla cargada el 15 no puede hacer
 *     aparecer como deuda de septiembre la mensualidad que vencía el 10. Si se
 *     rompiera, el operador vería una deuda que nadie contrajo y podría pagarla.
 *  2. PREVISIÓN Y OBLIGACIÓN NO SE MEZCLAN NI SE SUMAN. Son dos cifras distintas y
 *     no existe ninguna que las contenga sumadas.
 *  3. CUANDO UNA PREVISIÓN SE GENERA, DESAPARECE COMO PREVISIÓN. Reemplazo, no
 *     acumulación: si fallara, el mes de la generación se vería —y se sumaría— dos
 *     veces, y el total diría el doble de lo que se debe.
 *  4. SIN FECHA ES «POR COMPLETAR». A una regla sin día no se le inventa un período
 *     ni un vencimiento con tal de mostrarle una fila con botón.
 *  5. UN MONTO DESCONOCIDO NO ES CERO. No suma a ninguna cifra.
 *  6. EL SALDO PROVISIONAL SIGUE DICIÉNDOSE PROVISIONAL.
 *  7. EL CANDADO DE ÁMBITO ES EL DE SIEMPRE, también en esta pantalla y también en
 *     las previsiones.
 */
class PanelTest extends TestCase
{
    use RefreshDatabase;

    /** El día que todo esto describe: el arranque real del módulo en desarrollo. */
    private const HOY = '2026-09-15';

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
        $u->syncPermissions(array_map(fn ($p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function operador(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosAdministrar,
            PermisoSistema::GastosRecurrencias,
        ]);
    }

    private function crearRegla(User $u, array $extra = []): Regla
    {
        return app(AdministrarReglas::class)->crear($u, array_replace([
            'clave' => (string) Str::uuid(),
            'nombre' => 'Mensualidad del N400',
            'beneficiario' => 'Auto Fácil',
            'concepto' => 'Mensualidad del N400 2026',
            'categoria' => 'Vehículos',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'monto_modo' => 'fijo',
            'importe' => '400.00',
            'frecuencia' => 'mensual',
            'dia_mes' => 10,
            'dias_generar_antes' => 5,
            // Arranque el 15: DESPUÉS del día 10 de este mes. Ese es el caso.
            'vigente_desde' => self::HOY,
        ], $extra));
    }

    private function panel(User $u, array $filtros = [], string $hoy = self::HOY): array
    {
        return app(PanelGastos::class)->armar($u, $filtros, CarbonImmutable::parse($hoy)->startOfDay());
    }

    /** Una cuenta abierta: se debe, sin fecha de vencimiento. */
    private function cuentaAbierta(User $u, string $proveedor, string $importe, ?string $certeza = null): Gasto
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => hash('sha256', $proveedor.$importe),
            'beneficiario' => $proveedor,
            'concepto' => 'Saldo inicial',
            'categoria' => 'Productos',
            'ambito' => 'empresarial',
            'naturaleza' => 'compra',
            'moneda' => 'USD',
            'importe' => $importe,
            'certeza' => $certeza,
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'registrado_por' => $u->id,
        ]);

        $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => null]);

        return $gasto->fresh();
    }

    // ═══════════ 1. No se inventa deuda hacia atrás ═══════════

    public function test_la_mensualidad_anterior_al_arranque_no_aparece_como_deuda(): void
    {
        $u = $this->operador();
        $this->crearRegla($u);

        $datos = $this->panel($u);

        // Ni como obligación, ni como previsión, ni en ninguna de las dos cifras.
        $this->assertSame(0, (int) $datos['se_debe'], 'Una regla sin generar no puede producir deuda.');
        $this->assertSame(0, (int) $datos['se_espera'],
            'La mensualidad del día 10 vence ANTES del arranque del día 15: no se proyecta.');
        $this->assertCount(0, $datos['previsiones']);
        $this->assertCount(0, $datos['este_mes']);
    }

    public function test_lo_anterior_al_arranque_se_informa_aparte_y_sin_cifra(): void
    {
        $u = $this->operador();
        $this->crearRegla($u);

        $datos = $this->panel($u);

        // Se ve, pero como omisión explícita: no es una deuda y no lleva importe.
        $this->assertCount(1, $datos['anteriores_al_arranque']);
        $fila = $datos['anteriores_al_arranque']->first();
        $this->assertSame('2026-09-10', $fila->vence);
        $this->assertStringContainsString('antes del arranque', $fila->motivo);
        $this->assertObjectNotHasProperty('importe', $fila,
            'Lo anterior al arranque no lleva cifra: no se sabe si se debe.');
    }

    public function test_la_primera_prevision_es_la_del_mes_siguiente(): void
    {
        $u = $this->operador();
        $this->crearRegla($u);

        // Mirando desde octubre, la del 10 de octubre sí es futura y sí se proyecta.
        $datos = $this->panel($u, [], '2026-10-01');

        $this->assertCount(1, $datos['previsiones']);
        $this->assertSame('2026-10-10', $datos['previsiones']->first()->vence);
        $this->assertSame(40000, (int) $datos['se_espera']);
    }

    public function test_una_regla_que_arranca_antes_si_proyecta_su_mes(): void
    {
        $u = $this->operador();
        // Misma regla, pero vigente desde el día 1 y venciendo el 28: cae adelante.
        $this->crearRegla($u, ['dia_mes' => 28, 'vigente_desde' => '2026-09-01', 'importe' => '48.00']);

        $datos = $this->panel($u);

        $this->assertCount(1, $datos['previsiones']);
        $this->assertSame('2026-09-28', $datos['previsiones']->first()->vence);
        $this->assertSame(4800, (int) $datos['se_espera']);
    }

    // ═══════════ 2. Previsión y obligación, separadas ═══════════

    public function test_las_dos_cifras_van_separadas_y_no_hay_ninguna_que_las_sume(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78');
        $this->crearRegla($u, ['dia_mes' => 28, 'vigente_desde' => '2026-09-01', 'importe' => '48.00']);

        $datos = $this->panel($u);

        $this->assertSame(95678, (int) $datos['se_debe']);
        $this->assertSame(4800, (int) $datos['se_espera']);

        // Lo importante no es que las dos cifras estén bien: es que NO EXISTA una tercera
        // con la suma. Ninguna clave del panel vale 1004.78.
        foreach ($datos as $clave => $valor) {
            if (is_int($valor)) {
                $this->assertNotSame(100478, $valor,
                    "La clave «{$clave}» contiene la suma de deuda y previsión, que no debe existir.");
            }
        }
    }

    // ═══════════ 3. Generar una previsión la REEMPLAZA ═══════════

    public function test_al_generarse_el_periodo_la_prevision_desaparece_y_no_se_cuenta_dos_veces(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, ['dia_mes' => 28, 'vigente_desde' => '2026-09-01', 'importe' => '48.00']);

        $antes = $this->panel($u);
        $this->assertCount(1, $antes['previsiones'], 'Antes de generar es una previsión.');
        $this->assertSame(4800, (int) $antes['se_espera']);
        $this->assertSame(0, (int) $antes['se_debe']);

        // Se genera el período: ahora existe la obligación real.
        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-25')->startOfDay(), $u);

        $despues = $this->panel($u, [], '2026-09-25');

        // REEMPLAZO: bajó a deuda y dejó de estar previsto. En ningún momento las dos.
        $this->assertSame(4800, (int) $despues['se_debe'], 'La obligación generada ya se debe.');
        $this->assertSame(0, (int) $despues['se_espera'], 'Y ya no se sigue esperando: fue reemplazada.');
        $this->assertCount(0, $despues['previsiones']);
        $this->assertCount(1, $despues['este_mes']);
    }

    // ═══════════ 4. Sin fecha es «Por completar» ═══════════

    public function test_una_regla_sin_dia_se_queda_en_por_completar_y_no_genera_ningun_vencimiento(): void
    {
        $u = $this->operador();
        // Como ANDA, CAES y DELSUR: sin día y con monto variable.
        $this->crearRegla($u, [
            'beneficiario' => 'DELSUR',
            'concepto' => 'Luz de la fábrica',
            'dia_mes' => null,
            'monto_modo' => 'variable',
            'importe' => null,
            // Así se guarda «Por completar»: sin inventarle un día para poder guardar.
            'incompleta' => true,
        ]);

        $datos = $this->panel($u);

        $this->assertCount(1, $datos['por_completar']);
        $this->assertStringContainsString('el día de cobro', $datos['por_completar']->first()->falta);

        // Y lo que importa: NO se le inventó un período para poder mostrarla.
        $this->assertCount(0, $datos['previsiones']);
        $this->assertCount(0, $datos['esperando_recibo']);
        $this->assertCount(0, $datos['anteriores_al_arranque']);
        $this->assertSame(0, (int) $datos['se_espera']);
    }

    // ═══════════ 5. Un monto desconocido no es cero ═══════════

    public function test_la_obligacion_que_espera_recibo_no_suma_a_ninguna_cifra(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'DELSUR',
            'dia_mes' => 28,
            'vigente_desde' => '2026-09-01',
            'monto_modo' => 'variable',
            'importe' => null,
        ]);

        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-25')->startOfDay(), $u);

        $datos = $this->panel($u, [], '2026-09-25');

        $this->assertCount(1, $datos['esperando_recibo']);
        $this->assertNull($datos['esperando_recibo']->first()->importe);
        $this->assertSame(0, (int) $datos['se_debe'], 'Un monto que no se sabe no es cero.');
        $this->assertSame(0, (int) $datos['se_espera']);
    }

    public function test_una_prevision_de_monto_variable_proyecta_la_fecha_pero_no_un_importe(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, [
            'dia_mes' => 28, 'vigente_desde' => '2026-09-01',
            'monto_modo' => 'variable', 'importe' => null,
        ]);

        $datos = $this->panel($u);

        $this->assertCount(1, $datos['previsiones']);
        $this->assertNull($datos['previsiones']->first()->importe);
        $this->assertSame(0, (int) $datos['se_espera'], 'No se inventa cero para poder sumar.');
    }

    // ═══════════ 6. El saldo provisional sigue diciéndose ═══════════

    public function test_el_saldo_provisional_llega_marcado_a_la_pantalla(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        $datos = $this->panel($u);
        $porNombre = $datos['sin_fecha']->keyBy('beneficiario');

        $this->assertTrue($porNombre['Proveedor A']->esProvisional());
        $this->assertFalse($porNombre['Distribuidora Ejemplo, S.A. de C.V.']->esProvisional());

        // Provisional NO significa que no se deba: suma igual que cualquier otra.
        $this->assertSame(235678, (int) $datos['se_debe']);
    }

    /**
     * «Lo confirmó una persona» y «lo respalda un papel» no son lo mismo.
     *
     * El caso real: el usuario confirmó los 850 de Proveedor A, pero no hay estado de cuenta
     * detrás. Marcarlo `confirmado` habría hecho decir a la pantalla «Confirmado con
     * documento», que es sencillamente falso. Por eso hay un tercer estado, y por eso
     * la fila dice de dónde viene la confirmación.
     */
    public function test_un_saldo_confirmado_por_el_usuario_no_se_presenta_como_respaldado(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '850.00', 'confirmado_usuario');

        $gasto = Gasto::where('beneficiario', 'Proveedor A')->firstOrFail();
        $this->assertFalse($gasto->esProvisional(), 'Ya no es una estimación.');
        $this->assertTrue($gasto->confirmadoPorElUsuario());
        $this->assertSame('Confirmado por el usuario', $gasto->etiquetaCerteza());

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Confirmado por el usuario · sin documento de respaldo', false)
            // Ni se le llama provisional, ni se insinúa que tenga papel.
            ->assertDontSee('Saldo provisional')
            ->assertDontSee('Confirmado con documento');

        // Y deja de contar como provisional en la cifra de arriba.
        $this->assertSame(0, (int) $this->panel($u)['se_debe_provisional']);
        $this->assertSame(85000, (int) $this->panel($u)['se_debe']);
    }

    public function test_la_pantalla_dice_provisional_con_todas_las_letras(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Saldo provisional', false);
    }

    // ═══════════ 7. El candado de ámbito, también acá ═══════════

    public function test_quien_no_ve_lo_personal_no_recibe_sus_previsiones_ni_sus_deudas(): void
    {
        $dueno = $this->operador();
        $this->crearRegla($dueno, [
            'ambito' => 'personal', 'beneficiario' => 'Netflix',
            'dia_mes' => 28, 'vigente_desde' => '2026-09-01', 'importe' => '14.00',
        ]);

        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer]);
        $datos = $this->panel($soloEmpresa);

        $this->assertCount(0, $datos['previsiones'], 'Una previsión personal no se entrega a quien solo ve empresa.');
        $this->assertSame(0, (int) $datos['se_espera'], 'Ni siquiera a través del total.');

        // Y quien sí lo tiene, la ve.
        $this->assertCount(1, $this->panel($dueno)['previsiones']);
    }

    // ═══════════ La pantalla y sus acciones ═══════════

    public function test_la_pantalla_abre_y_muestra_las_dos_cifras(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Se debe hoy')
            ->assertSee('Se espera este mes')
            ->assertSee('956.78')
            ->assertSee('Distribuidora Ejemplo, S.A. de C.V.');
    }

    public function test_pagar_desde_la_pantalla_salda_la_obligacion(): void
    {
        $u = $this->operador();
        $gasto = $this->cuentaAbierta($u, 'Starlink', '48.00');

        $this->actingAs($u)->post(route('gastos.panel.pagar', $gasto), [
            'clave' => (string) Str::uuid(),
            'fecha' => '2026-09-15',
            'metodo' => 'transferencia',
        ])->assertRedirect(route('gastos.panel'));

        $this->assertSame(0, (int) $this->panel($u)['se_debe']);
    }

    public function test_abonar_desde_la_pantalla_baja_el_saldo_sin_cancelarlo(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        $this->actingAs($u)->post(route('gastos.panel.abonos'), [
            'clave' => (string) Str::uuid(),
            'beneficiario' => 'Proveedor A',
            'importe' => '400.00',
            'fecha' => '2026-09-15',
            'metodo' => 'transferencia',
            'moneda' => 'USD',
        ])->assertRedirect(route('gastos.panel'));

        $this->assertSame(100000, (int) $this->panel($u)['se_debe'], 'Quedan 1.000 de los 1.400.');
    }

    public function test_compre_y_pague_no_deja_ninguna_deuda(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->post(route('gastos.panel.compras'), [
            'clave' => (string) Str::uuid(),
            'concepto' => 'Bolsas para empaque',
            'importe' => '25.00',
            'fecha' => '2026-09-15',
            'metodo' => 'efectivo',
            'ambito' => 'empresarial',
            'moneda' => 'USD',
        ])->assertRedirect(route('gastos.panel'));

        $datos = $this->panel($u);
        $this->assertSame(0, (int) $datos['se_debe'], 'Gasto y pago nacen juntos: nunca hubo deuda.');
        $this->assertCount(1, $datos['pagado_en_el_mes']);
    }

    public function test_anotar_el_monto_completa_la_obligacion_y_no_crea_otra(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'DELSUR', 'dia_mes' => 28, 'vigente_desde' => '2026-09-01',
            'monto_modo' => 'variable', 'importe' => null,
        ]);
        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-25')->startOfDay(), $u);

        $gasto = Gasto::where('beneficiario', 'DELSUR')->firstOrFail();
        $this->assertTrue($gasto->montoDesconocido());

        $this->actingAs($u)->post(route('gastos.panel.completar', $gasto), [
            'importe' => '73.40',
            'vence' => '2026-09-28',
        ])->assertRedirect(route('gastos.panel'));

        // La MISMA obligación, ahora con importe. No apareció una segunda.
        $this->assertSame(1, Gasto::where('beneficiario', 'DELSUR')->count());
        $this->assertSame('73.40', Gasto::where('beneficiario', 'DELSUR')->firstOrFail()->importe);
    }

    public function test_anotar_el_monto_respeta_el_vencimiento_real_del_recibo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'CAES', 'dia_mes' => 28, 'vigente_desde' => '2026-09-01',
            'monto_modo' => 'variable', 'importe' => null,
        ]);
        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-25')->startOfDay(), $u);
        $gasto = Gasto::where('beneficiario', 'CAES')->firstOrFail();

        // El papel dice el 30, no el 28 que proyectaba la regla. Manda el papel.
        $this->actingAs($u)->post(route('gastos.panel.completar', $gasto), [
            'importe' => '52.10',
            'vence' => '2026-09-30',
        ])->assertRedirect(route('gastos.panel'));

        $this->assertSame('2026-09-30', $gasto->fresh()->cuotas()->first()->vence->toDateString());
    }

    public function test_sin_permiso_de_pago_la_pantalla_se_ve_pero_no_se_puede_pagar(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer]);
        $gasto = $this->cuentaAbierta($u, 'Starlink', '48.00');

        $this->actingAs($u)->get(route('gastos.panel'))->assertOk();

        $this->actingAs($u)->post(route('gastos.panel.pagar', $gasto), [
            'clave' => (string) Str::uuid(),
            'fecha' => '2026-09-15',
            'metodo' => 'transferencia',
        ])->assertForbidden();
    }

    // ═══════════ Una previsión no se evapora al vencer ═══════════

    /**
     * El escenario exacto: generación automática APAGADA —como está hoy— y el reloj
     * pasa por encima del vencimiento. Nadie crea la obligación, así que la fila
     * tiene que seguir ahí. Si desapareciera, esa cuenta dejaría de mencionarse sin
     * que nadie lo hubiera decidido.
     */
    public function test_una_prevision_vencida_sin_generar_sigue_visible_y_no_se_evapora(): void
    {
        $this->assertFalse((bool) config('gastos.recurrencias.generacion_automatica'),
            'Este caso describe el sistema con la generación desatendida apagada.');

        $u = $this->operador();
        // Starlink: día 28, arranque el 14. El 28 es POSTERIOR al arranque.
        $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'concepto' => 'Internet satelital',
            'dia_mes' => 28, 'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        // Antes del día: es previsión.
        $antes = $this->panel($u, [], '2026-09-20');
        $this->assertCount(1, $antes['previsiones']);
        $this->assertCount(0, $antes['fecha_pasada']);

        // El reloj pasa el vencimiento y nadie generó nada.
        $despues = $this->panel($u, [], '2026-09-29');

        $this->assertCount(0, $despues['previsiones'], 'Ya no es «lo que viene».');
        $this->assertCount(1, $despues['fecha_pasada'], 'Pero NO desaparece: sigue esperando una decisión.');

        $fila = $despues['fecha_pasada']->first();
        $this->assertSame('2026-09-28', $fila->vence);
        $this->assertSame(4800, (int) $fila->importe_previsto);
    }

    public function test_la_fecha_pasada_no_suma_a_la_deuda_ni_a_la_prevision(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'dia_mes' => 28,
            'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        $datos = $this->panel($u, [], '2026-09-29');

        $this->assertCount(1, $datos['fecha_pasada']);
        // Separada de la deuda confirmada: nadie verificó que se deba.
        $this->assertSame(0, (int) $datos['se_debe']);
        // Y tampoco es ya una previsión.
        $this->assertSame(0, (int) $datos['se_espera']);
        $this->assertCount(0, $datos['vencido'], 'No es una obligación vencida: no existe como obligación.');
    }

    /**
     * El defecto que esto previene: acotar la mirada al mes en curso hacía que la
     * fila se borrara sola al cambiar de mes. Un dato que se borra al cumplir X días
     * es igual de invisible que uno que nunca se mostró.
     */
    public function test_la_fecha_pasada_sigue_visible_meses_despues(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'dia_mes' => 28,
            'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        // Tres meses más tarde, todavía sin generar nada.
        $datos = $this->panel($u, [], '2026-12-05');

        $vencimientos = $datos['fecha_pasada']->pluck('vence')->all();
        $this->assertContains('2026-09-28', $vencimientos, 'La de septiembre no puede haberse borrado sola.');
        // Y las siguientes tampoco se generaron, así que también esperan decisión.
        $this->assertSame(['2026-09-28', '2026-10-28', '2026-11-28'], $vencimientos);
        $this->assertSame(0, (int) $datos['se_debe']);
    }

    public function test_generar_el_periodo_la_convierte_en_deuda_y_deja_de_estar_por_confirmar(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'dia_mes' => 28,
            'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        $this->assertCount(1, $this->panel($u, [], '2026-09-29')['fecha_pasada']);

        // Una persona confirma que sí se debe, generando el período.
        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-29')->startOfDay(), $u);

        $datos = $this->panel($u, [], '2026-09-29');

        // REEMPLAZO, no duplicación: pasó a deuda y salió de «por confirmar».
        $this->assertSame(4800, (int) $datos['se_debe']);
        $this->assertCount(0, $datos['fecha_pasada'], 'Ya existe la obligación: esta fila no se vuelve a emitir.');
        $this->assertCount(0, $datos['previsiones']);
        $this->assertCount(1, $datos['vencido'], 'Ahora sí es una obligación, y está vencida.');
    }

    public function test_lo_anterior_al_arranque_nunca_entra_en_fecha_pasada(): void
    {
        $u = $this->operador();
        // Auto Fácil: día 10, arranque el 15. El 10 quedó del lado de atrás.
        $this->crearRegla($u);

        // Mucho después, la exclusión se conserva.
        $datos = $this->panel($u, [], '2026-11-20');

        foreach ($datos['fecha_pasada'] as $fila) {
            $this->assertNotSame('2026-09-10', $fila->vence,
                'Un período anterior al arranque no se convierte en «por confirmar» con el paso del tiempo.');
        }

        // Sigue donde corresponde, con su propio motivo.
        $this->assertCount(1, $datos['anteriores_al_arranque']);
        $this->assertSame('2026-09-10', $datos['anteriores_al_arranque']->first()->vence);

        // Y las de octubre y noviembre, que sí son posteriores al arranque, esperan.
        $this->assertSame(['2026-10-10', '2026-11-10'], $datos['fecha_pasada']->pluck('vence')->all());
    }

    public function test_una_regla_pausada_deja_de_acumular_fechas_por_confirmar(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'dia_mes' => 28,
            'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        app(AdministrarReglas::class)->pausar($u, $regla, 'Se dio de baja el servicio.');

        // Pausada no genera y tampoco reclama decisiones: sale de la pantalla.
        $this->assertCount(0, $this->panel($u, [], '2026-12-05')['fecha_pasada']);
    }

    public function test_la_pantalla_muestra_el_grupo_de_fecha_pasada(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'dia_mes' => 28,
            'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00'));

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Fecha pasada · por confirmar', false)
            ->assertSee('Starlink');
    }

    // ═══════════ Pagar una fila programada sin salir de la pantalla ═══════════

    /**
     * Pagar una fila programada el 29 de septiembre.
     *
     * El reloj se mueve de verdad, y hace falta por dos motivos distintos: el pago no
     * puede llevar una fecha futura, y la ventana de períodos pagables la calcula el
     * servidor con `now()`. Pasar solo la fecha en el cuerpo dejaría el servidor
     * mirando otro mes.
     *
     * @param  array<string, mixed>  $extra
     */
    private function pagarProgramado(User $u, Regla $regla, string $periodo, array $extra = [])
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00'));

        return $this->actingAs($u)->post(route('gastos.panel.programado.pagar', $regla), array_replace([
            'clave' => (string) Str::uuid(),
            'periodo' => $periodo,
            'fecha' => '2026-09-29',
            'metodo' => 'transferencia',
        ], $extra));
    }

    private function starlink(User $u): Regla
    {
        return $this->crearRegla($u, [
            'beneficiario' => 'Starlink', 'concepto' => 'Internet satelital',
            'dia_mes' => 28, 'vigente_desde' => '2026-09-14', 'importe' => '48.00',
        ]);
    }

    public function test_pagar_una_fila_programada_crea_la_obligacion_y_el_pago_de_una_vez(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        $this->assertSame(0, Gasto::where('beneficiario', 'Starlink')->count());

        $this->pagarProgramado($u, $regla, '2026-09')->assertRedirect(route('gastos.panel'));

        // Se creó la obligación del período...
        $gasto = Gasto::where('beneficiario', 'Starlink')->firstOrFail();
        $this->assertSame('48.00', $gasto->importe);
        $this->assertSame(1, Ocurrencia::where('regla_id', $regla->id)->where('periodo', '2026-09')->count());

        // ...y quedó pagada, sin pasar por la pantalla de reglas.
        $datos = $this->panel($u, [], '2026-09-29');
        $this->assertSame(0, (int) $datos['se_debe']);
        $this->assertCount(0, $datos['fecha_pasada'], 'Ya existe la obligación: sale de «por confirmar».');
        $this->assertCount(1, $datos['pagado_en_el_mes']);
    }

    public function test_confirmar_dos_veces_no_duplica_la_obligacion_ni_la_deuda(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        // Dos envíos con claves de pago distintas: el peor caso, porque la idempotencia
        // del pago no ayuda. Lo que no puede pasar es que haya dos deudas de septiembre.
        $this->pagarProgramado($u, $regla, '2026-09')->assertRedirect(route('gastos.panel'));
        $this->pagarProgramado($u, $regla, '2026-09')->assertRedirect(route('gastos.panel'));

        $this->assertSame(1, Gasto::where('beneficiario', 'Starlink')->count(), 'Una sola obligación del período.');
        $this->assertSame(1, Ocurrencia::where('regla_id', $regla->id)->where('periodo', '2026-09')->count());
        $this->assertSame(0, (int) $this->panel($u, [], '2026-09-29')['se_debe']);

        // El segundo intento no registró un pago de más: la obligación ya no tenía saldo.
        $this->assertSame(1, DB::table('gastos_pagos')->whereNull('revertido_at')->count());
    }

    public function test_abrir_o_cancelar_no_crea_ninguna_deuda(): void
    {
        $u = $this->operador();
        $this->starlink($u);

        // Abrir la pantalla es lo único que ocurre al desplegar el panel: el formulario
        // se muestra en el navegador y no toca el servidor.
        $this->actingAs($u)->get(route('gastos.panel'))->assertOk();
        $this->actingAs($u)->get(route('gastos.panel'))->assertOk();

        $this->assertSame(0, Gasto::count(), 'Mirar la pantalla no puede crear deuda.');
        $this->assertSame(0, Ocurrencia::count());
        $this->assertSame(0, DB::table('gastos_pagos')->count());
    }

    public function test_no_se_puede_pagar_un_periodo_anterior_al_arranque_aunque_se_mande_a_mano(): void
    {
        $u = $this->operador();
        // Auto Fácil: día 10, arranque el 15. Septiembre quedó excluido.
        $regla = $this->crearRegla($u);

        // Alguien manda el período a mano, saltándose la pantalla.
        $this->pagarProgramado($u, $regla, '2026-09')->assertStatus(422);

        $this->assertSame(0, Gasto::count(), 'La exclusión del arranque es del sistema, no de la vista.');
        $this->assertSame(0, Ocurrencia::count());
    }

    public function test_no_se_puede_pagar_un_periodo_que_la_regla_no_produce(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        // Un mes fuera de la ventana pagable (del arranque al fin del mes en curso).
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00'));
        $this->pagarProgramado($u, $regla, '2026-12')->assertStatus(422);

        $this->assertSame(0, Gasto::count());
    }

    public function test_una_fila_de_monto_variable_exige_el_importe_del_recibo(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'DELSUR', 'concepto' => 'Luz de la fábrica',
            'dia_mes' => 28, 'vigente_desde' => '2026-09-14',
            'monto_modo' => 'variable', 'importe' => null,
        ]);

        // Sin importe no se puede: la obligación nacería sin cifra y no habría qué pagar.
        $this->pagarProgramado($u, $regla, '2026-09')->assertSessionHasErrors('importe');

        // Con el importe del recibo, se completa y se paga.
        $this->pagarProgramado($u, $regla, '2026-09', ['importe' => '73.40'])
            ->assertRedirect(route('gastos.panel'));

        $gasto = Gasto::where('beneficiario', 'DELSUR')->firstOrFail();
        $this->assertSame('73.40', $gasto->importe);
        $this->assertSame(0, (int) $this->panel($u, [], '2026-09-29')['se_debe']);
    }

    public function test_un_periodo_omitido_no_se_resucita_pagandolo(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        app(AdministrarReglas::class)->omitir($u, $regla, '2026-09', 'Ese mes lo cubrió la promoción.');

        $this->pagarProgramado($u, $regla, '2026-09')->assertSessionHasErrors('periodo');

        $this->assertSame(0, Gasto::where('beneficiario', 'Starlink')->count(),
            'Omitir fue una decisión con motivo; pagar no puede deshacerla por la puerta de atrás.');
    }

    /**
     * Un método de pago que no está en el catálogo se rechaza ANTES de escribir nada.
     *
     * Antes no: el controlador aceptaba cualquier cadena y el catálogo se comprobaba
     * dentro de RegistrarPago, o sea DESPUÉS de haber creado la obligación. Una
     * petición que no puede terminar bien dejaba una deuda nueva detrás.
     */
    public function test_un_metodo_de_pago_invalido_no_crea_ninguna_obligacion(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        $this->pagarProgramado($u, $regla, '2026-09', ['metodo' => 'metodo_inventado'])
            ->assertSessionHasErrors('metodo');

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Ocurrencia::count());
        $this->assertSame(0, DB::table('gastos_pagos')->count());
    }

    /**
     * Si el pago falla DESPUÉS de crear la obligación, no queda la obligación creada.
     *
     * El fallo se provoca como pasaría de verdad: alguien desactiva al usuario mientras
     * la petición está en vuelo. El modelo ya cargado sigue diciendo que está activo, así
     * que los permisos pasan y la obligación se crea; un instante después, la validación
     * de `pagado_por` consulta la base, lo encuentra desactivado y el pago revienta.
     *
     * Ese es exactamente el punto donde antes quedaba la deuda a medias: gasto, cuota y
     * ocurrencia escritos, cero pagos. Se comprobó primero contra MySQL —donde se vio el
     * defecto— y esta prueba lo fija para que no vuelva.
     */
    public function test_si_el_pago_falla_no_queda_la_obligacion_creada(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00'));
        $this->actingAs($u);

        // El usuario se desactiva en la base; el modelo en memoria no se entera.
        DB::table('users')->where('id', $u->id)->update(['activo' => false]);

        $this->post(route('gastos.panel.programado.pagar', $regla), [
            'clave' => (string) Str::uuid(),
            'periodo' => '2026-09',
            'fecha' => '2026-09-29',
            'metodo' => 'transferencia',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Gasto::count(), 'El pago falló: la obligación tiene que haberse deshecho con él.');
        $this->assertSame(0, Ocurrencia::count());
        $this->assertSame(0, DB::table('gastos_cuotas')->count());
        $this->assertSame(0, DB::table('gastos_pagos')->count());
    }

    /**
     * Si la obligación YA EXISTÍA, un pago fallido no puede borrarla.
     *
     * La transacción solo deshace lo que hizo esta petición. Una deuda que ya estaba
     * —generada antes, por la corrida programada o por otra persona— sobrevive intacta
     * al fallo, que es lo correcto: no la creó esta operación y no le pertenece.
     */
    public function test_un_pago_fallido_no_borra_una_obligacion_que_ya_existia(): void
    {
        $u = $this->operador();
        $regla = $this->starlink($u);

        // La obligación se genera antes, por el camino de siempre.
        app(GenerarObligaciones::class)->paraRegla($regla, CarbonImmutable::parse('2026-09-25')->startOfDay(), $u);
        $this->assertSame(1, Gasto::where('beneficiario', 'Starlink')->count());

        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00'));
        $this->actingAs($u);
        DB::table('users')->where('id', $u->id)->update(['activo' => false]);

        $this->post(route('gastos.panel.programado.pagar', $regla), [
            'clave' => (string) Str::uuid(),
            'periodo' => '2026-09',
            'fecha' => '2026-09-29',
            'metodo' => 'transferencia',
        ])->assertSessionHasErrors();

        // Sigue ahí, y sigue sin pagar.
        $this->assertSame(1, Gasto::where('beneficiario', 'Starlink')->count());
        $this->assertSame(1, Ocurrencia::where('regla_id', $regla->id)->count());
        $this->assertSame(0, DB::table('gastos_pagos')->count());
    }

    public function test_pagar_una_fila_programada_exige_los_dos_permisos(): void
    {
        $dueno = $this->operador();
        $regla = $this->starlink($dueno);

        // Puede pagar, pero no registrar: no puede fabricar la deuda que va a pagar.
        $soloPaga = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar]);
        $this->pagarProgramado($soloPaga, $regla, '2026-09')->assertForbidden();

        // Puede registrar, pero no pagar.
        $soloRegistra = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar]);
        $this->pagarProgramado($soloRegistra, $regla, '2026-09')->assertForbidden();

        $this->assertSame(0, Gasto::count());
    }

    public function test_quien_no_ve_lo_personal_no_puede_pagar_una_regla_personal(): void
    {
        $dueno = $this->operador();
        $regla = $this->crearRegla($dueno, [
            'ambito' => 'personal', 'beneficiario' => 'Netflix',
            'dia_mes' => 28, 'vigente_desde' => '2026-09-14', 'importe' => '14.00',
        ]);

        $soloEmpresa = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPagosRegistrar,
        ]);

        $this->pagarProgramado($soloEmpresa, $regla, '2026-09')->assertForbidden();
        $this->assertSame(0, Gasto::count());
    }

    // ═══════════ Los textos que afirman de menos o de más ═══════════

    public function test_la_cifra_registrada_dice_cuanto_es_provisional(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');
        $this->cuentaAbierta($u, 'Proveedor B Hernández', '1000.00', 'provisional');
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        $datos = $this->panel($u);

        $this->assertSame(335678, (int) $datos['se_debe']);
        // El provisional es PARTE del total, no un sumando aparte.
        $this->assertSame(240000, (int) $datos['se_debe_provisional']);
        $this->assertLessThan((int) $datos['se_debe'], (int) $datos['se_debe_provisional']);

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Saldo registrado', false)
            ->assertSee('2,400.00')
            // Ya no se afirma que todo esté confirmado.
            ->assertDontSee('Confirmado: hay papel o saldo acordado', false);
    }

    public function test_lo_programado_no_se_presenta_como_que_no_se_debe(): void
    {
        $u = $this->operador();
        $this->starlink($u);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Programado · pendiente de confirmar', false)
            // Que no exista el registro no demuestra que no se deba.
            ->assertDontSee('todavía no se deben', false)
            ->assertDontSee('Previsión, todavía no se debe', false);
    }

    public function test_sin_vencidos_se_resuelve_en_una_linea(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Sin vencidos.')
            ->assertDontSee('Nada vencido. Al día.');
    }

    // ═══════════ El panel como puerta del área ═══════════

    public function test_entrar_al_area_abre_el_panel(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        // La raíz del área ES el panel, no una redirección hacia él.
        $this->actingAs($u)->get('/gastos')
            ->assertOk()
            ->assertSee('Se debe hoy')
            ->assertSee('Saldo registrado', false);

        $this->assertSame(url('/gastos'), route('gastos.panel'));
    }

    public function test_el_menu_por_pagar_lleva_al_panel(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            // La fila «Por pagar» del menú apunta a la raíz del área, que es el panel.
            ->assertSee('href="'.route('gastos.panel').'"', false)
            ->assertSee('Por pagar');
    }

    public function test_el_listado_clasico_sigue_existiendo_con_su_busqueda_y_sus_filtros(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        // Sigue en pie, en su URL propia, y sigue filtrando.
        $this->assertSame(url('/gastos/listado'), route('gastos.index'));

        $this->actingAs($u)->get(route('gastos.index'))
            ->assertOk()
            ->assertSee('Buscar y filtrar')
            ->assertSee('Distribuidora Ejemplo, S.A. de C.V.')
            ->assertSee('Proveedor A');

        // La búsqueda por texto recorta de verdad.
        $this->actingAs($u)->get(route('gastos.index', ['q' => 'Distribuidora Ejemplo, S.A. de C.V.']))
            ->assertOk()
            ->assertSee('Distribuidora Ejemplo, S.A. de C.V.')
            ->assertDontSee('Proveedor A');

        // Y las pestañas siguen ahí.
        $this->actingAs($u)->get(route('gastos.index', ['pestana' => 'por_completar']))->assertOk();
    }

    public function test_el_listado_clasico_sale_del_recorrido_habitual_pero_queda_accesible(): void
    {
        $u = $this->operador();

        $panel = $this->actingAs($u)->get(route('gastos.panel'))->assertOk();

        // Ya no se ofrece como «el listado clásico»...
        $panel->assertDontSee('Ver el listado clásico');
        // ...pero se llega por lo que hace.
        $panel->assertSee('Buscar y filtrar');
        $panel->assertSee('href="'.route('gastos.index').'"', false);
    }

    public function test_el_panel_ofrece_los_accesos_secundarios_en_orden(): void
    {
        $u = $this->operador();
        $html = $this->actingAs($u)->get(route('gastos.panel'))->assertOk()->getContent();

        $cuentas = mb_strpos($html, route('gastos.cuentas'));
        $historial = mb_strpos($html, route('gastos.pagos.index'));
        $reglas = mb_strpos($html, route('gastos.reglas.index'));
        $informes = mb_strpos($html, route('gastos.informes'));

        foreach (['cuentas' => $cuentas, 'historial' => $historial, 'reglas' => $reglas, 'informes' => $informes] as $nombre => $pos) {
            $this->assertNotFalse($pos, "Falta el acceso a «{$nombre}».");
        }

        $this->assertTrue($cuentas < $historial && $historial < $reglas && $reglas < $informes,
            'Cuentas, historial, gastos que se repiten e informes, en ese orden.');
    }

    public function test_volver_despues_de_registrar_un_pago_lleva_al_panel_sin_perder_el_detalle(): void
    {
        $u = $this->operador();
        $gasto = $this->cuentaAbierta($u, 'Starlink', '48.00');
        $cuota = $gasto->cuotas()->firstOrFail();

        // La pantalla de pagos manda el reparto como `aplicar[cuota_id] = importe`, y
        // exige comprobante o el motivo de no tenerlo.
        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(),
            'importe' => '48.00',
            'fecha' => self::HOY,
            'metodo' => 'transferencia',
            'pagado_por' => $u->id,
            'sin_comprobante' => 'Pago por transferencia, el banco no emitió voucher.',
            'aplicar' => [$cuota->id => '48.00'],
        ])->assertRedirect(route('gastos.panel'));

        // El detalle del pago no se pierde: viaja como enlace del aviso.
        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Ver el pago y adjuntar el comprobante');
    }

    // ═══════════ El abono vuelve a donde empezó ═══════════

    /** @param  array<string, mixed>  $extra */
    private function abonar(User $u, string $proveedor, array $extra = [])
    {
        return $this->actingAs($u)->post(route('gastos.cuentas.abonos'), array_replace([
            'clave' => (string) Str::uuid(),
            'beneficiario' => $proveedor,
            'importe' => '400.00',
            'fecha' => self::HOY,
            'metodo' => 'transferencia',
            'moneda' => 'USD',
        ], $extra));
    }

    public function test_un_abono_iniciado_en_la_cuenta_vuelve_a_la_cuenta(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        // Quien abona tres compras seguidas del mismo proveedor no quiere salir de la
        // libreta y volver a entrar cada vez.
        $this->abonar($u, 'Proveedor A', ['origen' => 'cuenta'])
            ->assertRedirect(route('gastos.cuentas.show', [
                'proveedor' => 'Proveedor A', 'moneda' => 'USD',
            ]));
    }

    public function test_un_abono_iniciado_en_el_panel_vuelve_al_panel(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        $this->actingAs($u)->post(route('gastos.panel.abonos'), [
            'clave' => (string) Str::uuid(),
            'origen' => 'panel',
            'beneficiario' => 'Proveedor A',
            'importe' => '400.00',
            'fecha' => self::HOY,
            'metodo' => 'transferencia',
            'moneda' => 'USD',
        ])->assertRedirect(route('gastos.panel'));
    }

    public function test_sin_origen_declarado_el_abono_vuelve_al_panel(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        // El panel es el destino por defecto, y desde ahí la cuenta queda a un clic.
        $this->abonar($u, 'Proveedor A')->assertRedirect(route('gastos.panel'));

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Ver la cuenta de Proveedor A');
    }

    /**
     * El destino NO se acepta como dirección. Es una palabra de una lista cerrada.
     *
     * Si el campo llevara una URL, bastaría con fabricar un enlace a nuestro propio
     * formulario con un sitio ajeno dentro para que el sistema, después de una
     * operación legítima y ya autenticada, depositara a la persona fuera de casa.
     */
    public function test_un_origen_manipulado_no_saca_a_nadie_del_sistema(): void
    {
        $u = $this->operador();

        foreach (['https://sitio-ajeno.example/entrar', '//sitio-ajeno.example', 'javascript:alert(1)', 'inventado'] as $intento) {
            $this->cuentaAbierta($u, 'Proveedor '.md5($intento), '100.00');

            $this->abonar($u, 'Proveedor '.md5($intento), ['origen' => $intento, 'importe' => '100.00'])
                // Ni redirige fuera, ni revienta: el valor no está en la lista y se
                // rechaza en la validación.
                ->assertSessionHasErrors('origen');
        }
    }

    public function test_la_compra_a_cuenta_tambien_vuelve_a_la_libreta(): void
    {
        $u = $this->operador();
        $this->cuentaAbierta($u, 'Proveedor A', '1400.00', 'provisional');

        $this->actingAs($u)->post(route('gastos.cuentas.compras'), [
            'clave' => (string) Str::uuid(),
            'origen' => 'cuenta',
            'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria de la semana',
            'importe' => '250.00',
            'fecha' => self::HOY,
            'moneda' => 'USD',
            'ambito' => 'empresarial',
            'categoria' => 'Productos',
        ])->assertRedirect(route('gastos.cuentas.show', [
            'proveedor' => 'Proveedor A', 'moneda' => 'USD',
        ]));
    }

    public function test_volver_despues_de_compre_y_pague_lleva_al_panel(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->post(route('gastos.compre-y-pague.store'), [
            'clave' => (string) Str::uuid(),
            'concepto' => 'Bolsas para empaque',
            'importe' => '25.00',
            'fecha' => self::HOY,
            'metodo' => 'efectivo',
            'ambito' => 'empresarial',
            'moneda' => 'USD',
        ])->assertRedirect(route('gastos.panel'));

        $this->actingAs($u)->get(route('gastos.panel'))->assertOk()->assertSee('Abrir la ficha');
    }

    // ═══════════ Los permisos, con la puerta cambiada ═══════════

    public function test_sin_permiso_de_ver_no_se_entra_ni_al_panel_ni_al_listado(): void
    {
        $sinNada = $this->usuario([]);

        $this->actingAs($sinNada)->get(route('gastos.panel'))->assertForbidden();
        $this->actingAs($sinNada)->get(route('gastos.index'))->assertForbidden();
    }

    public function test_quien_solo_ve_no_recibe_los_botones_de_accion(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer]);
        $this->cuentaAbierta($u, 'Distribuidora Ejemplo, S.A. de C.V.', '956.78', 'confirmado');

        $this->actingAs($u)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Distribuidora Ejemplo, S.A. de C.V.')
            ->assertDontSee('Compré y pagué')
            ->assertDontSee('>Abonar<', false);
    }

    public function test_planilla_sigue_integrada_y_con_su_propia_llave(): void
    {
        config()->set('planilla.enabled', true);

        // Con `gastos.ver` a secas no se ve ni el título del grupo de Planilla.
        $soloGastos = $this->usuario([PermisoSistema::GastosVer]);
        $this->actingAs($soloGastos)->get(route('gastos.panel'))
            ->assertOk()
            ->assertDontSee('Planilla');

        // Con la llave de Planilla, el grupo aparece dentro de la misma área.
        $conPlanilla = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::PlanillaVer]);
        $this->actingAs($conPlanilla)->get(route('gastos.panel'))
            ->assertOk()
            ->assertSee('Planilla')
            ->assertSee(route('planilla.index'), false);
    }

    /**
     * Una regla con fecha final NO proyecta más allá de ella.
     *
     * El caso real: la universidad son dos mensualidades, septiembre y octubre de
     * 2026, y octubre es la última. Si la vigencia no cortara, noviembre aparecería
     * como programado y después como «fecha pasada por confirmar», reclamando una
     * decisión sobre una deuda que no existe. La aritmética del calendario SÍ produce
     * noviembre y diciembre; lo que lo impide es la vigencia, y eso es lo que se fija
     * acá.
     */
    public function test_una_regla_con_fecha_final_no_proyecta_despues_de_esa_fecha(): void
    {
        $u = $this->operador();
        $this->crearRegla($u, [
            'beneficiario' => 'Universidad', 'concepto' => 'Mensualidad de la universidad',
            'ambito' => 'personal', 'importe' => '110.00', 'dia_mes' => 15,
            'vigente_desde' => '2026-09-01', 'vigente_hasta' => '2026-10-15',
        ]);

        // En octubre, la del 15 todavía se proyecta. Es la única regla de la prueba.
        $octubre = $this->panel($u, [], '2026-10-01');
        $this->assertSame(['2026-10-15'], $octubre['previsiones']->pluck('vence')->all());

        // En noviembre la universidad ya no proyecta NADA: octubre era la última.
        $noviembre = $this->panel($u, [], '2026-11-20');
        $this->assertSame([], $noviembre['previsiones']->where('beneficiario', 'Universidad')->pluck('vence')->all());

        // Lo que SÍ sigue ahí son septiembre y octubre, que vencieron sin que nadie
        // las registrara: esas esperan una decisión y no se borran solas. Lo que no
        // puede existir en ninguna lista es NOVIEMBRE, que está fuera de la vigencia.
        $todas = $noviembre['previsiones']->concat($noviembre['fecha_pasada'])
            ->where('beneficiario', 'Universidad')->pluck('vence')->sort()->values()->all();

        $this->assertSame(['2026-09-15', '2026-10-15'], $todas);
        $this->assertNotContains('2026-11-15', $todas, 'Octubre era la última mensualidad.');
    }

    public function test_un_periodo_posterior_a_la_fecha_final_no_se_puede_pagar(): void
    {
        $u = $this->operador();
        $regla = $this->crearRegla($u, [
            'beneficiario' => 'Universidad', 'ambito' => 'personal', 'importe' => '110.00',
            'dia_mes' => 15, 'vigente_desde' => '2026-09-01', 'vigente_hasta' => '2026-10-15',
        ]);

        // Noviembre está fuera de la vigencia: el servidor no lo acepta ni a mano.
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
        $this->actingAs($u)->post(route('gastos.panel.programado.pagar', $regla), [
            'clave' => (string) Str::uuid(),
            'periodo' => '2026-11',
            'fecha' => '2026-11-20',
            'metodo' => 'transferencia',
        ])->assertStatus(422);

        $this->assertSame(0, Gasto::where('beneficiario', 'Universidad')->count());
    }

    public function test_con_el_modulo_apagado_la_pantalla_no_existe(): void
    {
        config()->set('gastos.enabled', false);

        $this->actingAs($this->operador())->get(route('gastos.panel'))->assertNotFound();
    }
}
