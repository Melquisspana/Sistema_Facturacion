<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Representación gráfica / PDF PRELIMINAR del DTE. Solo lectura: no transmite, no
 * cambia estado, no guarda sello, no usa credenciales. Marca claramente los
 * documentos no transmitidos / sin sello.
 */
class DtePdfPreliminarTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        Storage::fake('local');

        ['estab' => $this->estab, 'pv' => $this->pv, 'empresa' => $this->empresa] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        Correlativo::create(['tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ccfGenerado(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();
        $dte->numero_control = 'DTE-03-M001P001-000000000000012';
        $dte->codigo_generacion = 'B58C589F-F27A-43EE-8EE8-A6E9B4C968BF';
        $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'.json';
        $dte->save();

        return $dte->refresh();
    }

    private function ccfFirmado(): Dte
    {
        $dte = $this->ccfGenerado();
        $dte->json_firmado_path = 'dte/firmados/dte-03-'.$dte->id.'.jws';
        $dte->save();

        return $dte->refresh();
    }

    /** Factura consumidor final SIN cliente (venta a consumidor final sin identificar). */
    private function facturaGenerada(): Dte
    {
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** Renderiza la plantilla del PDF a HTML (rápido, para aserciones de texto). */
    private function html(Dte $dte): string
    {
        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento.empresa', 'puntoVenta', 'dteRelacionado']);
        $emisor = $dte->establecimiento?->empresa;

        return view('facturacion.pdf', compact('dte', 'emisor'))->render();
    }

    private function ccfBorrador(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 5);

        return $dte->refresh();
    }

    // --- Emisor / numeración ---

    public function test_muestra_numeracion_oficial_si_existe(): void
    {
        $ccf = $this->ccfGenerado(); // tiene numero_control y codigo_generacion
        $html = $this->html($ccf);

        $this->assertStringContainsString('DTE-03-M001P001-000000000000012', $html);
        $this->assertStringContainsString('B58C589F-F27A-43EE-8EE8-A6E9B4C968BF', $html);
        // La numeración NO debe mostrarse como pendiente.
        $this->assertStringNotContainsString('<span class="pend">pendiente</span>', $html);
    }

    public function test_muestra_pendiente_si_no_hay_numeracion(): void
    {
        $html = $this->html($this->ccfBorrador()); // sin numero_control ni codigo

        // La numeración oficial se muestra como pendiente (estilo discreto).
        $this->assertStringContainsString('<span class="pend">pendiente</span>', $html);
    }

    public function test_resuelve_emisor_real_si_enlazado_es_placeholder(): void
    {
        // El emisor enlazado al DTE queda con NIT placeholder...
        $this->empresa->update(['nit' => '0000-000000-000-0']);
        $real = Empresa::create([
            'razon_social' => 'Dulces La Negrita REAL', 'nit' => '10132512610012',
            'nrc' => '1014765', 'ambiente' => '00', 'activo' => true,
        ]);
        $ccf = $this->ccfGenerado();

        $emisor = app(\App\Http\Controllers\Facturacion\DteController::class)->resolverEmisorParaPdf($ccf);

        $this->assertSame($real->id, $emisor->id);
        $this->assertSame('10132512610012', $emisor->nit);

        // Y la plantilla muestra el NIT real, no el placeholder.
        $html = view('facturacion.pdf', ['dte' => $ccf, 'emisor' => $emisor, 'logoSrc' => null, 'qrDataUri' => null])->render();
        $this->assertStringContainsString('10132512610012', $html);
        $this->assertStringNotContainsString('0000-000000-000-0', $html);
    }

    public function test_tabla_muestra_codigo_de_barras_y_encabezado(): void
    {
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
            'codigo' => 'CAL-777', 'codigo_barra' => '7412201700031',
        ]);
        $cliente = Cliente::factory()->contribuyente()->create();
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);

        $html = $this->html($dte->refresh());

        $this->assertStringContainsString('Código', $html);          // encabezado de columna
        $this->assertStringContainsString('7412201700031', $html);   // código de barras visible
        // Si hay código de barras, NO se muestra el código interno (solo un código a la vez).
        $this->assertStringNotContainsString('CAL-777', $html);
    }

    public function test_codigo_interno_solo_como_respaldo_sin_barras(): void
    {
        // Producto sin código de barras: en la tabla se muestra el código interno como respaldo.
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
            'codigo' => 'INT-555', 'codigo_barra' => null,
        ]);
        $cliente = Cliente::factory()->contribuyente()->create();
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);

        $html = $this->html($dte->refresh());

        $this->assertStringContainsString('INT-555', $html); // respaldo visible cuando no hay barras
    }

    // --- Contenido / marcas ---

    public function test_preliminar_sin_sello_muestra_aviso_discreto(): void
    {
        $html = $this->html($this->ccfGenerado());

        // Aviso pequeño y discreto (una sola línea), NO las dos barras grandes viejas.
        $this->assertStringContainsString('Preliminar · no válido fiscalmente', $html);
        $this->assertStringNotContainsString('DOCUMENTO NO TRANSMITIDO A HACIENDA', $html);
        $this->assertStringNotContainsString('no válido como documento fiscal', $html);
        // No debe parecer emitido oficialmente.
        $this->assertStringNotContainsString('DTE aceptado', $html);
    }

    public function test_firmado_no_aceptado_tambien_es_discreto(): void
    {
        $html = $this->html($this->ccfFirmado());

        // Firmado pero sin aceptar: mismo aviso discreto, sin la barra "FIRMADO LOCALMENTE".
        $this->assertStringContainsString('Preliminar · no válido fiscalmente', $html);
        $this->assertStringNotContainsString('FIRMADO LOCALMENTE / SIN TRANSMISIÓN', $html);
        // El estado interno se sigue viendo en la sección técnica.
        $this->assertStringContainsString('Firmado localmente: <strong>sí</strong>', $html);
    }

    public function test_aceptado_con_sello_se_ve_limpio(): void
    {
        $ccf = $this->ccfGenerado();
        $ccf->forceFill([
            'estado' => EstadoDte::Aceptado,
            'sello_recepcion' => '2026SELLOREALXYZ0000',
            'fecha_procesamiento_mh' => now(),
        ])->save();

        $html = $this->html($ccf->refresh());

        // PDF limpio para entregar/imprimir: sin avisos de preliminar / no transmitido / borrador.
        $this->assertStringNotContainsString('Preliminar · no válido fiscalmente', $html);
        $this->assertStringNotContainsString('DOCUMENTO NO TRANSMITIDO A HACIENDA', $html);
        $this->assertStringNotContainsString('no válido como documento fiscal', $html);
        // El sello de recepción sí se muestra.
        $this->assertStringContainsString('2026SELLOREALXYZ0000', $html);
    }

    public function test_rechazado_muestra_aviso_compacto(): void
    {
        $ccf = $this->ccfGenerado();
        $ccf->forceFill([
            'estado' => EstadoDte::Rechazado,
            'respuesta_mh' => ['descripcionMsg' => 'Documento rechazado de prueba'],
        ])->save();

        $html = $this->html($ccf->refresh());

        $this->assertStringContainsString('RECHAZADO POR HACIENDA', $html);
        $this->assertStringContainsString('Documento rechazado de prueba', $html);
        // Sin la barra de "no transmitido".
        $this->assertStringNotContainsString('DOCUMENTO NO TRANSMITIDO A HACIENDA', $html);
    }

    public function test_seccion_tecnica_refleja_estado_interno(): void
    {
        $html = $this->html($this->ccfFirmado());

        $this->assertStringContainsString('Estado técnico', $html);
        $this->assertStringContainsString('Firmado localmente: <strong>sí</strong>', $html);
        $this->assertStringContainsString('Sello de recepción: <strong>no</strong>', $html);
        $this->assertStringContainsString('Estado Hacienda: <strong>no transmitido</strong>', $html);
    }

    public function test_no_genera_qr_sin_datos_oficiales(): void
    {
        $html = $this->html($this->ccfGenerado());

        $this->assertStringContainsString('no se genera QR oficial', $html);
    }

    public function test_con_qr_muestra_imagen_y_no_el_box_pendiente(): void
    {
        $dte = $this->ccfFirmado();
        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento.empresa', 'puntoVenta', 'dteRelacionado']);
        $emisor = $dte->establecimiento?->empresa;

        $html = view('facturacion.pdf', [
            'dte' => $dte, 'emisor' => $emisor, 'logoSrc' => null,
            'qrDataUri' => 'data:image/png;base64,QRFAKE123',
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,QRFAKE123', $html);
        $this->assertStringNotContainsString('no se genera QR oficial', $html);
    }

    public function test_sin_logo_no_rompe_y_muestra_texto(): void
    {
        // logo_path apuntando a un archivo inexistente: no debe romper.
        config()->set('dte.pdf.logo_path', base_path('no-existe-logo.png'));
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.pdf', $ccf))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // El emisor (texto) sigue presente en la plantilla.
        $this->assertStringContainsString('Dulces La Negrita', $this->html($ccf));
    }

    public function test_no_imprime_credenciales(): void
    {
        config()->set('dte.firma.cert_password', 'CERT_PW_SECRETO');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $html = $this->html($this->ccfFirmado());

        $this->assertStringNotContainsString('CERT_PW_SECRETO', $html);
        $this->assertStringNotContainsString('TOKEN_SECRETO_X', $html);
    }

    // --- Generación PDF por ruta / roles ---

    public function test_admin_genera_pdf(): void
    {
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.pdf', $ccf))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_facturacion_descarga_pdf(): void
    {
        $ccf = $this->ccfGenerado();

        $resp = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.pdf.descargar', $ccf))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $resp->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $resp->headers->get('content-disposition'));
    }

    public function test_consulta_puede_ver_pdf(): void
    {
        $ccf = $this->ccfGenerado();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.pdf', $ccf))
            ->assertOk();
    }

    public function test_invitado_redirige_a_login(): void
    {
        $ccf = $this->ccfGenerado();

        $this->get(route('facturacion.pdf', $ccf))->assertRedirect('/login');
        $this->get(route('facturacion.pdf.descargar', $ccf))->assertRedirect('/login');
    }

    // --- No efectos secundarios ---

    public function test_pdf_no_cambia_estado_ni_guarda_sello_ni_transmite(): void
    {
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.pdf', $ccf))
            ->assertOk();

        Http::assertNothingSent();
        $ccf->refresh();
        $this->assertSame(EstadoDte::Generado, $ccf->estado);
        $this->assertNull($ccf->sello_recepcion);
    }

    // --- Factura consumidor final: claridad visual (sin cliente, IVA incluido) ---

    public function test_factura_sin_cliente_muestra_consumidor_final_sin_identificar(): void
    {
        $html = $this->html($this->facturaGenerada());

        $this->assertStringContainsString('Consumidor final', $html);
        $this->assertStringContainsString('Consumidor final sin identificar.', $html);
    }

    public function test_factura_muestra_nota_de_iva_incluido(): void
    {
        $html = $this->html($this->facturaGenerada());

        $this->assertStringContainsString('Precios con IVA incluido.', $html);
    }

    public function test_ccf_no_muestra_nota_de_iva_incluido(): void
    {
        $html = $this->html($this->ccfGenerado());

        $this->assertStringNotContainsString('Precios con IVA incluido.', $html);
    }
}
