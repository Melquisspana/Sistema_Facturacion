<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lo que una revisión encontró vigente en Planilla con plata de por medio (issue #17).
 *
 *  a. Un error de integridad cualquiera no es «otro proceso ya confirmó».
 *  b. El mismo tercero escrito con otras mayúsculas, tildes o espacios es UNA deuda, y
 *     no se pierde la segunda parte.
 *  c. El anticipo que se descuenta es de esa persona, en esa moneda y de un pago vigente.
 *  d. Guardar el borrador no borra las líneas de una planilla que se confirmó en medio.
 *  e. Con una instalación a medias el módulo no se abre.
 */
class PlataConfirmarPlanillaTest extends TestCase
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
        config()->set('gastos.enabled', true);
        config()->set('asistencia.enabled', false);
    }

    private function gestor(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::PlanillaDocumentos,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function empleado(User $u, string $nombre): PlanillaEmpleado
    {
        return app(RegistrarEmpleado::class)->registrar($u, ['nombre' => $nombre, 'cargo' => 'Ventas']);
    }

    private function planilla(User $u): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $conceptos */
    private function linea(PlanillaEmpleado $e, array $conceptos = [], string $salario = '200.00'): array
    {
        return ['planilla_empleado_id' => $e->id, 'salario' => $salario, 'conceptos' => $conceptos];
    }

    private function aTercero(string $tercero, string $importe): array
    {
        return ['tipo' => 'descuento', 'concepto' => 'Cuota de préstamo', 'importe' => $importe,
            'destino' => 'tercero', 'tercero' => $tercero];
    }

    private function deAnticipo(PlanillaAnticipo $a, string $importe = '30.00'): array
    {
        return ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => $importe,
            'destino' => 'anticipo', 'planilla_anticipo_id' => $a->id];
    }

    private function anticipo(User $u, PlanillaEmpleado $e, string $moneda = 'USD', ?int $pagoId = null): PlanillaAnticipo
    {
        return app(RegistrarAnticipo::class)->registrar($u, $e, [
            'fecha' => '2026-09-02', 'importe' => '60.00', 'moneda' => $moneda,
            'pago_id' => $pagoId, 'referencia' => 'recibo 901',
        ]);
    }

    private function pagoReal(User $u, string $beneficiario): Pago
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('c', 64),
            'beneficiario' => $beneficiario, 'concepto' => 'Anticipo de quincena', 'categoria' => 'Planilla',
            'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '60.00',
            'documentacion' => 'pendiente', 'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => '60.00', 'vence' => '2026-09-02']);

        return app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => '60.00', 'fecha' => '2026-09-02',
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Entregado en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => '60.00']]);
    }

    // ═══════════ a. Un error de integridad cualquiera no es «ya confirmada» ═══════════

    public function test_un_error_de_integridad_ajeno_falla_y_no_deja_nada_a_medias(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->aTercero('Cooperativa Ficticia', '25.00')]),
        ]);

        // Un índice único que salta por algo que NO es otra confirmación: alguien dejó
        // antes la fila del tercero por fuera del servicio, a mitad de esta transacción.
        PlanillaObligacionTercero::creating(function (PlanillaObligacionTercero $o) {
            DB::table('planilla_obligaciones_terceros')->insert([
                'planilla_id' => $o->planilla_id, 'tercero' => $o->tercero,
                'importe' => '1.00', 'created_at' => now(),
            ]);
        });

        try {
            app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
            $this->fail('Un error de integridad que no es una confirmación ajena tenía que fallar.');
        } catch (QueryException) {
            // Lo esperado.
        }

        // Y se deshizo todo: ni confirmada, ni una deuda creada.
        $this->assertTrue($planilla->fresh()->borrador());
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, PlanillaObligacionTercero::count());
    }

    // ═══════════ b. El mismo tercero escrito de otra forma ═══════════

    public function test_el_mismo_tercero_con_otras_mayusculas_tildes_o_espacios_es_una_sola_deuda_completa(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $carlos = $this->empleado($u, 'Carlos Prueba');
        $luis = $this->empleado($u, 'Luis Prueba');

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->aTercero('Asociación Cooperativa Ficticia', '25.00')]),
            $this->linea($carlos, [$this->aTercero('asociacion  cooperativa ficticia ', '20.00')]),
            $this->linea($luis, [$this->aTercero('ASOCIACIÓN COOPERATIVA FICTICIA', '5.50')]),
        ]);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertTrue($planilla->fresh()->confirmada());

        // Una sola obligación, por la suma de las tres: ninguna parte se perdió.
        $this->assertSame(1, PlanillaObligacionTercero::count());
        $obligacion = PlanillaObligacionTercero::firstOrFail();
        $this->assertSame('50.50', $obligacion->importe);
        $this->assertSame('50.50', Gasto::findOrFail($obligacion->gasto_id)->importe);
        $this->assertSame('Asociación Cooperativa Ficticia', $obligacion->tercero);
    }
}
