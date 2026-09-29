<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPpq;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\FichaLotePpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** Del Seguimiento sale el PPQ; el TXT (un solo lugar) lo deja pagado solo. */
class CrearPpqDesdeSeguimientoTest extends TestCase
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

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
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
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
    }

    private function ccf(bool $entregado = true): CobroDocumento
    {
        // La OC trae la sala (0017) aunque el albarán todavía no haya llegado.
        $dte = $this->dte('03', ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        if ($entregado) {
            $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
                'fecha_albaran' => '2026-09-05', 'monto_albaran' => '98.95', 'sala_codigo' => '0017',
                'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
            $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();
        }

        return $doc;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    public function test_crea_el_ppq_con_el_ccf_y_su_nc_y_el_txt_lo_deja_pagado(): void
    {
        $doc = $this->ccf();
        // Devolución: el albarán de entrega trae 1.05 menos; la NC AC04 va por 1.11.
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '1.11']);
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/3874', 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0017', 'numero' => '3874', 'fecha' => '2026-09-01', 'total' => '1.11']);
        $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'estado' => 'invalidado', 'total_pagar' => '9.00']);
        $usuario = $this->usuario();

        $respuesta = $this->actingAs($usuario)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]]);

        $lote = PpqLote::sole();
        $respuesta->assertRedirect(route('ppq.lotes.show', $lote));
        $this->assertSame(EstadoPpq::Borrador, $lote->estado);
        $this->assertSame($this->cliente->id, $lote->cliente_id);
        $this->assertEqualsCanonicalizing(['03', '05'], $lote->items()->pluck('tipo_dte')->all(), 'CCF + su NC vigente; la invalidada no.');
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);

        // La diferencia de la devolución queda cubierta por la NC AC04.
        $ficha = app(FichaLotePpq::class);
        $resumen = $ficha->resumen($ficha->filas($lote));
        $this->assertSame(1.05, $resumen['diferencia_devoluciones']);
        $this->assertSame(0, $resumen['con_diferencia']);

        // No se puede meter dos veces.
        $this->actingAs($usuario)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]])
            ->assertSessionHasErrors('documentos');

        // El TXT se carga una vez, en el Seguimiento, y concilia el PPQ.
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.str_replace('-', '', $doc->numero_control).";10-SEP-26;100.00\n"
            .'000123;TITULAR DE EJEMPLO;NC;'.str_replace('-', '', $nc->numero_control).";10-SEP-26;-1.11\n";
        $this->actingAs($usuario)->post(route('cobros.pagos', $this->cliente), [
            'archivo' => UploadedFile::fake()->createWithContent('pagos.txt', $txt),
        ])->assertOk()->assertSeeText('También se actualizaron los PPQ');

        $this->assertSame(EstadoPpq::Pagado, $lote->refresh()->estado);
    }

    public function test_avisa_cuando_gmail_esta_desconectado(): void
    {
        config(['ppq.gmail.enabled' => true]);   // encendido, sin cuenta conectada
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Gmail está desconectado');

        config(['ppq.gmail.enabled' => false]);  // integración apagada: no hay nada que avisar
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertDontSeeText('Gmail está desconectado');
    }

    public function test_un_ccf_no_entregado_no_entra_al_ppq(): void
    {
        $doc = $this->ccf(entregado: false);

        $this->actingAs($this->usuario())->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]])
            ->assertSessionHasErrors('documentos');
        $this->assertSame(0, PpqLote::count());

        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('No entregado')->assertSeeText('El albarán no ha llegado al correo')
            ->assertSeeText('Sala 0017', false);
    }
}
