<?php

namespace App\Services\Dte\Concerns;

use App\Models\Configuracion;
use App\Models\Dte;
use App\Models\RespaldoEjecucion;
use App\Support\Dte\CorrelativoSistemaNuevo;
use App\Support\WorkerHeartbeat;

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
        $worker = WorkerHeartbeat::diagnostico();

        return $this->check('worker', 'Worker/cola activo', $worker['nivel'] === 'correcto', $worker['mensaje']);
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function checkBackup(): array
    {
        $backupOk = RespaldoEjecucion::hayValidoHoy();

        return $this->check('backup', 'Backup del día listo', $backupOk,
            $backupOk ? 'existe un backup automático/manual válido de hoy' : 'no hay un backup válido registrado hoy');
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

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private function check(string $clave, string $label, bool $ok, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'ok' => $ok, 'detalle' => $detalle];
    }

    /**
     * Número de ESTE documento: el ya reservado (numeroControl) si el documento ya fue
     * generado, o el próximo que asignará el correlativo de producción si todavía es
     * borrador. Solo lectura: no consume ni modifica ningún correlativo.
     */
    private function documentoActual(Dte $dte, string $tipoDte): int
    {
        if ($dte->numero_control) {
            return (int) preg_replace('/\D+/', '', substr($dte->numero_control, -15));
        }

        return (int) (CorrelativoSistemaNuevo::correlativo($tipoDte, '01')?->ultimo_numero ?? 0) + 1;
    }

    /**
     * Datos generales para el resumen de confirmación, comunes a cualquier tipo de DTE
     * (tipo, ambiente, número de control, URL efectiva de transmisión, certificado
     * esperado, correo destino). Solo lectura/presentación: no muestra credenciales.
     *
     * @return array<string, mixed>
     */
    private function infoGeneral(Dte $dte): array
    {
        return [
            'tipo_dte' => $dte->tipo_dte->label(),
            'ambiente' => $dte->ambiente->value,
            'numero_control' => $dte->numero_control,
            'url_efectiva' => (string) config('dte.transmision.url_base'),
            'certificado_esperado' => (string) config('dte.transmision.ambiente') === 'produccion' ? 'Producción' : 'Pruebas',
            'correo_destino' => $dte->clienteSucursal?->correo ?: $dte->cliente?->correo,
        ];
    }
}
