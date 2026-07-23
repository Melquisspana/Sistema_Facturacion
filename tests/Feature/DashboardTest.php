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
        $this->dte(['total_pagar' => 100, 'ambiente' => '01']);
        $this->dte(['total_pagar' => 250, 'numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01']);
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
        $dte = $this->dte(['ambiente' => '01']);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Documentos reales de producción');
        $resp->assertSee('CLIENTE DASHBOARD SA');
        $resp->assertSee($dte->numero_control);
        $resp->assertSee(route('facturacion.show', $dte), false);
    }

    public function test_actividad_reciente_no_incluye_borradores(): void
    {
        $this->dte(['estado' => EstadoDte::Borrador->value, 'numero_control' => null, 'ambiente' => '01']);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Todavía no hay documentos enviados o aceptados este período.');
    }

    // ---------- Documentos reales de producción (ambiente 01 fijo, NO el ambiente activo de la instalación) ----------

    public function test_solo_cuenta_documentos_aceptados_de_ambiente_produccion(): void
    {
        // La instalación puede estar en cualquier ambiente activo (aquí '00', típico de
        // desarrollo): las cifras de negocio SIEMPRE son de producción real ('01').
        config(['dte.ambiente' => '00']);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'total_pagar' => 100]);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '00', 'total_pagar' => 999]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSeeInOrder(['DTE aceptados (mes)', '1']); // solo el de producción (01)
        $resp->assertSee('100.00');
        $resp->assertDontSee('999.00'); // el de pruebas (00/APITEST) nunca suma
    }

    public function test_las_cifras_de_produccion_no_cambian_segun_el_ambiente_activo_de_la_instalacion(): void
    {
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'total_pagar' => 250]);

        config(['dte.ambiente' => '00']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['DTE aceptados (mes)', '1']);

        config(['dte.ambiente' => '01']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['DTE aceptados (mes)', '1']);
    }

    public function test_actividad_reciente_no_mezcla_ambientes(): void
    {
        $apitest = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '00', 'estado' => EstadoDte::Aceptado->value]);
        $produccion = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee($apitest->numero_control);
        $resp->assertSee($produccion->numero_control);
    }

    public function test_actividad_reciente_solo_muestra_aceptados_reales(): void
    {
        // Enviado/Rechazado: eventos reales del ciclo de vida, pero NO son "documentos
        // reales de producción" todavía (no confirmados/aceptados por Hacienda).
        $enviado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'estado' => EstadoDte::Enviado->value]);
        $rechazado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Rechazado->value]);
        $aceptado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000003', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee($enviado->numero_control);
        $resp->assertDontSee($rechazado->numero_control);
        $resp->assertSee($aceptado->numero_control);
    }

    public function test_rechazados_no_inflan_ventas_aceptadas(): void
    {
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value, 'total_pagar' => 100]);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Rechazado->value, 'total_pagar' => 5000]);
        $this->dte(['numero_control' => null, 'ambiente' => '01', 'estado' => EstadoDte::Borrador->value, 'total_pagar' => 8000]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $resp->viewData('stats')['dte_aceptados_mes']); // solo el aceptado cuenta
        $this->assertSame(100.0, $resp->viewData('stats')['ventas_mes']);   // rechazado/borrador no suman
        $resp->assertSeeInOrder(['Ventas del mes', '100.00']);
    }

    public function test_dte_145_aparece_sin_importar_el_ambiente_activo_de_la_instalacion(): void
    {
        // Reproduce el caso real: el CCF #145 (ambiente 01, aceptado real) debe verse
        // siempre en el panel del negocio, incluso si esta instalación corre en modo
        // pruebas/APITEST ('00').
        $produccion = $this->dte([
            'numero_control' => 'DTE-03-M001P002-000000000000001', 'ambiente' => '01',
            'estado' => EstadoDte::Aceptado->value, 'total_pagar' => 1.02,
        ]);

        config(['dte.ambiente' => '00']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSee($produccion->numero_control);

        config(['dte.ambiente' => '01']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSee($produccion->numero_control);
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

    // ---------- Diagnóstico real (Parte 4: dashboard y "atención inmediata") ----------

    public function test_gestor_ve_el_bloque_de_diagnostico_consulta_no(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Diagnóstico');
        $this->actingAs($this->usuario('consulta'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('Diagnóstico');
    }

    public function test_todo_en_orden_sin_datos_ni_problemas_reales(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Todo en orden');
    }

    public function test_failed_jobs_muestra_atencion_inmediata(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'fake', 'failed_at' => now(),
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Atención inmediata');
    }

    public function test_backup_vencido_muestra_atencion_inmediata_pero_cola_vacia_no(): void
    {
        // Sin ningún RespaldoEjecucion (nunca corrió el backup): crítico por backup,
        // NO por cola vacía (el worker sin datos + cola vacía es solo advertencia).
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Atención inmediata');
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
