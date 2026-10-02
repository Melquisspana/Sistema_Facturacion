<?php

namespace Tests\Feature\Seguridad;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\GmailCuenta;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Autorización fina: lo que un permiso amplio dejaba ver o hacer de más.
 *
 * - La cuenta de un proveedor en Gastos mostraba obligaciones protegidas (sueldos de
 *   planilla) a quien solo tenía `gastos.ver`.
 * - Preparar el archivo de NC y descargar formatos ya preparados escribían por GET.
 * - El regreso de la autorización de Gmail no comprobaba `state`.
 */
class AutorizacionFinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function compra(User $u, string $importe): Gasto
    {
        return app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Juan Pérez',
            'concepto' => 'Compra', 'importe' => $importe, 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);
    }

    // ------------------------------------------------------------ Gastos

    public function test_la_cuenta_de_un_proveedor_no_incluye_obligaciones_protegidas(): void
    {
        config()->set('gastos.enabled', true);
        $u = $this->usuario(['gastos.ver', 'gastos.registrar']);
        $visible = $this->compra($u, '100.00');
        $protegido = $this->compra($u, '250.00');

        // La protección real (planilla) se sustituye por una que oculta un gasto concreto:
        // lo que se prueba es que la cuenta APLICA la protección, no cómo la calcula.
        $proteccion = Mockery::mock(ProteccionDeGastos::class);
        $proteccion->shouldReceive('ocultosPara')->andReturn(Gasto::query()->select('id')->whereKey($protegido->id)->toBase());
        $this->app->instance(ProteccionDeGastos::class, $proteccion);

        $ids = app(CuentaProveedor::class)->abiertas($u, 'Juan Pérez', 'USD')->pluck('gasto_id')->all();

        $this->assertSame([$visible->id], $ids);
    }

    // ------------------------------------------------------------ escrituras por GET

    public function test_preparar_el_archivo_de_nc_y_descargar_formatos_ya_no_aceptan_get(): void
    {
        $this->actingAs($this->usuario(['ppq.ver', 'ppq.gestionar']));

        $this->get('/ppq/lotes/1/archivo-nc')->assertStatus(405);
        $this->get('/ppq/nc-exportaciones/1/descargar')->assertStatus(405);
        $this->get('/cobros/solicitudes/1/descargar')->assertStatus(405);
    }

    // ------------------------------------------------------------ OAuth de Gmail

    private function gmailSimulado(bool $esperaConectar): void
    {
        $gmail = Mockery::mock(GmailClient::class);
        $gmail->shouldReceive('configurado')->andReturn(true);
        $gmail->shouldReceive('authUrl')->andReturnUsing(fn (?string $state = null) => 'https://accounts.example.test/auth?state='.$state);
        $esperaConectar
            ? $gmail->shouldReceive('conectar')->once()->andReturn(new GmailCuenta(['email' => 'cuenta@example.com']))
            : $gmail->shouldNotReceive('conectar');
        $this->app->instance(GmailClient::class, $gmail);
    }

    public function test_conectar_gmail_ata_un_state_aleatorio_a_la_sesion(): void
    {
        $this->gmailSimulado(false);

        $respuesta = $this->actingAs($this->usuario(['ppq.ver', 'ppq.gmail']))->get(route('ppq.gmail.conectar'));

        $state = session('ppq_gmail_oauth_state');
        $this->assertIsString($state);
        $this->assertGreaterThanOrEqual(40, strlen($state));
        $respuesta->assertRedirect('https://accounts.example.test/auth?state='.$state);
    }

    public function test_el_regreso_de_gmail_sin_el_state_de_esta_sesion_no_conecta_nada(): void
    {
        $this->gmailSimulado(false);

        $this->actingAs($this->usuario(['ppq.ver', 'ppq.gmail']))
            ->withSession(['ppq_gmail_oauth_state' => 'el-de-esta-sesion'])
            ->get(route('ppq.gmail.callback', ['code' => 'codigo-ajeno', 'state' => 'otro']))
            ->assertRedirect(route('ppq.index'))
            ->assertSessionHas('error');
    }

    public function test_el_regreso_de_gmail_con_el_state_correcto_conecta(): void
    {
        $this->gmailSimulado(true);

        $this->actingAs($this->usuario(['ppq.ver', 'ppq.gmail']))
            ->withSession(['ppq_gmail_oauth_state' => 'el-de-esta-sesion'])
            ->get(route('ppq.gmail.callback', ['code' => 'codigo', 'state' => 'el-de-esta-sesion']))
            ->assertRedirect(route('ppq.index'))
            ->assertSessionHas('status');
    }
}
