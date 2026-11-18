<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * «quitar» en la ficha del lote: el renglón sale con constancia y el CCF vuelve a «por
 * presentar». Antes solo se borraba el renglón y el CCF quedaba «en PPQ» sin estar en
 * ninguno: fuera de la bandeja y sin casilla (PPQ 28 de producción).
 */
class PpqQuitarItemDelLoteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) (700 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOPRUEBA'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
    }

    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03', ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function usuario(RolSistema $rol = RolSistema::Facturacion): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function crear(array $docs): PpqLote
    {
        return app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, array_map(fn ($doc) => $doc->id, $docs));
    }

    private function quitar(User $user, PpqLote $lote, $item)
    {
        return $this->actingAs($user)->from(route('ppq.lotes.show', $lote))
            ->delete(route('ppq.lotes.items.destroy', [$lote, $item]));
    }

    public function test_quitar_un_ccf_lo_devuelve_a_por_presentar_con_nota_y_bitacora(): void
    {
        $doc = $this->ccf();
        $otro = $this->ccf();
        $lote = $this->crear([$doc, $otro]);
        $item = $lote->items()->where('dte_id', $doc->dte_id)->sole();
        $user = $this->usuario();

        $this->quitar($user, $lote, $item)->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, 'El CCF '.$doc->correlativoCorto().' salió del lote y volvió a por presentar'));

        $this->assertDatabaseMissing('ppq_items', ['id' => $item->id]);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $otro->refresh()->presentacion_estado, 'El que sigue en el lote no cambia.');
        $evento = $doc->eventos()->where('referencia_linea', "ppq-{$lote->id}-quitado")->sole();
        $this->assertSame('nota', $evento->tipo->value);
        $this->assertSame($lote->id, $evento->datos['ppq_lote_id']);
        $this->assertSame($user->id, $evento->user_id);
        $this->assertStringContainsString('CCF '.$doc->correlativoCorto().' · $100.00', (string) $lote->refresh()->observaciones);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'ppq.item_quitado')
            ->where('subject_id', $lote->id)->where('causer_id', $user->id)->count());

        // Vuelve a la bandeja con su casilla y entra en un PPQ nuevo.
        $this->actingAs($user)->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'etapa' => 'listos']))
            ->assertOk()->assertViewHas('listosPpq', fn ($listos) => $listos->has($doc->id));
        $this->assertSame(1, $this->crear([$doc->refresh()])->items()->count());
    }

    public function test_quitar_un_ccf_avisa_que_su_nc_sigue_en_el_lote_y_no_la_quita(): void
    {
        $doc = $this->ccf();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '10.00']);
        $lote = $this->crear([$doc]);
        $this->assertSame(2, $lote->items()->count());

        $this->quitar($this->usuario(), $lote, $lote->items()->where('dte_id', $doc->dte_id)->sole())
            ->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, 'volvió a por presentar')
                && str_contains($mensaje, 'Su nota de crédito '.(int) substr($nc->numero_control, -15).' sigue en el lote'));

        $this->assertTrue($lote->items()->where('dte_id', $nc->id)->exists(), 'La NC no se quita sola.');
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->refresh()->presentacion_estado);
    }

    public function test_quitar_una_nc_no_cambia_su_ccf(): void
    {
        $doc = $this->ccf();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '10.00']);
        $docNc = CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'dte', 'dte_id' => $nc->id,
            'tipo_dte' => '05', 'numero_control' => $nc->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '10.00']);
        $lote = $this->crear([$doc]);
        $antesNc = $docNc->refresh()->presentacion_estado;

        $this->quitar($this->usuario(), $lote, $lote->items()->where('dte_id', $nc->id)->sole())
            ->assertSessionHas('status', 'La NC '.(int) substr($nc->numero_control, -15).' salió del lote.');

        $this->assertFalse($lote->items()->where('dte_id', $nc->id)->exists());
        $this->assertTrue($lote->items()->where('dte_id', $doc->dte_id)->exists());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
        $this->assertSame($antesNc, $docNc->refresh()->presentacion_estado);
        $this->assertSame(0, $doc->eventos()->count() + $docNc->eventos()->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'ppq.item_quitado')->count());
    }

    public function test_un_ccf_que_sigue_en_otro_lote_vigente_no_cambia(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $otro = PpqLote::create(['referencia' => 'Otro lote de prueba', 'fecha' => today(), 'estado' => 'borrador', 'cliente_id' => $this->cliente->id]);
        $otro->items()->create(['tipo_dte' => '03', 'numero_control' => $doc->numero_control, 'monto_dte' => '100.00']);

        $this->quitar($this->usuario(), $lote, $lote->items()->sole())
            ->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, 'no volvió a por presentar')
                && str_contains($mensaje, "sigue en el PPQ #{$otro->id}"));

        $this->assertSame(0, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
        $this->assertSame(0, $doc->eventos()->count());
    }

    public function test_un_renglon_conciliado_o_con_historia_no_se_quita(): void
    {
        $pagado = $this->ccf();
        $conHistoria = $this->ccf();
        $lote = $this->crear([$pagado, $conHistoria]);
        $itemPagado = $lote->items()->where('dte_id', $pagado->dte_id)->sole();
        $itemPagado->update(['conciliacion_estado' => 'pagado']);
        $itemHistoria = $lote->items()->where('dte_id', $conHistoria->dte_id)->sole();
        $conciliacion = DB::table('ppq_conciliaciones')->insertGetId(['ppq_lote_id' => $lote->id]);
        DB::table('ppq_conciliacion_movimientos')->insert(['ppq_conciliacion_id' => $conciliacion, 'ppq_item_id' => $itemHistoria->id]);
        $user = $this->usuario();

        $this->quitar($user, $lote, $itemPagado)
            ->assertSessionHas('error', 'No se quitó el CCF '.$pagado->correlativoCorto().': ya tiene un cobro registrado.');
        $this->quitar($user, $lote, $itemHistoria)
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'tiene historia de conciliación'));

        $this->assertSame(2, $lote->items()->count());
        $this->assertDatabaseHas('ppq_conciliacion_movimientos', ['ppq_item_id' => $itemHistoria->id]);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $pagado->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $conHistoria->refresh()->presentacion_estado);
        $this->assertSame(0, DB::table('activity_log')->where('description', 'ppq.item_quitado')->count());
    }

    public function test_un_lote_no_editable_sigue_rechazando(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $lote->update(['estado' => 'enviado']);

        $this->quitar($this->usuario(), $lote, $lote->items()->sole())
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'no admite cambios'));

        $this->assertSame(1, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
    }

    public function test_sin_permiso_de_gestionar_ppq_no_puede_quitar(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);

        $this->quitar($this->usuario(RolSistema::Jefatura), $lote, $lote->items()->sole())->assertForbidden();

        $this->assertSame(1, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
    }
}
