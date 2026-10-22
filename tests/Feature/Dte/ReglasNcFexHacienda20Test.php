<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\GeneracionException;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\ValidacionPreJsonService;
use App\Support\HoraNegocio;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Reglas de la Normativa 2.0, Anexo IV, para la NC y la FEX (issue #42, grupo C):
 *
 * - H7, campo 17.2 c: la NC se genera dentro de los 3 meses desde la fecha del CCF,
 *   «salvo excepciones legales». Decisión de Melqui: NO se bloquea; se AVISA y se pide
 *   confirmación con un motivo obligatorio, que queda en la auditoría. El candado vive
 *   en el servicio de generación, no solo en la pantalla.
 * - H9, campos 90.13, 91.13 y 101.2: las NC de un CCF no pueden acreditar más exento ni
 *   más no sujeto que el del CCF, ni sumar más IVA retenido que el retenido en el CCF.
 * - H10, campos 35-37 y 166-167: la FEX de servicios llevaría recinto, régimen e
 *   incoterms en null; el sistema no la emite (se exportan bienes) y lo dice.
 */
class ReglasNcFexHacienda20Test extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);

        $this->emisor = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->emisor['estab']->id,
                'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00',
                'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function producto(TipoImpuesto $impuesto = TipoImpuesto::Gravado, float $precio = 10): Producto
    {
        return Producto::factory()->create([
            'unidad_medida_id' => UnidadMedida::whereNotNull('codigo')->first()->id,
            'precio_unitario' => $precio,
            'tipo_impuesto' => $impuesto->value,
        ]);
    }

    /** CCF aceptado (gravado 20.00) con la fecha de emisión indicada. */
    private function ccfAceptado(?string $fechaEmision = null): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'actividad_economica_id' => ActividadEconomica::first()->id,
            'correo' => 'cliente@ejemplo.sv',
        ]);
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto(), cantidad: 2);
        $ccf = $this->aceptarCcf($ccf->refresh());

        if ($fechaEmision !== null) {
            // Documento ya aceptado: se ajusta la fecha en la base, fuera de las reglas de edición.
            DB::table('dtes')->where('id', $ccf->id)->update(['fecha_emision' => $fechaEmision]);
        }

        return $ccf->refresh();
    }

    /** NC por pronto pago de 5.00 sobre el CCF. */
    private function ncProntoPago(Dte $ccf): Dte
    {
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago'], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 5]);

        return $nc->refresh();
    }

    private function hoy(): CarbonInterface
    {
        return HoraNegocio::ahora()->startOfDay();
    }

    // ---------------------------------------------------------------- H7: plazo de 3 meses

    public function test_nc_dentro_de_los_tres_meses_se_genera_sin_motivo(): void
    {
        // Límite exacto: el CCF es de hace 3 meses justos.
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(3)->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);

        app(DteGeneracionService::class)->generar($nc, $this->usuario());

        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    public function test_nc_fuera_de_plazo_sin_motivo_no_se_genera(): void
    {
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(3)->subDay()->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);

        try {
            app(DteGeneracionService::class)->generar($nc, $this->usuario());
            $this->fail('Debió pedir el motivo de la excepción al plazo de 3 meses.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('3 meses', $e->getMessage());
        }

        $this->assertSame('borrador', $nc->refresh()->estado->value);
        $this->assertNull($nc->numero_control);
    }

    public function test_nc_fuera_de_plazo_con_motivo_se_genera_y_queda_en_auditoria(): void
    {
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(5)->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);
        $motivo = 'Devolución acordada por resolución del cliente, excepción legal documentada.';

        app(DteGeneracionService::class)->generar($nc, $this->usuario(), $motivo);

        $this->assertSame('generado', $nc->refresh()->estado->value);
        $registro = Activity::query()
            ->where('subject_type', $nc->getMorphClass())
            ->where('subject_id', $nc->id)
            ->get()
            ->first(fn (Activity $a) => $a->getExtraProperty('motivo_excepcion_plazo') === $motivo);
        $this->assertNotNull($registro, 'El motivo de la excepción debe quedar en la auditoría de la NC.');
    }

    public function test_motivo_en_blanco_no_cuenta_como_motivo(): void
    {
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(4)->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);

        $this->expectException(GeneracionException::class);
        app(DteGeneracionService::class)->generar($nc, $this->usuario(), '   ');
    }

    public function test_pantalla_avisa_y_pide_confirmacion_con_motivo(): void
    {
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(4)->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);
        $usuario = $this->usuario();

        // Sin confirmar: vuelve con el aviso y no genera.
        $this->actingAs($usuario)->post(route('facturacion.generar', $nc))->assertSessionHasErrors('generar');
        $this->assertSame('borrador', $nc->refresh()->estado->value);

        // Confirmando sin motivo: tampoco.
        $this->actingAs($usuario)->post(route('facturacion.generar', $nc), ['confirmar_plazo_nc' => 1, 'motivo_plazo_nc' => ''])
            ->assertSessionHasErrors();
        $this->assertSame('borrador', $nc->refresh()->estado->value);

        // Confirmando con motivo: genera.
        $this->actingAs($usuario)->post(route('facturacion.generar', $nc), [
            'confirmar_plazo_nc' => 1,
            'motivo_plazo_nc' => 'Excepción legal: acuerdo de devolución firmado con el cliente.',
        ])->assertRedirect();
        $this->assertSame('generado', $nc->refresh()->estado->value);
    }

    public function test_editor_de_la_nc_fuera_de_plazo_muestra_el_aviso(): void
    {
        $ccf = $this->ccfAceptado($this->hoy()->subMonthsNoOverflow(4)->format('Y-m-d'));
        $nc = $this->ncProntoPago($ccf);

        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('3 meses')
            ->assertSee('motivo_plazo_nc', false);
    }

    // ---------------------------------------------------------------- H9: topes de la NC

    private function superaSaldo(Dte $nc): bool
    {
        return (bool) collect(app(ValidacionPreJsonService::class)->validar($nc))
            ->first(fn ($p) => str_contains($p, 'El CCF ') && str_contains($p, 'menor que'));
    }

    public function test_nc_con_exento_cuando_el_ccf_no_tiene_exento_se_bloquea(): void
    {
        $ccf = $this->ccfAceptado(); // solo gravado 20.00, exento 0.00
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(TipoImpuesto::Exento, 5), 1);

        $this->assertTrue($this->superaSaldo($nc->refresh()));
    }

    public function test_nc_con_no_sujeto_cuando_el_ccf_no_tiene_no_sujeto_se_bloquea(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(TipoImpuesto::NoSujeto, 5), 1);

        $this->assertTrue($this->superaSaldo($nc->refresh()));
    }

    public function test_nc_gravada_dentro_del_saldo_sigue_pasando(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'averia']);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(), 1); // 10.00 ≤ 20.00

        $this->assertFalse($this->superaSaldo($nc->refresh()));
    }

    public function test_nc_no_puede_retener_mas_iva_que_el_retenido_en_el_ccf(): void
    {
        $ccf = $this->ccfAceptado(); // sin retención
        $this->assertSame('0.00', (string) $ccf->iva_retenido);
        $nc = $this->ncProntoPago($ccf);
        // La NC queda con IVA retenido aunque el CCF no retuvo nada.
        DB::table('dtes')->where('id', $nc->id)->update(['iva_retenido' => '0.05']);

        $problemas = app(ValidacionPreJsonService::class)->validar($nc->refresh());

        $this->assertTrue(
            (bool) collect($problemas)->first(fn ($p) => str_contains($p, 'IVA retenido')),
            'Debe frenar una NC que retiene más IVA que el CCF: '.implode(' | ', $problemas)
        );
    }

    // ---------------------------------------------------------------- H10: FEX de servicios

    public function test_no_se_crea_una_fex_de_servicios(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario())->post(route('facturacion.store-exportacion'), [
            'tipo_dte' => '11',
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
            'condicion_operacion' => 1,
            'flete' => 0,
            'seguro' => 0,
            'tipo_item_expor' => 2,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ])->assertSessionHasErrors('tipo_item_expor');

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_fex_de_servicios_ya_guardada_no_pasa_la_validacion_previa(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        $this->borradores->agregarLineaDesdeProducto($fex, $this->producto(), cantidad: 1);
        DB::table('dtes')->where('id', $fex->id)->update(['tipo_item_expor' => 2]);

        $problemas = app(ValidacionPreJsonService::class)->validar($fex->refresh());

        $this->assertTrue((bool) collect($problemas)->first(fn ($p) => str_contains($p, 'servicios')));
    }
}
