<?php

namespace App\Observers;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\Dte;

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

    /**
     * Auto-envío del DTE por correo al cliente cuando el MH lo ACEPTA, si está
     * activado en Configuración (correo.auto_envio). Solo encola (afterCommit): NO
     * bloquea ni modifica la transmisión; vive en el módulo de correo. Sin destinatario
     * o si ya hay un envío encolado/exitoso, no hace nada.
     */
    public function updated(Dte $dte): void
    {
        if (! $dte->wasChanged('estado') || $dte->estado !== EstadoDte::Aceptado) {
            return;
        }
        if (! \App\Models\Configuracion::getBool('correo.auto_envio', false)) {
            return;
        }
        if ($dte->envios()->whereIn('estado', ['pendiente', 'enviado'])->exists()) {
            return;
        }

        $correo = $dte->clienteSucursal?->correo ?: $dte->cliente?->correo;
        if (blank($correo)) {
            return;
        }

        $envio = $dte->envios()->create([
            'destinatario' => $correo,
            'destinatarios' => [$correo],
            'estado' => 'pendiente',
            'user_id' => null, // envío automático (sistema)
        ]);

        // Con queue=database el job es transaccional (su fila se inserta dentro de la
        // transacción de aceptación y se confirma con ella); el worker lo toma luego.
        \App\Jobs\EnviarDteCorreo::dispatch($envio->id);
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
