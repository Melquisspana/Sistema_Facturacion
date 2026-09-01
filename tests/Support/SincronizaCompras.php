<?php

namespace Tests\Support;

use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Support\Carbon;

/**
 * Atajo para las pruebas que sincronizan compras: instala un buzón falso y corre el
 * rango. Evita repetir el armado en cinco archivos y, sobre todo, evita que cada uno
 * invente su propia semántica de paginación —que es justo donde vivía el error—.
 */
trait SincronizaCompras
{
    protected function instalarBuzon(MailboxClient $buzon): SincronizadorDocumentosRecibidos
    {
        $this->app->instance(MailboxClient::class, $buzon);

        return app(SincronizadorDocumentosRecibidos::class);
    }

    /** Corre el rango indicado. Por defecto, el día que usan los escenarios de prueba. */
    protected function sincronizar(
        string $desde = '2026-07-10',
        ?string $hasta = null,
        int $limite = 100,
        bool $aplicar = true,
    ): ResumenSincronizacion {
        return app(SincronizadorDocumentosRecibidos::class)->sincronizarRango(
            Carbon::parse($desde)->startOfDay(),
            Carbon::parse($hasta ?? $desde)->startOfDay(),
            $limite,
            $aplicar,
        );
    }
}
