<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\MotivoAnulacion;
use App\Exceptions\Dte\AnulacionException;
use App\Models\Dte;
use App\Services\Dte\DteAnulacionService;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * LA ANULACIÓN INTERNA NO COMPITE CON LA EMISIÓN.
 *
 * Anular un documento generado no tomaba el candado del documento ni volvía a leer la
 * fila: un pedido podía anularlo con lo que había cargado mientras otro lo firmaba y lo
 * transmitía, y quedaba «Invalidado» acá un documento que Hacienda aceptó.
 *
 * Por pasos, porque SQLite no reproduce dos procesos: el segundo pedido encuentra el
 * candado tomado, y una copia vieja no anula un documento que ya avanzó.
 */
class AnulacionInternaConCandadoTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
    }

    /** @return array<string, string> */
    private function motivo(): array
    {
        return ['motivo_anulacion' => MotivoAnulacion::ErrorMonto->value, 'observacion_anulacion' => 'Importe mal cargado.'];
    }

    public function test_anular_con_el_documento_emitiendose_avisa_y_no_lo_toca(): void
    {
        $dte = $this->generado();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.anular', $dte), $this->motivo())
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHasErrors(['anular' => CandadoEmisionDte::OCUPADO]);

        $this->assertSame(EstadoDte::Generado, $dte->fresh()->estado);
        $this->assertNull($dte->fresh()->motivo_anulacion);
    }

    public function test_una_copia_vieja_no_anula_un_documento_que_ya_se_firmo(): void
    {
        $dte = $this->generado();
        // Este pedido cargó el documento cuando todavía estaba generado…
        $copiaVieja = Dte::findOrFail($dte->id);
        // …y mientras tanto otro lo firmó.
        Storage::disk('local')->put('dte/firmados/otro-pedido.jws', 'eyJ.fake.jws.compacta');
        Dte::whereKey($dte->id)->update(['estado' => EstadoDte::Firmado->value, 'json_firmado_path' => 'dte/firmados/otro-pedido.jws']);

        try {
            app(DteAnulacionService::class)->anular($copiaVieja, MotivoAnulacion::ErrorMonto, 'Importe mal cargado.');
            $this->fail('No se anula por dentro un documento que ya está firmado.');
        } catch (AnulacionException $e) {
            $this->assertStringContainsString('Solo se puede anular un documento generado', $e->getMessage());
        }

        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Firmado, $guardado->estado);
        $this->assertNull($guardado->motivo_anulacion);
        $this->assertSame(0, $guardado->historial()->where('estado_nuevo', 'invalidado')->count());
    }

    public function test_anular_un_documento_generado_sigue_funcionando_y_deja_el_candado_libre(): void
    {
        $dte = $this->generado();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.anular', $dte), $this->motivo())
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('status');

        $this->assertSame(EstadoDte::Invalidado, $dte->fresh()->estado);
        $this->assertSame(MotivoAnulacion::ErrorMonto->value, $dte->fresh()->getRawOriginal('motivo_anulacion'));
        $this->assertTrue($this->candadoLibre($dte));
    }
}
