<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Support\Planta\LoteQuery;
use App\Support\Planta\PlantaDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Panel de inicio de Planta.
 *
 * DOS AFIRMACIONES CENTRALES, y el resto de la batería las rodea:
 *
 *  1. NINGUNA CIFRA ES UNA SUMA DE CANTIDADES. El panel cuenta insumos, filas de
 *     saldo y documentos; nunca totaliza `cantidad`, porque en esa columna
 *     conviven libras y unidades y su suma no significa nada físico.
 *
 *  2. LO QUE NO SE PUEDE VER NO SE CONSULTA. Sin el permiso funcional, la
 *     consulta del indicador NO se ejecuta: no basta con ocultar la tarjeta en el
 *     Blade. Se verifica espiando el SQL de la petición, que es la única forma de
 *     distinguir una autorización real de un `@can` decorativo.
 *
 * Los escenarios se construyen por el camino real —recepciones, traslados,
 * cambios de disponibilidad y ajustes confirmados— y nunca escribiendo a mano en
 * `planta_existencias`.
 */
class PlantaDashboardTest extends TestCase
{
    use AjustePlantaFixtures;
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    /** Todas las tablas del módulo que el panel consulta o podría consultar. */
    private const TABLAS_PLANTA = [
        'planta_traslados',
        'planta_existencias',
        'planta_recepciones',
        'planta_lotes',
        'planta_ajustes',
    ];

    /**
     * Tablas de DATOS fiscales que esta pantalla no puede tocar jamás.
     *
     * `failed_jobs` y `jobs` NO están en esta lista y no es un descuido: los
     * consulta el view composer de `layouts.navigation` para el badge de «Salud
     * del sistema», que es del LAYOUT y solo para administradores (ver
     * AppServiceProvider). No sale del panel de Planta ni depende de él. Para el
     * rol `produccion` —quien realmente trabaja en el área— ese composer no toca
     * la base, y eso sí se comprueba abajo contra la lista completa.
     */
    private const TABLAS_AJENAS = [
        'dtes',
        'documentos_recibidos',
        'exportaciones',
    ];

    /** Todo lo ajeno, incluida la cola. Aplicable a quien no es administrador. */
    private const TABLAS_AJENAS_Y_COLA = [
        'dtes',
        'documentos_recibidos',
        'exportaciones',
        'failed_jobs',
        'jobs',
    ];

    private function usuarioCon(array $permisos): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(array_merge(['planta.ver'], $permisos));
    }

    private function usuarioConRolSimple(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** SQL de una petición al panel, para poder afirmar qué NO se consultó. */
    private function sqlDe(callable $peticion): array
    {
        $sentencias = [];
        $midiendo = true;

        DB::listen(function ($query) use (&$sentencias, &$midiendo): void {
            if ($midiendo) {
                $sentencias[] = $query->sql;
            }
        });

        $peticion();
        $midiendo = false;

        return $sentencias;
    }

    private function contarConsultas(callable $accion): int
    {
        $consultas = 0;
        $midiendo = true;

        DB::listen(function () use (&$consultas, &$midiendo): void {
            if ($midiendo) {
                $consultas++;
            }
        });

        $accion();
        $midiendo = false;

        return $consultas;
    }

    private function assertSqlNoMenciona(array $sentencias, array $tablas, string $porQue): void
    {
        foreach ($tablas as $tabla) {
            foreach ($sentencias as $sql) {
                $this->assertStringNotContainsString($tabla, $sql, "{$porQue} (apareció «{$tabla}»).");
            }
        }
    }

    /** Huella de las seis tablas que el panel no puede alterar. */
    private function huellaDelModulo(): array
    {
        $huella = [];

        foreach ([
            'planta_movimientos', 'planta_existencias', 'planta_traslados',
            'planta_recepciones', 'planta_ajustes', 'planta_lotes',
        ] as $tabla) {
            $fila = DB::table($tabla)
                ->selectRaw('COUNT(*) as filas, COALESCE(MAX(id), 0) as max_id')
                ->first();

            $huella[$tabla] = ['filas' => (int) $fila->filas, 'max_id' => (int) $fila->max_id];
        }

        return $huella;
    }

    /**
     * LA ubicación de tránsito, creada una sola vez por prueba.
     *
     * El servicio de traslados exige que exista exactamente una de sistema, y su
     * `codigo` es único: un escenario que la creara en cada llamada reventaría
     * con un duplicado a la segunda. Aquí se comparte, que además es como
     * funciona de verdad —hay una sola en toda la planta—.
     */
    private ?PlantaUbicacion $transitoCompartido = null;

    private function transitoUnico(): PlantaUbicacion
    {
        return $this->transitoCompartido ??= $this->transitoDelSistema();
    }

    /** Escenario de traslado que reutiliza la única ubicación de tránsito. */
    private function escenarioConTransitoUnico(string $cantidadRecibida = '5'): array
    {
        $origen = $this->bodega();
        $destino = $this->bodega();
        $transito = $this->transitoUnico();

        $recepcion = $this->saldoDisponibleEn($origen, $cantidadRecibida);
        $detalle = $recepcion->refresh()->detalles->first();

        return [
            'origen' => $origen,
            'destino' => $destino,
            'transito' => $transito,
            'recepcion' => $recepcion,
            'insumo_id' => (int) $detalle->planta_insumo_id,
            'lote_id' => (int) $detalle->planta_lote_id,
        ];
    }

    /** Envía un traslado FECHÁNDOLO en el pasado, viajando en el tiempo de verdad. */
    private function trasladoEnviadoHace(int $dias): PlantaTraslado
    {
        $e = $this->escenarioConTransitoUnico();
        $traslado = $this->borradorTraslado($e, '100');

        $this->travelTo(now()->subDays($dias));
        $this->servicioTraslado()->enviar($traslado, $this->admin());
        $this->travelBack();

        return $traslado->refresh();
    }

    /** Recepción confirmada cuyo lote vence en la fecha indicada. */
    private function loteQueVence(string $fechaVencimiento, string $cantidad = '5'): PlantaLote
    {
        $admin = $this->admin();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($this->bodega(), [
                $this->linea($insumo, [
                    'cantidad_recibida' => $cantidad,
                    'fecha_vencimiento' => $fechaVencimiento,
                ]),
            ]),
            $admin,
        );
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        return $recepcion->refresh()->detalles->first()->lote()->firstOrFail();
    }

    // =================================================================
    // 1-4. Acceso y autorización
    // =================================================================

    public function test_con_el_modulo_apagado_nadie_entra_al_panel(): void
    {
        // phpunit.xml fija PLANTA_ENABLED=false.
        foreach (['administrador', 'produccion'] as $rol) {
            $this->actingAs($this->usuarioConRolSimple($rol))
                ->get(route('planta.dashboard'))
                ->assertNotFound();
        }
    }

    public function test_el_invitado_es_redirigido_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.dashboard'))->assertRedirect(route('login'));
    }

    public function test_los_roles_fiscales_reciben_403(): void
    {
        $this->encenderModulo();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuarioConRolSimple($rol))
                ->get(route('planta.dashboard'))
                ->assertForbidden();
        }
    }

    public function test_produccion_y_administrador_entran_al_panel(): void
    {
        $this->encenderModulo();

        foreach (['produccion', 'administrador'] as $rol) {
            $this->actingAs($this->usuarioConRolSimple($rol))
                ->get(route('planta.dashboard'))
                ->assertOk()
                ->assertSee('Área de Producción');
        }
    }

    // =================================================================
    // 5, 7. Sin permiso funcional: ni tarjeta ni consulta
    // =================================================================

    public function test_con_solo_planta_ver_entra_sin_tarjetas_y_con_aviso(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon([]))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Área de Producción')
            ->assertSee('No tenés permisos para consultar indicadores operativos')
            ->assertDontSee('Traslados en tránsito')
            ->assertDontSee('Existencias retenidas')
            ->assertDontSee('Recepciones pendientes de confirmar');
    }

    /**
     * La aserción que separa una autorización real de un `@can` decorativo: sin
     * permisos funcionales, NINGUNA tabla del módulo llega a consultarse.
     */
    public function test_con_solo_planta_ver_no_se_ejecuta_ninguna_consulta_de_indicadores(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $usuario = $this->usuarioCon([]);

        $sql = $this->sqlDe(fn () => $this->actingAs($usuario)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertSqlNoMenciona(
            $sql,
            self::TABLAS_PLANTA,
            'Sin permisos funcionales no debe ejecutarse ninguna consulta de indicadores',
        );
    }

    public function test_quien_solo_ve_existencias_no_dispara_las_demas_consultas(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $usuario = $this->usuarioCon(['planta.existencias.ver']);

        $sql = $this->sqlDe(fn () => $this->actingAs($usuario)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertTrue(
            collect($sql)->contains(fn (string $s) => str_contains($s, 'planta_existencias')),
            'Con planta.existencias.ver sí debe consultarse la proyección de saldos.',
        );

        $this->assertSqlNoMenciona(
            $sql,
            ['planta_traslados', 'planta_recepciones', 'planta_ajustes', 'planta_lotes'],
            'Solo se autorizó existencias',
        );
    }

    public function test_quien_solo_ve_traslados_no_dispara_las_demas_consultas(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $usuario = $this->usuarioCon(['planta.traslados.ver']);

        $sql = $this->sqlDe(fn () => $this->actingAs($usuario)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertTrue(
            collect($sql)->contains(fn (string $s) => str_contains($s, 'planta_traslados')),
            'Con planta.traslados.ver sí debe consultarse la tabla de traslados.',
        );

        $this->assertSqlNoMenciona(
            $sql,
            ['planta_existencias', 'planta_recepciones', 'planta_ajustes', 'planta_lotes'],
            'Solo se autorizó traslados',
        );
    }

    // =================================================================
    // 6. Cada tarjeta con su permiso
    // =================================================================

    /** @return array<string, array{0: string, 1: string}> */
    public static function tarjetas(): array
    {
        return [
            'traslados' => ['planta.traslados.ver', 'Traslados en tránsito'],
            'existencias' => ['planta.existencias.ver', 'Existencias retenidas'],
            'recepciones' => ['planta.recepciones.ver', 'Recepciones pendientes de confirmar'],
            'lotes' => ['planta.catalogos.ver', 'Lotes por vencimiento'],
            'ajustes' => ['planta.ajustes.ver', 'Ajustes confirmados'],
        ];
    }

    public function test_cada_tarjeta_aparece_solo_con_su_permiso(): void
    {
        $this->encenderModulo();

        foreach (self::tarjetas() as $nombre => [$permiso, $titulo]) {
            $this->actingAs($this->usuarioCon([$permiso]))
                ->get(route('planta.dashboard'))
                ->assertOk()
                ->assertSee($titulo, false);

            // Y con cualquier OTRO permiso, esa misma tarjeta no está.
            $otros = collect(self::tarjetas())
                ->reject(fn ($t, $clave) => $clave === $nombre)
                ->map(fn ($t) => $t[0])
                ->values()
                ->all();

            $this->actingAs($this->usuarioCon($otros))
                ->get(route('planta.dashboard'))
                ->assertOk()
                ->assertDontSee($titulo, false);
        }
    }

    // =================================================================
    // 8-10. Conteos, nunca sumas
    // =================================================================

    /**
     * Dos insumos con UNIDADES BASE DISTINTAS y saldo conocido: 700 libras y 400
     * unidades. El panel no puede mostrar 1100 en ninguna parte, ni ninguna
     * cantidad con la escala del inventario.
     */
    public function test_no_existe_ninguna_cifra_que_sume_unidades_distintas(): void
    {
        $bodega = $this->bodega();
        $admin = $this->admin();

        foreach ([[$this->insumoConLotes(), '7'], [$this->insumoSinLotes(), '4']] as [$insumo, $cantidad]) {
            $recepcion = $this->servicioRecepcion()->crearBorrador(
                $this->payload($bodega, [$this->linea($insumo, ['cantidad_recibida' => $cantidad])]),
                $admin,
            );
            $this->servicioRecepcion()->confirmar($recepcion, $admin);
        }

        $this->encenderModulo();

        $html = $this->actingAs($this->usuarioCon(['planta.existencias.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            // Dos insumos distintos con saldo disponible: eso sí es un conteo.
            ->assertSee('Insumos con existencia disponible')
            ->getContent();

        // 700 + 400 = 1100. Ese número no puede existir en la página.
        $this->assertStringNotContainsString('1100', $html);

        // Ni ninguna cantidad con la escala decimal del inventario: el panel no
        // imprime cantidades, solo conteos.
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{4}/', $html);
    }

    public function test_insumos_con_existencia_cuenta_insumos_distintos_y_no_registros(): void
    {
        $insumo = $this->insumoConLotes();
        $admin = $this->admin();

        // El MISMO insumo entrando tres veces: tres lotes, tres filas de saldo,
        // un solo insumo.
        for ($i = 0; $i < 3; $i++) {
            $recepcion = $this->servicioRecepcion()->crearBorrador(
                $this->payload($this->bodega(), [$this->linea($insumo)]),
                $admin,
            );
            $this->servicioRecepcion()->confirmar($recepcion, $admin);
        }

        $this->assertSame(3, DB::table('planta_existencias')->where('cantidad', '>', 0)->count());
        $this->assertSame(1, (new PlantaDashboardQuery)->existencias()['insumosDisponibles']);
    }

    public function test_retenido_y_rechazado_cuentan_registros_con_saldo(): void
    {
        $recepcion = $this->saldoRetenido();
        $admin = $this->admin();

        // De 500 retenidos se rechazan 100: quedan dos filas con saldo, una en
        // cada estado.
        $rechazo = $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Rechazado),
            $admin,
        );
        $this->servicioCambio()->confirmar($rechazo, $admin);

        $existencias = (new PlantaDashboardQuery)->existencias();

        $this->assertSame(1, $existencias['retenidos']);
        $this->assertSame(1, $existencias['rechazados']);
        // Nada retenido cuenta como disponible.
        $this->assertSame(0, $existencias['insumosDisponibles']);
    }

    public function test_la_tarjeta_de_retenido_habla_de_registros_y_no_de_cantidad(): void
    {
        $this->saldoRetenido();
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.existencias.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Existencias retenidas')
            ->assertSee('registro con saldo');
    }

    public function test_un_bucket_en_cero_no_cuenta_en_ningun_estado(): void
    {
        $e = $this->escenarioConTransitoUnico();
        $traslado = $this->borradorTraslado($e, '500');

        // Enviar y recibir todo deja el bucket de origen y el de tránsito en cero.
        $this->servicioTraslado()->enviar($traslado, $this->admin());
        $this->servicioTraslado()->recibir($traslado->refresh(), $this->admin());

        $this->assertGreaterThan(
            0,
            DB::table('planta_existencias')->where('cantidad', 0)->count(),
            'El escenario debe dejar buckets en cero.',
        );

        // Un solo insumo con saldo, en el destino.
        $this->assertSame(1, (new PlantaDashboardQuery)->existencias()['insumosDisponibles']);
    }

    // =================================================================
    // 12. Traslados
    // =================================================================

    public function test_solo_los_traslados_enviados_cuentan_como_transito(): void
    {
        $enviado = $this->trasladoEnviadoHace(2);

        // Uno recibido y uno cancelado: ninguno viaja.
        $recibido = $this->trasladoEnviadoHace(4);
        $this->servicioTraslado()->recibir($recibido, $this->admin());

        $cancelable = $this->borradorTraslado($this->escenarioConTransitoUnico(), '100');
        $this->servicioTraslado()->cancelar($cancelable, $this->admin());

        $traslados = (new PlantaDashboardQuery)->traslados();

        $this->assertSame(1, $traslados['cantidad']);
        $this->assertSame(2, $traslados['dias']);
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $enviado->refresh()->estado);
    }

    public function test_el_mas_antiguo_se_determina_por_enviado_en(): void
    {
        $this->trasladoEnviadoHace(1);
        $this->trasladoEnviadoHace(9);
        $this->trasladoEnviadoHace(4);

        $traslados = (new PlantaDashboardQuery)->traslados();

        $this->assertSame(3, $traslados['cantidad']);
        $this->assertSame(9, $traslados['dias']);
    }

    public function test_los_dias_en_transito_de_un_traslado_son_correctos(): void
    {
        $traslado = $this->trasladoEnviadoHace(5);

        $this->assertSame(5, PlantaDashboardQuery::diasEnTransito($traslado));
    }

    public function test_un_traslado_recibido_no_muestra_dias_en_transito(): void
    {
        $traslado = $this->trasladoEnviadoHace(5);
        $this->servicioTraslado()->recibir($traslado, $this->admin());

        $this->assertNull(PlantaDashboardQuery::diasEnTransito($traslado->refresh()));
    }

    public function test_un_traslado_en_borrador_no_muestra_dias_en_transito(): void
    {
        $borrador = $this->borradorTraslado($this->escenarioConTransitoUnico(), '100');

        $this->assertNull(PlantaDashboardQuery::diasEnTransito($borrador));
    }

    /**
     * ÚNICO sitio de toda la suite donde los umbrales aparecen como números
     * literales, y es a propósito: aquí es donde se documenta la regla de
     * negocio. Todo lo demás se deriva de las constantes, de modo que ajustar el
     * criterio se hace en un solo lugar del código y otro de las pruebas.
     *
     * El viaje de Casa a Fábrica dura una hora y debe recibirse el mismo día: un
     * día en tránsito ya es anómalo y dos son un problema.
     */
    public function test_los_umbrales_corresponden_a_la_operacion_real(): void
    {
        $this->assertSame(1, PlantaDashboardQuery::DIAS_TRANSITO_ADVERTENCIA);
        $this->assertSame(2, PlantaDashboardQuery::DIAS_TRANSITO_PELIGRO);
    }

    /**
     * El comportamiento en los bordes, expresado RELATIVO a las constantes: si
     * mañana cambian, esta prueba sigue describiendo la regla correcta.
     */
    public function test_la_severidad_cambia_exactamente_en_cada_umbral(): void
    {
        $advertencia = PlantaDashboardQuery::DIAS_TRANSITO_ADVERTENCIA;
        $peligro = PlantaDashboardQuery::DIAS_TRANSITO_PELIGRO;

        // Sin traslado en tránsito no hay antigüedad que juzgar.
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_NEUTRA, PlantaDashboardQuery::severidadTransito(null));

        // Justo por debajo del primer umbral, y en el propio umbral.
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_NEUTRA, PlantaDashboardQuery::severidadTransito($advertencia - 1));
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_ADVERTENCIA, PlantaDashboardQuery::severidadTransito($advertencia));

        // Justo por debajo del segundo, en el propio umbral y muy por encima.
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_ADVERTENCIA, PlantaDashboardQuery::severidadTransito($peligro - 1));
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_PELIGRO, PlantaDashboardQuery::severidadTransito($peligro));
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_PELIGRO, PlantaDashboardQuery::severidadTransito($peligro + 20));
    }

    /** Sin fecha de salida no hay antigüedad: null, nunca cero. */
    public function test_sin_fecha_de_salida_no_hay_antiguedad(): void
    {
        $this->assertNull(PlantaDashboardQuery::diasDesde(null));
        $this->assertNull(PlantaDashboardQuery::diasDesde(''));
        $this->assertSame(PlantaDashboardQuery::SEVERIDAD_NEUTRA, PlantaDashboardQuery::severidadTransito(null));
    }

    /**
     * Los tres tramos sobre traslados REALES, enviados de verdad en el pasado:
     * hoy es neutro, un día es advertencia y dos o más son peligro.
     */
    public function test_los_tres_tramos_sobre_traslados_reales(): void
    {
        $advertencia = PlantaDashboardQuery::DIAS_TRANSITO_ADVERTENCIA;
        $peligro = PlantaDashboardQuery::DIAS_TRANSITO_PELIGRO;

        $hoy = $this->trasladoEnviadoHace(0);
        $enUmbral = $this->trasladoEnviadoHace($advertencia);
        $grave = $this->trasladoEnviadoHace($peligro);
        $muyGrave = $this->trasladoEnviadoHace($peligro + 5);

        foreach ([
            [$hoy, 0, PlantaDashboardQuery::SEVERIDAD_NEUTRA],
            [$enUmbral, $advertencia, PlantaDashboardQuery::SEVERIDAD_ADVERTENCIA],
            [$grave, $peligro, PlantaDashboardQuery::SEVERIDAD_PELIGRO],
            [$muyGrave, $peligro + 5, PlantaDashboardQuery::SEVERIDAD_PELIGRO],
        ] as [$traslado, $diasEsperados, $severidadEsperada]) {
            $dias = PlantaDashboardQuery::diasEnTransito($traslado);

            $this->assertSame($diasEsperados, $dias, "El traslado #{$traslado->numero} debía llevar {$diasEsperados} días.");
            $this->assertSame($severidadEsperada, PlantaDashboardQuery::severidadTransito($dias));
        }
    }

    /**
     * La MISMA regla en las dos pantallas. Se comprueba por el color realmente
     * renderizado y no por la función, que ya está probada arriba: lo que
     * importa es que ninguna vista aplique un criterio propio.
     *
     * El rojo es la señal inequívoca: en el listado, el badge de estado de un
     * traslado enviado es ámbar, así que el ámbar no distingue; el rojo solo
     * puede venir de la antigüedad mientras no haya traslados cancelados.
     */
    public function test_el_listado_y_el_panel_aplican_la_misma_severidad(): void
    {
        $this->encenderModulo();
        $usuario = $this->usuarioCon(['planta.traslados.ver']);
        $peligro = PlantaDashboardQuery::DIAS_TRANSITO_PELIGRO;

        // --- Un día: advertencia, nunca peligro ---
        $this->trasladoEnviadoHace(PlantaDashboardQuery::DIAS_TRANSITO_ADVERTENCIA);

        $listado = $this->actingAs($usuario)->get(route('planta.traslados.index'))->assertOk();
        $listado->assertSee('bg-amber-100', false);
        $listado->assertDontSee('bg-red-100', false);

        // En el panel solo se dibuja la tarjeta de traslados: el anillo es suyo.
        $panel = $this->actingAs($usuario)->get(route('planta.dashboard'))->assertOk();
        $panel->assertSee('ring-amber-300', false);
        $panel->assertDontSee('ring-red-300', false);

        // --- Dos días: peligro en las dos pantallas ---
        $this->trasladoEnviadoHace($peligro);

        $this->actingAs($usuario)->get(route('planta.traslados.index'))->assertOk()
            ->assertSee('bg-red-100', false);

        $this->actingAs($usuario)->get(route('planta.dashboard'))->assertOk()
            ->assertSee('ring-red-300', false);
    }

    /**
     * Lo que ya no viaja no muestra antigüedad ni cuenta en el KPI, sea porque
     * llegó, porque se canceló o porque se reversó.
     */
    public function test_lo_que_ya_no_viaja_no_tiene_antiguedad_ni_cuenta(): void
    {
        $peligro = PlantaDashboardQuery::DIAS_TRANSITO_PELIGRO;

        $recibido = $this->trasladoEnviadoHace($peligro + 3);
        $this->servicioTraslado()->recibir($recibido, $this->admin());

        $reversado = $this->trasladoEnviadoHace($peligro + 3);
        $this->servicioTraslado()->reversar($reversado, 'salió el lote equivocado', $this->admin());

        $cancelado = $this->borradorTraslado($this->escenarioConTransitoUnico(), '100');
        $this->servicioTraslado()->cancelar($cancelado, $this->admin());

        foreach ([$recibido, $reversado, $cancelado] as $traslado) {
            $this->assertNull(
                PlantaDashboardQuery::diasEnTransito($traslado->refresh()),
                "El traslado #{$traslado->numero} ya no viaja y no puede mostrar antigüedad.",
            );
        }

        $traslados = (new PlantaDashboardQuery)->traslados();

        $this->assertSame(0, $traslados['cantidad']);
        $this->assertNull($traslados['dias']);
    }

    public function test_sin_traslados_en_transito_la_tarjeta_muestra_su_estado_vacio(): void
    {
        $this->encenderModulo();

        $traslados = (new PlantaDashboardQuery)->traslados();
        $this->assertSame(0, $traslados['cantidad']);
        $this->assertNull($traslados['dias'], 'Sin traslados no hay antigüedad: null, no cero.');

        $this->actingAs($this->usuarioCon(['planta.traslados.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Nada en tránsito');
    }

    public function test_la_tarjeta_de_transito_enlaza_al_listado_filtrado(): void
    {
        $this->trasladoEnviadoHace(8);
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.traslados.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('El más antiguo lleva')
            ->assertSee(route('planta.traslados.index', ['estado' => EstadoTrasladoPlanta::Enviado->value]));
    }

    public function test_el_listado_de_traslados_muestra_los_dias_en_transito(): void
    {
        $this->trasladoEnviadoHace(5);
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.traslados.ver']))
            ->get(route('planta.traslados.index'))
            ->assertOk()
            ->assertSee('Días en tránsito')
            ->assertSee('5 días');
    }

    public function test_el_listado_no_muestra_dias_de_transito_de_lo_ya_recibido(): void
    {
        $traslado = $this->trasladoEnviadoHace(5);
        $this->servicioTraslado()->recibir($traslado, $this->admin());
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.traslados.ver']))
            ->get(route('planta.traslados.index'))
            ->assertOk()
            ->assertSee('Días en tránsito')
            ->assertDontSee('5 días');
    }

    // =================================================================
    // 13. Recepciones
    // =================================================================

    public function test_solo_las_recepciones_en_borrador_cuentan(): void
    {
        $this->borrador();

        $confirmada = $this->borrador();
        $this->servicioRecepcion()->confirmar($confirmada, $this->admin());

        $this->assertSame(1, (new PlantaDashboardQuery)->recepcionesEnBorrador());
    }

    public function test_la_tarjeta_de_recepciones_enlaza_al_listado_filtrado(): void
    {
        $this->borrador();
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.recepciones.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.recepciones.index', ['estado' => EstadoRecepcionPlanta::Borrador->value]));
    }

    public function test_sin_borradores_la_tarjeta_de_recepciones_muestra_su_estado_vacio(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.recepciones.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Sin recepciones pendientes');
    }

    // =================================================================
    // 14. Lotes
    // =================================================================

    public function test_un_lote_vencido_con_saldo_cuenta(): void
    {
        $this->loteQueVence(Carbon::today()->subDay()->toDateString());

        $this->assertSame(
            ['vencidos' => 1, 'porVencer' => 0],
            (new PlantaDashboardQuery)->lotesPorVencimiento(),
        );
    }

    public function test_un_lote_vencido_pero_agotado_no_cuenta(): void
    {
        $admin = $this->admin();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($this->bodega(), [
                $this->linea($insumo, ['fecha_vencimiento' => Carbon::today()->subDay()->toDateString()]),
            ]),
            $admin,
        );
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        // Reversar deja el saldo en cero; el lote y su historial siguen ahí.
        $this->servicioRecepcion()->reversar($recepcion->refresh(), 'devolución al proveedor', $admin);

        $this->assertSame(0, (new PlantaDashboardQuery)->lotesPorVencimiento()['vencidos']);
    }

    public function test_un_lote_vencido_pero_retirado_no_cuenta(): void
    {
        $lote = $this->loteQueVence(Carbon::today()->subDay()->toDateString());

        $this->assertSame(1, (new PlantaDashboardQuery)->lotesPorVencimiento()['vencidos']);

        $lote->update(['activo' => false]);

        $this->assertSame(0, (new PlantaDashboardQuery)->lotesPorVencimiento()['vencidos']);
    }

    /**
     * El genérico queda fuera por SER genérico, no por casualidad: se le fuerza
     * una fecha de vencimiento con el query builder —el modelo lo bloquea— y aun
     * así no aparece.
     */
    public function test_el_lote_generico_no_cuenta_aunque_tenga_fecha_de_vencimiento(): void
    {
        $admin = $this->admin();
        $insumo = $this->insumoSinLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($this->bodega(), [$this->linea($insumo)]),
            $admin,
        );
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        $generico = $recepcion->refresh()->detalles->first()->lote()->firstOrFail();
        $this->assertTrue($generico->es_generico);

        DB::table('planta_lotes')->where('id', $generico->id)
            ->update(['fecha_vencimiento' => Carbon::today()->subDay()->toDateString()]);

        $this->assertSame(
            ['vencidos' => 0, 'porVencer' => 0],
            (new PlantaDashboardQuery)->lotesPorVencimiento(),
        );
    }

    public function test_un_lote_que_vence_dentro_de_la_ventana_cuenta_como_proximo(): void
    {
        $this->loteQueVence(Carbon::today()->addDays(10)->toDateString());

        $this->assertSame(
            ['vencidos' => 0, 'porVencer' => 1],
            (new PlantaDashboardQuery)->lotesPorVencimiento(),
        );
    }

    public function test_un_lote_que_vence_fuera_de_la_ventana_no_cuenta(): void
    {
        $this->loteQueVence(Carbon::today()->addDays(PlantaDashboardQuery::DIAS_VENTANA + 5)->toDateString());

        $this->assertSame(
            ['vencidos' => 0, 'porVencer' => 0],
            (new PlantaDashboardQuery)->lotesPorVencimiento(),
        );
    }

    public function test_un_lote_sin_fecha_de_vencimiento_no_cuenta(): void
    {
        $this->loteQueVence(Carbon::today()->addDays(5)->toDateString());
        $this->trasladoEnviadoHace(1); // crea lotes sin vencimiento

        $this->assertSame(1, (new PlantaDashboardQuery)->lotesPorVencimiento()['porVencer']);
    }

    public function test_la_tarjeta_de_lotes_enlaza_con_filtros_validos(): void
    {
        $this->loteQueVence(Carbon::today()->subDay()->toDateString());
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.catalogos.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.lotes.index', [
                'vencimiento' => LoteQuery::VENCIMIENTO_VENCIDOS,
                'activo' => LoteQuery::ACTIVO_SI,
            ]))
            ->assertSee(route('planta.lotes.index', [
                'vencimiento' => LoteQuery::VENCIMIENTO_POR_VENCER,
                'dias' => PlantaDashboardQuery::DIAS_VENTANA,
                'activo' => LoteQuery::ACTIVO_SI,
            ]));
    }

    public function test_sin_lotes_la_tarjeta_muestra_su_estado_vacio(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.catalogos.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Sin lotes vencidos ni próximos a vencer');
    }

    // =================================================================
    // 15. Ajustes
    // =================================================================

    public function test_un_ajuste_confirmado_dentro_de_la_ventana_cuenta(): void
    {
        $this->ajusteConfirmadoEn(Carbon::today()->subDays(5)->toDateString());

        $this->assertSame(1, (new PlantaDashboardQuery)->ajustesConfirmadosRecientes());
    }

    public function test_un_ajuste_en_borrador_no_cuenta(): void
    {
        $this->borradorAjuste($this->escenarioConSaldo());

        $this->assertSame(0, (new PlantaDashboardQuery)->ajustesConfirmadosRecientes());
    }

    public function test_un_ajuste_confirmado_fuera_de_la_ventana_no_cuenta(): void
    {
        $this->ajusteConfirmadoEn(
            Carbon::today()->subDays(PlantaDashboardQuery::DIAS_VENTANA + 10)->toDateString()
        );

        $this->assertSame(0, (new PlantaDashboardQuery)->ajustesConfirmadosRecientes());
    }

    public function test_la_tarjeta_de_ajustes_enlaza_al_listado_filtrado(): void
    {
        $this->ajusteConfirmadoEn(Carbon::today()->subDays(3)->toDateString());
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.ajustes.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.ajustes.index', [
                'estado' => EstadoAjustePlanta::Confirmado->value,
                'desde' => PlantaDashboardQuery::inicioDeVentana()->toDateString(),
            ]));
    }

    public function test_sin_ajustes_la_tarjeta_muestra_su_estado_vacio(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioCon(['planta.ajustes.ver']))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('Sin ajustes confirmados en los últimos '.PlantaDashboardQuery::DIAS_VENTANA.' días');
    }

    /** Ajuste positivo confirmado con fecha operativa concreta. */
    private function ajusteConfirmadoEn(string $fecha): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->servicioAjuste()->crearBorrador(
            $this->payloadAjuste(
                $e,
                TipoAjuste::Positivo,
                '100',
                EstadoDisponibilidad::Disponible,
                [],
                ['fecha' => $fecha],
            ),
            $this->admin(),
        );

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    // =================================================================
    // 16-19. Rendimiento, inmutabilidad y aislamiento
    // =================================================================

    /** Escenario con datos en las cinco fuentes del panel. */
    private function escenarioCompleto(): void
    {
        $this->trasladoEnviadoHace(4);
        $this->saldoRetenido();
        $this->borrador();
        $this->loteQueVence(Carbon::today()->subDay()->toDateString());
        $this->ajusteConfirmadoEn(Carbon::today()->subDays(2)->toDateString());
    }

    public function test_el_numero_de_consultas_no_crece_con_el_volumen(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $admin = $this->usuarioConRolSimple('administrador');

        // Calentamiento: no se mide.
        $this->actingAs($admin)->get(route('planta.dashboard'))->assertOk();

        $conPocos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.dashboard'))->assertOk());

        for ($i = 0; $i < 6; $i++) {
            $this->escenarioCompleto();
        }

        $conMuchos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertLessThanOrEqual(
            $conPocos,
            $conMuchos,
            "El panel hace {$conMuchos} consultas con el escenario grande y {$conPocos} con el pequeño: crece con los datos.",
        );
    }

    public function test_cargar_el_panel_no_cambia_ninguna_fila_del_modulo(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();

        $antes = $this->huellaDelModulo();

        $this->actingAs($this->usuarioConRolSimple('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk();

        $this->assertSame($antes, $this->huellaDelModulo());
    }

    /**
     * El panel NO reconcilia. La reconciliación agrupa todo el libro mayor por
     * las cinco dimensiones del bucket y lee entera la proyección: dos escaneos
     * completos que no caben en una pantalla de inicio. La forma más directa de
     * demostrarlo es que el mayor ni siquiera se consulta.
     */
    public function test_el_panel_no_ejecuta_la_reconciliacion(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $admin = $this->usuarioConRolSimple('administrador');

        $sql = $this->sqlDe(fn () => $this->actingAs($admin)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertSqlNoMenciona(
            $sql,
            ['planta_movimientos'],
            'El panel no lee el libro mayor: eso es lo que hace la reconciliación',
        );
    }

    /**
     * Quien trabaja en el área no roza el módulo fiscal: ni sus datos ni la cola.
     * Es el rol que importa para esta garantía, y el mismo que usa
     * PlantaRedireccionTest C4.
     */
    public function test_para_produccion_el_panel_no_consulta_nada_ajeno(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $usuario = $this->usuarioConRolSimple('produccion');

        $sql = $this->sqlDe(fn () => $this->actingAs($usuario)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertSqlNoMenciona(
            $sql,
            self::TABLAS_AJENAS_Y_COLA,
            'El área de Producción no consulta el módulo fiscal ni la cola',
        );
    }

    /**
     * Y para el administrador tampoco se consulta un solo dato fiscal. La cola
     * queda fuera de la aserción a propósito: `failed_jobs` lo lee el badge del
     * navbar para administradores, que es del layout compartido y existe con
     * módulo o sin él (JobsFallidosBadgeTest lo cubre). Si esta prueba lo
     * incluyera, estaría midiendo el layout y no el panel.
     */
    public function test_para_el_administrador_el_panel_no_consulta_datos_fiscales(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();
        $admin = $this->usuarioConRolSimple('administrador');

        $sql = $this->sqlDe(fn () => $this->actingAs($admin)
            ->get(route('planta.dashboard'))->assertOk());

        $this->assertSqlNoMenciona(
            $sql,
            self::TABLAS_AJENAS,
            'El panel de Planta no consulta datos fiscales',
        );
    }

    // =================================================================
    // 20-21. Textos
    // =================================================================

    public function test_los_textos_placeholder_ya_no_aparecen(): void
    {
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuarioConRolSimple('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk();

        foreach ([
            'Módulo en preparación',
            'Esta área todavía no tiene funciones operativas',
            'No hay datos que mostrar',
            'Qué se podrá hacer aquí más adelante',
            'Registrar lo que se produce cada día',
        ] as $obsoleto) {
            $resp->assertDontSee($obsoleto);
        }
    }

    public function test_el_panel_no_muestra_indicadores_fiscales(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuarioConRolSimple('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk();

        foreach (['DTE aceptados', 'Ventas del mes', 'Jobs fallidos', 'Estado técnico', 'Diagnóstico'] as $indicador) {
            $resp->assertDontSee($indicador);
        }
    }

    public function test_el_panel_no_ofrece_ninguna_accion_de_escritura(): void
    {
        $this->escenarioCompleto();
        $this->encenderModulo();

        $html = $this->actingAs($this->usuarioConRolSimple('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->getContent();

        // El layout trae su propio formulario —cerrar sesión—, así que se mira la
        // ACCIÓN de cada formulario que no es GET, no cuántos hay.
        preg_match_all('/<form\b[^>]*>/i', $html, $formularios);

        foreach ($formularios[0] as $etiqueta) {
            if (preg_match('/method=["\']get["\']/i', $etiqueta) === 1) {
                continue;
            }

            $accion = preg_match('/action=["\']([^"\']*)["\']/i', $etiqueta, $m) === 1 ? $m[1] : '';

            $this->assertStringNotContainsString('/planta', $accion, "Un formulario POST apunta a {$accion}.");
        }
    }
}
