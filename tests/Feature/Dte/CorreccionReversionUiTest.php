<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sección "Corrección / reversión del documento" en la ficha (facturacion.show): las dos
 * tarjetas (Invalidación oficial / Revertir con NC) deben MOSTRARSE para cualquier CCF
 * aceptado cuando el usuario tiene permisos, aunque estén bloqueadas — deshabilitadas y con
 * razones, nunca ocultas. El gating REAL no se relaja: MOCK y "solo Aceptado" siguen
 * bloqueados; solo la aceptación real (APITEST o producción) habilita las acciones.
 */
class CorreccionReversionUiTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['00', '01'] as $amb) {
            Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => $amb, 'ultimo_numero' => 0, 'activo' => true]);
            Correlativo::create(['tipo_dte' => '05', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => $amb, 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    /** CCF GENERADO (una línea gravada 10 × 10) en el ambiente indicado. */
    private function ccfGenerado(string $ambiente): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'ambiente' => $ambiente,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($ccf);

        return $ccf->refresh();
    }

    /** CCF aceptado REALMENTE (sello real + fecha de procesamiento). */
    private function ccfRealAceptado(string $ambiente): Dte
    {
        return $this->aceptarCcf($this->ccfGenerado($ambiente));
    }

    /** CCF marcado Aceptado pero con sello MOCK (no real): no habilita las acciones. */
    private function ccfMockAceptado(string $ambiente): Dte
    {
        $ccf = $this->ccfRealAceptado($ambiente);
        $ccf->sello_recepcion = 'MOCK-SIMULADO-'.$ccf->id;
        $ccf->fecha_procesamiento_mh = null;
        $ccf->save();

        return $ccf->refresh();
    }

    /** Config para dejar los candados de la transmisión real ABIERTOS (apitest mockeado). */
    private function abrirCandados(): void
    {
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Resp', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Sol', 'tipo_doc' => '36', 'num_doc' => '06141101690011']);
    }

    public function test_ccf_mock_aceptado_muestra_ambas_tarjetas_deshabilitadas(): void
    {
        Http::fake();
        $ccf = $this->ccfMockAceptado('00');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            // Encabezado distingue MOCK de aceptación real.
            ->assertSee('Aceptación simulada en modo prueba')
            // Ambas tarjetas visibles (no ocultas).
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Revertir con nota de crédito')
            // Invalidación: deshabilitada con razones (incluye la de no aceptado realmente).
            ->assertSee('Botón deshabilitado')
            // Reversión: bloqueada (sin form real habilitado), con explicación.
            ->assertDontSee(route('facturacion.nota-credito.revertir', $ccf), false)
            ->assertSee('requiere un CCF con');
    }

    public function test_ccf_apitest_real_habilita_segun_candados(): void
    {
        Http::fake();
        $ccf = $this->ccfRealAceptado('00');
        $admin = $this->usuario('administrador');

        // Modo seguro (candados cerrados): reversión habilitada (guard = candidatura),
        // invalidación deshabilitada por candados del entorno.
        $this->actingAs($admin)
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Aceptado por Hacienda')
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Revertir con nota de crédito')
            ->assertSee(route('facturacion.nota-credito.revertir', $ccf), false) // reversión habilitada
            ->assertSee('Botón deshabilitado');                                   // invalidación bloqueada por candados

        // Con los candados abiertos, la invalidación pasa a habilitable (botón sin deshabilitar).
        $this->abrirCandados();
        $this->actingAs($admin)
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Transmitir invalidación a Hacienda')
            ->assertDontSee('Botón deshabilitado');
    }

    public function test_ccf_produccion_real_conserva_reglas(): void
    {
        Http::fake();
        $ccf = $this->ccfRealAceptado('01');

        // Aun abriendo los candados de apitest, producción NO se habilita (falta
        // produccion_enabled + endpoint productivo): la invalidación sigue deshabilitada.
        $this->abrirCandados();
        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Revertir con nota de crédito')
            ->assertSee(route('facturacion.nota-credito.revertir', $ccf), false) // reversión habilitada (aceptación real)
            ->assertSee('Botón deshabilitado');                                   // producción sigue candada
    }

    public function test_documento_no_aceptado_no_muestra_la_seccion(): void
    {
        Http::fake();
        $ccf = $this->ccfGenerado('00'); // generado, sin aceptar

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Corrección / reversión del documento')
            ->assertDontSee('Invalidación oficial (evento anulardte)');
    }
}
