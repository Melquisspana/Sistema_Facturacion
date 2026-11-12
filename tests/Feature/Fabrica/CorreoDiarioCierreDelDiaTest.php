<?php

namespace Tests\Feature\Fabrica;

use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\ResumenDiarioPedidos;
use App\Services\Rutas\VentasRutas;
use App\Services\Rutas\Viajes;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El correo de las 18:00 como cierre del día: además de lo de la fábrica
 * ({@see CorreoDiarioPedidosTest}), lo vendido en el día en una línea y el PDF del día de
 * cada vendedor. Datos inventados y correos de ejemplo.
 */
class CorreoDiarioCierreDelDiaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private Producto $producto;

    private Establecimiento $establecimiento;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 18:00:00');
        config()->set('pedidos.correo_resumen_diario', 'cierre@example.test');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->producto = Producto::factory()->create(['nombre' => 'Galleta de prueba']);
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '01', 'activo' => true]);
        $this->establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $this->sala = $this->cliente->sucursales()->create(['nombre' => 'Sala de prueba Oriente', 'ruta_id' => Ruta::create(['nombre' => 'Zona de prueba'])->id]);

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

    /**
     * Un documento real (aceptado por Hacienda, en producción) con una línea. `$monto` es
     * la venta sin IVA. Sin más datos, un CCF de hoy.
     */
    private function documento(float $monto, array $datos = [], array $linea = []): DteLinea
    {
        $tipo = $datos['tipo_dte'] ?? '03';

        return Dte::withoutEvents(function () use ($monto, $datos, $linea, $tipo) {
            $dte = Dte::create($datos + [
                'establecimiento_id' => $this->establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'sello_recepcion' => (string) Str::uuid(), 'fecha_procesamiento_mh' => now(), 'cliente_id' => $this->cliente->id,
                'numero_control' => 'DTE-'.$tipo.'-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
                'fecha_emision' => '2026-11-09', 'hora_emision' => '10:00:00',
                'subtotal' => $monto, 'total_descuento' => 0, 'total_pagar' => $monto,
            ]);

            return DteLinea::withoutEvents(fn () => DteLinea::create($linea + [
                'dte_id' => $dte->id, 'numero_linea' => 1, 'tipo_impuesto' => 'gravado', 'producto_id' => $this->producto->id,
                'descripcion' => 'Galleta de prueba', 'cantidad' => 1, 'precio_unitario' => $monto, 'venta_gravada' => $monto, 'total_linea' => $monto,
            ]));
        });
    }

    private function enviado(): ResumenDiarioPedidosCorreo
    {
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        $correos = Mail::sent(ResumenDiarioPedidosCorreo::class);
        $this->assertCount(1, $correos);

        return $correos->first();
    }

    /** El cuerpo del correo como lo lee la persona: sin etiquetas ni estilos, en una línea. */
    private function texto(ResumenDiarioPedidosCorreo $correo): string
    {
        $cuerpo = (string) preg_replace('/^.*<body[^>]*>/s', '', $correo->render());

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cuerpo))));
    }

    // ══════════════════════════════ lo vendido en el día

    public function test_dice_lo_vendido_hoy_en_una_linea_sin_iva_y_la_nota_de_credito_resta(): void
    {
        $ccf = $this->documento(1100);
        $this->documento(50.50);
        // Una factura de consumidor final: $113 con IVA son $100 de venta.
        $this->documento(113, ['tipo_dte' => '01', 'iva' => 13]);
        // No cuentan: lo de ayer, lo invalidado y lo que no es de producción.
        $this->documento(900, ['fecha_emision' => '2026-11-08']);
        $this->documento(800, ['estado' => 'invalidado']);
        $this->documento(700, ['ambiente' => '00']);
        // La nota de crédito de un CCF de hoy resta hoy.
        $this->documento(10, ['tipo_dte' => '05'], ['dte_linea_original_id' => $ccf->id]);

        $texto = $this->texto($this->enviado());

        $this->assertStringContainsString('Vendido hoy hasta las 6 de la tarde: $1,240.50 en 3 facturas.', $texto);
        // El mismo número que da la pantalla de Ventas para ese día.
        $pantalla = app(VentasRutas::class)->consultar(['periodo' => 'rango', 'desde' => '2026-11-09', 'hasta' => '2026-11-09']);
        $this->assertSame(1240.50, $pantalla['totales']['actual']);
        $this->assertSame(3, $pantalla['contadores']['ccf'] + $pantalla['contadores']['fe']);
        // Solo el resumen del día: ni cada CCF, ni el mes, ni exportación si no hubo.
        $this->assertStringNotContainsString('DTE-03', $texto);
        $this->assertStringNotContainsString('1,100.00', $texto);
        $this->assertStringNotContainsString('2,140.50', $texto, 'sin el acumulado del mes');
        $this->assertDoesNotMatchRegularExpression('/\bmes\b|acumulado/iu', $texto);
        $this->assertStringNotContainsString('Exportación', $texto);
        $this->assertStringNotContainsString('Hoy no se facturó nada', $texto);
    }

    public function test_la_nota_de_credito_de_un_documento_de_otro_dia_no_toca_lo_de_hoy(): void
    {
        // Como en Ventas: la nota resta en el día del documento que corrige.
        $ayer = $this->documento(300, ['fecha_emision' => '2026-11-08']);
        $this->documento(40);
        $this->documento(25, ['tipo_dte' => '05'], ['dte_linea_original_id' => $ayer->id]);

        $this->assertStringContainsString('Vendido hoy hasta las 6 de la tarde: $40.00 en 1 factura.', $this->texto($this->enviado()));
    }

    public function test_si_hoy_no_se_vendio_nada_lo_dice(): void
    {
        $this->documento(900, ['fecha_emision' => '2026-11-08']);

        $texto = $this->texto($this->enviado());

        $this->assertStringContainsString('Hoy no se facturó nada.', $texto);
        $this->assertStringNotContainsString('Vendido hoy', $texto);
        $this->assertStringNotContainsString('900.00', $texto);
    }

    public function test_la_exportacion_va_en_su_propia_linea_solo_el_dia_que_hubo(): void
    {
        $caja = ['producto_id' => null, 'descripcion' => 'Caja de prueba', 'venta_gravada' => 0];
        $this->documento(40);
        $this->documento(500, ['tipo_dte' => '11'], $caja + ['venta_exportacion' => 500]);
        $this->documento(350.25, ['tipo_dte' => '11'], $caja + ['venta_exportacion' => 350.25]);

        $texto = $this->texto($this->enviado());

        $this->assertStringContainsString('Vendido hoy hasta las 6 de la tarde: $40.00 en 1 factura.', $texto);
        $this->assertStringContainsString('Exportación: $850.25 en 2 facturas.', $texto);
    }

    public function test_si_falla_la_cuenta_de_ventas_el_correo_sale_igual_y_lo_dice(): void
    {
        Exceptions::fake();
        $this->mock(VentasRutas::class)->shouldReceive('delDia')->once()
            ->andThrow(new \RuntimeException('Falla simulada de ventas'));

        $texto = $this->texto($this->enviado());

        $this->assertStringContainsString('No se pudo calcular lo vendido hoy', $texto);
        $this->assertStringNotContainsString('Hoy no se facturó nada', $texto);
        // Lo de la fábrica sigue saliendo.
        $this->assertStringContainsString('Hoy no salió ningún pedido', $texto);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Falla simulada de ventas');
    }

    // ══════════════════════════════ el PDF del día de cada vendedor

    /** @return array{0: User, 1: PersonalRuta} */
    private function vendedor(string $nombre): array
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');

        return [$usuario, PersonalRuta::create(['nombre' => $nombre, 'user_id' => $usuario->id])];
    }

    /** El vendedor marca un CCF de hoy en «Por entregar». Sin más datos, entregado y cobrado en efectivo. */
    private function marcar(User $usuario, array $datos = ['resultado' => 'entregado', 'forma_cobro' => 'efectivo']): void
    {
        $dte = $this->documento(142, ['cliente_sucursal_id' => $this->sala->id, 'fecha_emision' => now()->toDateString()])->dte;
        $this->actingAs($usuario)->post(route('rutas.por-entregar.marcar', $dte), $datos)->assertSessionHasNoErrors();
    }

    /** @return array<string, string> nombre del archivo => contenido */
    private function adjuntos(ResumenDiarioPedidosCorreo $correo): array
    {
        return collect($correo->attachments())->mapWithKeys(function (Attachment $adjunto) {
            $this->assertSame('application/pdf', $adjunto->mime);

            return [$adjunto->as => $adjunto->attachWith(fn () => null, fn ($datos) => $datos())];
        })->all();
    }

    /** @return array<string, mixed> */
    private function auditoria(): array
    {
        return Activity::where('log_name', 'pedido_sala')->where('description', ResumenDiarioPedidos::AUDITORIA)->sole()->properties->all();
    }

    public function test_adjunta_el_pdf_del_dia_de_cada_vendedor_con_movimiento_y_de_nadie_mas(): void
    {
        [$ana] = $this->vendedor('Ana Ficticia');
        [$beto, $personaBeto] = $this->vendedor('Beto Ficticio');
        [$carla] = $this->vendedor('Carla Ficticia');
        [$dora] = $this->vendedor('Dora Ficticia');
        $this->vendedor('Eva Ficticia');

        // Ayer entregó Dora: hoy no tiene nada.
        $this->travelTo('2026-11-08 10:00:00');
        $this->marcar($dora);
        $this->travelTo('2026-11-09 10:00:00');
        // Hoy: Ana entrega y cobra, Carla anota un «No se pudo» y Beto solo un gasto.
        $this->marcar($ana);
        $this->marcar($carla, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada']);
        app(Viajes::class)->paraPedido($personaBeto, $this->sala, $beto)->gastos()
            ->create(['fecha' => '2026-11-09', 'personal_id' => $personaBeto->id, 'concepto' => 'gasolina', 'monto' => '20.00', 'registrado_por' => $beto->id]);
        $this->travelTo('2026-11-09 18:00:00');

        $correo = $this->enviado();

        // A quien está en Configuración › Rutas, como siempre.
        $this->assertTrue($correo->hasTo('cierre@example.test'));
        $adjuntos = $this->adjuntos($correo);
        $this->assertSame(
            ['dia-2026-11-09-ana-ficticia.pdf', 'dia-2026-11-09-beto-ficticio.pdf', 'dia-2026-11-09-carla-ficticia.pdf'],
            array_keys($adjuntos),
        );
        foreach ($adjuntos as $pdf) {
            $this->assertStringStartsWith('%PDF', $pdf);
        }
        $texto = $this->texto($correo);
        $this->assertStringContainsString('Van adjuntos los PDF del día, con lo marcado hasta las 6 de la tarde, de: Ana Ficticia, Beto Ficticio, Carla Ficticia.', $texto);
        $this->assertStringNotContainsString('Dora', $texto);
        $this->assertStringNotContainsString('Eva', $texto);
        $this->assertStringNotContainsString('ningún vendedor', $texto);
        $this->assertStringNotContainsString('No se pudo adjuntar', $texto);

        // Es el mismo PDF de «Terminar el día», no una copia: mismo nombre y mismo contenido.
        $pantalla = $this->actingAs($ana)->get(route('rutas.mi-salida.dia.pdf'))->assertOk();
        $this->assertStringContainsString('dia-2026-11-09-ana-ficticia.pdf', (string) $pantalla->headers->get('content-disposition'));
        // Dos PDF del mismo contenido solo difieren en lo que DomPDF sella en cada uno: la hora y su identificador.
        $sinSello = fn (string $pdf) => md5((string) preg_replace('/\/(CreationDate|ModDate) \(D:[^)]*\)|\/ID\[<[0-9a-f]+><[0-9a-f]+>\]/', '', $pdf));
        $this->assertSame($sinSello($pantalla->getContent()), $sinSello($adjuntos['dia-2026-11-09-ana-ficticia.pdf']));

        // La bitácora: cuántos adjuntos fueron, sin nombres ni montos. assertEquals: MySQL reordena las claves del JSON.
        $this->assertEquals(
            ['fecha' => '2026-11-09', 'estado' => 'enviado', 'destinatarios' => 1, 'hasta' => '2026-11-09 18:00:00', 'adjuntos' => 3],
            $this->auditoria(),
        );

        // Y no sale dos veces.
        $this->artisan('pedidos:correo-diario')->expectsOutput('El resumen de hoy ya se había mandado.')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_si_ningun_vendedor_tuvo_movimiento_no_adjunta_nada_y_lo_dice(): void
    {
        [$dora] = $this->vendedor('Dora Ficticia');
        $this->travelTo('2026-11-08 10:00:00');
        $this->marcar($dora);
        $this->travelTo('2026-11-09 18:00:00');

        $correo = $this->enviado();

        $this->assertSame([], $correo->attachments());
        $texto = $this->texto($correo);
        $this->assertStringContainsString('Hoy ningún vendedor marcó entregas.', $texto);
        $this->assertStringNotContainsString('Van adjuntos', $texto);
        $this->assertSame(0, $this->auditoria()['adjuntos']);
    }

    public function test_dos_vendedores_con_el_mismo_nombre_no_pisan_su_pdf(): void
    {
        [$una] = $this->vendedor('Ana Ficticia');
        [$otra, $personaOtra] = $this->vendedor('Ana Ficticia');
        $this->marcar($una);
        $this->marcar($otra);

        $this->assertSame(
            ['dia-2026-11-09-ana-ficticia.pdf', 'dia-2026-11-09-ana-ficticia-'.$personaOtra->id.'.pdf'],
            array_keys($this->adjuntos($this->enviado())),
        );
    }

    public function test_si_falla_el_pdf_de_un_vendedor_el_correo_sale_con_los_demas_y_lo_dice(): void
    {
        Exceptions::fake();
        [$ana] = $this->vendedor('Ana Ficticia');
        [$beto, $personaBeto] = $this->vendedor('Beto Ficticio');
        $this->marcar($ana);
        $this->marcar($beto);
        $real = app(DiaVendedor::class);
        $this->partialMock(DiaVendedor::class, fn (MockInterface $dia) => $dia->shouldReceive('pdfBinario')->twice()
            ->andReturnUsing(fn (PersonalRuta $persona, ?User $usuario, string $fecha) => $persona->is($personaBeto)
                ? throw new \RuntimeException('Falla simulada del PDF')
                : $real->pdfBinario($persona, $usuario, $fecha)));

        $correo = $this->enviado();

        $this->assertSame(['dia-2026-11-09-ana-ficticia.pdf'], array_keys($this->adjuntos($correo)));
        $texto = $this->texto($correo);
        $this->assertStringContainsString('Van adjuntos los PDF del día, con lo marcado hasta las 6 de la tarde, de: Ana Ficticia.', $texto);
        $this->assertStringContainsString('No se pudo adjuntar el PDF de: Beto Ficticio.', $texto);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Falla simulada del PDF');
        $this->assertSame(1, $this->auditoria()['adjuntos']);
    }

    // ══════════════════════════════ es una foto de las 6

    public function test_dice_hasta_que_hora_cuenta_y_a_mano_dice_la_hora_en_que_salio(): void
    {
        // La hora de todos los días sale de la constante, no está escrita a mano.
        $this->assertSame('18:00', ResumenDiarioPedidos::HORA);
        $this->assertSame('las 6 de la tarde', ResumenDiarioPedidos::horaDicha());
        $this->assertSame('las 10:15 de la mañana', ResumenDiarioPedidos::horaDicha('10:15'));
        $this->assertSame('la 1 de la tarde', ResumenDiarioPedidos::horaDicha('13:00'));
        $this->assertSame('las 8:30 de la noche', ResumenDiarioPedidos::horaDicha('20:30'));

        // A mano, a media mañana: la foto es de esa hora, no de las 6.
        [$ana] = $this->vendedor('Ana Ficticia');
        $this->travelTo('2026-11-09 10:00:00');
        $this->marcar($ana);
        $this->travelTo('2026-11-09 10:15:00');
        $this->artisan('pedidos:correo-diario', ['--forzar' => true])->expectsOutput('Resumen enviado.')->assertSuccessful();

        $texto = $this->texto(Mail::sent(ResumenDiarioPedidosCorreo::class)->sole());
        $this->assertStringContainsString('Vendido hoy hasta las 10:15 de la mañana: $142.00 en 1 factura.', $texto);
        $this->assertStringContainsString('Van adjuntos los PDF del día, con lo marcado hasta las 10:15 de la mañana, de: Ana Ficticia.', $texto);
        $this->assertStringContainsString('Este resumen llega cada día a las 6 de la tarde.', $texto);
    }

    // ══════════════════════════════ quién tuvo movimiento, sin una consulta por ficha

    public function test_quien_tuvo_movimiento_son_los_mismos_que_dice_el_dia_de_cada_persona(): void
    {
        [$ana, $personaAna] = $this->vendedor('Ana Ficticia');
        [$beto, $personaBeto] = $this->vendedor('Beto Ficticio');
        [$carla, $personaCarla] = $this->vendedor('Carla Ficticia');
        [$dora] = $this->vendedor('Dora Ficticia');
        $this->vendedor('Eva Ficticia');
        // Sin login: no anota nada en el sistema, pero puede tener un gasto a su nombre.
        $fabio = PersonalRuta::create(['nombre' => 'Fabio Ficticio']);
        PersonalRuta::create(['nombre' => 'Gil Ficticio']);

        $this->travelTo('2026-11-08 10:00:00');
        $this->marcar($dora);
        $this->travelTo('2026-11-09 10:00:00');
        $this->marcar($ana);
        $this->marcar($carla, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada']);
        $viaje = app(Viajes::class)->paraPedido($personaBeto, $this->sala, $beto);
        foreach ([$personaBeto, $fabio] as $persona) {
            $viaje->gastos()->create(['fecha' => '2026-11-09', 'personal_id' => $persona->id, 'concepto' => 'gasolina', 'monto' => '20.00', 'registrado_por' => $beto->id]);
        }

        $dia = app(DiaVendedor::class);
        $conMovimiento = $dia->personasConMovimiento('2026-11-09');

        $this->assertSame(['Ana Ficticia', 'Beto Ficticio', 'Carla Ficticia', 'Fabio Ficticio'], $conMovimiento->pluck('nombre')->all());
        // Ficha por ficha, con la regla de «Terminar el día»: exactamente las mismas personas.
        $unaPorUna = PersonalRuta::query()->with('user')->orderBy('nombre')->orderBy('id')->get()
            ->filter(fn (PersonalRuta $p) => $dia->diasConMovimiento($p, $p->user, '2026-11-09', '2026-11-09') !== []);
        $this->assertSame($unaPorUna->pluck('id')->all(), $conMovimiento->pluck('id')->all());
        // Y cada una trae su usuario, que es con lo que se arma su PDF.
        $this->assertTrue($conMovimiento->firstWhere('id', $personaCarla->id)->user->is($carla));
        $this->assertNull($conMovimiento->firstWhere('id', $fabio->id)->user);
        $this->assertSame([], $dia->personasConMovimiento('2026-11-10')->all());
        $this->assertSame([$personaAna->id, $personaBeto->id, $personaCarla->id, $fabio->id], $conMovimiento->pluck('id')->all());
    }

    public function test_buscar_quien_tuvo_movimiento_no_hace_mas_consultas_con_mas_fichas(): void
    {
        [$ana] = $this->vendedor('Ana Ficticia');
        $this->marcar($ana);

        $contar = function (): array {
            $consultas = [];
            DB::listen(function ($consulta) use (&$consultas) {
                $consultas[] = $consulta->sql;
            });
            $personas = app(DiaVendedor::class)->personasConMovimiento('2026-11-09');
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            $this->assertSame(['Ana Ficticia'], $personas->pluck('nombre')->all());

            return $consultas;
        };

        $pocas = $contar();
        for ($i = 1; $i <= 25; $i++) {
            $this->vendedor('Ficha de prueba '.$i);
        }
        $muchas = $contar();

        $this->assertLessThanOrEqual(4, count($pocas));
        $this->assertCount(count($pocas), $muchas);
        // El Personal se lee una sola vez. MySQL cita con acentos graves; SQLite, con comillas dobles.
        $this->assertCount(1, preg_grep('/from\s+["`]rutas_personal["`]/i', $muchas));
    }
}
