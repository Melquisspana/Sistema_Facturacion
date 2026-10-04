<?php

namespace Tests\Concerns;

use App\Models\Dte;
use Illuminate\Support\Facades\Storage;

trait PreparaArchivoEntregaDte
{
    /** Evidencia ficticia coherente para probar la entrega de un aceptado. */
    protected function prepararArchivoEntrega(Dte $dte): void
    {
        $dte->json_generado_path ??= 'dte/json/'.$dte->codigo_generacion.'.json';
        $dte->json_firmado_path = 'dte/firmados/'.$dte->codigo_generacion.'.jws';
        $dte->respuesta_mh = ['selloRecibido' => $dte->sello_recepcion];
        $json = json_encode(['identificacion' => [
            'codigoGeneracion' => $dte->codigo_generacion,
            'numeroControl' => $dte->numero_control,
            'tipoDte' => $dte->tipo_dte->value,
            'ambiente' => $dte->ambiente->value,
            'fecEmi' => $dte->fecha_emision->format('Y-m-d'),
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $base64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        Storage::disk('local')->put($dte->json_generado_path, $json);
        Storage::disk('local')->put($dte->json_firmado_path, $base64('{"alg":"RS512"}').'.'.$base64($json).'.ZmlybWEtZmljdGljaWE');
        $dte->saveQuietly();
    }
}
