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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
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
 *
 * Y lo que encontró la revisión de esos arreglos: lo que decide con un saldo dentro de
 * la transacción lo lee con candado, la pantalla no ofrece anticipos que el servidor
 * rechaza, y un carácter invisible en el nombre del tercero no parte la deuda.
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

    /**
     * Las consultas que corren de acá en adelante, con su candado escrito. SQLite no lo
     * escribe (todo va en serie), así que en SQLite se deja como comentario, que la base
     * ignora; MySQL ya lo escribe solo. Es la forma de comprobar en SQLite que una
     * lectura que decide va con candado: la carrera completa solo se ve en MySQL con dos
     * conexiones.
     *
     * @return \ArrayObject<int, string>
     */
    private function consultasConCandado(): \ArrayObject
    {
        $conexion = DB::connection();

        if ($conexion->getDriverName() === 'sqlite') {
            $conexion->setQueryGrammar(new class($conexion) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    if (is_string($value)) {
                        return $value;
                    }

                    return $value ? '/* for update */' : '/* lock in share mode */';
                }
            });
        }

        $sql = new \ArrayObject;
        DB::listen(fn ($q) => $sql->append(mb_strtolower($q->sql)));

        return $sql;
    }

    /** @param  \ArrayObject<int, string>  $sql */
    private function assertLeidaConCandado(\ArrayObject $sql, string $tabla, string $candado): void
    {
        $con = array_filter($sql->getArrayCopy(),
            fn (string $q) => str_starts_with($q, 'select') && preg_match('/[`"]'.$tabla.'[`"]/', $q) && str_contains($q, $candado));

        $this->assertNotEmpty($con, 'Ninguna lectura de '.$tabla.' lleva «'.$candado.'».');
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

    public function test_un_caracter_invisible_en_el_nombre_del_tercero_no_parte_la_deuda(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $carlos = $this->empleado($u, 'Carlos Prueba');

        // El segundo, pegado de un PDF, trae un espacio de ancho cero en medio. MySQL lo
        // ignora en el índice único: es el mismo acreedor.
        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->aTercero('CoopX', '10.00')]),
            $this->linea($carlos, [$this->aTercero("Coop\u{200B}X", '5.25')]),
        ]);

        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertTrue($planilla->fresh()->confirmada());
        $this->assertSame(1, PlanillaObligacionTercero::count());
        $obligacion = PlanillaObligacionTercero::firstOrFail();
        $this->assertSame('15.25', $obligacion->importe);
        $this->assertSame('15.25', Gasto::findOrFail($obligacion->gasto_id)->importe);
    }

    // ═══════════ c. El anticipo es de esa persona, en esa moneda y vigente ═══════════

    public function test_no_se_descuenta_el_anticipo_de_otra_persona(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $carlos = $this->empleado($u, 'Carlos Prueba');
        $deCarlos = $this->anticipo($u, $carlos);

        try {
            app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
                $this->linea($ana, [$this->deAnticipo($deCarlos)]),
            ]);
            $this->fail('El anticipo de otra persona no se le descuenta a Ana.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('es de otra persona', $e->getMessage());
        }

        $this->assertSame(0, DB::table('planilla_conceptos')->count());
    }

    public function test_no_se_descuenta_un_anticipo_en_otra_moneda(): void
    {
        config()->set('gastos.monedas', ['USD', 'EUR']);

        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $enEuros = $this->anticipo($u, $ana, 'EUR');

        try {
            app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
                $this->linea($ana, [$this->deAnticipo($enEuros)]),
            ]);
            $this->fail('Un anticipo en EUR no se recupera en una planilla en USD.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('EUR', $e->getMessage());
        }
    }

    public function test_no_se_descuenta_un_anticipo_cuyo_pago_esta_revertido(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana, 'USD', $pago->id);

        app(RegistrarPago::class)->revertir($u, $pago, 'La salida de caja se registró por error.');

        try {
            app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
                $this->linea($ana, [$this->deAnticipo($anticipo)]),
            ]);
            $this->fail('Un anticipo cuyo pago se revirtió no es dinero entregado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('revertido', $e->getMessage());
            // Con veinte personas hay que saber qué línea corregir.
            $this->assertStringContainsString('Ana Prueba: El anticipo del 02/09/2026', $e->getMessage());
        }
    }

    public function test_si_el_pago_se_revierte_despues_de_guardar_el_borrador_no_se_confirma(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana, 'USD', $pago->id);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->deAnticipo($anticipo)]),
        ]);

        // El borrador no aplica nada, así que el pago todavía se puede revertir.
        app(RegistrarPago::class)->revertir($u, $pago, 'La salida de caja se registró por error.');

        $reparos = app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()->load('detalles.conceptos'));
        $this->assertStringContainsString('revertido', implode(' ', $reparos));

        try {
            app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
            $this->fail('Confirmar tenía que frenarse: el anticipo ya no es dinero entregado.');
        } catch (ValidationException) {
            // Lo esperado.
        }

        $this->assertTrue($planilla->fresh()->borrador());
        $this->assertSame(0, DB::table('planilla_anticipo_aplicaciones')->count());
    }

    public function test_el_anticipo_propio_vigente_se_sigue_descontando(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana, 'USD', $pago->id);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->deAnticipo($anticipo, '30.00')]),
        ]);
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertTrue($planilla->fresh()->confirmada());
        $this->assertSame(3000, $anticipo->fresh()->pendiente());
    }

    public function test_al_confirmar_lo_recuperado_del_anticipo_y_su_pago_se_leen_con_candado(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana, 'USD', $pago->id);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->deAnticipo($anticipo, '30.00')]),
        ]);

        $sql = $this->consultasConCandado();
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        $this->assertTrue($planilla->fresh()->confirmada());
        // En MySQL, sin candado, otra planilla que descontó el mismo anticipo mientras
        // tanto no se vería y se recuperaría dos veces.
        $this->assertLeidaConCandado($sql, 'planilla_anticipo_aplicaciones', 'for update');
        // Y el pago, que alguien pudo estar revirtiendo a la vez.
        $this->assertLeidaConCandado($sql, 'gastos_pagos', 'for update');
    }

    public function test_si_el_pago_se_revierte_despues_de_los_reparos_la_confirmacion_lo_ve_y_no_descuenta(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana, 'USD', $pago->id);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->deAnticipo($anticipo, '30.00')]),
        ]);

        // Otro proceso revierte el pago justo cuando la confirmación ya pasó los reparos
        // y acaba de bloquear el anticipo.
        $this->consultasConCandado();
        $revertido = false;
        DB::listen(function ($q) use (&$revertido, $pago) {
            $sql = mb_strtolower($q->sql);
            if ($revertido || ! preg_match('/[`"]planilla_anticipos[`"]/', $sql) || ! str_contains($sql, 'for update')) {
                return;
            }
            $revertido = true;
            DB::table('gastos_pagos')->where('id', $pago->id)->update(['revertido_at' => now()]);
        });

        try {
            app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());
            $this->fail('Con el pago revertido en medio, el anticipo no se descuenta.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('revertido', $e->getMessage());
        }

        $this->assertTrue($revertido);
        $this->assertTrue($planilla->fresh()->borrador());
        $this->assertSame(0, DB::table('planilla_anticipo_aplicaciones')->count());
    }

    public function test_revertir_un_pago_cuenta_las_aplicaciones_de_su_anticipo_con_candado(): void
    {
        $u = $this->gestor();
        $ana = $this->empleado($u, 'Ana Prueba');
        $pago = $this->pagoReal($u, 'Ana Prueba');
        $this->anticipo($u, $ana, 'USD', $pago->id);

        $sql = $this->consultasConCandado();
        app(RegistrarPago::class)->revertir($u, $pago, 'La salida de caja se registró por error.');

        // En MySQL, sin candado, la aplicación de una planilla que se confirmaba a la vez
        // no se vería y el pago se revertiría con el anticipo ya descontado.
        $this->assertLeidaConCandado($sql, 'planilla_anticipo_aplicaciones', 'lock in share mode');
    }

    public function test_la_pantalla_no_ofrece_anticipos_que_el_servidor_rechazaria(): void
    {
        config()->set('gastos.monedas', ['USD', 'EUR']);

        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $carlos = $this->empleado($u, 'Carlos Prueba');

        $pago = $this->pagoReal($u, 'Ana Prueba');
        $this->anticipo($u, $ana, 'USD', $pago->id);
        app(RegistrarPago::class)->revertir($u, $pago, 'La salida de caja se registró por error.');
        $this->anticipo($u, $ana, 'EUR');
        $vigente = $this->anticipo($u, $carlos);

        $this->actingAs($u)->get(route('planilla.preparar', $planilla))
            ->assertOk()
            ->assertViewHas('anticipos', function (array $anticipos) use ($ana, $carlos, $vigente) {
                return ($anticipos[$ana->id] ?? []) === []
                    && array_column($anticipos[$carlos->id] ?? [], 'id') === [$vigente->id];
            });
    }

    // ═══════════ d. Guardar no pisa una planilla confirmada en medio ═══════════

    public function test_guardar_con_la_planilla_confirmada_en_medio_no_borra_sus_lineas(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);
        $ana = $this->empleado($u, 'Ana Prueba');
        $anticipo = $this->anticipo($u, $ana);

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, [
            $this->linea($ana, [$this->deAnticipo($anticipo, '30.00')]),
        ]);

        // Lo que tenía en la mano quien guarda: la planilla leída cuando era borrador.
        $leidaAntes = $planilla->fresh();
        $this->assertTrue($leidaAntes->borrador());

        // Mientras tanto, otra persona la confirma.
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla->fresh());

        try {
            app(PrepararPlanilla::class)->guardarLineas($u, $leidaAntes, [
                $this->linea($ana, [], '999.00'),
            ]);
            $this->fail('Guardar sobre una planilla ya confirmada tenía que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('confirmada', $e->getMessage());
        }

        // Las líneas confirmadas siguen, con su deuda y el anticipo aplicado.
        $detalle = $planilla->fresh()->detalles()->sole();
        $this->assertSame('200.00', $detalle->salario);
        $this->assertNotNull($detalle->gasto_id);
        $this->assertSame(1, DB::table('planilla_anticipo_aplicaciones')->count());
        $this->assertSame(3000, $anticipo->fresh()->pendiente());
    }

    // ═══════════ e. Instalación a medias ═══════════

    public function test_con_una_tabla_de_planilla_sin_migrar_el_modulo_no_se_abre(): void
    {
        $u = $this->gestor();

        Schema::dropIfExists('planilla_documentos');

        $respuesta = $this->actingAs($u)->get(route('planilla.index'));
        $respuesta->assertStatus(503);
        $respuesta->assertSee('planilla_documentos');
    }
}
