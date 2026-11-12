<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * EL JSON QUE SE GENERA AL ENVIAR UN CORREO VA BAJO EL MISMO CANDADO.
 *
 * Un documento viejo, generado y sin JSON, lo recibe de quien lo necesite primero: el
 * correo o la emisión. Sin candado, «enviar correo» y «firmar y transmitir» a la vez
 * podían generar los dos el archivo y dejar distintos el JSON, la firma y la fila.
 */
class JsonAlEnviarCorreoConCandadoTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
        Queue::fake();
    }

    /** Un documento de antes: generado, con su numeración, y sin el archivo JSON. */
    private function generadoSinJson(): Dte
    {
        $dte = $this->generado();
        Dte::whereKey($dte->id)->update(['json_generado_path' => null]);

        return $dte->refresh();
    }

    public function test_el_correo_rapido_con_el_documento_emitiendose_avisa_y_no_genera_el_json(): void
    {
        $dte = $this->generadoSinJson();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.correo.cliente', $dte))
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('error', CandadoEmisionDte::OCUPADO);

        $this->assertNull($dte->fresh()->json_generado_path);
        $this->assertSame(0, DteEnvio::count());
    }

    public function test_el_correo_con_destinatarios_con_el_documento_emitiendose_avisa_y_no_genera_el_json(): void
    {
        $dte = $this->generadoSinJson();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'cliente@ejemplo.test'])
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHasErrors(['destinatarios' => CandadoEmisionDte::OCUPADO]);

        $this->assertNull($dte->fresh()->json_generado_path);
        $this->assertSame(0, DteEnvio::count());
    }

    public function test_sin_nadie_emitiendo_el_correo_genera_el_json_que_faltaba_y_suelta_el_candado(): void
    {
        $dte = $this->generadoSinJson();
        $codigo = $dte->codigo_generacion;

        $this->actingAs($this->usuario())
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.correo.cliente', $dte))
            ->assertSessionHas('status');

        $guardado = $dte->fresh();
        $this->assertNotNull($guardado->json_generado_path);
        $this->assertSame($codigo, $guardado->codigo_generacion);
        $this->assertSame(1, DteEnvio::count());
        $this->assertTrue($this->candadoLibre($dte));
    }

    public function test_un_documento_que_ya_tiene_json_envia_el_correo_aunque_se_este_emitiendo(): void
    {
        // Con el JSON ya guardado el correo no genera nada: no necesita el candado.
        $dte = $this->generado();
        $this->otroPedidoEmitiendo($dte);

        $this->actingAs($this->usuario())
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.correo.cliente', $dte))
            ->assertSessionHas('status');

        $this->assertSame(1, DteEnvio::count());
    }

    public function test_firmar_y_transmitir_un_documento_sin_json_no_se_frena_con_su_propio_candado(): void
    {
        $this->habilitar();
        config()->set('dte.transmision.mock', true);
        $dte = $this->generadoSinJson();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertNotNull($guardado->json_generado_path);
    }
}
