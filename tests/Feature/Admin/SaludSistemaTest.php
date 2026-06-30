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
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Panel "Salud del sistema" (solo administrador, solo lectura). No toca facturación.
 */
class SaludSistemaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) { @unlink($ruta); }
        parent::tearDown();
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
            ->assertSee('Backups')
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

    // --- Backups ---

    public function test_detecta_backup_mas_reciente_si_existe(): void
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.config('backup.backup.name', config('app.name')));
        File::ensureDirectoryExists($dir);
        $zip = $dir.DIRECTORY_SEPARATOR.'2026-06-17-02-00-00.zip';
        File::put($zip, 'contenido de prueba');
        $this->temporales[] = $zip;

        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('2026-06-17-02-00-00.zip')
            ->assertSee('Último backup');
    }

    public function test_detecta_scripts_de_backup_existentes(): void
    {
        // Los scripts reales están en el repo (scripts\*.bat).
        $this->actingAs($this->usuario('administrador'))->ver()
            ->assertOk()
            ->assertSee('scripts/backup-run.bat')
            ->assertSee('scripts/backup-restore-test.bat')
            ->assertSee('docs/BACKUPS_WINDOWS.md');
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
}
