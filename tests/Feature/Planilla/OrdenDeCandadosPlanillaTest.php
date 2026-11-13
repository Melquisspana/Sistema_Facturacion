<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\PersonasCambiaron;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConsultasConCandado;
use Tests\TestCase;

/**
 * UN SOLO ORDEN DE CANDADOS EN TODO LO QUE ESCRIBE UNA PLANILLA: primero las fichas de
 * todas sus personas, en una sentencia y por id, y después la planilla. Guardar líneas
 * escribe detalles que apuntan a las fichas, y confirmar las bloquea; si un camino tomaba
 * la planilla primero y otro las fichas primero, dos pedidos podían esperarse entre sí.
 *
 * Lo que se comprueba acá es el orden de las sentencias. La carrera completa solo se ve en
 * MySQL con dos conexiones. Datos ficticios.
 */
class OrdenDeCandadosPlanillaTest extends TestCase
{
    use ConsultasConCandado;
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

    private PlanillaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());

        $this->ana = $this->persona('Ana', 'FICTICIO-1');
        $this->beto = $this->persona('Beto', 'FICTICIO-2');
    }

    private function persona(string $nombre, string $dui): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticio', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '450.00',
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
    }

    /** @return array<string, string> */
    private function quincena(): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => '2026-10-01', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function borrador(): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->quincena());
    }

    /**
     * Las fichas se toman antes que la planilla, por id y en una sentencia, y después de
     * tomar la planilla ninguna ficha nueva: a lo sumo se repiten las que ya se tenían.
     *
     * @param  \ArrayObject<int, array{sql: string, valores: array<int, mixed>}>  $consultas
     * @param  array<int, int>  $esperadas
     */
    private function assertFichasAntesQueLaPlanilla(\ArrayObject $consultas, array $esperadas): void
    {
        $antes = [];
        $planillaTomada = false;

        foreach ($consultas as $consulta) {
            $sql = $consulta['sql'];
            $esFicha = str_starts_with($sql, 'select') && $this->nombra($sql, 'planilla_empleados') && $this->llevaCandado($sql);

            if ((str_starts_with($sql, 'insert') && preg_match('/into [`"]planillas[`"]/', $sql) === 1)
                || (str_starts_with($sql, 'select') && preg_match('/from [`"]planillas[`"]/', $sql) === 1 && $this->llevaCandado($sql))) {
                $planillaTomada = true;

                continue;
            }

            if (! $esFicha) {
                continue;
            }

            // `whereKey` con ids enteros los escribe en la sentencia, sin parámetros.
            preg_match('/[`"]id[`"] in \(([^)]*)\)/', $sql, $lista);
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $lista[1] ?? '')), fn ($v) => $v !== '' && $v !== '?'));
            $ids = array_merge($ids, array_map('intval', $consulta['valores']));

            if (! $planillaTomada) {
                $this->assertMatchesRegularExpression('/where [`"]planilla_empleados[`"]\.[`"]id[`"] in \(.*order by [`"]id[`"] asc/', $sql);
                $antes = array_merge($antes, $ids);
            } else {
                $this->assertSame([], array_values(array_diff($ids, $antes)), 'Se bloqueó una ficha nueva después de la planilla: '.$sql);
            }
        }

        $this->assertTrue($planillaTomada, 'No se tomó la planilla.');
        sort($esperadas);
        $this->assertSame($esperadas, array_values(array_unique($antes)));
    }

    public function test_generar_toma_las_fichas_antes_que_la_planilla(): void
    {
        $consultas = $this->consultasEnTransaccion();

        app(GenerarPlanilla::class)->generar($this->actor, $this->quincena());

        $this->assertFichasAntesQueLaPlanilla($consultas, [$this->ana->id, $this->beto->id]);
        $this->assertSame('confirmada', Planilla::sole()->estado);
    }

    public function test_guardar_lineas_toma_las_fichas_de_quienes_quedan_y_de_quienes_salen_antes_que_la_planilla(): void
    {
        $planilla = $this->borrador();
        $consultas = $this->consultasEnTransaccion();

        // Beto sale: su ficha también se toma, porque su línea se borra.
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
        ]);

        $this->assertFichasAntesQueLaPlanilla($consultas, [$this->ana->id, $this->beto->id]);
    }

    public function test_confirmar_toma_las_fichas_antes_que_la_planilla(): void
    {
        $planilla = $this->borrador();
        $consultas = $this->consultasEnTransaccion();

        app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());

        $this->assertFichasAntesQueLaPlanilla($consultas, [$this->ana->id, $this->beto->id]);
    }

    public function test_anular_toma_quien_anula_despues_las_fichas_y_despues_la_planilla(): void
    {
        $planilla = app(GenerarPlanilla::class)->generar($this->actor, $this->quincena());
        $consultas = $this->consultasEnTransaccion();

        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con un error.');

        $this->assertTrue($this->nombra($consultas[0]['sql'], 'users') && $this->llevaCandado($consultas[0]['sql']));
        $this->assertFichasAntesQueLaPlanilla($consultas, [$this->ana->id, $this->beto->id]);
    }

    public function test_si_aparece_alguien_mientras_se_confirma_no_se_bloquea_su_ficha_suelta(): void
    {
        $planilla = $this->borrador();
        $lineaDeBeto = (array) DB::table('planilla_detalles')->where('planilla_id', $planilla->id)->where('planilla_empleado_id', $this->beto->id)->first();
        PlanillaDetalle::whereKey($lineaDeBeto['id'])->delete();
        $consultas = $this->consultasEnTransaccion();

        // Otro pedido agrega a Beto justo después de que este tomó la ficha de Ana.
        $agregado = false;
        DB::listen(function ($q) use (&$agregado, $lineaDeBeto) {
            if (! $agregado && $this->nombra(mb_strtolower($q->sql), 'planilla_empleados') && $this->llevaCandado(mb_strtolower($q->sql))) {
                $agregado = true;
                DB::table('planilla_detalles')->insert($lineaDeBeto);
            }
        });

        try {
            app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());
            $this->fail('Tenía que empezar de nuevo.');
        } catch (PersonasCambiaron) {
            // Lo atrapa el reintento de afuera, que vuelve a tomar las fichas con Beto incluido.
        }

        $this->assertTrue($agregado);
        $this->assertFichasAntesQueLaPlanilla($consultas, [$this->ana->id]);
        $this->assertSame('borrador', $planilla->fresh()->estado);
    }

    /**
     * Si cambiaron las personas, el reintento vuelve a leer a quiénes bloquear: con el
     * conjunto viejo agotaría los tres intentos aunque nadie más tocara la planilla. Las
     * pruebas corren dentro de una transacción (y ahí no se reintenta), así que la
     * transacción de afuera se simula: solo se mira el bucle de reintentos.
     */
    public function test_el_reintento_vuelve_a_leer_las_personas(): void
    {
        DB::shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('transaction')->andReturnUsing(fn (\Closure $trabajo) => $trabajo());

        $lecturas = [[1], [1, 2]];
        $recibidos = [];

        $resultado = app(PrepararPlanilla::class)->conCandados(function (array $personas) use (&$recibidos) {
            $recibidos[] = $personas;
            if ($personas === [1]) {
                throw new PersonasCambiaron; // apareció la persona 2 con la planilla tomada
            }

            return 'listo';
        }, function () use (&$lecturas) {
            return array_shift($lecturas);
        });

        $this->assertSame('listo', $resultado);
        $this->assertSame([[1], [1, 2]], $recibidos);
    }
}
