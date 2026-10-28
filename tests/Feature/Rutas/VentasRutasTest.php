<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\VentasRutas;
use App\Services\Rutas\VentasRutasExcel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class VentasRutasTest extends TestCase
{
    use RefreshDatabase;

    private ClienteSucursal $sala;

    private Producto $producto;

    private Establecimiento $establecimiento;

    /** Correlativo de las fixtures: número de control con el formato y largo reales (31). */
    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'un']);
        $this->sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Centro', 'codigo' => '001']);
        $this->producto = Producto::factory()->create(['nombre' => 'Dulce']);
        $empresa = Empresa::create(['razon_social' => 'Pruebas', 'ambiente' => '01', 'activo' => true]);
        $this->establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
    }

    private function documento(float $monto = 100, array $datos = [], array $linea = []): DteLinea
    {
        $this->correlativo++;
        $tipo = $datos['tipo_dte'] ?? '03';
        $numero = 'DTE-'.$tipo.'-M001P001-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT);

        return Dte::withoutEvents(function () use ($monto, $datos, $linea, $numero) {
            $dte = Dte::create($datos + ['establecimiento_id' => $this->establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01', 'sello_recepcion' => Str::uuid()->toString(), 'fecha_procesamiento_mh' => now(), 'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id, 'numero_control' => $numero, 'fecha_emision' => '2026-10-05', 'hora_emision' => '10:00:00', 'subtotal' => $monto, 'total_descuento' => 0, 'total_pagar' => $monto]);

            return DteLinea::withoutEvents(fn () => DteLinea::create($linea + ['dte_id' => $dte->id, 'numero_linea' => 1, 'tipo_impuesto' => 'gravado', 'producto_id' => $this->producto->id, 'descripcion' => 'Dulce', 'cantidad' => 1, 'precio_unitario' => $monto, 'venta_gravada' => $monto, 'total_linea' => $monto]));
        });
    }

    public function test_canales_separados_y_nc_resta_en_el_canal_original(): void
    {
        $ccf = $this->documento(100);
        $fex = $this->documento(500, ['tipo_dte' => '11'], ['producto_id' => null, 'codigo' => 'CAJA', 'descripcion' => 'Caja exportada', 'venta_gravada' => 0, 'venta_exportacion' => 500]);
        $this->assertSame(100.0, $this->ventas()['totales']['actual']);
        $this->assertSame(500.0, $this->ventas(['canal' => 'exportacion'])['totales']['actual']);
        $this->documento(20, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-05'], ['dte_linea_original_id' => $ccf->id]);
        $this->assertSame(500.0, $this->ventas(['canal' => 'exportacion'])['totales']['actual']);
        $this->documento(50, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-05'], ['dte_linea_original_id' => $fex->id]);
        $v = $this->ventas(['canal' => 'exportacion']);
        $this->assertSame(450.0, $v['totales']['actual']);
        $this->assertSame(80.0, $this->ventas()['totales']['actual']);
        $this->assertSame('Caja exportada', $v['productos'][0]['nombre']);
        $this->assertSame($this->sala->cliente_id, $v['clientes'][0]['id']);
        $this->assertSame([], $v['salas']);
        $this->assertSame([], $v['rutas']);
    }

    public function test_exportacion_descuento_comparaciones_y_alerta_cliente(): void
    {
        foreach ([['2025-10-05', 80], ['2026-09-05', 100], ['2026-10-05', 70]] as [$fecha, $monto]) {
            $this->documento($monto, ['tipo_dte' => '11', 'fecha_emision' => $fecha, 'total_descuento' => $fecha === '2026-10-05' ? 7 : 0], ['producto_id' => null, 'descripcion' => 'Caja estable', 'venta_gravada' => 0, 'venta_exportacion' => $monto, 'cantidad' => 2]);
        }
        $v = $this->ventas(['canal' => 'exportacion']);
        $this->assertSame(63.0, $v['totales']['actual']);
        $this->assertSame(100.0, $v['productos'][0]['anterior']);
        $this->assertSame(80.0, $v['productos'][0]['anio_anterior']);
        $this->assertSame(80.0, $v['clientes'][0]['anio_anterior']);
        $this->assertCount(1, $v['alertas']);
        $this->assertSame(2.0, $v['productos'][0]['cantidad']);
        $this->assertSame(63.0, $v['tendencia'][11]['monto']);
    }

    public function test_fe_neta_con_sala_y_consumidor_final_con_exento_y_descuento(): void
    {
        $this->documento(113, ['tipo_dte' => '01', 'iva' => 13]);
        $uno = $this->documento(113, ['tipo_dte' => '01', 'subtotal' => 163, 'total_descuento' => 16.30, 'iva' => 11.70, 'cliente_sucursal_id' => null]);
        DteLinea::withoutEvents(fn () => DteLinea::create(['dte_id' => $uno->dte_id, 'numero_linea' => 2, 'descripcion' => 'Exento', 'cantidad' => 1, 'precio_unitario' => 50, 'tipo_impuesto' => 'exento', 'venta_exenta' => 50, 'total_linea' => 50]));
        $v = $this->ventas();
        $this->assertSame(235.0, $v['totales']['actual']);
        $this->assertSame(2, $v['contadores']['fe']);
        $salas = collect($v['salas'])->keyBy('nombre');
        $this->assertSame(100.0, $salas['Sala Centro']['actual']);
        $this->assertSame(135.0, $salas['Consumidor final']['actual']);
        $this->assertSame(235.0, array_sum(array_column($v['productos'], 'actual')));
    }

    public function test_canales_validacion_documentos_no_reales_y_permisos(): void
    {
        foreach (['01', '11'] as $tipo) {
            foreach ([['ambiente' => '00'], ['sello_recepcion' => 'MOCK-123'], ['estado' => 'invalidado'], ['archivado' => true]] as $datos) {
                $this->documento(100, $datos + ['tipo_dte' => $tipo], ['venta_exportacion' => 100]);
            }
        }
        foreach (['nacional', 'exportacion'] as $canal) {
            $this->assertSame(0.0, $this->ventas(['canal' => $canal])['totales']['actual']);
            foreach (['administrador', 'jefatura', 'facturacion', 'contabilidad', 'produccion', 'vendedor'] as $rol) {
                $this->actingAs(User::factory()->create()->assignRole($rol));
                foreach (['index', 'excel', 'pdf'] as $accion) {
                    $this->get(route('rutas.ventas.'.$accion, ['canal' => $canal]))->assertStatus(in_array($rol, ['administrador', 'jefatura', 'facturacion']) ? 200 : 403);
                }
            }
        }
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        foreach (['index', 'excel', 'pdf'] as $accion) {
            $this->getJson(route('rutas.ventas.'.$accion, ['canal' => 'todo']))->assertUnprocessable()->assertJsonValidationErrors('canal');
        }
    }

    public function test_prorrateo_multilinea_cierra_al_centavo_en_fe_y_fex(): void
    {
        foreach (['01', '11'] as $tipo) {
            $primera = $this->documento(1, ['tipo_dte' => $tipo, 'subtotal' => 3, 'total_descuento' => 0.01, 'iva' => $tipo === '01' ? 0.34 : 0], ['producto_id' => null, 'descripcion' => 'Caja A', 'venta_gravada' => $tipo === '01' ? 1 : 0, 'venta_exportacion' => $tipo === '11' ? 1 : 0]);
            foreach ([2, 3] as $numero) {
                DteLinea::withoutEvents(fn () => DteLinea::create(['dte_id' => $primera->dte_id, 'numero_linea' => $numero, 'descripcion' => 'Caja '.$numero, 'cantidad' => 1, 'precio_unitario' => 1, 'tipo_impuesto' => 'gravado', 'venta_gravada' => $tipo === '01' ? 1 : 0, 'venta_exportacion' => $tipo === '11' ? 1 : 0, 'total_linea' => 1]));
            }
            $v = $this->ventas(['canal' => $tipo === '11' ? 'exportacion' : 'nacional']);
            $this->assertSame($tipo === '11' ? 2.99 : 2.65, $v['totales']['actual']);
            $this->assertEqualsWithDelta($v['totales']['actual'], array_sum(array_column($v['productos'], 'actual')), 0.000001);
        }
    }

    public function test_selector_conserva_periodo_y_descarta_filtros_y_titulos_de_descargas(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->get(route('rutas.ventas.index', ['periodo' => 'mes_pasado', 'sala' => $this->sala->id]))
            ->assertOk()->assertSee(route('rutas.ventas.index', ['periodo' => 'mes_pasado', 'canal' => 'exportacion']));
        $this->get(route('rutas.ventas.index', ['canal' => 'exportacion', 'vista' => 'clientes', 'sala' => $this->sala->id]))
            ->assertOk()->assertSee('Ventas de exportación')->assertSee('USD, sin IVA')->assertSee('Presentaciones')->assertDontSee('Ficha de la sala')->assertDontSee('Agrupar ventas por ruta');
        foreach (['nacional' => 'nacionales', 'exportacion' => 'exportacion'] as $canal => $nombre) {
            foreach (['excel' => 'xlsx', 'pdf' => 'pdf'] as $accion => $extension) {
                $this->get(route('rutas.ventas.'.$accion, ['canal' => $canal]))->assertOk()->assertDownload('ventas-'.$nombre.'-2026-10.'.$extension);
            }
        }
    }

    public function test_excel_y_pdf_no_mezclan_canales(): void
    {
        $this->documento(100, [], ['descripcion' => 'Nacional exclusivo']);
        $this->documento(500, ['tipo_dte' => '11'], ['producto_id' => null, 'descripcion' => 'Exportacion exclusiva', 'venta_gravada' => 0, 'venta_exportacion' => 500]);
        foreach (['nacional', 'exportacion'] as $canal) {
            $v = $this->ventas(['canal' => $canal]);
            $path = app(VentasRutasExcel::class)->generar($v);
            try {
                $libro = IOFactory::load($path);
                $this->assertSame($canal === 'exportacion' ? ['Presentaciones', 'Clientes'] : ['Productos', 'Salas', 'Rutas'], $libro->getSheetNames());
                $this->assertEquals($canal === 'exportacion' ? 500 : 100, $libro->getActiveSheet()->getCell('C4')->getValue());
                $libro->disconnectWorksheets();
            } finally {
                unlink($path);
            }
            $html = view('rutas.ventas.pdf', $v + ['empresa' => Empresa::first()])->render();
            if ($canal === 'exportacion') {
                $this->assertStringContainsString('Exportacion exclusiva', $html);
                $this->assertStringNotContainsString('Dulce', $html);
                $this->assertStringNotContainsString('Totales por ruta', $html);
            } else {
                $this->assertStringNotContainsString('Exportacion exclusiva', $html);
            }
        }
    }

    private function ventas(array $filtros = []): array
    {
        return app(VentasRutas::class)->consultar($filtros);
    }

    public function test_anio_anterior_tendencia_y_excel(): void
    {
        $this->documento(80, ['fecha_emision' => '2025-10-05']);
        $original = $this->documento(120);
        $this->documento(20, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-02'], ['dte_linea_original_id' => $original->id]);
        $this->documento(50, ['fecha_emision' => '2026-08-05']);
        $v = $this->ventas();
        $this->assertSame(80.0, $v['totales']['anio_anterior']);
        $this->assertEquals(25, $v['totales']['porcentaje_anio']);
        $this->assertCount(12, $v['tendencia']);
        $this->assertSame('2025-11', $v['tendencia'][0]['mes']);
        $this->assertSame(0.0, $v['tendencia'][0]['monto']);
        $this->assertSame('2026-10', $v['tendencia'][11]['mes']);
        $this->assertSame(100.0, $v['tendencia'][11]['monto']);
        $otro = Producto::factory()->create();
        $this->assertSame(0.0, $this->ventas(['producto' => $otro->id])['tendencia'][11]['monto']);
        $path = app(VentasRutasExcel::class)->generar($v);
        try {
            $libro = IOFactory::load($path);
            $this->assertSame('Mismo período año anterior', $libro->getActiveSheet()->getCell('G3')->getValue());
            $this->assertSame(80.0, $libro->getActiveSheet()->getCell('G4')->getValue());
            $libro->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->actingAs(User::factory()->create()->assignRole('administrador'))->get(route('rutas.ventas.index'))
            ->assertOk()->assertSee('vs octubre 2025')->assertSee('polyline', false);
    }

    public function test_sin_anio_anterior_no_muestra_pastilla(): void
    {
        $this->documento();
        $this->assertNull($this->ventas()['totales']['porcentaje_anio']);
        $this->actingAs(User::factory()->create()->assignRole('administrador'))->get(route('rutas.ventas.index'))
            ->assertOk()->assertDontSee('vs octubre 2025');
    }

    public function test_devoluciones_excluyen_pronto_pago_y_ordenan_por_porcentaje(): void
    {
        $original = $this->documento(200);
        $this->documento(20, ['tipo_dte' => '05', 'tipo_nota_credito' => 'averia'], ['dte_linea_original_id' => $original->id]);
        $this->documento(30, ['tipo_dte' => '05', 'tipo_nota_credito' => 'pronto_pago'], ['dte_linea_original_id' => $original->id]);
        $otro = Producto::factory()->create(['nombre' => 'Otro']);
        $segundo = $this->documento(50, [], ['producto_id' => $otro->id]);
        $this->documento(10, ['tipo_dte' => '05'], ['dte_linea_original_id' => $segundo->id]);
        $v = $this->ventas(['vista' => 'devoluciones'])['devoluciones'];
        $this->assertSame(30.0, $v['total']);
        $this->assertSame(12.0, $v['porcentaje']);
        $this->assertSame([$otro->id, $this->producto->id], array_column($v['productos'], 'id'));
        $this->assertSame(10.0, $v['productos'][1]['porcentaje']);
        $this->assertSame(200.0, $v['productos'][1]['vendido']);
        $this->assertSame(20.0, $v['productos'][1]['devuelto']);
        $this->assertSame(30.0, $v['salas'][0]['devuelto']);
        $this->actingAs(User::factory()->create()->assignRole('jefatura'))->get(route('rutas.ventas.index', ['vista' => 'devoluciones']))
            ->assertOk()->assertSee('Devuelto $30.00');
    }

    public function test_ficha_sala_frecuencia_promedio_y_oportunidades(): void
    {
        $ruta = Ruta::create(['nombre' => 'Centro']);
        $this->sala->update(['ruta_id' => $ruta->id]);
        foreach (['2026-09-15', '2026-09-25', '2026-10-05'] as $fecha) {
            $this->documento(100, ['fecha_emision' => $fecha]);
        }
        $this->documento(30, ['tipo_dte' => '05']);
        $oportunidad = Producto::factory()->create(['nombre' => 'Oportunidad']);
        for ($i = 0; $i < 2; $i++) {
            $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Vecina '.$i, 'codigo' => '00'.$i, 'ruta_id' => $ruta->id, 'activo' => true]);
            $this->documento(40, ['cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id], ['producto_id' => $oportunidad->id]);
        }
        $ficha = $this->ventas(['sala' => $this->sala->id])['ficha_sala'];
        $this->assertSame('2026-10-05', $ficha['ultima_compra']);
        $this->assertSame(10, $ficha['dias']);
        $this->assertSame(10.0, $ficha['frecuencia']);
        $this->assertSame(90.0, $ficha['promedio']);
        $this->assertSame($oportunidad->id, $ficha['oportunidades'][0]['id']);
        $this->assertSame(2, $ficha['oportunidades'][0]['salas']);
        $this->assertSame(3, $ficha['oportunidades'][0]['total']);
        $this->actingAs(User::factory()->create()->assignRole('jefatura'))->get(route('rutas.ventas.index', ['sala' => $this->sala->id]))
            ->assertOk()->assertSee('hace 10 días')->assertSee('Lo compran 2 de 3 salas de la ruta');
        $this->documento(40, [], ['producto_id' => $oportunidad->id]);
        $this->assertSame([], $this->ventas(['sala' => $this->sala->id])['ficha_sala']['oportunidades']);
    }

    public function test_ficha_sin_frecuencia_y_sin_ruta(): void
    {
        $this->documento();
        $ficha = $this->ventas(['sala' => $this->sala->id])['ficha_sala'];
        $this->assertNull($ficha['frecuencia']);
        $this->assertSame([], $ficha['oportunidades']);
        $this->actingAs(User::factory()->create()->assignRole('jefatura'))->get(route('rutas.ventas.index', ['sala' => $this->sala->id]))
            ->assertOk()->assertSee('Sin datos suficientes')->assertDontSee('Oportunidades');
    }

    public function test_pdf_permisos_e_invitado(): void
    {
        $this->get(route('rutas.ventas.pdf'))->assertRedirect(route('login'));
        foreach (['administrador', 'jefatura', 'facturacion', 'contabilidad', 'produccion', 'vendedor'] as $rol) {
            $respuesta = $this->actingAs(User::factory()->create()->assignRole($rol))->get(route('rutas.ventas.pdf'));
            if (in_array($rol, ['administrador', 'jefatura', 'facturacion'])) {
                $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
                $this->assertStringStartsWith('%PDF', $respuesta->getContent());
            } else {
                $respuesta->assertForbidden();
            }
        }
    }

    public function test_tendencia_filtra_sala_ruta_cliente_y_completa_meses(): void
    {
        $ruta = Ruta::create(['nombre' => 'Centro']);
        $this->sala->update(['ruta_id' => $ruta->id]);
        $original = $this->documento(100, ['fecha_emision' => '2026-08-10']);
        $this->documento(20, ['tipo_dte' => '05', 'fecha_emision' => '2026-10-01'], ['dte_linea_original_id' => $original->id]);
        $otra = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Otra', 'codigo' => '002']);
        $this->documento(500, ['cliente_id' => $otra->cliente_id, 'cliente_sucursal_id' => $otra->id, 'fecha_emision' => '2026-08-11']);
        foreach ([['sala' => $this->sala->id], ['ruta' => $ruta->id], ['cliente' => $this->sala->cliente_id]] as $filtro) {
            $serie = $this->ventas($filtro)['tendencia'];
            $this->assertSame(80.0, $serie[9]['monto']);
            $this->assertSame(0.0, $serie[10]['monto']);
            $this->assertSame(0.0, $serie[11]['monto']);
        }
    }

    public function test_oportunidades_exigen_treinta_por_ciento_y_salas_activas_de_la_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'Centro']);
        $this->sala->update(['ruta_id' => $ruta->id, 'activo' => true]);
        $this->documento();
        $producto = Producto::factory()->create();
        for ($i = 0; $i < 7; $i++) {
            $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Vecina '.$i, 'codigo' => (string) $i, 'ruta_id' => $ruta->id, 'activo' => true]);
            $this->documento(40, ['cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id], ['producto_id' => $i < 2 ? $producto->id : $this->producto->id]);
        }
        $this->assertSame([], $this->ventas(['sala' => $this->sala->id])['ficha_sala']['oportunidades'], '2 de 8 no alcanza el 30 %.');
        $inactiva = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Inactiva', 'codigo' => '99', 'ruta_id' => $ruta->id, 'activo' => false]);
        $this->documento(40, ['cliente_id' => $inactiva->cliente_id, 'cliente_sucursal_id' => $inactiva->id], ['producto_id' => $producto->id]);
        $this->documento(40, ['fecha_emision' => '2026-01-01'], ['producto_id' => $producto->id]);
        $this->assertSame([], $this->ventas(['sala' => $this->sala->id])['ficha_sala']['oportunidades']);
    }

    public function test_pdf_resumen_a4_de_una_pagina_y_validacion_de_filtros(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $producto = Producto::factory()->create(['nombre' => str_repeat('Producto largo ', 6).$i]);
            $this->documento(100, ['fecha_emision' => '2026-09-05'], ['producto_id' => $producto->id]);
            $this->documento($i % 2 ? 50 : 150, [], ['producto_id' => $producto->id]);
        }
        for ($i = 0; $i < 20; $i++) {
            $ruta = Ruta::create(['nombre' => 'Ruta '.$i]);
            $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala '.$i, 'codigo' => (string) $i, 'ruta_id' => $ruta->id]);
            $this->documento(100, ['fecha_emision' => '2026-09-05', 'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id]);
            $this->documento(50, ['cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id]);
        }
        $datos = $this->ventas();
        $html = view('rutas.ventas.pdf', $datos + ['empresa' => Empresa::first()])->render();
        $this->assertStringContainsString('Ventas nacionales · Octubre 2026', $html);
        $this->assertStringContainsString('Totales por ruta', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdf->output();
        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->getJson(route('rutas.ventas.pdf', ['producto' => -1]))->assertUnprocessable();
        $this->get(route('rutas.ventas.pdf', ['producto' => $producto->id]))->assertOk()
            ->assertDownload('ventas-nacionales-2026-10.pdf');
    }

    public function test_permisos_en_ambos_endpoints(): void
    {
        foreach (['administrador', 'jefatura', 'facturacion', 'contabilidad', 'produccion', null] as $rol) {
            $user = User::factory()->create();
            if ($rol) {
                $user->assignRole($rol);
            }
            foreach (['index', 'excel'] as $accion) {
                $this->actingAs($user)->get(route('rutas.ventas.'.$accion))->assertStatus(in_array($rol, ['administrador', 'jefatura', 'facturacion']) ? 200 : 403);
            }
        }
    }

    public function test_invitado_va_al_login(): void
    {
        foreach (['index', 'excel'] as $accion) {
            $this->get(route('rutas.ventas.'.$accion))->assertRedirect(route('login'));
        }
    }

    public function test_descuento_global_se_prorratea_y_cuadra(): void
    {
        $uno = $this->documento(60, ['subtotal' => 100, 'total_descuento' => 10]);
        $otro = Producto::factory()->create();
        DteLinea::withoutEvents(fn () => DteLinea::create(['dte_id' => $uno->dte_id, 'numero_linea' => 2, 'tipo_impuesto' => 'gravado', 'producto_id' => $otro->id, 'descripcion' => 'Otro', 'cantidad' => 2, 'precio_unitario' => 20, 'venta_gravada' => 40, 'total_linea' => 40]));
        $v = $this->ventas();
        $this->assertSame(90.0, $v['totales']['actual']);
        $this->assertSame([54.0, 36.0], array_column($v['productos'], 'actual'));
    }

    public function test_resumen_de_productos_por_monto_cantidad_y_variacion(): void
    {
        $caro = Producto::factory()->create(['nombre' => 'Caro']);
        $barato = Producto::factory()->create(['nombre' => 'Barato']);
        // Septiembre: Dulce 50, Caro 300. Octubre: Dulce 100 (sube), Caro 120 en 1 u (baja), Barato 30 en 40 u.
        $this->documento(50, ['fecha_emision' => '2026-09-10']);
        $this->documento(300, ['fecha_emision' => '2026-09-10'], ['producto_id' => $caro->id]);
        $this->documento(100);
        $this->documento(120, [], ['producto_id' => $caro->id]);
        $this->documento(30, [], ['producto_id' => $barato->id, 'cantidad' => 40]);
        $this->documento(5, ['dte_relacionado_id' => null, 'tipo_dte' => '05'], ['producto_id' => null]);

        $resumen = $this->ventas()['resumen'];

        $this->assertSame(['Caro', 'Dulce', 'Barato'], array_column($resumen['monto'], 'nombre'), 'Por monto, sin los ajustes sin producto.');
        $this->assertSame('Barato', $resumen['cantidad'][0]['nombre']);
        $this->assertSame(['Dulce', 'Barato'], array_column($resumen['suben'], 'nombre'), 'Ordenado por cuánto subió: +50 y +30.');
        $this->assertSame(['Caro'], array_column($resumen['bajan'], 'nombre'));
        $this->assertSame(-180.0, $resumen['bajan'][0]['diferencia']);

        $this->actingAs(User::factory()->create()->assignRole('jefatura'))
            ->get(route('rutas.ventas.index', ['top' => 'cantidad']))
            ->assertOk()->assertSee('Ventas nacionales')->assertSee('Lo que más se vende')->assertSee('Bajaron');
    }

    public function test_nc_se_atribuye_al_ccf_por_linea_y_por_documento(): void
    {
        $original = $this->documento();
        $this->documento(30, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-02'], ['dte_linea_original_id' => $original->id]);
        $this->assertSame(70.0, $this->ventas()['totales']['actual']);
        $this->documento(10, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-03', 'dte_relacionado_id' => $original->dte_id], ['producto_id' => null]);
        $v = $this->ventas();
        $this->assertSame(60.0, $v['salas'][0]['actual']);
        $this->assertSame('Ajustes sin producto', $v['productos'][1]['nombre']);
        $this->assertSame(-10.0, $v['productos'][1]['actual']);
        $this->travelTo('2026-11-15 12:00:00');
        $this->assertSame(0.0, $this->ventas()['totales']['actual']);
    }

    public function test_excluye_documentos_no_reales(): void
    {
        foreach ([['ambiente' => '00'], ['sello_recepcion' => 'MOCK-123'], ['estado' => 'invalidado'], ['estado' => 'borrador'], ['estado' => 'rechazado'], ['archivado' => true]] as $datos) {
            $this->documento(100, $datos);
        }
        $this->assertSame(0.0, $this->ventas()['totales']['actual']);
    }

    public function test_referencia_de_linea_prevalece_en_fecha_sala_ruta_y_producto(): void
    {
        $original = $this->documento();
        $otraSala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Otra sala', 'codigo' => '002']);
        $otroProducto = Producto::factory()->create();
        $otroCcf = $this->documento(50, ['cliente_sucursal_id' => $otraSala->id, 'cliente_id' => $otraSala->cliente_id, 'fecha_emision' => '2026-09-10']);
        $this->documento(30, ['tipo_dte' => '05', 'fecha_emision' => '2026-11-01', 'cliente_sucursal_id' => $otraSala->id, 'dte_relacionado_id' => $otroCcf->dte_id], ['producto_id' => $otroProducto->id, 'dte_linea_original_id' => $original->id]);
        $v = $this->ventas(['producto' => $this->producto->id, 'sala' => $this->sala->id, 'ruta' => 0]);
        $this->assertSame(70.0, $v['totales']['actual']);
        $this->assertSame(0.0, $v['totales']['anterior']);
        $this->assertSame(0.0, $v['totales']['cantidad']);
        $this->assertSame(['ccf' => 1, 'fe' => 0, 'fex' => 0, 'salas' => 1, 'nc' => -30.0], $v['contadores']);
    }

    public function test_exenta_no_sujeta_y_redondeo_al_final_del_agregado(): void
    {
        $this->documento(0.01, ['subtotal' => 0.03, 'total_descuento' => 0.01], ['venta_gravada' => 0, 'venta_exenta' => 0.01, 'venta_no_sujeta' => 0.02]);
        $this->assertSame(0.02, $this->ventas()['totales']['actual']);
    }

    public function test_query_invalida_y_vistas_de_detalle(): void
    {
        $this->documento();
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        foreach ([['periodo' => 'otro'], ['periodo' => 'rango'], ['periodo' => 'rango', 'desde' => '2026-10-10', 'hasta' => '2026-10-01'], ['sala' => 'invalida'], ['cliente' => 999999], ['ruta' => -1], ['producto' => 'invalido'], ['vista' => 'otra']] as $filtro) {
            $this->getJson(route('rutas.ventas.index', $filtro))->assertUnprocessable();
            $this->getJson(route('rutas.ventas.excel', $filtro))->assertUnprocessable();
        }
        foreach ([['producto' => $this->producto->id], ['sala' => $this->sala->id], ['ruta' => 0], ['periodo' => 'rango', 'desde' => '2026-10-01', 'hasta' => '2026-10-31']] as $filtro) {
            $this->get(route('rutas.ventas.index', $filtro))->assertOk()->assertSee('role="img"', false);
        }
    }

    public function test_alerta_incluye_el_treinta_por_ciento_y_no_compro(): void
    {
        $this->documento(100, ['fecha_emision' => '2026-09-10']);
        $actual = $this->documento(70);
        $this->assertCount(1, $this->ventas()['alertas']);
        DteLinea::withoutEvents(fn () => $actual->update(['venta_gravada' => 71]));
        $this->assertCount(0, $this->ventas()['alertas']);
        DteLinea::withoutEvents(fn () => $actual->update(['venta_gravada' => 0]));
        $this->assertSame('No compró', $this->ventas()['alertas'][0]['alerta']);
    }

    public function test_ruta_actual_y_sin_ruta(): void
    {
        $this->documento();
        $this->assertSame('Sin ruta', $this->ventas()['rutas'][0]['nombre']);
        $ruta = Ruta::create(['nombre' => 'Occidente']);
        $this->sala->update(['ruta_id' => $ruta->id]);
        $this->assertSame('Occidente', $this->ventas()['rutas'][0]['nombre']);
    }

    public function test_periodos_calendario_y_rango_validado(): void
    {
        $p = $this->ventas()['periodo'];
        $this->assertSame('2026-09-01', $p['anterior_desde']->toDateString());
        $this->assertSame('2026-09-30', $p['anterior_hasta']->toDateString());
        $p = $this->ventas(['periodo' => 'tres_meses'])['periodo'];
        $this->assertSame('2026-08-01', $p['desde']->toDateString());
        $this->assertSame('2026-05-01', $p['anterior_desde']->toDateString());
        $p = $this->ventas(['periodo' => 'mes_pasado'])['periodo'];
        $this->assertSame('2026-09-01', $p['desde']->toDateString());
        $p = $this->ventas(['periodo' => 'rango', 'desde' => '2026-10-05', 'hasta' => '2026-10-06'])['periodo'];
        $this->assertSame('2026-10-03', $p['anterior_desde']->toDateString());
        $this->actingAs(User::factory()->create()->assignRole('administrador'))->getJson(route('rutas.ventas.index', ['periodo' => 'rango', 'desde' => '2025-01-01', 'hasta' => '2026-10-01']))->assertUnprocessable();
    }

    public function test_filtros_y_nc_sin_referencia_y_subtotal_cero(): void
    {
        $this->documento(20, ['subtotal' => 0]);
        $otro = Producto::factory()->create();
        $this->documento(50, [], ['producto_id' => $otro->id]);
        $this->documento(5, ['tipo_dte' => '05']);
        $v = $this->ventas(['producto' => $this->producto->id]);
        $this->assertCount(1, $v['salas']);
        $this->assertSame(15.0, $v['totales']['actual']);
        $this->assertSame(65.0, $this->ventas(['sala' => $this->sala->id, 'cliente' => $this->sala->cliente_id])['totales']['actual']);
    }

    public function test_excel_numerico_y_vista(): void
    {
        $this->documento(100, ['fecha_emision' => '2026-09-10']);
        $this->documento(70);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->get(route('rutas.ventas.index'))->assertOk()->assertSee('Vendido sin IVA')->assertSee('$70.00')->assertSee('compraron menos');
        $this->get(route('rutas.ventas.excel'))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = app(VentasRutasExcel::class)->generar($this->ventas());
        try {
            $libro = IOFactory::load($path);
            $this->assertSame(70.0, $libro->getSheetByName('Productos')->getCell('C4')->getValue());
            $this->assertSame('n', $libro->getSheetByName('Productos')->getCell('C4')->getDataType());
            $this->assertSame(['Productos', 'Salas', 'Rutas'], $libro->getSheetNames());
            $libro->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_carga_las_lineas_sin_n_mas_uno(): void
    {
        $this->documento();
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->ventas();
            $consultas = count(DB::getQueryLog());
            $this->documento();
            $this->documento(20, ['tipo_dte' => '05']);
            DB::flushQueryLog();
            $this->ventas();
            $this->assertSame($consultas, count(DB::getQueryLog()));
            $this->assertSame(1, $consultas);
        } finally {
            DB::disableQueryLog();
        }
    }
}
