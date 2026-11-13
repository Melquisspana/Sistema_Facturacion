<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\RepresentacionDteRecibido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * «Ver» una compra: la factura armada en pantalla desde el JSON guardado, sin
 * generar archivos. Y la lista sin las columnas de adjuntos ni de «DTE válido».
 * Todos los datos son ficticios.
 */
class ComprasVerDteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Candado: nada de esta pantalla puede tocar el buzón.
        $this->app->instance(MailboxClient::class, new BuzonFalso(disponible: false));
    }

    private function usuario(string $rol = 'contabilidad'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @param  array<string, string>  $archivos  nombre => contenido */
    private function compra(array $archivos, array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        $rutas = [];
        foreach ($archivos as $nombre => $contenido) {
            $ruta = 'documentos-recibidos/v'.$n.'/'.$nombre;
            Storage::disk('local')->put($ruta, $contenido);
            $rutas[] = $ruta;
        }

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => 'ver'.$n,
            'emisor_nombre' => 'PROVEEDOR FICTICIO '.$n,
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-F0000001-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'estado' => 'pendiente',
            'clasificacion' => 'dte_valido',
            'total' => 100.00,
            'tiene_pdf' => isset($archivos['factura.pdf']),
            'tiene_json' => isset($archivos['dte.json']),
            'fecha_correo' => now(),
            'metadata_json' => ['archivos' => $rutas],
        ]);
    }

    /** CCF ficticio con la estructura oficial y el sello afuera (estilo ContaPortable). */
    private function ccf(string $descripcion = 'Caja de dulce de prueba'): array
    {
        return [
            'selloRecibido' => '2026SELLOFICTICIO0000000000000000001',
            'dteJson' => [
                'identificacion' => [
                    'version' => 3, 'ambiente' => '01', 'tipoDte' => '03',
                    'numeroControl' => 'DTE-03-F0000001-000000000000123',
                    'codigoGeneracion' => '11111111-2222-3333-4444-555555555555',
                    'fecEmi' => '2026-07-10', 'horEmi' => '09:15:00',
                ],
                'emisor' => [
                    'nit' => '12345678901234', 'nrc' => '123456', 'nombre' => 'DISTRIBUIDORA FICTICIA S.A. DE C.V.',
                    'nombreComercial' => 'LA FICTICIA', 'codActividad' => '46900', 'descActividad' => 'Venta al por mayor de prueba',
                    'direccion' => ['departamento' => '06', 'municipio' => '14', 'complemento' => 'Calle Inventada 123, San Salvador'],
                    'telefono' => '22220000', 'correo' => 'ventas@ficticia.test',
                ],
                'receptor' => [
                    'nit' => '98765432109876', 'nrc' => '654321', 'nombre' => 'COMPRADOR DE PRUEBA S.A.',
                    'descActividad' => 'Fabricación de prueba',
                    'direccion' => ['complemento' => 'Colonia Imaginaria, Santa Ana'],
                    'correo' => 'compras@prueba.test',
                ],
                'cuerpoDocumento' => [
                    ['numItem' => 1, 'cantidad' => 2, 'descripcion' => $descripcion, 'precioUni' => 50.0,
                        'montoDescu' => 0, 'ventaNoSuj' => 0, 'ventaExenta' => 0, 'ventaGravada' => 100.0],
                ],
                'resumen' => [
                    'totalNoSuj' => 0, 'totalExenta' => 0, 'totalGravada' => 100.0, 'subTotalVentas' => 100.0,
                    'totalDescu' => 0, 'tributos' => [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 13.0]],
                    'subTotal' => 100.0, 'ivaPerci1' => 0, 'ivaRete1' => 1.0, 'reteRenta' => 0,
                    'montoTotalOperacion' => 113.0, 'totalNoGravado' => 0, 'totalPagar' => 112.0,
                    'totalLetras' => 'CIENTO DOCE 00/100 DÓLARES', 'condicionOperacion' => 1,
                ],
                'apendice' => [['campo' => 'orden', 'etiqueta' => 'Orden de compra', 'valor' => 'OC-0001']],
            ],
        ];
    }

    public function test_ver_un_ccf_muestra_emisor_receptor_lineas_totales_codigo_y_sello(): void
    {
        $doc = $this->compra(['factura.pdf' => '%PDF-1.4', 'dte.json' => json_encode($this->ccf())]);
        $archivosAntes = Storage::disk('local')->allFiles();

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertOk()
            ->assertSee('Comprobante de crédito fiscal')
            ->assertSee('DTE-03-F0000001-000000000000123')
            ->assertSee('11111111-2222-3333-4444-555555555555')
            ->assertSee('2026SELLOFICTICIO0000000000000000001')
            ->assertSee('10/07/2026 09:15:00')
            ->assertSee('Contado')
            // Emisor
            ->assertSee('DISTRIBUIDORA FICTICIA S.A. DE C.V.')
            ->assertSee('NIT 12345678901234')
            ->assertSee('NRC 123456')
            ->assertSee('Venta al por mayor de prueba')
            ->assertSee('Calle Inventada 123, San Salvador')
            // Receptor
            ->assertSee('COMPRADOR DE PRUEBA S.A.')
            ->assertSee('NIT 98765432109876')
            // Línea
            ->assertSeeInOrder(['Cantidad', 'Descripción', 'Precio unitario', 'No sujeto', 'Exento', 'Gravado'])
            ->assertSeeInOrder(['Caja de dulce de prueba', '$50.00', '$0.00', '$0.00', '$100.00'])
            // Totales
            ->assertSeeInOrder(['Suma de ventas', '$100.00', 'Impuesto al Valor Agregado 13%', '$13.00',
                'Subtotal', 'IVA retenido', '$1.00', 'Monto total de la operación', '$113.00', 'Total a pagar', '$112.00'])
            ->assertDontSee('IVA percibido')
            ->assertSee('CIENTO DOCE 00/100 DÓLARES')
            ->assertSee('OC-0001')
            ->assertSee('Imprimir')
            ->assertSee('Abrir PDF');

        // Solo lee: ni archivos nuevos ni cambios en la compra.
        $this->assertSame($archivosAntes, Storage::disk('local')->allFiles());
        $this->assertSame('pendiente', $doc->refresh()->estado);
    }

    public function test_un_json_con_campos_faltantes_o_raros_no_rompe(): void
    {
        $raro = [
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => ['no', 'es', 'texto']],
            'emisor' => ['nombre' => ['objeto' => 'raro'], 'direccion' => 'Dirección en una sola línea'],
            'receptor' => 'no es un objeto',
            'cuerpoDocumento' => [
                ['descripcion' => 'Línea sin montos', 'cantidad' => 'abc', 'precioUni' => ['x']],
                'una línea que no es objeto',
            ],
            'resumen' => ['tributos' => 'tampoco', 'totalPagar' => 'no numérico'],
            'apendice' => ['suelto'],
        ];
        $doc = $this->compra(['dte.json' => json_encode($raro)]);

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertOk()
            ->assertSee('Línea sin montos')
            ->assertSee('Dirección en una sola línea')
            ->assertSee('—')
            ->assertSee('Total a pagar');
    }

    public function test_json_en_latin1_se_lee_igual_que_al_sincronizar(): void
    {
        $json = mb_convert_encoding(json_encode($this->ccf('Piñata de prueba'), JSON_UNESCAPED_UNICODE), 'ISO-8859-1', 'UTF-8');
        $doc = $this->compra(['dte.json' => $json]);

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertOk()
            ->assertSee('Piñata de prueba');
    }

    public function test_sin_json_muestra_el_aviso_y_ofrece_el_pdf(): void
    {
        $doc = $this->compra(['factura.pdf' => '%PDF-1.4'], ['clasificacion' => 'falta_adjunto']);

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertOk()
            ->assertSee('Esta compra no tiene guardado el JSON del DTE')
            ->assertSee('Abrir PDF')
            ->assertDontSee('Imprimir');
    }

    public function test_json_danado_o_que_no_es_dte_muestra_aviso(): void
    {
        $danado = $this->compra(['dte.json' => '{esto no es json']);
        $otro = $this->compra(['dte.json' => json_encode(['banco' => 'estado de cuenta'])]);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('documentos-recibidos.ver', $danado))
            ->assertOk()->assertSee('no se pudo leer');
        $this->actingAs($usuario)->get(route('documentos-recibidos.ver', $otro))
            ->assertOk()->assertSee('no tiene la forma de un DTE');
    }

    public function test_sin_permiso_de_compras_da_403(): void
    {
        $doc = $this->compra(['dte.json' => json_encode($this->ccf())]);

        $this->actingAs($this->usuario('produccion'))
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertForbidden();
    }

    public function test_el_html_del_json_se_escapa(): void
    {
        $doc = $this->compra(['dte.json' => json_encode($this->ccf('<script>alert("x")</script>'))]);

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.ver', $doc))
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false);
    }

    public function test_nota_de_credito_muestra_el_documento_relacionado_y_su_total(): void
    {
        $nc = $this->ccf();
        $nc['dteJson']['identificacion']['tipoDte'] = '05';
        $nc['dteJson']['documentoRelacionado'] = [['tipoDocumento' => '03', 'tipoGeneracion' => 2,
            'numeroDocumento' => '99999999-8888-7777-6666-555555555555', 'fechaEmision' => '2026-07-01']];
        unset($nc['dteJson']['resumen']['totalPagar'], $nc['dteJson']['resumen']['totalNoGravado']);

        $dte = app(RepresentacionDteRecibido::class)->desdeJson($nc);

        $this->assertSame('Nota de crédito', $dte['titulo']);
        $this->assertSame([['tipo' => 'Comprobante de crédito fiscal', 'numero' => '99999999-8888-7777-6666-555555555555', 'fecha' => '01/07/2026']], $dte['relacionados']);
        $final = collect($dte['totales'])->firstWhere('destacado', true);
        $this->assertSame(['etiqueta' => 'Monto total de la operación', 'valor' => '$113.00', 'destacado' => true], $final);
        $this->assertNull(collect($dte['totales'])->firstWhere('etiqueta', 'Total a pagar'));
    }

    public function test_factura_y_comprobante_de_retencion_cambian_lo_que_corresponde(): void
    {
        $rep = app(RepresentacionDteRecibido::class);

        $factura = $rep->desdeJson([
            'identificacion' => ['tipoDte' => '01'],
            'receptor' => ['tipoDocumento' => '13', 'numDocumento' => '00000000-0', 'nombre' => 'CLIENTE FICTICIO'],
            'cuerpoDocumento' => [['cantidad' => 1.5, 'descripcion' => 'Bolsa', 'precioUni' => 1.13, 'ventaGravada' => 1.695]],
            'resumen' => ['totalGravada' => 1.7, 'subTotalVentas' => 1.7, 'subTotal' => 1.7, 'totalIva' => 0.2,
                'montoTotalOperacion' => 1.7, 'totalPagar' => 1.7],
        ]);
        $this->assertSame('Factura', $factura['titulo']);
        $this->assertSame('DUI 00000000-0', $factura['receptor']['documento']);
        $this->assertSame('1.5', $factura['lineas'][0]['cantidad']);
        $this->assertContains(['etiqueta' => 'IVA incluido', 'valor' => '$0.20', 'destacado' => false], $factura['totales']);

        $retencion = $rep->desdeJson([
            'identificacion' => ['tipoDte' => '07'],
            'cuerpoDocumento' => [['numItem' => 1, 'tipoDte' => '03', 'numDocumento' => 'DOC-1', 'fechaEmision' => '2026-07-02',
                'montoSujetoGrav' => 200, 'ivaRetenido' => 2, 'descripcion' => 'Retención 1%']],
            'resumen' => ['totalSujetoRetencion' => 200, 'totalIVAretenido' => 2, 'totalIVAretenidoLetras' => 'DOS 00/100'],
        ]);
        $this->assertSame(['item', 'documento', 'fecha', 'descripcion', 'sujeto', 'retenido'], array_column($retencion['columnas'], 'clave'));
        $this->assertSame('Comprobante de crédito fiscal DOC-1', $retencion['lineas'][0]['documento']);
        $this->assertSame(['sujeto' => '$200.00', 'retenido' => '$2.00'], $retencion['sumas']);
        $this->assertSame('DOS 00/100', $retencion['total_letras']);
    }

    // --- Lista ---

    public function test_la_lista_ya_no_tiene_columnas_de_archivos_ni_de_dte_valido_y_ofrece_ver(): void
    {
        $doc = $this->compra(['factura.pdf' => '%PDF-1.4', 'dte.json' => '{}']);

        $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.index', ['rango' => 'todos']))
            ->assertOk()
            ->assertDontSee('>Adjuntos</th>', false)
            ->assertDontSee('>Clasificación</th>', false)
            ->assertDontSee('DTE válido</span>', false)
            ->assertSee(route('documentos-recibidos.ver', $doc))
            // Los archivos guardados siguen a mano, en el menú de la fila.
            ->assertSee('Abrir PDF')
            ->assertSee('Abrir JSON');
    }

    public function test_la_etiqueta_roja_sale_solo_en_el_dte_no_valido(): void
    {
        $this->compra(['dte.json' => '{}'], ['emisor_nombre' => 'PROVEEDOR VALIDO']);
        $this->compra(['factura.pdf' => '%PDF-1.4'], ['emisor_nombre' => 'PROVEEDOR SIN JSON', 'clasificacion' => 'falta_adjunto']);

        $html = $this->actingAs($this->usuario())
            ->get(route('documentos-recibidos.index', ['rango' => 'todos']))
            ->assertOk()
            ->assertSee('Falta JSON')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'title="No es un DTE válido'));
    }

    /** Jefatura (13/11/2026): proveedor, fechas, total y el PDF; sin código, número de control completo ni JSON. */
    public function test_jefatura_ve_la_compra_sin_codigo_ni_json(): void
    {
        $doc = $this->compra(['factura.pdf' => '%PDF-1.4', 'dte.json' => json_encode($this->ccf())]);
        $jefatura = $this->usuario('jefatura');

        $this->actingAs($jefatura)->get(route('documentos-recibidos.index', ['rango' => 'todos']))->assertOk()
            ->assertSee($doc->emisor_nombre)
            ->assertSee('$100.00')
            ->assertSee('…'.substr($doc->numero_control, -4))
            ->assertDontSee($doc->numero_control)
            ->assertDontSee('name="codigo_generacion"', false)
            ->assertDontSee('Abrir JSON')
            ->assertSee('Abrir PDF');
        $this->actingAs($jefatura)->get(route('documentos-recibidos.ver', $doc))->assertOk()
            ->assertSee('DISTRIBUIDORA FICTICIA S.A. DE C.V.')
            ->assertSee('$112.00')
            ->assertDontSee('DTE-03-F0000001-000000000000123')
            ->assertDontSee('11111111-2222-3333-4444-555555555555')
            ->assertDontSee('2026SELLOFICTICIO0000000000000000001');
        $this->actingAs($jefatura)->get(route('documentos-recibidos.archivo', [$doc, 'json']))->assertForbidden();
        $this->actingAs($jefatura)->get(route('documentos-recibidos.archivo', [$doc, 'pdf']))->assertOk();

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->get(route('documentos-recibidos.index', ['rango' => 'todos']))->assertOk()
            ->assertSee($doc->numero_control)
            ->assertSee('name="codigo_generacion"', false)
            ->assertSee('Abrir JSON');
        $this->actingAs($admin)->get(route('documentos-recibidos.archivo', [$doc, 'json']))->assertOk();
    }
}
