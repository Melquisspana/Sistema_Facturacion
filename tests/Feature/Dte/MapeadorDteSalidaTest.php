<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteNoMapeableException;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Pais;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\UnidadMedida;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapeadorDteSalidaTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private MapeadorDteSalida $mapeador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte(); // incluye catalogos_mh (CAT-014...) que exige el serializador
        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);
        $this->mapeador = app(MapeadorDteSalida::class);
    }

    private function emisor(): array
    {
        $actividad = ActividadEconomica::first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();
        // MunicipioSeeder (liviano) no trae el código CAT-013 (se completa al importar el
        // catálogo oficial); lo respaldamos con un código real ya importado en catalogos_mh
        // para que la Factura de Exportación (que sí exige este campo) pueda generarse en pruebas.
        if ($muni && blank($muni->codigo)) {
            $muni->update(['codigo' => \App\Models\CatalogoMh::where('cat', '013')->value('codigo') ?? '10']);
        }

        $empresa = Empresa::create([
            'razon_social' => 'Dulces La Negrita', 'nombre_comercial' => 'La Negrita',
            // Sin guiones: el schema de la Factura 01 limita el NIT del emisor a 14 chars.
            'nit' => '06140000000000', 'nrc' => '111111-1',
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'direccion' => 'Calle Principal', 'telefono' => '2200-0000', 'correo' => 'fact@negrita.sv',
            'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz',
            'tipo_establecimiento' => '01', 'activo' => true,
        ]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('empresa', 'estab', 'pv', 'actividad', 'depto', 'muni');
    }

    private function clienteContribuyente(array $emisor, array $override = []): Cliente
    {
        return Cliente::factory()->contribuyente()->create(array_merge([
            'actividad_economica_id' => $emisor['actividad']->id,
            'departamento_id' => $emisor['depto']->id,
            'municipio_id' => $emisor['muni']->id,
        ], $override));
    }

    private function producto(string $nombre = 'Dulce de leche'): Producto
    {
        $unidad = UnidadMedida::whereNotNull('codigo')->first();

        return Producto::factory()->create([
            'nombre' => $nombre, 'codigo' => 'DUL-1', 'unidad_medida_id' => $unidad->id,
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    private function generarBorrador(TipoDte $tipo, array $emisor, ?Cliente $cliente, array $extra = [], ?Producto $producto = null): Dte
    {
        $base = [
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $base += [
                'tipo_item_expor' => 1,
                'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1',
                'regimen' => '1000.000',
                'cod_incoterms' => '09',
            ];
        }
        $dte = $this->borradores->crearBorrador(array_merge($base, $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $producto ?? $this->producto(), cantidad: 10);
        $this->generacion->generar($dte);

        return $dte->refresh();
    }

    public function test_mapea_ccf_generado_completo(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor, ['nombre' => 'Calleja S.A. de C.V.']);
        $ccf = $this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $cliente);

        $salida = $this->mapeador->mapear($ccf);

        $this->assertSame(4, $salida->identificacion->version); // CCF v4 (config dte.json.versiones)
        $this->assertSame('03', $salida->identificacion->tipoDte);
        $this->assertSame('06140000000000', $salida->emisor->nit);
        $this->assertSame('M001', $salida->emisor->codigoEstablecimiento);
        $this->assertSame('P001', $salida->emisor->codigoPuntoVenta);
        $this->assertSame('Calleja S.A. de C.V.', $salida->receptor->nombre);
        $this->assertCount(1, $salida->lineas);
        $this->assertSame('100.00', $salida->resumen->totalGravado);
        $this->assertSame('113.00', $salida->resumen->totalPagar);
        $this->assertSame('CIENTO TRECE 00/100 DÓLARES', $salida->resumen->totalLetras);
    }

    public function test_mapea_factura_01_sin_receptor(): void
    {
        $emisor = $this->emisor();
        $factura = $this->generarBorrador(TipoDte::Factura, $emisor, null);

        $salida = $this->mapeador->mapear($factura);

        $this->assertSame('01', $salida->identificacion->tipoDte);
        $this->assertNull($salida->receptor);
    }

    public function test_mapea_exportacion_con_receptor_extranjero_flete_y_seguro(): void
    {
        $emisor = $this->emisor();
        $pais = Pais::where('codigo', '!=', 'SV')->first();
        $cliente = Cliente::factory()->exportacion()->create(['pais_id' => $pais->id]);
        $fex = $this->generarBorrador(TipoDte::FacturaExportacion, $emisor, $cliente, ['flete' => 5, 'seguro' => 2]);

        $salida = $this->mapeador->mapear($fex);

        $this->assertSame('11', $salida->identificacion->tipoDte);
        $this->assertSame($pais->codigo, $salida->receptor->pais);
        $this->assertSame('5.00', $salida->resumen->flete);
        $this->assertSame('2.00', $salida->resumen->seguro);
        $this->assertSame('100.00', $salida->resumen->totalExportacion);
        $this->assertSame('100.00', $salida->lineas[0]->ventaExportacion);
        $this->assertSame('0.00', $salida->resumen->iva); // exportación: IVA 0
        $this->assertSame(1, $salida->emisor->tipoItemExpor);
        $this->assertSame('01', $salida->emisor->recintoFiscal);
        $this->assertSame('EX-1', $salida->emisor->tipoRegimen);
        $this->assertSame('1000.000', $salida->emisor->regimen);
        $this->assertSame('09', $salida->resumen->codIncoterms);
        $this->assertSame('FOB-Libre a bordo', $salida->resumen->descIncoterms);
    }

    public function test_mapea_nota_credito_con_documento_relacionado(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor);
        // La NC exige un CCF ACEPTADO por Hacienda (con codigoGeneracion oficial).
        $ccf = $this->aceptarCcf($this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $cliente));

        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 4);
        $this->generacion->generar($nc);

        $salida = $this->mapeador->mapear($nc->refresh());

        $this->assertCount(1, $salida->documentoRelacionado);
        $this->assertSame('03', $salida->documentoRelacionado[0]->tipoDocumento);
        $this->assertSame(2, $salida->documentoRelacionado[0]->tipoGeneracion);
        // El relacionado referencia el codigoGeneracion OFICIAL del CCF aceptado.
        $this->assertSame($ccf->codigo_generacion, $salida->documentoRelacionado[0]->numeroDocumento);
    }

    public function test_nota_credito_averia_con_productos_manuales_genera_json_v3_valido(): void
    {
        // El serializador valida la unidad contra CAT-014 (no contra la tabla UnidadMedida).
        \App\Models\CatalogoMh::firstOrCreate(['cat' => '014', 'codigo' => '59'], ['valor' => 'Unidad']);
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor);
        // CCF realmente aceptado por el MH (aceptarCcf setea sello real + fecha_procesamiento_mh).
        $ccf = $this->aceptarCcf($this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $cliente)); // gravado 100

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        // Producto MANUAL que NO está en el CCF (avería lo permite); total dentro del saldo.
        $unidad = UnidadMedida::whereNotNull('codigo')->first();
        $manual = Producto::factory()->create([
            'nombre' => 'Producto avería libre', 'codigo' => 'AVE-9', 'unidad_medida_id' => $unidad->id,
            'precio_unitario' => 2, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $manual, 3); // 2 × 3 = 6 ≤ 100
        $this->generacion->generar($nc->refresh());

        // JSON oficial serializado (estructura v3).
        $oficial = app(SerializadorNotaCreditoMh::class)->serializar($this->mapeador->mapear($nc->refresh()));

        // Estructura v3.
        $this->assertSame(3, $oficial['identificacion']['version']);
        $this->assertSame(1, $oficial['resumen']['condicionOperacion']);
        $this->assertArrayNotHasKey('totalIva', $oficial['resumen']);
        $this->assertArrayNotHasKey('totalIva', $oficial['cuerpoDocumento'][0]);
        // IVA solo en resumen.tributos[20].
        $this->assertSame('20', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertSame(round(6 * 0.13, 2), $oficial['resumen']['tributos'][0]['valor']); // 0.78
        // documentoRelacionado y cuerpoDocumento[].numeroDocumento usan el codigoGeneracion del CCF real.
        $this->assertSame($ccf->codigo_generacion, $oficial['documentoRelacionado'][0]['numeroDocumento']);
        $this->assertSame($ccf->codigo_generacion, $oficial['cuerpoDocumento'][0]['numeroDocumento']);
        // La línea de avería es el producto manual (no estaba en el CCF).
        $this->assertSame('AVE-9', $oficial['cuerpoDocumento'][0]['codigo']);
    }

    public function test_usa_snapshots_de_linea_no_producto_actual(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor);
        $producto = $this->producto('Original');
        $ccf = $this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $cliente, producto: $producto);

        // El producto cambia DESPUÉS de generar.
        $producto->update(['nombre' => 'Cambiado', 'precio_unitario' => 999]);

        $salida = $this->mapeador->mapear($ccf->refresh());

        $this->assertSame('Original', $salida->lineas[0]->descripcion);   // snapshot
        $this->assertSame('10.000000', $salida->lineas[0]->precioUnitario); // snapshot
    }

    public function test_agrega_apendice_de_orden_de_compra(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor, ['requiere_orden_compra' => true, 'etiqueta_orden_compra' => 'Orden Selectos']);
        $ccf = $this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $cliente, ['numero_orden_compra' => 'OC-9']);

        $salida = $this->mapeador->mapear($ccf);

        $this->assertCount(1, $salida->apendice);
        $this->assertSame('ordenCompra', $salida->apendice[0]->campo);
        $this->assertSame('Orden Selectos', $salida->apendice[0]->etiqueta);
        $this->assertSame('OC-9', $salida->apendice[0]->valor);
    }

    public function test_no_asigna_codigo_generacion_ni_numero_control_si_estan_vacios(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->generarBorrador(TipoDte::CreditoFiscal, $emisor, $this->clienteContribuyente($emisor));

        // La generación ahora asigna numeración oficial (JSON atómico); para probar que el
        // MAPEADOR no la inventa, se vacía explícitamente (documento viejo sin numeración).
        $ccf->numero_control = null;
        $ccf->codigo_generacion = null;
        $ccf->saveQuietly();

        $salida = $this->mapeador->mapear($ccf->refresh());

        $this->assertNull($salida->identificacion->codigoGeneracion);
        $this->assertNull($salida->identificacion->numeroControl);
    }

    public function test_falla_si_validacion_detecta_problemas(): void
    {
        $emisor = $this->emisor();
        // Borrador (no generado) → la validación previa falla.
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->clienteContribuyente($emisor),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        $this->expectException(DteNoMapeableException::class);
        $this->mapeador->mapear($ccf);
    }
}
