<?php

namespace Tests\Feature\Alertas;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\DocumentosFiscalesFueraDeRegla;
use App\Services\Alertas\Hallazgo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Documentos de producción sin el sello de Hacienda o con la nota sobre un CCF invalidado. Datos inventados. */
class DocumentosFiscalesFueraDeReglaTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
    }

    private function dte(array $override): Dte
    {
        $this->n++;
        $empresa = Empresa::create(['razon_social' => 'Empresa '.$this->n, 'ambiente' => '01', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Caja', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE SECRETO SA']);
        $dte = (new Dte)->forceFill(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (300 + $this->n), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(), 'total_pagar' => 100,
            'sello_recepcion' => '2026SELLOREAL'.$this->n, 'fecha_procesamiento_mh' => now(),
        ], $override));
        $dte->save();

        return $dte;
    }

    private function revisar(): ?Hallazgo
    {
        Cache::flush();

        return app(DocumentosFiscalesFueraDeRegla::class)->revisar();
    }

    public function test_un_aceptado_real_no_avisa_y_el_detector_esta_en_el_avisador(): void
    {
        $this->dte([]);

        $detector = app(DocumentosFiscalesFueraDeRegla::class);
        $this->assertTrue($detector->aplica());
        $this->assertSame('dte_fuera_de_regla', $detector->tipo());
        $this->assertNull($this->revisar());
        $this->assertContains('dte_fuera_de_regla', array_map(fn ($d) => $d->tipo(), app(Avisador::class)->detectores()));
    }

    public function test_un_aceptado_sin_sello_real_de_produccion_avisa_y_los_de_prueba_o_viejos_no(): void
    {
        $this->dte(['fecha_procesamiento_mh' => null]);
        $this->dte(['sello_recepcion' => 'MOCK-123']);
        $this->dte(['sello_recepcion' => null]);
        $this->dte(['sello_recepcion' => null, 'ambiente' => '00']);
        $this->dte(['sello_recepcion' => null, 'fecha_emision' => now()->subDays(31)->toDateString()]);

        $h = $this->revisar();

        $this->assertSame(3, $h->conteo);
        $this->assertSame('3 documentos aceptados no tienen el sello de Hacienda', $h->titulo);
        $this->assertStringContainsString('301, 302, 303', $h->detalle);
        $this->assertStringContainsString('consulta pública de Hacienda', $h->queHacer);
        $this->assertSame('facturacion.index', $h->enlace);
        $this->assertStringNotContainsString('SECRETO', $h->titulo.$h->detalle.$h->queHacer);
        $this->assertStringNotContainsString('$', $h->titulo.$h->detalle.$h->queHacer);
    }

    public function test_un_invalidado_con_sello_real_y_sin_sello_de_invalidacion_avisa(): void
    {
        $this->dte(['estado' => EstadoDte::Invalidado->value, 'sello_invalidacion' => null, 'fecha_invalidacion' => now()->subDays(2)]);
        $this->dte(['estado' => EstadoDte::Invalidado->value, 'sello_invalidacion' => 'SELLOINV', 'fecha_invalidacion' => now()->subDays(2)]);
        $this->dte(['estado' => EstadoDte::Invalidado->value, 'sello_invalidacion' => null, 'fecha_invalidacion' => now()->subDays(40)]);

        $h = $this->revisar();

        $this->assertSame(1, $h->conteo);
        $this->assertSame('1 documento invalidado no tiene el sello de invalidación', $h->titulo);
        $this->assertStringContainsString('301', $h->detalle);
    }

    public function test_una_nota_aceptada_sobre_un_ccf_invalidado_hace_poco_avisa(): void
    {
        $ccf = $this->dte(['estado' => EstadoDte::Invalidado->value, 'sello_invalidacion' => 'SELLOINV', 'fecha_invalidacion' => now()->subDays(10)]);
        $this->dte(['tipo_dte' => TipoDte::NotaCredito->value, 'dte_relacionado_id' => $ccf->id,
            'numero_control' => 'DTE-05-M001P001-000000000000045']);

        $h = $this->revisar();

        $this->assertSame('1 nota sigue sobre un CCF invalidado', $h->titulo);
        $this->assertStringContainsString('45', $h->detalle);
        $this->assertStringContainsString('invalidarla o rehacerla', $h->queHacer);
    }

    public function test_el_resultado_se_guarda_una_hora(): void
    {
        $dte = $this->dte(['sello_recepcion' => null]);
        $detector = app(DocumentosFiscalesFueraDeRegla::class);
        $this->assertNotNull($detector->revisar());

        $dte->forceFill(['sello_recepcion' => '2026SELLOREAL'])->save();
        $this->assertNotNull($detector->revisar());
        $this->travel(61)->minutes();
        $this->assertNull($detector->revisar());
    }
}
