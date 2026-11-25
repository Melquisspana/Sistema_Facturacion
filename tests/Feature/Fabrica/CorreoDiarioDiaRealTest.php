<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPedidoWeb;
use App\Enums\OrigenPedidoSala;
use App\Enums\TipoDte;
use App\Mail\AlertaSistemaCorreo;
use App\Mail\DteCorreo;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PdfFabricaDiario;
use App\Services\Rutas\PedidosFabrica;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * El correo de las 18:00 en un día como los de la fábrica (Melqui, 25/11/2026): pedidos que
 * llegan de mañana y de tarde, uno que sale entero, uno que sale en parte con lo que faltó,
 * uno listo que no salió, uno que se está armando desde ayer, uno que nadie tocó en tres días
 * y uno de la tienda web. Se mira el correo como se lee (HTML y texto), el PDF adjunto y el
 * mensaje tal como saldría: el logo dentro del correo y ningún enlace al sistema.
 *
 * Datos y correos inventados. Mail::fake y, para ver el mensaje armado, el transporte de
 * prueba (array), que lo guarda en memoria y no lo entrega a nadie.
 */
class CorreoDiarioDiaRealTest extends TestCase
{
    use RefreshDatabase;

    private const PARTES = ['Llegó de los vendedores', 'Salió de la fábrica', 'Quedó allá', 'Ventas del día'];

    private User $fabrica;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Establecimiento $establecimiento;

    private Producto $galleta;

    private Producto $canilla;

    private Producto $quesadilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 08:00:00');
        Http::preventStrayRequests();
        config()->set('pedidos.correo_resumen_diario', 'oficina@ejemplo.test');
        config(['dte.ambiente' => '01']);

        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => 'Confitería de prueba', 'ambiente' => '01', 'activo' => true]);
        $this->establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);
        $this->quesadilla = Producto::factory()->create(['nombre' => 'Quesadilla de prueba', 'codigo' => 'Q01']);

        // Se sustituye el candado, no el entorno: la suite no puede mandar correo real.
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Mail::fake();
    }

    // ══════════════════════════════ ayudas

    private function sala(string $nombre): ClienteSucursal
    {
        return Cliente::factory()->create(['nombre' => $nombre])
            ->sucursales()->create(['nombre' => $nombre, 'codigo' => (string) random_int(1000, 9999), 'ruta_id' => $this->ruta->id]);
    }

    /**
     * Un pedido que el vendedor sacó ahora (la hora del viaje en el tiempo).
     *
     * @param  array<int, string>  $cantidades  [producto_id => cantidad]
     */
    private function pedido(ClienteSucursal $sala, array $cantidades, ?string $fecha = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->persona->user_id,
            'fecha' => $fecha ?? now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    /** Un pedido de la tienda web, pagado y con su factura aceptada: la fábrica ya lo puede mandar. */
    private function pedidoWeb(): PedidoSala
    {
        $pedido = PedidoSala::create(['origen' => OrigenPedidoSala::Web, 'fecha' => now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado]);
        $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => 12]);
        $dte = Dte::withoutEvents(fn () => Dte::create([
            'tipo_dte' => TipoDte::Factura, 'estado' => EstadoDte::Aceptado, 'ambiente' => '00',
            'establecimiento_id' => $this->establecimiento->id, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->format('H:i:s'),
            'receptor_nombre' => 'Compradora Inventada', 'receptor_correo' => 'compradora@example.test',
        ]));
        PedidoWeb::create([
            'pedido_sala_id' => $pedido->id, 'web_id' => 1, 'codigo' => 'LN-TEST-0000-0001', 'idioma' => 'es',
            'pagado_en' => now(), 'total_centavos' => 98765, 'moneda' => 'USD',
            'cliente_nombre' => 'Compradora Inventada', 'cliente_correo' => 'compradora@example.test', 'cliente_telefono' => 'telefono-secreto',
            'direccion' => 'direccion-secreta', 'entrega_tipo' => 'recoger', 'pasarela' => 'prueba',
            'estado' => EstadoPedidoWeb::Facturado, 'informado_preparando_en' => now(), 'dte_id' => $dte->id,
            'lineas' => [['producto' => 'gall', 'nombre' => 'Galleta de prueba', 'cantidad' => 12, 'precio' => 98765]],
            'cajitas_detalle' => [['n' => 1, 'clase' => 'surtido', 'surtido' => 'La clásica', 'tam' => 12, 'cantidad' => 2, 'contenido' => ['gall' => 6], 'precio' => 98765]],
        ]);

        return $pedido;
    }

    private function version(PedidoSala $pedido): string
    {
        return PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();
    }

    private function linea(PedidoSala $pedido, Producto $producto): int
    {
        return (int) $pedido->lineas()->where('producto_id', $producto->id)->value('id');
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $motivos = [], bool $sigue = false): void
    {
        $porLinea = fn (array $datos) => collect($datos)->mapWithKeys(
            fn ($valor, $producto) => [$pedido->lineas()->where('producto_id', $producto)->value('id') => $valor],
        )->all();

        app(PedidosFabrica::class)->mandar($pedido, [
            'clave' => (string) Str::uuid(), 'version' => $this->version($pedido),
            'cantidades' => $porLinea($cantidades), 'motivos' => $porLinea($motivos), 'sigue' => $sigue,
        ], $this->fabrica);
    }

    /** Un CCF aceptado de hoy por `$monto` sin IVA, de los que cuenta la pantalla de Ventas. */
    private function venta(float $monto): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        Dte::withoutEvents(function () use ($monto, $cliente) {
            $dte = Dte::create([
                'establecimiento_id' => $this->establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'sello_recepcion' => (string) Str::uuid(), 'fecha_procesamiento_mh' => now(), 'cliente_id' => $cliente->id,
                'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
                'fecha_emision' => now()->toDateString(), 'hora_emision' => '10:00:00',
                'subtotal' => $monto, 'total_descuento' => 0, 'total_pagar' => $monto,
            ]);
            DteLinea::withoutEvents(fn () => DteLinea::create([
                'dte_id' => $dte->id, 'numero_linea' => 1, 'tipo_impuesto' => 'gravado', 'producto_id' => $this->galleta->id,
                'descripcion' => 'Galleta de prueba', 'cantidad' => 1, 'precio_unitario' => $monto, 'venta_gravada' => $monto, 'total_linea' => $monto,
            ]));
        });
    }

    /** @return list<ResumenDiarioPedidosCorreo> */
    private function enviados(): array
    {
        return Mail::sent(ResumenDiarioPedidosCorreo::class)->values()->all();
    }

    /** Un HTML como lo lee la persona: sin etiquetas ni estilos, en una línea. */
    private function leido(string $html): string
    {
        $cuerpo = (string) preg_replace('/^.*<body[^>]*>/s', '', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cuerpo))));
    }

    /**
     * El mensaje tal como saldría, armado por el transporte de prueba (array): se queda en
     * memoria y no se entrega. Un MailManager aparte, porque el de la fachada está falseado.
     */
    private function mensaje(ResumenDiarioPedidosCorreo $correo): Email
    {
        $mailer = (new MailManager($this->app))->mailer('array');
        $mailer->to('oficina@ejemplo.test')->send($correo);

        return $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    private function plano(ResumenDiarioPedidosCorreo $correo): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) $this->mensaje($correo)->getTextBody());
    }

    /** Lo que dice una parte, sin su título: hasta el título de la parte que sigue. */
    private function parte(string $texto, string $titulo): string
    {
        $inicio = strpos($texto, $titulo);
        $this->assertNotFalse($inicio, 'Falta la parte «'.$titulo.'»');
        $inicio += strlen($titulo);
        $siguiente = self::PARTES[array_search($titulo, self::PARTES, true) + 1] ?? null;
        $fin = $siguiente !== null ? strpos($texto, $siguiente, $inicio) : false;

        return trim(substr($texto, $inicio, $fin === false ? null : $fin - $inicio));
    }

    /** @param  list<string>  $textos */
    private function assertEnOrden(string $texto, array $textos, string $donde): void
    {
        $posiciones = array_map(fn (string $t) => strpos($texto, $t), $textos);
        $this->assertNotContains(false, $posiciones, $donde.': falta algo de '.implode(' / ', $textos));
        $ordenadas = $posiciones;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posiciones, $donde.': no va en este orden: '.implode(' / ', $textos));
    }

    // ══════════════════════════════ un día real

    public function test_un_dia_real_sale_en_tres_partes_en_el_html_el_texto_y_el_pdf(): void
    {
        $fabrica = app(PedidosFabrica::class);

        // Viernes 06/11: un pedido que nadie toca en tres días.
        $this->travelTo('2026-11-06 09:00:00');
        $abarrotes = $this->pedido($this->sala('Abarrotes Inventados'), [$this->canilla->id => '2']);

        // Domingo 08/11: uno que empiezan a armar y el correo de las 18:00.
        $this->travelTo('2026-11-08 10:00:00');
        $kiosco = $this->pedido($this->sala('Kiosco Inventado'), [$this->galleta->id => '8']);
        $this->travelTo('2026-11-08 16:00:00');
        $fabrica->guardarBorrador($kiosco, ['cantidades' => [$this->linea($kiosco, $this->galleta) => '4']], $this->fabrica);
        $this->travelTo('2026-11-08 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        $this->assertMatchesRegularExpression('/Armando\s*Kiosco Inventado: N\.º '.$kiosco->id.', de hoy/u', $this->leido($this->enviados()[0]->render()));

        // Domingo 19:30, después del correo: un vendedor deja sacado el de mañana.
        $this->travelTo('2026-11-08 19:30:00');
        $panaderia = $this->pedido($this->sala('Panadería Ficticia'), [$this->quesadilla->id => '3'], '2026-11-09');

        // Lunes 09/11, de mañana.
        $this->travelTo('2026-11-09 08:15:00');
        $centro = $this->sala('Súper Ficticio Centro');
        $entero = $this->pedido($centro, [$this->galleta->id => '24', $this->canilla->id => '6']);
        $this->travelTo('2026-11-09 09:40:00');
        $norte = $this->pedido($this->sala('Tienda Inventada Norte'), [$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->travelTo('2026-11-09 10:05:00');
        $web = $this->pedidoWeb();
        $this->travelTo('2026-11-09 11:00:00');
        $this->mandar($entero, [$this->galleta->id => '24', $this->canilla->id => '6']);
        $this->travelTo('2026-11-09 12:00:00');
        $this->mandar($web, [$this->galleta->id => '12']);

        // De tarde.
        $this->travelTo('2026-11-09 14:30:00');
        $listo = $this->pedido($this->sala('Bodega Inventada Sur'), [$this->galleta->id => '6']);
        $this->travelTo('2026-11-09 15:00:00');
        $this->mandar($norte, [$this->galleta->id => '10', $this->canilla->id => '2'], [$this->canilla->id => 'Sin harina']);
        $this->travelTo('2026-11-09 15:10:00');
        $enParte = $this->pedido($centro, [$this->quesadilla->id => '4']);
        $this->travelTo('2026-11-09 16:00:00');
        $this->assertTrue($fabrica->marcarListo($listo, true, $this->fabrica, $this->version($listo)));
        $this->travelTo('2026-11-09 16:30:00');
        $this->mandar($enParte, [$this->quesadilla->id => '1'], sigue: true);
        $this->travelTo('2026-11-09 17:00:00');
        $nuevo = $this->pedido($this->sala('Mini Súper Inventado'), [$this->galleta->id => '2']);

        // 18:00: sale el correo, y no se repite.
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        $this->travelTo('2026-11-09 18:05:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('El resumen de hoy ya se había mandado.')->assertSuccessful();
        $this->assertCount(2, $this->enviados());
        $correo = $this->enviados()[1];
        $this->assertSame('Pedidos del 09/11/2026', $correo->envelope()->subject);

        // ── El correo como se lee.
        $texto = $this->leido($correo->render());
        $this->assertEnOrden($texto, self::PARTES, 'HTML');

        // 1. Llegó: desde el correo de ayer a las 18:00, de mañana y de tarde, una línea por sala.
        $this->assertSame(
            'Bodega Inventada Sur: N.º '.$listo->id.' · Carlos Inventado · 1 producto'
            .' Mini Súper Inventado: N.º '.$nuevo->id.' · Carlos Inventado · 1 producto'
            .' Panadería Ficticia: N.º '.$panaderia->id.' · Carlos Inventado · 1 producto'
            .' Pedido web · Compradora I.: N.º '.$web->id.' · 1 producto'
            .' Súper Ficticio Centro: N.º '.$entero->id.' · Carlos Inventado · 2 productos; N.º '.$enParte->id.' · Carlos Inventado · 1 producto'
            .' Tienda Inventada Norte: N.º '.$norte->id.' · Carlos Inventado · 2 productos',
            $this->parte($texto, 'Llegó de los vendedores'),
        );

        // 2. Salió: el entero y el que va en parte, juntos en su sala; lo que faltó, con su motivo.
        $this->assertSame(
            'Pedido web · Compradora I.: 2 cajitas.'
            .' Súper Ficticio Centro: pedidos N.º '.$entero->id.' y '.$enParte->id.', 3 productos.'
            .' Tienda Inventada Norte: pedido N.º '.$norte->id.', 2 productos. Faltó Canilla de prueba 3 (Sin harina).',
            $this->parte($texto, 'Salió de la fábrica'),
        );

        // 3. Quedó allá: listos, armando y sin empezar, cada uno con su día.
        $this->assertSame(
            'Listos para salir Bodega Inventada Sur: N.º '.$listo->id.', de hoy'
            .' Armando Kiosco Inventado: N.º '.$kiosco->id.', de ayer'
            .' Sin empezar Abarrotes Inventados: N.º '.$abarrotes->id.', del 06/11'
            .' Mini Súper Inventado: N.º '.$nuevo->id.', de hoy'
            .' Panadería Ficticia: N.º '.$panaderia->id.', de hoy'
            .' Súper Ficticio Centro: el resto del N.º '.$enParte->id.', de hoy',
            $this->parte($texto, 'Quedó allá'),
        );
        $this->assertStringStartsWith('Hoy no se facturó nada.', $this->parte($texto, 'Ventas del día'));
        foreach (['Por mandar:', 'Faltaron cosas', 'Ver en el sistema', 'No llegaron', 'Hoy no salió', 'No quedó nada'] as $noVa) {
            $this->assertStringNotContainsString($noVa, $texto);
        }
        $this->assertDoesNotMatchRegularExpression('/\b0 (productos?|pedidos?|salas?|envíos?|cajitas?)\b/u', $texto);

        // ── El PDF adjunto: las mismas partes, con los productos, y después cada envío.
        $this->assertStringStartsWith('%PDF', $correo->pdf['pedidos-del-2026-11-09.pdf'] ?? '');
        $pdf = $this->leido(app(PdfFabricaDiario::class)->html($correo->contenido));
        $this->assertEnOrden($pdf, ['RESUMEN DE PEDIDOS', 'Llegó de los vendedores', 'Salió de la fábrica', 'Quedó allá', 'Listos para salir', 'Armando', 'Sin empezar', 'HOJA DE DESPACHO'], 'PDF');
        $this->assertSame(1, substr_count($pdf, 'RESUMEN DE PEDIDOS'));
        $this->assertSame(4, substr_count($pdf, 'HOJA DE DESPACHO'), 'una hoja por envío: el entero, el web, el de Norte y el que va en parte');
        $llegoPdf = $this->parte($pdf, 'Llegó de los vendedores');
        foreach ([
            'Súper Ficticio Centro N.º '.$entero->id.' Carlos Inventado Galleta de prueba 24, Canilla de prueba 6',
            'Súper Ficticio Centro N.º '.$enParte->id.' Carlos Inventado Quesadilla de prueba 4',
            'Pedido web · Compradora I. N.º '.$web->id.' — Galleta de prueba 12',
            'Panadería Ficticia N.º '.$panaderia->id.' Carlos Inventado Quesadilla de prueba 3',
        ] as $fila) {
            $this->assertStringContainsString($fila, $llegoPdf);
        }
        $this->assertStringNotContainsString('Kiosco', $llegoPdf);
        $salioPdf = $this->parte($pdf, 'Salió de la fábrica');
        $this->assertStringContainsString('N.º '.$entero->id.' · 11:00: Galleta de prueba 24, Canilla de prueba 6 N.º '.$enParte->id.' · 16:30: Quesadilla de prueba 1', $salioPdf);
        $this->assertStringContainsString('Tienda Inventada Norte N.º '.$norte->id.' · 15:00: Galleta de prueba 10, Canilla de prueba 2 Canilla de prueba 3 — Sin harina', $salioPdf);
        $this->assertStringContainsString('N.º '.$web->id.' · 12:00: Galleta de prueba 12', $salioPdf);
        $quedoPdf = $this->parte($pdf, 'Quedó allá');
        // Cada grupo con su columna («Va en la caja» o «Falta mandar») y la fecha de cada pedido.
        $this->assertEnOrden($quedoPdf, [
            'Listos para salir', 'Va en la caja', 'Bodega Inventada Sur N.º '.$listo->id.' 09/11/2026 Galleta de prueba 6',
            'Armando', 'Falta mandar', 'Kiosco Inventado N.º '.$kiosco->id.' 08/11/2026 Galleta de prueba 8',
            'Sin empezar', 'Abarrotes Inventados N.º '.$abarrotes->id.' 06/11/2026 Canilla de prueba 2',
            'Súper Ficticio Centro N.º '.$enParte->id.' (el resto) 09/11/2026 Quesadilla de prueba 3',
        ], 'PDF, Quedó allá');
        $this->assertSame(2, substr_count($quedoPdf, 'Falta mandar'));
        // Lo privado del cliente de la tienda no va ni en el correo ni en el PDF.
        foreach (['Compradora Inventada', 'compradora@example.test', 'telefono-secreto', 'direccion-secreta', '98765', '987.65'] as $privado) {
            $this->assertStringNotContainsString($privado, $texto);
            $this->assertStringNotContainsString($privado, $pdf);
        }

        // ── El mensaje tal como sale: el texto plano en el mismo orden y el logo dentro del correo.
        $mensaje = $this->mensaje($correo);
        $plano = (string) preg_replace('/\s+/u', ' ', (string) $mensaje->getTextBody());
        $this->assertEnOrden($plano, self::PARTES, 'texto plano');
        $this->assertEnOrden($plano, [
            'Bodega Inventada Sur**: N.º '.$listo->id.' · Carlos Inventado · 1 producto',
            'Tienda Inventada Norte**: pedido N.º '.$norte->id.', 2 productos. Faltó Canilla de prueba 3 (Sin harina).',
            'Listos para salir', 'Bodega Inventada Sur**: N.º '.$listo->id.', de hoy',
            'Armando', 'Kiosco Inventado**: N.º '.$kiosco->id.', de ayer',
            'Sin empezar', 'Abarrotes Inventados**: N.º '.$abarrotes->id.', del 06/11',
            'Súper Ficticio Centro**: el resto del N.º '.$enParte->id.', de hoy',
            'Hoy no se facturó nada.',
            'El detalle de cada pedido, con sus productos, va en el PDF adjunto.',
        ], 'texto plano');
        // Se lee limpio: sin etiquetas, sin enlaces y sin las barras con que el HTML escapa el markdown.
        $this->assertDoesNotMatchRegularExpression('/<[a-z\/][^>]*>/i', $plano);
        $this->assertStringNotContainsString('http', $plano);
        $this->assertStringContainsString('Pedido web · Compradora I.**: 2 cajitas.', $plano);
        $this->assertStringNotContainsString('\\', $plano);

        $html = (string) $mensaje->getHtmlBody();
        $this->assertSame(1, preg_match('/<img src="cid:([^"]+)"[^>]*class="doc-logo"/', $html, $m));
        $this->assertDoesNotMatchRegularExpression('/<img\b[^>]*src="(?!cid:)/i', $html, 'ninguna imagen se pide afuera');
        $this->assertDoesNotMatchRegularExpression('/<a\b/i', $html);
        $this->assertStringNotContainsString((string) config('app.url'), $html);
        $adjuntos = collect($mensaje->getAttachments());
        $this->assertSame('inline', $adjuntos->first(fn ($a) => $a->getContentId() === $m[1])?->getDisposition());
        $this->assertSame(['pedidos-del-2026-11-09.pdf'], $adjuntos->filter(fn ($a) => $a->getDisposition() === 'attachment')
            ->map(fn ($a) => $a->getFilename())->values()->all());
    }

    public function test_el_texto_plano_lleva_lo_escrito_tal_cual_sin_las_barras_del_markdown(): void
    {
        // En el HTML, lo escrito se escapa para que el markdown no arme enlaces ni títulos; el
        // texto plano no pasa por markdown, así que ahí las barras quedaban a la vista.
        $this->travelTo('2026-11-09 09:00:00');
        $pedido = $this->pedido($this->sala('Súper 2-B (Centro) S.A.'), [$this->galleta->id => '4', $this->canilla->id => '2']);
        $this->travelTo('2026-11-09 15:00:00');
        $this->mandar($pedido, [$this->galleta->id => '4', $this->canilla->id => '0'], [$this->canilla->id => 'No hubo masa (se acabó) - mañana']);

        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        $correo = $this->enviados()[0];

        $plano = $this->plano($correo);
        $this->assertStringContainsString('Súper 2-B (Centro) S.A.**: N.º '.$pedido->id.' · Carlos Inventado · 2 productos', $plano);
        $this->assertStringContainsString('Súper 2-B (Centro) S.A.**: pedido N.º '.$pedido->id.', 1 producto. Faltó Canilla de prueba 2 (No hubo masa (se acabó) - mañana).', $plano);
        $this->assertStringNotContainsString('\\', $plano);
        // El HTML sigue igual: se lee tal cual y sin barras.
        $texto = $this->leido($correo->render());
        $this->assertStringContainsString('Súper 2-B (Centro) S.A.: pedido N.º '.$pedido->id.', 1 producto. Faltó Canilla de prueba 2 (No hubo masa (se acabó) - mañana).', $texto);
        $this->assertStringNotContainsString('\\', $texto);
    }

    // ══════════════════════════════ días sin pedidos

    public function test_un_dia_sin_nada_dice_las_tres_frases_tambien_en_el_texto_y_no_adjunta_el_pdf(): void
    {
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();

        $correo = $this->enviados()[0];
        $this->assertSame([], $correo->pdf);
        $texto = $this->leido($correo->render());
        $this->assertSame('No llegaron pedidos nuevos.', $this->parte($texto, 'Llegó de los vendedores'));
        $this->assertSame('Hoy no salió ningún pedido.', $this->parte($texto, 'Salió de la fábrica'));
        $this->assertSame('No quedó nada por mandar.', $this->parte($texto, 'Quedó allá'));
        $this->assertStringContainsString('Hoy no se facturó nada.', $texto);
        $this->assertStringNotContainsString('PDF adjunto', $texto);

        $plano = $this->plano($correo);
        $this->assertEnOrden($plano, ['No llegaron pedidos nuevos.', 'Hoy no salió ningún pedido.', 'No quedó nada por mandar.', 'Hoy no se facturó nada.'], 'texto plano');
        foreach (['Listos para salir', 'Armando', 'Sin empezar', 'PDF adjunto'] as $noVa) {
            $this->assertStringNotContainsString($noVa, $plano);
        }
    }

    public function test_un_dia_con_solo_ventas_dice_lo_vendido_y_las_tres_partes_vacias(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $this->venta(150.25);
        $this->venta(49.75);

        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();

        $correo = $this->enviados()[0];
        $this->assertSame([], $correo->pdf);
        $vendido = 'Vendido hoy hasta las 6 de la tarde: $200.00 en 2 facturas.';
        $texto = $this->leido($correo->render());
        $this->assertEnOrden($texto, ['No llegaron pedidos nuevos.', 'Hoy no salió ningún pedido.', 'No quedó nada por mandar.', $vendido], 'HTML');
        $this->assertStringNotContainsString('Hoy no se facturó nada', $texto);
        $this->assertStringNotContainsString('PDF adjunto', $texto);
        $this->assertEnOrden($this->plano($correo), ['No quedó nada por mandar.', $vendido], 'texto plano');
    }

    // ══════════════════════════════ a mano y el de las 18:00

    public function test_a_mano_sale_con_su_hora_y_el_de_las_seis_sale_igual_una_sola_vez(): void
    {
        $this->travelTo('2026-11-09 09:00:00');
        $manana = $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '5']);
        $this->venta(10);

        // A mano a mediodía: sale ya, con la hora en que salió.
        $this->travelTo('2026-11-09 12:30:00');
        $this->artisan('pedidos:correo-diario', ['--forzar' => true])->expectsOutput('Resumen enviado.')->assertSuccessful();
        $aMano = $this->leido($this->enviados()[0]->render());
        $this->assertSame('Súper Ficticio Centro: N.º '.$manana->id.' · Carlos Inventado · 1 producto', $this->parte($aMano, 'Llegó de los vendedores'));
        $this->assertStringContainsString('Vendido hoy hasta las 12:30 de la tarde: $10.00 en 1 factura.', $aMano);

        // De tarde llega otro; el de las 18:00 trae los dos.
        $this->travelTo('2026-11-09 15:00:00');
        $tarde = $this->pedido($this->sala('Tienda Inventada Norte'), [$this->canilla->id => '3']);
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        $seis = $this->leido($this->enviados()[1]->render());
        $this->assertSame(
            'Súper Ficticio Centro: N.º '.$manana->id.' · Carlos Inventado · 1 producto Tienda Inventada Norte: N.º '.$tarde->id.' · Carlos Inventado · 1 producto',
            $this->parte($seis, 'Llegó de los vendedores'),
        );
        $this->assertStringContainsString('Vendido hoy hasta las 6 de la tarde: $10.00 en 1 factura.', $seis);

        // Correrlo otra vez no lo repite.
        $this->travelTo('2026-11-09 18:01:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('El resumen de hoy ya se había mandado.')->assertSuccessful();
        $this->assertCount(2, $this->enviados());

        // A mano después de las 18:00: solo lo que llegó desde ese correo; lo que quedó, todo.
        $this->travelTo('2026-11-09 18:40:00');
        $noche = $this->pedido($this->sala('Bodega Inventada Sur'), [$this->galleta->id => '1']);
        $this->travelTo('2026-11-09 19:00:00');
        $this->artisan('pedidos:correo-diario', ['--forzar' => true])->expectsOutput('Resumen enviado.')->assertSuccessful();
        $this->assertCount(3, $this->enviados());
        $despues = $this->leido($this->enviados()[2]->render());
        $this->assertSame('Bodega Inventada Sur: N.º '.$noche->id.' · Carlos Inventado · 1 producto', $this->parte($despues, 'Llegó de los vendedores'));
        $this->assertSame(
            'Sin empezar Bodega Inventada Sur: N.º '.$noche->id.', de hoy Súper Ficticio Centro: N.º '.$manana->id.', de hoy Tienda Inventada Norte: N.º '.$tarde->id.', de hoy',
            $this->parte($despues, 'Quedó allá'),
        );
        $this->assertStringContainsString('Vendido hoy hasta las 7 de la noche: $10.00 en 1 factura.', $despues);
    }

    // ══════════════════════════════ el logo

    public function test_sin_archivo_de_logo_el_correo_sale_con_el_nombre_y_sin_imagen_rota(): void
    {
        config(['dte.pdf.logo_path' => storage_path('no-existe-logo-de-prueba.png')]);
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();

        $mensaje = $this->mensaje($this->enviados()[0]);
        $html = (string) $mensaje->getHtmlBody();
        $this->assertDoesNotMatchRegularExpression('/<img\b/i', $html);
        $this->assertStringContainsString('Confitería de prueba', $html);
        $this->assertSame([], collect($mensaje->getAttachments())->filter(fn ($a) => $a->getDisposition() === 'inline')->all());
    }

    public function test_un_logo_que_gd_no_lee_va_incrustado_tal_cual(): void
    {
        $svg = sys_get_temp_dir().'/logo-de-prueba-'.Str::random(8).'.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
        config(['dte.pdf.logo_path' => $svg]);

        try {
            $this->travelTo('2026-11-09 18:00:00');
            $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
            $mensaje = $this->mensaje($this->enviados()[0]);

            // Dentro del correo, nunca pedido por una dirección. (El archivo se lee al armar el envío.)
            $this->assertSame(1, preg_match('/<img src="cid:([^"]+)"[^>]*class="doc-logo"/', (string) $mensaje->getHtmlBody(), $m));
            $logo = collect($mensaje->getAttachments())->first(fn ($a) => $a->getContentId() === $m[1]);
            $this->assertSame('inline', $logo?->getDisposition());
            $this->assertStringContainsString('<svg', $logo->getBody());
        } finally {
            @unlink($svg);
        }
    }

    // ══════════════════════════════ los demás correos no cambian

    public function test_los_demas_correos_siguen_con_el_tema_de_laravel_y_sin_los_colores_del_resumen(): void
    {
        // Primero el resumen, en el mismo proceso: su tema no se le pega al correo que sigue.
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();
        $resumen = $this->enviados()[0]->render();

        $colores = fn (string $archivo) => collect(preg_match_all('/#[0-9a-f]{6}\b/i', (string) file_get_contents($archivo), $m) ? $m[0] : [])
            ->map(fn ($c) => strtoupper($c))->unique();
        $propios = $colores(resource_path('views/vendor/mail/html/themes/documento.css'))
            ->diff($colores(dirname((new \ReflectionClass(Markdown::class))->getFileName()).'/resources/views/html/themes/default.css'))->values();
        $this->assertContains('#C23B26', $propios->all());
        $this->assertStringContainsStringIgnoringCase('#C23B26', $resumen);

        $dte = Dte::withoutEvents(fn () => Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'estado' => 'aceptado', 'ambiente' => '01',
            'establecimiento_id' => $this->establecimiento->id, 'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba'])->id,
            'numero_control' => 'DTE-03-M001P001-000000000000001', 'fecha_emision' => '2026-11-09', 'hora_emision' => '09:00:00', 'total_pagar' => 150.00,
        ]));
        $otros = [
            'la factura al cliente' => (new DteCorreo($dte, 'pdf'))->render(),
            'el aviso del sistema' => (new AlertaSistemaCorreo('normalizado', 'Prueba inventada', null, '09/11/2026 10:00', 'https://sistema.example.test/alertas'))->render(),
        ];
        foreach ($otros as $cual => $html) {
            foreach ($propios as $color) {
                $this->assertStringNotContainsStringIgnoringCase($color, $html, $cual.' no lleva '.$color.' del tema del resumen');
            }
            $this->assertStringNotContainsString('doc-logo', $html, $cual);
            $this->assertStringNotContainsString('doc-ttl', $html, $cual);
            // El fondo y el texto del tema de Laravel, como antes.
            $this->assertStringContainsStringIgnoringCase('background-color: #fafafa', $html, $cual);
            $this->assertStringContainsStringIgnoringCase('color: #52525b', $html, $cual);
        }
    }
}
