<?php

namespace Tests\Feature\Dte;

use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\AlbaranNotaCreditoService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Reglas de descuento por PERFIL del cliente y captura del albarán de crédito.
 *
 * Las dos primeras pruebas son las DORADAS: reproducen albaranes reales de Calleja y
 * fijan la asimetría que motivó todo esto —la avería lleva el descuento del CCF y la
 * devolución no, aunque las dos cuelguen de un CCF con 5 %—. Si alguien vuelve a
 * unificar la regla, estas dos se ponen rojas.
 *
 * La tercera es igual de importante en sentido contrario: un cliente SIN perfil tiene que
 * calcular exactamente como antes de que existiera este código.
 */
class NcPerfilDescuentoAlbaranTest extends TestCase
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

    private function producto(float $precio, string $nombre = 'PRODUCTO'): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre,
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    /** Cliente con el 5 % de descuento global, como el real. */
    private function clienteConDescuento(float $pct = 5): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['descuento_global_default' => $pct]);
    }

    /**
     * Perfil del cliente con las dos modalidades mapeadas tal como quedó la regla: avería
     * al AC02 heredando el descuento del CCF, devolución al AC04 sin descuento.
     */
    private function perfilConAmbasModalidades(Cliente $cliente): ClientePerfilDocumento
    {
        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '001065',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
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

        // El resolutor memoiza por request; en un test el perfil nace después de que el
        // contenedor ya resolvió el servicio.
        app(PerfilDocumentoResolver::class)->olvidar();

        return $perfil;
    }

    /**
     * CCF aceptado con las líneas indicadas.
     *
     * @param  array<int, array{0: Producto, 1: int}>  $lineas
     */
    private function ccfAceptado(array $emisor, Cliente $cliente, array $lineas): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        foreach ($lineas as [$producto, $cantidad]) {
            $this->borradores->agregarLineaDesdeProducto($ccf, $producto, $cantidad);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    private function crearNc(Dte $ccf, string $tipo): Dte
    {
        $this->actingAs($this->usuario())
            // `origen_averia` solo lo consume la avería; las demás modalidades lo descartan.
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => $tipo, 'motivo' => 'Prueba', 'origen_averia' => 'entrega'])
            ->assertRedirect();

        return Dte::where('tipo_dte', '05')->where('tipo_nota_credito', $tipo)->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- doradas

    /**
     * DORADA · albarán AC02 real (Sonsonate II, documento 002270, 13/04/2026):
     * costos 0.90 + 0.95 + 1.04 = 2.89 bruto · descuento 0.14 · gravado 2.75 ·
     * IVA 0.36 · total 3.11.
     *
     * El 0.14 solo sale si el descuento se redondea UNA vez sobre el subtotal
     * (2.89 × 5 % = 0.1445 → 0.14). Línea a línea daría 0.15 y la nota no cuadraría
     * con el albarán del cliente.
     */
    public function test_dorada_averia_reproduce_el_albaran_ac02_real(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);

        $huevitos = $this->producto(0.90, 'HUEVITOS');
        $dulce = $this->producto(0.95, 'DULCE DE MIEL');
        $mani = $this->producto(1.04, 'MANI HORNEADO');

        $ccf = $this->ccfAceptado($emisor, $cliente, [[$huevitos, 20], [$dulce, 20], [$mani, 20]]);
        $this->assertSame('5.00', $ccf->descuento_porcentaje_aplicado);

        $nc = $this->crearNc($ccf, TipoNotaCredito::Averia->value);

        foreach ([$huevitos, $dulce, $mani] as $producto) {
            $this->actingAs($this->usuario())
                ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 1])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $nc->refresh();
        $this->assertSame('5.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('2.89', $nc->total_gravado);      // bruto (v3 del MH)
        $this->assertSame('0.14', $nc->descuento_global);   // 5 % redondeado UNA vez
        $this->assertSame('0.14', $nc->descuento_gravado);
        // Gravado NETO: lo que va en la columna GRAVADO del archivo del cliente.
        $this->assertSame('2.75', Dinero::redondear(
            Dinero::restar($nc->total_gravado, $nc->descuento_gravado), 2
        ));
        $this->assertSame('0.36', $nc->iva);
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('3.11', $nc->total_pagar);
    }

    /**
     * DORADA · albarán AC04 real (Usulután 0033, albarán 3209, 14/08/2026): 6 × 1.04 =
     * 6.24 gravado · descuento 0.00 · IVA 0.81 · total 7.05, AUNQUE el CCF relacionado
     * lleve su 5 % de siempre. El propio albarán lo declara: «Descuentos Generales ·
     * Monetario 0.00 · Porcentaje 0».
     */
    public function test_dorada_devolucion_no_aplica_descuento_aunque_el_ccf_tenga_5_por_ciento(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);

        $mani = $this->producto(1.04, 'MANI HORNEADO');
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$mani, 20]]);
        $this->assertSame('5.00', $ccf->descuento_porcentaje_aplicado);

        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);
        $lineaOriginal = $ccf->lineas()->firstOrFail();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $lineaOriginal]), ['cantidad' => 6])
            ->assertRedirect()->assertSessionHasNoErrors();

        $nc->refresh();
        $this->assertSame('0.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('6.24', $nc->total_gravado);
        $this->assertSame('0.00', $nc->descuento_global);
        $this->assertSame('0.81', $nc->iva);
        $this->assertSame('7.05', $nc->total_pagar);
    }

    /**
     * DORADA · un cliente SIN perfil calcula exactamente como antes: la avería hereda el
     * descuento del CCF y la devolución TAMBIÉN, que era el comportamiento histórico.
     * Esta prueba es la que garantiza que activar el perfil de una cadena no le mueve los
     * números a ninguna otra.
     */
    public function test_dorada_cliente_sin_perfil_conserva_el_comportamiento_historico(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();   // sin perfil declarado

        $mani = $this->producto(1.04, 'MANI HORNEADO');
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$mani, 20]]);

        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);
        $lineaOriginal = $ccf->lineas()->firstOrFail();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $lineaOriginal]), ['cantidad' => 6])
            ->assertRedirect();

        $nc->refresh();
        // Comportamiento histórico: la devolución SÍ hereda el 5 % del CCF.
        $this->assertSame('5.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('6.24', $nc->total_gravado);
        $this->assertSame('0.31', $nc->descuento_global);
        $this->assertSame('0.77', $nc->iva);
        $this->assertSame('6.70', $nc->total_pagar);
    }

    /** Una tasa propia no depende del CCF: 10 % sobre 6.24 = 0.62. */
    public function test_tasa_propia_ignora_el_descuento_del_ccf(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $perfil = $this->perfilConAmbasModalidades($cliente);
        $perfil->tiposNc()->where('tipo_nota_credito', TipoNotaCredito::DevolucionProducto->value)
            ->update(['descuento_origen' => OrigenDescuentoNc::TasaPropia->value, 'descuento_tasa' => 10]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $mani = $this->producto(1.04);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$mani, 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        $nc->refresh();
        $this->assertSame('10.00', $nc->descuento_porcentaje_aplicado);
        $this->assertSame('0.62', $nc->descuento_global);
    }

    /** El perfil desactivado no aplica: vuelve al criterio histórico. */
    public function test_perfil_desactivado_vuelve_al_comportamiento_historico(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente)->update(['activo' => false]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $mani = $this->producto(1.04);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$mani, 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        $this->assertSame('5.00', $nc->refresh()->descuento_porcentaje_aplicado);
    }

    // ---------------------------------------------------------------- albarán

    public function test_albaran_se_registra_desglosado_desde_el_numero_canonico(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0033/00/3209',
                'fecha' => '2026-08-14',
                'total' => 7.05,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $albaran = $nc->refresh()->albaran;
        $this->assertSame('AC04/0033/00/3209', $albaran->numero_canonico);
        $this->assertSame('AC04', $albaran->tipo_codigo);
        $this->assertSame('0033', $albaran->sala_codigo);
        $this->assertSame('3209', $albaran->numero);
        $this->assertSame('7.05', $albaran->total);
    }

    /** El nombre del PDF que manda el cliente trae las mismas piezas en otro orden. */
    public function test_albaran_acepta_el_nombre_del_archivo_pdf(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(0.90), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::Averia->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => '26-04-0045-00-002270-AC02-0001.PDF',
                'fecha' => '2026-04-13',
                'total' => 3.11,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $albaran = $nc->refresh()->albaran;
        $this->assertSame('AC02/0045/00/2270', $albaran->numero_canonico);
        $this->assertSame('AC02', $albaran->tipo_codigo);
        $this->assertSame('2270', $albaran->numero);
    }

    /** Capturar un AC02 en una devolución es un error de operación, y se detiene acá. */
    public function test_albaran_de_tipo_equivocado_se_rechaza(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC02/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasErrors('numero');

        $this->assertNull($nc->refresh()->albaran);
    }

    /** El mismo albarán no puede acreditarse dos veces mientras la primera NC siga viva. */
    public function test_un_albaran_no_puede_originar_dos_notas_de_credito(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 40]]);

        $primera = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $primera), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasNoErrors();

        $segunda = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $segunda), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasErrors('numero');

        $this->assertNull($segunda->refresh()->albaran);
    }

    /**
     * Un borrador eliminado libera su albarán: el operador se equivocó de nota, la borró,
     * y tiene que poder capturar el mismo albarán en la correcta.
     */
    public function test_borrador_eliminado_libera_el_albaran(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 40]]);

        $descartada = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $descartada), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasNoErrors();

        $this->actingAs($this->usuario('administrador'))
            ->delete(route('facturacion.destroy', $descartada))->assertRedirect();

        $buena = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $buena), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('AC04/0033/00/3209', $buena->refresh()->albaran->numero_canonico);
    }

    // ---------------------------------------------------------- avisos al generar

    /** Una diferencia contra el albarán avisa y exige confirmación, pero no bloquea. */
    public function test_diferencia_contra_el_albaran_exige_confirmacion_y_luego_deja_generar(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        // Albarán con un total que NO coincide con los 7.05 de la nota.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 9.99,
            ])->assertSessionHasNoErrors();

        // Sin confirmar: no genera y explica por qué.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertSessionHasErrors('generar');
        $this->assertSame('borrador', $nc->refresh()->estado->value);

        // Los valores fiscales NO se tocaron para forzar el cuadre.
        $this->assertSame('7.05', $nc->total_pagar);

        // Confirmando: genera.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc), ['confirmar_avisos_nc' => 1])
            ->assertRedirect();
        $this->assertSame('generado', $nc->refresh()->estado->value);
        $this->assertSame('7.05', $nc->total_pagar);
    }

    /** Si el cliente exige albarán, generar sin él se frena (eso sí es un dato faltante). */
    public function test_albaran_obligatorio_impide_generar_sin_capturarlo(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente)->update(['exige_albaran_en_nc' => true]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertSessionHasErrors('generar');
        $this->assertSame('borrador', $nc->refresh()->estado->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasNoErrors();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    /** El pronto pago no nace de un albarán: la exigencia no lo alcanza. */
    public function test_albaran_obligatorio_no_alcanza_a_modalidades_sin_mapear(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente)->update(['exige_albaran_en_nc' => true]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::ProntoPago->value);
        $this->actingAs($this->usuario())
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Pronto pago', 'monto' => 5.00])
            ->assertRedirect();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    /** Una NC ya generada no puede cambiar su albarán. */
    public function test_albaran_no_se_puede_cambiar_despues_de_generar(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();
        $this->actingAs($this->usuario())
            ->post(route('facturacion.albaran.store', $nc), [
                'numero' => 'AC04/0033/00/3209', 'fecha' => '2026-08-14', 'total' => 7.05,
            ])->assertSessionHasNoErrors();

        app(DteGeneracionService::class)->generar($nc->refresh());

        $this->expectException(DocumentoInmutableException::class);
        app(AlbaranNotaCreditoService::class)->registrar($nc->refresh(), [
            'numero' => 'AC04/0033/00/9999', 'fecha' => '2026-08-15', 'total' => 1.00,
        ]);
    }

    public function test_comparacion_marca_cuadre_exacto(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteConDescuento();
        $this->perfilConAmbasModalidades($cliente);
        $ccf = $this->ccfAceptado($emisor, $cliente, [[$this->producto(1.04), 20]]);
        $nc = $this->crearNc($ccf, TipoNotaCredito::DevolucionProducto->value);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar', [$nc, $ccf->lineas()->firstOrFail()]), ['cantidad' => 6])
            ->assertRedirect();

        DteAlbaran::create([
            'dte_id' => $nc->id, 'numero_canonico' => 'AC04/0033/00/3209', 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0033', 'numero' => '3209', 'fecha' => '2026-08-14', 'total' => 7.05,
        ]);

        $comparacion = app(AlbaranNotaCreditoService::class)->comparacion($nc->refresh());
        $this->assertSame('7.05', $comparacion['total_nc']);
        $this->assertSame('7.05', $comparacion['total_albaran']);
        $this->assertSame('0.00', $comparacion['diferencia']);
        $this->assertTrue($comparacion['cuadra']);
        $this->assertSame([], app(AlbaranNotaCreditoService::class)->avisos($nc));
    }
}
