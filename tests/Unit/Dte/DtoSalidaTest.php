<?php

namespace Tests\Unit\Dte;

use App\DataTransferObjects\Dte\Salida\ApendiceDteData;
use App\DataTransferObjects\Dte\Salida\DocumentoRelacionadoDteData;
use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\EmisorDteData;
use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ReceptorDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Support\Dte\NumeroALetras;
use Tests\TestCase;

class DtoSalidaTest extends TestCase
{
    private function identificacion(): IdentificacionDteData
    {
        return new IdentificacionDteData(
            version: 3, ambiente: '00', tipoDte: '03',
            fechaEmision: '2026-06-14', horaEmision: '10:00:00',
        );
    }

    private function emisor(): EmisorDteData
    {
        return new EmisorDteData(
            nit: '0614-000000-000-0', nrc: '111111-1', nombre: 'Dulces La Negrita',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
        );
    }

    private function resumen(?string $totalLetras = null): ResumenDteData
    {
        return new ResumenDteData(
            totalGravado: '100.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: '13.00', ivaRetenido: '0.00', retencionRenta: '0.00',
            totalAntesRetencion: '113.00', montoTotalOperacion: '113.00', totalPagar: '113.00',
            totalLetras: $totalLetras, condicionOperacion: 1,
        );
    }

    private function linea(): LineaDteData
    {
        return new LineaDteData(
            numeroLinea: 1, descripcion: 'Dulce de leche', cantidad: '10', precioUnitario: '10.00',
            totalLinea: '113.00', ventaGravada: '100.00', iva: '13.00',
        );
    }

    public function test_se_puede_crear_cada_dto_con_datos_minimos(): void
    {
        $this->assertSame('03', $this->identificacion()->tipoDte);
        $this->assertSame(1, $this->identificacion()->tipoModelo); // default normal
        $this->assertSame('Dulces La Negrita', $this->emisor()->nombre);
        $this->assertSame('100.00', $this->resumen()->totalGravado);
        $this->assertSame('100.00', $this->linea()->ventaGravada);

        $receptor = new ReceptorDteData(nombre: 'Calleja S.A. de C.V.', numDocumento: '0614-1');
        $this->assertSame('Calleja S.A. de C.V.', $receptor->nombre);
        $this->assertNull($receptor->pais);
    }

    public function test_dte_salida_data_agrupa_las_secciones(): void
    {
        $salida = new DteSalidaData(
            identificacion: $this->identificacion(),
            emisor: $this->emisor(),
            resumen: $this->resumen(),
            lineas: [$this->linea()],
            receptor: new ReceptorDteData(nombre: 'Receptor'),
            apendice: [ApendiceDteData::ordenCompra('OC-1')],
        );

        $this->assertInstanceOf(IdentificacionDteData::class, $salida->identificacion);
        $this->assertInstanceOf(EmisorDteData::class, $salida->emisor);
        $this->assertInstanceOf(ResumenDteData::class, $salida->resumen);
        $this->assertCount(1, $salida->lineas);
        $this->assertInstanceOf(LineaDteData::class, $salida->lineas[0]);
        $this->assertSame('Receptor', $salida->receptor->nombre);
        $this->assertCount(1, $salida->apendice);
        $this->assertSame([], $salida->documentoRelacionado); // default vacío
    }

    public function test_receptor_puede_ser_nulo(): void
    {
        $salida = new DteSalidaData(
            identificacion: $this->identificacion(),
            emisor: $this->emisor(),
            resumen: $this->resumen(),
            lineas: [$this->linea()],
        );

        $this->assertNull($salida->receptor); // Factura 01 a consumidor final
    }

    public function test_apendice_permite_orden_de_compra(): void
    {
        $oc = ApendiceDteData::ordenCompra('OC-2026-001', 'Orden de compra Selectos');

        $this->assertSame('ordenCompra', $oc->campo);
        $this->assertSame('Orden de compra Selectos', $oc->etiqueta);
        $this->assertSame('OC-2026-001', $oc->valor);
    }

    public function test_documento_relacionado_referencia_ccf_original(): void
    {
        $rel = new DocumentoRelacionadoDteData(
            tipoDocumento: '03', tipoGeneracion: 2,
            numeroDocumento: 'INT-03-M001P001-000000000000001', fechaEmision: '2026-06-14',
        );

        $this->assertSame('03', $rel->tipoDocumento);
        $this->assertSame(2, $rel->tipoGeneracion);
        $this->assertSame('INT-03-M001P001-000000000000001', $rel->numeroDocumento);
    }

    public function test_total_en_letras_puede_venir_de_numero_a_letras(): void
    {
        $resumen = $this->resumen(NumeroALetras::convertir('113.00'));

        $this->assertSame('CIENTO TRECE 00/100 DÓLARES', $resumen->totalLetras);
    }
}
