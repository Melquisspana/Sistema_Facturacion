<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\EmisorDteData;
use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ReceptorDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Enums\TipoDte;
use App\Models\CatalogoMh;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorCcfMh;
use App\Services\Dte\Serializadores\SerializadorExportacionMh;
use App\Services\Dte\Serializadores\SerializadorFacturaMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * resumen.pagos según la Normativa de Cumplimiento 2.0, Anexo IV, campos 160-164 (p.121):
 *
 * - Condición 1 (contado) o 3 (otro): el código de forma de pago (CAT-017) es OBLIGATORIO
 *   y la suma de montoPago es el total a pagar. Antes FE y FEX mandaban `pagos: null`.
 * - Condición 2 (crédito): plazo (CAT-018) y período son obligatorios, y se mantienen el
 *   código y montoPago = total. La lectura de los campos 160-164 pedía codigo null y monto
 *   0.00, pero el MH lo recibe con observación 002 (apitest, 24/10/2026).
 *
 * Cada caso valida el JSON completo contra el esquema oficial del tipo. El cambio toca
 * el JSON fiscal de lo que ya se emite: se confirma en apitest antes de desplegar (#42).
 */
class PagosResumenHacienda20Test extends TestCase
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

    /** @return array<string, array{TipoDte}> */
    public static function tipos(): array
    {
        return [
            'factura' => [TipoDte::Factura],
            'ccf' => [TipoDte::CreditoFiscal],
            'exportacion' => [TipoDte::FacturaExportacion],
        ];
    }

    #[DataProvider('tipos')]
    public function test_contado_lleva_forma_de_pago_y_el_total(TipoDte $tipo): void
    {
        $oficial = $this->serializarValido($tipo, condicion: 1, formaPago: '05');

        $this->assertSame([[
            'codigo' => '05',
            'montoPago' => 113.0,
            'referencia' => null,
            'plazo' => null,
            'periodo' => null,
        ]], $oficial['resumen']['pagos']);
        $this->assertSame(1, $oficial['resumen']['condicionOperacion']);
    }

    #[DataProvider('tipos')]
    public function test_contado_sin_forma_de_pago_usa_la_configurada(TipoDte $tipo): void
    {
        config(['dte.json.forma_pago_default' => '01']);

        $oficial = $this->serializarValido($tipo, condicion: 1, formaPago: null);

        $this->assertSame('01', $oficial['resumen']['pagos'][0]['codigo']);
        $this->assertSame(113.0, $oficial['resumen']['pagos'][0]['montoPago']);
    }

    #[DataProvider('tipos')]
    public function test_condicion_otro_tambien_exige_forma_de_pago(TipoDte $tipo): void
    {
        $oficial = $this->serializarValido($tipo, condicion: 3, formaPago: '02');

        $this->assertSame('02', $oficial['resumen']['pagos'][0]['codigo']);
        $this->assertSame(113.0, $oficial['resumen']['pagos'][0]['montoPago']);
        $this->assertNull($oficial['resumen']['pagos'][0]['plazo']);
        $this->assertNull($oficial['resumen']['pagos'][0]['periodo']);
    }

    #[DataProvider('tipos')]
    public function test_credito_lleva_forma_de_pago_total_y_plazo(TipoDte $tipo): void
    {
        config(['dte.json.plazo_credito_default' => '01', 'dte.json.periodo_credito_default' => 30]);

        // Con codigo null y montoPago 0.00 el MH lo recibía con observación 002 «Forma de
        // pago difiere del Total a Pagar» (apitest, 24/10/2026): va código y total, más plazo.
        $oficial = $this->serializarValido($tipo, condicion: 2, formaPago: '05');

        $this->assertSame([[
            'codigo' => '05',
            'montoPago' => 113.0,
            'referencia' => null,
            'plazo' => '01',
            'periodo' => 30,
        ]], $oficial['resumen']['pagos']);
        $this->assertSame(2, $oficial['resumen']['condicionOperacion']);
        // El total a pagar no cambia: solo cambia cómo se informa la forma de pago.
        $this->assertSame(113.0, $oficial['resumen']['totalPagar']);
    }

    /** @return array<string, mixed> */
    private function serializarValido(TipoDte $tipo, int $condicion, ?string $formaPago): array
    {
        $oficial = match ($tipo) {
            TipoDte::Factura => app(SerializadorFacturaMh::class)->serializar($this->salidaFactura($condicion, $formaPago)),
            TipoDte::CreditoFiscal => app(SerializadorCcfMh::class)->serializar($this->salidaCcf($condicion, $formaPago)),
            TipoDte::FacturaExportacion => app(SerializadorExportacionMh::class)->serializar($this->salidaExportacion($condicion, $formaPago)),
        };

        $res = app(DteSchemaValidator::class)->validar($oficial, $tipo);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));

        return $oficial;
    }

    private function ident(string $tipo, int $version): IdentificacionDteData
    {
        return new IdentificacionDteData(
            version: $version, ambiente: '00', tipoDte: $tipo, fechaEmision: '2026-10-20', horaEmision: '10:00:00',
            numeroControl: 'DTE-'.$tipo.'-M001P001-000000000000001',
            codigoGeneracion: 'A1B2C3D4-E5F6-4A8B-9C0D-1E2F3A4B5C6D',
            tipoModelo: 1, tipoOperacion: 1, tipoContingencia: null, motivoContingencia: null, tipoMoneda: 'USD',
        );
    }

    private function emisor(): EmisorDteData
    {
        return new EmisorDteData(
            nit: '06140000000011', nrc: '111111', nombre: 'Emisor de Prueba',
            codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
            actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '05', direccion: 'Calle X',
            telefono: '22000000', correo: 'emisor@ejemplo.sv', tipoEstablecimiento: '02',
            tipoItemExpor: 1, recintoFiscal: '01', tipoRegimen: 'EX-1', regimen: '1000.000',
        );
    }

    private function resumen(int $condicion, ?string $formaPago, string $gravado = '100.00', string $iva = '13.00', string $exportacion = '0.00'): ResumenDteData
    {
        return new ResumenDteData(
            totalGravado: $gravado, totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: $exportacion,
            descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
            iva: $iva, ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '113.00',
            montoTotalOperacion: '113.00', totalPagar: '113.00', totalLetras: 'CIENTO TRECE 00/100',
            condicionOperacion: $condicion, porcentajeDescuento: '0.00', formaPago: $formaPago,
            codIncoterms: '09', descIncoterms: 'CIF-Costo, seguro y flete',
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

    private function salidaFactura(int $condicion, ?string $formaPago): DteSalidaData
    {
        return new DteSalidaData(
            identificacion: $this->ident('01', 2), emisor: $this->emisor(), resumen: $this->resumen($condicion, $formaPago),
            lineas: [$this->lineaGravada()], receptor: null, apendice: [],
        );
    }

    private function salidaCcf(int $condicion, ?string $formaPago): DteSalidaData
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '36', numDocumento: '06140000000001', nrc: '222222', nombre: 'Cliente Contribuyente',
            actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '05',
            direccion: 'Colonia Centro', telefono: '22001111', correo: 'cliente@ejemplo.sv',
        );

        return new DteSalidaData(
            identificacion: $this->ident('03', 4), emisor: $this->emisor(), resumen: $this->resumen($condicion, $formaPago),
            lineas: [$this->lineaGravada()], receptor: $receptor, apendice: [],
        );
    }

    private function salidaExportacion(int $condicion, ?string $formaPago): DteSalidaData
    {
        $receptor = new ReceptorDteData(
            tipoDocumento: '37', numDocumento: 'EXT-001', nombre: 'Importadora CA',
            actividadEconomica: '10730', pais: 'GT', direccion: 'Ciudad de Guatemala', tipoPersona: '1',
        );
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'Producto exportado', cantidad: '10', precioUnitario: '11.300000',
            totalLinea: '113.00', tipoItem: 1, codigo: 'P-1', unidadMedida: '59',
            ventaExportacion: '113.00',
        );

        return new DteSalidaData(
            identificacion: $this->ident('11', 3), emisor: $this->emisor(),
            resumen: $this->resumen($condicion, $formaPago, gravado: '0.00', iva: '0.00', exportacion: '113.00'),
            lineas: [$linea], receptor: $receptor, apendice: [],
        );
    }
}
