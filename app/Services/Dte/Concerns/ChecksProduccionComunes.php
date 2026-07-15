<?php

namespace App\Services\Dte\Concerns;

use App\Models\Configuracion;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Checks de infraestructura de producción compartidos por los preflights de
 * readiness (CCF los tiene inline en PreflightEmisionProduccion; este trait los
 * reutiliza para Factura consumidor final y Exportación SIN tocar ese archivo).
 *
 * Requiere que la clase consumidora tenga App\Services\Dte\DteFirmaService en
 * $this->firma y App\Services\Dte\DteTransmisionService en $this->transmision
 * (mismo patrón de inyección que ya usa PreflightEmisionProduccion).
 *
 * SOLO LECTURA: ningún check firma, transmite, ni cambia estado/correlativos.
 */
trait ChecksProduccionComunes
{
    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkAmbiente(): array
    {
        $ambienteOk = (string) config('dte.ambiente') === '01';

        return $this->check('ambiente', 'Ambiente producción (01) activo', $ambienteOk,
            $ambienteOk ? 'dte.ambiente=01' : 'dte.ambiente='.config('dte.ambiente').' (no es producción)');
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkWorker(): array
    {
        $worker = WorkerHeartbeat::estado();
        $workerOk = ($worker['estado'] ?? null) === 'activo';

        return $this->check('worker', 'Worker/cola activo', $workerOk,
            $workerOk ? 'último pulso '.($worker['hace'] ?? '—') : 'worker apagado ('.($worker['estado'] ?? '—').')');
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkBackup(): array
    {
        $backupOk = $this->hayBackupDelDia();

        return $this->check('backup', 'Backup del día listo', $backupOk,
            $backupOk ? 'existe backup de hoy' : 'no hay backup de hoy');
    }

    /**
     * Health-check EN VIVO del firmador — solo si el ambiente ya es producción, para
     * no pegarle al firmador mientras se opera en modo seguro (mismo criterio que CCF).
     *
     * @return array{clave: string, label: string, ok: bool, detalle: string}
     */
    private function checkFirmador(bool $ambienteOk): array
    {
        if ($ambienteOk) {
            $h = $this->firma->healthCheck();
            $firmadorOk = (bool) ($h['disponible'] ?? false);
            $firmadorDet = $firmadorOk ? 'firmador disponible' : 'firmador no responde';
        } else {
            $firmadorOk = false;
            $firmadorDet = 'no evaluado (requiere ambiente producción)';
        }

        return $this->check('firmador', 'Firmador activo', $firmadorOk, $firmadorDet);
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkCandados(): array
    {
        $candadosOk = (bool) $this->transmision->estadoOperativo()['transmision_real_posible'];

        return $this->check('candados', 'Candados de producción correctos', $candadosOk,
            $candadosOk ? 'transmisión real habilitada' : 'transmisión real bloqueada (paralelo/mock/candados)');
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkCredenciales(): array
    {
        $credOk = Configuracion::getBool('produccion.auth_prod_validada', false);

        return $this->check('credenciales', 'Credenciales producción validadas', $credOk,
            $credOk ? 'validadas' : 'sin validar (correr dte:auth-test --prod y confirmar)');
    }

    private function hayBackupDelDia(): bool
    {
        $nombre = (string) config('backup.backup.name', config('app.name'));
        foreach (Storage::disk('local')->files($nombre) as $archivo) {
            if (! str_ends_with(strtolower($archivo), '.zip')) {
                continue;
            }
            $ts = Storage::disk('local')->lastModified($archivo);
            if (Carbon::createFromTimestamp($ts, config('app.timezone'))->isToday()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function check(string $clave, string $label, bool $ok, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'ok' => $ok, 'detalle' => $detalle];
    }
}
