<?php

namespace App\Services\DocumentosRecibidos;

use App\Jobs\EnviarDocumentoRecibidoContabilidad;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoEnvio;

/**
 * Punto ÚNICO de encolado del envío de una COMPRA a contabilidad. Crea el registro
 * 'pendiente' del historial y despacha el job (la interfaz no espera al SMTP).
 *
 * No envía el correo, no lee el buzón y no cambia el estado del documento: eso lo
 * hace el job, y solo cuando el envío termina realmente bien.
 */
class EnvioDocumentoRecibidoService
{
    /**
     * Encola un envío del documento a `$emails`. Devuelve el registro creado, o null si
     * YA hay uno pendiente para los mismos destinatarios (no se duplican jobs).
     *
     * @param  array<int, string>  $emails
     */
    public function encolar(DocumentoRecibido $documento, array $emails, ?int $userId): ?DocumentoRecibidoEnvio
    {
        if ($this->tienePendienteEquivalente($documento, $emails)) {
            return null;
        }

        $envio = $documento->envios()->create([
            'destinatario' => $emails[0],
            'destinatarios' => $emails,
            'estado' => 'pendiente',
            'user_id' => $userId,
        ]);

        EnviarDocumentoRecibidoContabilidad::dispatch($envio->id);

        return $envio;
    }

    /**
     * ¿Ya hay un envío PENDIENTE de este documento al mismo conjunto de destinatarios?
     *
     * @param  array<int, string>  $emails
     */
    private function tienePendienteEquivalente(DocumentoRecibido $documento, array $emails): bool
    {
        return $documento->envios()->where('estado', 'pendiente')->get()
            ->contains(fn (DocumentoRecibidoEnvio $e) => $this->mismosDestinatarios($e->listaDestinatarios(), $emails));
    }

    /** ¿Dos listas de destinatarios son el mismo conjunto (sin importar orden/duplicados)? */
    private function mismosDestinatarios(array $a, array $b): bool
    {
        $norm = fn (array $x) => collect($x)->map(fn ($e) => strtolower(trim((string) $e)))->unique()->sort()->values()->all();

        return $norm($a) === $norm($b);
    }
}
