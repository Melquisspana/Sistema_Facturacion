<?php

namespace Tests\Feature\Ppq;

use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqLote;
use App\Services\Ppq\PpqBusquedaService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusquedaPorEjercicioTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_exacto_no_elige_entre_ejercicios_y_la_lista_los_conserva(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $dtes = collect();
        // Dos ambientes permiten probar la ambigüedad sin modificar el esquema de dtes
        // ni depender de #125. Sin ambiente explícito se comparan ambos candidatos.
        foreach (['00' => '2026-12-20', '01' => '2027-01-15'] as $ambiente => $fecha) {
            $dtes->push(Dte::create([
                'establecimiento_id' => Establecimiento::firstOrFail()->id,
                'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => $ambiente,
                'numero_control' => 'DTE-03-M001P002-000000000000015',
                'codigo_generacion' => strtoupper((string) Str::uuid()),
                'fecha_emision' => $fecha, 'hora_emision' => '08:00:00', 'total_pagar' => 100,
            ]));
        }
        $busqueda = app(PpqBusquedaService::class);

        $this->assertNull($busqueda->buscarExacto('15', '03'));
        $this->assertNull($busqueda->buscarExacto('DTE-03-M001P002-000000000000015', '03'));
        $this->assertEqualsCanonicalizing($dtes->pluck('id')->all(), $busqueda->buscar(['numero_control' => '15', 'tipo' => '03'])->pluck('id')->all());

        $lote = PpqLote::create(['referencia' => 'Documento anterior', 'fecha' => '2026-12-20', 'estado' => 'borrador']);
        $lote->items()->create(['origen' => 'gmail', 'numero_control' => $dtes->first()->numero_control,
            'tipo_dte' => '03', 'fecha_documento' => '2026-12-20', 'monto_dte' => 100, 'sin_albaran' => true]);

        $this->assertSame([$dtes->first()->id => $lote->id], $busqueda->dtesYaUsados($dtes));
    }
}
