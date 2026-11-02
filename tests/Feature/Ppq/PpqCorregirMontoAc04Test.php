<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqAlbaran;
use App\Services\Ppq\AlbaranParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PpqCorregirMontoAc04Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['dte.storage.disk' => 'local']);
        $this->app->instance(AlbaranParser::class, new class extends AlbaranParser
        {
            public function desdePdf(string $pdfBytes): array
            {
                return $this->desdeTexto($pdfBytes);
            }
        });
    }

    private function nota(): PpqAlbaran
    {
        Storage::disk('local')->put('ac04-ficticia.pdf', file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt')));

        return PpqAlbaran::create([
            'numero_albaran' => 'AC04/0148/00/8427', 'tipo_codigo' => 'AC04',
            'monto_albaran' => '2.37', 'archivo_path' => 'ac04-ficticia.pdf',
        ]);
    }

    public function test_en_seco_muestra_los_montos_sin_escribir(): void
    {
        $nota = $this->nota();
        $this->artisan('ppq:corregir-monto-ac04')->expectsOutputToContain('En seco')
            ->expectsOutputToContain('AC04/0148/00/8427: 2.37 → 2.10')->assertSuccessful();
        $this->assertSame('2.37', $nota->refresh()->monto_albaran);
    }

    public function test_aplica_solo_ac04_y_es_idempotente(): void
    {
        $nota = $this->nota();
        $ac01 = PpqAlbaran::create(['numero_albaran' => 'AC01/0148/00/9427', 'tipo_codigo' => 'AC01',
            'monto_albaran' => '50.00', 'archivo_path' => $nota->archivo_path]);
        $this->artisan('ppq:corregir-monto-ac04', ['--aplicar' => true])
            ->expectsOutputToContain('Corregidas: 1.')->assertSuccessful();
        $this->assertSame('2.10', $nota->refresh()->monto_albaran);
        $fecha = $nota->updated_at->toDateTimeString();
        $this->travel(1)->minutes();
        $this->artisan('ppq:corregir-monto-ac04', ['--aplicar' => true])
            ->expectsOutputToContain('Corregidas: 0.')->assertSuccessful();
        $this->assertSame($fecha, $nota->refresh()->updated_at->toDateTimeString());
        $this->assertSame('50.00', $ac01->refresh()->monto_albaran);
    }

    public function test_pdf_ilegible_o_ausente_se_lista_sin_modificar_el_monto(): void
    {
        $nota = $this->nota();
        Storage::disk('local')->put($nota->archivo_path, 'Documento ilegible');
        $this->artisan('ppq:corregir-monto-ac04', ['--aplicar' => true])
            ->expectsOutputToContain('PDF ilegible')->expectsOutputToContain('No legibles: 1.')->assertSuccessful();
        $this->assertSame('2.37', $nota->refresh()->monto_albaran);
        Storage::disk('local')->delete($nota->archivo_path);
        $this->artisan('ppq:corregir-monto-ac04', ['--aplicar' => true])
            ->expectsOutputToContain('No legibles: 1.')->assertSuccessful();
        $this->assertSame('2.37', $nota->refresh()->monto_albaran);
    }
}
