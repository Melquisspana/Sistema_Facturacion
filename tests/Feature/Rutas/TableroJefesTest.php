<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PermisoSistema;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoRutas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * El inicio de Rutas para los jefes de vendedores: cuándo toca ir a cada sala y ruta.
 *
 *  · Sala: días desde su última visita, que es la entrega más nueva (último CCF o entrega
 *    marcada en Rutas) o el último pedido sacado.
 *  · Ruta: días desde la última salida o la última visita a cualquiera de sus salas.
 *  · Ritmo: el calculado con los CCF; los días cargados en la ruta, solo si no hay datos.
 *
 * Nombres inventados: el repositorio es público.
 */
class TableroJefesTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoy;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->hoy = CarbonImmutable::parse('2026-11-03');
        $this->travelTo($this->hoy->setTime(9, 0));
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function sala(string $nombre, Ruta $ruta): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'ruta_id' => $ruta->id, 'activo' => true]);
    }

    private function ccf(ClienteSucursal $sala, string $fecha, float $subtotal = 100.00): Dte
    {
        $this->correlativo++;
        $establecimiento = Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });

        return Dte::create([
            'establecimiento_id' => $establecimiento->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'hora_emision' => '10:00:00',
            'subtotal' => $subtotal,
            'total_pagar' => round($subtotal * 1.13, 2),
        ]);
    }

    /** @param  array<int, string>  $fechas */
    private function ccfs(ClienteSucursal $sala, array $fechas): void
    {
        foreach ($fechas as $fecha) {
            $this->ccf($sala, $fecha);
        }
    }

    private function salida(Ruta $ruta, string $inicio, ?string $regreso = null, EstadoSalidaRuta $estado = EstadoSalidaRuta::Finalizada): SalidaRuta
    {
        return SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => $inicio, 'fecha_fin_real' => $regreso ?? $inicio, 'estado' => $estado]);
    }

    /** Una entrega marcada en Rutas, de un CCF de la sala. */
    private function entrega(SalidaRuta $salida, ClienteSucursal $sala, string $fecha, ?PersonalRuta $quien = null): void
    {
        $salida->entregas()->create([
            'dte_id' => Dte::where('cliente_sucursal_id', $sala->id)->latest('fecha_emision')->value('id'),
            'cliente_sucursal_id' => $sala->id,
            'resultado' => ResultadoEntrega::Entregado,
            'entregado_por_id' => $quien?->id,
            'fecha_resultado' => $fecha.' 11:00:00',
        ]);
    }

    private function pedido(ClienteSucursal $sala, string $fecha): PedidoSala
    {
        return PedidoSala::create([
            'cliente_sucursal_id' => $sala->id,
            'ruta_id' => $sala->ruta_id,
            'registrado_por' => User::factory()->create()->id,
            'fecha' => $fecha,
        ]);
    }

    /** @return array<string, array> fila de cada sala por nombre, como la arma el tablero */
    private function salas(): array
    {
        return app(RitmoRutas::class)->porRuta($this->hoy)
            ->flatMap(fn (array $f) => $f['salas']->map(fn (ClienteSucursal $s) => RitmoRutas::filaSala($s, $f['ritmoSalas'][$s->id], $this->hoy, $f['visitas'][$s->id])))
            ->keyBy(fn (array $f) => $f['sala']->nombre)
            ->all();
    }

    /** @return array<string, array> fila de cada ruta por nombre */
    private function rutas(): array
    {
        return app(RitmoRutas::class)->porRuta($this->hoy)->keyBy(fn (array $f) => $f['ruta']->nombre)->all();
    }

    public function test_el_ritmo_de_la_sala_es_la_mediana_de_los_dias_entre_sus_ccf(): void
    {
        $ruta = Ruta::create(['nombre' => 'Ruta Norte']);
        // Intervalos 12, 16, 12, 30 y 13: ordenados 12, 12, 13, 16, 30. La mediana es 13;
        // el mes raro de 30 días no la mueve.
        $this->ccfs($this->sala('Súper Ficticio Centro', $ruta), ['2026-08-01', '2026-08-13', '2026-08-29', '2026-09-10', '2026-10-10', '2026-10-23']);

        $sala = $this->salas()['Súper Ficticio Centro'];

        $this->assertSame([13, Ritmo::CONFIABLE], [$sala['ritmo']->dias, $sala['ritmo']->confianza()]);
        // Último CCF el 23/10: 11 días, faltan 2 para la fecha esperada.
        $this->assertSame([11, 2, Ritmo::PRONTO], [$sala['dias'], $sala['faltan'], $sala['estado']]);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Súper Ficticio Centro', 'Toca ir', 'Última entrega', 'hace 11 días', 'Compra cada 13 días']);
    }

    public function test_la_sala_cuenta_desde_el_ccf_la_entrega_de_rutas_o_el_pedido_sacado(): void
    {
        $ruta = Ruta::create(['nombre' => 'Ruta Norte']);
        $fechas = ['2026-09-22', '2026-10-06', '2026-10-20']; // cada 14 días; el último hace 14.
        $soloCcf = $this->sala('Tienda Inventada Uno', $ruta);
        $entregada = $this->sala('Tienda Inventada Dos', $ruta);
        $conPedido = $this->sala('Tienda Inventada Tres', $ruta);
        foreach ([$soloCcf, $entregada, $conPedido] as $sala) {
            $this->ccfs($sala, $fechas);
        }
        // La entrega marcada en Rutas el 30/10 es más nueva que su último CCF.
        $this->entrega($this->salida($ruta, '2026-10-30'), $entregada, '2026-10-30');
        // El pedido de ayer: ya no toca ir a sacarlo. Uno anulado no cuenta.
        $this->pedido($conPedido, '2026-11-02');
        $this->pedido($soloCcf, '2026-11-02')->update(['estado' => 'anulado']);

        $salas = $this->salas();

        $this->assertSame('2026-10-20', $salas['Tienda Inventada Uno']['ultimaEntrega']->toDateString());
        $this->assertSame([14, Ritmo::PRONTO, null], [$salas['Tienda Inventada Uno']['dias'], $salas['Tienda Inventada Uno']['estado'], $salas['Tienda Inventada Uno']['ultimoPedido']]);

        $this->assertSame('2026-10-30', $salas['Tienda Inventada Dos']['ultimaEntrega']->toDateString());
        $this->assertSame([4, Ritmo::AL_DIA], [$salas['Tienda Inventada Dos']['dias'], $salas['Tienda Inventada Dos']['estado']]);

        $this->assertSame('2026-10-20', $salas['Tienda Inventada Tres']['ultimaEntrega']->toDateString());
        $this->assertSame('2026-11-02', $salas['Tienda Inventada Tres']['ultimoPedido']->toDateString());
        $this->assertSame([1, Ritmo::AL_DIA], [$salas['Tienda Inventada Tres']['dias'], $salas['Tienda Inventada Tres']['estado']]);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Tienda Inventada Uno', 'Toca ir', 'Última entrega', 'hace 14 días'])
            ->assertSeeInOrder(['Tienda Inventada Tres', 'Al día', 'Última entrega', 'hace 14 días', 'Pedido sacado', 'ayer'])
            ->assertSee('title="30/10/2026"', false);
    }

    /** El albarán de entrega más nuevo de cada sala sale de una fila agregada, no de todos sus albaranes. */
    public function test_el_albaran_de_entrega_mas_nuevo_cuenta_y_se_trae_una_fila_por_sala(): void
    {
        $ruta = Ruta::create(['nombre' => 'Ruta Norte']);
        $sala = $this->sala('Tienda Inventada Uno', $ruta);
        $otra = $this->sala('Tienda Inventada Dos', $ruta);
        foreach ([[$sala, '2026-10-01', '2026-10-03'], [$sala, '2026-10-12', '2026-10-14'], [$sala, '2026-10-22', '2026-10-25'], [$otra, '2026-10-05', '2026-10-06']] as $i => [$de, $emitido, $entregado]) {
            PpqAlbaran::create([
                'numero_albaran' => 'AC01/0232/00/'.(6700 + $i), 'dte_id' => $this->ccf($de, $emitido)->id,
                'monto_albaran' => 100, 'fecha_albaran' => $entregado, 'origen' => 'gmail',
            ]);
        }
        // Un albarán que no es de entrega no cuenta, aunque sea más nuevo.
        PpqAlbaran::create([
            'numero_albaran' => 'AC02/0232/00/6799', 'dte_id' => Dte::where('cliente_sucursal_id', $sala->id)->latest('fecha_emision')->value('id'),
            'monto_albaran' => 2.89, 'fecha_albaran' => '2026-11-01', 'origen' => 'gmail',
        ]);

        $cargados = 0;
        PpqAlbaran::retrieved(function () use (&$cargados) {
            $cargados++;
        });
        $fechas = app(EntregasCcf::class)->ultimaVisitaPorSala([$sala->id, $otra->id]);

        $this->assertSame('2026-10-25', $fechas[$sala->id]->toDateString());
        $this->assertSame('2026-10-06', $fechas[$otra->id]->toDateString());
        $this->assertSame(0, $cargados, 'No debería cargar los albaranes uno por uno.');
        $this->assertSame('2026-10-25', $this->salas()['Tienda Inventada Uno']['ultimaEntrega']->toDateString());
    }

    public function test_la_ruta_cuenta_desde_su_ultima_salida_o_la_ultima_visita_a_sus_salas(): void
    {
        $sur = Ruta::create(['nombre' => 'Ruta Sur', 'frecuencia_objetivo_dias' => 15]);
        $oeste = Ruta::create(['nombre' => 'Ruta Oeste', 'frecuencia_objetivo_dias' => 15]);
        $nunca = Ruta::create(['nombre' => 'Ruta Nueva', 'frecuencia_objetivo_dias' => 15]);
        $this->sala('Kiosco Ejemplo Bahía', $nunca);

        // Sur: la salida volvió hace 24 días, pero a una sala le facturaron hace 3.
        $this->salida($sur, '2026-10-09', '2026-10-10');
        $this->ccf($this->sala('Bodega Ejemplo Puerto', $sur), '2026-10-31');
        // Oeste: la salida volvió hace 2 días; el último CCF es más viejo.
        $this->salida($oeste, '2026-10-31', '2026-11-01');
        $this->ccf($this->sala('Bodega Ejemplo Playa', $oeste), '2026-10-20');

        $rutas = $this->rutas();

        $this->assertSame([3, RitmoRutas::DESDE_SALAS, RitmoRutas::AL_DIA], [$rutas['Ruta Sur']['dias'], $rutas['Ruta Sur']['desde'], $rutas['Ruta Sur']['estado']]);
        $this->assertSame([2, RitmoRutas::DESDE_SALIDA], [$rutas['Ruta Oeste']['dias'], $rutas['Ruta Oeste']['desde']]);
        $this->assertSame([null, RitmoRutas::SIN_SALIDAS], [$rutas['Ruta Nueva']['dias'], $rutas['Ruta Nueva']['estado']]);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Ruta Sur', 'Última visita a sus salas hace 3 días'])
            ->assertSeeInOrder(['Ruta Oeste', 'Se fue hace 2 días'])
            ->assertSeeInOrder(['Ruta Nueva', 'Nunca se ha ido']);
    }

    /** Decisión 4 de Melqui (03/11/2026): no se carga a mano; el campo es solo respaldo. */
    public function test_con_datos_manda_el_ritmo_calculado_y_sin_datos_los_dias_cargados(): void
    {
        $conDatos = Ruta::create(['nombre' => 'Ruta Costa', 'frecuencia_objetivo_dias' => 30]);
        $sinDatos = Ruta::create(['nombre' => 'Ruta Oriente', 'frecuencia_objetivo_dias' => 30]);
        // Costa: vueltas cada 10 días y volvió hace 13. Aunque diga 30, manda el calculado: atrasada.
        $this->ccfs($this->sala('Sala de la Costa', $conDatos), ['2026-09-21', '2026-10-01', '2026-10-11', '2026-10-21']);
        $this->salida($conDatos, '2026-10-20', '2026-10-21');
        // Oriente: sin CCF no hay ritmo; se usan sus 30 días cargados.
        $this->salida($sinDatos, '2026-10-20', '2026-10-21');

        $rutas = $this->rutas();

        $this->assertSame([10, Ritmo::CONFIABLE, -3, RitmoRutas::ATRASADA], [$rutas['Ruta Costa']['ritmo'], $rutas['Ruta Costa']['origenRitmo'], $rutas['Ruta Costa']['faltan'], $rutas['Ruta Costa']['estado']]);
        $this->assertSame([30, RitmoRutas::MANUAL, 17, RitmoRutas::AL_DIA], [$rutas['Ruta Oriente']['ritmo'], $rutas['Ruta Oriente']['origenRitmo'], $rutas['Ruta Oriente']['faltan'], $rutas['Ruta Oriente']['estado']]);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Ruta Costa', 'Atrasada 3 días', 'Suele ir cada 10 días', 'Ruta Oriente', 'Al día', 'Suele ir cada 30 días (cargado a mano)']);
    }

    public function test_lo_mas_urgente_sale_arriba(): void
    {
        // Todas cada 10 días (margen 2). Se cargan en otro orden para que no gane el alfabético.
        foreach ([
            ['Ruta Al Día', '2026-11-01'],       // 2 días: al día
            ['Ruta Poco Atrasada', '2026-10-21'], // 13 días: 3 tarde
            ['Ruta Toca Ir', '2026-10-25'],       // 9 días: falta 1
            ['Ruta Muy Atrasada', '2026-10-14'],  // 20 días: 10 tarde
        ] as [$nombre, $regreso]) {
            $this->salida(Ruta::create(['nombre' => $nombre, 'frecuencia_objetivo_dias' => 10]), $regreso);
        }

        $orden = app(RitmoRutas::class)->porRuta($this->hoy)->map(fn (array $f) => $f['ruta']->nombre)->all();

        $this->assertSame(['Ruta Muy Atrasada', 'Ruta Poco Atrasada', 'Ruta Toca Ir', 'Ruta Al Día'], $orden);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Ruta Muy Atrasada', 'Atrasada 10 días',
                'Ruta Poco Atrasada', 'Atrasada 3 días',
                'Ruta Toca Ir', 'Toca ir', 'faltan 1 día',
                'Ruta Al Día', 'Al día',
            ]);
    }

    public function test_el_resumen_cuenta_al_dia_y_atrasadas_y_lo_facturado_en_30_dias(): void
    {
        $norte = Ruta::create(['nombre' => 'Ruta Norte', 'frecuencia_objetivo_dias' => 10]);
        $sur = Ruta::create(['nombre' => 'Ruta Sur', 'frecuencia_objetivo_dias' => 10]);
        $this->salida($norte, '2026-11-01');
        $this->salida($sur, '2026-10-10');

        $uno = $this->sala('Tienda Inventada Uno', $norte);
        $this->ccf($uno, '2026-10-25', 150.50);
        $this->ccf($uno, '2026-10-04', 999.00); // hace 30 días: queda afuera
        $this->ccf($this->sala('Tienda Inventada Dos', $norte), '2026-10-05', 49.50); // hace 29: entra

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['1', 'ruta atrasada', '1 al día de 2', '0', 'salas atrasadas', '0 al día de 2'])
            ->assertSeeInOrder(['Facturado en los últimos 30 días:', '$200.00'])
            ->assertSeeInOrder(['Ruta Sur', 'Facturado en 30 días:', '$0.00', 'Ruta Norte', 'Facturado en 30 días:', '$200.00']);
    }

    public function test_solo_entra_quien_tiene_permiso_de_ver_rutas(): void
    {
        $this->get(route('rutas.dashboard'))->assertRedirect(route('login'));

        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()->assertSee('Vendedores');

        foreach (['vendedor', 'contabilidad', 'produccion'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->assertFalse($usuario->can('rutas.ver'), $rol);
            $this->actingAs($usuario)->get(route('rutas.dashboard'))->assertForbidden();
        }
    }

    public function test_el_vendedor_muestra_el_telefono_de_su_ficha_de_personal_y_su_ultima_entrega(): void
    {
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        $admin = $this->admin();
        $admin->givePermissionTo(PermisoSistema::todos());
        $luis = PersonalRuta::create(['nombre' => 'Luis Inventado', 'telefono' => '7000-0000']);
        $ficha = PlanillaEmpleado::create([
            'clave' => 'ruta-'.$luis->id, 'registrado_por' => $admin->id,
            'nombre' => $luis->nombre, 'personal_ruta_id' => $luis->id,
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1', 'con_descuentos_ley' => false, 'activo' => true,
        ]);

        // El teléfono se corrige en la ficha de Personal (su bloque «Rutas»).
        $this->actingAs($admin)
            ->put(route('rutas.personal.update', $luis), ['telefono' => '7111-2222'])
            ->assertRedirect(route('personal.empleados.show', 'p'.$ficha->id));

        $ruta = Ruta::create(['nombre' => 'Ruta Norte']);
        $sala = $this->sala('Tienda Inventada Uno', $ruta);
        $this->ccf($sala, '2026-10-31');
        $this->entrega($this->salida($ruta, '2026-11-01'), $sala, '2026-11-01', $luis);

        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Vendedores', 'Luis Inventado', 'href="tel:71112222"', 'Tel. 7111-2222', 'Última entrega', 'hace 2 días'], false)
            ->assertDontSee('7000-0000');
    }

    /**
     * Las rutas configuradas son el catálogo de ZONAS. Una sala sin ruta cuenta en la zona que
     * cubre su distrito o su departamento; sin ninguna, sale igual en la lista, «Sin zona».
     */
    public function test_la_sala_sin_ruta_cuenta_en_la_zona_que_cubre_su_lugar(): void
    {
        $oriente = Departamento::updateOrCreate(['codigo' => '12'], ['nombre' => 'Departamento de prueba', 'activo' => true]);
        $distrito = Distrito::create(['departamento_id' => $oriente->id, 'municipio' => 'Municipio de prueba', 'codigo' => '01', 'nombre' => 'Distrito de prueba', 'activo' => true]);
        $zona = Ruta::create(['nombre' => 'Ruta Oriente']);
        RutaCobertura::create(['ruta_id' => $zona->id, 'departamento_id' => $oriente->id]);
        $apagada = Ruta::create(['nombre' => 'Ruta Vieja', 'activa' => false]);

        $sinRuta = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Tienda Inventada Sin Ruta', 'departamento_id' => $oriente->id, 'distrito_id' => $distrito->id, 'activo' => true]);
        $deRutaApagada = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Tienda Inventada Vieja', 'ruta_id' => $apagada->id, 'departamento_id' => $oriente->id, 'distrito_id' => $distrito->id, 'activo' => true]);
        $sinLugar = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Tienda Inventada Sin Lugar', 'activo' => true]);
        foreach ([$sinRuta, $deRutaApagada, $sinLugar] as $sala) {
            $this->ccfs($sala, ['2026-09-22', '2026-10-06', '2026-10-20']);
        }

        $fila = $this->rutas()['Ruta Oriente'];
        $this->assertEqualsCanonicalizing(['Tienda Inventada Sin Ruta', 'Tienda Inventada Vieja'], $fila['salas']->pluck('nombre')->all());
        $this->assertSame([14, '2026-10-20'], [$fila['ritmo'], $fila['ritmoSalas'][$sinRuta->id]->ultima()->toDateString()]);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Tienda Inventada Sin Lugar', 'Sin zona'])
            ->assertSeeInOrder(['Tienda Inventada Sin Ruta', 'Ruta Oriente'])
            ->assertSee('1 sala activa sin zona.')
            ->assertDontSee('sin ruta.');
    }

    public function test_el_tablero_hace_las_mismas_consultas_con_mas_zonas_y_salas(): void
    {
        $departamento = Departamento::updateOrCreate(['codigo' => '12'], ['nombre' => 'Departamento de prueba', 'activo' => true]);
        $nueva = function (int $n) use ($departamento) {
            $zona = Ruta::create(['nombre' => 'Ruta de prueba '.$n]);
            $distrito = Distrito::create(['departamento_id' => $departamento->id, 'municipio' => 'Municipio de prueba', 'codigo' => str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'nombre' => 'Distrito '.$n, 'activo' => true]);
            RutaCobertura::create(['ruta_id' => $zona->id, 'distrito_id' => $distrito->id]);
            $this->ccfs($this->sala('Tienda Inventada '.$n, $zona), ['2026-10-01', '2026-10-15']);
            $sinRuta = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Tienda Sin Ruta '.$n, 'departamento_id' => $departamento->id, 'distrito_id' => $distrito->id, 'activo' => true]);
            $this->ccf($sinRuta, '2026-10-20');
            $this->pedido($sinRuta, '2026-11-01');
            $this->salida($zona, '2026-10-20');
        };
        $consultas = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(RitmoRutas::class)->tablero($this->hoy);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $nueva(1);
        $consultas(); // La primera vez se cargan los ajustes en caché; no es del tablero.
        $pocas = $consultas();
        foreach ([2, 3, 4] as $n) {
            $nueva($n);
        }

        $this->assertSame($pocas, $consultas());
        $this->assertCount(8, app(RitmoRutas::class)->tablero($this->hoy)['salas']->whereNotNull('ruta'));
    }
}
