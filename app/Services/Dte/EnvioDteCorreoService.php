<?php

namespace App\Services\Dte;

use App\Jobs\EnviarDteCorreo;
use App\Models\Dte;
use App\Models\DteEnvio;

/**
 * Punto ÚNICO de encolado de correos de un DTE. Crea el registro 'pendiente' del
 * historial y despacha el job (la UI no espera al SMTP). Lo usan tanto la UI de
 * Facturación como el auto-envío del observer, para que el anti-duplicado y el
 * historial sean los mismos en todos los caminos.
 *
 * NO envía el correo, no genera PDF/JSON (eso vive en el job) y no toca el estado
 * fiscal del DTE.
 */
class EnvioDteCorreoService
{
    /**
     * Encola un envío del DTE a `$emails` por el canal indicado. Devuelve el DteEnvio
     * creado, o null si YA hay uno pendiente equivalente (mismo DTE + mismos
     * destinatarios + mismo canal), para no duplicar jobs.
     *
     * El anti-duplicado está aislado por canal: un pendiente al cliente no bloquea uno
     * a contabilidad ni al revés.
     *
     * @param  array<int, string>  $emails
     * @param  string  $canal  DteEnvio::CANAL_CLIENTE | DteEnvio::CANAL_CONTABILIDAD
     *
     * @throws \InvalidArgumentException si el canal no es válido
     */
    public function encolar(Dte $dte, array $emails, ?int $userId, string $canal = DteEnvio::CANAL_CLIENTE): ?DteEnvio
    {
        if (! in_array($canal, DteEnvio::CANALES, true)) {
            throw new \InvalidArgumentException('Canal de envío no válido: "'.$canal.'".');
        }

        if ($this->tienePendienteEquivalente($dte, $emails, $canal)) {
            return null;
        }

        $envio = $dte->envios()->create([
            'destinatario' => $emails[0],
            'destinatarios' => $emails,
            'canal' => $canal,
            'estado' => 'pendiente',
            'user_id' => $userId,
        ]);

        EnviarDteCorreo::dispatch($envio->id);

        return $envio;
    }

    /**
     * ¿Ya hay un envío PENDIENTE de este DTE, al mismo conjunto de destinatarios y por el
     * mismo canal? Los envíos históricos (canal NULL) cuentan como canal 'cliente', así que
     * bloquean un duplicado al cliente pero nunca uno a contabilidad.
     *
     * @param  array<int, string>  $emails
     */
    private function tienePendienteEquivalente(Dte $dte, array $emails, string $canal): bool
    {
        return $dte->envios()->where('estado', 'pendiente')->get()
            ->contains(fn (DteEnvio $e) => $e->canalEfectivo() === $canal
                && $this->mismosDestinatarios($e->destinatarios ?: array_filter([$e->destinatario]), $emails));
    }

    /** ¿Dos listas de destinatarios son el mismo conjunto (sin importar orden/duplicados)? */
    private function mismosDestinatarios(array $a, array $b): bool
    {
        $norm = fn (array $x) => collect($x)->map(fn ($e) => strtolower(trim((string) $e)))->unique()->sort()->values()->all();

        return $norm($a) === $norm($b);
    }
}
