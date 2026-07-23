<?php

namespace App\Services\Sistema;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\RespaldoEjecucion;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CorrelativoSistemaNuevo;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnóstico REAL del sistema para el Dashboard: reemplaza el semáforo que solo
 * miraba `estadoOperativo()['color']` + worker inactivo + jobs fallidos (como
 * "advertencia" nada más) por una lista de checks clasificados
 * correcto/advertencia/crítico, cada uno con su propia razón.
 *
 * NUNCA hace llamadas de red (ni a Hacienda ni al firmador): todo es config/cache/BD/
 * filesystem, para que el Dashboard no se quede esperando un servicio externo (mismo
 * principio que ya seguía DashboardController::estadoTecnico()). El health-check EN
 * VIVO del firmador sigue existiendo solo bajo demanda en "Preparar emisión real".
 *
 * Nunca marca crítico por: cola vacía, correo automático desactivado, ambiente/punto
 * de venta informativos, o no comparar contra el correlativo de Conta (P001).
 */
class DiagnosticoSistemaService
{
    public function __construct(
        private readonly DteTransmisionService $transmision,
    ) {}

    /**
     * @return array{nivel: string, checks: array<int, array{clave: string, label: string, nivel: string, detalle: string}>}
     */
    public function evaluar(): array
    {
        $checks = [
            $this->checkBd(),
            $this->checkWorker(),
            $this->checkJobsFallidos(),
            $this->checkBackup(),
            $this->checkFirmador(),
            $this->checkTransmision(),
            $this->checkAmbiente(),
            $this->checkCorrelativosP002(),
            $this->checkStorageLink(),
            $this->checkMigracionesPendientes(),
        ];

        return ['nivel' => $this->nivelGlobal($checks), 'checks' => $checks];
    }

    /** @param  array<int, array{nivel: string}>  $checks */
    private function nivelGlobal(array $checks): string
    {
        $nivel = 'correcto';
        foreach ($checks as $c) {
            if ($c['nivel'] === 'critico') {
                return 'critico';
            }
            if ($c['nivel'] === 'advertencia') {
                $nivel = 'advertencia';
            }
        }

        return $nivel;
    }

    private function checkBd(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->check('bd', 'Base de datos', 'correcto', 'Conexión establecida ('.config('database.default').').');
        } catch (Throwable) {
            return $this->check('bd', 'Base de datos', 'critico', 'No responde la base de datos.');
        }
    }

    private function checkWorker(): array
    {
        $d = WorkerHeartbeat::diagnostico();

        return $this->check('worker', 'Worker / cola', $d['nivel'], $d['mensaje']);
    }

    /** Disparador explícito de "atención inmediata": antes solo subía a advertencia. */
    private function checkJobsFallidos(): array
    {
        $n = (int) DB::table('failed_jobs')->count();

        return $this->check('jobs_fallidos', 'Trabajos fallidos', $n > 0 ? 'critico' : 'correcto',
            $n > 0 ? "Hay {$n} trabajo(s) fallido(s) en failed_jobs." : 'Sin trabajos fallidos.');
    }

    private function checkBackup(): array
    {
        $ok = RespaldoEjecucion::hayValidoHoy();

        return $this->check('backup', 'Backup del día', $ok ? 'correcto' : 'critico',
            $ok ? 'Hay un backup automático/manual válido de hoy.' : 'No hay un backup válido registrado hoy.');
    }

    /**
     * Sin ping en vivo (ver docblock de la clase). Crítico SOLO si la transmisión real
     * está posible AHORA (mismo criterio que `estadoOperativo()`) pero la firma no está
     * lista para eso: config inconsistente, no un chequeo de red.
     */
    private function checkFirmador(): array
    {
        $modo = $this->transmision->estadoOperativo();
        $firmaEnabled = (bool) config('dte.firma.enabled', false);
        $firmaMock = (bool) config('dte.firma.mock', false);

        if (($modo['transmision_real_posible'] ?? false) && (! $firmaEnabled || $firmaMock)) {
            return $this->check('firmador', 'Firmador', 'critico',
                'La transmisión real a producción está habilitada, pero la firma real no lo está (deshabilitada o en mock).');
        }

        return $this->check('firmador', 'Firmador', 'correcto',
            $firmaEnabled ? ($firmaMock ? 'Habilitado en modo mock.' : 'Habilitado (real).') : 'Deshabilitado (fase de preparación).');
    }

    private function checkTransmision(): array
    {
        $modo = $this->transmision->estadoOperativo();
        $nivel = match ($modo['color'] ?? 'ok') {
            'critico' => 'critico',
            'advertencia' => 'advertencia',
            default => 'correcto',
        };

        return $this->check('transmision', 'Modo de transmisión', $nivel, (string) ($modo['detalle'] ?? ''));
    }

    /** Informativo: nunca crítico por usar P002 separado de Conta. */
    private function checkAmbiente(): array
    {
        $ambiente = AmbienteHacienda::tryFrom((string) config('dte.ambiente'))?->label() ?? (string) config('dte.ambiente');
        $pv = (string) (config('dte.punto_venta_predeterminado') ?: 'automático (único punto activo)');

        return $this->check('ambiente', 'Ambiente y punto de venta', 'correcto',
            'Ambiente '.$ambiente.' · punto de venta predeterminado '.$pv.'.');
    }

    /**
     * Crítico SOLO si ya se emitió en producción un tipo de DTE que hoy NO tiene un
     * correlativo activo del sistema nuevo (P002) — inconsistencia real de datos.
     * Nunca compara contra el correlativo de Conta (P001).
     */
    private function checkCorrelativosP002(): array
    {
        $tipos = [TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::NotaCredito, TipoDte::FacturaExportacion];
        $inconsistencias = [];

        foreach ($tipos as $tipo) {
            $seUsoEnProduccion = Dte::where('tipo_dte', $tipo->value)
                ->where('ambiente', AmbienteHacienda::Produccion->value)
                ->where('estado', EstadoDte::Aceptado->value)
                ->exists();

            if ($seUsoEnProduccion && CorrelativoSistemaNuevo::correlativo($tipo->value, AmbienteHacienda::Produccion->value) === null) {
                $inconsistencias[] = $tipo->label();
            }
        }

        if ($inconsistencias !== []) {
            return $this->check('correlativos_p002', 'Correlativos de producción (sistema nuevo)', 'critico',
                'Hay documentos aceptados en producción sin correlativo activo de P002 para: '.implode(', ', $inconsistencias).'.');
        }

        return $this->check('correlativos_p002', 'Correlativos de producción (sistema nuevo)', 'correcto',
            'Consistentes con los documentos ya emitidos.');
    }

    private function checkStorageLink(): array
    {
        $ok = is_dir(public_path('storage'));

        return $this->check('storage_link', 'Enlace de storage', $ok ? 'correcto' : 'critico',
            $ok ? 'public/storage existe.' : 'Falta "php artisan storage:link": public/storage no existe.');
    }

    private function checkMigracionesPendientes(): array
    {
        $aplicadas = DB::table('migrations')->pluck('migration')->all();
        $archivos = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn (string $p) => basename($p, '.php'))
            ->values()->all();
        $pendientes = array_values(array_diff($archivos, $aplicadas));

        if ($pendientes !== []) {
            return $this->check('migraciones', 'Migraciones pendientes', 'critico',
                count($pendientes).' migración(es) sin aplicar.');
        }

        return $this->check('migraciones', 'Migraciones pendientes', 'correcto', 'Todas las migraciones están aplicadas.');
    }

    /** @return array{clave: string, label: string, nivel: string, detalle: string} */
    private function check(string $clave, string $label, string $nivel, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'nivel' => $nivel, 'detalle' => $detalle];
    }
}
