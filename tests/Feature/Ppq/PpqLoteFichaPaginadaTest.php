<?php

namespace Tests\Feature\Ppq;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Services\Ppq\FichaLotePpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ficha de un lote PPQ grande: páginas de 25 en el ORDEN DEL EXCEL (CCF antes que NC,
 * correlativo ascendente, id), sin pérdidas ni duplicados, con los totales del lote
 * completo en cada página. El Excel sigue llevando todo el lote.
 */
class PpqLoteFichaPaginadaTest extends TestCase
{
    use RefreshDatabase;

    private const OC = '26050230001794';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Jefatura->value);
    }

    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'PPQ grande', 'fecha' => now(), 'estado' => 'borrador']);
    }

    private function item(PpqLote $lote, string $tipo, ?string $control, float $monto, ?PpqAlbaran $albaran = null): PpqItem
    {
        return $lote->items()->create([
            'origen' => 'gmail',
            'tipo_dte' => $tipo,
            'numero_control' => $control,
            'numero_orden_compra' => self::OC,
            'monto_dte' => $monto,
            'ppq_albaran_id' => $albaran?->id,
            'monto_albaran' => $albaran?->monto_albaran,
            'sin_albaran' => $albaran === null,
        ]);
    }

    /**
     * 45 documentos creados DESORDENADOS: CCF y NC intercalados, correlativos al revés,
     * un control sin dígitos (cuenta como 0), dos CCF con el mismo correlativo en P001 y
     * P002 (desempata el id) y algunos con albarán, uno de otra sala.
     */
    private function loteGrande(): PpqLote
    {
        $lote = $this->lote();

        foreach (range(40, 1) as $n) {
            $tipo = $n % 4 === 0 ? '05' : '03';
            $albaran = $n % 5 === 0 ? PpqAlbaran::create([
                'numero_albaran' => 'AC01/0230/00/'.$n,
                'numero_orden_compra' => self::OC,
                'monto_albaran' => 10.00,
                'origen' => 'gmail',
            ]) : null;
            $this->item($lote, $tipo, 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT), 10.00 + $n, $albaran);
        }

        $this->item($lote, '03', 'SIN-NUMERO', 5.00);
        $this->item($lote, '03', 'DTE-03-M001P001-000000000000986', 1.00);
        $this->item($lote, '03', 'DTE-03-M001P002-000000000000986', 2.00);
        $this->item($lote, '05', 'DTE-05-M001P002-000000000000002', 3.00);
        $otraSala = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0999/00/77', 'numero_orden_compra' => self::OC, 'monto_albaran' => 9.00, 'origen' => 'gmail',
        ]);
        $this->item($lote, '03', 'DTE-03-M001P002-000000000000077', 9.00, $otraSala);

        return $lote->fresh();
    }

    private function ficha(PpqLote $lote, array $params = [])
    {
        return $this->actingAs($this->usuario())->get(route('ppq.lotes.show', ['lote' => $lote] + $params));
    }

    public function test_las_paginas_muestran_los_mas_recientes_primero_sin_perder_ni_repetir(): void
    {
        $lote = $this->loteGrande();

        $vistos = [];
        foreach ([1, 2] as $pagina) {
            $items = $this->ficha($lote, ['page' => $pagina])->assertOk()->viewData('items');
            $this->assertSame($pagina, $items->currentPage());
            $this->assertSame(45, $items->total());
            $vistos = array_merge($vistos, $items->pluck('id')->all());
        }

        $this->assertCount(45, array_unique($vistos), 'Ningún documento repetido.');
        $this->assertEqualsCanonicalizing($lote->itemsOrdenados()->pluck('id')->all(), $vistos, 'Mismo conjunto que el Excel.');

        // Todos los CCF antes que cualquier NC, y correlativo DESCENDENTE en cada grupo.
        $orden = PpqItem::whereIn('id', $vistos)->get()->sortBy(fn ($i) => array_search($i->id, $vistos, true))->values();
        $tipos = $orden->map->ordenTipo()->all();
        $this->assertSame($tipos, collect($tipos)->sort()->values()->all(), 'CCF primero, NC después.');
        foreach ([0, 1] as $grupo) {
            $corr = $orden->filter(fn ($i) => $i->ordenTipo() === $grupo)->map->correlativoNumero()->values()->all();
            $this->assertSame($corr, collect($corr)->sortDesc()->values()->all());
        }
        // El 986 más alto va arriba; el control sin dígitos cuenta como 0 y cierra los CCF.
        $this->assertSame('DTE-03-M001P002-000000000000986', $orden->first()->numero_control);
        $this->assertSame('SIN-NUMERO', $orden->filter(fn ($i) => $i->ordenTipo() === 0)->last()->numero_control);
    }

    public function test_la_fecha_del_documento_manda_sobre_el_correlativo(): void
    {
        $lote = $this->lote();
        $viejo = $this->item($lote, '03', 'DTE-03-M001P002-000000000000900', 1.00);
        $viejo->update(['fecha_documento' => '2026-08-01']);
        $nuevo = $this->item($lote, '03', 'DTE-03-M001P002-000000000000005', 1.00);
        $nuevo->update(['fecha_documento' => '2026-09-20']);

        $ids = $this->ficha($lote->fresh())->assertOk()->viewData('items')->pluck('id')->all();

        $this->assertSame([$nuevo->id, $viejo->id], $ids);
    }

    public function test_los_totales_son_del_lote_completo_en_cada_pagina(): void
    {
        $lote = $this->loteGrande();

        $pagina1 = $this->ficha($lote)->assertOk()->viewData('resumen');
        $pagina2 = $this->ficha($lote, ['page' => 2])->assertOk()->viewData('resumen');

        $this->assertSame($pagina1, $pagina2);
        // Y son EXACTAMENTE los que calcula el modelo sobre todos los items.
        $this->assertSame(45, $pagina1['cantidad']);
        $this->assertEqualsWithDelta($lote->totalMontoDte(), $pagina1['total_dte'], 0.001);
        $this->assertEqualsWithDelta($lote->totalMontoAlbaran(), $pagina1['total_albaran'], 0.001);
        $this->assertEqualsWithDelta($lote->diferenciaTotal(), $pagina1['diferencia'], 0.001);
        $this->assertSame($lote->cantidadSinAlbaran(), $pagina1['sin_albaran']);
        $this->assertSame($lote->cantidadConDiferencia(), $pagina1['con_diferencia']);
        $this->assertSame($lote->cantidadAlbaranSinMonto(), $pagina1['sin_monto']);
        $this->assertSame($lote->cantidadOtraSala(), $pagina1['otra_sala']);
        $this->assertSame(1, $pagina1['otra_sala'], 'El albarán de la sala 0999 contra una OC de la 0230.');
    }

    public function test_el_excel_sigue_llevando_el_lote_completo(): void
    {
        $lote = $this->loteGrande();

        $ruta = app(ExcelCallejaExporter::class)->generar($lote);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame(46, $hoja->getHighestDataRow(), 'Encabezado + 45 documentos, no solo una página.');
    }

    public function test_la_pagina_visible_no_hidrata_el_lote_entero(): void
    {
        $lote = $this->loteGrande();

        $items = app(FichaLotePpq::class)->pagina(app(FichaLotePpq::class)->idsOrdenados(app(FichaLotePpq::class)->filas($lote)));

        $this->assertCount(25, $items->items());
        $this->assertTrue($items->first()->relationLoaded('albaran'));
        $this->assertFalse($lote->relationLoaded('items'), 'La ficha no carga la colección completa.');
    }

    public function test_lote_vacio_y_parametros_de_pagina_raros(): void
    {
        $vacio = $this->lote();
        $this->ficha($vacio)->assertOk()->assertSeeText('El lote no tiene documentos.');

        $lote = $this->loteGrande();
        $raro = $this->actingAs($this->usuario())->get(route('ppq.lotes.show', $lote).'?page[]=2')->assertOk();
        $this->assertSame(1, $raro->viewData('items')->currentPage(), 'Un arreglo como página vale 1.');

        $fuera = $this->ficha($lote, ['page' => 9])->assertOk();
        $fuera->assertSeeText('Esta página no existe');
        $this->assertSame(45, $fuera->viewData('resumen')['cantidad']);
    }
}
