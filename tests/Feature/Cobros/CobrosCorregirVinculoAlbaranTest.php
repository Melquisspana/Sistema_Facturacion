<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\SugerenciasAlbaran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Un CCF con la OC mal tecleada deduce otra sala (0620 en vez de 0062) y no encuentra su
 * albarán. Se corrige ELIGIENDO el albarán entre sugeridos; la OC del DTE sellado no se
 * toca y la sala sale del albarán vinculado.
 */
class CobrosCorregirVinculoAlbaranTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const OC_MAL = '26090620003463';

    private const OC_BUENA = '26090062003463';

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
    }

    private function ccf(): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];

        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-03-M001P002-000000000000247',
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLO'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => self::OC_MAL,
            'fecha_emision' => '2026-09-18',
            'hora_emision' => '08:00:00',
            'total_pagar' => 123.74,
        ]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $dte->numero_control,
            'fecha_emision' => '2026-09-18',
            'monto' => '123.74',
        ]);
    }

    private function albaran(string $numero, array $datos = []): PpqAlbaran
    {
        return PpqAlbaran::create($datos + [
            'numero_albaran' => $numero,
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => self::OC_BUENA,
            'sala_codigo' => '0062',
        ]);
    }

    private function gestor(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    public function test_salas_parecidas_cubren_el_cero_corrido_y_no_cualquier_sala(): void
    {
        $this->assertTrue(SugerenciasAlbaran::salasParecidas('0062', '0620'));
        $this->assertTrue(SugerenciasAlbaran::salasParecidas('0062', '0063'));
        $this->assertFalse(SugerenciasAlbaran::salasParecidas('0062', '0236'));
    }

    public function test_sugiere_primero_el_albaran_de_la_sala_parecida_y_explica_por_que(): void
    {
        $documento = $this->ccf();
        $bueno = $this->albaran('AC01/0062/00/0001');
        $this->albaran('AC01/0236/00/0002', ['sala_codigo' => '0236', 'numero_orden_compra' => '26090236000001', 'monto_albaran' => '999.00']);
        $tomado = $this->albaran('AC01/0062/00/0003');
        CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Externo->value, 'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000300', 'monto' => '123.74', 'ppq_albaran_id' => $tomado->id,
        ]);

        $sugeridos = app(SugerenciasAlbaran::class)->para($documento);

        $this->assertSame($bueno->id, $sugeridos[0]['id']);
        $this->assertContains('mismo monto', $sugeridos[0]['motivos']);
        $this->assertContains('sala 0062 parecida a la 0620 de la orden de compra', $sugeridos[0]['motivos']);
        $this->assertNotContains('AC01/0236/00/0002', array_column($sugeridos, 'numero'), 'Otra sala y otro monto no se sugiere.');

        $conflicto = collect($sugeridos)->firstWhere('id', $tomado->id);
        $this->assertSame('DTE-03-M001P002-000000000000300', $conflicto['tomado_por']);
        $this->assertSame($tomado->id, end($sugeridos)['id'], 'El tomado va al final.');
    }

    public function test_la_ficha_ofrece_los_sugeridos_y_vincular_deja_sala_evento_y_bitacora(): void
    {
        $documento = $this->ccf();
        $albaran = $this->albaran('AC01/0062/00/0001');
        $usuario = $this->gestor();

        $ficha = $this->actingAs($usuario)->get(route('cobros.documentos.show', $documento))
            ->assertOk()
            ->assertSeeText('Elegir albarán');
        $this->assertSame([$albaran->id], array_column($ficha->viewData('sugeridos'), 'id'));

        $this->actingAs($usuario)
            ->from(route('cobros.documentos.show', $documento))
            ->post(route('cobros.documentos.vincular', $documento), [
                'ppq_albaran_id' => $albaran->id,
                'nota' => 'Elegido en «Corregir vínculo»: mismo monto.',
            ])
            ->assertSessionHasNoErrors();

        $documento->refresh();
        $this->assertSame($albaran->id, $documento->ppq_albaran_id);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $documento->vinculacion_estado);
        $this->assertSame(self::OC_MAL, $documento->dte->fresh()->numero_orden_compra, 'El DTE sellado no se toca.');

        $evento = $documento->eventos()->where('tipo', TipoEventoCobro::Vinculacion->value)->sole();
        $this->assertNull($evento->datos['albaran_anterior']);
        $this->assertSame('AC01/0062/00/0001', $evento->datos['albaran']);
        $this->assertSame('0062', $evento->datos['sala_nueva']);
        $this->assertSame($usuario->id, $evento->user_id);

        $log = Activity::where('log_name', 'cobros_vinculacion')->sole();
        $this->assertSame('0062', $log->properties['despues']['sala']);
        $this->assertNull($log->properties['antes']['albaran_id']);
    }

    public function test_el_buscador_encuentra_por_numero_sin_escribir_nada(): void
    {
        $documento = $this->ccf();
        $this->albaran('AC01/0062/00/4321', ['fecha_albaran' => '2025-01-10', 'monto_albaran' => '1.00']);

        $this->actingAs($this->gestor())
            ->getJson(route('cobros.documentos.albaranes', [$documento, 'q' => '4321']))
            ->assertOk()
            ->assertJsonPath('0.numero', 'AC01/0062/00/4321')
            ->assertJsonPath('0.sala', '0062');

        $this->assertNull($documento->refresh()->ppq_albaran_id);
    }

    public function test_un_ccf_presentado_no_cambia_de_albaran(): void
    {
        $documento = $this->ccf();
        $viejo = $this->albaran('AC01/0620/00/0009', ['sala_codigo' => '0620', 'numero_orden_compra' => self::OC_MAL]);
        $nuevo = $this->albaran('AC01/0062/00/0001');
        $documento->forceFill([
            'ppq_albaran_id' => $viejo->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
            'presentacion_estado' => EstadoPresentacionCobro::Presentada->value,
        ])->save();
        $usuario = $this->gestor();

        $this->actingAs($usuario)->get(route('cobros.documentos.show', $documento))
            ->assertOk()
            ->assertDontSeeText('Vincular con otro albarán')
            ->assertSeeText('el albarán no se cambia desde aquí');

        $this->actingAs($usuario)
            ->from(route('cobros.documentos.show', $documento))
            ->post(route('cobros.documentos.vincular', $documento), ['ppq_albaran_id' => $nuevo->id, 'nota' => 'Intento'])
            ->assertSessionHasErrors('ppq_albaran_id');

        $this->assertSame($viejo->id, $documento->refresh()->ppq_albaran_id);
        $this->assertSame(0, $documento->eventos()->count());
    }

    public function test_sin_permiso_de_gestion_no_ve_la_ventana_ni_busca(): void
    {
        $documento = $this->ccf();
        $soloVe = User::factory()->create();
        $soloVe->givePermissionTo('ppq.ver');

        $this->actingAs($soloVe)->get(route('cobros.documentos.show', $documento))
            ->assertOk()
            ->assertDontSeeText('Elegir albarán');

        $this->actingAs($soloVe)
            ->getJson(route('cobros.documentos.albaranes', [$documento, 'q' => '0062']))
            ->assertForbidden();
    }
}
