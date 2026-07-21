<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Dte;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Console\Command;

/**
 * PREFLIGHT (solo lectura) previo a la invalidación real de un DTE aceptado: corre
 * TODA la lista de verificación antes de intentar transmitir a `/fesv/anulardte`.
 *
 * NO firma para envío, NO transmite, NO cambia estado, NO persiste. Solo diagnostica:
 * estado de la NC, aceptación real, endpoint apitest, validez del schema, tipo
 * explícito, responsable/solicitante, disponibilidad del firmador y del token, y los
 * flags actuales. El único HTTP que hace es el health-check GET del firmador (status).
 */
class DteInvalidacionPreflightCommand extends Command
{
    protected $signature = 'dte:invalidacion-preflight {dte : ID del DTE aceptado a invalidar}
        {--tipo= : Tipo de anulación CAT-024 (1=Error info, 2=Rescindir, 3=Otro) — OBLIGATORIO y explícito}
        {--motivo= : Motivo en texto (obligatorio para tipo 3)}
        {--reemplazo= : Código de generación del documento de reemplazo (obligatorio para tipo 1)}
        {--confirmo-nc-relacionada : Diagnostica como si ya se hubiera confirmado la NC relacionada}';

    protected $description = 'Checklist de preflight para la invalidación real (solo lectura). No firma ni transmite.';

    public function handle(DteInvalidacionService $inval, DteFirmaService $firma, DteTransmisionAuthService $auth): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        // tipoAnulacion EXPLÍCITO obligatorio.
        $tipoExplicito = ! ($this->option('tipo') === null || $this->option('tipo') === '');
        $tipo = $tipoExplicito ? TipoAnulacionMh::tryFrom((int) $this->option('tipo')) : null;
        if ($tipoExplicito && $tipo === null) {
            $this->error('Tipo de anulación inválido. Use 1 (Error info), 2 (Rescindir) o 3 (Otro).');

            return self::FAILURE;
        }
        // Para poder armar el evento igual necesitamos un tipo; si no vino, usamos 2 solo
        // para el diagnóstico, pero marcamos el check "tipo explícito" como fallido.
        $tipoDiag = $tipo ?? TipoAnulacionMh::RescindirOperacion;

        $evento = new EventoInvalidacionData(
            tipoAnulacion: $tipoDiag,
            nombreResponsable: config('dte.invalidacion.responsable.nombre') ?: null,
            tipoDocResponsable: config('dte.invalidacion.responsable.tipo_doc') ?: null,
            numDocResponsable: config('dte.invalidacion.responsable.num_doc') ?: null,
            nombreSolicita: config('dte.invalidacion.solicita.nombre') ?: null,
            tipoDocSolicita: config('dte.invalidacion.solicita.tipo_doc') ?: null,
            numDocSolicita: config('dte.invalidacion.solicita.num_doc') ?: null,
            motivoAnulacion: $this->option('motivo') ?: null,
            codigoGeneracionReemplazo: $this->option('reemplazo') ?: null,
        );

        $this->warn('*** PREFLIGHT — SOLO LECTURA. NO firma para envío, NO transmite, NO cambia el estado ***');
        $this->newLine();

        $checks = [];
        $ok = true;
        $add = function (bool $pasa, string $etiqueta, string $detalle) use (&$checks, &$ok) {
            $checks[] = [$pasa ? '✔' : '✘', $etiqueta, $detalle];
            $ok = $ok && $pasa;
        };

        $confirmoNc = (bool) $this->option('confirmo-nc-relacionada');

        // --- Estado de la NC ---
        $add($dte->estado === EstadoDte::Aceptado, 'NC existe y está aceptada', 'estado: '.$dte->estado->value);
        $add($dte->aceptadoRealmentePorMh(), 'Aceptada realmente por MH', $dte->aceptadoRealmentePorMh() ? 'sí (sello real + fecha MH)' : 'NO');
        $add(blank($dte->sello_invalidacion), 'sello_invalidacion sigue null', blank($dte->sello_invalidacion) ? 'sí' : 'ya tiene: '.$dte->sello_invalidacion);
        $add(! $dte->tieneEventoInvalidacion(), 'Sin evento de invalidación previo', $dte->tieneEventoInvalidacion() ? 'YA tiene' : 'sí');
        $add(! $dte->estaProtegidoComoEvidencia(), 'No protegido como evidencia',
            $dte->estaProtegidoComoEvidencia() ? 'PROTEGIDO — no se puede invalidar por ninguna vía' : 'no protegido');
        $add(! $dte->tieneNotaCreditoRelacionada() || $confirmoNc, 'Sin NC relacionada (o confirmado)',
            $dte->tieneNotaCreditoRelacionada()
                ? ($confirmoNc ? 'tiene NC relacionada — confirmado con --confirmo-nc-relacionada' : 'TIENE NC relacionada — posible doble corrección fiscal, falta --confirmo-nc-relacionada')
                : 'no tiene NC relacionada');

        // --- Evento / schema / endpoint (dry-run interno) ---
        try {
            $d = $inval->dryRun($dte, $evento, false, false, $confirmoNc);
            $add(str_contains($d['endpoint'], 'apitest.dtes.mh.gob.sv'), 'Endpoint apitest', $d['endpoint']);
            $add($d['schema']['valido'], 'Schema evento válido (v3)', $d['schema']['valido'] ? 'sí' : 'NO: '.implode(' | ', array_slice($d['schema']['errores'], 0, 4)));
            $candados = $d['candados'];
            $motivo = $d['evento']['motivo'] ?? [];
        } catch (DteInvalidacionException $e) {
            $add(false, 'Evento serializable', $e->getMessage());
            $d = null;
            $candados = ['bloqueado' => true, 'razones' => [$e->getMessage()]];
            $motivo = [];
        }

        $add($tipoExplicito, 'Tipo de anulación explícito', $tipoExplicito ? $tipoDiag->value.' ('.$tipoDiag->label().')' : 'FALTA --tipo (obligatorio)');

        // --- Responsable / solicitante (NO se inventan) ---
        $respOk = filled($evento->nombreResponsable) && filled($evento->tipoDocResponsable) && filled($evento->numDocResponsable);
        $solOk = filled($evento->nombreSolicita) && filled($evento->tipoDocSolicita) && filled($evento->numDocSolicita);
        $add($respOk, 'Responsable completo', $respOk ? $evento->nombreResponsable.' ('.$evento->tipoDocResponsable.')' : 'FALTAN DTE_INVALIDACION_RESP_*');
        $add($solOk, 'Solicitante completo', $solOk ? $evento->nombreSolicita.' ('.$evento->tipoDocSolicita.')' : 'FALTAN DTE_INVALIDACION_SOL_*');

        // --- Firmador real disponible (health GET, read-only) ---
        $firmaMock = (bool) config('dte.firma.mock', false);
        $firmaEnabled = (bool) config('dte.firma.enabled', false);
        $health = $firma->healthCheck();
        $add($health['disponible'] && $firmaEnabled && ! $firmaMock, 'Firmador real disponible',
            ($health['disponible'] ? 'firmador responde' : 'NO responde ('.$health['url'].')')
            .' · firma '.($firmaEnabled ? 'habilitada' : 'DESHABILITADA')
            .($firmaMock ? ' · MOCK activo' : ''));

        // --- Token MH disponible (diagnóstico, sin HTTP ni secretos) ---
        $diag = $auth->diagnostico();
        $tokenOk = $diag['token_manual_configurado'] || ($diag['usuario_configurado'] && $diag['password_configurado']) || $diag['token_cacheado'];
        $add($tokenOk, 'Token MH disponible', $tokenOk
            ? 'credenciales/token configurados ('.$diag['ambiente'].')'
            : 'faltan DTE_TRANSMISION_USER/PASSWORD o token');

        $this->table(['', 'Verificación', 'Detalle'], $checks);

        // --- Flags actuales del .env ---
        $this->newLine();
        $this->line('Flags actuales:');
        $this->table(['Flag', 'Valor'], [
            ['DTE_INVALIDACION_MOCK', $this->b(config('dte.invalidacion.mock'))],
            ['DTE_INVALIDACION_REAL_CONFIRMATION', $this->b(config('dte.invalidacion.real_confirmation'))],
            ['DTE_FIRMA_ENABLED', $this->b($firmaEnabled)],
            ['DTE_FIRMADOR_MOCK', $this->b($firmaMock)],
            ['DTE_TRANSMISION_AMBIENTE', (string) config('dte.transmision.ambiente')],
            ['DTE_TRANSMISION_TEST_ENABLED', $this->b(config('dte.transmision.test_enabled'))],
            ['DTE_TEST_ANULACION_URL', (string) config('dte.ambientes.00.anulacion_url') ?: '(vacío → default apitest)'],
        ]);

        // --- Bloque motivo del evento ---
        if ($motivo !== []) {
            $this->newLine();
            $this->line('Bloque motivo del evento:');
            $this->line((string) json_encode($motivo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // --- Candados que todavía bloquean la transmisión real ---
        $this->newLine();
        if ($candados['bloqueado']) {
            $this->warn('Candados que TODAVÍA bloquean la transmisión real:');
            foreach ($candados['razones'] as $r) {
                $this->line('    ✗ '.$r);
            }
        } else {
            $this->info('Candados de transmisión OK (faltarían solo --transmitir-real --confirmo-invalidar en el comando real).');
        }

        $this->newLine();
        $this->line('Confirmado: este preflight NO transmitió a /fesv/anulardte y NO cambió el estado de la NC.');
        $this->line($ok ? 'Preflight: TODO OK ✔' : 'Preflight: hay verificaciones pendientes ✘');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function b(mixed $v): string
    {
        return $v ? 'true' : 'false';
    }
}
