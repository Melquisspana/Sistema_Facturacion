<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Dte;
use App\Services\Dte\DteInvalidacionService;
use Illuminate\Console\Command;

/**
 * FASE D — Transmisión REAL del evento de invalidación a `/fesv/anulardte` (SOLO apitest),
 * fuertemente candada. Por defecto NO transmite: exige `--transmitir-real` +
 * `--confirmo-invalidar` y los flags de entorno (DTE_INVALIDACION_MOCK=false,
 * DTE_INVALIDACION_REAL_CONFIRMATION=true, firma real habilitada).
 *
 * `--dry-run` muestra EXACTAMENTE qué se enviaría (evento, endpoint, cuerpo) y el estado
 * de todos los candados, SIN firmar ni transmitir.
 */
class DteInvalidacionRealCommand extends Command
{
    protected $signature = 'dte:invalidacion-real {dte : ID del DTE aceptado a invalidar}
        {--tipo= : Tipo de anulación CAT-024 (1=Error info, 2=Rescindir, 3=Otro) — OBLIGATORIO y explícito}
        {--motivo= : Motivo en texto (obligatorio para tipo 3)}
        {--reemplazo= : Código de generación del documento de reemplazo (obligatorio para tipo 1)}
        {--dry-run : Muestra qué se enviaría sin firmar ni transmitir}
        {--transmitir-real : Confirmación 1/2 para transmitir de verdad}
        {--confirmo-invalidar : Confirmación 2/2 para transmitir de verdad}
        {--confirmo-nc-relacionada : Confirma continuar aunque el documento tenga una Nota de Crédito relacionada (riesgo de doble corrección fiscal)}';

    protected $description = 'Transmisión REAL del evento de invalidación a anulardte (apitest), candada. Use --dry-run primero.';

    public function handle(DteInvalidacionService $servicio): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        // Guarda amable: si el DTE ya fue invalidado oficialmente, no se intenta nada.
        if ($dte->tieneEventoInvalidacion()) {
            $this->mostrarYaInvalidado($dte);

            return self::SUCCESS;
        }

        // tipoAnulacion EXPLÍCITO obligatorio (sin default para real).
        if ($this->option('tipo') === null || $this->option('tipo') === '') {
            $this->error('Debe indicar el tipo de anulación explícito: --tipo=1|2|3 (CAT-024). No se asume por defecto.');

            return self::FAILURE;
        }
        $tipo = TipoAnulacionMh::tryFrom((int) $this->option('tipo'));
        if ($tipo === null) {
            $this->error('Tipo de anulación inválido. Use 1 (Error info), 2 (Rescindir) o 3 (Otro).');

            return self::FAILURE;
        }

        $evento = new EventoInvalidacionData(
            tipoAnulacion: $tipo,
            nombreResponsable: config('dte.invalidacion.responsable.nombre') ?: null,
            tipoDocResponsable: config('dte.invalidacion.responsable.tipo_doc') ?: null,
            numDocResponsable: config('dte.invalidacion.responsable.num_doc') ?: null,
            nombreSolicita: config('dte.invalidacion.solicita.nombre') ?: null,
            tipoDocSolicita: config('dte.invalidacion.solicita.tipo_doc') ?: null,
            numDocSolicita: config('dte.invalidacion.solicita.num_doc') ?: null,
            motivoAnulacion: $this->option('motivo') ?: null,
            codigoGeneracionReemplazo: $this->option('reemplazo') ?: null,
        );

        $transmitirReal = (bool) $this->option('transmitir-real');
        $confirmoInvalidar = (bool) $this->option('confirmo-invalidar');
        $confirmoNcRelacionada = (bool) $this->option('confirmo-nc-relacionada');

        // Advertencia visible e independiente de los candados: se muestra SIEMPRE que
        // exista una NC relacionada, tanto en dry-run como antes de transmitir de verdad.
        if ($dte->tieneNotaCreditoRelacionada()) {
            $this->warn('⚠ Este documento ya tiene una Nota de Crédito relacionada. Invalidarlo oficialmente '
                .'además puede producir una DOBLE CORRECCIÓN FISCAL (la NC y el evento de invalidación cubriendo '
                .'la misma operación). Requiere --confirmo-nc-relacionada para transmitir.');
        }

        // --- DRY-RUN (default seguro si no se pide transmitir) ---
        if ($this->option('dry-run') || ! $transmitirReal || ! $confirmoInvalidar) {
            try {
                $d = $servicio->dryRun($dte, $evento, $transmitirReal, $confirmoInvalidar, $confirmoNcRelacionada);
            } catch (DteInvalidacionException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->warn('*** DRY-RUN — NO se firma, NO se transmite a anulardte, NO se cambia el estado ***');
            $this->newLine();
            $this->line('DTE: '.$dte->id.' ('.$dte->tipo_dte->label().') · estado '.$dte->estado->value.' · tipoAnulacion '.$tipo->value.' ('.$tipo->label().')');
            $this->line('Endpoint: '.$d['endpoint']);
            $this->line('Ambiente: '.$d['ambiente']);
            $this->line('Schema evento: '.($d['schema']['valido'] ? 'VÁLIDO ✔' : 'INVÁLIDO ✘'));
            foreach (array_slice($d['schema']['errores'], 0, 10) as $err) {
                $this->line('    - '.$err);
            }
            $this->newLine();
            if ($d['candados']['bloqueado']) {
                $this->warn('Candados que BLOQUEAN la transmisión real:');
                foreach ($d['candados']['razones'] as $r) {
                    $this->line('    ✗ '.$r);
                }
            } else {
                $this->info('Candados OK: transmitiría (vuelva a correr con --transmitir-real --confirmo-invalidar sin --dry-run).');
            }
            $this->newLine();
            $this->line('Cuerpo que se enviaría a anulardte:');
            $this->line((string) json_encode($d['cuerpo_envio'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->line('Evento (identificacion/emisor/documento/motivo):');
            $this->line((string) json_encode($d['evento'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // --- TRANSMISIÓN REAL (todos los candados los valida el servicio) ---
        $this->warn('*** TRANSMISIÓN REAL a /fesv/anulardte (apitest) ***');
        try {
            $r = $servicio->transmitir($dte, $evento, $transmitirReal, $confirmoInvalidar, $confirmoNcRelacionada);
        } catch (DteInvalidacionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Campo', 'Valor'], [
            ['Resultado', $r['resultado']],
            ['HTTP', (string) ($r['http_status'] ?? '—')],
            ['Mensaje', $r['mensaje']],
            ['Sello invalidación', $r['sello'] ?? '—'],
            ['Estado DTE', $r['estado_dte']],
            ['¿Invalidado?', $r['invalidado'] ? 'SÍ' : 'NO'],
        ]);

        return $r['resultado'] === 'aceptado' ? self::SUCCESS : self::FAILURE;
    }

    /** Mensaje amable cuando el DTE ya fue invalidado: NO regenera ni retransmite. */
    private function mostrarYaInvalidado(Dte $dte): void
    {
        $this->info('Este DTE ya fue invalidado oficialmente. No se regenera ni se retransmite.');
        $resp = $dte->respuesta_mh_invalidacion;
        $this->table(['Campo', 'Valor'], [
            ['DTE', $dte->id.' ('.$dte->tipo_dte->label().')'],
            ['Estado', $dte->estado->value],
            ['Sello invalidación', (string) ($dte->sello_invalidacion ?? '—')],
            ['Código generación evento', (string) ($dte->codigo_generacion_invalidacion ?? '—')],
            ['Tipo anulación', (string) ($dte->tipo_anulacion?->value ?? '—')],
            ['Fecha invalidación', (string) $dte->fecha_invalidacion],
            ['Fecha procesamiento MH', (string) $dte->fecha_procesamiento_invalidacion],
            ['Última respuesta MH', is_array($resp) ? ($resp['descripcionMsg'] ?? $resp['estado'] ?? '—') : '—'],
        ]);
        $this->line('Para ver el detalle completo (JSON/JWS/respuesta): php artisan dte:invalidacion-debug '.$dte->id);
    }
}
