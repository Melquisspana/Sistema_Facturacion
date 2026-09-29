<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La ficha de Cobros de un CCF muestra las notas de crédito VINCULADAS a él
 * (`dte_relacionado_id`), en cualquier estado y con ese estado dicho tal cual —solo las
 * aceptadas realmente por Hacienda son NC fiscales vigentes—, su propio albarán y el
 * formato de NC que la incluyó. Nada por OC, importe ni fecha. Solo consulta.
 */
class CobrosFichaNotasCreditoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
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
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '26090017003463',
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '100.00',
        ]);
    }

    private function nc(Dte $ccf, array $extra = []): Dte
    {
        return $this->dte('05', $extra + ['dte_relacionado_id' => $ccf->id, 'total_pagar' => '12.34']);
    }

    private function seguimiento(Dte $ccf): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $ccf->id,
            'tipo_dte' => '03',
            'numero_control' => $ccf->numero_control,
            'fecha_emision' => '2026-09-10',
            'monto' => '100.00',
        ]);
    }

    private function ficha(CobroDocumento $documento)
    {
        return $this->actingAs(User::factory()->create()->assignRole(RolSistema::Jefatura->value))
            ->get(route('cobros.documentos.show', $documento));
    }

    public function test_muestra_la_nc_aceptada_con_su_albaran_y_su_formato(): void
    {
        $ccf = $this->dte('03');
        $nc = $this->nc($ccf);
        DteAlbaran::create([
            'dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/3209', 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0017', 'numero' => '3209', 'fecha' => '2026-09-01', 'total' => '12.34',
        ]);
        $lote = NcExportacion::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'NC-000123-20260915-01',
            'formato' => 'albaran_nc_v1', 'archivo_nombre' => '000123202609150800.xlsx',
        ]);
        NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nc->id, 'orden' => 1]);

        $respuesta = $this->ficha($this->seguimiento($ccf))->assertOk();

        $respuesta->assertSeeText('Notas de crédito vinculadas a este CCF');
        $respuesta->assertSeeText('Solo las aceptadas realmente por Hacienda son notas de crédito fiscales vigentes');
        $respuesta->assertSee($nc->numero_control);
        $respuesta->assertSeeText('Aceptada por Hacienda');
        $respuesta->assertSee('AC04/0017/00/3209');
        $respuesta->assertSee(route('ppq.nc-exportaciones.show', $lote), false);
        // El formato se dice generado, y que su entrega no consta: nunca «enviado».
        $respuesta->assertSeeText('Archivo generado; su entrega al cliente no consta aquí');
        $this->assertSame(1, $respuesta->viewData('notas')['total']);
    }

    public function test_el_estado_se_dice_tal_cual_y_nada_se_da_por_aceptado_o_incluido(): void
    {
        $ccf = $this->dte('03');
        $borrador = $this->nc($ccf, ['estado' => 'borrador', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $invalidada = $this->nc($ccf, ['estado' => 'invalidado']);
        $simulada = $this->nc($ccf, ['sello_recepcion' => 'MOCK-123']);

        $respuesta = $this->ficha($this->seguimiento($ccf))->assertOk();

        $respuesta->assertSee($borrador->numero_control);
        $respuesta->assertSeeText('Aún no aceptada (En edición)');
        $respuesta->assertSee($invalidada->numero_control);
        $respuesta->assertSeeText('Invalidada: no acredita');
        $respuesta->assertSee($simulada->numero_control);
        $respuesta->assertSeeText('Sin aceptación real de Hacienda');
        $respuesta->assertDontSeeText('Aceptada por Hacienda');
        $respuesta->assertSeeText('Sin albarán registrado');
        $respuesta->assertSeeText('No incluida en ningún formato');
    }

    public function test_no_muestra_nc_de_otro_ccf_aunque_compartan_orden_de_compra(): void
    {
        $ccf = $this->dte('03');
        $otroCcf = $this->dte('03'); // misma OC por defecto
        $ajena = $this->nc($otroCcf);
        // Una NC SIN vínculo al CCF, con la misma OC e importe: tampoco es suya.
        $suelta = $this->dte('05', ['dte_relacionado_id' => null, 'total_pagar' => '12.34']);

        $respuesta = $this->ficha($this->seguimiento($ccf))->assertOk();

        $respuesta->assertDontSee($ajena->numero_control);
        $respuesta->assertDontSee($suelta->numero_control);
        $respuesta->assertSeeText('Este CCF no tiene notas de crédito vinculadas.');
        $this->assertSame(0, $respuesta->viewData('notas')['total']);
    }

    public function test_un_documento_sin_dte_local_no_muestra_la_seccion(): void
    {
        $externo = CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P001-000000000000777',
            'fecha_emision' => '2026-09-10',
            'monto' => '50.00',
        ]);

        $respuesta = $this->ficha($externo)->assertOk();

        $this->assertFalse($respuesta->viewData('notas')['aplica']);
        $respuesta->assertDontSeeText('Notas de crédito vinculadas a este CCF');
    }
}
