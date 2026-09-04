<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Services\Ppq\PpqBusquedaService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDITORÍA de por qué el buscador de PPQ devolvía documentos ajenos.
 *
 * Estas pruebas se escribieron ANTES de tocar el servicio, para dejar el defecto
 * documentado con datos en vez de con una sospecha. Lo que reproducen:
 *
 * Al escribir un correlativo, el servicio hacía coincidencia EXACTA contra
 * `numero_control` —eso estaba bien— pero la unía con OR a tres subcadenas:
 *
 *     correlativoExacto(numero_control)
 *     OR codigo_generacion  LIKE %1120%
 *     OR sello_recepcion    LIKE %1120%
 *     OR numero_orden_compra LIKE %1120%
 *
 * `codigo_generacion` es un UUID de 36 caracteres y `sello_recepcion` una cadena
 * larga y aleatoria. Buscar cuatro dígitos DENTRO de ellos acierta por puro azar:
 * con suficientes documentos, casi cualquier correlativo aparece incrustado en
 * algún UUID o en algún sello. De ahí salían los 5 a 8 resultados «parecidos»
 * que no tenían ninguna relación con lo buscado.
 *
 * La orden de compra agrega lo suyo: una sola OC ampara varios CCF de la misma
 * sala, así que casar por subcadena de OC arrastra documentos hermanos.
 */
class PpqBusquedaExactaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function cliente(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    private function dte(string $numeroControl, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => config('dte.ambiente'),
            'cliente_id' => $this->cliente()->id,
            'numero_control' => $numeroControl,
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    /** Ids que devuelve el buscador PRINCIPAL (0 o 1). */
    private function idsDe(array $filtros): array
    {
        $dte = app(PpqBusquedaService::class)->buscarExacto((string) ($filtros['q'] ?? ''), '03');

        return $dte === null ? [] : [$dte->id];
    }

    /**
     * EL DEFECTO, con datos: un correlativo que además aparece DENTRO del UUID de
     * otro documento arrastra a ese otro documento a los resultados.
     */
    public function test_un_correlativo_no_debe_arrastrar_documentos_por_su_codigo_de_generacion(): void
    {
        $buscado = $this->dte('DTE-03-M001P002-000000000001120');

        // Otro documento, sin relación: su UUID contiene «1120» por casualidad.
        $ajeno = $this->dte('DTE-03-M001P002-000000000009999', [
            'codigo_generacion' => 'AAAAAAAA-1120-4AAA-BBBB-CCCCCCCCCCCC',
        ]);

        $ids = $this->idsDe(['q' => '1120']);

        $this->assertContains($buscado->id, $ids);
        $this->assertNotContains(
            $ajeno->id,
            $ids,
            'Un código de generación que contiene el correlativo por azar no convierte a ese documento en un resultado.'
        );
    }

    /** Lo mismo con el sello de recepción, que también es una cadena larga y aleatoria. */
    public function test_un_correlativo_no_debe_arrastrar_documentos_por_su_sello(): void
    {
        $buscado = $this->dte('DTE-03-M001P002-000000000001120');
        $ajeno = $this->dte('DTE-03-M001P002-000000000008888', [
            'sello_recepcion' => '2026ABCD1120EFGH',
        ]);

        $ids = $this->idsDe(['q' => '1120']);

        $this->assertContains($buscado->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    /**
     * Una orden de compra ampara VARIOS CCF de la misma sala. Casar el número
     * buscado contra la OC por subcadena arrastra a todos los hermanos.
     */
    public function test_un_correlativo_no_debe_arrastrar_los_hermanos_de_la_misma_orden_de_compra(): void
    {
        $buscado = $this->dte('DTE-03-M001P002-000000000001120', [
            'numero_orden_compra' => '260600232001120',
        ]);
        $hermano = $this->dte('DTE-03-M001P002-000000000007777', [
            'numero_orden_compra' => '260600232001120',
        ]);

        $ids = $this->idsDe(['q' => '1120']);

        $this->assertContains($buscado->id, $ids);
        $this->assertNotContains(
            $hermano->id,
            $ids,
            'La OC no identifica un documento: ampara varios, y no puede convertirlos a todos en resultado.'
        );
    }

    /** Dos correlativos parecidos: solo coincide el exacto. */
    public function test_dos_numeros_parecidos_solo_coincide_el_exacto(): void
    {
        $exacto = $this->dte('DTE-03-M001P002-000000000001120');
        $parecido = $this->dte('DTE-03-M001P002-000000000011201');
        $otro = $this->dte('DTE-03-M001P002-000000000001121');

        $ids = $this->idsDe(['q' => '1120']);

        $this->assertSame([$exacto->id], $ids);
        $this->assertNotContains($parecido->id, $ids);
        $this->assertNotContains($otro->id, $ids);
    }

    /** Un número PARCIAL no debe producir resultados: no es el documento. */
    public function test_un_numero_parcial_no_produce_resultados(): void
    {
        $this->dte('DTE-03-M001P002-000000000001120');

        // «112» es un prefijo de 1120, no un correlativo existente.
        $this->assertSame([], $this->idsDe(['q' => '112']));
    }
}
