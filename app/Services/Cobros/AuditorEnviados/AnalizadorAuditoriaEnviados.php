<?php

namespace App\Services\Cobros\AuditorEnviados;

use App\Services\Cobros\AuditorEnviadosService;

/**
 * Clasifica los correos ya leídos y decodificados. NO habla con Gmail, no decodifica
 * JSON y no sabe qué es un adjunto: solo recibe {@see CorreoConAdjuntosDte} ya
 * construidos y produce conteos. Separarlo de {@see AuditorEnviadosService}
 * (que sí llama a Gmail) es lo que permite probar toda esta clasificación con listas
 * de mentira, sin tocar una cuenta real.
 *
 * NUNCA decide "el CCF no existe": cuando una NC declara un CCF relacionado que este
 * barrido no vio, se limita a informarlo como "no apareció en el barrido" — puede
 * estar fuera del rango pedido o detrás del límite. Este auditor no desbloquea nada.
 */
final class AnalizadorAuditoriaEnviados
{
    /**
     * @param  array<int, CorreoConAdjuntosDte>  $correos  en el orden en que se recorrieron
     */
    public function analizar(array $correos, string $nitClienteNormalizado, bool $truncado): ResultadoAuditoriaEnviados
    {
        [$unicos, $correosDuplicados] = $this->deduplicarCorreos($correos);

        $sinJson = 0;
        $receptorDistinto = 0;
        $receptorNoIdentificable = 0;
        $tipoDistinto = 0;
        $incompletos = 0;
        $ccfCount = 0;
        $ncCount = 0;
        $ncSinRelacion = 0;
        $dtesRepetidos = 0;

        /** @var array<string, string> código de generación => id del primer correo donde apareció */
        $origenPorCodigo = [];
        /** @var array<string, true> */
        $ccfVistos = [];
        /** @var array<string, array<int, string>> NC => sus códigos de CCF relacionados */
        $relacionesPorNc = [];
        /** @var array<string, true> */
        $codigosDuplicados = [];

        foreach ($unicos as $correo) {
            if ($correo->adjuntos === []) {
                $sinJson++;

                continue;
            }

            foreach ($correo->adjuntos as $adjunto) {
                $nit = self::normalizarNit($adjunto->receptorNit);

                if ($nit === null) {
                    $receptorNoIdentificable++;

                    continue;
                }

                if ($nit !== $nitClienteNormalizado) {
                    $receptorDistinto++;

                    continue;
                }

                if (! in_array($adjunto->tipoDte, ['03', '05'], true)) {
                    $tipoDistinto++;

                    continue;
                }

                if (blank($adjunto->numeroControl) || blank($adjunto->codigoGeneracion)) {
                    $incompletos++;

                    continue;
                }

                $codigo = strtoupper($adjunto->codigoGeneracion);

                if (isset($origenPorCodigo[$codigo])) {
                    if ($origenPorCodigo[$codigo] !== $correo->messageId) {
                        $dtesRepetidos++;
                        $codigosDuplicados[$codigo] = true;
                    }

                    // Mismo documento ya contado (de este correo o de otro): no se recuenta.
                    continue;
                }
                $origenPorCodigo[$codigo] = $correo->messageId;

                if ($adjunto->tipoDte === '03') {
                    $ccfCount++;
                    $ccfVistos[$codigo] = true;

                    continue;
                }

                $ncCount++;
                if ($adjunto->documentoRelacionadoCodigos === []) {
                    $ncSinRelacion++;
                }
                $relacionesPorNc[$codigo] = array_map(strtoupper(...), $adjunto->documentoRelacionadoCodigos);
            }
        }

        $faltantes = [];
        foreach ($relacionesPorNc as $relacionados) {
            foreach ($relacionados as $rel) {
                if ($rel !== '' && ! isset($ccfVistos[$rel])) {
                    $faltantes[$rel] = true;
                }
            }
        }

        return new ResultadoAuditoriaEnviados(
            correosRevisados: count($unicos),
            correosDuplicados: $correosDuplicados,
            correosSinJsonLegible: $sinJson,
            correosReceptorDistinto: $receptorDistinto,
            correosReceptorNoIdentificable: $receptorNoIdentificable,
            dtesTipoDistinto: $tipoDistinto,
            dtesIncompletos: $incompletos,
            ccf: $ccfCount,
            nc: $ncCount,
            ncSinRelacion: $ncSinRelacion,
            dtesRepetidosEnOtroCorreo: $dtesRepetidos,
            truncado: $truncado,
            ncSinCcfEnBarrido: array_keys($faltantes),
            codigosDuplicados: array_keys($codigosDuplicados),
        );
    }

    /**
     * Quita los correos que el barrido haya devuelto más de una vez (mismo
     * `messageId` como entrada repetida de la lista, no el mismo correo con varios
     * adjuntos). Se queda con la PRIMERA aparición.
     *
     * @param  array<int, CorreoConAdjuntosDte>  $correos
     * @return array{0: array<int, CorreoConAdjuntosDte>, 1: int}
     */
    private function deduplicarCorreos(array $correos): array
    {
        $vistos = [];
        $unicos = [];
        $duplicados = 0;

        foreach ($correos as $correo) {
            if (isset($vistos[$correo->messageId])) {
                $duplicados++;

                continue;
            }
            $vistos[$correo->messageId] = true;
            $unicos[] = $correo;
        }

        return [$unicos, $duplicados];
    }

    /**
     * Normaliza un NIT/DUI a solo dígitos, igual criterio que usan los serializadores
     * del MH. Vacío o solo separadores => null ("no identificable"), nunca "0".
     */
    public static function normalizarNit(?string $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor);

        return filled($digitos) ? $digitos : null;
    }
}
