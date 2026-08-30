<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPpq;
use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\BandejaDocumentos;
use App\Services\Rutas\Cobranza;
use App\Services\Rutas\SaldoPorRuta;
use App\Services\Rutas\SeguimientoDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard de Rutas / Cobros: las cinco bandas y la ventana compartida.
 *
 * Lo que estas pruebas defienden, por orden de importancia:
 *
 *  - EL ANCLA: dashboard y bandeja, con los mismos filtros, devuelven cifras
 *    IDÉNTICAS. Es el test que se cae el día que alguien meta un cálculo propio en
 *    el controlador o en el Blade, que es exactamente lo que no debe pasar;
 *  - cada tarjeta enlaza al listado ARRASTRANDO el contexto (fechas, ruta, sala):
 *    el universo del número y el del listado que abre son el mismo;
 *  - el saldo nunca se publica como una sola cifra: siempre partido en fuera de PPQ
 *    y en PPQ sin pagar;
 *  - lo que no se pudo calcular queda VISIBLE, no disimulado dentro de un total;
 *  - `SaldoPorRuta` agrupa y delega: la suma de las filas no puede discrepar del
 *    total general porque sale de la misma función.
 */
class DashboardCobranzaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

            return Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'activo' => true,
            ]);
        });
    }

    private function sala(Ruta $ruta, string $nombre = 'Selectos San Miguel'): ClienteSucursal
    {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Calleja'], Cliente::factory()->raw(['nombre' => 'Calleja']));

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
            'codigo' => '0240',
            'ruta_id' => $ruta->id,
        ]);
    }

    /** Las salidas se anclan a HOY para no depender de la fecha real de la máquina. */
    private function salida(Ruta $ruta, ?string $inicio = null): SalidaRuta
    {
        $inicio ??= Carbon::today()->toDateString();

        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => $inicio,
            'fecha_fin_estimada' => $inicio,
            'estado' => EstadoSalidaRuta::Planificada,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ccf(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            // Fiscalmente VIGENTE. Desde la Fase 0, a una salida de ruta solo entra un
            // CCF que existe de verdad ante Hacienda: produccion, aceptado y con sello
            // real. Un CCF de prueba tiene que parecerse a uno real o no entra.
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(substr(md5($control), 0, 12)),
            'fecha_procesamiento_mh' => '2026-08-14 12:00:00',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => Carbon::today()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    private function documento(SalidaRuta $salida, Dte $ccf): SalidaRutaDocumento
    {
        return SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => $ccf->id,
            'numero_control' => $ccf->numero_control,
            'numero_orden_compra' => $ccf->numero_orden_compra,
            'cliente_sucursal_id' => $ccf->cliente_sucursal_id,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);
    }

    private function item(string $control, ?string $estado = null, ?float $monto = null): PpqItem
    {
        $lote = PpqLote::create(['referencia' => 'PPQ prueba', 'fecha' => Carbon::today()->toDateString(), 'estado' => EstadoPpq::Listo]);

        return PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'dte_id' => null,
            'origen' => 'gmail',
            'numero_control' => $control,
            'tipo_dte' => '03',
            'monto_dte' => $monto ?? 100.00,
            'conciliacion_estado' => $estado,
            'fecha_pago' => $estado ? Carbon::today()->toDateString() : null,
            'monto_pagado' => $estado ? ($monto ?? 100.00) : null,
        ]);
    }

    /** Un escenario con las tres situaciones de cobro a la vez. */
    private function escenario(): Ruta
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        // Fuera de PPQ: nadie lo ingresó.
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        // En PPQ, sin pagar.
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', ['total_pagar' => 50.00]));
        $this->item('DTE-03-M001P002-000000000000002');
        // Cobrado.
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000003', ['total_pagar' => 30.00]));
        $this->item('DTE-03-M001P002-000000000000003', 'pagado', 30.00);

        return $ruta;
    }

    // ==================================================== EL ANCLA

    /**
     * El test que sostiene todo el bloque.
     *
     * Si algún día alguien agrega un `SUM` en el controlador, una resta en el Blade o
     * un servicio de métricas paralelo, las dos mitades se separan y esto se cae. No
     * compara «parecido»: compara IGUALDAD ESTRICTA de los arrays completos.
     */
    public function test_ancla_el_dashboard_y_la_bandeja_devuelven_las_mismas_cifras(): void
    {
        $ruta = $this->escenario();

        foreach ([[], ['ruta_id' => $ruta->id], ['desde' => Carbon::today()->subDays(5)->toDateString()]] as $filtros) {
            $esperado = app(BandejaDocumentos::class)->consultar($filtros);

            $respuesta = $this->actingAs($this->admin())
                ->get(route('rutas.dashboard', $filtros))
                ->assertOk();

            $this->assertSame($esperado['dinero'], $respuesta->viewData('dinero'), 'el dinero difiere con filtros '.json_encode($filtros));
            $this->assertSame($esperado['resumen'], $respuesta->viewData('resumen'), 'el resumen difiere con filtros '.json_encode($filtros));
            $this->assertEquals($esperado['antiguedad'], $respuesta->viewData('antiguedad'), 'la antigüedad difiere con filtros '.json_encode($filtros));
            $this->assertSame($esperado['desde']->toDateString(), $respuesta->viewData('desde')->toDateString());
            $this->assertSame($esperado['hasta']->toDateString(), $respuesta->viewData('hasta')->toDateString());
        }
    }

    public function test_la_suma_de_las_filas_por_ruta_da_el_total_general(): void
    {
        $ruta = $this->escenario();

        // Una segunda ruta con su propio saldo, para que la suma tenga algo que sumar.
        $otra = Ruta::create(['nombre' => 'Santa Ana']);
        $salaOtra = $this->sala($otra, 'Selectos Santa Ana');
        $this->documento($this->salida($otra), $this->ccf($salaOtra, 'DTE-03-M001P002-000000000000010', ['total_pagar' => 70.00]));

        $vista = app(BandejaDocumentos::class)->consultar([]);
        $porRuta = app(SaldoPorRuta::class)->agrupar($vista['documentos']);

        $this->assertCount(2, $porRuta);
        $this->assertSame(
            round($vista['dinero']['saldo'], 2),
            round($porRuta->sum(fn (array $f) => $f['saldo']), 2),
        );
        // Y cada documento con saldo aparece en UNA sola fila.
        $this->assertSame(
            $vista['dinero']['documentos_con_saldo'],
            $porRuta->sum(fn (array $f) => $f['documentos']),
        );

        // Ordenadas de mayor a menor: arriba la que más plata tiene trabada.
        $this->assertSame('San Miguel', $porRuta->first()['ruta']);
        $this->assertSame($ruta->id, $porRuta->first()['ruta_id']);
    }

    // ==================================================== bandas

    public function test_el_saldo_se_publica_partido_y_nunca_como_una_sola_cifra(): void
    {
        $this->escenario();

        $dinero = $this->actingAs($this->admin())
            ->get(route('rutas.dashboard'))
            ->assertOk()
            ->viewData('dinero');

        // 100 fuera de PPQ + 50 presentado sin pagar. El cobrado no deja saldo.
        $this->assertSame(150.00, $dinero['saldo']);
        $this->assertSame(100.00, $dinero['saldo_fuera_ppq']);
        $this->assertSame(50.00, $dinero['saldo_en_ppq']);
        $this->assertSame(30.00, $dinero['cobrado']);

        // Las dos mitades se muestran, y el total nunca aparece solo.
        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertSee('Fuera de PPQ')
            ->assertSee('En PPQ sin pagar')
            ->assertSee('$100.00')
            ->assertSee('$50.00');
    }

    public function test_la_calidad_del_dato_queda_visible(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        // Histórico P001 sin monto: no se sabe cuánto es y no puede sumar como cero.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'numero_control' => 'DTE-03-M001P001-000000000000999',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'sala_nombre' => 'Selectos San Miguel',
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        $this->assertSame(1, $respuesta->viewData('dinero')['sin_monto']);
        $respuesta->assertSee('Documentos sin monto')
            ->assertSee('quedan FUERA de las sumas de arriba', false);
    }

    public function test_sin_huecos_la_banda_de_calidad_lo_dice_en_vez_de_desaparecer(): void
    {
        $this->escenario();

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('los totales de arriba están completos', false);
    }

    public function test_los_tramos_de_antiguedad_salen_de_cobranza_y_no_de_la_vista(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        foreach (array_keys(Cobranza::TRAMOS) as $tramo) {
            $respuesta->assertSee($tramo.' días');
        }
        $respuesta->assertSee('Sin fecha');
    }

    public function test_la_documentacion_pendiente_usa_los_contadores_del_seguimiento(): void
    {
        $this->escenario();

        $vista = app(BandejaDocumentos::class)->consultar([]);
        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        $this->assertSame($vista['resumen']['sin_albaran'], $respuesta->viewData('resumen')['sin_albaran']);
        $this->assertSame(3, $respuesta->viewData('resumen')['sin_albaran']);
        $respuesta->assertSee('Sin albarán')->assertSee('Papel pendiente');
    }

    // ==================================================== enlaces

    public function test_cada_tarjeta_enlaza_a_la_bandeja_con_su_filtro(): void
    {
        $ruta = $this->escenario();

        // Con un hueco de dato a propósito: la tarjeta de saldo desconocido solo se
        // pinta cuando hay algo que declarar, así que sin esto su enlace no existiría.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => SalidaRuta::where('ruta_id', $ruta->id)->value('id'),
            'numero_control' => 'DTE-03-M001P001-000000000000999',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'sala_nombre' => 'Selectos San Miguel',
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        foreach ([
            ['saldo' => BandejaDocumentos::SALDO_CON],
            ['ppq' => BandejaDocumentos::PPQ_FUERA, 'saldo' => BandejaDocumentos::SALDO_CON],
            ['ppq' => BandejaDocumentos::PPQ_PENDIENTE, 'saldo' => BandejaDocumentos::SALDO_CON],
            ['saldo' => BandejaDocumentos::SALDO_DESCONOCIDO],
            ['entrega' => BandejaDocumentos::ENTREGA_SIN_ALBARAN],
            ['papel' => BandejaDocumentos::PAPEL_PENDIENTE],
            ['requiere_nc' => '1'],
            ['antiguedad' => '90+', 'saldo' => BandejaDocumentos::SALDO_CON],
        ] as $filtro) {
            // Sin `false`: el href va escapado en el HTML y el esperado tiene que
            // escaparse igual, o los `&` no coinciden.
            $respuesta->assertSee(
                route('rutas.documentos.index', $respuesta->viewData('enlaceBase') + $filtro),
            );
        }
    }

    public function test_los_enlaces_arrastran_las_fechas_la_ruta_y_la_sala(): void
    {
        $ruta = $this->escenario();
        $sala = ClienteSucursal::where('ruta_id', $ruta->id)->firstOrFail();

        $filtros = [
            'desde' => Carbon::today()->subDays(3)->toDateString(),
            'hasta' => Carbon::today()->addDays(3)->toDateString(),
            'ruta_id' => (string) $ruta->id,
            'sucursal_id' => (string) $sala->id,
        ];

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard', $filtros))->assertOk();

        // El contexto entero viaja en la base de todos los enlaces...
        $this->assertSame($filtros, $respuesta->viewData('enlaceBase'));

        // ...y el enlace concreto que se pinta lo lleva junto con su propio filtro.
        $destino = route('rutas.documentos.index', $filtros + ['saldo' => BandejaDocumentos::SALDO_CON]);
        $respuesta->assertSee($destino);
    }

    public function test_sin_fechas_escritas_los_enlaces_llevan_la_ventana_resuelta(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();
        $base = $respuesta->viewData('enlaceBase');

        // Aunque el usuario no escribió fechas, el enlace no puede quedar sin ellas: el
        // listado abriría un universo más grande que el número del que se hizo clic.
        $dias = (int) config('rutas.bandeja_dias', 60);
        $this->assertSame(Carbon::today()->subDays($dias)->toDateString(), $base['desde']);
        $this->assertSame(Carbon::today()->addDays($dias)->toDateString(), $base['hasta']);
    }

    // ==================================================== ventana

    public function test_la_ventana_por_defecto_es_la_misma_que_la_bandeja(): void
    {
        $dias = (int) config('rutas.bandeja_dias', 60);

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        $this->assertSame(Carbon::today()->subDays($dias)->toDateString(), $respuesta->viewData('desde')->toDateString());
        $this->assertSame(Carbon::today()->addDays($dias)->toDateString(), $respuesta->viewData('hasta')->toDateString());
    }

    public function test_mover_la_ventana_cambia_las_cifras(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);

        // Una salida de hace mucho, fuera de cualquier ventana corta.
        $vieja = $this->salida($ruta, Carbon::today()->subDays(200)->toDateString());
        $this->documento($vieja, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 500.00]));

        // Con la ventana por defecto no se ve.
        $porDefecto = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();
        $this->assertSame(0.0, $porDefecto->viewData('dinero')['saldo']);

        // Moviéndola hacia atrás, aparece.
        $ampliada = $this->actingAs($this->admin())
            ->get(route('rutas.dashboard', ['desde' => Carbon::today()->subDays(300)->toDateString()]))
            ->assertOk();
        $this->assertSame(500.00, $ampliada->viewData('dinero')['saldo']);
    }

    // ==================================================== agrupación

    public function test_una_ruta_sin_saldo_no_aparece_en_la_tabla(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->documento($this->salida($ruta), $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        $this->item('DTE-03-M001P002-000000000000001', 'pagado', 100.00);

        $porRuta = $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk()->viewData('porRuta');

        $this->assertCount(0, $porRuta);
        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertSee('Ninguna ruta tiene saldo pendiente en este período.');
    }

    public function test_la_fila_por_ruta_parte_el_saldo_y_marca_lo_mas_viejo(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        // Uno reciente fuera de PPQ y otro viejo ya presentado.
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', [
            'total_pagar' => 40.00,
            'fecha_emision' => Carbon::today()->subDays(120)->toDateString(),
        ]));
        $this->item('DTE-03-M001P002-000000000000002');

        $fila = app(SaldoPorRuta::class)
            ->agrupar(app(BandejaDocumentos::class)->consultar([])['documentos'])
            ->first();

        $this->assertSame(140.00, $fila['saldo']);
        $this->assertSame(100.00, $fila['fuera_ppq']);
        $this->assertSame(40.00, $fila['en_ppq']);
        $this->assertSame(2, $fila['documentos']);
        $this->assertSame('90+', $fila['tramo_viejo']);
        $this->assertSame(0, $fila['sin_fecha']);
    }

    // ==================================================== acceso y lectura

    public function test_ver_alcanza_para_entrar_y_no_hace_falta_gestionar(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('jefatura')->givePermissionTo('rutas.ver');

        $this->actingAs($usuario)->get(route('rutas.dashboard'))->assertOk();
    }

    public function test_mirar_el_dashboard_no_escribe_nada(): void
    {
        $this->escenario();

        $antes = SalidaRutaDocumento::query()->get()->map->only(['id', 'requiere_nc', 'documentacion_fisica_recibida_at']);

        $this->actingAs($this->admin())->get(route('rutas.dashboard'))->assertOk();

        $despues = SalidaRutaDocumento::query()->get()->map->only(['id', 'requiere_nc', 'documentacion_fisica_recibida_at']);
        $this->assertEquals($antes->toArray(), $despues->toArray());
    }

    public function test_los_contadores_de_ppq_no_se_calculan_restando(): void
    {
        // El caso que rompía la resta vieja: cobrado en un lote que después se retiró.
        // `en_ppq - pagados` habría dado -1; `total - en_ppq` lo habría mandado a la
        // bandeja de «falta ingresarlo», mandando a reclamar algo ya cobrado.
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        $item = $this->item('DTE-03-M001P002-000000000000001', 'pagado', 100.00);
        $item->lote->delete();

        $resumen = app(SeguimientoDocumentos::class)->resumen(
            app(BandejaDocumentos::class)->consultar([])['documentos']
        );

        $this->assertSame(0, $resumen['en_ppq']);
        $this->assertSame(1, $resumen['pagados']);
        // Ninguno de los dos es una resta, y por eso ninguno queda negativo ni de más.
        $this->assertSame(0, $resumen['en_ppq_sin_pagar']);
        $this->assertSame(0, $resumen['fuera_ppq']);
    }
}
