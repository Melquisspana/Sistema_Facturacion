<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rediseño del listado principal (solo interfaz: botones + buscador siempre visible +
 * panel de filtros avanzados colapsable + contador + accesos rápidos). No prueba lógica
 * de filtrado en sí (eso ya lo cubre DteListadoTest) ni cambia columnas/consultas.
 */
class DteListadoFiltrosUiTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function ver(array $params = [])
    {
        return $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.index', $params));
    }

    // --- Botones de creación ---

    public function test_los_cuatro_botones_de_creacion_aparecen(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();

        $this->assertStringContainsString('Nuevo CCF', $html);
        $this->assertStringContainsString('Nueva nota de crédito', $html);
        $this->assertStringContainsString('Nueva factura consumidor final', $html);
        $this->assertStringContainsString('Nueva factura exportación', $html);
    }

    public function test_los_botones_ya_no_tienen_badges_temporales(): void
    {
        $this->emisor();

        $this->ver()->assertOk()
            ->assertDontSee('En revisión')
            ->assertDontSee('Validada en APITEST')
            ->assertDontSee('Producción bloqueada');
    }

    /**
     * Los 4 botones deben ser SÓLIDOS, con altura/padding/radius/texto/foco compartidos
     * (misma clase base) — sin variante outline para Factura ni FEX.
     */
    public function test_los_cuatro_botones_comparten_la_clase_base_solida(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();
        $comunes = ['rounded-lg', 'shadow-sm', 'transition-colors', 'py-2.5', 'text-white', 'focus-visible:ring-2'];

        foreach (['facturacion.create-ccf', 'facturacion.create-nota-credito', 'facturacion.create-factura', 'facturacion.create-exportacion'] as $ruta) {
            $boton = $this->extraerBotonCreacion($html, $ruta);
            foreach ($comunes as $clase) {
                $this->assertStringContainsString($clase, $boton, "El botón de '{$ruta}' no comparte la clase base '{$clase}'.");
            }
        }
    }

    public function test_factura_consumidor_final_tiene_fondo_verde_solido(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();
        $boton = $this->extraerBotonCreacion($html, 'facturacion.create-factura');

        $this->assertStringContainsString('bg-emerald-600', $boton);
        $this->assertStringNotContainsString('bg-white', $boton);
        $this->assertStringNotContainsString('border-emerald-300', $boton);
        $this->assertStringNotContainsString('text-emerald-700', $boton);
    }

    public function test_fex_tiene_fondo_azul_solido(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();
        $boton = $this->extraerBotonCreacion($html, 'facturacion.create-exportacion');

        $this->assertStringContainsString('bg-sky-600', $boton);
        $this->assertStringNotContainsString('bg-white', $boton);
        $this->assertStringNotContainsString('border-sky-300', $boton);
        $this->assertStringNotContainsString('text-sky-700', $boton);
    }

    public function test_ccf_y_nota_credito_conservan_sus_colores_solidos(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();

        $this->assertStringContainsString('bg-indigo-600', $this->extraerBotonCreacion($html, 'facturacion.create-ccf'));
        $this->assertStringContainsString('bg-rose-600', $this->extraerBotonCreacion($html, 'facturacion.create-nota-credito'));
    }

    /** Extrae el HTML del botón de creación identificado por su ruta con nombre. */
    private function extraerBotonCreacion(string $html, string $nombreRuta): string
    {
        $href = preg_quote(route($nombreRuta), '/');
        preg_match('/<a\s+href="'.$href.'"[^>]*class="([^"]*)"/s', $html, $m);
        $this->assertNotEmpty($m, "No se encontró el botón para la ruta '{$nombreRuta}'.");

        return $m[1];
    }

    // --- Buscador siempre visible ---

    public function test_el_buscador_esta_siempre_visible_sin_filtros_activos(): void
    {
        $this->emisor();

        $this->ver()->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('Buscar por número, orden de compra, cliente o sala', false);
    }

    // --- Panel de filtros: existencia + estado inicial abierto/cerrado ---

    public function test_el_panel_de_filtros_existe_con_sus_campos(): void
    {
        $this->emisor();

        $this->ver()->assertOk()
            ->assertSee('name="tipo_dte"', false)
            ->assertSee('name="estado"', false)
            ->assertSee('name="cliente_id"', false)
            ->assertSee('name="fecha_desde"', false)
            ->assertSee('name="fecha_hasta"', false);
    }

    public function test_panel_comienza_cerrado_sin_filtros_activos(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/open:\s*false/', $html);
    }

    public function test_panel_comienza_abierto_con_filtro_de_tipo_activo(): void
    {
        $this->emisor();

        $html = $this->ver(['tipo_dte' => '03'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/open:\s*true/', $html);
    }

    public function test_panel_comienza_abierto_con_busqueda_activa(): void
    {
        $this->emisor();

        $html = $this->ver(['q' => 'OC-123'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/open:\s*true/', $html);
    }

    // --- Contador de filtros activos ---

    public function test_boton_filtros_no_muestra_contador_sin_filtros(): void
    {
        $this->emisor();

        $html = $this->ver()->assertOk()->getContent();
        $boton = $this->extraerBotonFiltros($html);

        // Sin filtros: el badge del contador no debe existir (el @if lo omite del todo).
        $this->assertStringNotContainsString('bg-indigo-600 text-white text-[11px]', $boton);
    }

    public function test_boton_filtros_cuenta_solo_filtros_con_valor_real(): void
    {
        $this->emisor();

        // tipo_dte + estado + cliente_id = 3; el value="" de un select "Todos" no cuenta
        // porque ni siquiera se envía como query param real cuando está vacío en el test.
        $cliente = Cliente::factory()->contribuyente()->create();
        $html = $this->ver(['tipo_dte' => '03', 'estado' => 'aceptado', 'cliente_id' => $cliente->id])
            ->assertOk()->getContent();

        $boton = $this->extraerBotonFiltros($html);
        $this->assertStringContainsString('>3<', $boton);
    }

    public function test_contador_no_cuenta_filtro_vacio_ni_todos(): void
    {
        $this->emisor();

        // tipo_dte='' (vacío, equivalente a "Todos") no debe sumar al contador.
        $html = $this->ver(['tipo_dte' => '', 'estado' => 'aceptado'])->assertOk()->getContent();

        $boton = $this->extraerBotonFiltros($html);
        $this->assertStringContainsString('>1<', $boton);
    }

    /** Extrae el HTML del botón "Filtros" (identificado por su x-click) para inspeccionar el contador. */
    private function extraerBotonFiltros(string $html): string
    {
        preg_match('/<button[^>]*@click="open = !open"[^>]*>.*?<\/button>/s', $html, $m);
        $this->assertNotEmpty($m, 'No se encontró el botón "Filtros" en el HTML.');

        return $m[0];
    }

    // --- Filtros siguen funcionando (parámetros actuales) ---

    public function test_los_filtros_actuales_siguen_funcionando_desde_la_nueva_interfaz(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
            'numero_orden_compra' => 'OC-NUEVA-UI',
        ]);

        $this->ver(['q' => 'OC-NUEVA-UI'])->assertOk()->assertSee('OC-NUEVA-UI');
        $this->ver(['tipo_dte' => '03'])->assertOk()->assertSee('OC-NUEVA-UI');
        $this->ver(['tipo_dte' => '11'])->assertOk()->assertDontSee('OC-NUEVA-UI');
    }

    // --- Accesos rápidos de estado ---

    public function test_los_accesos_rapidos_de_estado_siguen_disponibles(): void
    {
        $this->emisor();

        $this->ver()->assertOk()
            ->assertSee('Todos')
            ->assertSee('Aceptados')
            ->assertSee('Pendientes de emitir')
            ->assertSee('En edición')
            ->assertSee('Notas de crédito')
            ->assertSee('Anulados');
    }

    public function test_el_acceso_rapido_activo_se_distingue_visualmente(): void
    {
        $this->emisor();

        $html = $this->ver(['estado' => 'aceptado'])->assertOk()->getContent();

        // El chip activo usa fondo sólido indigo; los inactivos quedan en blanco/gris.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*estado=aceptado"[^>]*class="[^"]*bg-indigo-600[^"]*"[^>]*>Aceptados/',
            $html
        );
    }
}
