<?php

namespace Tests\Feature\Configuracion;

use App\Models\RespaldoEjecucion;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración › Sistema › «Generar respaldo ahora», por su RUTA
 * (`configuracion.sistema.respaldar`).
 *
 * Nunca corre un mysqldump de verdad ni escribe en la carpeta real de respaldos: el
 * proceso y el disco son dobles. Lo que se comprueba es que el botón corre el respaldo
 * manual, que queda registrado en el historial de respaldos —salga bien o mal— y que
 * sin el permiso `respaldos.ejecutar` no corre nada, aunque se administre la
 * configuración.
 */
class SistemaRespaldarRutaTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_BD = 'clave-de-prueba-de-la-base';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Sleep::fake();
        config(['database.connections.mysql.password' => self::CLAVE_BD]);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Administra la configuración, pero no puede ejecutar respaldos. */
    private function gestorSinRespaldos(): User
    {
        $rol = Role::findOrCreate('gestor_configuracion_prueba', 'web');
        $rol->syncPermissions([Permission::findOrCreate('configuracion.gestionar', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create(['activo' => true])->assignRole('gestor_configuracion_prueba');
    }

    private function dumpValido(): string
    {
        return "-- MySQL dump\nINSERT INTO prueba VALUES (1);\n-- Dump completed on 2026-11-25 10:00:00\n";
    }

    public function test_corre_el_respaldo_manual_y_queda_registrado(): void
    {
        Process::fake(['*' => Process::result(output: $this->dumpValido())]);

        $this->actingAs($this->admin())
            ->post(route('configuracion.sistema.respaldar'))
            ->assertRedirect(route('configuracion.sistema'))
            ->assertSessionHas('status', 'Respaldo generado y verificado. Revisá «último respaldo válido» abajo.');

        $respaldo = RespaldoEjecucion::query()->sole();
        $this->assertTrue($respaldo->exitoso);
        $this->assertSame('manual', $respaldo->origen);
        $this->assertSame(hash('sha256', $this->dumpValido()), $respaldo->sha256);
        Storage::disk('backups')->assertExists($respaldo->archivo_ruta);

        // Una sola corrida, con mysqldump, y la clave de la base nunca en la línea de comandos.
        Process::assertRanTimes(fn (PendingProcess $proceso) => str_contains(implode(' ', (array) $proceso->command), 'mysqldump'), 1);
        Process::assertDidntRun(fn (PendingProcess $proceso) => str_contains(implode(' ', (array) $proceso->command), self::CLAVE_BD));
    }

    public function test_si_el_respaldo_falla_avisa_y_tambien_queda_registrado(): void
    {
        config(['backup_diario.reintentos' => 0]);
        Process::fake(['*' => Process::result(output: '', errorOutput: 'mysqldump: error de prueba', exitCode: 2)]);

        $this->actingAs($this->admin())
            ->post(route('configuracion.sistema.respaldar'))
            ->assertRedirect(route('configuracion.sistema'))
            ->assertSessionHas('error', 'El respaldo terminó con código 1. Revisá el registro del servidor.');

        $respaldo = RespaldoEjecucion::query()->sole();
        $this->assertFalse($respaldo->exitoso);
        $this->assertSame('manual', $respaldo->origen);
        $this->assertNull($respaldo->archivo_ruta);
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    /**
     * El disco se llena a mitad de camino: guarda un pedazo y avisa que no pudo. Ese
     * respaldo no puede figurar como «generado y verificado»: queda registrado como
     * fallido y el pedazo no se queda en la carpeta.
     */
    public function test_si_el_disco_no_guarda_el_respaldo_entero_queda_como_fallido_y_sin_pedazos(): void
    {
        Process::fake(['*' => Process::result(output: $this->dumpValido())]);
        $falso = Storage::disk('backups');
        Storage::set('backups', new class($falso->getDriver(), $falso->getAdapter(), $falso->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                parent::put($path, substr((string) $contents, 0, 12), $options);

                return false;
            }
        });

        $this->actingAs($this->admin())
            ->post(route('configuracion.sistema.respaldar'))
            ->assertRedirect(route('configuracion.sistema'))
            ->assertSessionHas('error', 'El respaldo terminó con código 1. Revisá el registro del servidor.');

        $respaldo = RespaldoEjecucion::query()->sole();
        $this->assertFalse($respaldo->exitoso);
        $this->assertSame('manual', $respaldo->origen);
        $this->assertNull($respaldo->archivo_ruta);
        $this->assertNull($respaldo->sha256);
        $this->assertStringContainsString('no quedó entero', $respaldo->mensaje);
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_sin_respaldos_ejecutar_da_403_y_no_corre_nada(): void
    {
        Process::fake();

        // Administra la configuración: pasa el candado del grupo, no el del botón.
        $this->actingAs($this->gestorSinRespaldos())
            ->post(route('configuracion.sistema.respaldar'))
            ->assertForbidden();

        // Ni siquiera llega al grupo de Configuración.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('facturacion'))
            ->post(route('configuracion.sistema.respaldar'))
            ->assertForbidden();

        Process::assertNothingRan();
        $this->assertDatabaseCount('respaldo_ejecuciones', 0);
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_un_invitado_va_al_login_sin_respaldar(): void
    {
        Process::fake();

        $this->post(route('configuracion.sistema.respaldar'))->assertRedirect(route('login'));

        Process::assertNothingRan();
        $this->assertDatabaseCount('respaldo_ejecuciones', 0);
    }
}
