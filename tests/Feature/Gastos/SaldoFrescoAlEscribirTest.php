<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\User;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use App\Services\Gastos\VincularCompra;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConsultasConCandado;
use Tests\TestCase;

/**
 * DOS MOVIMIENTOS A LA VEZ SOBRE LA MISMA CUOTA NO SE PASAN DEL SALDO.
 *
 * En MySQL (REPEATABLE READ) la primera lectura común de una transacción fija la foto que
 * ven las demás. El segundo pago tomaba esa foto antes de esperar el candado del gasto, y
 * al entrar calculaba el pendiente con ella: no veía el pago que el primero acababa de
 * confirmar, y la cuota quedaba pagada dos veces.
 *
 * El arreglo: quien va a ESCRIBIR lee el saldo con candado, que siempre devuelve lo último
 * confirmado. SQLite no tiene esa foto ni dos procesos, así que la regla se prueba por pasos:
 *   1. las lecturas de saldo de cada camino que escribe llevan candado (lo que falla en
 *      MySQL sin él);
 *   2. si el otro movimiento entra mientras este esperaba, este se rechaza.
 * La carrera real se comprueba con dos procesos contra MySQL. Datos ficticios.
 */
class SaldoFrescoAlEscribirTest extends TestCase
{
    use ConsultasConCandado;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['gastos.enabled' => true, 'planilla.enabled' => true, 'planilla.pagos_enabled' => true]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function gasto(string $importe = '100.00'): Gasto
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor de prueba', 'concepto' => 'Servicio de prueba',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => $importe, 'documentacion' => 'pendiente',
            'responsable_id' => $this->actor->id, 'registrado_por' => $this->actor->id,
        ]);
        $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => '2026-09-10']);

        return $gasto->fresh();
    }

    private function pagar(Cuota $cuota, string $importe): Pago
    {
        return app(RegistrarPago::class)->registrar($this->actor, [
            'clave' => (string) Str::uuid(), 'importe' => $importe, 'fecha' => '2026-09-05',
            'metodo' => 'efectivo', 'pagado_por' => $this->actor->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cuota->id, 'importe' => $importe]]);
    }

    /** @param  array<string, mixed>  $extra */
    private function ajustar(Cuota $cuota, string $direccion, string $importe, array $extra = []): Ajuste
    {
        return app(RegistrarAjuste::class)->registrar($this->actor, $cuota, $extra + [
            'clave' => (string) Str::uuid(), 'direccion' => $direccion, 'tipo' => 'correccion',
            'importe' => $importe, 'motivo' => 'Corrección de prueba.',
        ]);
    }

    /** Lo que deja el OTRO proceso al confirmar su pago, escrito tal cual queda en la base. */
    private function pagoDeOtroProceso(Cuota $cuota, string $importe): void
    {
        $pagoId = DB::table('gastos_pagos')->insertGetId([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('1', 64),
            'beneficiario' => 'Proveedor de prueba', 'moneda' => 'USD', 'importe' => $importe,
            'fecha' => '2026-09-05', 'metodo' => 'efectivo',
            'pagado_por' => $this->actor->id, 'registrado_por' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('gastos_pago_aplicaciones')->insert(['pago_id' => $pagoId, 'cuota_id' => $cuota->id, 'importe' => $importe]);
    }

    /**
     * Corre `$deOtroProceso` una sola vez, justo cuando este proceso toma con candado una
     * fila de `$tabla`: es el momento en que, en MySQL, se quedaría esperando al otro.
     */
    private function mientrasEsperaElCandadoDe(string $tabla, \Closure $deOtroProceso): void
    {
        $hecho = false;
        DB::listen(function ($q) use (&$hecho, $tabla, $deOtroProceso) {
            $sql = mb_strtolower($q->sql);
            if ($hecho || ! preg_match('/[`"]'.$tabla.'[`"]/', $sql) || ! str_contains($sql, 'for update')) {
                return;
            }
            $hecho = true;
            $deOtroProceso();
        });
    }

    // ═══════════════════════ Las pruebas valen en SQLite y en MySQL ═══════════════════════

    public function test_las_consultas_se_reconocen_con_las_comillas_de_sqlite_y_con_las_de_mysql(): void
    {
        // MySQL cita los nombres con acentos graves; SQLite, con comillas dobles. La lectura
        // de «¿ya se registró este mismo ajuste?» no decide ningún saldo y no lleva candado:
        // si no se la reconoce en MySQL, la prueba le exigiría uno que no tiene que tener.
        foreach (['select * from `gastos_ajustes` where `clave` = ? limit 1', 'select * from "gastos_ajustes" where "clave" = ? limit 1'] as $consulta) {
            $this->assertTrue($this->nombra($consulta, 'clave'), $consulta);
            $this->assertFalse($this->nombra($consulta, 'gastos'), $consulta);
            $this->assertCount(1, $this->lecturasDe(new \ArrayObject([$consulta]), 'gastos_ajustes'), $consulta);
        }

        $this->assertTrue($this->llevaCandado('select * from `gastos` where `id` = ? limit 1 for update'));
        $this->assertTrue($this->llevaCandado('select `importe` from `gastos_ajustes` where `cuota_id` = ? lock in share mode'));
        $this->assertFalse($this->llevaCandado('select * from `gastos_ajustes` where `clave` = ? limit 1'));
    }

    // ═══════════════════════ Registrar un pago ═══════════════════════

    public function test_registrar_un_pago_lee_el_saldo_con_candado(): void
    {
        $cuota = $this->gasto()->cuotas->first();
        $sql = $this->consultasConCandado();

        $this->pagar($cuota, '40.00');

        $this->assertTodasLeidasConCandado($sql, 'gastos_pago_aplicaciones');
        $this->assertTodasLeidasConCandado($sql, 'gastos_ajustes');
    }

    public function test_si_otro_pago_entra_mientras_este_esperaba_no_se_pasa_del_saldo(): void
    {
        $cuota = $this->gasto()->cuotas->first();
        $this->consultasConCandado();
        $this->mientrasEsperaElCandadoDe('gastos', fn () => $this->pagoDeOtroProceso($cuota, '100.00'));

        try {
            $this->pagar($cuota, '100.00');
            $this->fail('El segundo pago no debió entrar: la cuota ya estaba saldada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('supera el pendiente', $e->getMessage());
        }

        // En la suite las dos partes comparten una conexión, así que el rechazo deshace
        // también lo que «el otro proceso» escribió adentro: lo que importa es que el
        // segundo pago no quedó.
        $this->assertSame(0, Pago::count());
        $this->assertSame(10000, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
    }

    // ═══════════════════════ Registrar y revertir un ajuste ═══════════════════════

    public function test_registrar_un_ajuste_lee_el_saldo_con_candado(): void
    {
        $cuota = $this->gasto()->cuotas->first();
        $sql = $this->consultasConCandado();

        $this->ajustar($cuota, 'credito', '30.00');

        $this->assertTodasLeidasConCandado($sql, 'gastos_pago_aplicaciones');
        // La primera lectura de ajustes es la de «¿ya se registró este mismo?», por su clave.
        $deSaldo = array_filter($this->lecturasDe($sql, 'gastos_ajustes'), fn (string $q) => ! $this->nombra($q, 'clave'));
        $this->assertNotEmpty($deSaldo);
        foreach ($deSaldo as $lectura) {
            $this->assertTrue($this->llevaCandado($lectura), $lectura);
        }
    }

    public function test_si_un_pago_entra_mientras_el_credito_esperaba_el_credito_no_deja_la_cuota_en_negativo(): void
    {
        $cuota = $this->gasto()->cuotas->first();
        $this->consultasConCandado();
        $this->mientrasEsperaElCandadoDe('gastos', fn () => $this->pagoDeOtroProceso($cuota, '100.00'));

        try {
            $this->ajustar($cuota, 'credito', '100.00');
            $this->fail('El crédito no debió entrar: la cuota ya estaba pagada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya no tiene saldo', $e->getMessage());
        }

        $this->assertSame(0, Ajuste::count());
    }

    public function test_aplicar_una_nota_de_credito_de_compras_lee_lo_ya_aplicado_con_candado(): void
    {
        // La misma nota puede aplicarse a dos deudas distintas a la vez: cada una tiene su
        // gasto bloqueado, y lo que las pone en fila es el documento.
        $nota = DocumentoRecibido::create([
            'identidad' => (string) Str::uuid(), 'origen_email' => 'proveedor@ejemplo.test',
            'asunto' => 'NC', 'remitente' => 'Proveedor de prueba', 'fecha_correo' => now(),
            'fecha_dte' => '2026-09-01', 'tipo_documento' => '05', 'numero_control' => 'DTE-05-0009',
            'codigo_generacion' => strtoupper((string) Str::uuid()), 'emisor_nombre' => 'Proveedor de prueba',
            'total' => 60.00, 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
        ]);
        $cuota = $this->gasto()->cuotas->first();
        $sql = $this->consultasConCandado();

        $this->ajustar($cuota, 'credito', '60.00', ['tipo' => 'nota_credito', 'documento_recibido_id' => $nota->id]);

        $delDocumento = array_filter($this->lecturasDe($sql, 'gastos_ajustes'), fn (string $q) => $this->nombra($q, 'documento_recibido_id'));
        $this->assertNotEmpty($delDocumento);
        foreach ($delDocumento as $lectura) {
            $this->assertTrue($this->llevaCandado($lectura), $lectura);
        }
        $this->assertSame(0, app(VincularCompra::class)->creditoDelDocumento($nota)['disponible']);
    }

    public function test_revertir_un_debito_lee_el_saldo_con_candado(): void
    {
        $cuota = $this->gasto()->cuotas->first();
        $debito = $this->ajustar($cuota, 'debito', '20.00');
        $sql = $this->consultasConCandado();

        app(RegistrarAjuste::class)->revertir($this->actor, $debito, 'Se cargó de más.');

        $this->assertTodasLeidasConCandado($sql, 'gastos_pago_aplicaciones');
        $this->assertNotNull($debito->fresh()->revertido_at);
    }

    // ═══════════════════════ Anular una planilla ═══════════════════════

    private function planillaConfirmada(): Planilla
    {
        app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '450.00',
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
        $abierta = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-10-01', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-10-15',
        ]);

        return app(ConfirmarPlanilla::class)->confirmar($this->actor, $abierta->fresh());
    }

    public function test_si_entra_un_pago_mientras_se_anula_la_planilla_no_se_anula(): void
    {
        $planilla = $this->planillaConfirmada();
        $cuota = PlanillaDetalle::where('planilla_id', $planilla->id)->sole()->gasto->cuotas->first();
        $this->consultasConCandado();
        // Ya pasó la revisión de «no hay pagos» y va a tomar el candado de la cuota.
        $this->mientrasEsperaElCandadoDe('gastos_cuotas', fn () => $this->pagoDeOtroProceso($cuota, '225.00'));

        try {
            app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con un error.');
            $this->fail('Con un pago recién registrado, la planilla no se anula.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No se puede anular', $e->getMessage());
        }

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame(0, Ajuste::count(), 'No queda ningún ajuste a medias.');
    }

    public function test_anular_una_planilla_lee_pagos_y_saldo_con_candado_y_toma_el_gasto_antes_que_la_cuota(): void
    {
        $planilla = $this->planillaConfirmada();
        $sql = $this->consultasConCandado();

        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con un error.');

        $this->assertSame('anulada', $planilla->fresh()->estado);
        $conCandado = array_values(array_filter($sql->getArrayCopy(), fn (string $q) => str_starts_with($q, 'select') && str_contains($q, 'for update')));
        $gasto = array_key_first(array_filter($conCandado, fn (string $q) => preg_match('/from [`"]gastos[`"]/', $q) === 1));
        $cuota = array_key_first(array_filter($conCandado, fn (string $q) => preg_match('/from [`"]gastos_cuotas[`"]/', $q) === 1));
        // El mismo orden que en los pagos y los ajustes, para que no se crucen.
        $this->assertNotNull($gasto, 'Anular no tomó el candado del gasto.');
        $this->assertNotNull($cuota);
        $this->assertLessThan($cuota, $gasto);

        $despuesDelCandado = new \ArrayObject(array_slice($sql->getArrayCopy(), array_search($conCandado[$cuota], $sql->getArrayCopy(), true)));
        $this->assertTodasLeidasConCandado($despuesDelCandado, 'gastos_pago_aplicaciones');
    }
}
