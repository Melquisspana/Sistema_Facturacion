<?php

namespace Tests\Feature\Ppq;

use App\Enums\EstadoNcExportacion;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * HISTORIAL de archivos de notas de crédito: una fila por lote, paginado, con la suma de
 * sus NC, y una ficha de solo lectura con exactamente las notas de ese lote.
 *
 * Antes la pantalla cargaba TODAS las NC ya exportadas y cortaba los archivos en 30.
 */
class NcExportacionHistorialTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** NC aceptada con su albarán guardado. */
    private function nc(Cliente $cliente, string $total): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        $nc = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '05',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-05-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => $total,
        ]);

        DteAlbaran::create([
            'dte_id' => $nc->id,
            'numero_canonico' => 'AC04/0033/00/'.(3000 + $this->n),
            'tipo_codigo' => 'AC04',
            'sala_codigo' => '0033',
            'numero' => (string) (3000 + $this->n),
            'fecha' => '2026-09-01',
            'total' => $total,
        ]);

        return $nc;
    }

    /** @param  array<int, Dte>  $notas */
    private function lote(Cliente $cliente, array $notas = [], ?string $referencia = null): NcExportacion
    {
        static $secuencia = 0;
        $secuencia++;

        $lote = NcExportacion::create([
            'cliente_id' => $cliente->id,
            'referencia' => $referencia ?? 'NC-000123-20260901-'.str_pad((string) $secuencia, 3, '0', STR_PAD_LEFT),
            'formato' => 'albaran_nc_v1',
            'archivo_nombre' => '000123'.str_pad((string) $secuencia, 12, '0', STR_PAD_LEFT).'.xlsx',
        ]);

        foreach (array_values($notas) as $i => $nota) {
            NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nota->id, 'orden' => $i + 1]);
        }

        return $lote;
    }

    // ------------------------------------------------------------------ historial

    public function test_el_historial_pagina_todos_los_archivos_sin_perder_ni_repetir(): void
    {
        $cliente = $this->cliente();
        $lotes = collect(range(1, 45))->map(fn () => $this->lote($cliente));
        $usuario = $this->usuario();

        $vistos = collect();
        foreach ([1, 2, 3] as $pagina) {
            $respuesta = $this->actingAs($usuario)
                ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id, 'lotes_page' => $pagina]))
                ->assertOk();
            $paginador = $respuesta->viewData('lotes');
            $this->assertSame($pagina, $paginador->currentPage());
            $vistos = $vistos->concat($paginador->pluck('id'));

            if ($pagina < 3) {
                $siguiente = $paginador->url($pagina + 1);
                $this->assertStringContainsString('cliente_id='.$cliente->id, $siguiente, 'El cliente viaja entre páginas.');
                $this->assertStringContainsString('lotes_page='.($pagina + 1), $siguiente);
            }
        }

        $this->assertCount(45, $vistos, 'Ningún archivo repetido ni perdido.');
        $this->assertEqualsCanonicalizing($lotes->pluck('id')->all(), $vistos->all());
        // Del más reciente al más antiguo.
        $this->assertSame($lotes->last()->id, $vistos->first());
    }

    public function test_filtros_y_cliente_enviados_como_arreglos_no_rompen_la_pantalla(): void
    {
        $cliente = $this->cliente();
        $this->cliente();

        $respuesta = $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.index')
            .'?cliente_id[]='.$cliente->id.'&q[]=x&desde[]=2026-09-01&hasta[]=x&tipo[]=05&sala[]=0033')
            ->assertOk();

        $this->assertNull($respuesta->viewData('cliente'));
        $this->assertSame(['desde' => '', 'hasta' => '', 'tipo' => '', 'sala' => '', 'q' => ''], $respuesta->viewData('filtros'));
        $this->assertFalse($respuesta->viewData('hayFiltros'));
    }

    public function test_cada_fila_suma_el_total_de_sus_notas_y_no_las_de_otro_lote(): void
    {
        $cliente = $this->cliente();
        $loteA = $this->lote($cliente, [$this->nc($cliente, '10.00'), $this->nc($cliente, '20.50')]);
        $loteB = $this->lote($cliente, [$this->nc($cliente, '5.25')]);

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk();

        $filas = $respuesta->viewData('lotes')->keyBy('id');
        $this->assertSame(2, $filas[$loteA->id]->items_count);
        $this->assertEqualsWithDelta(30.50, (float) $filas[$loteA->id]->total_notas, 0.001);
        $this->assertSame(1, $filas[$loteB->id]->items_count);
        $this->assertEqualsWithDelta(5.25, (float) $filas[$loteB->id]->total_notas, 0.001);
        $respuesta->assertSee('30.50');
        $respuesta->assertSee(route('ppq.nc-exportaciones.show', $loteA), false);
    }

    // ------------------------------------------------------------------ detalle

    public function test_el_detalle_muestra_solo_las_notas_del_lote_y_su_suma(): void
    {
        $cliente = $this->cliente();
        $a1 = $this->nc($cliente, '10.00');
        $a2 = $this->nc($cliente, '20.50');
        $b1 = $this->nc($cliente, '5.25');
        $loteA = $this->lote($cliente, [$a1, $a2]);
        $this->lote($cliente, [$b1]);
        $sueltaSinLote = $this->nc($cliente, '7.00');

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.show', $loteA))
            ->assertOk();

        $respuesta->assertSee($loteA->archivo_nombre);
        $respuesta->assertSee($a1->numero_control);
        $respuesta->assertSee($a2->numero_control);
        $respuesta->assertSee('AC04/0033/00/', false);
        $respuesta->assertDontSee($b1->numero_control);
        $respuesta->assertDontSee($sueltaSinLote->numero_control);
        $respuesta->assertSee('30.50');
        $respuesta->assertSee(route('ppq.nc-exportaciones.descargar', $loteA), false);
    }

    public function test_abrir_el_detalle_no_cambia_estado_ni_descargas(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00')]);

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk();

        $lote->refresh();
        $this->assertSame(EstadoNcExportacion::Generado, $lote->estado);
        $this->assertSame(0, $lote->descargas);
        $this->assertNull($lote->descargado_en);
    }

    public function test_el_detalle_exige_ppq_ver(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente);

        $this->actingAs($this->usuario(RolSistema::Contabilidad))
            ->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk();

        $this->actingAs($this->usuario(RolSistema::Produccion))
            ->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertForbidden();
    }

    public function test_descargar_no_incorpora_notas_nuevas_al_lote(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00'), $this->nc($cliente, '20.50')]);
        $nueva = $this->nc($cliente, '3.00'); // pendiente, emitida después

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->post(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertOk();

        $this->assertSame(2, $lote->items()->count());
        $this->assertFalse(NcExportacionItem::where('dte_id', $nueva->id)->exists());

        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertDontSee($nueva->numero_control);
    }
}
