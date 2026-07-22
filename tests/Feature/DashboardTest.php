<?php

namespace Tests\Feature;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DocumentoRecibido;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard operativo (reemplaza el "You're logged in!" vacío de Breeze).
 * Todo lo que muestra sale de datos ya existentes (sin llamadas a Hacienda, sin
 * firmar, sin secretos). Cubre: carga para usuario autenticado, estadísticas
 * básicas, enlaces rápidos según permisos, y que no se filtre nada sensible.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return ['estab' => $estab, 'pv' => $pv];
    }

    private function dte(array $override = []): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE DASHBOARD SA']);

        return Dte::create(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P001-000000000000001',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'total_pagar' => 150.00,
        ], $override));
    }

    public function test_dashboard_carga_para_usuario_autenticado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_invitado_no_accede_al_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_encabezado_saluda_con_el_nombre_del_usuario_y_la_fecha(): void
    {
        $usuario = $this->usuario('administrador');

        $resp = $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $resp->assertSee($usuario->name);
        $resp->assertSee('Resumen operativo de Dulces La Negrita');
        $resp->assertSeeText(now()->year);
    }

    public function test_estadisticas_basicas_reflejan_datos_reales(): void
    {
        $this->dte(['total_pagar' => 100]);
        $this->dte(['total_pagar' => 250, 'numero_control' => 'DTE-03-M001P001-000000000000002']);
        DocumentoRecibido::create(['gmail_message_id' => 'm1', 'estado' => 'pendiente', 'fecha_correo' => now()]);
        DocumentoRecibido::create(['gmail_message_id' => 'm2', 'estado' => 'enviado', 'fecha_correo' => now()]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('DTE aceptados (mes)');
        $resp->assertSeeInOrder(['DTE aceptados (mes)', '2']); // 2 DTE aceptados creados arriba
        $resp->assertSee('350.00'); // suma de ventas del mes (100 + 250)
        $resp->assertSee('Compras pendientes');
        $resp->assertSeeInOrder(['Compras pendientes', '1']); // solo 1 en estado pendiente
    }

    public function test_actividad_reciente_muestra_el_dte_con_enlace_para_abrir(): void
    {
        $dte = $this->dte();

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Actividad reciente');
        $resp->assertSee('CLIENTE DASHBOARD SA');
        $resp->assertSee($dte->numero_control);
        $resp->assertSee(route('facturacion.show', $dte), false);
    }

    public function test_actividad_reciente_no_incluye_borradores(): void
    {
        $this->dte(['estado' => EstadoDte::Borrador->value, 'numero_control' => null]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Todavía no hay documentos enviados o aceptados este período.');
    }

    // ---------- Enlaces rápidos y permisos ----------

    public function test_gestor_dte_ve_acciones_de_creacion(): void
    {
        $resp = $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Nuevo CCF');
        $resp->assertSee('Nueva Factura');
        $resp->assertSee(route('facturacion.create-ccf'), false);
    }

    public function test_consulta_no_ve_acciones_de_creacion(): void
    {
        $resp = $this->actingAs($this->usuario('consulta'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee('Nuevo CCF');
        $resp->assertDontSee('Nueva Factura');
        $resp->assertDontSee('Nueva lista de empaque');
    }

    public function test_solo_administrador_ve_tarjeta_de_jobs_fallidos(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->assertSee('Jobs fallidos');
        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk()->assertDontSee('Jobs fallidos');
    }

    public function test_solo_gestor_dte_ve_el_estado_tecnico(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->assertSee('Estado técnico');
        $this->actingAs($this->usuario('consulta'))->get(route('dashboard'))->assertOk()->assertDontSee('Estado técnico');
    }

    // ---------- Nada sensible, nada de emisión real ----------

    public function test_no_expone_secretos_ni_credenciales(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee(config('app.key'));
        $resp->assertDontSee(env('DB_PASSWORD') ?: '__sin_password__');
        $resp->assertDontSeeText('DTE_FIRMADOR_MOCK');
        $resp->assertDontSeeText('APP_KEY');
    }

    public function test_dry_run_se_muestra_como_activo_y_no_se_apaga(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Dry-run');
        $resp->assertSee('ACTIVO');
        $this->assertTrue((bool) config('dte.transmision.dry_run'), 'DTE_TRANSMISION_DRY_RUN debe seguir activo.');
    }

    // ---------- Rutas existentes siguen funcionando ----------

    public function test_rutas_de_navegacion_existentes_siguen_respondiendo(): void
    {
        $admin = $this->usuario('administrador');

        foreach ([
            'dashboard', 'clientes.index', 'productos.index', 'facturacion.index',
            'documentos-recibidos.index', 'exportaciones.index', 'exportaciones.clientes.index',
        ] as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }
}
