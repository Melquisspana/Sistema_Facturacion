<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El día de verdad visto desde la oficina (Melqui, 24/11/2026): lo que la fábrica mandó con
 * la persona que se regresa a las 3 aparece en Facturación › «Llegó de pedidos», cada cosa
 * en su grupo; «Facturar» va directo al borrador solo con una OC clara; lo que espera el
 * resto no se factura dos veces. La caja que solo tiene su orden impresa no llega. Datos
 * inventados.
 */
class LlegoDePedidosDiaRealTest extends TestCase
{
    use RefreshDatabase;

    private User $oficina;

    private User $fabrica;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $cerca;

    private Ruta $lejos;

    private Producto $galleta;

    private Producto $canilla;

    private Producto $quesadilla;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-23 07:30:00');
        config()->set('planta.enabled', false);

        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->cerca = Ruta::create(['nombre' => 'Zona Cerca Inventada']);
        $this->lejos = Ruta::create(['nombre' => 'Zona Lejos Inventada']);

        $empresa = Empresa::create(['razon_social' => 'Empresa Inventada', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.25]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 2.00]);
        $this->quesadilla = Producto::factory()->create(['nombre' => 'Quesadilla de prueba', 'codigo' => 'Q01', 'precio_unitario' => 3.00]);
    }

    private function sala(string $letra, string $codigo, Ruta $zona, bool $exigeOc): ClienteSucursal
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Inventado '.$letra, 'requiere_orden_compra' => $exigeOc])
            ->sucursales()->create(['nombre' => 'Súper Ficticio '.$letra, 'codigo' => $codigo, 'ruta_id' => $zona->id]);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(ClienteSucursal $sala, array $cantidades, ?string $oc = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado, 'numero_orden_compra' => $oc,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function ocDelCorreo(ClienteSucursal $sala, string $numero): void
    {
        OrdenCompraRecibida::create([
            'numero' => $numero, 'sala_codigo' => $sala->codigo, 'cliente_sucursal_id' => $sala->id,
            'fecha_creacion' => now()->toDateString(), 'fecha_despacho' => now()->addDay()->toDateString(), 'total' => '22.90',
            'pdf_path' => 'ppq/ordenes-compra/x.pdf', 'pdf_hash' => hash('sha256', $numero),
            'gmail_message_id' => 'm-'.$numero, 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
    }

    /** @return array<int, string> [linea_id => valor] */
    private function porLinea(PedidoSala $pedido, array $porProducto): array
    {
        return collect($porProducto)->mapWithKeys(fn ($v, $producto) => [$pedido->lineas()->where('producto_id', $producto)->value('id') => $v])->all();
    }

    /** La PC de pedidos: lo deja listo y en la tarde lo manda con «Imprimir y mandar». */
    private function mandar(PedidoSala $pedido, array $cantidades, bool $sigue = false): PedidoSalaEnvio
    {
        $fabrica = app(PedidosFabrica::class);
        $fabrica->guardarBorrador($pedido, ['cantidades' => $this->porLinea($pedido, $cantidades), 'sigue' => $sigue], $this->fabrica);
        $fabrica->marcarListo($pedido->refresh(), true, $this->fabrica, $pedido->load($fabrica->relaciones())->versionProduccion());

        return $fabrica->mandar($pedido->refresh(), [
            'clave' => (string) Str::uuid(),
            'version' => $pedido->load($fabrica->relaciones())->versionProduccion(),
            'cantidades' => $this->porLinea($pedido, $cantidades), 'sigue' => $sigue,
        ], $this->fabrica);
    }

    /** Hacienda aceptó el CCF ese día. */
    private function emitir(Dte $dte): void
    {
        $dte->update(['estado' => EstadoDte::Aceptado, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->format('H:i:s')]);
    }

    private function lista(array $filtro = []): TestResponse
    {
        return $this->actingAs($this->oficina)->get(route('facturacion.llego-de-fabrica', $filtro))->assertOk();
    }

    /** @return array<string, list<int>> [grupo => N.º de pedido] */
    private function grupos(TestResponse $lista): array
    {
        return $lista->viewData('grupos')->mapWithKeys(fn ($g) => [$g['clave'] => $g['pedidos']->pluck('id')->all()])->all();
    }

    /** El «Facturar» de la fila: null si no hay; la OC que manda si va directo; 'formulario' si abre el formulario. */
    private function facturar(string $html, PedidoSala $pedido): ?string
    {
        $accion = preg_quote('action="'.route('facturacion.pedidos.facturar', $pedido).'"', '/');
        if (preg_match('/<form method="POST" '.$accion.'>(.*?)<\/form>/s', $html, $form) === 1) {
            preg_match('/name="numero_orden_compra" value="([^"]*)"/', $form[1], $oc);

            return $oc[1] ?? '';
        }

        return str_contains($html, 'href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id])).'"') ? 'formulario' : null;
    }

    public function test_lo_que_trajeron_a_las_tres_sale_en_su_grupo_y_facturar_va_directo_solo_con_una_oc_clara(): void
    {
        $g = $this->galleta->id;
        $c = $this->canilla->id;
        // Cuatro salas: A no pide OC pero el vendedor la anotó; B pide OC y no llegó; E pide OC
        // y llegó una por correo; V pide OC y llegaron dos.
        $a = $this->pedido($this->sala('A', '9101', $this->cerca, false), [$g => '10'], '26119101000001');
        $salaB = $this->sala('B', '9102', $this->lejos, true);
        $b = $this->pedido($salaB, [$g => '4']);
        $salaE = $this->sala('E', '9105', $this->cerca, true);
        $this->ocDelCorreo($salaE, '26119105000005');
        $e = $this->pedido($salaE, [$c => '5']);
        $salaV = $this->sala('V', '9106', $this->lejos, true);
        $this->ocDelCorreo($salaV, '26119106000061');
        $this->ocDelCorreo($salaV, '26119106000062');
        $v = $this->pedido($salaV, [$c => '2']);
        // C: solo le imprimieron la orden para la caja; no salió.
        $cc = $this->pedido($this->sala('C', '9103', $this->cerca, false), [$g => '12'], '26119103000003');
        app(PedidosFabrica::class)->guardarBorrador($cc, ['cantidades' => $this->porLinea($cc, [$g => '6'])], $this->fabrica);
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $cc))->assertOk();

        // 3 de la tarde: la fábrica manda A, B, E y V.
        $this->travelTo('2026-11-23 15:00');
        foreach ([[$a, [$g => '10']], [$b, [$g => '4']], [$e, [$c => '5']], [$v, [$c => '2']]] as [$pedido, $cantidades]) {
            $this->mandar($pedido, $cantidades);
        }

        $this->travelTo('2026-11-23 15:30');
        $lista = $this->lista();
        $this->assertSame(['para_facturar' => [$a->id, $e->id, $v->id], 'esperando_oc' => [$b->id], 'esperando_resto' => [], 'facturados' => []], $this->grupos($lista));
        $html = $lista->getContent();
        $this->assertStringNotContainsString('Súper Ficticio C', $html);
        $this->assertSame('26119101000001', $this->facturar($html, $a));
        $this->assertSame('26119105000005', $this->facturar($html, $e));
        $this->assertSame('formulario', $this->facturar($html, $v));
        $this->assertSame('formulario', $this->facturar($html, $b));
        $this->assertNull($this->facturar($html, $cc));
        // Pastillas por zona, solo para mirar.
        $lista->assertSeeInOrder(['Todas 4', 'Zona Cerca Inventada 2', 'Zona Lejos Inventada 2']);
        $this->assertSame(['para_facturar' => [$a->id, $e->id], 'esperando_oc' => [], 'esperando_resto' => [], 'facturados' => []],
            $this->grupos($this->lista(['ruta' => (string) $this->cerca->id])));

        // La caja que solo tiene su orden tampoco se puede facturar a mano.
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $cc), ['numero_orden_compra' => '26119103000003', 'directo' => '1'])
            ->assertRedirect(route('facturacion.create-ccf', ['pedido' => $cc->id]))->assertSessionHasErrors();
        $this->assertSame(0, Dte::withTrashed()->count());

        // «Facturar» de A, con doble clic: un solo borrador, con la OC del vendedor, y abre el editor.
        $datos = ['numero_orden_compra' => '26119101000001', 'directo' => '1'];
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $a), $datos)->assertSessionHasNoErrors();
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $a), $datos)
            ->assertSessionHasNoErrors()->assertRedirect(route('facturacion.edit', $dteA = Dte::sole()));
        $this->assertSame('26119101000001', $dteA->numero_orden_compra);
        $this->assertSame(EstadoDte::Borrador, $dteA->estado);
        $this->emitir($dteA);

        $this->assertSame(['para_facturar' => [$e->id, $v->id], 'esperando_oc' => [$b->id], 'esperando_resto' => [], 'facturados' => [$a->id]], $this->grupos($this->lista()));
        $this->lista()->assertSee('Ya facturados hoy');
        // Con el CCF emitido, otro «Facturar» de A no hace nada.
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $a), $datos)->assertSessionHasErrors();
        $this->assertSame(1, Dte::withTrashed()->count());
    }

    public function test_lo_que_espera_el_resto_no_se_factura_dos_veces_con_la_misma_oc(): void
    {
        $g = $this->galleta->id;
        $c = $this->canilla->id;
        $q = $this->quesadilla->id;
        $d = $this->pedido($this->sala('D', '9104', $this->lejos, true), [$g => '8', $c => '4', $q => '2'], '26119104000004');

        // Martes 3 de la tarde: D sale en parte; la quesadilla va el jueves.
        $this->travelTo('2026-11-24 15:00');
        $this->mandar($d, [$g => '8', $c => '4'], sigue: true);
        $this->assertSame(EstadoPedidoSala::Parcial, $d->refresh()->estado);
        $this->assertSame('26119104000004', $this->facturar($this->lista()->getContent(), $d));
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $d), ['numero_orden_compra' => '26119104000004', 'directo' => '1'])
            ->assertSessionHasNoErrors();
        $this->emitir($uno = Dte::sole());

        // Queda en «Esperando el resto», sin «Facturar».
        $lista = $this->lista();
        $this->assertSame([$d->id], $this->grupos($lista)['esperando_resto']);
        $this->assertNull($this->facturar($lista->getContent(), $d));
        $lista->assertSee('Ya se facturó lo que llegó y falta que pedidos mande el resto.');
        // Ni a mano: no hay nada nuevo por facturar.
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $d), ['numero_orden_compra' => '26119104000004', 'directo' => '1'])
            ->assertSessionHasErrors();
        $this->assertSame(1, Dte::withTrashed()->count());

        // Martes 4:30: el resto queda listo en la fábrica; sigue esperando, igual.
        $this->travelTo('2026-11-24 16:30');
        $fabrica = app(PedidosFabrica::class);
        $fabrica->guardarBorrador($d, ['cantidades' => $this->porLinea($d, [$q => '2'])], $this->fabrica);
        $fabrica->marcarListo($d->refresh(), true, $this->fabrica, $d->load($fabrica->relaciones())->versionProduccion());
        $lista = $this->lista();
        $this->assertSame([$d->id], $this->grupos($lista)['esperando_resto']);
        $this->assertNull($this->facturar($lista->getContent(), $d));

        // Jueves: llega el resto. No va directo: la OC ya tiene un CCF y hay que revisarla.
        $this->travelTo('2026-11-26 15:00');
        $fabrica->mandar($d->refresh(), [
            'clave' => (string) Str::uuid(), 'version' => $d->load($fabrica->relaciones())->versionProduccion(),
            'cantidades' => $this->porLinea($d, [$q => '2']),
        ], $this->fabrica);
        $this->travelTo('2026-11-26 15:30');
        $lista = $this->lista();
        $this->assertSame([$d->id], $this->grupos($lista)['esperando_oc']);
        $this->assertSame('Esa OC ya tiene un CCF.', $lista->viewData('oc')[$d->id]['aviso']);
        $this->assertSame('formulario', $this->facturar($lista->getContent(), $d));
        $lista->assertSee('Revisar OC');
        $this->actingAs($this->oficina)->get(route('facturacion.create-ccf', ['pedido' => $d->id]))->assertOk()
            ->assertViewHas('propuesta', fn ($p) => $p['oc_con_ccf']?->is($uno));
    }

    /**
     * La primera parte se facturó con la OC que llegó por correo; antes de que llegue el resto
     * entra otra OC de la misma sala (la del próximo pedido). El resto no puede irse directo
     * con esa otra OC, ni venir puesta en el formulario como si fuera la suya.
     */
    public function test_el_resto_no_se_factura_directo_con_la_oc_del_correo_de_otro_pedido(): void
    {
        $g = $this->galleta->id;
        $q = $this->quesadilla->id;
        $sala = $this->sala('R', '9107', $this->cerca, true);
        $this->ocDelCorreo($sala, '26119107000071');
        $r = $this->pedido($sala, [$g => '6', $q => '2']);

        $this->travelTo('2026-11-23 15:00');
        $this->mandar($r, [$g => '6'], sigue: true);
        $this->assertSame('26119107000071', $this->facturar($this->lista()->getContent(), $r));
        $this->actingAs($this->oficina)->post(route('facturacion.pedidos.facturar', $r), ['numero_orden_compra' => '26119107000071', 'directo' => '1'])
            ->assertSessionHasNoErrors();
        $this->emitir(Dte::sole());
        $this->assertSame([$r->id], $this->grupos($this->lista())['esperando_resto']);

        // Miércoles: llega por correo la OC del próximo pedido de la sala.
        $this->travelTo('2026-11-25 09:00');
        $this->ocDelCorreo($sala, '26119107000072');
        // Jueves: llega el resto.
        $this->travelTo('2026-11-26 15:00');
        $fabrica = app(PedidosFabrica::class);
        $fabrica->mandar($r->refresh(), [
            'clave' => (string) Str::uuid(), 'version' => $r->load($fabrica->relaciones())->versionProduccion(),
            'cantidades' => $this->porLinea($r, [$q => '2']),
        ], $this->fabrica);

        $lista = $this->lista();
        $this->assertNotSame('26119107000072', $this->facturar($lista->getContent(), $r), 'el resto no se factura directo con la OC de otro pedido');
        // Como el resto con la OC del vendedor: la de su CCF, con el aviso, y se revisa en el formulario.
        $this->assertSame([$r->id], $this->grupos($lista)['esperando_oc']);
        $this->assertSame('formulario', $this->facturar($lista->getContent(), $r));
        $oc = $lista->viewData('oc')[$r->id];
        $this->assertSame(['26119107000071'], $oc['propuestas']);
        $this->assertSame('OC del CCF', $oc['titulo']);
        $this->assertSame('Esa OC ya tiene un CCF.', $oc['aviso']);
        $this->actingAs($this->oficina)->get(route('facturacion.create-ccf', ['pedido' => $r->id]))->assertOk()
            ->assertViewHas('propuesta', fn ($p) => $p['oc'] === '26119107000071' && $p['oc_con_ccf']?->is(Dte::sole()));
    }

    public function test_la_cuenta_de_pedidos_y_el_vendedor_no_ven_llego_de_pedidos_ni_facturan(): void
    {
        $g = $this->galleta->id;
        $a = $this->pedido($this->sala('A', '9101', $this->cerca, false), [$g => '10'], '26119101000001');
        $this->travelTo('2026-11-23 15:00');
        $this->mandar($a, [$g => '10']);

        foreach ([$this->fabrica, $this->vendedor] as $usuario) {
            $this->actingAs($usuario)->get(route('facturacion.llego-de-fabrica'))->assertForbidden();
            $this->actingAs($usuario)->post(route('facturacion.pedidos.facturar', $a), ['numero_orden_compra' => '26119101000001', 'directo' => '1'])->assertForbidden();
            $this->actingAs($usuario)->get(route('facturacion.create-ccf', ['pedido' => $a->id]))->assertForbidden();
        }
        $this->assertSame(0, Dte::withTrashed()->count());
    }
}
