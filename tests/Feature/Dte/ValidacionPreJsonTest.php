<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\UnidadMedida;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\ValidacionPreJsonService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidacionPreJsonTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private ValidacionPreJsonService $validacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->borradores = app(DteBorradorService::class);
        $this->validacion = app(ValidacionPreJsonService::class);
    }

    /** Emisor completo + correlativos. */
    private function emisor(): array
    {
        $actividad = ActividadEconomica::first();
        $depto = Departamento::first();
        $muni = Municipio::where('departamento_id', $depto->id)->first();

        $empresa = Empresa::create([
            'razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'nrc' => '111111-1',
            'actividad_economica_id' => $actividad->id, 'departamento_id' => $depto->id, 'municipio_id' => $muni->id,
            'direccion' => 'Calle Principal', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz',
            'tipo_establecimiento' => '01', 'activo' => true,
        ]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        foreach (['03', '05'] as $t) {
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

    private function productoConUnidad(): Producto
    {
        $unidad = UnidadMedida::whereNotNull('codigo')->first();

        return Producto::factory()->create([
            'unidad_medida_id' => $unidad->id, 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    private function ccfBorradorCompleto(array $emisor, ?Cliente $cliente = null, array $extra = []): Dte
    {
        $cliente ??= $this->clienteContribuyente($emisor);
        $dte = $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ], $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $this->productoConUnidad(), cantidad: 2);

        return $dte->refresh();
    }

    public function test_valida_pasa_con_ccf_generado_completo(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfBorradorCompleto($emisor);
        app(DteGeneracionService::class)->generar($ccf);

        $this->assertSame([], $this->validacion->validar($ccf->refresh()));
        $this->assertTrue($this->validacion->aprobado($ccf));
    }

    public function test_falla_si_esta_en_borrador(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfBorradorCompleto($emisor);

        $this->assertContains('El documento debe estar en estado generado.', $this->validacion->validar($ccf));
    }

    public function test_falla_si_no_tiene_lineas(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->clienteContribuyente($emisor),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        $this->assertContains('El documento no tiene líneas.', $this->validacion->validar($ccf));
    }

    public function test_falla_si_falta_emisor_completo(): void
    {
        $emisor = $this->emisor();
        $emisor['empresa']->update(['nit' => null, 'nrc' => null]);
        $ccf = $this->ccfBorradorCompleto($emisor);

        $problemas = $this->validacion->validar($ccf->refresh());
        $this->assertContains('Falta NIT del emisor.', $problemas);
        $this->assertContains('Falta NRC del emisor.', $problemas);
    }

    public function test_falla_si_falta_establecimiento_o_punto_venta(): void
    {
        $emisor = $this->emisor();
        $dte = Dte::create([
            'tipo_dte' => '03', 'estado' => 'generado', 'ambiente' => '00',
            'establecimiento_id' => $emisor['estab']->id, 'punto_venta_id' => null,
            'cliente_id' => $this->clienteContribuyente($emisor)->id,
            'condicion_operacion' => 1, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(),
            'total_pagar' => 0,
        ]);

        $this->assertContains('El documento debe tener establecimiento y punto de venta del emisor.', $this->validacion->validar($dte));
    }

    public function test_falla_si_ccf_requiere_orden_y_no_la_tiene(): void
    {
        $emisor = $this->emisor();
        $cliente = $this->clienteContribuyente($emisor, ['requiere_orden_compra' => true]);
        // Se crea con orden (obligatoria) y luego se quita para simular el faltante.
        $ccf = $this->ccfBorradorCompleto($emisor, $cliente, ['numero_orden_compra' => 'OC-1']);
        $ccf->update(['numero_orden_compra' => null]);

        $this->assertContains('El CCF requiere número de orden de compra.', $this->validacion->validar($ccf->refresh()));
    }

    public function test_falla_si_nota_credito_sin_documento_relacionado(): void
    {
        $emisor = $this->emisor();
        $nc = Dte::create([
            'tipo_dte' => '05', 'estado' => 'generado', 'ambiente' => '00',
            'establecimiento_id' => $emisor['estab']->id, 'punto_venta_id' => $emisor['pv']->id,
            'cliente_id' => $this->clienteContribuyente($emisor)->id, 'dte_relacionado_id' => null,
            'condicion_operacion' => 1, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(),
            'total_pagar' => 0,
        ]);

        $problemas = $this->validacion->validar($nc);
        $this->assertTrue((bool) collect($problemas)->first(fn ($p) => str_contains($p, 'vinculada a un CCF aceptado')));
    }

    private function superaSaldo(array $problemas): bool
    {
        return (bool) collect($problemas)->first(fn ($p) => str_contains($p, 'no puede superar el monto disponible'));
    }

    public function test_bloquea_nc_averia_si_gravada_supera_la_del_ccf(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfBorradorCompleto($emisor)); // gravado 20.00 (10 × 2)

        // Avería con productos manuales que SUMAN más que el CCF (10 × 3 = 30 > 20).
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->productoConUnidad(), 3);

        $this->assertTrue($this->superaSaldo($this->validacion->validar($nc->refresh())));
    }

    public function test_permite_nc_averia_con_productos_manuales_dentro_del_saldo(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfBorradorCompleto($emisor)); // gravado 20.00

        // Avería con producto manual que NO está en el CCF, dentro del saldo (10 × 1 = 10 ≤ 20).
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->productoConUnidad(), 1);

        // No debe aparecer el problema de saldo (sí otros, p.ej. estado borrador, irrelevantes aquí).
        $this->assertFalse($this->superaSaldo($this->validacion->validar($nc->refresh())));
    }

    public function test_bloquea_nc_si_supera_saldo_tras_nc_aceptada_previa(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfBorradorCompleto($emisor)); // gravado 20.00

        // NC #1 ya ACEPTADA consume 10.00 del saldo.
        $nc1 = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc1, $this->productoConUnidad(), 1); // 10.00
        $nc1->refresh();
        // NC #1 ACEPTADA REALMENTE por el MH (consume saldo): sello real + fecha_procesamiento_mh.
        $nc1->estado = \App\Enums\EstadoDte::Aceptado->value;
        $nc1->sello_recepcion = '2026'.strtoupper(\Illuminate\Support\Str::random(36));
        $nc1->fecha_procesamiento_mh = now();
        $nc1->save();

        // Saldo disponible = 20 − 10 = 10. NC #2 con 15.00 (15 × 1) lo supera → bloqueada.
        $caro = Producto::factory()->create([
            'unidad_medida_id' => UnidadMedida::whereNotNull('codigo')->first()->id,
            'precio_unitario' => 15, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $nc2 = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc2, $caro, 1); // 15.00 > saldo 10.00

        $this->assertTrue($this->superaSaldo($this->validacion->validar($nc2->refresh())));
    }

    private function noRealMh(array $problemas): bool
    {
        return (bool) collect($problemas)->first(fn ($p) => str_contains($p, 'aceptado realmente por Hacienda'));
    }

    public function test_bloquea_nc_si_ccf_relacionado_es_mock(): void
    {
        $emisor = $this->emisor();
        // CCF "aceptado" MOCK: estado aceptado pero con sello MOCK y SIN fecha_procesamiento_mh.
        $ccf = $this->ccfBorradorCompleto($emisor);
        $ccf->estado = \App\Enums\EstadoDte::Aceptado->value;
        $ccf->codigo_generacion = strtoupper((string) \Illuminate\Support\Str::uuid());
        $ccf->sello_recepcion = 'MOCK-SIMULADO-ABC123';
        $ccf->fecha_procesamiento_mh = null;
        $ccf->save();

        $nc = $this->borradores->crearNotaCredito($ccf->refresh(), ['tipo' => 'pronto_pago']);

        $this->assertTrue($this->noRealMh($this->validacion->validar($nc->refresh())));
    }

    public function test_permite_nc_si_ccf_aceptado_real_mh(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfBorradorCompleto($emisor)); // real: sello real + fecha_procesamiento_mh

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        // No debe aparecer el problema de "aceptado realmente" (el CCF es real).
        $this->assertFalse($this->noRealMh($this->validacion->validar($nc->refresh())));
    }
}
