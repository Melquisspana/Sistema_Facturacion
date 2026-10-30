<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Dte\SaldoMontoCcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** Las devoluciones pendientes se cuentan por AC04, independientemente de la AC01. */
class CobrosAc04PendienteTest extends TestCase
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
        return $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->get(route('cobros.documentos.show', $documento));
    }

    public function test_sin_ac01_cuenta_por_ac04_sin_mezclar_averia_y_filtra(): void
    {
        $ccf = $this->dte('03', ['ambiente' => '00', 'monto_total_operacion' => '100.00']);
        $doc = $this->seguimiento($ccf);
        foreach (['8427', '8428'] as $numero) {
            PpqAlbaran::create(['dte_id' => $ccf->id, 'tipo_codigo' => 'AC04', 'numero_albaran' => 'AC04/0148/00/'.$numero, 'sala_codigo' => '0148', 'monto_albaran' => '10.00']);
        }
        $averia = $this->nc($ccf, ['tipo_nota_credito' => 'averia', 'monto_total_operacion' => '12.34']);
        DteAlbaran::create(['dte_id' => $averia->id, 'numero_canonico' => 'AC02/0148/00/8427', 'tipo_codigo' => 'AC02', 'numero' => '8427']);
        $this->ficha($doc)->assertSee('Hacer NC')->assertSee('AC04/0148/00/8428');
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true]);
        $otro = $this->seguimiento($this->dte('03', ['ambiente' => '00']));
        $respuesta = $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'devolucion_sin_nc' => '1']))->assertOk()->assertSee('Devolución sin NC (2)');
        $this->assertSame([$doc->id], $respuesta->viewData('documentos')->pluck('id')->all());
        $nc = $this->nc($ccf, ['tipo_nota_credito' => 'devolucion_producto', 'monto_total_operacion' => '12.34']);
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0148/00/8427', 'tipo_codigo' => 'AC04', 'numero' => '8427']);
        $ficha = $this->ficha($doc)->assertOk();
        $this->assertCount(1, $ficha->viewData('ncPorAlbaran'));
        $ficha->assertDontSee(route('facturacion.create-nota-credito', ['ccf' => $ccf->id, 'ac04' => PpqAlbaran::where('numero_albaran', 'AC04/0148/00/8427')->value('id')]), false);
        $this->assertSame('24.68', app(SaldoMontoCcf::class)->descontadoEnCobro([$ccf->id])[$ccf->id]);
        $this->assertSame('75.32', app(SaldoMontoCcf::class)->saldos(collect([$ccf]))[$ccf->id]);
        $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'devolucion_sin_nc' => '1']))->assertOk()->assertSee('Devolución sin NC (1)');
    }

    public function test_quien_no_crea_nc_no_ve_el_boton(): void
    {
        $ccf = $this->dte('03');
        PpqAlbaran::create(['dte_id' => $ccf->id, 'tipo_codigo' => 'AC04', 'numero_albaran' => 'AC04/0148/00/8427']);
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Contabilidad->value))
            ->get(route('cobros.documentos.show', $this->seguimiento($ccf)))->assertOk()->assertDontSee('Hacer NC');
    }
}
