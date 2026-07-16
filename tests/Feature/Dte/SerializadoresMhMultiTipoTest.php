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
            ['cat' => '014', 'codigo' => '99', 'valor' => 'Otra'], // Bolsa se mapea a 99 (Otra)
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
            actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '05', direccion: 'Calle X',
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
        // Regresión: el distrito del emisor no debe perderse al serializar (bug DTE #125).
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        // Regresión: ventaGravada/totalGravada deben ser BRUTOS (con IVA incluido), no la
        // base neta interna (bug real DTE #126, rechazo MH código 003 "cálculo de total
        // por ítem erróneo"). lineaGravada()/resumenCcf(): neto 100.00 + IVA 13.00 = 113.00.
        $this->assertSame(113.0, $oficial['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame(113.0, $oficial['resumen']['totalGravada']);
        $this->assertSame(113.0, $oficial['resumen']['subTotalVentas']);
        $this->assertSame(113.0, $oficial['resumen']['subTotal']);
        $this->assertSame(113.0, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(113.0, $oficial['resumen']['totalPagar']); // NO se suma el IVA otra vez
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_factura_reproduce_caso_real_dte126_rechazado_por_mh(): void
    {
        // Caso real: DTE #126, 10 × 1.05 (IVA incluido), sin descuento. MH rechazó
        // con código 003 "cuerpoDocumento.item.1: El calculo de total por item es erroneo"
        // porque ventaGravada llevaba la base neta (9.29) en vez del bruto (10.50).
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'CANILLITAS', cantidad: '10', precioUnitario: '1.05',
            totalLinea: '10.50', tipoItem: 1, codigo: '79873', unidadMedida: '59',
            ventaGravada: '9.29', iva: '1.21',
        );
        $resumen = new ResumenDteData(
            totalGravado: '9.29', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '1.21', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '10.50',
            montoTotalOperacion: '10.50', totalPagar: '10.50', totalLetras: 'DIEZ 50/100 DÓLARES',
            condicionOperacion: 1, porcentajeDescuento: '0.00',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('01', 2), emisor: $this->emisor(), resumen: $resumen,
            lineas: [$linea], receptor: null, apendice: [],
        );

        $oficial = app(SerializadorFacturaMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::Factura);

        $this->assertSame(1.05, $oficial['cuerpoDocumento'][0]['precioUni']);
        $this->assertSame(10.0, $oficial['cuerpoDocumento'][0]['cantidad']);
        $this->assertSame(0.0, $oficial['cuerpoDocumento'][0]['montoDescu']);
        $this->assertSame(10.5, $oficial['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame(1.21, $oficial['cuerpoDocumento'][0]['ivaItem']);
        $this->assertSame(10.5, $oficial['resumen']['totalGravada']);
        $this->assertSame(10.5, $oficial['resumen']['subTotalVentas']);
        $this->assertSame(10.5, $oficial['resumen']['subTotal']);
        $this->assertSame(1.21, $oficial['resumen']['totalIva']);
        $this->assertSame(10.5, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(10.5, $oficial['resumen']['totalPagar']);
        $this->assertNull($oficial['receptor']);
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_factura_con_descuento_de_linea_ventaGravada_bruta_menos_descuento(): void
    {
        // 1 × 11.30 (IVA incluido) − descuento 1.13 = 10.17 bruto; base 9.00, IVA 1.17.
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto con descuento', cantidad: '1', precioUnitario: '11.30',
            totalLinea: '10.17', tipoItem: 1, codigo: 'P-2', unidadMedida: '59',
            descuento: '1.13', ventaGravada: '9.00', iva: '1.17',
        );
        $resumen = new ResumenDteData(
            totalGravado: '9.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
            descuentoGravado: '1.13', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '1.13',
            iva: '1.17', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '10.17',
            montoTotalOperacion: '10.17', totalPagar: '10.17', totalLetras: 'DIEZ 17/100 DÓLARES',
            condicionOperacion: 1, porcentajeDescuento: '10.00',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('01', 2), emisor: $this->emisor(), resumen: $resumen,
            lineas: [$linea], receptor: null, apendice: [],
        );

        $oficial = app(SerializadorFacturaMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::Factura);

        // ventaGravada = cantidad × precioUni − montoDescu (bruto, con IVA incluido).
        $this->assertSame(10.17, $oficial['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame(1.13, $oficial['cuerpoDocumento'][0]['montoDescu']);
        $this->assertSame(1.17, $oficial['cuerpoDocumento'][0]['ivaItem']);
        $this->assertSame(10.17, $oficial['resumen']['totalGravada']);
        $this->assertSame(10.17, $oficial['resumen']['subTotalVentas']);
        $this->assertSame(1.13, $oficial['resumen']['totalDescu']);
        $this->assertSame(9.04, $oficial['resumen']['subTotal']); // 10.17 − 1.13
        // El IVA no se suma dos veces: montoTotalOperacion/totalPagar quedan en el bruto ya calculado.
        $this->assertSame(10.17, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(10.17, $oficial['resumen']['totalPagar']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_factura_serializa_y_valida_con_receptor_identificado(): void
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '13', numDocumento: '000000000', nrc: null, nombre: 'Cliente Identificado',
            actividadEconomica: '10730', departamento: '06', municipio: '17', distrito: '0617',
            direccion: 'Colonia Escalón', telefono: '22001111', correo: 'cliente@ejemplo.sv',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('01', 2), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$this->lineaGravada()], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorFacturaMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::Factura);

        // Regresión: ni el distrito del emisor ni el del receptor deben perderse.
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        $this->assertSame('0617', $oficial['receptor']['direccion']['distrito']);
        // Mismos criterios de montos brutos que con receptor null (lineaGravada()/resumenCcf()).
        $this->assertSame(113.0, $oficial['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame(113.0, $oficial['resumen']['totalGravada']);
        $this->assertSame(113.0, $oficial['resumen']['totalPagar']);
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
     * Cubre el riesgo detectado en la auditoría FEX: recintoFiscal/tipoRegimen/regimen/
     * codIncoterms/descIncoterms/tipoItemExpor se enviaban SIEMPRE como null aunque el
     * schema real del MH los exige. Con los datos ya resueltos (por-DTE, cargados desde
     * CAT-027/028/031/033), el serializador debe dejar de mandarlos null y el JSON debe
     * seguir validando contra fe-fex-v3.json.
     */
    public function test_exportacion_serializa_recinto_regimen_e_incoterms_no_null(): void
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXT-001', nombre: 'Importadora CA',
            actividadEconomica: '10730', pais: 'GT', direccion: 'Ciudad de Guatemala', tipoPersona: '1',
        );
        $emisor = new EmisorDteData(
            nit: '06140000000011', nrc: '111111', nombre: 'Dulces La Negrita',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
            actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '05', direccion: 'Calle X',
            telefono: '22000000', correo: 'e@negrita.sv', tipoEstablecimiento: '02',
            tipoItemExpor: 1, recintoFiscal: '01', tipoRegimen: 'EX-1', regimen: '1000.000',
        );
        $resumen = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '100.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '100.00',
            montoTotalOperacion: '100.00', totalPagar: '100.00', totalLetras: 'CIEN 00/100',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
            codIncoterms: '09', descIncoterms: 'FOB-Libre a bordo',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $emisor, resumen: $resumen,
            lineas: [$this->lineaExportacion()], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::FacturaExportacion);

        // Regresión: el distrito del emisor no debe perderse al serializar (bug FEX #128,
        // SerializadorExportacionMh hardcodeaba 'distrito' => '' en vez de leer $e->distrito).
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        $this->assertNotNull($oficial['emisor']['direccion']['distrito']);
        $this->assertNotSame('', $oficial['emisor']['direccion']['distrito']);
        $this->assertSame(1, $oficial['emisor']['tipoItemExpor']);
        $this->assertSame('01', $oficial['emisor']['recintoFiscal']);
        $this->assertSame('EX-1', $oficial['emisor']['tipoRegimen']);
        $this->assertSame('1000.000', $oficial['emisor']['regimen']);
        $this->assertSame('09', $oficial['resumen']['codIncoterms']);
        $this->assertSame('FOB-Libre a bordo', $oficial['resumen']['descIncoterms']);
        $this->assertSame('GT', $oficial['receptor']['codPais']);
        $this->assertSame('GUATEMALA', $oficial['receptor']['nombrePais']);
        $this->assertSame(100.0, $oficial['resumen']['totalGravada']); // venta de exportación
        $this->assertSame(100.0, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(100.0, $oficial['resumen']['totalPagar']);
        // Regresión: el tributo de exportación (C3, IVA 0%) no debe faltar (bug FEX #129,
        // rechazo real MH código 005 "[cuerpoDocumento.1.tributos] DEBE PROPORCIONAR UN VALOR").
        $this->assertSame(['C3'], $oficial['cuerpoDocumento'][0]['tributos']);
        $this->assertCount(1, $oficial['resumen']['tributos']);
        $this->assertSame('C3', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertSame('Impuesto al Valor Agregado (exportaciones) 0%', $oficial['resumen']['tributos'][0]['descripcion']);
        $this->assertSame(0.0, $oficial['resumen']['tributos'][0]['valor']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    /**
     * Reconstrucción EN MEMORIA equivalente al caso real de la FEX #129 rechazada por MH
     * (código 005, "[cuerpoDocumento.1.tributos] DEBE PROPORCIONAR UN VALOR"): mismos datos
     * que #128 (10 × 1.05, cliente Piloto Exportación USA, sin descuento). No toca el DTE
     * #129 real ni su JSON/JWS en disco: solo verifica que, con el fix del tributo C3, un
     * documento con los mismos datos serializaría con el arreglo de tributos correcto.
     */
    public function test_exportacion_reproduce_caso_real_dte129_rechazado_por_mh_codigo_005(): void
    {
        CatalogoMh::insert([
            ['cat' => '019', 'codigo' => '46900', 'valor' => 'Venta al por mayor de una variedad de artículos sin especialización'],
            ['cat' => '020', 'codigo' => 'US', 'valor' => 'ESTADOS UNIDOS'],
        ]);

        $emisor = new EmisorDteData(
            nit: '10132512610012', nrc: '1014765', nombre: 'Elsa Fidelina Hernández Cañas',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
            actividadEconomica: '10730', departamento: '08', municipio: '23', distrito: '05',
            direccion: 'km 28 1/2 carretera al aeropuerto frente a maquila internacional olocuilta la paz',
            telefono: '71276473', correo: 'dulceslanegrita@yahoo.com', tipoEstablecimiento: '02',
            tipoItemExpor: 1, recintoFiscal: '01', tipoRegimen: 'EX-1', regimen: '1000.000',
        );
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXP-PILOTO-001', nombre: 'Cliente Piloto Exportación USA',
            actividadEconomica: '46900', pais: 'US', direccion: 'Miami, Florida, United States',
            tipoPersona: 'juridica',
        );
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'CANILLITAS', cantidad: '10', precioUnitario: '1.05',
            totalLinea: '10.50', tipoItem: 1, codigo: '79873', unidadMedida: '59',
            ventaExportacion: '10.50',
        );
        $resumen = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '10.50',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '10.50',
            montoTotalOperacion: '10.50', totalPagar: '10.50', totalLetras: 'DIEZ 50/100 DÓLARES',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
            codIncoterms: '09', descIncoterms: 'FOB-Libre a bordo',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $emisor, resumen: $resumen,
            lineas: [$linea], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::FacturaExportacion);

        $this->assertSame(['C3'], $oficial['cuerpoDocumento'][0]['tributos']);
        $this->assertCount(1, $oficial['resumen']['tributos']);
        $this->assertSame('C3', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertSame('Impuesto al Valor Agregado (exportaciones) 0%', $oficial['resumen']['tributos'][0]['descripcion']);
        $this->assertSame(0.0, $oficial['resumen']['tributos'][0]['valor']);
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        $this->assertSame(1, $oficial['emisor']['tipoItemExpor']);
        $this->assertSame('01', $oficial['emisor']['recintoFiscal']);
        $this->assertSame('EX-1', $oficial['emisor']['tipoRegimen']);
        $this->assertSame('1000.000', $oficial['emisor']['regimen']);
        $this->assertSame('09', $oficial['resumen']['codIncoterms']);
        $this->assertSame('FOB-Libre a bordo', $oficial['resumen']['descIncoterms']);
        $this->assertSame(10.5, $oficial['resumen']['totalGravada']); // total_exportacion
        $this->assertSame(10.5, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(10.5, $oficial['resumen']['totalPagar']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    /**
     * Reconstrucción EN MEMORIA equivalente al caso real de la FEX #128 (10 × 1.05,
     * cliente Piloto Exportación USA, sin descuento). No toca el DTE #128 real ni su
     * JSON en disco: solo verifica que, con el fix del distrito, un documento con los
     * mismos datos serializaría correctamente.
     */
    public function test_exportacion_reproduce_caso_real_dte128_con_distrito_correcto(): void
    {
        CatalogoMh::insert([
            ['cat' => '019', 'codigo' => '46900', 'valor' => 'Venta al por mayor de una variedad de artículos sin especialización'],
            ['cat' => '020', 'codigo' => 'US', 'valor' => 'ESTADOS UNIDOS'],
        ]);

        $emisor = new EmisorDteData(
            nit: '10132512610012', nrc: '1014765', nombre: 'Elsa Fidelina Hernández Cañas',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
            actividadEconomica: '10730', departamento: '08', municipio: '23', distrito: '05',
            direccion: 'km 28 1/2 carretera al aeropuerto frente a maquila internacional olocuilta la paz',
            telefono: '71276473', correo: 'dulceslanegrita@yahoo.com', tipoEstablecimiento: '02',
            tipoItemExpor: 1, recintoFiscal: '01', tipoRegimen: 'EX-1', regimen: '1000.000',
        );
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXP-PILOTO-001', nombre: 'Cliente Piloto Exportación USA',
            actividadEconomica: '46900', pais: 'US', direccion: 'Miami, Florida, United States',
            tipoPersona: 'juridica',
        );
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'CANILLITAS', cantidad: '10', precioUnitario: '1.05',
            totalLinea: '10.50', tipoItem: 1, codigo: '79873', unidadMedida: '59',
            ventaExportacion: '10.50',
        );
        $resumen = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '10.50',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '10.50',
            montoTotalOperacion: '10.50', totalPagar: '10.50', totalLetras: 'DIEZ 50/100 DÓLARES',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
            codIncoterms: '09', descIncoterms: 'FOB-Libre a bordo',
        );
        $salida = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $emisor, resumen: $resumen,
            lineas: [$linea], receptor: $receptor, apendice: [],
        );

        $oficial = app(SerializadorExportacionMh::class)->serializar($salida);
        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::FacturaExportacion);

        $this->assertSame('11', $oficial['identificacion']['tipoDte']);
        $this->assertSame('00', $oficial['identificacion']['ambiente']);
        $this->assertSame('05', $oficial['emisor']['direccion']['distrito']);
        $this->assertSame(1, $oficial['emisor']['tipoItemExpor']);
        $this->assertSame('01', $oficial['emisor']['recintoFiscal']);
        $this->assertSame('EX-1', $oficial['emisor']['tipoRegimen']);
        $this->assertSame('1000.000', $oficial['emisor']['regimen']);
        $this->assertSame('09', $oficial['resumen']['codIncoterms']);
        $this->assertSame('FOB-Libre a bordo', $oficial['resumen']['descIncoterms']);
        $this->assertSame('US', $oficial['receptor']['codPais']);
        $this->assertSame(10.5, $oficial['resumen']['totalGravada']); // total_exportacion
        $this->assertSame(10.5, $oficial['resumen']['montoTotalOperacion']);
        $this->assertSame(10.5, $oficial['resumen']['totalPagar']);
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

    /**
     * Una línea cuya unidad es "Bolsa" (que en CAT-014 se mapea al código 99 "Otra")
     * debe serializar `uniMedida = 99` y validar contra el schema, tanto en NC (05)
     * como en FEX (11). Ambos serializadores usan el helper compartido uniMedida().
     */
    public function test_linea_unidad_bolsa_codigo_99_valida_en_nc_y_fex(): void
    {
        // NC (05) con línea en unidad Bolsa (código CAT-014 99).
        $lineaNcBolsa = new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto en bolsa', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '113.00', tipoItem: 1, codigo: 'P-1', unidadMedida: '99',
            ventaGravada: '100.00', iva: '13.00',
        );
        $relacionado = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2, numeroDocumento: '7C73BB9A-86FA-4904-B0F2-546A41EA59E0', fechaEmision: '2026-06-16',
        );
        $nc = new DteSalidaData(
            identificacion: $this->ident('05', 3), emisor: $this->emisor(), resumen: $this->resumenCcf(),
            lineas: [$lineaNcBolsa], receptor: $this->receptorContribuyente(),
            apendice: [], documentoRelacionado: [$relacionado],
        );
        $ncOficial = app(SerializadorNotaCreditoMh::class)->serializar($nc);
        $ncRes = app(DteSchemaValidator::class)->validar($ncOficial, TipoDte::NotaCredito);
        $this->assertSame(99, $ncOficial['cuerpoDocumento'][0]['uniMedida']);
        $this->assertTrue($ncRes['valido'], 'NC errores: '.implode(' | ', $ncRes['errores']));

        // FEX (11) con línea en unidad Bolsa.
        $lineaFexBolsa = new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto exportado en bolsa', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '100.00', tipoItem: 1, codigo: 'P-1', unidadMedida: '99',
            ventaExportacion: '100.00',
        );
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXT-001', nombre: 'Importadora CA',
            actividadEconomica: '10730', pais: 'GT', direccion: 'Ciudad de Guatemala', tipoPersona: 'juridica',
        );
        $resumenFex = new ResumenDteData(
            totalGravado: '0.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '100.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '0.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '100.00',
            montoTotalOperacion: '100.00', totalPagar: '100.00', totalLetras: 'CIEN 00/100',
            flete: '0.00', seguro: '0.00', condicionOperacion: 1, porcentajeDescuento: '0.00',
        );
        $fex = new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(), resumen: $resumenFex,
            lineas: [$lineaFexBolsa], receptor: $receptor, apendice: [],
        );
        $fexOficial = app(SerializadorExportacionMh::class)->serializar($fex);
        $fexRes = app(DteSchemaValidator::class)->validar($fexOficial, TipoDte::FacturaExportacion);
        $this->assertSame(99, $fexOficial['cuerpoDocumento'][0]['uniMedida']);
        $this->assertTrue($fexRes['valido'], 'FEX errores: '.implode(' | ', $fexRes['errores']));
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
