<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Support\Archivos\ArchivoAlmacenado;
use App\Support\Dte\ArchivoEntregaDte;
use stdClass;

/** Solo lectura: reúne la evidencia existente; nunca genera, firma ni persiste. */
class ArchivoEntregaDteService
{
    public function construir(Dte $dte): ArchivoEntregaDte
    {
        $nombre = preg_replace('/[^A-Za-z0-9_.-]+/', '_', strtoupper((string) $dte->codigo_generacion).'.json');
        if ($dte->estado !== EstadoDte::Aceptado) {
            $motivo = $dte->estado === EstadoDte::Rechazado
                ? 'rechazado por Hacienda'
                : 'sin aceptación vigente de Hacienda (estado '.$dte->estado->value.')';

            return new ArchivoEntregaDte(ArchivoEntregaDte::NO_FISCAL, null, $nombre, motivo: $motivo);
        }

        if (str_starts_with(strtoupper((string) $dte->sello_recepcion), 'MOCK')
            || str_ends_with((string) $dte->json_firmado_path, '.mock.jws')) {
            return new ArchivoEntregaDte(ArchivoEntregaDte::NO_FISCAL, null, $nombre, motivo: 'simulado (MOCK)');
        }

        $disco = (string) config('dte.storage.disk', 'local');
        $firma = ArchivoAlmacenado::leer($disco, $dte->json_firmado_path);
        $jws = trim((string) $firma->contenido);
        $segmentos = explode('.', $jws);
        $header = $this->objeto($this->base64url($segmentos[0] ?? ''));
        $respuesta = ArchivoAlmacenado::leer($disco, $dte->respuesta_mh_path);
        $cuerpo = $this->objeto((string) $respuesta->contenido);
        if (($header->alg ?? null) === 'none' || ($header->mock ?? null) === true
            || ($segmentos[2] ?? null) === 'MOCK-SIN-FIRMA-REAL' || ($cuerpo->_mock ?? null) === true) {
            return new ArchivoEntregaDte(ArchivoEntregaDte::NO_FISCAL, null, $nombre, motivo: 'simulado (MOCK)');
        }

        $faltantes = [];
        $recuperacion = [];
        $restaurar = 'restaurar el archivo desde el respaldo; no volver a generar ni firmar un documento ya aceptado';
        $revisar = 'revisar la evidencia antes de entregar';
        $json = ArchivoAlmacenado::leer($disco, $dte->json_generado_path);
        $documento = $this->objeto((string) $json->contenido);
        if (! $json->presente() || $documento === null) {
            $faltantes[] = 'JSON: '.($json->presente() ? 'no es un objeto JSON' : $json->explicacion());
            $recuperacion[] = $restaurar;
        }

        $payload = count($segmentos) === 3 && ! in_array('', $segmentos, true)
            ? $this->objeto($this->base64url($segmentos[1])) : null;
        if (! $firma->presente() || $payload === null) {
            $faltantes[] = 'firma: '.($firma->presente() ? 'no es JWS compacto con payload objeto JSON' : $firma->explicacion());
            $recuperacion[] = $restaurar;
        }

        if (blank($dte->sello_recepcion)) {
            $faltantes[] = 'sello de recepción';
            $recuperacion[] = 'consultar el estado en Hacienda para recuperar el sello';
        }

        if ($documento !== null) {
            if (property_exists($documento, 'firmaElectronica') || property_exists($documento, 'selloRecibido')) {
                $faltantes[] = 'el JSON guardado ya trae firma/sello';
                $recuperacion[] = $revisar;
            }
            $identificacion = $documento->identificacion ?? null;
            foreach (['codigoGeneracion' => $dte->codigo_generacion, 'numeroControl' => $dte->numero_control, 'tipoDte' => $dte->tipo_dte->value] as $clave => $esperado) {
                $valor = $identificacion instanceof stdClass ? ($identificacion->$clave ?? null) : null;
                $iguales = $clave === 'codigoGeneracion'
                    ? is_string($valor) && strtoupper($valor) === strtoupper((string) $esperado)
                    : $valor === $esperado;
                if (! $iguales) {
                    $faltantes[] = 'coherencia de identificacion.'.$clave.' con el DTE';
                    $recuperacion[] = $revisar;
                }
            }
            if ($payload !== null && ! $this->equivalentes($documento, $payload)) {
                $faltantes[] = 'coherencia del payload del JWS con el JSON';
                $recuperacion[] = $revisar;
            }
        }

        if (filled($dte->sello_recepcion) && filled($dte->respuesta_mh['selloRecibido'] ?? null)
            && $dte->respuesta_mh['selloRecibido'] !== $dte->sello_recepcion) {
            $faltantes[] = 'coherencia del sello en respuesta_mh';
            $recuperacion[] = $revisar;
        }
        if ($cuerpo !== null && property_exists($cuerpo, 'codigoGeneracion')
            && (! is_string($cuerpo->codigoGeneracion) || strtoupper($cuerpo->codigoGeneracion) !== strtoupper((string) $dte->codigo_generacion))) {
            $faltantes[] = 'coherencia de codigoGeneracion en la respuesta de Hacienda';
            $recuperacion[] = $revisar;
        }

        if ($faltantes !== []) {
            return new ArchivoEntregaDte(ArchivoEntregaDte::INCOMPLETO, null, $nombre, $faltantes, array_values(array_unique($recuperacion)));
        }

        $contenido = $this->anexar((string) $json->contenido, $jws, (string) $dte->sello_recepcion);
        if ($contenido === null) {
            return new ArchivoEntregaDte(ArchivoEntregaDte::INCOMPLETO, null, $nombre, ['armado del archivo de entrega'], [$revisar]);
        }

        return new ArchivoEntregaDte(ArchivoEntregaDte::COMPLETO, $contenido, $nombre);
    }

    /**
     * Agrega firmaElectronica y selloRecibido al final del objeto SIN decodificar ni
     * recodificar el documento: los bytes de la estructura entregada son exactamente
     * los guardados. Recodificar cambiaría `{}` por `[]` o el formato de un número.
     */
    private function anexar(string $original, string $jws, string $sello): ?string
    {
        $cuerpo = rtrim($original);
        if (! str_ends_with($cuerpo, '}')) {
            return null;
        }
        $interior = rtrim(substr($cuerpo, 0, -1));
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $contenido = $interior.(str_ends_with($interior, '{') ? '' : ',')
            ."\n    \"firmaElectronica\": ".json_encode($jws, $flags)
            .",\n    \"selloRecibido\": ".json_encode($sello, $flags)
            ."\n}";

        // Comprobación final: el resultado es un objeto con las mismas claves de antes.
        $resultado = $this->objeto($contenido);
        if ($resultado === null || $resultado->firmaElectronica !== $jws || $resultado->selloRecibido !== $sello) {
            return null;
        }
        unset($resultado->firmaElectronica, $resultado->selloRecibido);

        return $this->equivalentes($resultado, $this->objeto($original)) ? $contenido : null;
    }

    private function objeto(string $contenido): ?stdClass
    {
        $objeto = json_decode($contenido);

        return $objeto instanceof stdClass ? $objeto : null;
    }

    private function base64url(string $segmento): string
    {
        if ($segmento === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $segmento) || strlen($segmento) % 4 === 1) {
            return '';
        }

        return base64_decode(strtr($segmento, '-_', '+/'), true) ?: '';
    }

    /** Objetos sin orden de claves; listas conservan posición y tipo. */
    private function equivalentes(mixed $a, mixed $b): bool
    {
        if ($a instanceof stdClass && $b instanceof stdClass) {
            $claves = array_unique(array_merge(array_keys(get_object_vars($a)), array_keys(get_object_vars($b))));
            foreach ($claves as $clave) {
                if (! $this->equivalentes($a->$clave ?? null, $b->$clave ?? null)) {
                    return false;
                }
            }

            return true;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $clave => $valor) {
                if (! $this->equivalentes($valor, $b[$clave])) {
                    return false;
                }
            }

            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs($a - $b) <= 1e-9;
        }

        return $a === $b;
    }
}
