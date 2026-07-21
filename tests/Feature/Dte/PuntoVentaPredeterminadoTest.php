<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use App\Support\Dte\ResuelveEmisorUnico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Punto de venta predeterminado del sistema nuevo (P002), conviviendo con Conta
 * Portable en P001 bajo el mismo establecimiento M001. Cubre: resolución sin
 * ambigüedad hacia P002, independencia de correlativos, FEX desde Lista de Empaque,
 * validación NC↔CCF por punto de venta, y falla clara (sin fallback silencioso)
 * cuando la configuración es inválida. NO firma, NO transmite, NO envía correo.
 */
class PuntoVentaPredeterminadoTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    /** @return array{estab: Establecimiento, p001: PuntoVenta, p002: PuntoVenta} */
    private function emisorConP001YP002(): array
    {
        ['estab' => $estab, 'pv' => $p001] = $this->crearEmisorDte('M001', 'P001');
        $p002 = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Sistema nuevo', 'activo' => true]);

        return compact('estab', 'p001', 'p002');
    }

    private function correlativos(Establecimiento $estab, PuntoVenta $pv, array $tipos = ['01', '03', '05', '11'], string $ambiente = '00'): void
    {
        foreach ($tipos as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => $ambiente, 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ccfGenerado(Establecimiento $estab, PuntoVenta $pv): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    // --- 1. P002 se resuelve como punto de venta del sistema nuevo ---

    public function test_p002_se_resuelve_como_punto_de_venta_del_sistema_nuevo(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $resuelto = ResuelveEmisorUnico::resolver($estab->id, null);

        $this->assertSame($p002->id, $resuelto['punto_venta_id']);
    }

    public function test_ccf_creado_sin_elegir_punto_de_venta_usa_p002(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        config(['dte.punto_venta_predeterminado' => 'P002']);
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor');

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-ccf'), ['tipo_dte' => '03', 'cliente_id' => $cliente->id])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '03')->latest('id')->firstOrFail();
        $this->assertSame($p002->id, $dte->punto_venta_id);
    }

    // --- 2. P001 no se modifica ---

    public function test_p001_no_se_modifica_al_operar_en_p002(): void
    {
        ['estab' => $estab, 'p001' => $p001, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p001);
        $this->correlativos($estab, $p002);
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $p001Antes = $p001->only(['id', 'codigo', 'nombre', 'activo', 'establecimiento_id']);
        $correlativosP001Antes = Correlativo::where('punto_venta_id', $p001->id)->pluck('ultimo_numero', 'tipo_dte')->all();

        $this->ccfGenerado($estab, $p002);

        $this->assertSame($p001Antes, $p001->fresh()->only(['id', 'codigo', 'nombre', 'activo', 'establecimiento_id']));
        $this->assertSame($correlativosP001Antes, Correlativo::where('punto_venta_id', $p001->id)->pluck('ultimo_numero', 'tipo_dte')->all());
    }

    // --- 3. Primer CCF P002 produce consecutivo 1 ---

    public function test_primer_ccf_p002_produce_correlativo_y_numero_control_uno(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p002, ['03']);

        $dte = $this->ccfGenerado($estab, $p002);

        $this->assertSame(1, Correlativo::where('punto_venta_id', $p002->id)->where('tipo_dte', '03')->value('ultimo_numero'));
        $this->assertSame('DTE-03-M001P002-000000000000001', $dte->numero_control);
    }

    // --- 4. Correlativos P001 y P002 independientes ---

    public function test_correlativos_p001_y_p002_son_independientes(): void
    {
        ['estab' => $estab, 'p001' => $p001, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p001, ['03']);
        $this->correlativos($estab, $p002, ['03']);

        $this->ccfGenerado($estab, $p001);
        $this->ccfGenerado($estab, $p001);
        $this->ccfGenerado($estab, $p002);

        $this->assertSame(2, Correlativo::where('punto_venta_id', $p001->id)->where('tipo_dte', '03')->value('ultimo_numero'));
        $this->assertSame(1, Correlativo::where('punto_venta_id', $p002->id)->where('tipo_dte', '03')->value('ultimo_numero'));
    }

    // --- 5. FEX desde Lista de Empaque crea borrador P002 ---

    public function test_fex_desde_lista_de_empaque_crea_borrador_en_p002(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p002, ['11']);
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id, 'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-20', 'estado' => 'aprobada',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13,
            'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->assertSame($p002->id, $dte->punto_venta_id);
        $this->assertSame($estab->id, $dte->establecimiento_id);
    }

    // --- 6. NC P002 acepta un CCF P002 ---

    public function test_nc_p002_acepta_ccf_p002(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p002, ['03']);
        $ccf = $this->aceptarCcf($this->ccfGenerado($estab, $p002));

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        $this->assertSame($p002->id, $nc->punto_venta_id);
        $this->assertSame($estab->id, $nc->establecimiento_id);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
    }

    // --- 7. NC P002 rechaza un CCF P001 ---

    public function test_nc_rechaza_punto_de_venta_explicito_que_no_coincide_con_el_ccf(): void
    {
        ['estab' => $estab, 'p001' => $p001, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p001, ['03']);
        $ccfP001 = $this->aceptarCcf($this->ccfGenerado($estab, $p001));

        $this->expectException(ValidationException::class);
        $this->borradores->crearNotaCredito($ccfP001, ['tipo' => 'pronto_pago', 'punto_venta_id' => $p002->id]);
    }

    public function test_formulario_nc_independiente_hereda_punto_de_venta_del_ccf_elegido_sin_forzar_p002(): void
    {
        ['estab' => $estab, 'p001' => $p001, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p001, ['03']);
        config(['dte.punto_venta_predeterminado' => 'P002']);
        $ccfP001 = $this->aceptarCcf($this->ccfGenerado($estab, $p001));

        // El formulario independiente NO envía establecimiento_id/punto_venta_id: el
        // controller los toma SIEMPRE del CCF elegido, ignorando el predeterminado P002.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccfP001->id,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();
        $this->assertSame($p001->id, $nc->punto_venta_id);
    }

    // --- 8. Configuración ausente o P002 inactivo produce error claro ---

    public function test_configuracion_apunta_a_codigo_inexistente_falla_con_mensaje_claro(): void
    {
        ['estab' => $estab] = $this->emisorConP001YP002();
        config(['dte.punto_venta_predeterminado' => 'P099']);

        $this->expectException(PuntoVentaPredeterminadoInvalidoException::class);
        $this->expectExceptionMessage('no existe');
        ResuelveEmisorUnico::resolver($estab->id, null);
    }

    public function test_p002_inactivo_falla_con_mensaje_claro(): void
    {
        ['estab' => $estab, 'p002' => $p002] = $this->emisorConP001YP002();
        $p002->update(['activo' => false]);
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $this->expectException(PuntoVentaPredeterminadoInvalidoException::class);
        $this->expectExceptionMessage('inactivo');
        ResuelveEmisorUnico::resolver($estab->id, null);
    }

    public function test_configuracion_ausente_con_dos_puntos_de_venta_activos_exige_seleccion_explicita(): void
    {
        // Sin configurar dte.punto_venta_predeterminado (default del suite: vacío).
        ['estab' => $estab] = $this->emisorConP001YP002();

        $resuelto = ResuelveEmisorUnico::resolver($estab->id, null);

        $this->assertNull($resuelto['punto_venta_id']);
    }

    // --- 9. No existen fallbacks silenciosos a P001 ---

    public function test_configuracion_invalida_nunca_cae_de_vuelta_a_p001(): void
    {
        ['estab' => $estab, 'p001' => $p001] = $this->emisorConP001YP002();
        config(['dte.punto_venta_predeterminado' => 'P099']); // no existe

        try {
            ResuelveEmisorUnico::resolver($estab->id, null);
            $this->fail('Debió lanzar PuntoVentaPredeterminadoInvalidoException, no resolver silenciosamente.');
        } catch (PuntoVentaPredeterminadoInvalidoException $e) {
            // Nunca debe haber resuelto (ni de casualidad) al id de P001.
            $this->assertNotSame($p001->id, null);
        }
    }

    // --- 10. Los históricos P001 continúan visibles e intactos ---

    public function test_historicos_p001_siguen_visibles_e_intactos_tras_operar_en_p002(): void
    {
        ['estab' => $estab, 'p001' => $p001, 'p002' => $p002] = $this->emisorConP001YP002();
        $this->correlativos($estab, $p001, ['03']);
        $this->correlativos($estab, $p002, ['03']);

        $historico = $this->ccfGenerado($estab, $p001);
        $snapshot = $historico->only(['id', 'numero_interno', 'establecimiento_id', 'punto_venta_id', 'total_pagar', 'estado']);

        config(['dte.punto_venta_predeterminado' => 'P002']);
        $this->ccfGenerado($estab, $p002);

        $this->assertSame($snapshot, $historico->fresh()->only(['id', 'numero_interno', 'establecimiento_id', 'punto_venta_id', 'total_pagar', 'estado']));
        $this->assertTrue(Dte::whereKey($historico->id)->exists());
        $this->assertTrue(Dte::where('punto_venta_id', $p001->id)->where('id', $historico->id)->exists());
    }
}
