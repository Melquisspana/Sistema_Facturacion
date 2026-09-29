<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\RegistrarPago;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El flujo entero con DIEZ personas, de la pantalla al papel firmado.
 *
 * Las pruebas anteriores miran una pieza cada una. Esta recorre el camino completo, que
 * es donde aparecen los problemas que ninguna pieza tiene por su cuenta:
 *
 *   registrar una vez → abrir la quincena (se carga sola) → ajustar dos excepciones →
 *   confirmar → pagar → imprimir la hoja con diez firmas y los recibos
 *
 * Las excepciones no son decorado: una persona entró a mitad de quincena, otra cobró a
 * cuenta, otra cobró dos días después y dos llevan descuentos. Es la quincena de verdad,
 * no la fácil.
 */
class FlujoDiezPersonasTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: string, 2: string}> nombre, DUI, habitual */
    private const PERSONAL = [
        ['Ana Mendoza Ramírez', '0123 4567-8', '225.00'],
        ['Carlos Rivas Menjívar', '0234 5678-9', '200.00'],
        ['Rosa Alvarenga Mejía', '0567 8901-2', '180.00'],
        ['Luis Portillo Aguilar', '0456 7890-1', '180.00'],
        ['Marta Interiano Cruz', '0678 9012-3', '195.00'],
        ['José Ernesto Chicas', '0789 0123-4', '185.00'],
        ['Blanca Estela Pineda', '0890 1234-5', '175.00'],
        ['Óscar Amaya Rodríguez', '0901 2345-6', '190.00'],
        ['Nubia Guadalupe Flores', '1012 3456-7', '165.00'],
        ['Rafael Antonio Sosa', '1123 4567-8', '210.00'],
    ];

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
        $u = User::factory()->create(['activo' => true, 'name' => 'Sandra Melgar Ayala']);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaPagar, PermisoSistema::PlanillaDocumentos,
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPagosCorregir,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Se escribe UNA vez: nombre, DUI e importe habitual. */
    private function registrarPersonal(User $u): void
    {
        foreach (self::PERSONAL as [$nombre, $dui, $habitual]) {
            $empleado = app(RegistrarEmpleado::class)->registrar($u, [
                'nombre' => $nombre, 'dui' => $dui, 'cargo' => 'Producción', 'alta_el' => '2026-01-01',
            ]);

            app(SueldoHabitual::class)->registrar($u, $empleado, [
                'importe' => $habitual, 'vigente_desde' => '2026-01-01',
            ]);
        }
    }

    public function test_de_la_pantalla_a_la_hoja_firmada_con_diez_personas(): void
    {
        $u = $this->gestor();
        $this->registrarPersonal($u);

        // ═══ 1. Abrir la quincena: se carga sola ═══
        $planilla = app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-12',
        ])->fresh()->load('detalles.conceptos');

        $this->assertSame(10, $planilla->detalles->count(), 'Las diez tenían que cargarse solas.');

        // Cada quien con SU importe habitual, sin haber escrito nada. Se compara por
        // nombre y no por posición: el orden de carga es alfabético y comparar listas
        // ordenadas distinto solo produce fallos que no dicen nada.
        $esperados = [];
        foreach (self::PERSONAL as [$nombre, , $habitual]) {
            $esperados[$nombre] = $habitual;
        }
        $cargados = $planilla->detalles->pluck('salario', 'nombre_snapshot')->all();
        ksort($esperados);
        ksort($cargados);
        $this->assertSame($esperados, $cargados);

        // La pantalla abre y lleva a las diez. Los nombres viajan dentro del JSON que
        // Alpine pinta, y ahí van con el unicode escapado —«Ramírez»—, así que se
        // buscan en ESA forma y no en la del texto plano: `assertSee` no los vería.
        $pantalla = $this->actingAs($u)->get(route('planilla.preparar', $planilla))->assertOk();
        $html = $pantalla->getContent();

        foreach (self::PERSONAL as [$nombre, $dui]) {
            // Doble escape a propósito: `@js` mete el JSON dentro de un literal de
            // JavaScript, así que «Ramírez» viaja como «Ram\u00edrez» —con la barra
            // duplicada— y buscarlo con una sola no lo encuentra.
            $buscado = str_replace('\\', '\\\\', trim(json_encode($nombre, JSON_THROW_ON_ERROR), '"'));
            $this->assertStringContainsString($buscado, $html, "Falta {$nombre} en la pantalla.");
        }

        $pantalla->assertSee('Pago del período')->assertSee('Total a pagar');
        // Las cinco columnas y el detalle cerrado.
        $pantalla->assertSee('Extras')->assertSee('Ver detalle');
        $this->assertStringContainsString('abiertos: []', $html);

        // ═══ 2. Ajustar solo las excepciones ═══
        $porNombre = $planilla->detalles->keyBy('nombre_snapshot');

        // Un anticipo vivo para Nubia, entregado antes por Gastos.
        $nubia = PlanillaEmpleado::where('nombre', 'Nubia Guadalupe Flores')->firstOrFail();
        $anticipo = $this->adelanto($u, $nubia, '40.00', '2026-09-05');

        $lineas = [];
        foreach ($planilla->detalles as $detalle) {
            $linea = [
                'planilla_empleado_id' => $detalle->planilla_empleado_id,
                'salario' => (string) $detalle->salario,
                'conceptos' => [],
            ];

            if ($detalle->nombre_snapshot === 'Ana Mendoza Ramírez') {
                $linea['conceptos'][] = ['tipo' => 'ingreso', 'concepto' => 'Comisión de ventas', 'importe' => '35.50'];
            }

            if ($detalle->nombre_snapshot === 'Carlos Rivas Menjívar') {
                $linea['conceptos'][] = ['tipo' => 'descuento', 'concepto' => 'Cuota de préstamo', 'importe' => '25.00',
                    'destino' => 'tercero', 'tercero' => 'Cooperativa La Esperanza'];
            }

            // Luis entró el 8: su período y su importe escrito a mano.
            if ($detalle->nombre_snapshot === 'Luis Portillo Aguilar') {
                $linea['salario'] = '96.00';
                $linea['periodo_desde'] = '2026-09-08';
                $linea['periodo_hasta'] = '2026-09-15';
            }

            if ($detalle->nombre_snapshot === 'Nubia Guadalupe Flores') {
                $linea['conceptos'][] = ['tipo' => 'descuento', 'concepto' => 'Anticipo del 05', 'importe' => '40.00',
                    'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id];
            }

            $lineas[] = $linea;
        }

        app(PrepararPlanilla::class)->guardarLineas($u, $planilla, $lineas);
        $planilla = $planilla->fresh()->load('detalles.conceptos');

        // El período particular quedó guardado; el importe es el escrito, no un reparto.
        $luis = $planilla->detalles->firstWhere('nombre_snapshot', 'Luis Portillo Aguilar');
        $this->assertSame('2026-09-08', $luis->periodo_desde->toDateString());
        $this->assertSame('96.00', $luis->salario);

        // ═══ 3. Confirmar ═══
        app(ConfirmarPlanilla::class)->confirmar($u, $planilla);
        $planilla = $planilla->fresh()->load('detalles.gasto.cuotas', 'detalles.conceptos');

        // Diez obligaciones con personas + una con la cooperativa.
        $this->assertSame(11, Gasto::where('categoria', '!=', 'Anticipos al personal')->count());
        $this->assertSame(0, $anticipo->fresh()->pendiente(), 'El anticipo se consumió al confirmar.');

        // ═══ 4. Pagar: nueve completas, Luis a cuenta ═══
        foreach ($planilla->detalles as $detalle) {
            $pendiente = app(PagarPlanilla::class)->pendienteDe($detalle);

            if ($pendiente <= 0) {
                continue;
            }

            $esLuis = $detalle->nombre_snapshot === 'Luis Portillo Aguilar';

            app(PagarPlanilla::class)->pagarPersona($u, $detalle, [
                'importe' => $esLuis ? '60.00' : Dinero::decimal($pendiente),
                'fecha' => '2026-09-12', 'metodo' => 'efectivo',
                'pagado_por' => $u->id, 'sin_comprobante' => 'Quincena.',
            ]);
        }

        // ═══ 5. La hoja grupal: diez renglones con su firma ═══
        $hoja = $this->actingAs($u)->get(route('planilla.impresos', $planilla))->assertOk();
        $papel = $hoja->getContent();

        $hoja->assertSee('Planilla para firma', false);
        $hoja->assertSee('DULCES LA NEGRITA', false);
        $hoja->assertSee('10 trabajadores');

        foreach (self::PERSONAL as [$nombre, $dui]) {
            $hoja->assertSee($nombre);
            $hoja->assertSee($dui);
        }

        // Una celda de firma por persona: diez, ni una menos.
        $this->assertSame(10, substr_count($papel, 'firma-celda'),
            'Cada renglón necesita su espacio para firmar.');

        // Las excepciones, visibles sin buscarlas.
        $hoja->assertSee('Pago parcial', false);
        $hoja->assertSee('08–15 sep', false);

        // Y quien entregó sale del pago, no de la empleadora.
        $hoja->assertSee('Sandra Melgar Ayala');

        // ═══ 6. Los recibos individuales ═══
        $recibos = $this->actingAs($u)
            ->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertOk();

        $recibos->assertSee('Recibo de pago', false);
        $recibos->assertSee('del 08/09/2026 al 15/09/2026', false);
        $recibos->assertSee('Total recibido');
        // Quien no tiene descuentos no imprime ese apartado; quien sí, lo imprime.
        $recibos->assertSee('Anticipo del 05');
    }

    /** Un adelanto real: pago de Gastos + ficha, enlazados. */
    private function adelanto(User $u, PlanillaEmpleado $empleado, string $importe, string $fecha)
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => hash('sha256', $importe.$fecha.$empleado->id),
            'concepto' => 'Adelanto', 'beneficiario' => $empleado->nombre,
            'categoria' => 'Anticipos al personal', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => $importe, 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $cuota = $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => $fecha]);

        $pago = app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(), 'importe' => $importe, 'fecha' => $fecha,
            'metodo' => 'efectivo', 'pagado_por' => $u->id, 'sin_comprobante' => 'Adelanto en caja.',
        ], [['cuota_id' => $cuota->id, 'importe' => $importe]]);

        return app(RegistrarAnticipo::class)->registrar($u, $empleado, [
            'fecha' => $fecha, 'importe' => $importe, 'moneda' => 'USD', 'pago_id' => $pago->id,
        ]);
    }
}
