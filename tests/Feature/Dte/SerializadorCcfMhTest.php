<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\ApendiceDteData;
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
use App\Services\Dte\Serializadores\SerializadorCcfMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerializadorCcfMhTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Catálogos mínimos que usa el serializador.
        CatalogoMh::create(['cat' => '014', 'codigo' => '59', 'valor' => 'Unidad']);
        CatalogoMh::create(['cat' => '014', 'codigo' => '99', 'valor' => 'Otra']); // Bolsa se mapea a 99
        CatalogoMh::create(['cat' => '015', 'codigo' => '20', 'valor' => 'Impuesto al Valor Agregado 13%']);
        CatalogoMh::create(['cat' => '019', 'codigo' => '47190', 'valor' => 'Venta al por menor de otros productos']);
    }

    /** @param array<int, LineaDteData> $lineas */
    private function salida(?array $lineas = null, array $apendice = [], string $receptorNumDocumento = '06140000000001', ?string $formaPago = null, int $condicion = 2): DteSalidaData
    {
        $lineas ??= [new LineaDteData(
            numeroLinea: 1, descripcion: 'Dulce de leche', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '113.00', tipoItem: 1, codigo: 'DUL-1', codigoBarra: null, unidadMedida: '59',
            descuento: '0.00', ventaGravada: '100.00', iva: '13.00',
        )];

        return new DteSalidaData(
            identificacion: new IdentificacionDteData(
                version: 4, ambiente: '00', tipoDte: '03', fechaEmision: '2026-06-17', horaEmision: '10:00:00',
                numeroControl: 'DTE-03-M001P001-000000000000001',
                codigoGeneracion: 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D',
                tipoModelo: 1, tipoOperacion: 1, tipoContingencia: null, motivoContingencia: null, tipoMoneda: 'USD',
            ),
            emisor: new EmisorDteData(
                nit: '0614-000000-000-0', nrc: '111111', nombre: 'Dulces La Negrita',
                codigoEstablecimiento: 'M001', codigoPuntoVenta: 'P001',
                actividadEconomica: '47190', departamento: '06', municipio: '14', direccion: 'Calle X',
                telefono: '22000000', correo: 'e@e.com',
            ),
            resumen: new ResumenDteData(
                totalGravado: '100.00', totalExento: '0.00', totalNoSujeto: '0.00', totalExportacion: '0.00',
                descuentoGravado: '0.00', descuentoExento: '0.00', descuentoNoSujeto: '0.00', descuentoTotal: '0.00',
                iva: '13.00', ivaRetenido: '0.00', retencionRenta: '0.00', totalAntesRetencion: '113.00',
                montoTotalOperacion: '113.00', totalPagar: '113.00', totalLetras: 'CIENTO TRECE 00/100',
                condicionOperacion: $condicion, porcentajeDescuento: '0.00', formaPago: $formaPago,
            ),
            lineas: $lineas,
            receptor: new ReceptorDteData(
                tipoDocumento: '36', numDocumento: $receptorNumDocumento, nrc: '222222', nombre: 'Calleja',
                actividadEconomica: '47190', departamento: '06', municipio: '14', direccion: 'Av Y',
            ),
            apendice: $apendice,
        );
    }

    public function test_serializa_estructura_base(): void
    {
        $a = app(SerializadorCcfMh::class)->serializar($this->salida());

        foreach (['identificacion', 'documentoRelacionado', 'emisor', 'receptor', 'otrosDocumentos', 'ventaTercero', 'cuerpoDocumento', 'resumen', 'apendice'] as $clave) {
            $this->assertArrayHasKey($clave, $a);
        }
        $this->assertSame('03', $a['identificacion']['tipoDte']);
        $this->assertSame(4, $a['identificacion']['version']);
        $this->assertNull($a['documentoRelacionado']); // CCF no lleva documento relacionado
        $this->assertSame('M001', $a['emisor']['codEstable']);
        $this->assertSame(59, $a['cuerpoDocumento'][0]['uniMedida']);   // CAT-014 mapeado a entero
        $this->assertSame(['20'], $a['cuerpoDocumento'][0]['tributos']); // IVA CAT-015
        $this->assertSame(113.0, $a['resumen']['totalPagar']);           // número, no string
        $this->assertSame('20', $a['resumen']['tributos'][0]['codigo']);
    }

    /**
     * Una línea de CCF cuya unidad es "Bolsa" (código CAT-014 99 "Otra", porque el
     * catálogo no tiene bolsa propia) serializa uniMedida = 99 y valida. El CCF usa su
     * propio método unidadMedida(), por eso se prueba aparte de NC/FEX.
     */
    public function test_unidad_bolsa_codigo_99_se_serializa_en_ccf(): void
    {
        $lineaBolsa = [new LineaDteData(
            numeroLinea: 1, descripcion: 'Dulce en bolsa', cantidad: '10', precioUnitario: '10.000000',
            totalLinea: '113.00', tipoItem: 1, codigo: 'DUL-1', codigoBarra: null, unidadMedida: '99',
            descuento: '0.00', ventaGravada: '100.00', iva: '13.00',
        )];

        $a = app(SerializadorCcfMh::class)->serializar($this->salida(lineas: $lineaBolsa));
        $res = app(DteSchemaValidator::class)->validar($a, TipoDte::CreditoFiscal);

        $this->assertSame(99, $a['cuerpoDocumento'][0]['uniMedida']);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_orden_de_compra_va_en_apendice(): void
    {
        $apendice = [ApendiceDteData::ordenCompra('OC-123', 'Orden de compra')];
        $a = app(SerializadorCcfMh::class)->serializar($this->salida(apendice: $apendice));

        $this->assertNotNull($a['apendice']);
        $this->assertSame('OC-123', $a['apendice'][0]['valor']);
        $this->assertArrayHasKey('campo', $a['apendice'][0]);
        $this->assertArrayHasKey('etiqueta', $a['apendice'][0]);
    }

    public function test_unidad_cat014_invalida_falla_claramente(): void
    {
        $linea = new LineaDteData(
            numeroLinea: 1, descripcion: 'X', cantidad: '1', precioUnitario: '1.000000',
            totalLinea: '1.13', tipoItem: 1, unidadMedida: '777', ventaGravada: '1.00', iva: '0.13',
        );

        try {
            app(SerializadorCcfMh::class)->serializar($this->salida(lineas: [$linea]));
            $this->fail('Debió lanzar DteNoSerializableException.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('CAT-014', implode(' ', $e->problemas));
        }
    }

    public function test_validador_disponible_con_libreria_instalada(): void
    {
        $this->assertTrue(app(DteSchemaValidator::class)->disponible());
    }

    public function test_validador_detecta_json_invalido_contra_schema(): void
    {
        // Estructura inválida: faltan secciones requeridas del schema oficial.
        $res = app(DteSchemaValidator::class)->validar(['identificacion' => []], TipoDte::CreditoFiscal);

        $this->assertSame('invalido', $res['estado']);
        $this->assertFalse($res['valido']);
        $this->assertNotEmpty($res['errores']);
    }

    public function test_receptor_nit_se_normaliza_a_solo_digitos(): void
    {
        // El número del cliente puede venir con guiones; el MH lo exige sin ellos.
        $a = app(SerializadorCcfMh::class)->serializar($this->salida(receptorNumDocumento: '0614-010101-001-1'));

        $this->assertSame('06140101010011', $a['receptor']['nit']);
    }

    public function test_pagos_es_arreglo_no_nulo_con_monto_total(): void
    {
        // fe-ccf-v4: pagos es ["array","null"] con minItems:1 y el MH rechaza null en
        // el CCF → debe ser un arreglo con (al menos) un pago, nunca null ni [].
        $a = app(SerializadorCcfMh::class)->serializar($this->salida());

        $this->assertIsArray($a['resumen']['pagos']);
        $this->assertCount(1, $a['resumen']['pagos']);
        $this->assertSame(113.0, $a['resumen']['pagos'][0]['montoPago']);  // = totalPagar
        $this->assertSame('01', $a['resumen']['pagos'][0]['codigo']);      // default CAT-017
        $this->assertArrayHasKey('plazo', $a['resumen']['pagos'][0]);
        $this->assertArrayHasKey('periodo', $a['resumen']['pagos'][0]);
    }

    public function test_pagos_credito_incluye_plazo_cat018_y_periodo(): void
    {
        // condicionOperacion=2 (A crédito): el MH exige plazo (CAT-018) y periodo (>0).
        $a = app(SerializadorCcfMh::class)->serializar($this->salida(condicion: 2));

        $this->assertSame('01', $a['resumen']['pagos'][0]['plazo']);    // CAT-018: 01=Días
        $this->assertSame(30, $a['resumen']['pagos'][0]['periodo']);    // default configurable
    }

    public function test_pagos_contado_no_lleva_plazo_ni_periodo(): void
    {
        // condicionOperacion=1 (Contado): plazo/periodo van null (no aplica crédito).
        $a = app(SerializadorCcfMh::class)->serializar($this->salida(condicion: 1));

        $this->assertNull($a['resumen']['pagos'][0]['plazo']);
        $this->assertNull($a['resumen']['pagos'][0]['periodo']);
    }

    public function test_pagos_usa_la_forma_de_pago_del_documento(): void
    {
        $a = app(SerializadorCcfMh::class)->serializar($this->salida(formaPago: '05'));

        $this->assertSame('05', $a['resumen']['pagos'][0]['codigo']);
    }

    public function test_validador_acepta_ccf_bien_formado(): void
    {
        $oficial = app(SerializadorCcfMh::class)->serializar($this->salida());

        $res = app(DteSchemaValidator::class)->validar($oficial, TipoDte::CreditoFiscal);

        // El serializador con datos fake completos (numeroControl/codigoGeneracion válidos)
        // debe producir una estructura aceptada por fe-ccf-v4.json.
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }
}
