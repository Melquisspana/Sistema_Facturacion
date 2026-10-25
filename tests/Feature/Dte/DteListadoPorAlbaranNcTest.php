<?php

namespace Tests\Feature\Dte;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * El buscador del listado de Facturación encuentra las notas de crédito por el número del
 * albarán de crédito que las originó. Comparación EXACTA (nunca «contiene»): 3209 no trae
 * 13209 ni 32091. Los CCF no se buscan por albarán.
 */
class DteListadoPorAlbaranNcTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create();
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) (700 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '10.00',
        ]);
    }

    private function ncConAlbaran(string $tipo, string $sala, string $numero, array $extra = []): Dte
    {
        $nc = $this->dte('05', $extra);
        DteAlbaran::create([
            'dte_id' => $nc->id,
            'numero_canonico' => "{$tipo}/{$sala}/00/{$numero}",
            'tipo_codigo' => $tipo,
            'sala_codigo' => $sala,
            'numero' => $numero,
            'fecha' => '2026-09-01',
            'total' => '10.00',
        ]);

        return $nc;
    }

    private function buscar(string $q, RolSistema $rol = RolSistema::Facturacion)
    {
        return $this->actingAs(User::factory()->create()->assignRole($rol->value))
            ->get(route('facturacion.index', ['q' => $q]));
    }

    /** @return list<Dte> */
    private function encontradas(string $q): array
    {
        return $this->buscar($q)->assertOk()->viewData('dtes')->getCollection()->all();
    }

    private function idsEncontrados(string $q): array
    {
        return array_map(fn (Dte $d) => $d->id, $this->encontradas($q));
    }

    public function test_encuentra_la_nc_por_el_numero_con_o_sin_ceros_a_la_izquierda(): void
    {
        $nc = $this->ncConAlbaran('AC04', '0033', '3209');

        $this->assertSame([$nc->id], $this->idsEncontrados('3209'));
        $this->assertSame([$nc->id], $this->idsEncontrados('003209'));
        $this->assertSame([$nc->id], $this->idsEncontrados(' 3209 '));
        $this->buscar('3209')->assertSee($nc->numero_control);
    }

    public function test_encuentra_la_nc_por_el_numero_completo_del_albaran(): void
    {
        $nc = $this->ncConAlbaran('AC04', '0033', '3209');
        $otraSala = $this->ncConAlbaran('AC04', '0045', '3209');

        $this->assertSame([$nc->id], $this->idsEncontrados('AC04/0033/00/3209'));
        $this->assertSame([$nc->id], $this->idsEncontrados('ac04 / 0033 / 00 / 3209'));
        $this->assertSame([$otraSala->id], $this->idsEncontrados('AC04/0045/00/3209'));
    }

    public function test_encuentra_la_nc_por_el_nombre_del_pdf_del_albaran(): void
    {
        $nc = $this->ncConAlbaran('AC02', '0045', '2270');

        $this->assertSame([$nc->id], $this->idsEncontrados('26-04-0045-00-002270-AC02-0001.PDF'));
    }

    public function test_un_numero_parecido_no_trae_otra_nc(): void
    {
        $buscada = $this->ncConAlbaran('AC04', '0033', '3209');
        $this->ncConAlbaran('AC04', '0033', '13209');
        $this->ncConAlbaran('AC04', '0033', '32091');
        $this->ncConAlbaran('AC04', '0033', '320');

        $this->assertSame([$buscada->id], $this->idsEncontrados('3209'));
        $this->assertSame([$buscada->id], $this->idsEncontrados('AC04/0033/00/3209'));
    }

    public function test_el_numero_suelto_trae_todas_las_nc_de_distinta_sala_o_tipo_con_ese_numero(): void
    {
        $a = $this->ncConAlbaran('AC04', '0033', '3209');
        $b = $this->ncConAlbaran('AC04', '0045', '3209');
        $c = $this->ncConAlbaran('AC02', '0033', '3209');
        $this->ncConAlbaran('AC04', '0033', '4410');

        $encontradas = $this->idsEncontrados('3209');
        sort($encontradas);

        $this->assertSame([$a->id, $b->id, $c->id], $encontradas);
    }

    public function test_un_ccf_nunca_sale_por_el_albaran(): void
    {
        $nc = $this->ncConAlbaran('AC04', '0033', '3209');
        $ccf = $this->dte('03', ['numero_orden_compra' => 'OC-SIN-RELACION']);

        // Defensa en profundidad: aunque hubiera una fila de albarán colgada de un CCF,
        // la búsqueda por albarán es solo de notas de crédito.
        DteAlbaran::create([
            'dte_id' => $ccf->id, 'numero_canonico' => 'AC01/0033/00/3209', 'tipo_codigo' => 'AC01',
            'sala_codigo' => '0033', 'numero' => '3209',
        ]);

        $this->assertSame([$nc->id], $this->idsEncontrados('3209'));
        $this->assertNotContains($ccf->id, $this->idsEncontrados('AC01/0033/00/3209'));
    }

    public function test_un_ccf_con_el_mismo_numero_en_su_propio_dato_no_se_cuela_por_la_rama_del_albaran(): void
    {
        $nc = $this->ncConAlbaran('AC04', '0033', '3209');
        $ccf = $this->dte('03', ['numero_orden_compra' => '3209']);

        // La OC sigue siendo buscable como siempre (coincidencia de la rama existente);
        // el albarán de la NC no deja de aparecer por eso.
        $this->assertEqualsCanonicalizing([$nc->id, $ccf->id], $this->idsEncontrados('3209'));
    }

    public function test_la_nc_invalidada_tambien_se_encuentra_y_la_eliminada_no(): void
    {
        $invalidada = $this->ncConAlbaran('AC04', '0033', '3209', ['estado' => 'invalidado']);
        $borrador = $this->ncConAlbaran('AC04', '0045', '3209', ['estado' => 'borrador', 'sello_recepcion' => null]);
        $borrador->delete();

        $this->assertSame([$invalidada->id], $this->idsEncontrados('3209'));
    }

    public function test_la_busqueda_de_texto_de_siempre_sigue_funcionando(): void
    {
        $nc = $this->ncConAlbaran('AC04', '0033', '3209', ['numero_orden_compra' => 'OC-ABC-77']);
        $otra = $this->ncConAlbaran('AC04', '0033', '5000');

        $this->assertSame([$nc->id], $this->idsEncontrados('OC-ABC-77'));
        $this->assertNotContains($otra->id, $this->idsEncontrados('OC-ABC-77'));
    }

    public function test_un_texto_que_no_es_albaran_no_rompe_la_busqueda(): void
    {
        $this->ncConAlbaran('AC04', '0033', '3209');

        foreach (['%', '_', "' OR 1=1 --", 'AC04/', '//', '0', '000'] as $q) {
            $this->buscar($q)->assertOk();
        }
    }

    public function test_un_usuario_sin_permiso_de_ver_documentos_no_puede_buscar(): void
    {
        $this->ncConAlbaran('AC04', '0033', '3209');

        $this->buscar('3209', RolSistema::Produccion)->assertForbidden();
    }
}
