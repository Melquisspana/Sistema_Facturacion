<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

/**
 * La representación gráfica de un documento EMITIDO muestra los datos fiscales del
 * momento de emisión: los del JSON generado, no los del cliente o la empresa editados
 * después (Normativa 2.0, regla 11.3 y 11.3.1, y Anexo I, 4: «exportar los datos o
 * valores de los campos JSON directamente a la Representación Gráfica»). Issue #42, H3.
 *
 * Un borrador todavía no tiene JSON: muestra los datos actuales.
 * Si el JSON coincide con los datos actuales, el PDF sale exactamente igual que antes.
 */
class DatosFiscalesEmisionPdfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private DteBorradorService $borradores;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
                'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    private function borrador(TipoDte $tipo, ?Cliente $cliente, array $extra = []): Dte
    {
        $base = [
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente?->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $base += ['tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09'];
        }
        $dte = $this->borradores->crearBorrador(array_merge($base, $extra));
        $producto = Producto::factory()->create(['nombre' => 'Dulce de leche', 'precio_unitario' => 100, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    private function emitido(TipoDte $tipo, ?Cliente $cliente, array $extra = []): Dte
    {
        $dte = $this->borrador($tipo, $cliente, $extra);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function clienteOriginal(): Cliente
    {
        return Cliente::factory()->contribuyente()->create([
            'nombre' => 'Comercial Original, S.A. de C.V.',
            'num_documento' => '06140101010011',
            'nrc' => '2222220',
            'telefono' => '22221111',
            'correo' => 'original@cliente.example.com',
            'direccion' => 'Calle Original 1',
        ]);
    }

    /** Edita en la base sin pasar por reglas de negocio: es lo que el PDF NO debe reflejar. */
    private function editarCliente(Cliente $cliente): void
    {
        $cliente->forceFill([
            'nombre' => 'Comercial RENOMBRADO',
            'num_documento' => '06149999999999',
            'nrc' => '9999990',
            'telefono' => '29999999',
            'correo' => 'nuevo@cliente.example.com',
            'direccion' => 'Calle Nueva 99',
        ])->save();
    }

    private function fresco(Dte $dte): Dte
    {
        return Dte::query()->findOrFail($dte->id);
    }

    /** El pie técnico dice si hay JSON; no es parte de la representación fiscal. */
    private function sinPieTecnico(string $html): string
    {
        return (string) preg_replace('/JSON generado: <strong>[^<]*<\/strong>/u', '', $html);
    }

    public function test_ccf_emitido_muestra_el_receptor_del_json_aunque_el_cliente_cambie(): void
    {
        $cliente = $this->clienteOriginal();
        $ccf = $this->emitido(TipoDte::CreditoFiscal, $cliente);

        $this->editarCliente($cliente);
        $html = $this->htmlDelPdf($this->fresco($ccf));

        foreach (['Comercial Original, S.A. de C.V.', '2222220', 'original@cliente.example.com', 'Calle Original 1'] as $original) {
            $this->assertStringContainsString($original, $html, "Falta el dato de emisión «{$original}».");
        }
        $this->assertStringContainsString('06140101010011', preg_replace('/\D+/', '', $html) ?? '');
        foreach (['RENOMBRADO', '9999990', 'nuevo@cliente.example.com', 'Calle Nueva 99', '06149999999999'] as $nuevo) {
            $this->assertStringNotContainsString($nuevo, $html, "El PDF muestra el dato editado «{$nuevo}».");
        }
    }

    public function test_emitido_muestra_el_emisor_del_json_aunque_cambie_la_empresa(): void
    {
        $ccf = $this->emitido(TipoDte::CreditoFiscal, $this->clienteOriginal());
        $empresa = Empresa::query()->findOrFail($this->estab->empresa_id);
        $razonOriginal = $empresa->razon_social;
        $empresa->forceFill(['razon_social' => 'EMPRESA RENOMBRADA', 'telefono' => '2888-8888', 'direccion' => 'Dirección Mudada'])->save();

        $html = $this->htmlDelPdf($this->fresco($ccf));

        $this->assertStringContainsString($razonOriginal, $html);
        $this->assertStringNotContainsString('EMPRESA RENOMBRADA', $html);
        $this->assertStringNotContainsString('Dirección Mudada', $html);
        $this->assertStringNotContainsString('2888-8888', $html);
    }

    public function test_sala_emitida_muestra_su_direccion_del_json_aunque_la_sala_cambie(): void
    {
        $cliente = $this->clienteOriginal();
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id, 'nombre' => 'Sala Original', 'direccion' => 'Local 5 Original',
            'correo' => 'sala@cliente.example.com',
        ]);
        $ccf = $this->emitido(TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sala->id]);

        $sala->forceFill(['nombre' => 'Sala RENOMBRADA', 'direccion' => 'Local 9 Nuevo', 'correo' => 'salanueva@cliente.example.com'])->save();
        $html = $this->htmlDelPdf($this->fresco($ccf));

        $this->assertStringContainsString('Sala Original', $html);
        $this->assertStringContainsString('Local 5 Original', $html);
        $this->assertStringContainsString('sala@cliente.example.com', $html);
        $this->assertStringNotContainsString('RENOMBRADA', $html);
        $this->assertStringNotContainsString('Local 9 Nuevo', $html);
        $this->assertStringNotContainsString('salanueva@cliente.example.com', $html);
    }

    public function test_fex_emitida_muestra_el_receptor_del_json(): void
    {
        $cliente = Cliente::factory()->exportacion()->create([
            'nombre' => 'Importadora Original', 'correo' => 'compras@importadora.example.com', 'telefono' => '50255551111',
        ]);
        $fex = $this->emitido(TipoDte::FacturaExportacion, $cliente);

        $cliente->forceFill(['nombre' => 'Importadora RENOMBRADA', 'correo' => 'otro@importadora.example.com', 'telefono' => '50299990000'])->save();
        $html = $this->htmlDelPdf($this->fresco($fex));

        $this->assertStringContainsString('Importadora Original', $html);
        $this->assertStringContainsString('compras@importadora.example.com', $html);
        $this->assertStringNotContainsString('RENOMBRADA', $html);
        $this->assertStringNotContainsString('otro@importadora.example.com', $html);
        $this->assertStringNotContainsString('50299990000', $html);
    }

    public function test_borrador_sin_json_muestra_los_datos_actuales(): void
    {
        $cliente = $this->clienteOriginal();
        $borrador = $this->borrador(TipoDte::CreditoFiscal, $cliente);

        $this->editarCliente($cliente);
        $html = $this->htmlDelPdf($this->fresco($borrador));

        $this->assertStringContainsString('Comercial RENOMBRADO', $html);
        $this->assertStringContainsString('nuevo@cliente.example.com', $html);
    }

    /**
     * Documento histórico cuyo JSON coincide con los datos actuales: el PDF debe salir
     * IGUAL que con el criterio anterior (leer los modelos). Se compara la misma
     * representación con y sin el archivo JSON disponible.
     */
    public function test_si_el_json_coincide_el_pdf_sale_igual_que_antes(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'num_documento' => '0614-010101-001-1', // con guiones, como se guarda en el cliente
            'correo' => 'general@cliente.example.com',
        ]);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'correo' => 'sala@cliente.example.com']);
        $casos = [
            $this->emitido(TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sala->id]),
            $this->emitido(TipoDte::CreditoFiscal, $cliente),
            $this->emitido(TipoDte::Factura, null),
            $this->emitido(TipoDte::FacturaExportacion, Cliente::factory()->exportacion()->create()),
            // Sin dirección: el JSON lleva el relleno «—», que no debe aparecer en el PDF.
            $this->emitido(TipoDte::FacturaExportacion, Cliente::factory()->exportacion()->create(['direccion' => null])),
        ];

        foreach ($casos as $dte) {
            $conJson = $this->sinPieTecnico($this->htmlDelPdf($this->fresco($dte)));

            Storage::disk('local')->delete($dte->json_generado_path);
            $sinJson = $this->sinPieTecnico($this->htmlDelPdf($this->fresco($dte)));

            $this->assertSame($sinJson, $conJson, 'El PDF de '.$dte->tipo_dte->label().' cambió aunque el JSON coincide con los datos actuales.');
        }
    }

    /** El evento de invalidación identifica al receptor tal como se emitió el documento. */
    public function test_invalidacion_usa_el_receptor_del_json_aunque_el_cliente_cambie(): void
    {
        $cliente = $this->clienteOriginal();
        $ccf = $this->aceptarCcf($this->emitido(TipoDte::CreditoFiscal, $cliente));

        $this->editarCliente($cliente);
        $evento = app(SerializadorInvalidacionMh::class)->serializar($this->fresco($ccf), new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: 'Responsable de Prueba', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Solicitante de Prueba', tipoDocSolicita: '36', numDocSolicita: '06145555551015',
        ));

        $this->assertSame('36', $evento['documento']['tipoDocumento']);
        $this->assertSame('06140101010011', $evento['documento']['numDocumento']);
        $this->assertSame('Comercial Original, S.A. de C.V.', $evento['documento']['nombre']);
        $this->assertSame('22221111', $evento['documento']['telefono']);
        $this->assertSame('original@cliente.example.com', $evento['documento']['correo']);
        $this->assertTrue(app(DteSchemaValidator::class)->validarInvalidacion($evento)['valido']);
    }

    /**
     * Una FE emitida antes de normalizar el documento del receptor transmitió el NIT con
     * guiones. La invalidación lo sigue mandando solo con dígitos, como antes.
     */
    public function test_invalidacion_de_fe_vieja_con_nit_con_guiones_manda_solo_digitos(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['num_documento' => '0614-010101-001-1', 'correo' => 'fe@cliente.example.com']);
        $fe = $this->aceptarCcf($this->emitido(TipoDte::Factura, $cliente));

        // El JSON guardado de esa época trae el NIT tal como estaba en el cliente.
        $json = json_decode((string) Storage::disk('local')->get($fe->json_generado_path), true);
        $json['receptor']['numDocumento'] = '0614-010101-001-1';
        Storage::disk('local')->put($fe->json_generado_path, json_encode($json));

        $evento = app(SerializadorInvalidacionMh::class)->serializar($this->fresco($fe), new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: 'Responsable de Prueba', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Solicitante de Prueba', tipoDocSolicita: '36', numDocSolicita: '06145555551015',
        ));

        $this->assertSame('36', $evento['documento']['tipoDocumento']);
        $this->assertSame('06140101010011', $evento['documento']['numDocumento']);
        $this->assertTrue(app(DteSchemaValidator::class)->validarInvalidacion($evento)['valido']);
    }
}
