<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\DocumentoRelacionadoDteData;
use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\EmisorDteData;
use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ReceptorDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Exceptions\Dte\GeneracionException;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaRepository;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\SaldoMontoCcf;
use App\Services\Dte\Serializadores\NotaCreditoV4\CalculoImportesNcV4;
use App\Services\Dte\Serializadores\NotaCreditoV4\ImportesNcV4AjusteCcf;
use App\Services\Dte\Serializadores\SerializadorMhFactory;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoMh;
use App\Services\Dte\Serializadores\SerializadorNotaCreditoV4Mh;
use App\Services\Ppq\DteCorreoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class NotaCreditoV4Test extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['dte.ambiente' => '00', 'dte.json.versiones.05' => 4, 'dte.json.nc_v4_calculo' => 'ajuste_ccf']);
        $this->seedCatalogosDte();
    }

    private function salida(array $lineas = [], int $version = 4, string $retencion = '0.00', string $global = '0.00', ?string $nit = '0614-000000-001-1', ?string $distrito = '05', string $tipoRelacionado = '03'): DteSalidaData
    {
        $lineas = $lineas ?: [new LineaDteData(1, 'Producto', '10', '10.000000', '113.00', tipoItem: 1, unidadMedida: '59', ventaGravada: '100.00', iva: '13.00')];
        $gravado = number_format(array_sum(array_map(fn ($l) => (float) $l->ventaGravada, $lineas)), 2, '.', '');
        $exento = number_format(array_sum(array_map(fn ($l) => (float) $l->ventaExenta, $lineas)), 2, '.', '');
        $noSujeto = number_format(array_sum(array_map(fn ($l) => (float) $l->ventaNoSujeta, $lineas)), 2, '.', '');
        $iva = number_format(array_sum(array_map(fn ($l) => (float) $l->iva, $lineas)), 2, '.', '');
        $bruto = number_format((float) $gravado + (float) $exento + (float) $noSujeto + (float) $iva - (float) $global, 2, '.', '');
        $total = number_format((float) $bruto - (float) $retencion, 2, '.', '');

        return new DteSalidaData(
            identificacion: new IdentificacionDteData($version, '00', '05', '2026-10-06', '10:00:00', 'DTE-05-M001P001-000000000000001', 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D'),
            emisor: new EmisorDteData('06140000000011', '111111', 'Emisor de prueba', 'M001', 'P001', actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: '05', direccion: 'Calle X', telefono: '22000000', correo: 'emisor@example.com', tipoEstablecimiento: '02'),
            receptor: new ReceptorDteData(tipoDocumento: '36', numDocumento: $nit, nrc: '222222', nombre: 'Receptor de prueba', actividadEconomica: '10730', departamento: '06', municipio: '14', distrito: $distrito, direccion: 'Calle Y', telefono: '22001111', correo: 'receptor@example.com'),
            lineas: $lineas,
            resumen: new ResumenDteData($gravado, $exento, $noSujeto, '0.00', $global, '0.00', '0.00', $global, $iva, $retencion, '0.00', $bruto, $bruto, $total, 'TOTAL EN LETRAS', condicionOperacion: 1),
            documentoRelacionado: [new DocumentoRelacionadoDteData($tipoRelacionado, 2, 'B1B2C3D4-E5F6-4A8B-9C0D-1E2F3A4B5C6D', '2026-10-01')],
        );
    }

    private function comprobar(array $json, DteSalidaData $salida): void
    {
        $validacion = app(DteSchemaValidator::class)->validar($json, TipoDte::NotaCredito);
        $this->assertTrue($validacion['valido'], implode(' | ', $validacion['errores']));
        $this->assertSame(4, $json['identificacion']['version']);
        $this->assertNull($json['identificacion']['fusion']);
        $this->assertArrayNotHasKey('extension', $json);
        $this->assertSame('36', $json['receptor']['tipoDocumento']);
        $this->assertSame(preg_replace('/\D/', '', $salida->receptor->numDocumento), $json['receptor']['numDocumento']);
        foreach ($json['cuerpoDocumento'] as $linea) {
            $venta = $linea['ventaGravada'] + $linea['ventaExenta'] + $linea['ventaNoSuj'];
            $this->assertEqualsWithDelta($venta, $linea['cantidad'] * $linea['precioUni'] - $linea['montoDescu'], 0.01000001);
            $this->assertSame($linea['ventaGravada'] > 0 ? ['20'] : null, $linea['tributos']);
            $this->assertSame(0.0, (float) $linea['totalIva']);
            $this->assertSame(0.0, (float) $linea['ivaPerci']);
            $this->assertSame(0.0, (float) $linea['noGravado']);
            if ((float) $salida->resumen->ivaRetenido > 0) {
                $this->assertEqualsWithDelta(round($linea['ventaGravada'] * 0.01, 2), $linea['ivaRete'], 0.01000001);
            }
            $this->assertSame($salida->documentoRelacionado[0]->numeroDocumento, $linea['numeroDocumento']);
        }
        foreach (['ventaGravada' => 'totalGravada', 'ventaExenta' => 'totalExenta', 'ventaNoSuj' => 'totalNoSuj', 'totalIva' => 'totalIva', 'ivaRete' => 'ivaRete', 'ivaPerci' => 'ivaPerci', 'montoDescu' => 'totalDescu'] as $campo => $total) {
            $this->assertSame(round(array_sum(array_column($json['cuerpoDocumento'], $campo)), 2), (float) $json['resumen'][$total]);
        }
        $r = $json['resumen'];
        $this->assertSame(0.0, (float) $r['totalIva']);
        $this->assertNull($r['codigoRetencionMH']);
        $this->assertNull($r['observaciones']);
        if ($r['totalGravada'] > 0) {
            $this->assertSame('20', $r['tributos'][0]['codigo']);
            $this->assertNotEmpty($r['tributos'][0]['descripcion']);
            $this->assertSame((float) $salida->resumen->iva, (float) $r['tributos'][0]['valor']);
        } else {
            $this->assertNull($r['tributos']);
        }
        $this->assertSame(round($r['totalGravada'] + $r['totalExenta'] + $r['totalNoSuj'], 2), (float) $r['subTotalVentas']);
        $this->assertSame(round($r['subTotalVentas'] + array_sum(array_column($r['tributos'] ?? [], 'valor')), 2), (float) $r['montoTotalOperacion']);
        $this->assertSame(round($r['montoTotalOperacion'] - $r['ivaRete'], 2), (float) $r['totalPagar']);
        $this->assertSame((float) $salida->resumen->totalPagar, (float) $r['totalPagar']);
        $this->assertSame((float) $r['totalPagar'], app(DteCorreoParser::class)->desdeJson($json)['monto']);
    }

    public function test_seleccion_por_documento_y_v3_sin_cambios(): void
    {
        foreach ([3, 4] as $version) {
            config(['dte.json.versiones.05' => $version]);
            $salida = $this->salida(version: $version);
            // Cambiar la bandera después de mapear no cambia la versión del documento.
            config(['dte.json.versiones.05' => $version === 3 ? 4 : 3]);
            $json = app(SerializadorMhFactory::class)->para(TipoDte::NotaCredito, $salida->identificacion->version)->serializar($salida);
            if ($version === 3) {
                $this->assertSame(app(SerializadorNotaCreditoMh::class)->serializar($salida), $json);
                $this->assertTrue(app(DteSchemaValidator::class)->validar($json, TipoDte::NotaCredito)['valido']);
            } else {
                $this->comprobar($json, $salida);
            }
        }
    }

    public function test_version_desconocida_no_cae_a_v3(): void
    {
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage('versión');
        app(SerializadorMhFactory::class)->para(TipoDte::NotaCredito, 5)->serializar($this->salida(version: 5));
    }

    public function test_descuento_de_linea_exenta_no_sujeta_y_residuo_retencion(): void
    {
        $lineas = [
            new LineaDteData(1, 'Gravado con descuento', '1', '34.31', '37.63', tipoItem: 1, unidadMedida: '59', descuento: '1.01', ventaGravada: '33.30', iva: '4.33'),
            new LineaDteData(2, 'Gravado', '1', '33.30', '37.63', tipoItem: 1, unidadMedida: '59', ventaGravada: '33.30', iva: '4.33'),
            new LineaDteData(3, 'Gravado final', '1', '33.40', '37.74', tipoItem: 1, unidadMedida: '59', ventaGravada: '33.40', iva: '4.34'),
            new LineaDteData(4, 'Exento', '2', '5', '9.50', tipoItem: 1, unidadMedida: '59', descuento: '0.50', ventaExenta: '9.50'),
            new LineaDteData(5, 'No sujeto', '1', '3', '3.00', tipoItem: 1, unidadMedida: '59', ventaNoSujeta: '3.00'),
        ];
        $salida = $this->salida($lineas, retencion: '1.00');
        $json = app(SerializadorNotaCreditoV4Mh::class)->serializar($salida);
        $this->comprobar($json, $salida);
        $this->assertSame([0.33, 0.33, 0.34, 0.0, 0.0], array_column($json['cuerpoDocumento'], 'ivaRete'));
        $this->assertSame(1.01, $json['cuerpoDocumento'][0]['montoDescu']);
        $this->assertSame(1.51, $json['resumen']['totalDescu']);
    }

    public function test_fracciones_precio_preciso_e_iva_con_medio_centavo(): void
    {
        $salida = $this->salida([
            new LineaDteData(1, 'Fracción', '0.125', '12.34567', '1.74', tipoItem: 1, unidadMedida: '59', ventaGravada: '1.54', iva: '0.20'),
            new LineaDteData(2, 'IVA 0.065 redondeado', '0.5', '1.00001', '0.57', tipoItem: 1, unidadMedida: '59', ventaGravada: '0.50', iva: '0.07'),
        ]);
        $this->comprobar(app(SerializadorNotaCreditoV4Mh::class)->serializar($salida), $salida);
    }

    public function test_precio_neto_se_conserva_sin_reconstruir_un_bruto(): void
    {
        $salida = $this->salida([
            new LineaDteData(1, 'Precio periódico', '3', '0.11333333', '0.38', tipoItem: 1, unidadMedida: '59', ventaGravada: '0.34', iva: '0.04'),
        ]);
        $json = app(SerializadorNotaCreditoV4Mh::class)->serializar($salida);
        $this->comprobar($json, $salida);
        $this->assertSame(0.11333333, $json['cuerpoDocumento'][0]['precioUni']);
    }

    public function test_descuento_global_se_rechaza_solo_en_v4(): void
    {
        $v3 = $this->salida(version: 3, global: '5.00');
        $this->assertSame(app(SerializadorNotaCreditoMh::class)->serializar($v3), app(SerializadorMhFactory::class)->para(TipoDte::NotaCredito, 3)->serializar($v3));
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage('La NC versión 4 todavía no admite descuento global: el resumen v4 no tiene descuGravada y falta confirmar con Hacienda cómo se prorratea. Emítala sin descuento global o en versión 3.');
        app(SerializadorNotaCreditoV4Mh::class)->serializar($this->salida(global: '5.00'));
    }

    public static function faltantes(): array
    {
        return ['NIT' => [null, '05', 'NIT'], 'distrito' => ['06140000000011', null, 'distrito']];
    }

    #[DataProvider('faltantes')]
    public function test_no_inventa_datos_del_receptor(?string $nit, ?string $distrito, string $mensaje): void
    {
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage($mensaje);
        app(SerializadorNotaCreditoV4Mh::class)->serializar($this->salida(nit: $nit, distrito: $distrito));
    }

    public function test_schema_inexistente_no_usa_el_primer_archivo(): void
    {
        $this->assertNull(app(DteSchemaRepository::class)->paraTipo(TipoDte::NotaCredito, 5));
        $this->assertSame('sin_schema', app(DteSchemaValidator::class)->validar(['identificacion' => ['version' => 5]], TipoDte::NotaCredito)['estado']);
    }

    public function test_version_explicita_cero_no_usa_schema_de_otra_version(): void
    {
        $this->assertSame('sin_schema', app(DteSchemaValidator::class)->validar(['identificacion' => ['version' => 0]], TipoDte::NotaCredito)['estado']);
    }

    public function test_estrategia_desconocida_se_rechaza(): void
    {
        config(['dte.json.nc_v4_calculo' => 'desconocida']);
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage('desconocida');
        app(SerializadorNotaCreditoV4Mh::class)->serializar($this->salida());
    }

    public static function descuadres(): array
    {
        return ['IVA 13%' => ['totalIva'], 'retención' => ['ivaRete'], 'percepción' => ['ivaPerci'], 'descuentos' => ['totalDescu'], 'tributo IVA' => ['tributos'], 'total interno' => ['totalPagar'], 'base' => ['totalGravada']];
    }

    #[DataProvider('descuadres')]
    public function test_invariantes_fuera_de_la_estrategia(string $campo): void
    {
        $doble = new class($campo) implements CalculoImportesNcV4
        {
            public function __construct(private string $campo) {}

            public function lineas(DteSalidaData $d): array
            {
                return (new ImportesNcV4AjusteCcf)->lineas($d);
            }

            public function resumen(DteSalidaData $d, array $lineas): array
            {
                $resumen = (new ImportesNcV4AjusteCcf)->resumen($d, $lineas);
                if ($this->campo === 'tributos') {
                    $resumen['tributos'][0]['valor'] += 0.01;
                } else {
                    $resumen[$this->campo] += 0.01;
                }

                return $resumen;
            }
        };
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage($campo);
        (new SerializadorNotaCreditoV4Mh($doble))->serializar($this->salida());
    }

    private function ccf(array $precios = [150.01, 250.02]): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true, 'descuento_global_default' => 0]);
        $servicio = app(DteBorradorService::class);
        $ccf = $servicio->crearBorrador(['tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id]);
        foreach ($precios as $precio) {
            $producto = Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $servicio->agregarLineaDesdeProducto($ccf, $producto, cantidad: 2);
        }
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    public static function modalidades(): array
    {
        return ['parcial' => ['parcial'], 'total' => ['total'], 'pronto pago' => ['pronto_pago'], 'avería' => ['averia'], 'descuento por línea' => ['descuento_linea']];
    }

    #[DataProvider('modalidades')]
    public function test_flujos_existentes_y_saldo_independiente_del_json(string $modalidad): void
    {
        $ccf = $this->ccf();
        $servicio = app(DteBorradorService::class);
        $usuario = User::factory()->create()->assignRole('facturacion');
        if ($modalidad === 'total') {
            $nc = $servicio->revertirCcfCompleto($ccf, $usuario);
        } else {
            $tipo = match ($modalidad) {
                'pronto_pago' => TipoNotaCredito::ProntoPago,
                'averia' => TipoNotaCredito::Averia,
                default => TipoNotaCredito::DevolucionProducto,
            };
            $nc = $servicio->crearNotaCredito($ccf, ['tipo' => $tipo->value], $usuario);
            if ($modalidad === 'pronto_pago') {
                $servicio->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 124.30]);
            } elseif ($modalidad === 'averia') {
                $servicio->agregarLineaDesdeProducto($nc, Producto::factory()->create(['precio_unitario' => 150.01, 'tipo_impuesto' => TipoImpuesto::Gravado->value]), cantidad: 1);
            } else {
                // Por numero_linea, en la CI salió primero la línea de 250.02 (orden no fijo entre entornos):
                // se toma por id (la primera creada, 150.01) para que la prueba no dependa del azar.
                $linea = $servicio->acreditarLinea($nc, $ccf->lineas()->reorder('id')->first(), 1);
                if ($modalidad === 'descuento_linea') {
                    $servicio->actualizarLinea($linea, ['cantidad' => 1, 'descuento_monto' => 1.01]);
                    $this->assertSame('149.00', (string) $nc->refresh()->total_gravado);
                }
            }
        }
        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh();
        $antes = $nc->getAttributes();
        $saldo = app(SaldoMontoCcf::class)->saldo($ccf);
        $json = json_decode(Storage::disk('local')->get($nc->json_generado_path), true);
        $this->comprobar($json, app(MapeadorDteSalida::class)->mapear($nc));
        $this->assertLessThanOrEqual((float) $ccf->iva_retenido, (float) $nc->iva_retenido);
        $this->assertSame($saldo, app(SaldoMontoCcf::class)->saldo($ccf));
        foreach (['total_pagar', 'iva', 'iva_retenido', 'monto_total_operacion', 'total_gravado'] as $campo) {
            $this->assertSame($antes[$campo], $nc->refresh()->getAttributes()[$campo]);
        }
        config(['dte.json.versiones.05' => 3]);
        $v3 = app(MapeadorDteSalida::class)->mapear($nc);
        $jsonV3 = app(SerializadorMhFactory::class)->para(TipoDte::NotaCredito, $v3->identificacion->version)->serializar($v3);
        $this->assertSame((float) $nc->total_pagar, $jsonV3['resumen']['montoTotalOperacion']);
        $this->assertSame($saldo, app(SaldoMontoCcf::class)->saldo($ccf));
    }

    public function test_documento_v3_firmado_se_conserva_con_bandera_v4(): void
    {
        $ccf = $this->ccf();
        $servicio = app(DteBorradorService::class);
        $nc = $servicio->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value], User::factory()->create()->assignRole('facturacion'));
        $servicio->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 124.30]);
        config(['dte.json.versiones.05' => 3]);
        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh();
        $generado = ['ruta' => $nc->json_generado_path];
        $nc->forceFill(['estado' => EstadoDte::Firmado, 'json_firmado_path' => 'dte/firmados/nc-v3.jws'])->save();
        Storage::disk('local')->put($nc->json_firmado_path, 'cabecera.contenido.firma');
        $atributos = $nc->refresh()->getAttributes();
        $archivos = Storage::disk('local')->allFiles();
        $contenido = Storage::disk('local')->get($generado['ruta']);
        config(['dte.json.versiones.05' => 4]);
        $payload = app(DteTransmisionService::class)->prepararPayloadRecepcion($nc);
        $this->assertSame(3, $payload['version']);
        $this->assertSame('cabecera.contenido.firma', $payload['documento']);
        $this->assertTrue(app(DteSchemaValidator::class)->validar(json_decode($contenido, true), TipoDte::NotaCredito)['valido']);
        $this->assertSame($contenido, Storage::disk('local')->get($generado['ruta']));
        $this->assertSame($archivos, Storage::disk('local')->allFiles());
        $this->assertSame($atributos, $nc->refresh()->getAttributes());
    }

    public function test_config_version_cinco_bloquea_generacion_sin_archivo_ni_numeracion(): void
    {
        $ccf = $this->ccf();
        $nc = app(DteBorradorService::class)->revertirCcfCompleto($ccf, User::factory()->create()->assignRole('facturacion'));
        $archivos = Storage::disk('local')->allFiles();
        config(['dte.json.versiones.05' => 5]);
        try {
            app(DteGeneracionService::class)->generar($nc);
            $this->fail('Debió rechazarse la versión desconocida.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('solo se admiten 3 o 4', $e->getMessage());
        }
        $nc->refresh();
        $this->assertSame(EstadoDte::Borrador, $nc->estado);
        $this->assertNull($nc->numero_control);
        $this->assertNull($nc->json_generado_path);
        $this->assertSame($archivos, Storage::disk('local')->allFiles());
    }

    public function test_redondeo_interno_de_iva_se_conserva_en_el_tributo_del_resumen(): void
    {
        // Dos bases de 0.50: cada IVA 0.065 se redondea a 0.07, pero el
        // resumen interno calcula 1.00 × 0.13 = 0.13. La NC v4 lo envía
        // en tributos, sin sumar ni corregir los IVA internos de las líneas.
        $ccf = $this->ccf([0.25, 0.25]);
        $nc = app(DteBorradorService::class)->revertirCcfCompleto($ccf, User::factory()->create()->assignRole('facturacion'));
        $this->assertSame('0.13', (string) $nc->iva);
        $this->assertSame(0.14, round((float) $nc->lineas()->sum('iva_linea'), 2));
        $this->assertSame('1.13', (string) $nc->total_pagar);
        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh();
        $this->assertSame(EstadoDte::Generado, $nc->estado);
        $this->assertSame('1.13', (string) $nc->total_pagar);
        $json = json_decode(Storage::disk('local')->get($nc->json_generado_path), true);
        $this->comprobar($json, app(MapeadorDteSalida::class)->mapear($nc));
        $this->assertSame(0.13, $json['resumen']['tributos'][0]['valor']);
        config(['dte.json.versiones.05' => 3]);
        $v3 = app(SerializadorNotaCreditoMh::class)->serializar(app(MapeadorDteSalida::class)->mapear($nc));
        $this->assertSame(1.13, $v3['resumen']['montoTotalOperacion']);
        $this->assertSame('1.13', (string) $nc->refresh()->total_pagar);
    }

    public function test_caso_del_rechazo_historico_iva_en_tributos_y_total_iva_cero(): void
    {
        $salida = $this->salida([
            new LineaDteData(1, 'Caso histórico', '1', '1.00', '1.13', tipoItem: 1, unidadMedida: '59', ventaGravada: '1.00', iva: '0.13'),
        ]);
        $json = app(SerializadorNotaCreditoV4Mh::class)->serializar($salida);
        $this->comprobar($json, $salida);
        $this->assertSame(1.0, $json['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame(0.0, $json['cuerpoDocumento'][0]['totalIva']);
        $this->assertSame(0.0, $json['resumen']['totalIva']);
        $this->assertSame(1.0, $json['resumen']['subTotalVentas']);
        $this->assertSame(1.13, $json['resumen']['montoTotalOperacion']);
        $this->assertSame(1.13, $json['resumen']['totalPagar']);
        $this->assertSame(0.0, $json['resumen']['ivaRete']);
        $this->assertSame([['codigo' => '20', 'descripcion' => CatalogoMh::where('cat', '015')->where('codigo', '20')->value('valor'), 'valor' => 0.13]], $json['resumen']['tributos']);
    }

    public function test_ajuste_solo_exento_no_lleva_tributos(): void
    {
        $salida = $this->salida([
            new LineaDteData(1, 'Exento', '2', '5.00', '9.50', tipoItem: 1, unidadMedida: '59', descuento: '0.50', ventaExenta: '9.50'),
        ]);
        $this->comprobar(app(SerializadorNotaCreditoV4Mh::class)->serializar($salida), $salida);
    }

    public function test_documento_relacionado_distinto_de_ccf_es_rechazado(): void
    {
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage('la NC v4 de este sistema solo ajusta CCF');
        app(SerializadorNotaCreditoV4Mh::class)->serializar($this->salida(tipoRelacionado: '07'));
    }

    public function test_opcion_anterior_de_estrategia_es_rechazada(): void
    {
        config(['dte.json.nc_v4_calculo' => 'iva_incluido']);
        $this->expectException(DteNoSerializableException::class);
        $this->expectExceptionMessage('desconocida');
        app(SerializadorNotaCreditoV4Mh::class)->serializar($this->salida());
    }
}
