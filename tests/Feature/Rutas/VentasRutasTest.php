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

    private function ventas(array $filtros = []): array
    {
        return app(VentasRutas::class)->consultar($filtros);
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

    public function test_excluye_documentos_no_reales_y_facturas(): void
    {
        foreach ([['ambiente' => '00'], ['sello_recepcion' => 'MOCK-123'], ['estado' => 'invalidado'], ['estado' => 'borrador'], ['estado' => 'rechazado'], ['archivado' => true], ['tipo_dte' => '01']] as $datos) {
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
        $this->assertSame(['ccf' => 1, 'salas' => 1, 'nc' => -30.0], $v['contadores']);
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
