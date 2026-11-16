<?php

namespace Tests\Feature\Alertas;

use App\Enums\EstadoPedidoWeb;
use App\Models\PedidoWeb;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\PedidosWebParaRevisar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** El aviso de pedidos web que no se facturaron solos (ADR 0011). Datos inventados. */
class PedidosWebParaRevisarTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(int $n, EstadoPedidoWeb $estado): PedidoWeb
    {
        return PedidoWeb::create([
            'web_id' => $n, 'codigo' => 'LN-TEST-0000-000'.$n, 'idioma' => 'es', 'pagado_en' => now(),
            'alerta' => '', 'total_centavos' => 345, 'moneda' => 'USD',
            'cliente_nombre' => 'Cliente Secreto', 'cliente_correo' => 'secreto@example.test', 'cliente_telefono' => '70000000',
            'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [], 'pasarela' => 'prueba',
            'estado' => $estado, 'motivo_revision' => $estado === EstadoPedidoWeb::ParaRevisar ? 'Motivo inventado' : null,
        ]);
    }

    public function test_cuenta_los_pedidos_para_revisar_sin_datos_del_cliente(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'America/El_Salvador'));
        $detector = app(PedidosWebParaRevisar::class);
        $this->assertTrue($detector->aplica());
        $this->pedido(1, EstadoPedidoWeb::Importado);
        $this->assertNull($detector->revisar(), 'Sin pedidos para revisar, todo bien.');

        $this->pedido(2, EstadoPedidoWeb::ParaRevisar);
        $this->travel(5)->minutes();
        $this->pedido(3, EstadoPedidoWeb::ParaRevisar);
        $this->pedido(4, EstadoPedidoWeb::Facturado);

        $h = $detector->revisar();

        $this->assertSame(2, $h->conteo);
        $this->assertSame('2 pedidos web requieren atención', $h->titulo);
        $this->assertStringContainsString('LN-TEST-0000-0002', $h->detalle);
        $this->assertStringContainsString('LN-TEST-0000-0003', $h->detalle);
        $this->assertStringContainsString('lista de pedidos web', $h->queHacer);
        $this->assertStringContainsString('motivo y las alertas', $h->queHacer);
        $this->assertSame('2026-11-16 09:00', $h->desde->format('Y-m-d H:i'), 'Desde el más viejo.');
        $texto = $h->titulo.$h->detalle.$h->queHacer;
        $this->assertStringNotContainsString('Secreto', $texto);
        $this->assertStringNotContainsString('secreto@example.test', $texto);
        $this->assertStringNotContainsString('Motivo inventado', $texto, 'El motivo queda en el pedido, no en el correo.');
        $this->assertStringNotContainsString('$', $texto, 'Sin montos.');

        $this->assertNotNull(
            collect(app(Avisador::class)->detectores())->first(fn ($d) => $d instanceof PedidosWebParaRevisar),
            'Está entre los detectores que corre alertas:revisar.',
        );
    }

    public function test_una_factura_que_sigue_facturando_mas_de_media_hora_tambien_avisa(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'America/El_Salvador'));
        $detector = app(PedidosWebParaRevisar::class);
        $this->pedido(1, EstadoPedidoWeb::Facturando);

        $this->travel(PedidosWebParaRevisar::MINUTOS_TRABADO - 1)->minutes();
        $this->assertNull($detector->revisar(), 'Recién empezada todavía no está trabada.');

        $this->travel(2)->minutes();
        $h = $detector->revisar();
        $this->assertSame(1, $h->conteo);
        $this->assertStringContainsString('LN-TEST-0000-0001', $h->detalle);
    }
}
