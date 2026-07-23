<?php

namespace Tests\Feature\Admin;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Panel "Salud del sistema" (solo administrador, solo lectura). No toca facturación.
 */
class SaludSistemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ver()
    {
        return $this->get(route('admin.salud-sistema'));
    }

    // --- Acceso ---

    public function test_invitado_no_puede_ver(): void
    {
        $this->ver()->assertRedirect('/login');
    }

    public function test_consulta_y_contador_no_pueden_ver(): void
    {
        $this->actingAs($this->usuario('consulta'))->ver()->assertForbidden();
        $this->actingAs($this->usuario('contador'))->ver()->assertForbidden();
        $this->actingAs($this->usuario('facturacion'))->ver()->assertForbidden();
    }

    public function test_administrador_si_puede_ver(): void
    {
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Salud del sistema')
            ->assertSee('Estado general')
            ->assertSee('Seguridad')
            ->assertSee('Diagnóstico operativo')
            ->assertSee('Datos principales')
            ->assertSee('Alertas de datos')
            ->assertSee('Auditoría reciente');
    }

    // --- Seguridad ---

    public function test_muestra_alerta_si_debug_true(): void
    {
        config(['app.debug' => true]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('APP_DEBUG=true no debe usarse en producción');
    }

    public function test_muestra_alerta_si_admin_temporal_activo(): void
    {
        User::factory()->create(['email' => 'admin@dulceslanegrita.test', 'activo' => true])->assignRole('administrador');

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Admin temporal')
            ->assertSee('crear admin real y darlo de baja');
    }

    // --- Datos ---

    public function test_muestra_conteos_de_clientes_productos_dtes(): void
    {
        Cliente::factory()->contribuyente()->create();
        Producto::factory()->create(['activo' => true]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Clientes activos')
            ->assertSee('Productos activos')
            ->assertSee('Documentos DTE (total)')
            ->assertSee('Notas de crédito');
    }

    // --- Backup (vía DiagnosticoSistemaService: registro real en respaldo_ejecuciones) ---

    public function test_detecta_backup_valido_de_hoy(): void
    {
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Backup del día')
            ->assertSee('backup automático/manual válido de hoy');
    }

    public function test_sin_backup_de_hoy_se_ve_critico(): void
    {
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Backup del día')
            ->assertSee('No hay un backup válido registrado hoy');
    }

    // --- Alertas ---

    public function test_detecta_productos_sin_codigo_de_barra(): void
    {
        Producto::factory()->create(['activo' => true, 'codigo_barra' => null]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Productos activos sin código de barra');
    }

    public function test_detecta_documentos_borrador_con_total_0(): void
    {
        // Borrador sin líneas => total 0.
        $empresa = Empresa::create(['razon_social' => 'X', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Documentos borrador con total 0');
    }

    // --- Enlace en el menú (solo administrador) ---

    public function test_link_en_menu_solo_para_administrador(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Salud del sistema');

        $this->actingAs($this->usuario('consulta'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('Salud del sistema');
    }

    // --- Seguridad: no exponer secretos ---

    public function test_no_muestra_secretos(): void
    {
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertDontSee(config('app.key'))
            ->assertDontSee('DB_PASSWORD');
    }

    // --- Clasificación general: correcto / advertencia / crítico (vía DiagnosticoSistemaService) ---

    public function test_diagnostico_operativo_todo_correcto(): void
    {
        // El banner GENERAL mezcla también "Seguridad" (APP_ENV/admins), que en
        // cualquier entorno de pruebas o desarrollo casi nunca es 100% "correcto" (p.
        // ej. APP_ENV nunca es 'production' fuera del servidor real — eso es
        // información válida, no un bug). Esta prueba aísla el DIAGNÓSTICO OPERATIVO
        // (el mismo que usa el Dashboard) y confirma que, con todo en verde, su nivel
        // es 'correcto' sin ningún check individual en advertencia/crítico.
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $resp = $this->actingAs($this->usuario('administrador'))->ver()->assertOk();

        $diagnostico = $resp->viewData('diagnostico');
        $this->assertSame('correcto', $diagnostico['nivel'], implode(' | ', array_map(
            fn ($c) => $c['clave'].'='.$c['nivel'], $diagnostico['checks']
        )));
    }

    public function test_estado_general_advertencia_no_es_critico(): void
    {
        // Todo operativo en verde, pero un único administrador activo: eso es una
        // advertencia real de seguridad (conviene tener respaldo), NUNCA crítico.
        config(['app.debug' => false]);
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        $admin = $this->usuario('administrador'); // único admin activo => advertencia de seguridad

        $resp = $this->actingAs($admin)->ver()->assertOk();
        $resp->assertSee('Administradores activos');
        $resp->assertDontSee('Atención inmediata: hay un problema real que revisar');
    }

    public function test_estado_general_critico_por_backup_vencido(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        // Sin ningún RespaldoEjecucion (nunca corrió el backup): crítico real.
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('Atención inmediata: hay un problema real que revisar');
    }

    public function test_no_muestra_lenguaje_viejo_de_conta_p001(): void
    {
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertDontSee('Conta Portable');
    }

    public function test_cola_vacia_o_transmision_deshabilitada_no_generan_falso_critico(): void
    {
        // Ambiente de desarrollo típico: transmisión deshabilitada, dry-run activo,
        // cola vacía, worker activo, backup de hoy. Nada de esto debe ser "crítico".
        config(['app.debug' => false, 'dte.transmision.enabled' => false, 'dte.transmision.dry_run' => true]);
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertDontSee('Atención inmediata: hay un problema real que revisar');
    }
}
