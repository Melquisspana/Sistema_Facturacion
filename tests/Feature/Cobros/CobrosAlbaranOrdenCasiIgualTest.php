<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Support\OrdenCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Caso real del 06/11/2026: dos CCF de Calleja del 02/11 con la orden de compra mal escrita
 * quedaron «No entregado» en silencio aunque sus albaranes llegaron el 04/11. Un albarán de
 * entrega LIBRE de la misma sala con la OC del mismo largo y a lo sumo 2 dígitos distintos
 * deja el CCF para revisar, con el albarán nombrado; nunca se vincula solo.
 *
 * Las dos OC y los dos albaranes son los reales; lo demás es ficticio.
 */
class CobrosAlbaranOrdenCasiIgualTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

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
        ClientePerfilDocumento::create([
            'cliente_id' => $this->cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function ccf(string $oc, string $correlativo, ?string $codigoSala = null): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];

        $sucursal = $codigoSala === null ? null
            : $this->cliente->sucursales()->create(['nombre' => 'Sala '.$codigoSala, 'codigo' => $codigoSala]);

        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $sucursal?->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad($correlativo, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLO'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => $oc,
            'fecha_emision' => '2026-11-02',
            'hora_emision' => '08:00:00',
            'total_pagar' => 150.00,
        ]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $dte->numero_control,
            'fecha_emision' => '2026-11-02',
            'monto' => '150.00',
        ]);
    }

    private function albaran(string $numero, string $oc, ?string $sala = null): PpqAlbaran
    {
        return PpqAlbaran::create([
            'numero_albaran' => $numero,
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-11-04',
            'monto_albaran' => '150.00',
            'numero_orden_compra' => $oc,
            'sala_codigo' => $sala,
        ]);
    }

    private function gestor(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    public function test_casi_igual_es_mismo_largo_y_a_lo_sumo_dos_digitos_distintos(): void
    {
        $this->assertTrue(OrdenCompra::casiIgual('20100218003741', '26100218003741'));
        $this->assertTrue(OrdenCompra::casiIgual('26100230002885', '26100230003885'));
        $this->assertTrue(OrdenCompra::casiIgual('26100218003741', '26100218003799'));
        $this->assertFalse(OrdenCompra::casiIgual('26100218003741', '26100218003741'), 'La misma OC no es «casi».');
        $this->assertFalse(OrdenCompra::casiIgual('26100218003741', '26100218004999'), 'Tres dígitos distintos no.');
        $this->assertFalse(OrdenCompra::casiIgual('2610021800374', '26100218003741'), 'Otro largo no.');
        $this->assertFalse(OrdenCompra::casiIgual('', ''));
    }

    public function test_la_union_ii_queda_para_revisar_con_el_albaran_nombrado_y_no_se_vincula_solo(): void
    {
        // La sala sale de la sucursal del CCF (código guardado sin el cero).
        $documento = $this->ccf('20100218003741', '1001', codigoSala: '218');
        $albaran = $this->albaran('AC01/0218/00/5633', '26100218003741', '0218');

        $estado = app(VinculadorAlbaranes::class)->aplicar($documento, soloSiCambia: true);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $estado);
        $documento->refresh();
        $this->assertNull($documento->ppq_albaran_id, 'Es dinero y cobro: no se vincula solo.');
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $documento->vinculacion_estado);
        $this->assertSame(
            'El albarán AC01/0218/00/5633 trae la orden 26100218003741, casi igual a la del CCF (20100218003741): revisá y enlazalo.',
            $documento->vinculacion_motivo,
        );
        $this->assertSame([$albaran->id], array_column($documento->vinculacion_candidatos, 'id'));
    }

    public function test_la_union_queda_para_revisar_aunque_el_albaran_solo_traiga_la_sala_en_el_numero(): void
    {
        $documento = $this->ccf('26100230002885', '1002');
        $albaran = $this->albaran('AC01/0230/00/5703', '26100230003885');

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id']);
        $this->assertStringContainsString('AC01/0230/00/5703 trae la orden 26100230003885', $veredicto['motivo']);
        $this->assertSame($albaran->id, $veredicto['candidatos'][0]['id']);
    }

    public function test_tres_digitos_distintos_no_marca(): void
    {
        $documento = $this->ccf('26100218003741', '1003');
        $this->albaran('AC01/0218/00/5634', '26100218004999', '0218');

        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, app(VinculadorAlbaranes::class)->auditar($documento)['estado']);
    }

    public function test_otra_sala_no_marca(): void
    {
        $documento = $this->ccf('26100218003741', '1004');
        // Un dígito distinto en la OC, pero el albarán es de la sala 0219.
        $this->albaran('AC01/0219/00/5635', '26100219003741', '0219');

        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, app(VinculadorAlbaranes::class)->auditar($documento)['estado']);
    }

    public function test_albaran_ya_vinculado_a_otro_documento_no_marca(): void
    {
        $documento = $this->ccf('20100218003741', '1005');
        $albaran = $this->albaran('AC01/0218/00/5633', '26100218003741', '0218');
        $otro = $this->ccf('26100218003741', '1006');
        $otro->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, app(VinculadorAlbaranes::class)->auditar($documento)['estado']);
    }

    public function test_con_dos_candidatos_marca_y_dice_cuantos_pero_no_ofrece_el_clic(): void
    {
        $documento = $this->ccf('20100218003741', '1007');
        $this->albaran('AC01/0218/00/5633', '26100218003741', '0218');
        $this->albaran('AC01/0218/00/5640', '20100218003742', '0218');

        app(VinculadorAlbaranes::class)->aplicar($documento, soloSiCambia: true);
        $documento->refresh();

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $documento->vinculacion_estado);
        $this->assertNull($documento->ppq_albaran_id);
        $this->assertStringStartsWith('Hay 2 albaranes de entrega libres de la misma sala', $documento->vinculacion_motivo);

        $this->actingAs($this->gestor())
            ->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()
            ->assertSeeText('Revisar albarán')
            ->assertSeeText('Revisar en la ficha')
            ->assertDontSee('form="enlazar_'.$documento->id.'"', false);
    }

    public function test_seguimiento_lo_muestra_como_revisar_albaran_y_se_enlaza_con_un_clic(): void
    {
        $documento = $this->ccf('20100218003741', '1008');
        $albaran = $this->albaran('AC01/0218/00/5633', '26100218003741', '0218');
        app(VinculadorAlbaranes::class)->aplicar($documento, soloSiCambia: true);

        $gestor = $this->gestor();
        $respuesta = $this->actingAs($gestor)
            ->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()
            ->assertSeeText('Revisar albarán')
            ->assertDontSeeText('El albarán no ha llegado al correo')
            ->assertSeeText('casi igual a la del CCF (20100218003741)')
            ->assertSeeText('Enlazar AC01/0218/00/5633')
            ->assertSee('action="'.route('cobros.documentos.vincular', $documento).'"', false);

        // Cuenta como entregado al que le falta el albarán, no como «No entregado».
        $this->assertSame(0, $respuesta->viewData('etapas')['no_entregados']);
        $this->assertSame(1, $respuesta->viewData('etapas')['falta_albaran']);

        $this->actingAs($gestor)
            ->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'etapa' => 'falta_albaran']))
            ->assertOk()->assertSee($documento->numero_control);

        // El clic manda lo mismo que el formulario oculto: albarán y nota.
        $this->actingAs($gestor)
            ->from(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->post(route('cobros.documentos.vincular', $documento), [
                'ppq_albaran_id' => $albaran->id,
                'nota' => 'Enlazado desde Seguimiento: el albarán trae la orden 26100218003741, casi igual a la del CCF.',
            ])
            ->assertRedirect(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertSessionHasNoErrors();

        $documento->refresh();
        $this->assertSame($albaran->id, $documento->ppq_albaran_id);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $documento->vinculacion_estado);
        $this->assertStringStartsWith('Vinculado a mano por '.$gestor->name, $documento->vinculacion_motivo);
    }

    public function test_sin_permiso_de_gestionar_ve_el_aviso_pero_no_el_enlace_ni_puede_vincular(): void
    {
        $documento = $this->ccf('20100218003741', '1009');
        $albaran = $this->albaran('AC01/0218/00/5633', '26100218003741', '0218');
        app(VinculadorAlbaranes::class)->aplicar($documento, soloSiCambia: true);

        $soloVe = User::factory()->create();
        $soloVe->givePermissionTo('ppq.ver');

        $this->actingAs($soloVe)
            ->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()
            ->assertSeeText('Revisar albarán')
            ->assertDontSeeText('Enlazar AC01/0218/00/5633');

        $this->actingAs($soloVe)
            ->post(route('cobros.documentos.vincular', $documento), ['ppq_albaran_id' => $albaran->id, 'nota' => 'x'])
            ->assertForbidden();
        $this->assertNull($documento->refresh()->ppq_albaran_id);
    }
}
