<?php

namespace App\Services\Cobros;

use App\Support\Albaran;
use App\Support\Correo\MensajeActual;
use App\Support\IdentidadPpq;
use Illuminate\Support\Carbon;

/**
 * Interpreta los correos con los que el cliente responde a una solicitud.
 *
 * Los dos que manda hoy, tal como llegan:
 *
 *   Asunto: RECIBIDO (000123202609040951)
 *           REFERENCIA #31001
 *           PROGRAMACION DE PAGO: 07/09/2026
 *
 *   Asunto: OBSERVACIONES REF 31001
 *
 * ═══════════════════ Lee texto; no sabe nada de buzones ═══════════════════
 *
 * Esta clase recibe cadenas y devuelve datos. No abre Gmail, no marca mensajes y no manda
 * nada: eso le toca a {@see LectorCorreosCobro}, y separarlo es lo que permite probar la
 * interpretación contra los correos reales sin tocar una cuenta.
 *
 * ═══════════════════ Lo que NO hace, a propósito ═══════════════════
 *
 *  · **No supone que venga un PDF de quedan.** Hoy el acuse es texto; el día que traiga un
 *    adjunto, se agrega, pero nada de lo que hay acá depende de que exista.
 *  · **No adivina la referencia.** Si el correo no la trae, el resultado dice que no la
 *    trae. Un número cualquiera del cuerpo no es «la referencia»: hay fechas, importes y
 *    números de factura por todos lados.
 *  · **No inventa el tipo.** Un correo que no se reconoce es `desconocido` y va a revisión
 *    manual, que es trabajo pendiente y no basura.
 */
class CorreoCobroParser
{
    /** El acuse: «RECIBIDO (000123202609040951)». El paréntesis es el nombre del archivo. */
    private const RECIBIDO = '/\bRECIBIDO\b/iu';

    /** El otro correo: «OBSERVACIONES REF 31001». */
    private const OBSERVACIONES = '/\bOBSERVACION(ES)?\b/iu';

    /**
     * El archivo al que se refiere el acuse, EN SU FORMA MARCADA: `RECIBIDO (0001232026…)`.
     *
     * Se busca primero así, pegado a la palabra, porque un cuerpo HTML puede traer muchos
     * números largos —importes sin separadores, correlativos, números de orden— y el
     * primero que aparezca no tiene por qué ser el archivo.
     */
    private const ARCHIVO_MARCADO = '/\bRECIBIDO\b[^0-9A-Za-z]{0,10}\(?\s*(\d{12,20})(?:\.xlsx?)?\s*\)?/iu';

    /** «REFERENCIA #31001», «REF 31001», «REFERENCIA: 31001». */
    private const REFERENCIA = '/\bREF(?:ERENCIA)?\b\s*[#:]?\s*(\d{3,10})\b/iu';

    /** «PROGRAMACION DE PAGO: 07/09/2026». */
    private const PROGRAMACION = '/PROGRAMACI[OÓ]N\s+DE\s+PAGO\s*[:\-]?\s*([0-9]{1,2}[\/\-\.][0-9]{1,2}[\/\-\.][0-9]{2,4})/iu';

    /** Código de generación: UUID en mayúsculas, como lo escribe el MH. */
    private const CODIGO_GENERACION = '/\b([0-9A-F]{8}-\s*[0-9A-F]{4}-\s*[0-9A-F]{4}-\s*[0-9A-F]{4}-\s*[0-9A-F]{12})\b/i';

    /** Número de control: `DTE-03-M001P002-000000000090059`, con o sin guiones. */
    private const NUMERO_CONTROL = '/\bDTE[-\s]*\d{2}[-\s]*[A-Z]\d{3,4}[A-Z]\d{3,4}[-\s]*\d{6,20}\b/i';

    /**
     * Interpreta un mensaje completo (asunto + cuerpo).
     *
     * @return array{
     *     tipo: string,
     *     archivo_referido: ?string,
     *     referencia_calleja: ?string,
     *     fecha_programada_pago: ?string,
     *     codigos_generacion: array<int, string>,
     *     numeros_control: array<int, string>
     * }
     */
    public function interpretar(?string $asunto, ?string $cuerpo = null): array
    {
        $actual = MensajeActual::texto($cuerpo);
        $texto = trim(((string) $asunto)."\n".$actual);
        $tipo = preg_match('/\bRECHAZAD[OA]\b/iu', (string) $asunto)
            ? 'desconocido'
            : (preg_match(self::OBSERVACIONES, (string) $asunto) ? 'observaciones' : $this->tipo($texto));

        return [
            'tipo' => $tipo,
            'archivo_referido' => $this->archivo($texto),
            'referencia_calleja' => $this->referencia($texto),
            'fecha_programada_pago' => $tipo === 'recibido' ? $this->fechaProgramada($texto) : null,
            'codigos_generacion' => $this->codigosGeneracion($texto),
            'numeros_control' => $this->numerosControl($texto),
            'documentos' => $this->documentos($actual),
            'cuerpo_actual' => $actual,
        ];
    }

    /**
     * Qué clase de correo es. El acuse manda sobre la observación: un mensaje que dice
     * RECIBIDO y además trae observaciones sigue siendo, ante todo, el acuse de esa
     * solicitud.
     */
    private function tipo(string $texto): string
    {
        if (preg_match(self::RECIBIDO, $texto)) {
            return 'recibido';
        }

        if (preg_match(self::OBSERVACIONES, $texto)) {
            return 'observaciones';
        }

        return 'desconocido';
    }

    /**
     * El nombre del archivo al que se refiere el acuse. Es la evidencia que lo ata a UNA
     * solicitud.
     *
     * Solo se acepta la forma marcada. Un correlativo largo nunca prueba el archivo.
     */
    private function archivo(string $texto): ?string
    {
        if (preg_match(self::ARCHIVO_MARCADO, $texto, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Identidades por fila; solo se empareja cuando la fila trae un UUID y un control. */
    private function documentos(string $texto): array
    {
        $documentos = [];
        foreach (preg_split('/\n/', $texto) as $fila) {
            $codigos = $this->codigosGeneracion($fila);
            $controles = $this->numerosControl($fila);
            if (count($codigos) === 1 && count($controles) === 1) {
                $documentos[] = ['codigo_generacion' => $codigos[0], 'numero_control' => $controles[0], 'detalle' => trim($fila)];
            } else {
                foreach ($codigos as $codigo) {
                    $documentos[] = ['codigo_generacion' => $codigo, 'numero_control' => null, 'detalle' => trim($fila)];
                }
                foreach ($controles as $control) {
                    $documentos[] = ['codigo_generacion' => null, 'numero_control' => $control, 'detalle' => trim($fila)];
                }
            }
        }

        return array_values(collect($documentos)->unique(fn ($d) => ($d['codigo_generacion'] ?? '').'|'.($d['numero_control'] ?? ''))->all());
    }

    private function referencia(string $texto): ?string
    {
        return preg_match(self::REFERENCIA, $texto, $m) ? $m[1] : null;
    }

    /**
     * La fecha que el cliente programó para pagar. Se lee como salvadoreña (día/mes/año)
     * con {@see Albaran::fecha()}, que es el único sitio del sistema que sabe que
     * `07/09/2026` es septiembre y no julio.
     */
    private function fechaProgramada(string $texto): ?string
    {
        if (! preg_match(self::PROGRAMACION, $texto, $m)) {
            return null;
        }

        return Albaran::fecha($m[1]);
    }

    /** @return array<int, string> */
    private function codigosGeneracion(string $texto): array
    {
        preg_match_all(self::CODIGO_GENERACION, $texto, $m);

        return array_values(array_unique(array_map(fn ($codigo) => strtoupper((string) preg_replace('/\s+/', '', $codigo)), $m[1] ?? [])));
    }

    /**
     * Números de control mencionados, NORMALIZADOS con la misma regla de identidad que usa
     * todo el módulo. Así casan igual escritos con guiones o sin ellos.
     *
     * @return array<int, string>
     */
    private function numerosControl(string $texto): array
    {
        preg_match_all(self::NUMERO_CONTROL, $texto, $m);

        $claves = [];
        foreach ($m[0] ?? [] as $encontrado) {
            $clave = IdentidadPpq::normalizar($encontrado);
            if ($clave !== null) {
                $claves[$clave] = true;
            }
        }

        return array_keys($claves);
    }

    /** Fecha del mensaje, tolerando el formato de cabecera de correo. */
    public function fechaMensaje(?string $cabecera): ?Carbon
    {
        if (blank($cabecera)) {
            return null;
        }

        return rescue(fn () => Carbon::parse($cabecera), null, false);
    }
}
