<?php

namespace App\Observers;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Facades\Ajustes;
use App\Models\Cobros\CobroDocumento;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\EnvioDteCorreoService;
use App\Services\Ppq\NcPosterioresLotePpq;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inmutabilidad del DTE a nivel de modelo (defensa que no depende del controller).
 *
 * - En borrador: todo cambio permitido.
 * - Fuera de borrador: solo se permiten los campos propios de una transición de
 *   estado / emisión (estado y metadatos MH). Cualquier cambio de CONTENIDO
 *   (cliente, totales, condición, etc.) se bloquea.
 * - Solo se pueden eliminar borradores.
 */
class DteObserver
{
    /**
     * Campos que SÍ pueden cambiar en un documento ya emitido (los toca la
     * máquina de estados y, más adelante, la generación/envío a Hacienda).
     */
    private const CAMPOS_PERMITIDOS_FUERA_DE_BORRADOR = [
        'estado',
        'numero_control',
        'ejercicio', // se fija junto con numero_control (#42)
        'codigo_generacion',
        'sello_recepcion',
        'respuesta_mh',
        'respuesta_mh_path',
        'json_generado_path',
        'json_firmado_path',
        'pdf_path',
        'fecha_procesamiento_mh',
        'generado_by',
        'enviado_by',
        'invalidado_by',
        // Anulación interna (no es contenido del documento).
        'motivo_anulacion',
        'observacion_anulacion',
        'fecha_anulacion',
        // Archivado de un RECHAZADO: solo lo saca de la operación diaria. No es
        // contenido del documento ni cambia su estado fiscal.
        'archivado',
        'archivado_en',
        // Evento de invalidación oficial (metadatos, no contenido del DTE original).
        'codigo_generacion_invalidacion',
        'tipo_anulacion',
        'json_invalidacion_path',
        'jws_invalidacion_path',
        'sello_invalidacion',
        'respuesta_mh_invalidacion',
        'respuesta_mh_invalidacion_path',
        'fecha_invalidacion',
        'fecha_procesamiento_invalidacion',
        'updated_at',
    ];

    public function saving(Dte $dte): void
    {
        // Defensa: un documento NUNCA se relaciona consigo mismo (una NC apunta al
        // CCF original, no a sí misma). Si llegara a ocurrir, se corrige a null.
        if ($dte->id !== null && $dte->dte_relacionado_id !== null
            && (int) $dte->dte_relacionado_id === (int) $dte->id) {
            $dte->dte_relacionado_id = null;
        }
    }

    public function updating(Dte $dte): void
    {
        // Documento nuevo o estado original borrador → sin restricción.
        $estadoOriginal = EstadoDte::from($dte->getRawOriginal('estado'));
        if ($estadoOriginal === EstadoDte::Borrador) {
            return;
        }

        $cambios = array_keys($dte->getDirty());
        $noPermitidos = array_diff($cambios, self::CAMPOS_PERMITIDOS_FUERA_DE_BORRADOR);

        if (! empty($noPermitidos)) {
            throw new DocumentoInmutableException(
                'No se puede modificar un DTE en estado '.$estadoOriginal->label().
                '. Campos bloqueados: '.implode(', ', $noPermitidos).'.'
            );
        }
    }

    public function updated(Dte $dte): void
    {
        if ($dte->wasChanged('estado') && $dte->estado === EstadoDte::Aceptado) {
            $this->alQuedarAceptado($dte);
        }
        // Firmado dentro de una contingencia: el provisional ya está emitido.
        if ($dte->wasChanged('estado') && $dte->esTransitorio()) {
            $this->alQuedarProvisional($dte);
        }
        if ($dte->wasChanged('estado') && $dte->tipo_dte === TipoDte::NotaCredito && $dte->dte_relacionado_id) {
            $this->recalcularCobroDelCcf($dte);
        }
    }

    /**
     * Una NC que se acepta, se invalida o se anula cambia lo que hay que cobrar de su CCF
     * ({@see CobroDocumento::facturadoEfectivo()}): si el CCF ya tiene cobro, su estado de pago
     * se recalcula al momento, sin esperar al comando. Va después de confirmado el cambio y
     * nunca lo rompe: si falla, se reporta y lo arregla `cobros:recalcular-pagos`.
     */
    private function recalcularCobroDelCcf(Dte $nc): void
    {
        $ccfId = (int) $nc->dte_relacionado_id;
        DB::afterCommit(function () use ($ccfId) {
            try {
                // Como el aplicador del TXT: primero el candado del seguimiento y recién después
                // la lectura de sus eventos. Sin él, un cobro que entra a la vez puede quedar
                // pisado con un `monto_pagado` leído antes.
                DB::transaction(function () use ($ccfId) {
                    $ccf = CobroDocumento::query()->where('tipo_dte', TipoDte::CreditoFiscal->value)
                        ->where('dte_id', $ccfId)->lockForUpdate()->first();
                    if ($ccf !== null && Dinero::comparar($ccf->monto_pagado ?? '0', '0') !== 0) {
                        $ccf->recalcularPago();
                    }
                });
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Lo que cuelga de que Hacienda ACEPTE el documento: el alta en Seguimiento de cobros,
     * las notas de crédito en los lotes de su CCF y el correo automático al cliente.
     *
     * Ocurre cuando el estado pasa a Aceptado. Lo llama además la transmisión cuando una
     * consulta le completa el sello a un documento que figuraba aceptado sin él: recién
     * ahí es una aceptación real. Repetirlo no duplica nada: el alta y las notas no hacen
     * nada si ya están, y el correo no sale si el documento completo ya salió o está en cola.
     *
     * Auto-envío del DTE por correo al cliente, si está activado en Configuración
     * (correo.auto_envio). Solo encola (afterCommit): NO bloquea ni modifica la
     * transmisión; vive en el módulo de correo. Sin destinatario no hace nada.
     *
     * Vale también para el regularizado de una contingencia, aunque el cliente ya tenga
     * el provisional, y para el documento en duda al que alguien le mandó a mano el PDF
     * preliminar: lo que salió sin sello no cuenta como el documento completo (simulacro
     * del 17/11, F8; decisión de Melqui del 26/11). Un envío en cola sí cuenta, porque el
     * job arma el PDF al correr, ya con el sello.
     */
    public function alQuedarAceptado(Dte $dte): void
    {
        // Seguimiento de cobros: el CCF/NC entra en el momento en que Hacienda lo acepta, sin
        // esperar a la tarea programada. Va DESPUÉS de confirmada la aceptación y nunca la
        // rompe: si falla, el documento queda aceptado, el error se reporta y lo recoge la
        // tarea `cobros:sincronizar`. Las reglas de quién entra viven en AltaCobrosService.
        DB::afterCommit(function () use ($dte) {
            try {
                app(AltaCobrosService::class)->darDeAlta($dte);
            } catch (Throwable $e) {
                report($e);
            }
        });
        if ($dte->tipo_dte === TipoDte::NotaCredito && $dte->dte_relacionado_id) {
            DB::afterCommit(function () use ($dte) {
                try {
                    app(NcPosterioresLotePpq::class)->alAceptarse($dte);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
        if (! Ajustes::bool('correo.auto_envio', false)) {
            return;
        }
        if ($dte->envios()->get()->contains(fn (DteEnvio $e) => $e->estado === 'pendiente' || $e->llevoDocumentoCompleto())) {
            return;
        }

        $correo = $this->correoDelCliente($dte);
        if (blank($correo)) {
            return;
        }

        // Mismo pipeline que la UI (registro 'pendiente' + job). Con queue=database el job es
        // transaccional (su fila se inserta dentro de la transacción de aceptación y se confirma
        // con ella); el worker lo toma luego. user_id null = envío automático (sistema).
        app(EnvioDteCorreoService::class)->encolar(
            $dte,
            [$correo],
            null,
            DteEnvio::CANAL_CLIENTE,
        );
    }

    /**
     * Documento emitido en contingencia (provisional: firmado, sin sello). La norma pide
     * entregarle al cliente el provisional y después el completo: con el envío automático
     * encendido, este sale solo y el completo sale al sellarse ({@see alQuedarAceptado()}).
     * Solo el correo: lo demás de la aceptación espera al sello. Decisión de Melqui del
     * 26/11/2026 (simulacro del 17/11, F8).
     *
     * Lo llaman el paso a Firmado de un documento generado dentro de una contingencia y la
     * conversión automática ({@see ContingenciaAutomaticaService}). Mismas condiciones que
     * el envío automático al aceptarse; se encola después del commit y no se repite si ya
     * hay un envío en cola o hecho. Si falla, no rompe la emisión: se reporta.
     */
    public function alQuedarProvisional(Dte $dte): void
    {
        if (! $dte->esTransitorio() || ! Ajustes::bool('correo.auto_envio', false)) {
            return;
        }

        DB::afterCommit(function () use ($dte) {
            try {
                if ($dte->envios()->whereIn('estado', ['pendiente', 'enviado'])->exists()) {
                    return;
                }
                $correo = $this->correoDelCliente($dte);
                if (blank($correo)) {
                    return;
                }
                app(EnvioDteCorreoService::class)->encolar($dte, [$correo], null, DteEnvio::CANAL_CLIENTE);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Al terminar la contingencia se manda otra vez el provisional que no le llegó al
     * cliente. En la tipo 3 (sin internet) sale apenas se emite y falla, porque el
     * servidor de correo también es de afuera, y el job no reintenta (simulacro del
     * 17/11, F8). Al terminar ya hay conexión.
     *
     * Solo los documentos todavía sin sello cuyos envíos al cliente fallaron todos: lo
     * que salió, está en cola o nunca se intentó no se toca. Una vez por cese; si vuelve a
     * fallar, la ficha deja reenviarlo y el completo sale solo al sellarse. Lo llaman los
     * dos ceses: el de la persona ({@see ContingenciaService::terminar()}) y el de la
     * reconexión automática.
     */
    public function reintentarProvisionales(Contingencia $contingencia): void
    {
        if (! Ajustes::bool('correo.auto_envio', false)) {
            return;
        }

        DB::afterCommit(function () use ($contingencia) {
            $documentos = $contingencia->dtes()->where('estado', EstadoDte::Firmado->value)
                ->with(['envios', 'cliente', 'clienteSucursal'])->get();
            foreach ($documentos as $dte) {
                try {
                    $alCliente = $dte->envios->reject(fn (DteEnvio $e) => $e->esCanalContabilidad());
                    if (! $dte->esTransitorio() || $alCliente->isEmpty()
                        || $alCliente->contains(fn (DteEnvio $e) => $e->estado !== 'error')) {
                        continue;
                    }
                    $correo = $this->correoDelCliente($dte);
                    if (blank($correo)) {
                        continue;
                    }
                    app(EnvioDteCorreoService::class)->encolar($dte, [$correo], null, DteEnvio::CANAL_CLIENTE);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /** Correo del envío automático: el de la sala si tiene, si no el del cliente. */
    private function correoDelCliente(Dte $dte): ?string
    {
        return $dte->clienteSucursal?->correo ?: $dte->cliente?->correo;
    }

    public function deleting(Dte $dte): void
    {
        $estadoOriginal = EstadoDte::from($dte->getRawOriginal('estado'));

        if ($estadoOriginal !== EstadoDte::Borrador) {
            throw new DocumentoInmutableException(
                'Solo se pueden eliminar documentos en borrador (estado actual: '.$estadoOriginal->label().').'
            );
        }
    }
}
