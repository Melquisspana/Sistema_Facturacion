<?php

namespace Tests\Feature\Alertas;

use App\Facades\Ajustes;
use App\Services\Alertas\Detectores\TiendaWebFallas;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hallazgo del probador: una cantidad enorme en la lista de errores de la página (un
 * contador roto, un dato raro) desbordaba la suma del conteo y el aviso reventaba sin
 * avisar nada. Datos inventados; HTTP falso.
 */
class CantidadesEnormesTiendaWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_cantidades_enormes_de_la_pagina_no_tumban_los_avisos_de_pagos_ni_de_otras_fallas(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', 'secreto-inventado-solo-para-pruebas-123456');
        Ajustes::guardarComoSistema('tienda.pedidos.automatica', true);
        $ahora = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $fila = fn (string $tipo, string $detalle) => ['tipo' => $tipo, 'detalle' => $detalle, 'cantidad' => PHP_INT_MAX, 'primera' => $ahora, 'ultima' => $ahora];
        Http::preventStrayRequests();
        Http::fake([
            '*/pedidos-pagados*' => Http::response(['pedidos' => []]),
            '*/pedidos-a-revisar*' => Http::response(['pedidos' => []]),
            '*/errores' => Http::response(['cobra' => true, 'errores' => [
                $fila('error_cobro', 'token HTTP 401'), $fila('error_cobro', 'EnlacePago HTTP 502'),
                $fila('error_interno', 'hacer_pedido · Error'), $fila('error_interno', 'aviso_pago · Error'),
            ]]),
            '*/limpieza' => Http::response([], 200),
        ]);

        app(ImportarPedidosWeb::class)->ejecutar(true);

        $pagos = TiendaWebFallas::pagos()->revisar();
        $otras = TiendaWebFallas::otras()->revisar();
        $this->assertSame('La tienda web tiene 2 problemas', $pagos?->titulo);
        $this->assertSame('La tienda web tiene 2 problemas', $otras?->titulo);
        $this->assertIsInt($pagos->conteo);
        $this->assertIsInt($otras->conteo);
        foreach (Cache::get(ImportarPedidosWeb::CLAVE_ERRORES)['errores'] as $guardada) {
            $this->assertLessThanOrEqual(1_000_000, $guardada['cantidad']);
        }
    }
}
