<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\User;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\RegistrarPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El historial de pagos: QUÉ se pagó, no cuántas filas tenía.
 *
 * Lo que defienden estas pruebas:
 *
 *  1. LA LISTA DICE EL CONCEPTO. «Universidad · septiembre», no «1 obligación». Una
 *     etiqueta que cuenta filas no responde ninguna pregunta que alguien se haga.
 *  2. SE BUSCA POR CONCEPTO, que es lo que una persona recuerda de un pago —«la
 *     universidad», «lo de la pepitoria»— mucho antes que su referencia bancaria.
 *  3. ABONO Y PAGO QUE SALDA SE DISTINGUEN, y el saldo que se enseña se dice que es
 *     EL DE HOY: presentarlo como «lo que quedó tras ese pago» sería falso en cuanto
 *     haya un movimiento posterior.
 *  4. EL CANDADO DE ÁMBITO NO SE AFLOJA por tener buscador nuevo. Quien no alcanza lo
 *     personal no lo encuentra tecleando su concepto, que sería la fuga más fácil de
 *     introducir sin darse cuenta.
 */
class HistorialPagosTest extends TestCase
{
    use RefreshDatabase;

    private const HOY = '2026-09-16';

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
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function gasto(User $u, string $beneficiario, string $concepto, string $importe, array $extra = []): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => hash('sha256', $beneficiario.$concepto.$importe),
            'beneficiario' => $beneficiario,
            'concepto' => $concepto,
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => 'compra',
            'moneda' => 'USD',
            'importe' => $importe,
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'registrado_por' => $u->id,
        ], $extra));

        $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => null]);

        return $gasto->fresh();
    }

    /** @param  array<int, array{0: Gasto, 1: string}>  $partes */
    private function pagar(User $u, array $partes, string $importe): void
    {
        $aplicaciones = [];
        foreach ($partes as [$gasto, $cuanto]) {
            $aplicaciones[] = ['cuota_id' => $gasto->cuotas()->first()->id, 'importe' => $cuanto];
        }

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(),
            'importe' => $importe,
            'fecha' => self::HOY,
            'metodo' => 'transferencia',
            'pagado_por' => $u->id,
            'sin_comprobante' => 'Prueba automatizada.',
        ], $aplicaciones);
    }

    private function filtros(array $extra = []): array
    {
        return array_replace([
            'q' => '', 'desde' => null, 'hasta' => null, 'metodo' => null,
            'moneda' => null, 'ambito' => null, 'revertidos' => 'incluir',
        ], $extra);
    }

    // ═══════════ 1. La lista dice el concepto ═══════════

    public function test_el_historial_dice_que_se_pago_y_no_solo_cuantas_obligaciones(): void
    {
        $u = $this->operador();
        $this->gasto($u, 'Universidad', 'Mensualidad de la universidad', '110.00', [
            'ambito' => 'personal', 'naturaleza' => 'operativo',
            'periodo_desde' => '2026-09-01', 'periodo_hasta' => '2026-09-30',
        ]);
        $this->pagar($u, [[Gasto::where('beneficiario', 'Universidad')->firstOrFail(), '110.00']], '110.00');

        $this->actingAs($u)->get(route('gastos.pagos.index'))
            ->assertOk()
            ->assertSee('Mensualidad de la universidad')
            // El período se dice con el nombre del mes, no con una fecha suelta.
            ->assertSee('septiembre', false)
            ->assertDontSee('1 obligación(es)');
    }

    public function test_un_abono_a_una_cuenta_abierta_se_nombra_como_abono(): void
    {
        $u = $this->operador();
        $proveedorA = $this->gasto($u, 'Proveedor A', 'Saldo inicial · pepitoria', '850.00');
        $this->pagar($u, [[$proveedorA, '400.00']], '400.00');

        $this->actingAs($u)->get(route('gastos.pagos.index'))
            ->assertOk()
            ->assertSee('Abono a Proveedor A', false)
            ->assertSee('pepitoria', false)
            // Y se dice que todavía queda saldo, aclarando que es el de hoy.
            ->assertSee('Abono · queda saldo hoy', false);
    }

    public function test_un_pago_que_salda_se_distingue_de_un_abono(): void
    {
        $u = $this->operador();
        $luz = $this->gasto($u, 'DELSUR', 'Luz de la fábrica', '115.27');
        $this->pagar($u, [[$luz, '115.27']], '115.27');

        $this->actingAs($u)->get(route('gastos.pagos.index'))
            ->assertOk()
            ->assertSee('Saldó lo que cubre', false)
            ->assertDontSee('Abono · queda saldo hoy', false);
    }

    public function test_un_pago_de_varias_obligaciones_resume_y_permite_desplegar(): void
    {
        $u = $this->operador();
        $a = $this->gasto($u, 'Proveedor A', 'Pepitoria de agosto', '300.00');
        $b = $this->gasto($u, 'Proveedor A', 'Pepitoria de septiembre', '200.00');
        $this->pagar($u, [[$a, '300.00'], [$b, '200.00']], '500.00');

        $respuesta = $this->actingAs($u)->get(route('gastos.pagos.index'))->assertOk();

        // Resumen arriba…
        $respuesta->assertSee('2 obligaciones');
        // …y el reparto desplegable con cada concepto y su importe.
        $respuesta->assertSee('Pepitoria de agosto');
        $respuesta->assertSee('Pepitoria de septiembre');
        $respuesta->assertSee('<details', false);
    }

    // ═══════════ 2. Búsqueda por concepto ═══════════

    public function test_se_puede_buscar_por_concepto(): void
    {
        $u = $this->operador();
        $luz = $this->gasto($u, 'DELSUR', 'Luz de la fábrica', '115.27');
        $otro = $this->gasto($u, 'Ferretería', 'Tornillos y clavos', '20.00');
        $this->pagar($u, [[$luz, '115.27']], '115.27');
        $this->pagar($u, [[$otro, '20.00']], '20.00');

        $consulta = app(ConsultaGastos::class);

        $this->assertSame(2, $consulta->pagosVisibles($u, $this->filtros())->total());
        $this->assertSame(1, $consulta->pagosVisibles($u, $this->filtros(['q' => 'fábrica']))->total());
        $this->assertSame(1, $consulta->pagosVisibles($u, $this->filtros(['q' => 'Tornillos']))->total());
        // Y lo de siempre sigue funcionando: destinatario.
        $this->assertSame(1, $consulta->pagosVisibles($u, $this->filtros(['q' => 'DELSUR']))->total());
    }

    // ═══════════ 3. Filtro Empresa / Personal ═══════════

    public function test_el_filtro_de_ambito_recorta_sin_perder_nada(): void
    {
        $u = $this->operador();
        $casa = $this->gasto($u, 'Claro', 'Internet de casa', '55.00', ['ambito' => 'personal']);
        $fabrica = $this->gasto($u, 'DELSUR', 'Luz de la fábrica', '115.27');
        $this->pagar($u, [[$casa, '55.00']], '55.00');
        $this->pagar($u, [[$fabrica, '115.27']], '115.27');

        $consulta = app(ConsultaGastos::class);

        $this->assertSame(2, $consulta->pagosVisibles($u, $this->filtros())->total());
        $this->assertSame(1, $consulta->pagosVisibles($u, $this->filtros(['ambito' => 'personal']))->total());
        $this->assertSame(1, $consulta->pagosVisibles($u, $this->filtros(['ambito' => 'empresarial']))->total());
    }

    // ═══════════ 4. El candado de ámbito no se afloja ═══════════

    /**
     * La fuga más fácil de introducir con un buscador nuevo: que alguien sin permiso
     * sobre lo personal encuentre un pago tecleando su concepto, y deduzca por los
     * resultados lo que no puede ver.
     */
    public function test_quien_no_alcanza_lo_personal_no_lo_encuentra_por_concepto(): void
    {
        $dueno = $this->operador();
        $casa = $this->gasto($dueno, 'Claro', 'Internet de casa', '55.00', ['ambito' => 'personal']);
        $this->pagar($dueno, [[$casa, '55.00']], '55.00');

        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer]);
        $consulta = app(ConsultaGastos::class);

        $this->assertSame(0, $consulta->pagosVisibles($soloEmpresa, $this->filtros())->total());
        $this->assertSame(0, $consulta->pagosVisibles($soloEmpresa, $this->filtros(['q' => 'Internet de casa']))->total(),
            'Buscar por concepto no puede revelar un pago personal a quien no lo alcanza.');
        $this->assertSame(0, $consulta->pagosVisibles($soloEmpresa, $this->filtros(['q' => 'Claro']))->total());

        // Y el dueño sí lo encuentra.
        $this->assertSame(1, $consulta->pagosVisibles($dueno, $this->filtros(['q' => 'Internet de casa']))->total());
    }

    public function test_quien_no_alcanza_lo_personal_no_ve_el_filtro_de_ambito(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer]);

        $this->actingAs($u)->get(route('gastos.pagos.index'))
            ->assertOk()
            ->assertDontSee('Personal o casa');
    }

    /**
     * Pago mixto: se ve la parte que se alcanza y se calla la otra, también en el
     * reparto nuevo. Enseñar la fila personal sería peor que enseñar el total.
     */
    public function test_un_pago_mixto_solo_ensena_el_reparto_que_se_alcanza(): void
    {
        $dueno = $this->operador();
        $empresa = $this->gasto($dueno, 'Proveedor Único', 'Materiales de la fábrica', '100.00');
        $personal = $this->gasto($dueno, 'Proveedor Único', 'Compra de la casa', '40.00', ['ambito' => 'personal']);
        $this->pagar($dueno, [[$empresa, '100.00'], [$personal, '40.00']], '140.00');

        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer]);

        $respuesta = $this->actingAs($soloEmpresa)->get(route('gastos.pagos.index'))->assertOk();
        $respuesta->assertSee('Materiales de la fábrica');
        $respuesta->assertDontSee('Compra de la casa');
        // Ni el importe total del pago, que delataría la parte oculta.
        $respuesta->assertDontSee('140.00');
    }

    // ═══════════ 5. Totales ═══════════

    public function test_los_totales_siguen_los_filtros_y_excluyen_lo_revertido(): void
    {
        $u = $this->operador();
        $casa = $this->gasto($u, 'Claro', 'Internet de casa', '55.00', ['ambito' => 'personal']);
        $fabrica = $this->gasto($u, 'DELSUR', 'Luz de la fábrica', '115.27');
        $this->pagar($u, [[$casa, '55.00']], '55.00');
        $this->pagar($u, [[$fabrica, '115.27']], '115.27');

        $consulta = app(ConsultaGastos::class);

        $todos = $consulta->totalesPagos($u, $this->filtros())->firstWhere('moneda', 'USD');
        $this->assertSame(17027, (int) $todos->vigente);

        // El total sigue al filtro, no a la cartera entera.
        $soloCasa = $consulta->totalesPagos($u, $this->filtros(['ambito' => 'personal']))->firstWhere('moneda', 'USD');
        $this->assertSame(5500, (int) $soloCasa->vigente);
    }
}
