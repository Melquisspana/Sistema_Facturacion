<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Services\Ppq\PpqBusquedaService;
use App\Support\PpqElegibilidad;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * IDENTIDAD del documento en el buscador exacto de PPQ.
 *
 * Cubre las tres procedencias que conviven en la misma tabla y que la búsqueda tiene
 * que tratar igual de bien:
 *
 *   · CCF emitidos por ESTE sistema (P002, con su número de control y su sello);
 *   · CCF HEREDADOS de Conta (P001), registrados localmente pero emitidos por el
 *     sistema viejo — hoy son 74 de los 111 documentos cobrables del entorno real;
 *   · documentos que YA pertenecen a un lote PPQ, que no deben ofrecerse como si
 *     estuvieran libres.
 *
 * Y la separación de ambientes, que es la que impide que un documento de pruebas se
 * haga pasar por uno real solo porque comparte correlativo.
 */
class PpqBusquedaIdentidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
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
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    private function servicio(): PpqBusquedaService
    {
        return app(PpqBusquedaService::class);
    }

    // ------------------------------------------------- 1 y 2. Sistema y Conta

    public function test_encuentra_un_ccf_emitido_por_el_sistema(): void
    {
        $dte = $this->dte('DTE-03-M001P002-000000000001120');

        $this->assertSame($dte->id, $this->servicio()->buscarExacto('1120', '03')?->id);
        $this->assertSame($dte->id, $this->servicio()->buscarExacto('DTE-03-M001P002-000000000001120', '03')?->id);
    }

    /**
     * Los heredados de Conta viven en P001. Se buscan igual: el número de control es lo
     * único que ambos caminos comparten.
     */
    public function test_encuentra_un_ccf_heredado_de_conta(): void
    {
        $dte = $this->dte('DTE-03-M001P001-000000000000777');

        $this->assertSame($dte->id, $this->servicio()->buscarExacto('777', '03')?->id);
        $this->assertSame($dte->id, $this->servicio()->buscarExacto('DTE-03-M001P001-000000000000777', '03')?->id);
    }

    // ------------------------------------------------- 3. Variaciones de formato

    /**
     * Las variaciones SEGURAS del mismo número resuelven al mismo documento: ceros a la
     * izquierda del correlativo, y separadores del número de control.
     */
    public function test_variaciones_seguras_de_formato_resuelven_al_mismo_documento(): void
    {
        $dte = $this->dte('DTE-03-M001P002-000000000000986');

        foreach ([
            '986',
            '0986',
            '000986',
            'DTE-03-M001P002-000000000000986',
            'DTE03M001P002000000000000986',
            'dte-03-m001p002-000000000000986',
            '  DTE-03-M001P002-000000000000986  ',
        ] as $variante) {
            $this->assertSame(
                $dte->id,
                $this->servicio()->buscarExacto($variante, '03')?->id,
                "La variante «{$variante}» debía resolver al mismo documento."
            );
        }
    }

    /** Si el mismo correlativo existe en P001 y P002, gana el vigente (P002). */
    public function test_el_correlativo_repetido_resuelve_al_punto_de_venta_vigente(): void
    {
        $viejo = $this->dte('DTE-03-M001P001-000000000000986');
        $nuevo = $this->dte('DTE-03-M001P002-000000000000986');

        $this->assertSame($nuevo->id, $this->servicio()->buscarExacto('986', '03')?->id);

        // Pero pidiéndolo por su número completo, el histórico se sigue pudiendo alcanzar.
        $this->assertSame($viejo->id, $this->servicio()->buscarExacto('DTE-03-M001P001-000000000000986', '03')?->id);
    }

    // ------------------------------------------------- 6. Ya está en un lote PPQ

    /**
     * Un documento ya cobrado se reconoce por su número de control NORMALIZADO: los 158
     * items reales vienen del barrido de Gmail y NINGUNO tiene `dte_id`, así que cruzar
     * solo por el vínculo no encontraría ninguno.
     */
    public function test_reconoce_un_documento_que_ya_esta_en_un_lote(): void
    {
        $dte = $this->dte('DTE-03-M001P002-000000000001120');

        $lote = PpqLote::create([
            'referencia' => 'PPQ semana 25',
            'fecha' => now()->toDateString(),
            'estado' => 'borrador',
            'cliente_id' => $this->cliente()->id,
        ]);
        PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'dte_id' => null, // como los items reales: sin vínculo
            'numero_control' => 'DTE-03-M001P002-000000000001120',
            'origen' => 'gmail',
        ]);

        $encontrado = $this->servicio()->buscarExacto('1120', '03');
        $this->assertSame($dte->id, $encontrado?->id);

        $usados = $this->servicio()->dtesYaUsados([$encontrado]);
        $this->assertSame($lote->id, $usados[$dte->id] ?? null, 'Debe saberse en qué lote está.');
    }

    // ------------------------------------------------- 11. Ambientes separados

    /**
     * Un documento de PRUEBAS no puede desplazar a uno de PRODUCCIÓN cuando comparten
     * correlativo: desde que los correlativos de ambos ambientes cuentan por separado,
     * el mismo número existe en los dos, y devolver el simulado dejaría cobrar algo que
     * ante Hacienda no existe.
     *
     * El desempate lo da la VIGENCIA FISCAL, no el ambiente configurado: es la misma
     * regla que ya usa IdentidadPpq::dteLocal() para resolver un número a una fila.
     */
    public function test_un_documento_de_pruebas_no_desplaza_a_uno_de_produccion(): void
    {
        $pruebas = $this->dte('DTE-03-M001P002-000000000000500', [
            'ambiente' => '00',
            'sello_recepcion' => 'MOCK-SIMULADO',
            'fecha_procesamiento_mh' => null,
        ]);
        $produccion = $this->dte('DTE-03-M001P002-000000000000500', ['ambiente' => '01']);

        foreach (['500', 'DTE-03-M001P002-000000000000500'] as $termino) {
            $this->assertSame(
                $produccion->id,
                $this->servicio()->buscarExacto($termino, '03')?->id,
                "Buscando «{$termino}» debe ganar el documento de producción."
            );
        }

        $this->assertNotNull($pruebas->id);
    }

    /**
     * Un documento de pruebas que es el ÚNICO con ese número SÍ se encuentra: la pantalla
     * lo muestra bloqueado. Esconderlo mentiría sobre lo que existe y dejaría a quien
     * busca sin entender por qué «no aparece» algo que sí está cargado.
     */
    public function test_un_documento_de_pruebas_solo_se_encuentra_pero_no_es_cobrable(): void
    {
        $pruebas = $this->dte('DTE-03-M001P002-000000000000601', [
            'ambiente' => '00',
            'sello_recepcion' => 'MOCK-SIMULADO',
            'fecha_procesamiento_mh' => null,
        ]);

        $encontrado = $this->servicio()->buscarExacto('601', '03');

        $this->assertSame($pruebas->id, $encontrado?->id);
        $this->assertNotNull(
            PpqElegibilidad::motivo($encontrado),
            'Se encuentra, pero con un motivo que impide cobrarlo.'
        );
    }

    // ------------------------------------------------- tipo

    /** El buscador respeta el tipo: buscando CCF no aparece una NC del mismo correlativo. */
    public function test_respeta_el_tipo_de_documento(): void
    {
        $ccf = $this->dte('DTE-03-M001P002-000000000000300');
        $nc = $this->dte('DTE-05-M001P002-000000000000300', ['tipo_dte' => '05']);

        $this->assertSame($ccf->id, $this->servicio()->buscarExacto('300', '03')?->id);
        $this->assertSame($nc->id, $this->servicio()->buscarExacto('300', '05')?->id);
    }

    /** Un archivado está fuera de la operación: no se ofrece para cobrar. */
    public function test_un_archivado_no_aparece(): void
    {
        $this->dte('DTE-03-M001P002-000000000000400', ['estado' => 'rechazado', 'archivado' => true]);

        $this->assertNull($this->servicio()->buscarExacto('400', '03'));
    }

    // ------------------------------------------------- 12. Avanzada paginada

    /**
     * La avanzada SÍ devuelve varios y pagina. Es su razón de ser: se pide a propósito,
     * y por eso está separada del buscador exacto.
     */
    public function test_la_busqueda_avanzada_devuelve_varios_y_pagina(): void
    {
        foreach (range(1, 7) as $n) {
            $this->dte('DTE-03-M001P002-'.str_pad((string) (900 + $n), 15, '0', STR_PAD_LEFT), [
                'numero_orden_compra' => '260600232009999',
            ]);
        }

        $pagina = $this->servicio()->buscar(['oc' => '260600232009999', 'tipo' => '03'], porPagina: 3);

        $this->assertSame(7, $pagina->total());
        $this->assertCount(3, $pagina->items());
        $this->assertSame(3, $pagina->lastPage());
    }

    /** Y ordena por fecha reciente. */
    public function test_la_busqueda_avanzada_ordena_por_fecha_reciente(): void
    {
        $viejo = $this->dte('DTE-03-M001P002-000000000000801', [
            'numero_orden_compra' => '260600232008888', 'fecha_emision' => now()->subDays(10),
        ]);
        $nuevo = $this->dte('DTE-03-M001P002-000000000000802', [
            'numero_orden_compra' => '260600232008888', 'fecha_emision' => now(),
        ]);

        $ids = $this->servicio()->buscar(['oc' => '260600232008888', 'tipo' => '03'])->pluck('id')->all();

        $this->assertSame([$nuevo->id, $viejo->id], $ids);
    }

    /** La avanzada por código de generación es EXACTA, no por subcadena. */
    public function test_la_avanzada_busca_el_codigo_de_generacion_exacto(): void
    {
        $uuid = strtoupper((string) Str::uuid());
        $buscado = $this->dte('DTE-03-M001P002-000000000000701', ['codigo_generacion' => $uuid]);
        $this->dte('DTE-03-M001P002-000000000000702');

        $ids = $this->servicio()->buscar(['codigo_generacion' => $uuid, 'tipo' => '03'])->pluck('id')->all();
        $this->assertSame([$buscado->id], $ids);

        // Un fragmento del UUID no devuelve nada: no es el documento.
        $this->assertSame(
            [],
            $this->servicio()->buscar(['codigo_generacion' => substr($uuid, 0, 8), 'tipo' => '03'])->pluck('id')->all()
        );
    }
}
