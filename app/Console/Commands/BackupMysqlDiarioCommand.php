<?php

namespace App\Console\Commands;

use App\Models\RespaldoEjecucion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backup DIARIO de la base de datos vía `mysqldump` (single-transaction, rutinas,
 * triggers y eventos), VERIFICADO (tamaño > 0 + marca "Dump completed" al final) y con
 * SHA-256, registrado en `respaldo_ejecuciones` (fuente de verdad del readiness
 * "Backup del día listo"). Guarda el `.sql` en el disco `backups` (carpeta `backups/`
 * en la raíz del proyecto, NO versionada en git), con prefijo `auto-` para que la
 * retención automática NUNCA borre los dumps manuales que ya existen ahí.
 *
 * NO reemplaza a spatie/laravel-backup (zip completo app+BD, programado aparte en
 * routes/console.php): es un mecanismo NUEVO, específico para que el readiness de
 * producción tenga una fuente confiable (no un escaneo de archivos por fecha de
 * modificación, que tenía un bug de timezone documentado en el código anterior).
 *
 * La contraseña de BD NUNCA va en el argv del proceso (no aparece en `tasklist`/`ps`):
 * se pasa por la variable de entorno MYSQL_PWD del proceso hijo, y nunca se imprime
 * ni se loguea.
 */
class BackupMysqlDiarioCommand extends Command
{
    protected $signature = 'backup:mysql-diario
        {--origen=automatico : Origen del backup: automatico (tarea programada) o manual (contingencia)}';

    protected $description = 'Backup diario de MySQL verificado (mysqldump + SHA-256 + registro en BD). No reemplaza a spatie/laravel-backup.';

    public function handle(): int
    {
        $origen = $this->option('origen') === 'manual' ? 'manual' : 'automatico';
        $iniciadoEn = Carbon::now(config('app.timezone'));

        $prefijo = (string) config('backup_diario.prefijo_automatico', 'auto-');
        $nombre = $prefijo.$iniciadoEn->format('Y-m-d_His').'.sql';

        $this->line('Iniciando backup de BD ('.$origen.')...');

        try {
            $resultado = Process::timeout(300)->env($this->credencialesEnv())->run($this->comandoMysqldump());
        } catch (Throwable $e) {
            $this->registrar($iniciadoEn, false, null, null, null, 'Error al ejecutar mysqldump.', $origen);
            $this->error('Falló el backup: no se pudo ejecutar mysqldump.');

            return self::FAILURE;
        }

        if (! $resultado->successful()) {
            $this->registrar($iniciadoEn, false, null, null, null, 'mysqldump terminó con código '.$resultado->exitCode().'.', $origen);
            $this->error('Falló el backup: mysqldump terminó con error (código '.$resultado->exitCode().').');

            return self::FAILURE;
        }

        $salida = $resultado->output();
        Storage::disk('backups')->put($nombre, $salida);

        $tamano = (int) Storage::disk('backups')->size($nombre);
        $marcaOk = $this->terminaEnDumpCompleted($salida);

        if ($tamano <= 0 || ! $marcaOk) {
            $motivo = $tamano <= 0 ? 'el archivo generado está vacío' : 'no se encontró la marca "Dump completed" al final de la salida';
            Storage::disk('backups')->delete($nombre); // nunca dejar un dump parcial/corrupto en backups/
            $this->registrar($iniciadoEn, false, null, null, null, 'Backup inválido: '.$motivo.'.', $origen);
            $this->error('Falló la verificación del backup: '.$motivo.'. Se eliminó el archivo parcial.');

            return self::FAILURE;
        }

        $sha256 = hash('sha256', $salida);
        $this->registrar($iniciadoEn, true, $nombre, $tamano, $sha256, 'Backup completado correctamente.', $origen);
        $eliminados = $this->aplicarRetencion($prefijo);

        $this->info('Backup completado: '.$nombre.' ('.$tamano.' bytes, sha256 '.$sha256.').');
        if ($eliminados > 0) {
            $this->line($eliminados.' backup(s) automático(s) vencido(s) eliminado(s) por retención.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function comandoMysqldump(): array
    {
        $conn = (array) config('database.connections.mysql');
        $binDir = rtrim((string) ($conn['dump']['dump_binary_path'] ?? ''), '/\\');
        $binario = $binDir !== ''
            ? $binDir.DIRECTORY_SEPARATOR.'mysqldump'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '')
            : 'mysqldump';

        return [
            $binario,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--host='.($conn['host'] ?? '127.0.0.1'),
            '--port='.($conn['port'] ?? '3306'),
            '--user='.($conn['username'] ?? 'root'),
            (string) ($conn['database'] ?? ''),
        ];
    }

    /**
     * La contraseña viaja SOLO por variable de entorno del proceso hijo (nunca en el
     * argv, nunca impresa/logueada). Vacía si no hay password configurado (p. ej. root
     * sin contraseña en desarrollo).
     *
     * @return array<string, string>
     */
    private function credencialesEnv(): array
    {
        $password = (string) (config('database.connections.mysql.password') ?? '');

        return $password !== '' ? ['MYSQL_PWD' => $password] : [];
    }

    private function terminaEnDumpCompleted(string $salida): bool
    {
        $lineas = array_values(array_filter(
            array_map('rtrim', explode("\n", $salida)),
            fn ($l) => trim($l) !== ''
        ));

        return str_contains((string) end($lineas), 'Dump completed');
    }

    private function registrar(Carbon $iniciadoEn, bool $exitoso, ?string $ruta, ?int $tamano, ?string $sha256, string $mensaje, string $origen): void
    {
        RespaldoEjecucion::create([
            'iniciado_en' => $iniciadoEn,
            'terminado_en' => Carbon::now(config('app.timezone')),
            'exitoso' => $exitoso,
            'archivo_ruta' => $ruta,
            'archivo_tamano_bytes' => $tamano,
            'sha256' => $sha256,
            'mensaje' => $mensaje,
            'origen' => $origen,
        ]);
    }

    /** Borra SOLO backups/{prefijo}*.sql vencidos; nunca toca archivos sin ese prefijo. */
    private function aplicarRetencion(string $prefijo): int
    {
        $dias = (int) config('backup_diario.dias_retencion', 30);
        $limite = Carbon::now(config('app.timezone'))->subDays($dias);
        $disco = Storage::disk('backups');
        $eliminados = 0;

        foreach ($disco->files() as $archivo) {
            $base = basename($archivo);
            if (! str_starts_with($base, $prefijo) || ! str_ends_with($base, '.sql')) {
                continue;
            }
            $mtime = Carbon::createFromTimestamp($disco->lastModified($archivo), config('app.timezone'));
            if ($mtime->lt($limite)) {
                $disco->delete($archivo);
                $eliminados++;
            }
        }

        return $eliminados;
    }
}
