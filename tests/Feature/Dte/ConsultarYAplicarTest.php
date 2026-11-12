<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * LA CONSULTA A MANO TAMBIÉN PUEDE GUARDAR LO QUE HACIENDA DICE.
 *
 * `dte:consultar` solo mostraba la respuesta. Si un documento figuraba rechazado acá y
 * Hacienda lo tenía aceptado, no había con qué corregirlo. Con `--aplicar`, lo que
 * Hacienda contesta se guarda por el MISMO camino que la emisión: la misma regla del
 * sello, el mismo paso de la máquina de estados y el mismo candado por documento.
 */
class ConsultarYAplicarTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
        $this->habilitar();
    }

    /** Un documento que acá quedó rechazado. */
    private function rechazado(): Dte
    {
        $dte = $this->firmado();
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba', 'observaciones' => []], 400)]);
        $this->assertSame('rechazado', app(DteTransmisionService::class)->transmitir($dte)['resultado']);

        return $dte->refresh();
    }

    private function haciendaLoTieneAceptado(Dte $dte): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-CONSULTA', 'descripcionMsg' => 'RECIBIDO',
            'codigoGeneracion' => $dte->codigo_generacion,
        ], 200)]);
    }

    public function test_sin_pedirlo_la_consulta_sigue_siendo_de_solo_lectura(): void
    {
        $dte = $this->rechazado();
        $this->haciendaLoTieneAceptado($dte);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--consultar' => true])
            ->expectsOutputToContain('SOLO LECTURA');

        $this->assertSame(EstadoDte::Rechazado, $dte->fresh()->estado);
    }

    public function test_con_aplicar_un_rechazado_que_hacienda_tiene_aceptado_queda_aceptado(): void
    {
        $dte = $this->rechazado();
        $this->haciendaLoTieneAceptado($dte);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--consultar' => true, '--aplicar' => true])
            ->expectsOutputToContain('Estado guardado: Aceptado')
            ->assertExitCode(0);

        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertSame('SELLO-DE-PRUEBA-CONSULTA', $guardado->sello_recepcion);
        $this->assertTrue($guardado->respuesta_mh['aceptacion_tardia']);
        $this->assertTrue($this->candadoLibre($dte));
    }

    public function test_con_aplicar_y_el_documento_emitiendose_avisa_y_no_guarda_nada(): void
    {
        $dte = $this->rechazado();
        $this->haciendaLoTieneAceptado($dte);
        $this->otroPedidoEmitiendo($dte);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--consultar' => true, '--aplicar' => true])
            ->expectsOutputToContain(CandadoEmisionDte::OCUPADO)
            ->assertExitCode(1);

        $this->assertSame(EstadoDte::Rechazado, $dte->fresh()->estado);
    }

    public function test_aplicar_sin_consultar_de_verdad_no_guarda_nada(): void
    {
        $dte = $this->rechazado();
        $this->haciendaLoTieneAceptado($dte);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--aplicar' => true])
            ->expectsOutputToContain('SOLO VISTA PREVIA');

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Rechazado, $dte->fresh()->estado);
    }
}
