<?php

namespace App\Support\Dte;

use App\Enums\EstadoDte;
use App\Models\CatalogoMh;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Municipio;
use App\Support\Archivos\ArchivoAlmacenado;

/** Lectura de la identidad fiscal transmitida; nunca persiste ni regenera. */
final class DatosFiscalesEmision
{
    public static function desde(Dte $dte): ?array
    {
        if ($dte->estado === EstadoDte::Borrador || blank($dte->json_generado_path)) {
            return null;
        }
        $archivo = ArchivoAlmacenado::leer((string) config('dte.storage.disk', 'local'), $dte->json_generado_path);
        $json = json_decode((string) $archivo->contenido, true);
        if (! $archivo->presente() || ! is_array($json) || ! is_array($json['emisor'] ?? null)) {
            return null;
        }
        $receptor = $json['receptor'] ?? null;
        if (is_array($receptor) && array_key_exists('nit', $receptor)) {
            $receptor['tipoDocumento'] = '36';
            $receptor['numDocumento'] = $receptor['nit'];
        }
        // '—' es el relleno que ponen los serializadores cuando el receptor no tenía dirección.
        if (($receptor['complemento'] ?? null) === '—') {
            $receptor['complemento'] = '';
        }
        if (($receptor['direccion']['complemento'] ?? null) === '—') {
            $receptor['direccion']['complemento'] = '';
        }

        return ['emisor' => $json['emisor'], 'receptor' => $receptor];
    }

    /** Conserva el formato habitual cuando la identidad es la misma. */
    public static function valor(mixed $actual, mixed $emitido, bool $documento = false): mixed
    {
        if ((string) $actual === (string) $emitido) {
            return $actual;
        }
        if ($documento && preg_replace('/\D+/', '', (string) $actual) !== ''
            && preg_replace('/\D+/', '', (string) $actual) === preg_replace('/\D+/', '', (string) $emitido)) {
            return $actual;
        }

        return $emitido;
    }

    public static function actividad($modelo, array $datos, mixed $actual): mixed
    {
        if (array_key_exists('codActividad', $datos) && (string) $modelo?->actividadEconomica?->codigo === (string) $datos['codActividad']) {
            return $actual;
        }
        // La FEX solo transmite descripción: recuperar su código mediante CAT-019.
        if (! array_key_exists('codActividad', $datos) && $modelo?->actividadEconomica?->codigo
            && CatalogoMh::where('cat', '019')->where('codigo', $modelo->actividadEconomica->codigo)->where('valor', $datos['descActividad'] ?? '')->exists()) {
            return $actual;
        }

        return self::valor($actual, $datos['descActividad'] ?? null);
    }

    /** Códigos municipales y distritales se resuelven dentro del departamento. */
    public static function ubicacion($modelo, array $direccion, array $actuales): array
    {
        $depto = Departamento::where('codigo', $direccion['departamento'] ?? '')->first();
        $resultado = [];
        foreach (['departamento' => Departamento::class, 'municipio' => Municipio::class, 'distrito' => Distrito::class] as $campo => $clase) {
            if (! array_key_exists($campo, $direccion)) {
                $resultado[] = $actuales[count($resultado)] ?? null;

                continue;
            }
            $codigo = $direccion[$campo];
            $mismoDepartamento = (string) $modelo?->departamento?->codigo === (string) ($direccion['departamento'] ?? null);
            if ($mismoDepartamento && (string) $modelo?->{$campo}?->codigo === (string) $codigo) {
                $resultado[] = $actuales[count($resultado)] ?? null;

                continue;
            }
            $fila = $campo === 'departamento' ? $depto : ($depto ? $clase::where('departamento_id', $depto->id)->where('codigo', $codigo)->first() : null);
            $resultado[] = $fila ? ($campo === 'municipio' ? $fila->nombreFiscal() : $fila->nombre) : $codigo;
        }

        return $resultado;
    }
}
