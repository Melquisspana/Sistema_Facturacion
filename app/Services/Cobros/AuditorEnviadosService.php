<?php

namespace App\Services\Cobros;

use App\Models\Cliente;
use App\Services\Cobros\AuditorEnviados\AdjuntoDteLeido;
use App\Services\Cobros\AuditorEnviados\AnalizadorAuditoriaEnviados;
use App\Services\Cobros\AuditorEnviados\CorreoConAdjuntosDte;
use App\Services\Cobros\AuditorEnviados\ResultadoAuditoriaEnviados;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Support\Carbon;

/**
 * Auditoría de SOLO LECTURA de los CCF/NC que salieron por Gmail (correos ENVIADOS)
 * para un cliente, antes de cualquier importación masiva al seguimiento de cobros.
 *
 * No escribe datos del negocio: ni en `cobro_documentos`, ni en mensajes de Gmail
 * (no marca, no etiqueta, no mueve). GmailClient puede renovar su token OAuth cifrado
 * en `gmail_cuentas` como parte normal de la conexión. Es la mecánica de Gmail + decodificación; la
 * clasificación en sí vive en {@see AnalizadorAuditoriaEnviados}, que no conoce a
 * Gmail y por eso se puede probar con listas de mentira.
 *
 * NO desbloquea CCF externos ni afirma que un CCF "no tiene NC": eso lo decide la
 * preparación de "quedan", que exige verificación explícita. Este auditor solo
 * informa qué apareció y qué no en el rango pedido.
 */
class AuditorEnviadosService
{
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly DteCorreoParser $parser,
        private readonly AnalizadorAuditoriaEnviados $analizador,
    ) {}

    /**
     * @throws \RuntimeException si el cliente no tiene número de documento fiscal
     *                           registrado: sin eso no hay forma de filtrar con seguridad (nunca se filtra
     *                           por nombre).
     */
    public function auditar(Cliente $cliente, Carbon $desde, Carbon $hasta, int $limite): ResultadoAuditoriaEnviados
    {
        $nit = AnalizadorAuditoriaEnviados::normalizarNit($cliente->num_documento);

        if ($nit === null) {
            throw new \RuntimeException(
                "El cliente {$cliente->nombre} (id {$cliente->id}) no tiene número de documento fiscal registrado; "
                .'no se puede auditar con seguridad sin él (nunca se filtra por nombre).'
            );
        }

        $query = $this->query($desde, $hasta);

        $correos = [];
        $token = null;
        $truncado = false;

        do {
            $pagina = $this->gmail->idsDeCobros($query, $token, 100);

            foreach ($pagina['ids'] as $id) {
                if (count($correos) >= $limite) {
                    $truncado = true;
                    break 2;
                }

                $correos[] = $this->leerCorreo($id);
            }

            $token = $pagina['siguiente'];
        } while (filled($token));

        if (! $truncado && filled($token)) {
            $truncado = true;
        }

        return $this->analizador->analizar($correos, $nit, $truncado);
    }

    /** Consulta de Gmail que usará {@see auditar()} para este rango (para mostrarla antes de correr). */
    public function query(Carbon $desde, Carbon $hasta): string
    {
        return sprintf(
            'in:sent filename:json after:%s before:%s',
            $desde->format('Y/m/d'),
            $hasta->copy()->addDay()->format('Y/m/d'),
        );
    }

    private function leerCorreo(string $messageId): CorreoConAdjuntosDte
    {
        $adjuntos = $this->gmail->adjuntos($messageId);
        $leidos = [];

        foreach ($adjuntos as $adjunto) {
            if (! $this->pareceJson((string) ($adjunto['filename'] ?? ''), (string) ($adjunto['mime'] ?? ''))) {
                continue;
            }

            $decodificado = $this->decoder->decodificar(
                (string) ($adjunto['data'] ?? ''),
                (string) ($adjunto['mime'] ?? ''),
                (string) ($adjunto['filename'] ?? ''),
            );

            if (! $decodificado['ok'] || ! is_array($decodificado['data'])) {
                continue;
            }

            $json = $decodificado['data'];
            $base = $this->parser->desdeJson($json);
            $receptor = $this->parser->receptor($json);
            $relacionados = $this->parser->documentoRelacionado($json);

            $leidos[] = new AdjuntoDteLeido(
                tipoDte: $base['tipoDte'],
                numeroControl: $base['numeroControl'],
                codigoGeneracion: $base['codigoGeneracion'],
                receptorNit: $receptor['nit'],
                documentoRelacionadoCodigos: array_values(array_filter(array_map(
                    static fn (array $rel) => $rel['numeroDocumento'],
                    $relacionados,
                ))),
            );
        }

        return new CorreoConAdjuntosDte($messageId, $leidos);
    }

    private function pareceJson(string $filename, string $mime): bool
    {
        return str_ends_with(strtolower($filename), '.json') || str_contains(strtolower($mime), 'json');
    }
}
