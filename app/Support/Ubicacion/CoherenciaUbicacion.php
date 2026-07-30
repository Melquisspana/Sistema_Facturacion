<?php

namespace App\Support\Ubicacion;

use App\Models\Distrito;
use App\Models\Municipio;

/**
 * REGLA ÚNICA de coherencia de la ubicación fiscal: departamento → municipio 2024
 * (CAT-013) → distrito (CAT-008).
 *
 * Hasta ahora municipio y distrito se validaban por separado, cada uno solo contra el
 * departamento. Eso dejaba pasar pares imposibles —municipio "Cabañas Este" con distrito
 * "Ilobasco", que es de Cabañas Oeste— y Hacienda los rechaza con
 * «[receptor.direccion.distrito] VALOR NO ES PERMITIDO».
 *
 * La usan los FormRequests (salas, clientes, empresa, establecimientos), la validación
 * previa a generar el JSON y el comando `ubicaciones:auditar`, para que exista una sola
 * definición de "ubicación coherente".
 */
final class CoherenciaUbicacion
{
    /**
     * Revisa el trío y devuelve el problema encontrado, o null si es coherente.
     *
     * `$municipioId` y `$distritoId` pueden venir vacíos: aquí solo se juzga la
     * COHERENCIA entre lo que sí vino. La obligatoriedad la decide cada formulario /
     * la validación previa al JSON según el tipo de documento.
     */
    public static function problema(?int $departamentoId, ?int $municipioId, ?int $distritoId): ?string
    {
        $municipio = $municipioId ? Municipio::find($municipioId) : null;
        $distrito = $distritoId ? Distrito::find($distritoId) : null;

        if ($municipioId && ! $municipio) {
            return 'El municipio indicado no existe.';
        }
        if ($distritoId && ! $distrito) {
            return 'El distrito indicado no existe.';
        }

        if ($departamentoId && $municipio && (int) $municipio->departamento_id !== $departamentoId) {
            return 'El municipio seleccionado no pertenece al departamento elegido.';
        }
        if ($departamentoId && $distrito && (int) $distrito->departamento_id !== $departamentoId) {
            return 'El distrito seleccionado no pertenece al departamento elegido.';
        }

        // El corazón del arreglo: el distrito debe pertenecer al MUNICIPIO elegido.
        //
        // Solo se afirma incoherencia cuando AMBOS códigos se conocen. Si falta el vínculo
        // del distrito (`municipio_codigo`) o el código CAT-013 del municipio, no hay
        // evidencia de que el par sea inválido y bloquear sería un falso positivo: esos
        // huecos de catálogo los reporta `php artisan ubicaciones:auditar`.
        if ($municipio && $distrito
            && filled($distrito->municipio_codigo) && filled($municipio->codigo)
            && ! $distrito->perteneceAMunicipio($municipio)) {
            return "El distrito «{$distrito->nombre}» pertenece a «{$distrito->municipio}», "
                ."no al municipio «{$municipio->nombreFiscal()}» seleccionado.";
        }

        return null;
    }

    /**
     * Códigos oficiales que FALTAN para poder escribir la dirección en el JSON.
     *
     * Tener `municipio_id` / `distrito_id` no alcanza: lo que viaja al MH es el CÓDIGO
     * (CAT-013 / CAT-008). Si la fila del catálogo no lo tiene, el JSON sale con
     * `municipio: ""` o `distrito: ""` y Hacienda lo rechaza. Se revisa aparte de la
     * coherencia porque es un hueco de CATÁLOGO, no una mala combinación.
     *
     * @param  bool  $exigeDistrito  si el esquema del documento lleva `distrito`
     * @return array<int, string>
     */
    public static function codigosFaltantes(object $entidad, bool $exigeDistrito): array
    {
        $faltantes = [];

        $municipio = $entidad->municipio_id ? Municipio::find($entidad->municipio_id) : null;
        if ($municipio && blank($municipio->codigo)) {
            $faltantes[] = "el municipio «{$municipio->nombre}» no tiene código CAT-013 en el catálogo";
        }

        if ($exigeDistrito) {
            $distrito = $entidad->distrito_id ? Distrito::find($entidad->distrito_id) : null;
            if ($distrito && blank($distrito->codigo)) {
                $faltantes[] = "el distrito «{$distrito->nombre}» no tiene código CAT-008 en el catálogo "
                    .'(corré `php artisan distritos:codigos-mh`)';
            }
        }

        return $faltantes;
    }

    /**
     * Coherencia de una entidad ya persistida que tenga departamento/municipio/distrito
     * (cliente, sala, empresa o establecimiento).
     */
    public static function problemaDe(object $entidad): ?string
    {
        return self::problema(
            $entidad->departamento_id !== null ? (int) $entidad->departamento_id : null,
            $entidad->municipio_id !== null ? (int) $entidad->municipio_id : null,
            $entidad->distrito_id !== null ? (int) $entidad->distrito_id : null,
        );
    }
}
