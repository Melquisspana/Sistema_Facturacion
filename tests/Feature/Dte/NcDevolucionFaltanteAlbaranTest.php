<?php

namespace Tests\Feature\Dte;

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
use App\Services\Dte\AlbaranNotaCreditoService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * FALTANTE DE ENTREGA: la modalidad interna que la pantalla agrupa con la devolución y que
 * el perfil del cliente nunca declara por separado.
 *
 * EL CASO REAL que obligó a esto es el albarán de crédito
 * `26-08-0207-00-003874-AC04-0001.PDF` de Calleja: sala 0207 (Santa Rosa de Lima), albarán
 * AC04 número 3874 del 27/08/2026, contra el CCF DTE-03-M001P002-000000000000119. Un solo
 * renglón —DULCE DE TAMARINDO, facturado 10, recibido 9, una unidad de diferencia— con
 * costo proveedor $0.9800, y el papel imprime:
 *
 *     Gravado 0.98 · Exento 0.00 · I.V.A 0.13 · TOTAL 1.11
 *     Descuentos Generales: Monetario 0.00 · Porcentaje 0
 *     (*) El Costo del Proveedor tiene aplicado todos los descuentos.
 *
 * La nota salía por $1.05. La causa NO estaba en el cálculo: estaba en la RESOLUCIÓN DEL
 * PERFIL. Calleja declaró `averia -> AC02 · descuento del CCF` y
 * `devolucion_producto -> AC04 · sin descuento`, pero nunca `faltante_entrega`, porque en su
 * pantalla devolución y faltante son UNA sola opción. Al preguntar por la fila exacta, un
 * faltante no encontraba regla, caía al criterio histórico —heredar el descuento del CCF— y
 * se le restaba el 5 %: 0.98 − 0.05 = 0.93 gravado, IVA 0.12, total 1.05. La pantalla, en
 * cambio, ya rotulaba «AC04» para las dos: formulario y motor decían cosas distintas.
 *
 * Estas pruebas fijan las dos mitades: que las dos modalidades internas de la misma
 * modalidad operativa calculen IGUAL, y que la avería —que sí lleva el descuento— no se
 * mueva ni un centavo.
 */
class NcDevolucionFaltanteAlbaranTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
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

    /** Cliente con el 5 % de descuento global, como Calleja. */
    private function clienteConDescuento(float $pct = 5): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['descuento_global_default' => $pct]);
    }

    /** Sala del cliente con su código, para que el albarán resuelva la suya. */
    private function sala(Cliente $cliente, string $codigo, string $nombre): ClienteSucursal
    {
        return ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'activo' => true,
            'permite_nota_credito' => true,
        ]);
    }

    /**
     * El perfil REAL de Calleja, tal como está en la instalación: avería y devolución
     * mapeadas, faltante NO. Es precisamente la configuración que rompía el cálculo.
     */
    private function perfilComoElReal(Cliente $cliente, bool $exigeAlbaran = false): ClientePerfilDocumento
    {
        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1',
            'exige_albaran_en_nc' => $exigeAlbaran,
            'tolerancia_albaran' => 0,
        ]);

        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::Averia->value,
            'codigo_externo' => 'AC02',
            'etiqueta_externa' => 'Albarán Avería',
            'descuento_origen' => OrigenDescuentoNc::Ccf->value,
        ]);

        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value,
            'codigo_externo' => 'AC04',
            'etiqueta_externa' => 'Albarán Devolución',
            'descuento_origen' => OrigenDescuentoNc::Ninguno->value,
        ]);

        // El resolutor memoiza por request; acá el perfil nace después de resolverlo.
        app(PerfilDocumentoResolver::class)->olvidar();

        return $perfil;
    }

    private function producto(float $precio, string $nombre = 'PRODUCTO'): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre,
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    /**
     * CCF aceptado de una sala concreta.
     *
     * @param  array<int, array{0: Producto, 1: int|float}>  $lineas
     */
    private function ccfAceptado(array $emisor, Cliente $cliente, ?ClienteSucursal $sala, array $lineas): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        foreach ($lineas as [$producto, $cantidad]) {
            $this->borradores->agregarLineaDesdeProducto($ccf, $producto, $cantidad);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    /** Crea la NC por la ruta de la TARJETA DEL CCF (modalidad + submotivo, como la pantalla). */
    private function ncDesdeCcf(Dte $ccf, string $modalidad, ?string $tipo = null): Dte
    {
        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.store', $ccf), array_filter([
                'modalidad' => $modalidad,
                'tipo' => $tipo,
                'motivo' => 'Prueba',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        return Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();
    }

    // ============================================================ 1. el cálculo

    /**
     * DORADA · el albarán AC04 3874 real, capturado como FALTANTE DE ENTREGA.
     *
     * Una unidad a $0.98: gravado 0.98, descuento global 0.00 —el albarán imprime
     * «Porcentaje 0» y el costo proveedor ya trae aplicados todos los descuentos—, IVA 0.13
     * y total 1.11. Antes de la corrección salía 0.93 + 0.12 = 1.05, porque el faltante no
     * encontraba la regla declarada para su modalidad operativa y heredaba el 5 % del CCF.
     */
    public function test_dorada_faltante_reproduce_el_albaran_ac04_3874(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Súper Selectos Santa Rosa de Lima');

        $tamarindo = $this->producto(0.98, 'DULCE DE TAMARINDO LA NEGRITA BOLSA');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$tamarindo, 10]]);
        $this->assertSame('5.00', $ccf->descuento_porcentaje_aplicado);

        // Facturado 10, recibido 9: se acredita UNA unidad.
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);
        $this->assertSame(TipoNotaCredito::FaltanteEntrega, $nc->tipo_nota_credito);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $nc->refresh();
        $this->assertSame('0.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('0.98', $nc->total_gravado);
        $this->assertSame('0.00', $nc->descuento_global);
        $this->assertSame('0.00', $nc->descuento_gravado);
        $this->assertSame('0.13', $nc->iva);
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.11', $nc->total_pagar);
    }

    /**
     * Las dos modalidades internas de «Devolución o faltante» calculan IGUAL. Es la razón de
     * ser de la modalidad operativa: son dos hechos distintos con un solo tratamiento
     * fiscal, y el perfil declara uno que gobierna a los dos.
     */
    public function test_devolucion_y_faltante_dan_exactamente_el_mismo_resultado(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');

        $tamarindo = $this->producto(0.98, 'DULCE DE TAMARINDO');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$tamarindo, 10]]);
        $linea = $ccf->lineas()->firstOrFail();

        $totales = [];
        foreach ([TipoNotaCredito::DevolucionProducto, TipoNotaCredito::FaltanteEntrega] as $tipo) {
            $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', $tipo->value);
            $this->actingAs($this->usuario())
                ->post(route('facturacion.acreditar', [$nc, $linea]), ['cantidad' => 1])
                ->assertRedirect()->assertSessionHasNoErrors();

            $nc->refresh();
            $totales[$tipo->value] = [
                'pct' => $nc->descuento_porcentaje_aplicado,
                'gravado' => $nc->total_gravado,
                'iva' => $nc->iva,
                'total' => $nc->total_pagar,
            ];
        }

        $this->assertSame(
            $totales[TipoNotaCredito::DevolucionProducto->value],
            $totales[TipoNotaCredito::FaltanteEntrega->value]
        );
        $this->assertSame('1.11', $totales[TipoNotaCredito::FaltanteEntrega->value]['total']);
    }

    /**
     * La AVERÍA no se movió: sigue heredando el 5 % del CCF. Reproduce el AC02 real
     * (0.90 + 0.95 + 1.04 = 2.89 bruto · descuento 0.14 · gravado neto 2.75 · IVA 0.36 ·
     * total 3.11), con el mismo perfil que ahora resuelve el faltante por hermandad.
     */
    public function test_averia_conserva_el_descuento_del_ccf(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0045', 'Sonsonate II');

        $huevitos = $this->producto(0.90, 'HUEVITOS');
        $miel = $this->producto(0.95, 'DULCE DE MIEL');
        $mani = $this->producto(1.04, 'MANI HORNEADO');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$huevitos, 20], [$miel, 20], [$mani, 20]]);

        $nc = $this->ncDesdeCcf($ccf, 'averia');
        $this->assertSame(TipoNotaCredito::Averia, $nc->tipo_nota_credito);

        foreach ([$huevitos, $miel, $mani] as $producto) {
            $this->actingAs($this->usuario())
                ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 1])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $nc->refresh();
        $this->assertSame('5.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('2.89', $nc->total_gravado);
        $this->assertSame('0.14', $nc->descuento_global);
        $this->assertSame('0.36', $nc->iva);
        $this->assertSame('3.11', $nc->total_pagar);
    }

    /**
     * Un cliente que SÍ quiere tratar el faltante distinto de la devolución declara su
     * propia fila, y esa manda: la hermandad solo actúa cuando no hay nada declarado.
     */
    public function test_la_fila_declarada_para_el_tipo_exacto_manda_sobre_la_hermana(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $perfil = $this->perfilComoElReal($cliente);
        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::FaltanteEntrega->value,
            'codigo_externo' => 'AC04',
            'descuento_origen' => OrigenDescuentoNc::TasaPropia->value,
            'descuento_tasa' => 10,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $mani = $this->producto(1.04, 'MANI HORNEADO');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$mani, 20]]);
        $linea = $ccf->lineas()->firstOrFail();

        $faltante = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$faltante, $linea]), ['cantidad' => 6])
            ->assertRedirect();
        $this->assertSame('10.00', $faltante->refresh()->descuento_porcentaje_aplicado);

        $devolucion = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::DevolucionProducto->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$devolucion, $linea]), ['cantidad' => 6])
            ->assertRedirect();
        $this->assertSame('0.00', $devolucion->refresh()->descuento_porcentaje_aplicado);
    }

    /**
     * EL LÍMITE DE LA HERENCIA. Solo devolución y faltante comparten regla; «Otro ajuste»
     * agrupa tres modalidades internas en la pantalla, pero agruparlas para ELEGIR no es
     * evidencia de que el cliente quiera el mismo código, el mismo descuento ni las mismas
     * exigencias para las tres. Nadie lo declaró y no se inventa.
     *
     * Acá el perfil declara `descuento_posterior` con una tasa propia del 10 % y se
     * comprueba que una nota de tipo `otro` —la que crea la modalidad «Otro ajuste»— NO la
     * hereda: se queda en 0 %, que es el criterio histórico de las notas por monto.
     */
    public function test_las_modalidades_de_otro_ajuste_no_heredan_reglas_entre_si(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $perfil = $this->perfilComoElReal($cliente, exigeAlbaran: true);
        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::DescuentoPosterior->value,
            'codigo_externo' => 'AC09',
            'descuento_origen' => OrigenDescuentoNc::TasaPropia->value,
            'descuento_tasa' => 10,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(1.04), 20]]);

        $nc = $this->ncDesdeCcf($ccf, 'otro_ajuste');
        $this->assertSame(TipoNotaCredito::Otro, $nc->tipo_nota_credito);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Ajuste', 'monto' => 10.00])
            ->assertRedirect()->assertSessionHasNoErrors();

        // Ni el descuento de la hermana...
        $nc->refresh();
        $this->assertNull(app(PerfilDocumentoResolver::class)->reglaNotaCredito($nc));
        $this->assertSame('0.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('0.00', $nc->descuento_global);

        // ...ni su exigencia de albarán: generar no queda bloqueado por un AC09 ajeno.
        $albaranes = app(AlbaranNotaCreditoService::class);
        $this->assertFalse($albaranes->exigeAlbaran($nc));
        $this->assertSame([], $albaranes->datosObligatoriosFaltantes($nc));
        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    /**
     * Y en el otro sentido: una nota vieja de `descuento_posterior` —tipo que el formulario
     * ya no ofrece, pero que los documentos existentes conservan— sigue respondiendo por su
     * PROPIA fila, no por la de `otro`. La regla exacta nunca dejó de mandar.
     */
    public function test_una_modalidad_interna_vieja_conserva_su_propia_regla(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $perfil = $this->perfilComoElReal($cliente);
        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::DescuentoPosterior->value,
            'codigo_externo' => 'AC09',
            'descuento_origen' => OrigenDescuentoNc::TasaPropia->value,
            'descuento_tasa' => 10,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(1.04), 20]]);

        // Por la ruta que sí acepta la modalidad interna directa (documentos ya existentes).
        $nc = $this->ncDesdeCcf($ccf, 'otro_ajuste', TipoNotaCredito::DescuentoPosterior->value);
        $this->assertSame(TipoNotaCredito::DescuentoPosterior, $nc->tipo_nota_credito);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Ajuste', 'monto' => 10.00])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('AC09', app(PerfilDocumentoResolver::class)->reglaNotaCredito($nc->refresh())->codigo_externo);
        $this->assertSame('10.00', $nc->descuento_porcentaje_aplicado);
    }

    /** El pronto pago está solo en su modalidad: no hay de quién heredar ni a quién prestarle. */
    public function test_el_pronto_pago_no_hereda_de_ninguna_otra_modalidad(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente, exigeAlbaran: true);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(1.04), 20]]);

        $nc = $this->ncDesdeCcf($ccf, 'pronto_pago');
        $this->actingAs($this->usuario())
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Pronto pago', 'monto' => 5.00])
            ->assertRedirect();

        $nc->refresh();
        $this->assertNull(app(PerfilDocumentoResolver::class)->reglaNotaCredito($nc));
        $this->assertSame('0.00', $nc->descuento_porcentaje_aplicado);
        $this->assertFalse(app(AlbaranNotaCreditoService::class)->exigeAlbaran($nc));
    }

    /** Un cliente SIN perfil no se enteró de nada: sigue con el criterio histórico. */
    public function test_cliente_sin_perfil_conserva_el_comportamiento_historico_en_el_faltante(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();   // sin perfil
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');

        $mani = $this->producto(1.04, 'MANI HORNEADO');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$mani, 20]]);

        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        // Histórico: el faltante hereda el 5 % del CCF.
        $nc->refresh();
        $this->assertSame('5.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('0.31', $nc->descuento_global);
        $this->assertSame('6.70', $nc->total_pagar);
    }

    /**
     * La OTRA puerta de entrada: el formulario propio de «Nueva nota de crédito». La
     * corrección vive en la resolución del perfil, así que las dos puertas tienen que dar
     * el mismo número; si alguna volviera a resolver la regla por su cuenta, esto se rompe.
     */
    public function test_el_formulario_independiente_resuelve_igual_el_faltante(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');

        $tamarindo = $this->producto(0.98, 'DULCE DE TAMARINDO');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$tamarindo, 10]]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'devolucion_faltante',
                'tipo' => TipoNotaCredito::FaltanteEntrega->value,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $sala->id,
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Faltante de una unidad',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $nc = Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();
        $this->assertSame(TipoNotaCredito::FaltanteEntrega, $nc->tipo_nota_credito);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $nc->refresh();
        $this->assertSame('0.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('1.11', $nc->total_pagar);
    }

    // ============================================================ 2. el albarán

    /** El faltante ahora sabe que su albarán es un AC04: un AC02 se rechaza. */
    public function test_el_faltante_exige_el_mismo_tipo_de_albaran_que_la_devolucion(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), ['numero' => 'AC02/0207/00/3874'])
            ->assertSessionHasErrors('numero');
        $this->assertNull($nc->refresh()->albaran);

        // Y el número SUELTO se completa con el AC04 del perfil, sin escribirlo.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), ['numero' => '3874'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $albaran = $nc->refresh()->albaran;
        $this->assertSame('AC04', $albaran->tipo_codigo);
        $this->assertSame('0207', $albaran->sala_codigo);
        $this->assertSame('3874', $albaran->numero);
    }

    /**
     * Se puede guardar el albarán A MEDIAS —solo el número— y completarlo después, pero NO
     * se puede generar hasta que esté completo. El bloqueo dice QUÉ falta y lo decide el
     * servidor, no la pantalla.
     */
    public function test_albaran_incompleto_se_guarda_pero_no_deja_generar(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente, exigeAlbaran: true);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect();

        // Solo el número: se guarda.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), ['numero' => 'AC04/0207/00/3874'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $albaran = $nc->refresh()->albaran;
        $this->assertNotNull($albaran);
        $this->assertNull($albaran->fecha);
        $this->assertNull($albaran->total);

        // Pero no alcanza para emitir, y se dice qué falta.
        $faltan = app(AlbaranNotaCreditoService::class)->datosObligatoriosFaltantes($nc);
        $this->assertContains('la fecha del albarán', $faltan);
        $this->assertContains('el total del albarán', $faltan);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertSessionHasErrors('generar');
        $this->assertSame('borrador', $nc->refresh()->estado->value);

        // Completado: genera.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0207/00/3874', 'fecha' => '2026-08-27', 'total' => 1.11,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], app(AlbaranNotaCreditoService::class)->datosObligatoriosFaltantes($nc->refresh()));

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    /** La exigencia del albarán alcanza al faltante, que antes quedaba fuera por no tener regla. */
    public function test_el_albaran_obligatorio_alcanza_al_faltante(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente, exigeAlbaran: true);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect();

        $this->assertTrue(app(AlbaranNotaCreditoService::class)->exigeAlbaran($nc->refresh()));
        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertSessionHasErrors('generar');
        $this->assertSame('borrador', $nc->refresh()->estado->value);
    }

    /** Un cliente sin perfil nunca queda bloqueado por datos de albarán que no declaró. */
    public function test_la_exigencia_no_alcanza_a_un_cliente_sin_perfil(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect();

        $this->assertSame([], app(AlbaranNotaCreditoService::class)->datosObligatoriosFaltantes($nc->refresh()));
        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * La sala que viene DENTRO del número completo manda sobre la que la pantalla trajo
     * precargada: si el operador escribió el número del papel, esa es la sala del papel.
     */
    public function test_la_sala_del_numero_completo_manda_sobre_la_precargada(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0210', 'Otra sala');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0207/00/3874',
                'sala_codigo' => '0210',           // la que la pantalla trajo sugerida
                'fecha' => '2026-08-27', 'total' => 1.11,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0207', $nc->refresh()->albaran->sala_codigo);
    }

    /** El formulario del albarán muestra los CINCO datos, con lo conocido ya puesto. */
    public function test_el_formulario_muestra_y_autocompleta_tipo_y_sala(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente, exigeAlbaran: true);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('Número de albarán', false)
            ->assertSee('Tipo de albarán', false)
            ->assertSee('Sala del albarán', false)
            ->assertSee('Fecha del albarán', false)
            ->assertSee('Total del albarán', false)
            // Tipo y sala vienen puestos: son lo único que el sistema ya sabe.
            ->assertSee('name="tipo_codigo" type="text" readonly maxlength="10"', false)
            ->assertSee('value="AC04"', false)
            ->assertSee('value="0207"', false)
            // Y se dice que no se puede generar todavía, con el detalle de qué falta.
            ->assertSee('No se puede generar todavía', false);
    }

    // ============================================================ 4. comparación en vivo

    /**
     * Recalcular las líneas devuelve la comparación NC/albarán YA REPINTADA. Antes esta
     * respuesta solo traía el panel fiscal, así que la comparación se quedaba con los
     * números del último render completo y había que apretar F5 para verla cambiar.
     */
    public function test_recalcular_lineas_devuelve_la_comparacion_actualizada(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);
        $linea = $ccf->lineas()->firstOrFail();

        // Albarán del caso real: $1.11, que es lo que vale UNA unidad.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0207/00/3874', 'fecha' => '2026-08-27', 'total' => 1.11,
            ])->assertSessionHasNoErrors();

        // Ruta `acreditar.cantidad`: la IDEMPOTENTE, que es la que usa el editor
        // (data-ajax="cantidad") y la única que responde JSON. La vieja `acreditar` suma y
        // solo redirige.
        // Con 1 unidad CUADRA, y la respuesta AJAX ya lo dice.
        $cuadra = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.acreditar.cantidad', [$nc, $linea]), ['cantidad' => 1])
            ->assertOk()->assertJson(['ok' => true, 'generar_bloqueado' => false]);

        $this->assertStringContainsString('coinciden', $cuadra->json('albaran_html'));
        $this->assertStringContainsString('1.11', $cuadra->json('albaran_html'));
        $this->assertStringNotContainsString('no coinciden', $cuadra->json('albaran_html'));

        // Con 2 unidades YA NO cuadra, y tampoco hace falta recargar para verlo.
        $difiere = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.acreditar.cantidad', [$nc, $linea]), ['cantidad' => 2])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertStringContainsString('no coinciden', $difiere->json('albaran_html'));
        $this->assertStringContainsString('2.21', $difiere->json('albaran_html'));   // total de la nota
        $this->assertStringContainsString('1.10', $difiere->json('albaran_html'));   // diferencia

        // Y de vuelta a 1: el verde regresa sin F5.
        $vuelve = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.acreditar.cantidad', [$nc, $linea]), ['cantidad' => 1])
            ->assertOk();
        $this->assertStringNotContainsString('no coinciden', $vuelve->json('albaran_html'));
    }

    /** Guardar el albarán por AJAX devuelve los dos bloques repintados con datos del servidor. */
    public function test_guardar_el_albaran_por_ajax_repinta_la_comparacion(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertRedirect();

        $r = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0207/00/3874', 'fecha' => '2026-08-27', 'total' => 1.11,
            ])->assertOk()->assertJson(['ok' => true]);

        $this->assertStringContainsString('albaran-nc-panel', $r->json('albaran_html'));
        $this->assertStringContainsString('coinciden', $r->json('albaran_html'));
        $this->assertNotNull($r->json('resumen_html'));
    }

    /**
     * Un guardado que FALLA no devuelve datos nuevos: la pantalla se queda con lo último
     * confirmado. Mostrar el bloque repintado sería enseñar como cierto algo que no se
     * guardó.
     */
    public function test_un_guardado_fallido_no_devuelve_datos_nuevos(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $r = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.albaran.store', $nc), ['numero' => 'AC02/0207/00/3874'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertNull($r->json('albaran_html'));
        $this->assertNull($r->json('resumen_html'));
        $this->assertNull($nc->refresh()->albaran);
    }

    // ============================================================ 3. una sola selección de CCF

    /**
     * EL CASO REPRODUCIDO EN NAVEGADOR: se entra con `?ccf=` de un documento que el
     * buscador NO ofrece —acá, de otro ambiente— y la pantalla pintaba la tarjeta de «CCF ·
     * Aceptado» con todos los campos vacíos, sin su <option> en el select que alimenta el
     * POST. El formulario se llenaba entero, se guardaba, y volvía rebotado pidiendo el CCF:
     * eso era «se me pregunta varias veces el CCF relacionado».
     *
     * Ahora la preselección se resuelve con el MISMO universo del buscador: o el CCF se
     * puede acreditar y queda completo y posteable, o no se preselecciona y se dice por qué.
     */
    public function test_un_ccf_que_el_buscador_no_ofrece_no_queda_preseleccionado_a_medias(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ajeno = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);

        // Mismo caso que en la instalación real: aceptado de verdad, pero de un ambiente
        // distinto al que el sistema tiene configurado. Va por consulta directa porque el
        // observador —con razón— no deja mover el ambiente de un documento aceptado; el
        // CCF real nació así, no fue cambiado después.
        DB::table('dtes')->where('id', $ajeno->id)->update(['ambiente' => '01']);

        $r = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito', ['ccf' => $ajeno->id]))
            ->assertOk()
            ->assertSee('no se puede acreditar desde acá', false);

        // Ni tarjeta fantasma ni opción en el select: no figura como elegido en ningún lado.
        $datos = $r->viewData('datosNc');
        $this->assertSame('', $datos['ccfId']);
        $this->assertSame([], $datos['ccfs']);
        $this->assertStringNotContainsString('<option value="'.$ajeno->id.'"', $r->getContent());
    }

    /**
     * Y con un CCF que SÍ se puede acreditar, la selección llega completa: con sus datos
     * para pintar la tarjeta y con su <option> para que el POST la lleve.
     */
    public function test_el_ccf_preseleccionado_llega_completo_y_posteable(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);

        $r = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id]))
            ->assertOk()
            ->assertDontSee('no se puede acreditar desde acá', false);

        $datos = $r->viewData('datosNc');
        $this->assertSame((string) $ccf->id, $datos['ccfId']);
        // Los datos de la tarjeta viajan: sin esto se veía «CCF · Aceptado» y nada más.
        $this->assertArrayHasKey($ccf->id, $datos['ccfs']);
        $this->assertSame($ccf->numero_control, $datos['ccfs'][$ccf->id]['numero_control']);
        $this->assertNotNull($datos['ccfs'][$ccf->id]['total']);
        // Y su <option>, que es de donde sale el dte_relacionado_id del POST.
        $this->assertStringContainsString('<option value="'.$ccf->id.'"', $r->getContent());
    }

    /** Quitar el albarán también repinta: la comparación desaparece en el acto. */
    public function test_quitar_el_albaran_por_ajax_repinta_sin_comparacion(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilComoElReal($cliente);
        $sala = $this->sala($cliente, '0207', 'Santa Rosa de Lima');
        $ccf = $this->ccfAceptado($emisor, $cliente, $sala, [[$this->producto(0.98), 10]]);
        $nc = $this->ncDesdeCcf($ccf, 'devolucion_faltante', TipoNotaCredito::FaltanteEntrega->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0207/00/3874', 'fecha' => '2026-08-27', 'total' => 1.11,
            ])->assertSessionHasNoErrors();

        $r = $this->actingAs($this->usuario())
            ->deleteJson(route('facturacion.albaran.destroy', $nc))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertStringContainsString('Todavía no se registró el albarán', $r->json('albaran_html'));
        $this->assertNull($nc->refresh()->albaran);
    }
}
