<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Preflight de EMISIÓN REAL A PRODUCCIÓN de un CCF. SOLO LECTURA: no emite, no
 * firma, no transmite, no toca correlativos ni .env. Reúne todas las precondiciones
 * de seguridad para habilitar la acción "Generar y transmitir producción".
 *
 * En modo NO producción (dte.ambiente != 01) devuelve `puede=false` de una y NO hace
 * las verificaciones caras (firmador/HTTP): así la ficha del CCF no le pega al
 * firmador mientras se opera en PARALELO SEGURO.
 */
class PreflightEmisionProduccion
{
    public function __construct(
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionService $transmision,
    ) {}

    /**
     * @return array{puede: bool, checks: array<int, array{clave: string, label: string, ok: bool, detalle: string}>, faltantes: array<int, string>}
     */
    public function evaluar(Dte $dte): array
    {
        $checks = [];

        $ambienteOk = (string) config('dte.ambiente') === '01';
        $checks[] = $this->check('ambiente', 'Ambiente producción (01) activo', $ambienteOk,
            $ambienteOk ? 'dte.ambiente=01' : 'dte.ambiente='.config('dte.ambiente').' (no es producción)');

        // Correlativo alineado: el contador interno NO debe ir por detrás del último
        // externo confirmado de Conta. El próximo operativo = max(interno, externo) + 1.
        $externo = (int) (Configuracion::get('produccion.ultimo_ccf_externo') ?? 1093);
        $corr = Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->where('activo', true)->first();
        $interno = (int) ($corr?->ultimo_numero ?? -1);
        $operativoProximo = max($interno, $externo) + 1;
        // OK si existe y el interno alcanzó/superó al externo (no está desalineado por detrás).
        $corrOk = $corr !== null && $interno >= $externo;
        $checks[] = $this->check('correlativo', "Próximo correlativo CCF producción = {$operativoProximo}", $corrOk,
            $corr ? "interno {$interno} · externo {$externo} · próximo {$operativoProximo}"
                    .($corrOk ? '' : ' (Conta va por delante: alinear)')
                  : 'no hay correlativo de producción');

        // Worker/cola activo (heartbeat).
        $worker = WorkerHeartbeat::estado();
        $workerOk = ($worker['estado'] ?? null) === 'activo';
        $checks[] = $this->check('worker', 'Worker/cola activo', $workerOk,
            $workerOk ? 'último pulso '.($worker['hace'] ?? '—') : 'worker apagado ('.($worker['estado'] ?? '—').')');

        // Backup del día (mismo disco/nombre que spatie/laravel-backup).
        $backupOk = $this->hayBackupDelDia();
        $checks[] = $this->check('backup', 'Backup del día listo', $backupOk,
            $backupOk ? 'existe backup de hoy' : 'no hay backup de hoy');

        // Firmador activo (health check EN VIVO) — solo en ambiente producción, para no
        // pegarle al firmador en modo paralelo/render normal.
        if ($ambienteOk) {
            $h = $this->firma->healthCheck();
            $firmadorOk = (bool) ($h['disponible'] ?? false);
            $firmadorDet = $firmadorOk ? 'firmador disponible' : 'firmador no responde';
        } else {
            $firmadorOk = false;
            $firmadorDet = 'no evaluado (requiere ambiente producción)';
        }
        $checks[] = $this->check('firmador', 'Firmador activo', $firmadorOk, $firmadorDet);

        // Candados de producción abiertos (transmisión real posible AHORA).
        $candadosOk = (bool) $this->transmision->estadoOperativo()['transmision_real_posible'];
        $checks[] = $this->check('candados', 'Candados de producción correctos', $candadosOk,
            $candadosOk ? 'transmisión real habilitada' : 'transmisión real bloqueada (paralelo/mock/candados)');

        // Credenciales de producción validadas (login-only OK; lo confirma el operador).
        $credOk = Configuracion::getBool('produccion.auth_prod_validada', false);
        $checks[] = $this->check('credenciales', 'Credenciales producción validadas', $credOk,
            $credOk ? 'validadas' : 'sin validar (correr dte:auth-test --prod y confirmar)');

        // Documento completo: CCF con cliente, productos y total > 0.
        $docOk = $dte->tipo_dte === TipoDte::CreditoFiscal
            && $dte->cliente_id !== null
            && $dte->lineas->isNotEmpty()
            && (float) $dte->total_pagar > 0;
        $checks[] = $this->check('documento', 'Documento completo (cliente, productos, total)', $docOk,
            $docOk ? 'listo' : 'faltan cliente/productos/total o no es CCF');

        $faltantes = array_values(array_map(
            fn ($c) => $c['label'],
            array_filter($checks, fn ($c) => ! $c['ok'])
        ));

        return [
            'puede' => $faltantes === [],
            'checks' => $checks,
            'faltantes' => $faltantes,
        ];
    }

    /**
     * Resumen para el modal de confirmación (solo lectura). Cliente, sala, OC, próximo
     * número oficial esperado, totales, retención y correo destino si existe.
     *
     * @return array<string, mixed>
     */
    public function resumen(Dte $dte): array
    {
        $externo = (int) (Configuracion::get('produccion.ultimo_ccf_externo') ?? 1093);
        $corr = Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->where('activo', true)->first();
        $interno = (int) ($corr?->ultimo_numero ?? 0);
        // Último operativo = max(interno, externo); el número que tomará este CCF = operativo + 1.
        $operativoUltimo = max($interno, $externo);

        return [
            'cliente' => $dte->cliente?->nombre,
            'sala' => $dte->clienteSucursal?->nombre,
            'oc' => $dte->numero_orden_compra,
            'operativo_ultimo' => $operativoUltimo,
            'proximo_numero' => $operativoUltimo + 1,
            'total_gravado' => (float) $dte->total_gravado,
            'iva' => (float) $dte->iva,
            'retencion' => (float) $dte->iva_retenido,
            'aplica_retencion' => (bool) $dte->aplica_retencion,
            'total_pagar' => (float) $dte->total_pagar,
            'correo_destino' => $dte->clienteSucursal?->correo ?: $dte->cliente?->correo,
        ];
    }

    private function hayBackupDelDia(): bool
    {
        $nombre = (string) config('backup.backup.name', config('app.name'));
        foreach (Storage::disk('local')->files($nombre) as $archivo) {
            if (! str_ends_with(strtolower($archivo), '.zip')) {
                continue;
            }
            $ts = Storage::disk('local')->lastModified($archivo);
            // OJO: sin la timezone de la app, createFromTimestamp() interpreta el epoch en
            // UTC y isToday() compara contra "hoy" en UTC, no en America/El_Salvador — un
            // backup real de hoy (hecho después de las 6pm hora local) queda marcado como
            // "no es de hoy" porque en UTC ya es el día siguiente.
            if (Carbon::createFromTimestamp($ts, config('app.timezone'))->isToday()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{clave: string, label: string, ok: bool, detalle: string}
     */
    private function check(string $clave, string $label, bool $ok, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'ok' => $ok, 'detalle' => $detalle];
    }
}
