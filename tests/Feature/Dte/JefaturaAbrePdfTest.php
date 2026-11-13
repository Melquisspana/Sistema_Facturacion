<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Jefatura (12/11/2026): en Documentos fiscales, tocar un documento abre su PDF y no la
 * ficha de datos. Si todavía está en edición no hay PDF que mostrar: sale el aviso. Si
 * entra a la ficha por la URL, la manda al PDF (o al aviso). Los demás roles, igual.
 */
class JefaturaAbrePdfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        }
        $this->emisor = compact('estab', 'pv');
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function ccf(bool $generado): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
        ]);
        if ($generado) {
            $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
            app(DteGeneracionService::class)->generar($dte);
        }

        return $dte->refresh();
    }

    public function test_jefatura_ve_en_la_lista_el_enlace_al_pdf_y_no_a_la_ficha(): void
    {
        $dte = $this->ccf(generado: true);

        $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee(route('facturacion.pdf', $dte), false)
            ->assertDontSee('href="'.route('facturacion.show', $dte).'"', false);
    }

    public function test_jefatura_ve_que_un_borrador_todavia_no_esta_listo(): void
    {
        $dte = $this->ccf(generado: false);

        $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Todavía no está listo')
            ->assertDontSee(route('facturacion.pdf', $dte), false)
            ->assertDontSee('href="'.route('facturacion.show', $dte).'"', false);
    }

    public function test_jefatura_que_entra_a_la_ficha_va_al_pdf(): void
    {
        $dte = $this->ccf(generado: true);

        $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.show', $dte))
            ->assertRedirect(route('facturacion.pdf', $dte));
    }

    public function test_jefatura_que_entra_a_la_ficha_de_un_borrador_ve_el_aviso(): void
    {
        $dte = $this->ccf(generado: false);
        $jefatura = $this->usuario('jefatura');

        $this->actingAs($jefatura)->get(route('facturacion.show', $dte))
            ->assertRedirect(route('facturacion.index'))
            ->assertSessionHas('error');

        $this->actingAs($jefatura)->followingRedirects()->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('todavía no está listo');
    }

    public function test_jefatura_abre_el_pdf_en_linea(): void
    {
        $dte = $this->ccf(generado: true);

        $respuesta = $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.pdf', $dte));

        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $respuesta->headers->get('Content-Disposition'));
    }

    public function test_los_demas_roles_siguen_entrando_a_la_ficha(): void
    {
        $dte = $this->ccf(generado: true);

        foreach (['administrador', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('facturacion.show', $dte))->assertOk();
            $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))
                ->assertOk()
                ->assertSee('href="'.route('facturacion.show', $dte).'"', false);
        }
    }
}
