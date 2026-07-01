<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * VISTA PREVIA (solo lectura) del EVENTO DE INVALIDACIÓN de un DTE aceptado: serializa
 * el evento (bloques identificacion/emisor/documento/motivo) y lo valida contra
 * `invalidacion-schema-v3.json`.
 *
 * FASE B: NO firma, NO transmite a /fesv/anulardte, NO cambia el estado del DTE, NO
 * toca sello_recepcion/respuesta_mh/fecha_procesamiento_mh. Con --guardar escribe un
 * JSON de MOCK/inspección en storage/app/dte/invalidacion/preview/ (nunca oficial).
 *
 * El responsable/solicitante se leen de config('dte.invalidacion.*') (env). El tipo
 * de anulación (CAT-024) se pasa EXPLÍCITO con --tipo (no se asume).
 */
class DteInvalidacionPreviewCommand extends Command
{
    protected $signature = 'dte:invalidacion-preview {dte : ID del DTE aceptado a invalidar}
        {--tipo=2 : Tipo de anulación CAT-024 (1=Error info, 2=Rescindir, 3=Otro)}
        {--motivo= : Motivo en texto (obligatorio para tipo 3)}
        {--reemplazo= : Código de generación del documento de reemplazo (obligatorio para tipo 1)}
        {--guardar : Guarda el evento de MOCK/inspección en storage/app/dte/invalidacion/preview}';

    protected $description = 'Vista previa del evento de invalidación de un DTE (serializa y valida contra el schema). No firma ni transmite.';

    public function handle(
        SerializadorInvalidacionMh $serializador,
        DteSchemaValidator $validador,
    ): int {
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

        $this->warn('*** SOLO PREVIEW — NO se firma, NO se transmite, NO se cambia el estado del DTE ***');
        $this->line('Evento de invalidación para DTE '.$dte->id.' ('.$dte->tipo_dte->label().') · tipoAnulacion='.$tipo->value.' ('.$tipo->label().')');
        $this->newLine();

        // 1) Serializar el evento (con candados de dominio).
        try {
            $evento_json = $serializador->serializar($dte, $evento);
        } catch (DteNoSerializableException $e) {
            $this->error('No se puede serializar el evento de invalidación:');
            foreach ($e->problemas as $p) {
                $this->line('  - '.$p);
            }

            return self::FAILURE;
        }

        // 2) Validar contra el schema oficial del evento.
        $res = $validador->validarInvalidacion($evento_json);
        switch ($res['estado']) {
            case 'valido':
                $this->info('✔ '.$res['mensaje']);
                break;
            case 'invalido':
                $this->warn('✘ '.$res['mensaje']);
                foreach (array_slice($res['errores'], 0, 40) as $err) {
                    $this->line('  - '.$err);
                }
                break;
            default:
                $this->warn($res['mensaje']);
        }

        // Aviso de responsable/solicitante pendiente (obligatorios en el schema).
        if (blank($evento->nombreResponsable) || blank($evento->nombreSolicita)) {
            $this->newLine();
            $this->warn('Aviso: responsable/solicitante del evento vacíos (config dte.invalidacion.*). '
                .'El schema los exige; complete DTE_INVALIDACION_* antes de invalidar de verdad.');
        }

        // 3) Mostrar / guardar (mock de inspección, nunca oficial).
        $json = json_encode($evento_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->option('guardar')) {
            $ruta = 'dte/invalidacion/preview/evento-'.$dte->tipo_dte->value.'-'.$dte->id.'-preview.json';
            Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $json);
            $this->newLine();
            $this->info('Evento de MOCK guardado en storage/app/'.$ruta.' (NO es oficial, NO se transmitió).');
        } else {
            $this->newLine();
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
