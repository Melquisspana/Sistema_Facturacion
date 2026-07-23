<?php

namespace Tests\Feature\Console;

use App\Models\RespaldoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backup diario de MySQL: NUNCA ejecuta mysqldump real (Process::fake() en todos los
 * tests). Verifica la lógica de verificación (tamaño > 0 + marca "Dump completed"),
 * el registro en `respaldo_ejecuciones`, el SHA-256, y que la retención solo borra
 * dumps automáticos propios (prefijo `auto-`), nunca archivos manuales/protegidos.
 */
class BackupMysqlDiarioCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
    }

    private function dumpValido(): string
    {
        return "-- MySQL dump\nINSERT INTO foo VALUES (1);\n-- Dump completed on 2026-07-22 2:00:00\n";
    }

    public function test_backup_exitoso_registra_fila_exitosa_con_sha256_y_archivo(): void
    {
        Process::fake(['*' => Process::result(output: $this->dumpValido(), exitCode: 0)]);

        $this->artisan('backup:mysql-diario')->assertExitCode(0);

        $this->assertSame(1, RespaldoEjecucion::count());
        $r = RespaldoEjecucion::first();
        $this->assertTrue($r->exitoso);
        $this->assertSame('automatico', $r->origen);
        $this->assertNotNull($r->sha256);
        $this->assertSame(64, strlen($r->sha256));
        $this->assertSame(hash('sha256', $this->dumpValido()), $r->sha256);
        $this->assertStringStartsWith('auto-', $r->archivo_ruta);
        $this->assertStringEndsWith('.sql', $r->archivo_ruta);
        Storage::disk('backups')->assertExists($r->archivo_ruta);
        $this->assertGreaterThan(0, $r->archivo_tamano_bytes);
    }

    public function test_origen_manual_se_registra_correctamente(): void
    {
        Process::fake(['*' => Process::result(output: $this->dumpValido(), exitCode: 0)]);

        $this->artisan('backup:mysql-diario', ['--origen' => 'manual'])->assertExitCode(0);

        $this->assertSame('manual', RespaldoEjecucion::first()->origen);
    }

    public function test_sin_marca_dump_completed_falla_y_borra_el_archivo_parcial(): void
    {
        Process::fake(['*' => Process::result(output: "-- MySQL dump\nINSERT INTO foo VALUES (1);\n", exitCode: 0)]);

        $this->artisan('backup:mysql-diario')->assertExitCode(1);

        $r = RespaldoEjecucion::first();
        $this->assertFalse($r->exitoso);
        $this->assertStringContainsString('Dump completed', $r->mensaje);
        $this->assertNull($r->archivo_ruta);
        $this->assertEmpty(Storage::disk('backups')->allFiles());
    }

    public function test_salida_vacia_falla_por_tamano_cero(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        $this->artisan('backup:mysql-diario')->assertExitCode(1);

        $r = RespaldoEjecucion::first();
        $this->assertFalse($r->exitoso);
        $this->assertEmpty(Storage::disk('backups')->allFiles());
    }

    public function test_exit_code_distinto_de_cero_falla_sin_escribir_archivo(): void
    {
        Process::fake(['*' => Process::result(output: 'algun error', exitCode: 2)]);

        $this->artisan('backup:mysql-diario')->assertExitCode(1);

        $r = RespaldoEjecucion::first();
        $this->assertFalse($r->exitoso);
        $this->assertStringContainsString('código 2', $r->mensaje);
        $this->assertEmpty(Storage::disk('backups')->allFiles());
    }

    public function test_nunca_ejecuta_mysqldump_real_la_contrasena_no_va_en_el_argv(): void
    {
        config(['database.connections.mysql.password' => 'secreto-super-fake']);
        Process::fake(['*' => Process::result(output: $this->dumpValido(), exitCode: 0)]);

        $this->artisan('backup:mysql-diario')->assertExitCode(0);

        Process::assertRan(function (PendingProcess $process) {
            $comando = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            $this->assertStringNotContainsString('secreto-super-fake', $comando);
            $this->assertSame('secreto-super-fake', $process->environment['MYSQL_PWD'] ?? null);

            return true;
        });
    }

    public function test_retencion_borra_solo_auto_sql_viejos_y_respeta_manuales_y_recientes(): void
    {
        config(['backup_diario.dias_retencion' => 14]);
        $disco = Storage::disk('backups');

        // Automático VIEJO (20 días): debe borrarse.
        $disco->put('auto-2026-07-02_020000.sql', 'viejo');
        touch($disco->path('auto-2026-07-02_020000.sql'), now()->subDays(20)->getTimestamp());

        // Automático RECIENTE (2 días): debe conservarse.
        $disco->put('auto-2026-07-20_020000.sql', 'reciente');
        touch($disco->path('auto-2026-07-20_020000.sql'), now()->subDays(2)->getTimestamp());

        // Manual/protegido SIN el prefijo auto- (aunque sea viejo): NUNCA se borra.
        $disco->put('respaldo-manual-antes-de-migracion.sql', 'manual');
        touch($disco->path('respaldo-manual-antes-de-migracion.sql'), now()->subDays(90)->getTimestamp());

        Process::fake(['*' => Process::result(output: $this->dumpValido(), exitCode: 0)]);
        $this->artisan('backup:mysql-diario')->assertExitCode(0);

        $restantes = $disco->allFiles();
        $this->assertNotContains('auto-2026-07-02_020000.sql', $restantes);
        $this->assertContains('auto-2026-07-20_020000.sql', $restantes);
        $this->assertContains('respaldo-manual-antes-de-migracion.sql', $restantes);
    }
}
