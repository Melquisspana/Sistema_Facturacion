<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\CompraContado;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Los formularios simples: compra al contado, cuenta de proveedor y recurrente a medio
 * llenar.
 *
 * Simplificar una pantalla es fácil si se permite aflojar lo de atrás. Lo que defiende
 * este archivo es que NO se aflojó nada:
 *
 *  1. El ámbito se PREGUNTA y se respeta. Nada se clasifica como «empresa» por ahorrar
 *     un campo, y quien no tiene permiso para lo personal no puede registrarlo.
 *  2. El saldo de una cuenta sale de las compras, no de un campo guardado.
 *  3. El abono se reparte de lo más viejo a lo más nuevo, PERO se puede dirigir a una
 *     compra concreta.
 *  4. Nada se duplica: ni la compra al contado, ni el abono, ni el pago que va dentro.
 *  5. Una regla «Por completar» NO genera obligaciones.
 */
class FormulariosSimplesTest extends TestCase
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

    /** @param  array<int, PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Quien registra y paga, con acceso a lo personal. */
    private function completo(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::GastosPersonales, PermisoSistema::GastosRecurrencias,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);
    }

    // ═══════════ 1 · Compré y pagué ═══════════

    public function test_la_compra_al_contado_crea_gasto_y_pago_en_una_operacion(): void
    {
        $u = $this->completo();

        ['gasto' => $gasto, 'pago' => $pago] = app(CompraContado::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        $this->assertSame('85.00', $gasto->importe);
        $this->assertSame('85.00', $pago->importe);
        $this->assertSame('compra', $gasto->naturaleza);

        // Queda saldado en el acto: la deuda no llega a existir para nadie.
        $cuota = $gasto->cuotas()->first();
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($cuota));

        // Y es un gasto NORMAL: aparece en el historial como cualquier otro.
        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Pago::count());
    }

    public function test_sin_proveedor_se_dice_asi_y_no_se_deja_en_blanco(): void
    {
        $u = $this->completo();

        ['gasto' => $gasto] = app(CompraContado::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        // «Sin proveedor» es un dato; una cadena vacía es una pregunta sin responder.
        $this->assertSame('Sin proveedor', $gasto->beneficiario);
    }

    public function test_repetir_la_compra_al_contado_no_la_duplica(): void
    {
        $u = $this->completo();
        $clave = (string) Str::uuid();

        $datos = [
            'clave' => $clave, 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ];

        app(CompraContado::class)->registrar($u, $datos);
        app(CompraContado::class)->registrar($u, $datos);
        app(CompraContado::class)->registrar($u, $datos);

        // Un gasto y un pago, pase lo que pase con el doble clic.
        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Pago::count());
    }

    // ═══════════ 2 · El ámbito se pregunta y se respeta ═══════════

    public function test_el_ambito_personal_se_guarda_como_personal(): void
    {
        $u = $this->completo();

        ['gasto' => $gasto] = app(CompraContado::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Focos para la casa', 'importe' => '12.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'personal', 'moneda' => 'USD',
        ]);

        // Nada se clasifica como empresa por ahorrar un campo.
        $this->assertSame('personal', $gasto->ambito);
    }

    public function test_sin_permiso_personal_no_se_puede_registrar_algo_personal(): void
    {
        // Registra y paga, pero NO alcanza lo personal.
        $limitado = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        // Lo de la empresa sí puede.
        $esto = app(CompraContado::class)->registrar($limitado, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);
        $this->assertSame('empresarial', $esto['gasto']->ambito);

        // Lo personal, no. Esconder la opción en la pantalla no autoriza nada.
        $this->expectException(HttpException::class);
        app(CompraContado::class)->registrar($limitado, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Focos', 'importe' => '12.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'personal', 'moneda' => 'USD',
        ]);
    }

    public function test_registrar_sin_permiso_de_pagos_no_se_permite(): void
    {
        // Puede registrar gastos pero no pagos. «Compré y pagué» hace las dos cosas,
        // así que necesita los dos permisos.
        $soloGastos = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        $this->expectException(HttpException::class);
        app(CompraContado::class)->registrar($soloGastos, [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);
    }

    // ═══════════ 3 · La cuenta de proveedor ═══════════

    private function compra(User $u, string $concepto, string $importe, ?string $vence = null): Gasto
    {
        return app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(),
            'beneficiario' => 'Proveedor A',
            'concepto' => $concepto, 'importe' => $importe,
            'fecha' => now()->toDateString(), 'ambito' => 'empresarial',
            'moneda' => 'USD', 'categoria' => 'Productos', 'vence' => $vence,
        ]);
    }

    public function test_las_compras_forman_el_saldo_y_los_abonos_lo_bajan(): void
    {
        $u = $this->completo();
        $cuenta = app(CuentaProveedor::class);

        $this->compra($u, 'Pepitoria', '1200.00');
        $this->assertSame(120000, $cuenta->saldo($u, 'Proveedor A', 'USD'));

        $this->compra($u, 'Maní', '300.00');
        $this->assertSame(150000, $cuenta->saldo($u, 'Proveedor A', 'USD'));

        $cuenta->abonar($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ]);

        $this->assertSame(110000, $cuenta->saldo($u, 'Proveedor A', 'USD'));
    }

    public function test_la_compra_no_exige_vencimiento_pero_lo_admite(): void
    {
        $u = $this->completo();

        // Sin vencimiento: es lo normal en una cuenta abierta.
        $sin = $this->compra($u, 'Pepitoria', '1200.00');
        $this->assertNull($sin->cuotas()->first()->vence);

        // Con vencimiento: una compra concreta sí puede haberse pactado a fecha.
        $con = $this->compra($u, 'Ajonjolí', '500.00', '2026-10-15');
        $this->assertSame('2026-10-15', $con->cuotas()->first()->vence->toDateString());
    }

    public function test_el_abono_se_reparte_de_lo_mas_viejo_a_lo_mas_nuevo(): void
    {
        $u = $this->completo();
        $cuenta = app(CuentaProveedor::class);

        $vieja = $this->compra($u, 'Pepitoria', '300.00');
        $nueva = $this->compra($u, 'Maní', '500.00');

        // El plan se puede mirar ANTES de confirmar: es lo que enseña la pantalla.
        $plan = $cuenta->repartir($u, 'Proveedor A', 'USD', 40000);

        $this->assertSame(2, count($plan['lineas']));
        $this->assertSame('Pepitoria', $plan['lineas'][0]['concepto']);
        $this->assertSame(30000, $plan['lineas'][0]['aplica'], 'La vieja se cubre entera.');
        $this->assertSame(10000, $plan['lineas'][1]['aplica'], 'El resto va a la siguiente.');
        $this->assertSame(0, $plan['sobrante']);

        $cuenta->abonar($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ]);

        $saldos = app(SaldosGastos::class);
        $this->assertSame(0, $saldos->pendienteCuota($vieja->cuotas()->first()), 'La vieja quedó saldada.');
        $this->assertSame(40000, $saldos->pendienteCuota($nueva->cuotas()->first()));
    }

    public function test_el_abono_se_puede_dirigir_a_una_compra_concreta(): void
    {
        $u = $this->completo();
        $cuenta = app(CuentaProveedor::class);

        $vieja = $this->compra($u, 'Pepitoria', '300.00');
        $nueva = $this->compra($u, 'Maní', '500.00');

        // «Estos 400 son de la compra de maní», aunque la de pepitoria sea más vieja.
        $cuenta->abonar($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ], [
            ['cuota_id' => $nueva->cuotas()->first()->id, 'importe' => '400.00'],
        ]);

        $saldos = app(SaldosGastos::class);
        $this->assertSame(30000, $saldos->pendienteCuota($vieja->cuotas()->first()), 'La vieja no se tocó.');
        $this->assertSame(10000, $saldos->pendienteCuota($nueva->cuotas()->first()));
    }

    public function test_un_abono_mayor_que_la_deuda_se_rechaza_con_la_cifra_exacta(): void
    {
        $u = $this->completo();
        $this->compra($u, 'Pepitoria', '300.00');

        try {
            app(CuentaProveedor::class)->abonar($u, [
                'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
                'importe' => '500.00', 'fecha' => now()->toDateString(),
                'metodo' => 'transferencia', 'moneda' => 'USD',
            ]);
            $this->fail('Tenía que rechazarse: se le abonan 500 y solo se le deben 300.');
        } catch (ValidationException $e) {
            // La cifra exacta, no un «revisá los datos».
            $this->assertStringContainsString('200.00', $e->getMessage());
        }

        $this->assertSame(0, Pago::count(), 'No se registró ningún pago.');
    }

    public function test_repetir_el_abono_no_lo_duplica(): void
    {
        $u = $this->completo();
        $this->compra($u, 'Pepitoria', '1200.00');

        $datos = [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ];

        app(CuentaProveedor::class)->abonar($u, $datos);
        app(CuentaProveedor::class)->abonar($u, $datos);

        $this->assertSame(1, Pago::count());
        $this->assertSame(80000, app(CuentaProveedor::class)->saldo($u, 'Proveedor A', 'USD'));
    }

    public function test_la_cuenta_saldada_desaparece_de_la_lista(): void
    {
        $u = $this->completo();
        $cuenta = app(CuentaProveedor::class);

        $this->compra($u, 'Pepitoria', '300.00');
        $this->assertSame(1, $cuenta->cuentas($u)->count());

        $cuenta->abonar($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '300.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ]);

        // Una cuenta sin deuda no es una cuenta abierta.
        $this->assertSame(0, $cuenta->cuentas($u)->count());
    }

    // ═══════════ 4 · El recurrente «Por completar» ═══════════

    /** @param  array<string, mixed>  $extra */
    private function regla(User $u, array $extra = []): Regla
    {
        return app(AdministrarReglas::class)->crear($u, array_merge([
            'clave' => (string) Str::uuid(), 'nombre' => 'Google One',
            'beneficiario' => 'Google', 'concepto' => 'Almacenamiento',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'documentacion' => 'pendiente', 'responsable_id' => $u->id,
            'monto_modo' => 'fijo', 'importe' => '14.00', 'frecuencia' => 'mensual',
            'dias_generar_antes' => 5, 'vigente_desde' => now()->toDateString(),
        ], $extra));
    }

    public function test_se_puede_guardar_un_recurrente_sin_saber_el_dia(): void
    {
        $u = $this->completo();

        $regla = $this->regla($u, ['incompleta' => true]);

        $this->assertSame('borrador', $regla->estado);
        $this->assertNull($regla->dia_mes, 'No se inventó ningún día.');
        $this->assertTrue($regla->porCompletar());
        $this->assertFalse($regla->programacionCompleta());
        $this->assertSame('14.00', $regla->importe, 'Lo que sí se sabía se guardó.');
    }

    public function test_un_recurrente_por_completar_no_genera_nada(): void
    {
        $u = $this->completo();
        $regla = $this->regla($u, ['incompleta' => true]);

        $resultado = app(GenerarObligaciones::class)->paraRegla(
            $regla, CarbonImmutable::parse(now()->addMonths(2)->toDateString()), $u
        );

        $this->assertSame(0, $resultado['generadas']);
        $this->assertSame(0, Gasto::count(), 'Ni una obligación, ni siquiera dos meses después.');
        $this->assertNotNull($resultado['motivo_omision']);
    }

    public function test_completar_la_programacion_la_activa_y_entonces_si_genera(): void
    {
        $u = $this->completo();
        $regla = $this->regla($u, ['incompleta' => true]);

        $activa = app(AdministrarReglas::class)->completarYActivar($u, $regla, ['dia_mes' => 14]);

        $this->assertSame('activa', $activa->estado);
        $this->assertSame(14, $activa->dia_mes);
        $this->assertTrue($activa->programacionCompleta());

        // Ahora sí genera.
        $resultado = app(GenerarObligaciones::class)->paraRegla(
            $activa, CarbonImmutable::parse(now()->addMonth()->startOfMonth()->addDays(13)->toDateString()), $u
        );

        $this->assertGreaterThan(0, $resultado['generadas']);
    }

    public function test_activar_sin_el_dia_se_rechaza(): void
    {
        $u = $this->completo();
        $regla = $this->regla($u, ['incompleta' => true]);

        // Activar sin decir cuándo sería justo lo que el borrador venía a evitar.
        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->completarYActivar($u, $regla, []);
    }

    /**
     * Una regla dormida medio año no despierta fabricando medio año de deuda.
     *
     * La garantía es la misma de siempre; cambió DÓNDE se comprueba. Antes, activar
     * reescribía `vigente_desde` a hoy y la prueba mirába ese campo. Eso tapaba el
     * problema de los meses viejos, sí, pero de paso borraba los períodos RECIENTES y
     * legítimos: una regla guardada el 1 y completada el 16 perdía su propio primer mes
     * en silencio, sin dejar rastro de que había existido.
     *
     * Ahora la vigencia se respeta tal como se guardó y quien contiene los meses viejos
     * es la ventana de recuperación de {@see GenerarObligaciones}, que es donde vivía el
     * candado de verdad: lo anterior a la ventana NO se crea, se informa en
     * `fuera_de_ventana` para que lo mire una persona. Por eso esta prueba ya no
     * comprueba una fecha, sino lo único que importa: qué deuda existe después.
     */
    public function test_activar_no_despierta_generando_la_deuda_de_los_meses_dormidos(): void
    {
        $u = $this->completo();
        $this->travelTo(CarbonImmutable::parse('2026-09-16 09:00:00'));

        // Una regla que quedó a medio llenar hace medio año.
        $regla = $this->regla($u, [
            'incompleta' => true,
            'vigente_desde' => '2026-03-16',
        ]);

        $activa = app(AdministrarReglas::class)->completarYActivar($u, $regla, ['dia_mes' => 14]);

        // La vigencia NO se toca: es un dato que alguien eligió, no un efecto colateral
        // de cuándo se terminó de llenar el formulario.
        $this->assertSame('2026-03-16', $activa->vigente_desde->toDateString());

        // Y activar, por sí solo, no crea ni una obligación.
        $this->assertSame(0, Ocurrencia::where('regla_id', $activa->id)->count());

        $resultado = app(GenerarObligaciones::class)
            ->paraRegla($activa, CarbonImmutable::parse('2026-09-16'), $u);

        // Lo reciente se genera; los meses dormidos NO. Marzo a julio quedan informados
        // y sin crear: seis meses de sueño no son seis meses de deuda.
        $this->assertSame(['2026-08', '2026-09'], $resultado['periodos']);
        $this->assertSame(
            ['2026-04', '2026-05', '2026-06', '2026-07'],
            $resultado['fuera_de_ventana'],
        );

        $this->assertSame(
            ['2026-08', '2026-09'],
            Ocurrencia::where('regla_id', $activa->id)->orderBy('periodo')->pluck('periodo')->all(),
            'Los períodos fuera de la ventana no pueden haberse creado igual.',
        );
    }

    public function test_una_regla_ya_activa_no_se_puede_completar_otra_vez(): void
    {
        $u = $this->completo();
        $regla = $this->regla($u, ['dia_mes' => 28]);

        $this->assertSame('activa', $regla->estado);

        $this->expectException(ValidationException::class);
        app(AdministrarReglas::class)->completarYActivar($u, $regla, ['dia_mes' => 14]);
    }

    public function test_las_reglas_completas_siguen_naciendo_activas(): void
    {
        $u = $this->completo();

        // Sin `incompleta`, todo sigue igual que antes de este cambio.
        $regla = $this->regla($u, ['dia_mes' => 28]);

        $this->assertSame('activa', $regla->estado);
        $this->assertSame(28, $regla->dia_mes);
    }
}
