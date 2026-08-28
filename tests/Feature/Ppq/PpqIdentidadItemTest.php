<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Models\User;
use App\Services\Ppq\PpqBusquedaService;
use App\Support\IdentidadPpq;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * IDENTIDAD DE UN DOCUMENTO DENTRO DE PPQ — cuándo dos filas son el MISMO CCF/NC.
 *
 * EL HUECO QUE CIERRA, medido en la base real: hay 158 `ppq_items` y los 158 vienen del
 * barrido de Gmail con `dte_id` en NULL. Cuatro de los siete CCF locales elegibles ya
 * figuran ahí. Mientras el cruce se hiciera solo por `dte_id`, ninguno se encontraba: un
 * documento ya cobrado aparecía como si nunca hubiera entrado a un PPQ, y nada impedía
 * volver a agregarlo. Con la búsqueda local primero eso deja de ser un detalle —es el
 * camino por el que ahora entra el trabajo diario—.
 *
 * LA REGLA QUE SE EXIGE ACÁ ({@see IdentidadPpq}), en este orden:
 *   1. `dte_id` — vínculo explícito;
 *   2. `numero_control` NORMALIZADO — la llave que hace el trabajo real.
 *
 * Y lo que NUNCA es identidad: el correlativo suelto, la orden de compra, el monto y la
 * sala. Casar por cualquiera de esos daría por cobrado algo que nadie cobró, y hay
 * pruebas acá abajo que lo vigilan.
 *
 * La normalización NO es nueva: es la misma con la que PPQ ya cruza sus items contra el
 * TXT de pagos de Calleja (ConciliacionTxtParser::normalizarNumero()).
 */
class PpqIdentidadItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        PpqSala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    // ------------------------------------------------------------------ utilidades

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    private function lote(string $referencia = 'PPQ'): PpqLote
    {
        return PpqLote::create(['referencia' => $referencia, 'fecha' => now(), 'estado' => 'borrador']);
    }

    /** CCF local COBRABLE (producción, aceptado realmente por Hacienda). */
    private function ccf(string $numeroControl, string $oc = '260600232002345', float $monto = 113.58): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => $numeroControl,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => $oc,
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => $monto,
        ]);
    }

    /**
     * Item HISTÓRICO tal como están los 158 reales: snapshot de Gmail, `dte_id` en NULL.
     * Ese NULL es el punto de todas estas pruebas.
     */
    private function itemHistorico(PpqLote $lote, string $numeroControl, string $oc = '260600232002345'): PpqItem
    {
        return PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'dte_id' => null,
            'origen' => 'gmail',
            'numero_control' => $numeroControl,
            'tipo_dte' => '03',
            'numero_orden_compra' => $oc,
            'monto_dte' => 113.58,
            'sin_albaran' => true,
        ]);
    }

    /** Últimos 4 dígitos del correlativo, que es lo que se escribe al buscar. */
    private function correlativo(Dte $dte): string
    {
        $secuencia = substr((string) $dte->numero_control, strrpos((string) $dte->numero_control, '-') + 1);

        return str_pad(ltrim($secuencia, '0'), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ 1. detección

    public function test_un_dte_local_detecta_el_item_historico_de_gmail_con_dte_id_null(): void
    {
        $ccf = $this->ccf('DTE-03-M001P002-000000000000986');
        $lote = $this->lote('PPQ de junio');
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000986');

        // El item no tiene `dte_id`: solo el número de control puede emparejarlos.
        $this->assertNull(PpqItem::first()->dte_id);

        $mapa = app(PpqBusquedaService::class)->dtesYaUsados([$ccf]);

        $this->assertSame([$ccf->id => $lote->id], $mapa);
    }

    public function test_la_busqueda_avisa_que_ya_esta_en_un_lote(): void
    {
        $ccf = $this->ccf('DTE-03-M001P002-000000000000987');
        $lote = $this->lote('PPQ de junio');
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000987');

        $this->actingAs($this->usuario())
            ->get(route('ppq.index', ['q' => $this->correlativo($ccf)]))
            ->assertOk()
            ->assertSee('Ya está en el lote #'.$lote->id, false);
    }

    public function test_separadores_distintos_son_el_mismo_documento(): void
    {
        // El sistema escribe `DTE-03-…`; un histórico cargado a mano puede venir como
        // `DTE03…` o con otros separadores. Es el mismo CCF.
        $ccf = $this->ccf('DTE-03-M001P002-000000000000988');
        $lote = $this->lote();
        $this->itemHistorico($lote, 'dte03m001p002000000000000988');

        $this->assertSame(
            [$ccf->id => $lote->id],
            app(PpqBusquedaService::class)->dtesYaUsados([$ccf]),
        );

        // Y la normalización es la de PPQ, no una nueva.
        $this->assertSame(
            IdentidadPpq::normalizar('DTE-03-M001P002-000000000000988'),
            IdentidadPpq::normalizar('dte.03/m001p002_000000000000988'),
        );
    }

    // ------------------------------------------------------------------ 2. falsos positivos

    public function test_el_mismo_correlativo_en_p001_y_p002_no_se_confunde(): void
    {
        // Tras el cambio de punto de venta ambas series comparten numeración. El
        // correlativo 0989 existe en las dos y son documentos DISTINTOS.
        $p002 = $this->ccf('DTE-03-M001P002-000000000000989');
        $lote = $this->lote();
        $this->itemHistorico($lote, 'DTE-03-M001P001-000000000000989'); // el de Conta, ya cobrado

        $this->assertSame(
            [],
            app(PpqBusquedaService::class)->dtesYaUsados([$p002]),
            'Un P001 ya cobrado no puede dar por cobrado al P002 del mismo correlativo.',
        );
    }

    public function test_la_misma_orden_de_compra_no_produce_coincidencia_falsa(): void
    {
        // Una OC ampara varios CCF de la misma sala: no identifica a ninguno.
        $ccf = $this->ccf('DTE-03-M001P002-000000000000990', '260600232002345');
        $lote = $this->lote();
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000991', '260600232002345');

        $this->assertSame(
            [],
            app(PpqBusquedaService::class)->dtesYaUsados([$ccf]),
            'Compartir orden de compra no convierte a dos CCF en el mismo documento.',
        );
    }

    public function test_el_mismo_monto_y_la_misma_sala_no_producen_coincidencia_falsa(): void
    {
        $ccf = $this->ccf('DTE-03-M001P002-000000000000992', '260600232002345', 113.58);
        $lote = $this->lote();
        // Mismo monto, misma sala (misma OC), distinto documento.
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000993', '260600232002345');

        $this->assertSame([], app(PpqBusquedaService::class)->dtesYaUsados([$ccf]));
    }

    // ------------------------------------------------------------------ 3. alta al lote

    public function test_no_se_puede_agregar_dos_veces_al_mismo_lote_aunque_el_item_venga_de_gmail(): void
    {
        $ccf = $this->ccf('DTE-03-M001P002-000000000000994');
        $lote = $this->lote();
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000994'); // ya está, sin dte_id

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'ya está en este lote'));

        // Sigue habiendo UNO solo: no se cobró dos veces.
        $this->assertSame(1, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    public function test_al_agregar_avisa_si_el_documento_ya_esta_en_otro_lote(): void
    {
        $ccf = $this->ccf('DTE-03-M001P002-000000000000995');
        $viejo = $this->lote('PPQ de mayo');
        $nuevo = $this->lote('PPQ de junio');
        $this->itemHistorico($viejo, 'DTE-03-M001P002-000000000000995');

        // Entre lotes NO bloquea (se conserva el comportamiento), pero ahora sí avisa.
        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $nuevo), ['dte_id' => $ccf->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains((string) $m, 'ya estaba usado en el lote #'.$viejo->id));

        $this->assertDatabaseHas('ppq_items', ['ppq_lote_id' => $nuevo->id, 'dte_id' => $ccf->id]);
    }

    public function test_la_via_de_gmail_detecta_un_item_local_equivalente(): void
    {
        // El espejo del caso anterior: el documento entró por la vía LOCAL (con dte_id)
        // y ahora se intenta agregar el mismo desde Gmail. Es el mismo documento.
        $ccf = $this->ccf('DTE-03-M001P002-000000000000996');
        $lote = $this->lote();

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id, 'sin_albaran' => '1'])
            ->assertSessionHas('status');

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'origen' => 'gmail',
                'numero_control' => 'DTE-03-M001P002-000000000000996',
                'tipo_dte' => '03',
                'monto_dte' => '113.58',
                'sin_albaran' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'ya está en este lote'));

        $this->assertSame(1, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    public function test_la_via_de_gmail_tambien_cruza_con_separadores_distintos(): void
    {
        $lote = $this->lote();
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000997');

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'origen' => 'gmail',
                'numero_control' => 'DTE03M001P002000000000000997', // sin separadores
                'tipo_dte' => '03',
                'monto_dte' => '113.58',
                'sin_albaran' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'ya está en este lote'));

        $this->assertSame(1, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    public function test_un_documento_distinto_si_se_agrega(): void
    {
        // El control de que las pruebas de arriba no pasan por bloquearlo todo.
        $ccf = $this->ccf('DTE-03-M001P002-000000000000998');
        $lote = $this->lote();
        $this->itemHistorico($lote, 'DTE-03-M001P002-000000000000999');

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    // ------------------------------------------------------------------ 4. costo

    public function test_el_cruce_de_identidad_es_una_sola_consulta(): void
    {
        $lote = $this->lote();
        $dtes = [];
        foreach (range(1, 10) as $n) {
            $control = 'DTE-03-M001P002-'.str_pad((string) (2000 + $n), 15, '0', STR_PAD_LEFT);
            $dtes[] = $this->ccf($control, '26060023200'.$n);
            $this->itemHistorico($lote, $control, '26060023200'.$n);
        }

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $mapa = app(PpqBusquedaService::class)->dtesYaUsados($dtes);

        $this->assertCount(10, $mapa, 'Los diez deben detectarse.');
        $this->assertSame(1, $consultas, 'Diez documentos no pueden costar diez consultas.');
    }
}
