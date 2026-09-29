<?php

namespace Tests\Feature\Cobros;

use App\Services\Ppq\GmailClient;

/**
 * Gmail en memoria para la importación de enviados: `idsDeCobros` sirve una página por
 * índice y `adjuntos` devuelve los adjuntos declarados. No usa ninguna conexión real.
 */
class GmailClientDeImportacion extends GmailClient
{
    /**
     * @param  array<int, array<int, string>>  $paginas
     * @param  array<string, array<int, array<string, string>>>  $adjuntosPorId
     */
    public function __construct(private readonly array $paginas, private readonly array $adjuntosPorId)
    {
        parent::__construct();
    }

    public function disponible(): bool
    {
        return true;
    }

    public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
    {
        $indice = (int) ($token ?? 0);

        return [
            'ids' => $this->paginas[$indice] ?? [],
            'siguiente' => isset($this->paginas[$indice + 1]) ? (string) ($indice + 1) : null,
        ];
    }

    public function adjuntos(string $messageId): array
    {
        return $this->adjuntosPorId[$messageId] ?? [];
    }
}
