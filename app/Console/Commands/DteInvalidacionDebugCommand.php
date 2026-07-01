<?php

namespace App\Console\Commands;

use App\Models\Dte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * INSPECCIÓN (solo lectura) del evento de invalidación de un DTE: muestra el último
 * JSON guardado, el último JWS DECODIFICADO (payload real firmado), la última respuesta
 * del MH y si el PRÓXIMO intento regeneraría un evento nuevo o está bloqueado.
 *
 * NO firma, NO transmite, NO cambia estado, NO escribe nada. Útil para diagnosticar
 * rechazos comparando exactamente qué se firmó y envió.
 */
class DteInvalidacionDebugCommand extends Command
{
    protected $signature = 'dte:invalidacion-debug {dte : ID del DTE}';

    protected $description = 'Inspecciona el evento de invalidación (JSON/JWS/respuesta MH) de un DTE. Solo lectura.';

    public function handle(): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        $disco = (string) config('dte.storage.disk', 'local');
        $disk = Storage::disk($disco);

        $this->warn('*** DEBUG — SOLO LECTURA. No firma, no transmite, no cambia nada ***');
        if ($dte->tieneEventoInvalidacion()) {
            $this->info('✔ Este DTE ya fue invalidado oficialmente (sello presente o estado Invalidado).');
        }
        $this->newLine();

        // --- Estado actual de invalidación en BD ---
        $this->line('Estado del DTE '.$dte->id.' ('.$dte->tipo_dte->label().'):');
        $this->table(['Columna', 'Valor'], [
            ['estado', $dte->estado->value],
            ['sello_recepcion (original)', (string) $dte->sello_recepcion],
            ['codigo_generacion_invalidacion', (string) $dte->codigo_generacion_invalidacion],
            ['sello_invalidacion', (string) ($dte->sello_invalidacion ?? '—')],
            ['tipo_anulacion', (string) ($dte->tipo_anulacion?->value ?? '—')],
            ['fecha_invalidacion', (string) $dte->fecha_invalidacion],
            ['fecha_procesamiento_invalidacion', (string) $dte->fecha_procesamiento_invalidacion],
            ['json_invalidacion_path', (string) ($dte->json_invalidacion_path ?? '—')],
            ['jws_invalidacion_path', (string) ($dte->jws_invalidacion_path ?? '—')],
            ['respuesta_mh_invalidacion_path', (string) ($dte->respuesta_mh_invalidacion_path ?? '—')],
        ]);

        // --- Último JSON guardado ---
        $this->newLine();
        if ($dte->json_invalidacion_path && $disk->exists($dte->json_invalidacion_path)) {
            $this->line('Último JSON del evento ('.$dte->json_invalidacion_path.'):');
            $this->line((string) $disk->get($dte->json_invalidacion_path));
        } else {
            $this->line('Último JSON del evento: (no hay archivo)');
        }

        // --- Último JWS decodificado (payload REAL firmado) ---
        $this->newLine();
        if ($dte->jws_invalidacion_path && $disk->exists($dte->jws_invalidacion_path)) {
            $payload = $this->decodificarJws((string) $disk->get($dte->jws_invalidacion_path));
            $this->line('Último JWS decodificado ('.$dte->jws_invalidacion_path.'):');
            if ($payload === null) {
                $this->warn('  No se pudo decodificar el JWS.');
            } else {
                $this->table(['Campo', 'Valor'], [
                    ['identificacion.fecEmi', (string) ($payload['identificacion']['fecEmi'] ?? '—')],
                    ['identificacion.horEmi', (string) ($payload['identificacion']['horEmi'] ?? '—')],
                    ['identificacion.codigoGeneracion', (string) ($payload['identificacion']['codigoGeneracion'] ?? '—')],
                    ['documento.fecEmi', (string) ($payload['documento']['fecEmi'] ?? '—')],
                    ['documento.codigoGeneracion', (string) ($payload['documento']['codigoGeneracion'] ?? '—')],
                    ['documento.numeroControl', (string) ($payload['documento']['numeroControl'] ?? '—')],
                    ['motivo.tipoAnulacion', (string) ($payload['motivo']['tipoAnulacion'] ?? '—')],
                ]);
                $coincide = ($payload['identificacion']['fecEmi'] ?? null) === ($payload['documento']['fecEmi'] ?? null);
                $this->line('  identificacion.fecEmi == documento.fecEmi: '.($coincide ? 'SÍ ✔' : 'NO ✘ (causa de rechazo 027)'));
            }
        } else {
            $this->line('Último JWS: (no hay archivo)');
        }

        // --- Última respuesta MH ---
        $this->newLine();
        $this->line('Última respuesta del MH (columna respuesta_mh_invalidacion):');
        $this->line((string) json_encode($dte->respuesta_mh_invalidacion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // --- Historial de intentos en disco ---
        $this->newLine();
        $this->line('Intentos guardados en disco (respuestas):');
        $carpeta = trim((string) config('dte.storage.invalidacion_respuestas', 'dte/invalidacion/respuestas'), '/');
        $archivos = array_filter($disk->files($carpeta), fn ($f) => str_contains($f, '-'.$dte->tipo_dte->value.'-'.$dte->id.'-'));
        if ($archivos === []) {
            $this->line('  (ninguno)');
        }
        foreach ($archivos as $f) {
            $cuerpo = json_decode((string) $disk->get($f), true);
            $this->line('  · '.basename($f).' → '.($cuerpo['estado'] ?? '?').' ('.($cuerpo['codigoMsg'] ?? '?').') '.($cuerpo['descripcionMsg'] ?? ''));
        }

        // --- ¿Próximo intento regenera o reutiliza? ---
        $this->newLine();
        if ($dte->tieneEventoInvalidacion()) {
            $this->warn('Próximo intento: BLOQUEADO. Ya tiene sello_invalidacion o está Invalidado; no se reintenta.');
        } else {
            $this->info('Próximo intento: REGENERARÍA un evento NUEVO (codigoGeneracion nuevo, JSON/JWS nuevos). '
                .'No reutiliza el evento anterior; la evidencia previa queda en disco.');
        }

        return self::SUCCESS;
    }

    /**
     * Decodifica el payload (2º segmento base64url) de un JWS compacto.
     *
     * @return array<string, mixed>|null
     */
    private function decodificarJws(string $jws): ?array
    {
        $parts = explode('.', trim($jws));
        if (count($parts) < 2) {
            return null;
        }
        $p = strtr($parts[1], '-_', '+/');
        $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
        $json = json_decode((string) base64_decode($p), true);

        return is_array($json) ? $json : null;
    }
}
