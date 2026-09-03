<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * BLINDAJE de Notas de Crédito antes de que esto toque dinero real.
 *
 * No reemplaza a las suites existentes (retención, avería, pronto pago, reversión,
 * saldo): las complementa cerrando lo que ninguna cubría de forma explícita —las
 * INVARIANTES MATEMÁTICAS y la coherencia entre las tres representaciones del mismo
 * documento: lo PERSISTIDO, lo que se MUESTRA y lo que viaja en el JSON del MH.
 *
 * REGLA DE NEGOCIO FIJADA (no se toca acá): una NC por productos o avería solo puede
 * retener si el CCF original retuvo, y además su PROPIA base gravada neta debe superar
 * dte.retencion_iva_umbral — la misma comparación estricta que juzgó al CCF. El monto
 * es el 1 % de esa base. Una NC chica sobre un CCF retenido NO retiene: el albarán real
 * del cliente (caso Calleja, base 0.85) llega sin retención.
 *
 * Toda la aritmética esperada se expresa con {@see Dinero} (BCMath, half-up), nunca con
 * floats: es el mismo criterio que usa el sistema.
 *
 * Nada de esto emite a Hacienda, firma ni transmite: los JSON quedan en un disco falso.
 */
class DteNotaCreditoBlindajeTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private PuntoVenta $p001;

    private PuntoVenta $p002;

    private int $estabId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // los JSON generados no tocan el disco real
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $this->estabId = $estab->id;
        $this->p001 = $pv;
        $this->p002 = PuntoVenta::create([
            'establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true,
        ]);

        foreach (['03', '05'] as $tipo) {
            foreach ([$this->p001, $this->p002] as $punto) {
                Correlativo::create([
                    'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $punto->id,
                    'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
                ]);
            }
        }
    }

    // ================= Utilidades =================

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function cliente(bool $agenteRetencion, float $descuento = 0): Cliente
    {
        return Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => $agenteRetencion,
            'descuento_global_default' => $descuento,
        ]);
    }

    private function producto(float $precio): Producto
    {
        return Producto::factory()->create([
            'precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    /**
     * CCF ACEPTADO por Hacienda, construido por el flujo real de borrador.
     *
     * @param  array<int, array{0: float, 1: int}>  $lineas  [[precio, cantidad], …]
     */
    private function ccfAceptado(Cliente $cliente, array $lineas, ?PuntoVenta $pv = null): Dte
    {
        $pv ??= $this->p001;

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estabId,
            'punto_venta_id' => $pv->id,
        ]);

        foreach ($lineas as [$precio, $cantidad]) {
            $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto($precio), cantidad: $cantidad);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf->refresh());
    }

    private function nc(Dte $ccf, TipoNotaCredito $tipo): Dte
    {
        return $this->borradores->crearNotaCredito($ccf, [
            'tipo' => $tipo->value,
            // Solo lo usa la avería; en el resto de las modalidades se descarta.
            'origen_averia' => 'entrega',
        ], $this->usuario());
    }

    /** @return Collection<int, DteLinea> */
    private function lineasDe(Dte $dte)
    {
        return $dte->lineas()->orderBy('numero_linea')->get();
    }

    /**
     * Líneas indexadas por PRECIO ("20.00" => línea). Al generar un CCF,
     * DteGeneracionService::reordenarLineasSegunOc() congela el orden según la orden de
     * compra, así que `numero_linea` NO conserva el orden de alta: identificar la línea
     * por su posición sería frágil.
     *
     * @return Collection<string, DteLinea>
     */
    private function lineasPorPrecio(Dte $dte)
    {
        return $this->lineasDe($dte)->keyBy(fn (DteLinea $l) => Dinero::redondear($l->precio_unitario, 2));
    }

    /**
     * Documento creado DIRECTAMENTE en su estado final. Necesario cuando la prueba
     * requiere un documento que el flujo normal no produce (un tipo distinto de 03, una
     * aceptación simulada en producción): el observer de inmutabilidad —con razón— no
     * deja mutar esos campos en un documento ya emitido.
     */
    private function documentoCrudo(array $extra = []): Dte
    {
        return Dte::create(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $this->estabId,
            'punto_venta_id' => $this->p001->id,
            'cliente_id' => $this->cliente(true, 5)->id,
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) random_int(1, 999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ], $extra));
    }

    private function verShow(Dte $dte)
    {
        return $this->actingAs($this->usuario())->get(route('facturacion.show', $dte));
    }

    /** @return array<string, mixed> resumen del JSON oficial de la NC (sin transmitir) */
    private function resumenJson(Dte $nc): array
    {
        app(DteGeneracionService::class)->generar($nc);
        $salida = app(MapeadorDteSalida::class)->mapear($nc->refresh());

        return app(SerializadorNotaCreditoMh::class)->serializar($salida);
    }

    // ================= INVARIANTES (escenario 14) =================

    /**
     * Las dos igualdades que deben cumplirse SIEMPRE en un documento solo-gravado:
     *   SUBTOTAL BRUTO − DESCUENTO = BASE GRAVADA NETA
     *   TOTAL = BASE GRAVADA NETA + IVA − RETENCIÓN   (retención 0 cuando no aplica)
     */
    private function assertInvariantes(Dte $d, string $contexto = ''): void
    {
        $d->refresh();
        $baseNeta = Dinero::redondear(Dinero::restar($d->total_gravado, $d->descuento_gravado), 2);

        $this->assertSame(
            $baseNeta,
            Dinero::redondear(Dinero::restar($d->subtotal, $d->total_descuento), 2),
            "SUBTOTAL − DESCUENTO ≠ BASE GRAVADA NETA {$contexto}"
        );

        $this->assertSame(
            Dinero::redondear(Dinero::restar(Dinero::sumar($baseNeta, $d->iva), $d->iva_retenido), 2),
            Dinero::redondear($d->total_pagar, 2),
            "TOTAL ≠ BASE + IVA − RETENCIÓN {$contexto}"
        );

        // El IVA es exactamente el 13 % de la base neta, redondeado una sola vez.
        $this->assertSame(
            Dinero::redondear(Dinero::multiplicar($baseNeta, '0.13'), 2),
            Dinero::redondear($d->iva, 2),
            "IVA ≠ 13 % de la base neta {$contexto}"
        );

        // Y la retención, cuando aplica, el 1 % de esa MISMA base neta.
        $retencionEsperada = $d->aplica_retencion_iva
            ? Dinero::redondear(Dinero::multiplicar($baseNeta, '0.01'), 2)
            : '0.00';
        $this->assertSame(
            $retencionEsperada,
            Dinero::redondear($d->iva_retenido, 2),
            "Retención ≠ 1 % de la base neta {$contexto}"
        );

        // total_antes_retencion es el bruto: base + IVA, sin restar nada.
        $this->assertSame(
            Dinero::redondear(Dinero::sumar($baseNeta, $d->iva), 2),
            Dinero::redondear($d->total_antes_retencion, 2),
            "total_antes_retencion ≠ BASE + IVA {$contexto}"
        );
    }

    // ================= 1. CCF SIN RETENCIÓN =================

    /**
     * @return array<string, array{0: bool, 1: float}> [esAgente, descuento]
     */
    public static function ccfSinRetencion(): array
    {
        return [
            'cliente que no es agente de retención' => [false, 0],
            'agente de retención con descuento (base bajo el umbral)' => [true, 5],
        ];
    }

    /**
     * Un CCF sin retención NO puede contagiar retención a ninguna NC derivada.
     *
     * @dataProvider ccfSinRetencion
     */
    public function test_ccf_sin_retencion_no_contagia_retencion_a_ninguna_nc(bool $agente, float $descuento): void
    {
        // Con el segundo caso la base neta queda en 85.50: agente sí, pero bajo el umbral.
        // Las cantidades alcanzan para devolución + faltante + la reversión total del final.
        $lineas = $agente ? [[30.00, 3]] : [[300.00, 3]];
        $ccf = $this->ccfAceptado($this->cliente($agente, $descuento), $lineas);
        $this->assertFalse((bool) $ccf->aplica_retencion_iva, 'El CCF de partida no debía retener.');

        $casos = [
            'avería pequeña' => fn () => tap($this->nc($ccf, TipoNotaCredito::Averia), fn ($nc) => $this->borradores
                ->agregarProductoNotaCreditoAveria($nc, $this->producto(4.00), 1)),
            'avería grande' => fn () => tap($this->nc($ccf, TipoNotaCredito::Averia), fn ($nc) => $this->borradores
                ->agregarProductoNotaCreditoAveria($nc, $this->producto(880.00), 1)),
            'devolución parcial' => fn () => tap($this->nc($ccf, TipoNotaCredito::DevolucionProducto), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
            'faltante' => fn () => tap($this->nc($ccf, TipoNotaCredito::FaltanteEntrega), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
            'pronto pago' => fn () => tap($this->nc($ccf, TipoNotaCredito::ProntoPago), fn ($nc) => $this->borradores
                ->agregarConceptoNotaCredito($nc, ['descripcion' => 'Descuento pronto pago', 'monto' => 40])),
        ];

        foreach ($casos as $etiqueta => $construir) {
            $nc = $construir()->refresh();

            $this->assertFalse((bool) $nc->aplica_retencion_iva, "«{$etiqueta}» no debía retener.");
            $this->assertSame('0.00', (string) $nc->iva_retenido, "«{$etiqueta}» dejó retención.");
            $this->assertInvariantes($nc, "en «{$etiqueta}»");

            // Sin retención el total es exactamente base + IVA.
            $baseNeta = Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2);
            $this->assertSame(
                Dinero::redondear(Dinero::sumar($baseNeta, $nc->iva), 2),
                Dinero::redondear($nc->total_pagar, 2),
                "«{$etiqueta}»: el total no es base + IVA."
            );

            // Y la pantalla no inventa una fila de retención en $0.00.
            $this->verShow($nc)->assertOk()->assertDontSee('Retención IVA 1%');
        }

        // La devolución TOTAL merece su propio camino (revertirCcfCompleto).
        $total = $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->refresh();
        $this->assertFalse((bool) $total->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $total->iva_retenido);
        $this->assertInvariantes($total, 'en la devolución total');
        $this->verShow($total)->assertOk()->assertDontSee('Retención IVA 1%');
    }

    // ================= 2. CCF CON RETENCIÓN =================

    /** El caso de oro completo, contra los tres frentes: persistido, pantalla e invariantes. */
    public function test_caso_de_oro_112_25_da_119_43(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[112.25, 1]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(112.25), 1);
        $nc->refresh();

        $this->assertSame('112.25', (string) $nc->total_gravado);
        $this->assertSame('5.61', (string) $nc->total_descuento);
        $this->assertSame('106.64', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
        $this->assertSame('13.86', (string) $nc->iva);
        $this->assertSame('1.07', (string) $nc->iva_retenido);
        $this->assertSame('120.50', (string) $nc->total_antes_retencion);
        $this->assertSame('119.43', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en el caso de oro');

        $this->verShow($nc)->assertOk()
            ->assertSee('-$5.61')->assertSee('$106.64')->assertSee('$13.86')
            ->assertSee('Retención IVA 1%')->assertSee('-$1.07')->assertSee('$119.43');
    }

    /**
     * EL CASO REAL DE PRODUCCIÓN (Calleja): una NC por avería de $0.90 sobre un CCF que
     * sí retuvo. El albarán del cliente llegó por $0.96 y SIN retención, porque la base
     * neta de la NC ($0.85) no alcanza el umbral. La NC no puede inventar una retención
     * de $0.01 que el cliente no reconoce.
     */
    public function test_nc_de_090_sobre_ccf_con_retencion_no_retiene_y_da_096(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[500.00, 1]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(0.90), 1);
        $nc->refresh();

        // 0.90 − 5 % (0.045 → 0.05) = 0.85 de base neta → IVA 0.1105 → 0.11.
        $this->assertSame('0.90', (string) $nc->total_gravado);
        $this->assertSame('0.05', (string) $nc->total_descuento);
        $this->assertSame('0.85', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
        $this->assertSame('0.11', (string) $nc->iva);

        // Bajo el umbral: NO retiene, y el total es exactamente base + IVA.
        $this->assertFalse((bool) $nc->aplica_retencion_iva, 'Una NC de base 0.85 no puede retener.');
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('0.96', (string) $nc->total_antes_retencion);
        $this->assertSame('0.96', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en la NC de 0.90 del caso Calleja');

        // La pantalla no descuenta nada, pero SÍ explica por qué: la fila va en $0.00
        // con el motivo, en vez de desaparecer y dejar al usuario adivinando.
        $this->verShow($nc)->assertOk()
            ->assertSee('$0.96')
            ->assertSee('Retención IVA 1%')
            ->assertSee('No aplica: la base gravada neta de esta nota no supera $100.00')
            ->assertDontSee('-$0.01');
    }

    /**
     * La misma NC pequeña en las otras modalidades: mientras la base neta no supere el
     * umbral, ninguna retiene — da igual que el CCF de origen sí lo haya hecho.
     */
    public function test_ninguna_nc_bajo_el_umbral_retiene_aunque_el_ccf_haya_retenido(): void
    {
        // 10 × 20.00 = 200.00 − 5 % = 190.00: el CCF sí supera el umbral y retiene.
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[20.00, 10]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $casos = [
            'avería pequeña' => fn () => tap($this->nc($ccf, TipoNotaCredito::Averia), fn ($nc) => $this->borradores
                ->agregarProductoNotaCreditoAveria($nc, $this->producto(20.00), 1)),
            'devolución parcial' => fn () => tap($this->nc($ccf, TipoNotaCredito::DevolucionProducto), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
            'faltante' => fn () => tap($this->nc($ccf, TipoNotaCredito::FaltanteEntrega), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
        ];

        foreach ($casos as $etiqueta => $construir) {
            $nc = $construir()->refresh();

            // 20.00 − 5 % = 19.00 de base neta → bajo el umbral.
            $this->assertSame('19.00', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
            $this->assertFalse((bool) $nc->aplica_retencion_iva, "«{$etiqueta}» no debía retener bajo el umbral.");
            $this->assertSame('0.00', (string) $nc->iva_retenido, "«{$etiqueta}» dejó retención.");
            $this->assertSame('2.47', (string) $nc->iva);
            $this->assertSame('21.47', (string) $nc->total_pagar); // 19.00 + 2.47, sin restar nada
            $this->assertInvariantes($nc, "en «{$etiqueta}»");

            // La fila se muestra en $0.00 CON el motivo del umbral (el CCF sí retuvo).
            $this->verShow($nc)->assertOk()
                ->assertSee('Retención IVA 1%')
                ->assertSee('No aplica: la base gravada neta de esta nota no supera $100.00');
        }
    }

    public function test_ccf_con_retencion_la_hereda_en_toda_modalidad_por_productos_o_averia(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[400.00, 2]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        // Todas las NC de este caso quedan MUY por encima del umbral: lo que se prueba
        // acá es que el monto sale del 1 % de SU base neta, no del monto del CCF.
        $casos = [
            'NC grande (avería)' => fn () => tap($this->nc($ccf, TipoNotaCredito::Averia), fn ($nc) => $this->borradores
                ->agregarProductoNotaCreditoAveria($nc, $this->producto(750.00), 1)),
            'devolución parcial' => fn () => tap($this->nc($ccf, TipoNotaCredito::DevolucionProducto), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
            'faltante' => fn () => tap($this->nc($ccf, TipoNotaCredito::FaltanteEntrega), fn ($nc) => $this->borradores
                ->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1)),
        ];

        foreach ($casos as $etiqueta => $construir) {
            $nc = $construir()->refresh();
            $baseNeta = Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2);

            $this->assertSame(1, Dinero::comparar($baseNeta, '100.00'), "«{$etiqueta}» debía quedar sobre el umbral.");
            $this->assertTrue((bool) $nc->aplica_retencion_iva, "«{$etiqueta}» debía heredar la retención.");
            $this->assertSame(
                Dinero::redondear(Dinero::multiplicar($baseNeta, '0.01'), 2),
                (string) $nc->iva_retenido,
                "«{$etiqueta}»: la retención no es el 1 % de SU base neta."
            );
            $this->assertInvariantes($nc, "en «{$etiqueta}»");
            $this->verShow($nc)->assertOk()->assertSee('Retención IVA 1%');
        }
    }

    /**
     * El BORDE exacto del umbral: la comparación es ESTRICTA, igual que en el CCF.
     * Base neta 100.00 → no retiene; 100.01 → retiene.
     */
    public function test_el_umbral_de_la_nc_usa_la_misma_comparacion_estricta_que_el_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 0), [[5000.00, 1]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        // Sin descuento global: la base neta es el precio tal cual.
        $justo = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($justo, $this->producto(100.00), 1);
        $justo->refresh();

        $this->assertSame('100.00', Dinero::redondear(Dinero::restar($justo->total_gravado, $justo->descuento_gravado), 2));
        $this->assertFalse((bool) $justo->aplica_retencion_iva, 'Base = umbral NO retiene (comparación estricta).');
        $this->assertSame('0.00', (string) $justo->iva_retenido);
        $this->assertInvariantes($justo, 'en la NC de base exactamente 100.00');

        $unCentavoMas = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($unCentavoMas, $this->producto(100.01), 1);
        $unCentavoMas->refresh();

        $this->assertSame('100.01', Dinero::redondear(Dinero::restar($unCentavoMas->total_gravado, $unCentavoMas->descuento_gravado), 2));
        $this->assertTrue((bool) $unCentavoMas->aplica_retencion_iva);
        $this->assertSame('1.00', (string) $unCentavoMas->iva_retenido); // 100.01 × 1 % = 1.0001 → 1.00
        $this->assertInvariantes($unCentavoMas, 'en la NC de base 100.01');
    }

    /**
     * PRONTO PAGO sigue la MISMA regla que el resto de modalidades: si el receptor es gran
     * contribuyente, el CCF relacionado retuvo y la base neta propia supera el umbral,
     * retiene. Antes se excluía por su TIPO y salía sin retención aunque cumpliera las
     * tres condiciones fiscales, dejando la NC corta frente al albarán del cliente.
     *
     * Base 200.00 → IVA 26.00 → retención 2.00 → total 224.00.
     *
     * Lo que NO cambia: el pronto pago sigue sin heredar el descuento global del CCF —esa
     * sí es una regla por modalidad, y es independiente de la retención—.
     */
    public function test_pronto_pago_sobre_ccf_con_retencion_si_retiene_sobre_su_base(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[500.00, 1]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $nc = $this->nc($ccf, TipoNotaCredito::ProntoPago);
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 200]);
        $nc->refresh();

        // El descuento global NO se hereda: la base es el concepto tal cual.
        $this->assertSame('0.00', (string) $nc->descuento_porcentaje_aplicado);
        $this->assertSame('200.00', (string) $nc->total_gravado);
        $this->assertSame('26.00', (string) $nc->iva);

        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('2.00', (string) $nc->iva_retenido);
        $this->assertSame('224.00', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en pronto pago');
        $this->verShow($nc)->assertOk();
    }

    /**
     * El umbral, y no la modalidad, es lo que frena a las notas chicas: un pronto pago de
     * $50.00 sobre el mismo CCF que retuvo NO retiene.
     */
    public function test_pronto_pago_bajo_el_umbral_sigue_sin_retener(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[500.00, 1]]);

        $nc = $this->nc($ccf, TipoNotaCredito::ProntoPago);
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 50]);
        $nc->refresh();

        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('56.50', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en pronto pago bajo el umbral');
    }

    // ================= 3. DESCUENTO 5 % =================

    public function test_el_descuento_del_5_pct_se_aplica_antes_del_iva_y_de_la_retencion(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[200.00, 3]]);

        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 2); // 2 de 3
        $nc->refresh();

        // La NC hereda el 5 % del CCF: 400.00 bruto − 20.00 = 380.00 de base neta.
        $this->assertSame('5.00', (string) $nc->descuento_porcentaje_aplicado);
        $this->assertSame('400.00', (string) $nc->total_gravado);
        $this->assertSame('20.00', (string) $nc->total_descuento);
        $this->assertSame('380.00', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
        $this->assertSame('49.40', (string) $nc->iva);         // 380 × 13 %
        $this->assertSame('3.80', (string) $nc->iva_retenido); // 380 × 1 %, NO 400 × 1 %
        $this->assertSame('425.60', (string) $nc->total_pagar);

        // Explícito: la retención NO sale del subtotal bruto.
        $this->assertNotSame(
            Dinero::redondear(Dinero::multiplicar($nc->subtotal, '0.01'), 2),
            (string) $nc->iva_retenido,
            'La retención se calculó sobre el subtotal bruto en vez de la base neta.'
        );
        $this->assertInvariantes($nc, 'con descuento 5 %');
    }

    public function test_devolucion_completa_con_descuento_coincide_al_centavo_con_el_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[64.07, 1], [64.07, 1]]);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->refresh();

        $this->assertSame((string) $ccf->total_gravado, (string) $nc->total_gravado);
        $this->assertSame((string) $ccf->total_descuento, (string) $nc->total_descuento);
        $this->assertSame((string) $ccf->iva, (string) $nc->iva);
        $this->assertSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
        $this->assertSame((string) $ccf->total_pagar, (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en la reversión total');
    }

    // ================= 4. DOS NC PARCIALES =================

    public function test_dos_nc_parciales_no_duplican_saldo_ni_retencion(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[300.00, 4]]);
        $linea = $this->lineasDe($ccf)->first();

        $ncA = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($ncA, $linea, 1);
        $ncA->refresh();

        $ncB = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($ncB, $linea, 2);
        $ncB->refresh();

        // Cada una acredita SOLO su porción.
        $this->assertSame('300.00', (string) $ncA->total_gravado);
        $this->assertSame('600.00', (string) $ncB->total_gravado);
        $this->assertInvariantes($ncA, 'en la NC A');
        $this->assertInvariantes($ncB, 'en la NC B');

        // Retenciones proporcionales: 285.00 × 1 % y 570.00 × 1 %.
        $this->assertSame('2.85', (string) $ncA->iva_retenido);
        $this->assertSame('5.70', (string) $ncB->iva_retenido);

        // Saldo restante: 4 − 1 − 2 = 1 unidad.
        $acreditado = DteLinea::where('dte_linea_original_id', $linea->id)->sum('cantidad');
        $this->assertSame(3.0, (float) $acreditado);

        // Lo acreditado nunca supera lo vendido, y la suma de retenciones es coherente
        // con la fracción acreditada del CCF (3 de 4 unidades).
        $this->assertSame(
            Dinero::redondear(Dinero::sumar($ncA->iva_retenido, $ncB->iva_retenido), 2),
            '8.55'
        );
        $this->assertSame(-1, Dinero::comparar(
            Dinero::sumar($ncA->iva_retenido, $ncB->iva_retenido),
            $ccf->iva_retenido
        ), 'Dos NC parciales no pueden reversar más retención que la del CCF.');
    }

    /**
     * Cuando LAS DOS mitades superan el umbral, la suma reversa exactamente la retención
     * del CCF: la proporcionalidad sigue intacta donde la retención existe.
     */
    public function test_dos_nc_que_cubren_todo_reversan_exactamente_la_retencion_del_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[300.00, 1], [300.00, 1]]);
        $lineas = $this->lineasDe($ccf);

        $nc1 = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($nc1, $lineas[0], 1);

        $nc2 = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        // Cada mitad: 300.00 − 5 % = 285.00 → 2.85. El CCF retuvo 5.70.
        $this->assertSame('2.85', (string) $nc1->refresh()->iva_retenido);
        $this->assertSame('2.85', (string) $nc2->refresh()->iva_retenido);
        $suma = Dinero::redondear(Dinero::sumar($nc1->iva_retenido, $nc2->iva_retenido), 2);
        $this->assertSame(Dinero::redondear($ccf->iva_retenido, 2), $suma);
    }

    /**
     * CONSECUENCIA ACEPTADA de la regla: si el CCF se trocea en NC que quedan bajo el
     * umbral, la suma de las retenciones de las NC ya NO reversa la del CCF. Es
     * deliberado: manda lo que el cliente reconoce en cada albarán, no la reconciliación
     * contra el original. Se deja explícito para que nadie lo lea como una regresión.
     */
    public function test_trocear_un_ccf_en_nc_bajo_el_umbral_no_reversa_la_retencion_del_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[64.07, 1], [64.07, 1]]);
        $this->assertSame('1.22', (string) $ccf->iva_retenido); // 121.73 × 1 %

        $lineas = $this->lineasDe($ccf);
        $nc1 = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($nc1, $lineas[0], 1);
        $nc2 = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        // Cada mitad: 64.07 − 5 % = 60.87 → bajo los $100 → sin retención.
        foreach ([$nc1, $nc2] as $nc) {
            $nc->refresh();
            $this->assertFalse((bool) $nc->aplica_retencion_iva);
            $this->assertSame('0.00', (string) $nc->iva_retenido);
            $this->assertInvariantes($nc, 'en la mitad bajo el umbral');
        }

        // La reversión total EN UNA SOLA NC sí habría retenido (base 121.73): la
        // diferencia la crea el troceo, no el cálculo.
        $this->assertSame(
            1,
            Dinero::comparar($ccf->iva_retenido, Dinero::sumar($nc1->iva_retenido, $nc2->iva_retenido))
        );
    }

    // ================= 5. IMPEDIR SOBRECRÉDITO =================

    public function test_no_se_puede_acreditar_mas_que_el_saldo_restante(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[100.00, 5]]);
        $linea = $this->lineasDe($ccf)->first();

        $ncA = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($ncA, $linea, 3);
        $totalesA = $ncA->refresh()->only(['total_gravado', 'iva', 'iva_retenido', 'total_pagar']);
        $ccfAntes = $ccf->refresh()->only(['estado', 'total_pagar', 'iva_retenido', 'sello_recepcion']);

        // Quedan 2: pedir 3 debe fallar.
        $ncB = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        try {
            $this->borradores->acreditarLinea($ncB, $linea, 3);
            $this->fail('Debió rechazar la acreditación por encima del saldo.');
        } catch (SaldoAcreditableExcedidoException $e) {
            // esperado
        }

        // El rechazo no tocó NADA.
        $this->assertSame($totalesA, $ncA->refresh()->only(array_keys($totalesA)));
        $this->assertSame($ccfAntes, $ccf->refresh()->only(array_keys($ccfAntes)));
        $this->assertSame(3.0, (float) DteLinea::where('dte_linea_original_id', $linea->id)->sum('cantidad'));
        $this->assertSame(0, $ncB->refresh()->lineas()->count());
        $this->assertSame('0.00', (string) $ncB->total_pagar);

        // Y el saldo exacto sí se puede acreditar.
        $this->borradores->acreditarLinea($ncB, $linea, 2);
        $this->assertSame(5.0, (float) DteLinea::where('dte_linea_original_id', $linea->id)->sum('cantidad'));
    }

    public function test_sobre_un_ccf_agotado_no_se_puede_acreditar_nada_mas(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[250.00, 2]]);
        $linea = $this->lineasDe($ccf)->first();

        $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        // Otra acreditación puntual: rechazada.
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->borradores->acreditarLinea($nc, $linea, 1);
    }

    public function test_una_segunda_reversion_total_se_rechaza_sin_dejar_borrador(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[250.00, 2]]);
        $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $ncsAntes = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count();

        try {
            $this->borradores->revertirCcfCompleto($ccf, $this->usuario());
            $this->fail('La segunda reversión total debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }

        // Rollback total: ni un borrador huérfano.
        $this->assertSame($ncsAntes, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    // ================= 6. NC TOTAL =================

    public function test_nc_total_deja_el_saldo_en_cero_y_cuadra_la_retencion(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[120.00, 2], [80.00, 1]]);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->refresh();

        foreach ($this->lineasDe($ccf) as $lineaOriginal) {
            $acreditado = (float) DteLinea::where('dte_linea_original_id', $lineaOriginal->id)->sum('cantidad');
            $this->assertSame(
                (float) $lineaOriginal->cantidad,
                $acreditado,
                "La línea {$lineaOriginal->numero_linea} no quedó totalmente acreditada."
            );
        }

        $this->assertSame((string) $ccf->total_pagar, (string) $nc->total_pagar);
        $this->assertSame((string) $ccf->iva_retenido, (string) $nc->iva_retenido);
        $this->assertInvariantes($nc, 'en la NC total');
    }

    // ================= 7. REDONDEOS =================

    /**
     * Escenario deliberadamente incómodo: 3 × 33.33 = 99.99 con 5 % de descuento produce
     * decimales que se cortan en tres puntos distintos (descuento, IVA y retención). Las
     * expectativas se construyen con Dinero (half-up), el mismo criterio del sistema.
     */
    public function test_redondeos_incomodos_se_cortan_donde_corresponde(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[900.00, 1]]);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva);

        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(33.33), 3);
        $nc->refresh();

        // 3 × 33.33 = 99.99 · 5 % = 4.9995 → 5.00 · base 94.99
        $this->assertSame('99.99', (string) $nc->total_gravado);
        $this->assertSame('5.00', (string) $nc->total_descuento);
        $this->assertSame('94.99', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
        // IVA 94.99 × 0.13 = 12.3487 → 12.35. La base queda BAJO el umbral: sin retención.
        $this->assertSame('12.35', (string) $nc->iva);
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('107.34', (string) $nc->total_antes_retencion);
        $this->assertSame('107.34', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en el caso de redondeos');
    }

    /**
     * El mismo escenario incómodo pero SOBRE el umbral, para que el redondeo de la
     * retención siga cubierto: 3 × 44.44 = 133.32 corta decimales en descuento, IVA y
     * retención a la vez.
     */
    public function test_redondeos_incomodos_sobre_el_umbral_tambien_cuadran(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[900.00, 1]]);

        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(44.44), 3);
        $nc->refresh();

        // 3 × 44.44 = 133.32 · 5 % = 6.666 → 6.67 · base 126.65
        $this->assertSame('133.32', (string) $nc->total_gravado);
        $this->assertSame('6.67', (string) $nc->total_descuento);
        $this->assertSame('126.65', Dinero::redondear(Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2));
        // IVA 126.65 × 0.13 = 16.4645 → 16.46 · retención 1.2665 → 1.27
        $this->assertSame('16.46', (string) $nc->iva);
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.27', (string) $nc->iva_retenido);
        $this->assertSame('143.11', (string) $nc->total_antes_retencion);
        $this->assertSame('141.84', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en el caso de redondeos sobre el umbral');
    }

    public function test_el_iva_se_redondea_una_sola_vez_sobre_el_total_no_por_linea(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(false, 0), [[1000.00, 1]]);

        // Tres líneas cuyo IVA individual se redondearía hacia arriba: 0.0433 c/u.
        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        foreach (range(1, 3) as $i) {
            $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(0.33), 1);
        }
        $nc->refresh();

        // 0.99 × 13 % = 0.1287 → 0.13. Sumar los IVA por línea (0.04 × 3 = 0.12) daría otro número.
        $this->assertSame('0.99', (string) $nc->total_gravado);
        $this->assertSame('0.13', (string) $nc->iva);
        $this->assertSame('1.12', (string) $nc->total_pagar);
        $this->assertInvariantes($nc, 'en el redondeo del IVA');
    }

    // ================= 8. VARIOS PRODUCTOS =================

    public function test_ccf_de_tres_productos_admite_devoluciones_parciales_y_totales(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[10.00, 5], [20.00, 4], [30.00, 3]]);
        $lineas = $this->lineasPorPrecio($ccf);

        // (a) Devolución de un solo producto completo.
        $soloUno = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($soloUno, $lineas['10.00'], 5);
        $soloUno->refresh();
        $this->assertSame(1, $soloUno->lineas()->count());
        $this->assertSame('50.00', (string) $soloUno->total_gravado);
        $this->assertInvariantes($soloUno, 'devolviendo un solo producto');

        // (b) Cantidades parciales de dos productos distintos.
        $dosParciales = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($dosParciales, $lineas['20.00'], 2);  // 40.00
        $this->borradores->acreditarLinea($dosParciales, $lineas['30.00'], 1);  // 30.00
        $dosParciales->refresh();
        $this->assertSame(2, $dosParciales->lineas()->count());
        $this->assertSame('70.00', (string) $dosParciales->total_gravado);
        $this->assertSame([1, 2], $this->lineasDe($dosParciales)->pluck('numero_linea')->all());
        $this->assertInvariantes($dosParciales, 'devolviendo dos productos parcialmente');

        // (c) La reversión total solo toma lo que queda: 2 del segundo y 2 del tercero.
        $resto = $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->refresh();
        $this->assertSame(2, $resto->lineas()->count());
        $this->assertSame('100.00', (string) $resto->total_gravado); // 2×20 + 2×30
        $this->assertInvariantes($resto, 'en la reversión del resto');

        // Nada quedó sin acreditar y nada se acreditó de más.
        foreach ($lineas->values() as $lineaOriginal) {
            $this->assertSame(
                (float) $lineaOriginal->cantidad,
                (float) DteLinea::where('dte_linea_original_id', $lineaOriginal->id)->sum('cantidad')
            );
        }
    }

    public function test_una_averia_puede_combinar_productos_fuera_del_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[10.00, 5], [20.00, 4], [30.00, 3]]);

        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(15.50), 2);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(7.25), 4);
        $nc->refresh();

        $this->assertSame(2, $nc->lineas()->count());
        $this->assertSame('60.00', (string) $nc->total_gravado); // 31.00 + 29.00
        // La avería NO consume saldo de las líneas del CCF.
        foreach ($this->lineasDe($ccf) as $lineaOriginal) {
            $this->assertSame(0.0, (float) DteLinea::where('dte_linea_original_id', $lineaOriginal->id)->sum('cantidad'));
        }
        $this->assertInvariantes($nc, 'en la avería combinada');
    }

    // ================= 9. P001 Y P002 =================

    public function test_dos_ccf_con_el_mismo_correlativo_en_p001_y_p002_no_se_confunden(): void
    {
        $cliente = $this->cliente(true, 5);
        $enP001 = $this->ccfAceptado($cliente, [[150.00, 1]], $this->p001);
        $enP002 = $this->ccfAceptado($cliente, [[275.00, 1]], $this->p002);

        // Se les fuerza EL MISMO correlativo final para provocar la ambigüedad.
        $enP001->update(['numero_control' => 'DTE-03-M001P001-000000000001120']);
        $enP002->update(['numero_control' => 'DTE-03-M001P002-000000000001120']);
        $enP001->refresh();
        $enP002->refresh();

        foreach ([[$enP001, $this->p001, '150.00'], [$enP002, $this->p002, '275.00']] as [$ccf, $pv, $gravado]) {
            $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->refresh();

            // Relaciona EXACTAMENTE el que se le pasó, no el otro.
            $this->assertSame($ccf->id, (int) $nc->dte_relacionado_id);
            $this->assertSame($ccf->numero_control, $nc->dteRelacionado->numero_control);
            $this->assertSame($gravado, (string) $nc->total_gravado);

            // Y la serie de la NC es la del CCF: nunca se cruza P001 con P002.
            $this->assertSame((int) $ccf->establecimiento_id, (int) $nc->establecimiento_id);
            $this->assertSame((int) $ccf->punto_venta_id, (int) $nc->punto_venta_id);
            $this->assertSame($pv->id, (int) $nc->punto_venta_id);
        }
    }

    public function test_no_se_puede_emitir_la_nc_en_un_punto_de_venta_distinto_al_del_ccf(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[150.00, 1]], $this->p001);

        $this->expectException(ValidationException::class);
        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::DevolucionProducto->value,
            'punto_venta_id' => $this->p002->id, // ← otro punto de venta
        ], $this->usuario());
    }

    // ================= 10. CCF NO VÁLIDO =================

    /** @return array<string, array{0: string}> */
    public static function estadosNoAceptados(): array
    {
        return [
            'borrador' => [EstadoDte::Borrador->value],
            'generado' => [EstadoDte::Generado->value],
            'firmado' => [EstadoDte::Firmado->value],
            'enviado' => [EstadoDte::Enviado->value],
            'rechazado' => [EstadoDte::Rechazado->value],
            'invalidado' => [EstadoDte::Invalidado->value],
        ];
    }

    /** @dataProvider estadosNoAceptados */
    public function test_no_se_puede_crear_una_nc_contra_un_ccf_no_aceptado(string $estado): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[150.00, 1]]);
        $ccf->forceFill(['estado' => $estado])->save();

        try {
            $this->borradores->crearNotaCredito($ccf->refresh(), [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
            ], $this->usuario());
            $this->fail("Se creó una NC contra un CCF en estado {$estado}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }

        $this->assertDatabaseMissing('dtes', [
            'tipo_dte' => TipoDte::NotaCredito->value, 'dte_relacionado_id' => $ccf->id,
        ]);
    }

    public function test_en_produccion_un_ccf_con_sello_mock_queda_bloqueado(): void
    {
        // CCF de PRODUCCIÓN cuya aceptación es simulada: estado Aceptado pero sello MOCK
        // y sin huella del MH. Ante Hacienda ese documento no existe.
        $ccf = $this->documentoCrudo([
            'ambiente' => '01',
            'sello_recepcion' => 'MOCK-123456',
            'fecha_procesamiento_mh' => null,
        ]);

        try {
            $this->borradores->crearNotaCredito($ccf, [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
            ], $this->usuario());
            $this->fail('Se creó una NC contra una aceptación simulada de producción.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }
    }

    public function test_el_documento_relacionado_debe_ser_un_ccf(): void
    {
        // Una factura (01) aceptada NO puede ser el original de una nota de crédito.
        $factura = $this->documentoCrudo([
            'tipo_dte' => TipoDte::Factura->value,
            'numero_control' => 'DTE-01-M001P001-000000000000777',
        ]);

        try {
            $this->borradores->crearNotaCredito($factura, [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
            ], $this->usuario());
            $this->fail('Se creó una NC contra un documento que no es CCF.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }
    }

    public function test_sin_documento_relacionado_no_hay_nota_de_credito(): void
    {
        $this->expectException(ValidationException::class);
        $this->borradores->crearNotaCredito(null, [
            'tipo' => TipoNotaCredito::DevolucionProducto->value,
        ], $this->usuario());
    }

    // ================= 11. CLIENTE / SALA / OC =================

    public function test_la_nc_toma_cliente_sala_oc_y_emisor_del_ccf_ignorando_el_formulario(): void
    {
        $cliente = $this->cliente(true, 5);
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id, 'permite_nota_credito' => true, 'activo' => true,
        ]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $this->estabId,
            'punto_venta_id' => $this->p001->id,
            'numero_orden_compra' => 'OC-BLINDAJE-001',
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto(150.00), cantidad: 1);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh());

        // POST con TODO adulterado: otro cliente, otra OC.
        $intruso = $this->cliente(false, 0);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $intruso->id,              // ← intento de cambiar el cliente
                'numero_orden_compra' => 'OC-FALSA-999',   // ← ni siquiera se acepta el campo
                'establecimiento_id' => $this->estabId,
                'punto_venta_id' => $this->p001->id,
            ])->assertSessionHasErrors('cliente_id');

        // No se creó nada.
        $this->assertDatabaseMissing('dtes', [
            'tipo_dte' => TipoDte::NotaCredito->value, 'dte_relacionado_id' => $ccf->id,
        ]);

        // Con el cliente correcto sí se crea, y todo sale del CCF.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $cliente->id,
                'numero_orden_compra' => 'OC-FALSA-999',
                'establecimiento_id' => $this->estabId,
                'punto_venta_id' => $this->p001->id,
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame((int) $ccf->cliente_id, (int) $nc->cliente_id);
        $this->assertSame((int) $ccf->cliente_sucursal_id, (int) $nc->cliente_sucursal_id);
        $this->assertSame('OC-BLINDAJE-001', $nc->numero_orden_compra); // la del CCF, no la del POST
        $this->assertSame((int) $ccf->establecimiento_id, (int) $nc->establecimiento_id);
        $this->assertSame((int) $ccf->punto_venta_id, (int) $nc->punto_venta_id);
        $this->assertSame($ccf->id, (int) $nc->dte_relacionado_id);
    }

    public function test_una_devolucion_no_puede_emitirse_a_una_sala_distinta_a_la_del_ccf(): void
    {
        $cliente = $this->cliente(true, 5);
        $salaCcf = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'permite_nota_credito' => true]);
        $otraSala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'permite_nota_credito' => true]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $salaCcf->id,
            'establecimiento_id' => $this->estabId,
            'punto_venta_id' => $this->p001->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto(150.00), cantidad: 1);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh());

        $this->expectException(ValidationException::class);
        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::DevolucionProducto->value,
            'cliente_sucursal_id' => $otraSala->id,
        ], $this->usuario());
    }

    // ================= 12. PRESENTACIÓN =================

    /**
     * Los TRES estados que puede mostrar la fila de retención en una NC:
     *   1. retención real  → fila ámbar con el monto restado;
     *   2. estuvo en juego pero la base no llega al umbral → fila gris $0.00 + motivo;
     *   3. nunca estuvo en juego (el CCF no retuvo) → la fila NO se imprime.
     * El estado 3 no puede degradarse al 2: sería insinuar un umbral que jamás se evaluó.
     */
    public function test_la_fila_de_retencion_distingue_sus_tres_estados(): void
    {
        $conRetencion = $this->ccfAceptado($this->cliente(true, 5), [[500.00, 1]]);
        $sinRetencion = $this->ccfAceptado($this->cliente(false, 5), [[500.00, 1]]);
        $motivoUmbral = 'No aplica: la base gravada neta de esta nota no supera $100.00';

        // 1. Base 106.64 sobre un CCF que retuvo → retiene 1.07.
        $ncCon = $this->nc($conRetencion, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($ncCon, $this->producto(112.25), 1);

        // 2. Base 0.85 sobre el MISMO CCF → no retiene, pero por el umbral.
        $ncUmbral = $this->nc($conRetencion, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($ncUmbral, $this->producto(0.90), 1);

        // 3. Misma base que el caso 1, pero el CCF nunca retuvo.
        $ncSin = $this->nc($sinRetencion, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($ncSin, $this->producto(112.25), 1);

        // show + editor comparten el mismo partial de totales.
        foreach ([route('facturacion.show', $ncCon), route('facturacion.edit', $ncCon)] as $url) {
            $this->actingAs($this->usuario())->get($url)->assertOk()
                ->assertSee('Retención IVA 1%')->assertSee('-$1.07')->assertDontSee($motivoUmbral);
        }
        foreach ([route('facturacion.show', $ncUmbral), route('facturacion.edit', $ncUmbral)] as $url) {
            $this->actingAs($this->usuario())->get($url)->assertOk()
                ->assertSee('Retención IVA 1%')->assertSee($motivoUmbral)->assertDontSee('-$0.01');
        }
        foreach ([route('facturacion.show', $ncSin), route('facturacion.edit', $ncSin)] as $url) {
            $this->actingAs($this->usuario())->get($url)->assertOk()
                ->assertDontSee('Retención IVA 1%')->assertDontSee($motivoUmbral);
        }

        // Las cifras mostradas explican el total en los tres casos.
        $this->assertSame('119.43', (string) $ncCon->refresh()->total_pagar);
        $this->assertSame('0.96', (string) $ncUmbral->refresh()->total_pagar);
        $this->assertSame('120.50', (string) $ncSin->refresh()->total_pagar);
    }

    // ================= 13. JSON FISCAL =================

    public function test_el_json_de_una_nc_con_retencion_cuenta_la_misma_historia_que_la_cabecera(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[112.25, 1]]);
        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(112.25), 1);

        $json = $this->resumenJson($nc);
        $r = $json['resumen'];
        $nc->refresh();

        $this->assertSame((float) $nc->total_gravado, $r['totalGravada']);
        $this->assertSame((float) $nc->total_descuento, $r['totalDescu']);
        $this->assertSame((float) $nc->descuento_gravado, $r['descuGravada']);
        $this->assertSame(106.64, $r['subTotal']);                 // base gravada neta
        $this->assertSame((float) $nc->iva, $r['tributos'][0]['valor']);
        $this->assertSame((float) $nc->iva_retenido, $r['ivaRete1']);
        // La NC v3 no lleva totalPagar: montoTotalOperacion es el único total y va NETO.
        $this->assertArrayNotHasKey('totalPagar', $r);
        $this->assertSame((float) $nc->total_pagar, $r['montoTotalOperacion']);
        $this->assertSame(119.43, $r['montoTotalOperacion']);

        // Fórmula del MH: subTotal + tributos − ivaRete1 − reteRenta.
        $this->assertSame(
            round($r['subTotal'] + $r['tributos'][0]['valor'] - $r['ivaRete1'] - $r['reteRenta'], 2),
            $r['montoTotalOperacion']
        );

        // Documento relacionado: exactamente el CCF elegido.
        $this->assertSame($ccf->codigo_generacion, $json['documentoRelacionado'][0]['numeroDocumento']);
        $this->assertSame('03', $json['documentoRelacionado'][0]['tipoDocumento']);
    }

    public function test_el_json_de_una_nc_sin_retencion_no_resta_nada(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(false, 5), [[112.25, 1]]);
        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(112.25), 1);

        $r = $this->resumenJson($nc)['resumen'];
        $nc->refresh();

        $this->assertSame(0.0, $r['ivaRete1']);
        $this->assertSame(0.0, $r['reteRenta']);
        $this->assertSame(round($r['subTotal'] + $r['tributos'][0]['valor'], 2), $r['montoTotalOperacion']);
        $this->assertSame((float) $nc->total_pagar, $r['montoTotalOperacion']);
        $this->assertSame(120.50, $r['montoTotalOperacion']);
    }

    public function test_el_json_de_una_devolucion_parcial_lleva_su_propia_retencion_proporcional(): void
    {
        $ccf = $this->ccfAceptado($this->cliente(true, 5), [[300.00, 4]]);
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->acreditarLinea($nc, $this->lineasDe($ccf)->first(), 1);

        $r = $this->resumenJson($nc)['resumen'];
        $nc->refresh();

        $this->assertSame(300.0, $r['totalGravada']);
        $this->assertSame(285.0, $r['subTotal']);
        $this->assertSame((float) $nc->iva_retenido, $r['ivaRete1']);
        $this->assertSame(2.85, $r['ivaRete1']);
        $this->assertSame((float) $nc->total_pagar, $r['montoTotalOperacion']);
    }
}
