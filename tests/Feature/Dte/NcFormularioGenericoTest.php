<?php

namespace Tests\Feature\Dte;

use App\Enums\OrigenAveria;
use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * El formulario de nota de crédito tiene que servir a CUALQUIER cliente.
 *
 * El riesgo que cubren estas pruebas es concreto: el módulo nació resolviendo el caso de
 * un cliente grande con exigencias documentales propias (códigos de albarán, descuentos
 * por modalidad), y es fácil que esas exigencias se filtren a la pantalla y pasen a
 * parecer requisitos de todos. Acá se contrasta el mismo flujo con un cliente CON perfil
 * y otro SIN perfil, y se exige que el segundo funcione sin ver nada de lo del primero.
 *
 * Nada de esto emite, firma ni transmite.
 */
class NcFormularioGenericoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return compact('estab', 'pv');
    }

    private function sala(Cliente $cliente, string $nombre): ClienteSucursal
    {
        return ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'nombre' => $nombre,
            'activo' => true,
            'permite_nota_credito' => true,
        ]);
    }

    /** Cliente CON perfil documental: mapea sus propias modalidades a SUS códigos. */
    private function clienteConPerfil(string $codigoDevolucion, string $codigoAveria): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cadena Con Perfil']);
        $perfil = ClientePerfilDocumento::create(['cliente_id' => $cliente->id, 'activo' => true]);

        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value,
            'codigo_externo' => $codigoDevolucion,
            'descuento_origen' => OrigenDescuentoNc::Ninguno->value,
        ]);
        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::Averia->value,
            'codigo_externo' => $codigoAveria,
            'descuento_origen' => OrigenDescuentoNc::Ccf->value,
        ]);

        return $cliente;
    }

    private function ccfAceptado(Cliente $cliente, ?ClienteSucursal $sala, array $emisor, float $precio = 10, int $cantidad = 10): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: $cantidad);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    // ------------------------------------------------------- la pantalla es genérica

    /**
     * La pantalla no puede nombrar a ningún cliente ni traer códigos de albarán fijos:
     * eso convertiría la exigencia de uno en requisito visible para todos.
     */
    public function test_la_pantalla_no_cablea_ningun_cliente_ni_codigo(): void
    {
        $emisor = $this->emisor();
        $sinPerfil = Cliente::factory()->contribuyente()->create(['nombre' => 'Tienda Sin Perfil']);
        $this->ccfAceptado($sinPerfil, $this->sala($sinPerfil, 'Sucursal Única'), $emisor);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito'))->assertOk()->getContent();

        // Ni códigos de albarán fijos…
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*AC\d{2}\s*</',
            $html,
            'La pantalla imprime un código de albarán fijo: debe salir del perfil del cliente.'
        );

        // …ni las cuatro modalidades dejan de estar.
        foreach (['Devolución o faltante de entrega', 'Avería', 'Pronto pago', 'Otro ajuste'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /**
     * Cliente SIN perfil: el flujo completo funciona y no aparece ningún código. Es el
     * caso de casi todos los clientes.
     */
    public function test_un_cliente_sin_perfil_emite_su_nota_con_las_reglas_generales(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Tienda Sin Perfil']);
        $sala = $this->sala($cliente, 'Sucursal Única');
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'devolucion_faltante',
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $sala->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame(TipoNotaCredito::DevolucionProducto, $nc->tipo_nota_credito);
        $this->assertSame($cliente->id, $nc->cliente_id);

        // Sin perfil no hay panel de albarán ni código en la pantalla de captura.
        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertDontSee('Albarán del cliente')
            ->assertSee('Devolución o faltante de entrega');
    }

    /** Cliente CON perfil: ve SU código, no uno de ejemplo. */
    public function test_un_cliente_con_perfil_ve_su_propio_codigo(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConPerfil('ZZ09', 'ZZ07');
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        // En la ficha del CCF, el selector de modalidades rotula con el código del cliente.
        $this->actingAs($this->usuario())->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('ZZ09')
            ->assertSee('ZZ07');

        // Y en la captura de la nota, el panel del albarán usa ese mismo código.
        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::Averia->value,
            'origen_averia' => OrigenAveria::Entrega->value,
        ], $this->usuario());

        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('Albarán del cliente')
            ->assertSee('ZZ07');
    }

    /**
     * El descuento sigue la regla DECLARADA por cada cliente, y el que no declaró nada
     * conserva el criterio histórico. Es la parte fiscal que no puede uniformarse.
     */
    public function test_el_descuento_depende_del_perfil_y_no_de_la_pantalla(): void
    {
        $emisor = $this->emisor();

        // CON perfil: devolución declarada SIN descuento, avería declarada heredando el CCF.
        $conPerfil = $this->clienteConPerfil('ZZ09', 'ZZ07');
        $conPerfil->update(['descuento_global_default' => 5]);
        $salaA = $this->sala($conPerfil, 'Sucursal Norte');
        $ccfA = $this->ccfAceptado($conPerfil, $salaA, $emisor);
        $this->assertSame('5.00', $ccfA->descuento_porcentaje_aplicado);

        $devolucion = $this->borradores->crearNotaCredito($ccfA, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->establecerCantidadAcreditada($devolucion, $ccfA->lineas()->firstOrFail(), 6);
        $this->assertSame('0.00', $devolucion->refresh()->descuento_porcentaje_aplicado);

        $averia = $this->borradores->crearNotaCredito($ccfA, [
            'tipo' => TipoNotaCredito::Averia->value,
            'origen_averia' => OrigenAveria::Entrega->value,
        ], $this->usuario());
        $this->borradores->agregarProductoNotaCreditoAveria($averia, Producto::factory()->create([
            'precio_unitario' => 2, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]), 1);
        $this->assertSame('5.00', $averia->refresh()->descuento_porcentaje_aplicado);

        // SIN perfil: criterio histórico — la devolución hereda el descuento del CCF.
        $sinPerfil = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $salaB = $this->sala($sinPerfil, 'Sucursal Única');
        $ccfB = $this->ccfAceptado($sinPerfil, $salaB, $emisor);

        $ncB = $this->borradores->crearNotaCredito($ccfB, ['tipo' => TipoNotaCredito::DevolucionProducto->value], $this->usuario());
        $this->borradores->establecerCantidadAcreditada($ncB, $ccfB->lineas()->firstOrFail(), 6);
        $this->assertSame(
            '5.00',
            $ncB->refresh()->descuento_porcentaje_aplicado,
            'Un cliente sin perfil no puede cambiar de comportamiento por existir el perfil de otro.'
        );
    }

    // ------------------------------------------------------- reversión total

    /**
     * «Revertir con nota de crédito» tiene que dejar la nota lista para anular
     * económicamente el CCF: todas las líneas acreditadas y el MISMO total.
     */
    public function test_revertir_deja_la_nota_por_el_importe_completo_del_ccf(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Única');
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor, precio: 10, cantidad: 10);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.nota-credito.revertir', $ccf))
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();

        // Mismo importe que el CCF: es lo que lo anula económicamente.
        $this->assertSame($ccf->total_gravado, $nc->total_gravado);
        $this->assertSame($ccf->total_pagar, $nc->total_pagar);
        $this->assertSame($ccf->lineas()->count(), $nc->lineas()->count());

        // Y cae en el MISMO editor unificado, con las líneas ya acreditadas.
        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('Líneas del CCF original')
            ->assertSee('Generar nota de crédito');

        // El CCF original queda intacto: la reversión no lo toca.
        $this->assertSame('Aceptado', $ccf->refresh()->estado->label());
    }

    // ------------------------------------------------------- sala del hallazgo

    /**
     * Avería encontrada revisando inventario en una sala, acreditada contra un CCF de
     * OTRA sala del mismo cliente: se permite, se registra y exige motivo.
     */
    public function test_averia_de_inventario_puede_cruzar_de_sala_con_motivo(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaCcf = $this->sala($cliente, 'Sucursal Norte');
        $salaHallazgo = $this->sala($cliente, 'Sucursal Sur');
        $ccf = $this->ccfAceptado($cliente, $salaCcf, $emisor);

        // Sin motivo: rechazado.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'averia',
                'origen_averia' => OrigenAveria::InventarioSala->value,
                'sucursal_hallazgo_id' => $salaHallazgo->id,
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $salaCcf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
            ])->assertSessionHasErrors('motivo');

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());

        // Con motivo: se crea, y la sala del hallazgo queda REGISTRADA.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'averia',
                'origen_averia' => OrigenAveria::InventarioSala->value,
                'sucursal_hallazgo_id' => $salaHallazgo->id,
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $salaCcf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Producto dañado hallado en el estante de Sucursal Sur.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame(OrigenAveria::InventarioSala, $nc->origen_averia);
        $this->assertSame($salaHallazgo->id, $nc->sucursal_hallazgo_id);
        // La sala RECEPTORA sigue siendo la del CCF: el hallazgo no la cambia.
        $this->assertSame($salaCcf->id, $nc->cliente_sucursal_id);

        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()->assertSee('Sala del hallazgo')->assertSee('Sucursal Sur');
    }

    /** Cruzar de CLIENTE no se permite nunca, por ninguna vía. */
    public function test_la_sala_del_hallazgo_no_puede_ser_de_otro_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaCcf = $this->sala($cliente, 'Sucursal Norte');
        $ccf = $this->ccfAceptado($cliente, $salaCcf, $emisor);

        $ajeno = Cliente::factory()->contribuyente()->create();
        $salaAjena = $this->sala($ajeno, 'Sala de Otro Cliente');

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'averia',
                'origen_averia' => OrigenAveria::InventarioSala->value,
                'sucursal_hallazgo_id' => $salaAjena->id,
                'dte_relacionado_id' => $ccf->id,
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Intento de cruzar de cliente.',
            ])->assertSessionHasErrors('sucursal_hallazgo_id');

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    /** La avería detectada en una entrega no arrastra sala de hallazgo. */
    public function test_la_averia_en_entrega_no_registra_sala_de_hallazgo(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $otra = $this->sala($cliente, 'Sucursal Sur');
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::Averia->value,
            'origen_averia' => OrigenAveria::Entrega->value,
            // Llega, pero no corresponde a este origen: se descarta en vez de guardarse.
            'sucursal_hallazgo_id' => $otra->id,
        ], $this->usuario());

        $this->assertSame(OrigenAveria::Entrega, $nc->origen_averia);
        $this->assertNull($nc->sucursal_hallazgo_id);
    }

    // ------------------------------------------------------- pronto pago

    public function test_pronto_pago_a_otra_sala_exige_motivo_y_conserva_el_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaVenta = $this->sala($cliente, 'Sucursal Norte');
        $oficina = $this->sala($cliente, 'Bodega Administrativa');
        $ccf = $this->ccfAceptado($cliente, $salaVenta, $emisor);

        $base = [
            'modalidad' => 'pronto_pago',
            'dte_relacionado_id' => $ccf->id,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $oficina->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ];

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), $base)
            ->assertSessionHasErrors('motivo');

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), $base + ['motivo' => 'Cobro centralizado.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame(TipoNotaCredito::ProntoPago, $nc->tipo_nota_credito);
        $this->assertSame($oficina->id, $nc->cliente_sucursal_id);
        $this->assertSame($cliente->id, $nc->cliente_id, 'El cliente fiscal nunca cambia.');
    }
}
