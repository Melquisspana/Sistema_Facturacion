<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\DocumentoRelacionadoDteData;
use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\EmisorDteData;
use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ReceptorDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\CatalogoMh;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorExportacionMh;
use App\Services\Dte\Serializadores\SerializadorFacturaMh;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerializadoresMhMultiTipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogoMh::insert([
            ['cat' => '014', 'codigo' => '59', 'valor' => 'Unidad'],
            ['cat' => '015', 'codigo' => '20', 'valor' => 'Impuesto al Valor Agregado 13%'],
            ['cat' => '019', 'codigo' => '10730', 'valor' => 'Elaboración de cacao, chocolate y confitería'],
            ['cat' => '020', 'codigo' => 'GT', 'valor' => 'GUATEMALA'],
        ]);
    }

    private function ident(string $tipo, int $version): IdentificacionDteData
    {
        return new IdentificacionDteData(
            version: $version, ambiente: '00', tipoDte: $tipo, fechaEmision: '2026-06-17', horaEmision: '10:00:00',
            numeroControl: 'DTE-'.$tipo.'-M001P001-000000000000001',
            codigoGeneracion: 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D',
            tipoModelo: 1, tipoOperacion: 1, tipoContingencia: null, motivoContingencia: null, tipoMoneda: 'USD',
        );
    }

    private function emisor(): EmisorDteData
    {
        return new EmisorDteData(
            nit: '06140000000011', nrc: '111111', nombre: 'Dulces La Negrita',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
            actividadEconomica: '10730', departamento: '06', municipio: '14', direccion: 'Calle X',
            telefono: '22000000', correo: 'e@negrita.sv', tipoEstablecimiento: '02',
        );
    }

    private function lineaGravada(): LineaDteData
    {
        return new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '113.00', tipoItem: 1, codigo: 'P-1', unidadMedida: '59',
            ventaGravada: '100.00', iva: '13.00',
        );
    }

    private function lineaExportacion(): LineaDteData
    {
        return new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto exportado', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '100.00', tipoItem: 1, codigo: 'P-1', unidadMedida: '59',
            ventaExportacion: '100.00',
        );
    }

    private function resumenCcf(): ResumenDteData
    {
        return new ResumenDteData(
            totalGravado: '100.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '13.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '113.00',
            montoTotalOperacion: '113.00', totalPagar: '113.00', totalLetras: 'CIENTO TRECE 00/100',
            condicionOperacion: 1, porcentajeDescuento: '0.00',
        );
    }

    // --- Factura 01 ---

    public function test_factura_serializa_y_valida_consumidor_final(): void
    {
        $salida = new DteSalidaData(
            identificacion: $this->ident('01', 2), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: null, apendice: [],
        );

        $oficial = app(SerializadorFacturaMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::Factura);

        $this->assertNull($oficial['receptor']);                 // consumidor final
        $this->assertSame(13.0, $oficial['resumen']['totalIva']); // IVA incluido
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    // --- Exportación 11 ---

    public function test_exportacion_serializa_y_valida_con_pais(): void
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXT-001', nombre: 'Importadora CA',
            actividadEconomica: '10730', pais: 'GT', direccion: 'Ciudad de Guatemala', tipoPersona: '1',
        );
        $resumen = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '100.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '100.00',
            montoTotalOperacion: '100.00', totalPagar: '100.00', totalLetras: 'CIEN 00/100',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(), resumen: $resumen,
            lineas: [$this->lineaExportacion()], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::FacturaExportacion);

        $this->assertSame('GT', $oficial['receptor']['codPais']);
        $this->assertSame('GUATEMALA', $oficial['receptor']['nombrePais']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    /**
     * El receptor de una FEX trae el tipo de persona como VALOR del enum ('juridica'
     * /'natural'), no como número. Debe serializarse al código del MH (2 jurídica /
     * 1 natural), no castearse con (int) —que daría 0—.
     */
    public function test_exportacion_tipo_persona_juridica_se_serializa_como_2(): void
    {
        // País/actividad sembrados en el catálogo de prueba (GT/10730), como el test hermano;
        // el sujeto de la prueba es tipoPersona, que debe salir 2 aunque llegue como 'juridica'.
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXP-PILOTO-001', nombre: 'Cliente Piloto Exportación',
            actividadEconomica: '10730', pais: 'GT', direccion: 'Ciudad de Guatemala',
            tipoPersona: 'juridica',
        );
        $resumen = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '100.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '100.00',
            montoTotalOperacion: '100.00', totalPagar: '100.00', totalLetras: 'CIEN 00/100',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(), resumen: $resumen,
            lineas: [$this->lineaExportacion()], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::FacturaExportacion);

        $this->assertSame(2, $oficial['receptor']['tipoPersona']); // jurídica, NO 0
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_exportacion_tipo_persona_natural_se_serializa_como_1(): void
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXT-002', nombre: 'Comprador Natural',
            actividadEconomica: '46900', pais: 'US', direccion: 'Miami', tipoPersona: 'natural',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(),
            resumen: new ResumenDteData(
                totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '100.00',
                descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
                iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '100.00',
                montoTotalOperacion: '100.00', totalPagar: '100.00', totalLetras: 'CIEN 00/100',
                flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
            ),
            lineas: [$this->lineaExportacion()], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);

        $this->assertSame(1, $oficial['receptor']['tipoPersona']);
    }

    public function test_exportacion_sin_pais_falla_claro(): void
    {
        $receptor = new ReceptorDteData(tipoDocumento: '37', numDocumento: 'EXT-001', nombre: 'X', actividadEconomica: '10730', pais: null);
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaExportacion()], receptor: $receptor, apendice: [],
        );

        try {
            app(SerializadorExportacionMh::class)->serializar($salida);
            $this->fail('Debió lanzar DteNoSerializableException.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('país', implode(' ', $e->problemas));
        }
    }

    // --- Nota de crédito 05 ---

    private function receptorContribuyente(): ReceptorDteData
    {
        return new ReceptorDteData(
            tipoDocumento: '36', numDocumento: '06140000000011', nrc: '1937', nombre: 'Calleja',
            actividadEconomica: '10730', departamento: '06', municipio: '14', direccion: 'Av Cliente',
        );
    }

    public function test_nota_credito_serializa_y_valida_con_documento_relacionado(): void
    {
        $uuid = '7C73BB9A-86FA-4904-B0F2-546A41EA59E0'; // codigoGeneracion oficial (UUID v4) del CCF
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2, numeroDocumento: $uuid, fechaEmision: '2026-06-16',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $this->receptorContribuyente(),
            apendice: [], documentoRelacionado: [$relacionado],
        );

        $oficial = app(SerializadorNotaCreditoMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::NotaCredito);

        $this->assertSame(3, $oficial['identificacion']['version']); // NC estructura v3
        $this->assertCount(1, $oficial['documentoRelacionado']);
        $this->assertSame($uuid, $oficial['cuerpoDocumento'][0]['numeroDocumento']);
        // v3: el IVA va SOLO en resumen.tributos; NO hay totalIva por línea ni en el resumen.
        $this->assertArrayNotHasKey('totalIva', $oficial['cuerpoDocumento'][0]);
        $this->assertArrayNotHasKey('totalIva', $oficial['resumen']);
        $this->assertSame('20', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_nota_credito_v3_usa_nit_directo_en_receptor(): void
    {
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2, numeroDocumento: '7C73BB9A-86FA-4904-B0F2-546A41EA59E0', fechaEmision: '2026-06-16',
        );
        $base = fn (ReceptorDteData $r) => new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $r, apendice: [], documentoRelacionado: [$relacionado],
        );

        // La NC v3 usa `nit` directo (solo dígitos), como el CCF; no lleva tipoDocumento/numDocumento.
        $nit = $base(new ReceptorDteData(tipoDocumento: '36', numDocumento: '0614-010101-001-1', nrc: '1937', nombre: 'Calleja', actividadEconomica: '10730', departamento: '06', municipio: '14', direccion: 'Av'));
        $aNit = app(SerializadorNotaCreditoMh::class)->serializar($nit);
        $this->assertSame('06140101010011', $aNit['receptor']['nit']);
        $this->assertArrayNotHasKey('numDocumento', $aNit['receptor']);
        $this->assertArrayNotHasKey('tipoDocumento', $aNit['receptor']);
    }

    public function test_nota_credito_v3_no_emite_distrito_en_direccion(): void
    {
        $emisor = new EmisorDteData(
            nit: '06140000000011', nrc: '111111', nombre: 'Dulces La Negrita',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001', actividadEconomica: '10730',
            departamento: '06', municipio: '14', distrito: '0614', direccion: 'Calle X',
            telefono: '22000000', correo: 'e@negrita.sv', tipoEstablecimiento: '02',
        );
        $receptor = new ReceptorDteData(
            tipoDocumento: '36', numDocumento: '06140000000011', nrc: '1937', nombre: 'Calleja',
            actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '0617', direccion: 'Av',
        );
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2, numeroDocumento: '7C73BB9A-86FA-4904-B0F2-546A41EA59E0', fechaEmision: '2026-06-16',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $emisor, resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $receptor, apendice: [], documentoRelacionado: [$relacionado],
        );

        $oficial = app(SerializadorNotaCreditoMh::class)->serializar($salida);

        // La NC v3 NO lleva distrito en la dirección (solo departamento/municipio/complemento).
        $this->assertArrayNotHasKey('distrito', $oficial['emisor']['direccion']);
        $this->assertArrayNotHasKey('distrito', $oficial['receptor']['direccion']);
        $this->assertSame(['departamento', 'municipio', 'complemento'], array_keys($oficial['emisor']['direccion']));
    }

    public function test_nota_credito_v3_con_descuento_global_cuadra(): void
    {
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2, numeroDocumento: '7C73BB9A-86FA-4904-B0F2-546A41EA59E0', fechaEmision: '2026-06-16',
        );
        // CCF con descuento global del 5%: gravado bruto 100, descuGravada 5, neto 95, IVA 95×0.13=12.35.
        $resumen = new ResumenDteData(
            totalGravado: '100.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
            descuentoGravado: '5.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '5.00',
            iva: '12.35', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '107.35',
            montoTotalOperacion: '107.35', totalPagar: '107.35', totalLetras: 'CIENTO SIETE 35/100',
            condicionOperacion: 1, porcentajeDescuento: '5.00',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $resumen,
            lineas: [$this->lineaGravada()], receptor: $this->receptorContribuyente(),
            apendice: [], documentoRelacionado: [$relacionado],
        );

        $oficial = app(SerializadorNotaCreditoMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::NotaCredito);
        $r = $oficial['resumen'];

        // Estructura v3: bruto + descuento global; IVA sobre subTotal en resumen.tributos.
        $this->assertSame(100.0, $oficial['cuerpoDocumento'][0]['ventaGravada']); // BRUTO
        $this->assertSame(0.0, $oficial['cuerpoDocumento'][0]['montoDescu']);
        $this->assertSame(100.0, $r['totalGravada']);                 // bruto
        $this->assertSame(5.0, $r['descuGravada']);                   // descuento global
        $this->assertSame(5.0, $r['totalDescu']);
        $this->assertSame(95.0, $r['subTotal']);                      // bruto − descuento
        $this->assertSame('20', $r['tributos'][0]['codigo']);
        $this->assertSame(12.35, $r['tributos'][0]['valor']);         // IVA sobre subTotal (95 × 0.13)
        $this->assertSame(107.35, $r['montoTotalOperacion']);         // subTotal + IVA
        $this->assertArrayNotHasKey('totalIva', $r);
        $this->assertArrayNotHasKey('totalIva', $oficial['cuerpoDocumento'][0]);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_nota_credito_sin_documento_relacionado_falla_claro(): void
    {
        $salida = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $this->receptorContribuyente(),
            apendice: [], documentoRelacionado: [],
        );

        try {
            app(SerializadorNotaCreditoMh::class)->serializar($salida);
            $this->fail('Debió lanzar DteNoSerializableException.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('documento relacionado', implode(' ', $e->problemas));
        }
    }

    public function test_nota_credito_con_original_sin_numeracion_oficial_falla_claro(): void
    {
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2,
            numeroDocumento: 'INT-03-M001P001-000000000000004', // número interno, NO codigoGeneracion oficial
            fechaEmision: '2026-06-16',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $this->receptorContribuyente(),
            apendice: [], documentoRelacionado: [$relacionado],
        );

        try {
            app(SerializadorNotaCreditoMh::class)->serializar($salida);
            $this->fail('Debió lanzar DteNoSerializableException.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('código de generación oficial', implode(' ', $e->problemas));
        }
    }
}
