<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Dte;
use App\Services\Dte\DteInvalidacionMockService;
use Illuminate\Console\Command;

/**
 * FASE C — Firma MOCK del evento de invalidación de un DTE aceptado y persistencia
 * controlada en columnas dedicadas. NO transmite a Hacienda, NO cambia el estado de la
 * NC y NO toca la evidencia de recepción original.
 *
 * Sin --guardar: firma el mock y valida en memoria (dry-run, no escribe BD ni archivos).
 * Con --guardar: además persiste columnas nuevas + JSON/JWS en storage.
 *
 * El responsable/solicitante se leen de config('dte.invalidacion.*'). El tipo de
 * anulación (CAT-024) se pasa EXPLÍCITO con --tipo (no se asume).
 */
class DteInvalidacionMockCommand extends Command
{
    protected $signature = 'dte:invalidacion-mock {dte : ID del DTE aceptado a invalidar}
        {--tipo=2 : Tipo de anulación CAT-024 (1=Error info, 2=Rescindir, 3=Otro)}
        {--motivo= : Motivo en texto (obligatorio para tipo 3)}
        {--reemplazo= : Código de generación del documento de reemplazo (obligatorio para tipo 1)}
        {--guardar : Persiste columnas nuevas + JSON/JWS en storage}
        {--confirmar : Permite correr el mock aunque DTE_INVALIDACION_MOCK=false (nunca transmite)}';

    protected $description = 'Firma MOCK del evento de invalidación y persistencia en columnas dedicadas. No transmite ni cambia el estado del DTE.';

    public function handle(DteInvalidacionMockService $servicio): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

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

        $this->warn('*** MOCK — NO se transmite a /fesv/anulardte, NO se cambia el estado del DTE ***');

        try {
            $r = $servicio->firmarMock(
                $dte,
                $evento,
                persistir: (bool) $this->option('guardar'),
                permitirSinMock: (bool) $this->option('confirmar'),
            );
        } catch (DteInvalidacionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Evento de invalidación firmado en MOCK.');
        $this->table(['Campo', 'Valor'], [
            ['DTE', $dte->id.' ('.$dte->tipo_dte->label().')'],
            ['Estado DTE (sin cambios)', $r['estado_dte']],
            ['UUID del evento', $r['codigo_generacion_evento']],
            ['Tipo anulación (CAT-024)', $r['tipo_anulacion'].' ('.$tipo->label().')'],
            ['Sello invalidación (MOCK)', $r['sello_invalidacion']],
            ['JWS (preview)', $r['jws_preview']],
            ['Persistido en BD', $r['persistido'] ? 'sí' : 'no (dry-run; use --guardar)'],
            ['JSON path', $r['json_invalidacion_path'] ?? '—'],
            ['JWS path', $r['jws_invalidacion_path'] ?? '—'],
            ['Respuesta MH path', $r['respuesta_mh_invalidacion_path'] ?? '—'],
            ['¿Transmitió a Hacienda?', $r['transmitido'] ? 'SÍ' : 'NO'],
        ]);

        $this->newLine();
        $this->line('Confirmado: NO se transmitió a /fesv/anulardte y el estado del DTE sigue siendo "'.$r['estado_dte'].'".');

        return self::SUCCESS;
    }
}
