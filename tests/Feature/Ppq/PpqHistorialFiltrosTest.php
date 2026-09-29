<?php

namespace Tests\Feature\Ppq;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HISTORIAL PPQ (`/ppq/lotes`) con filtros visibles: cliente, estado, rango de fecha del
 * lote y un único campo para la referencia o el número de lote. Los filtros viajan al
 * paginar y el orden (fecha, id; recientes primero) no pierde ni repite lotes aunque
 * compartan fecha.
 */
class PpqHistorialFiltrosTest extends TestCase
{
    use RefreshDatabase;

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

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function lote(Cliente $cliente, string $referencia, string $fecha, string $estado = 'borrador'): PpqLote
    {
        return PpqLote::create([
            'cliente_id' => $cliente->id,
            'referencia' => $referencia,
            'fecha' => $fecha,
            'estado' => $estado,
        ]);
    }

    private function historial(array $params = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())->get(route('ppq.lotes.index', $params));
    }

    /** @return array<int, int> */
    private function ids($respuesta): array
    {
        return $respuesta->viewData('lotes')->pluck('id')->all();
    }

    // ------------------------------------------------------------------ filtros

    public function test_los_filtros_se_combinan(): void
    {
        $a = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja']);
        $b = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente']);

        $buscado = $this->lote($a, 'PPQ junio A', '2026-06-10');
        $pagado = $this->lote($a, 'PPQ junio pagado', '2026-06-15', 'pagado');
        $deOtro = $this->lote($b, 'PPQ junio B', '2026-06-12');
        $julio = $this->lote($a, 'PPQ julio', '2026-07-01');

        // Cliente + estado + rango: solo el de junio en borrador de Calleja.
        $this->assertSame([$buscado->id], $this->ids($this->historial([
            'cliente_id' => $a->id, 'estado' => 'borrador', 'desde' => '2026-06-01', 'hasta' => '2026-06-30',
        ])->assertOk()));

        // Texto + cliente.
        $this->assertSame([$deOtro->id], $this->ids($this->historial(['q' => 'junio', 'cliente_id' => $b->id])));

        // Texto solo: las tres de junio, recientes primero.
        $this->assertSame([$pagado->id, $deOtro->id, $buscado->id], $this->ids($this->historial(['q' => 'junio'])));

        // Número de lote, con o sin «#».
        $this->assertSame([$julio->id], $this->ids($this->historial(['q' => '#'.$julio->id])));
        $this->assertContains($julio->id, $this->ids($this->historial(['q' => (string) $julio->id])));

        // Estado solo.
        $this->assertSame([$pagado->id], $this->ids($this->historial(['estado' => 'pagado'])));
    }

    public function test_un_filtro_invalido_se_ignora_sin_romper_la_pantalla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->lote($cliente, 'PPQ uno', '2026-06-10');
        $this->lote($cliente, 'PPQ dos', '2026-06-11');

        $respuesta = $this->historial(['estado' => 'inventado', 'desde' => '2026-02-30', 'cliente_id' => 'abc'])->assertOk();

        $this->assertCount(2, $this->ids($respuesta));
        $filtros = $respuesta->viewData('filtros');
        $this->assertSame('', $filtros['estado']);
        $this->assertSame('', $filtros['desde'], 'Una fecha que no existe no se interpreta como otra.');
        $this->assertSame('', $filtros['cliente_id']);
    }

    public function test_filtros_enviados_como_arreglo_se_ignoran_sin_error(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->lote($cliente, 'PPQ uno', '2026-06-10');
        $this->lote($cliente, 'PPQ dos', '2026-06-11');

        // `?q[]=x&desde[]=x&...`: llegan como arreglos, no como texto.
        $url = route('ppq.lotes.index').'?q[]=uno&desde[]=2026-06-10&hasta[]=x&cliente_id[]='.$cliente->id.'&estado[]=borrador';

        $respuesta = $this->actingAs($this->usuario())->get($url)->assertOk();

        $this->assertCount(2, $this->ids($respuesta), 'Ningún filtro aplicado: se ven todos.');
        $this->assertSame(
            ['cliente_id' => '', 'estado' => '', 'desde' => '', 'hasta' => '', 'q' => ''],
            $respuesta->viewData('filtros'),
        );
        $this->assertFalse($respuesta->viewData('hayFiltros'));
    }

    public function test_sin_coincidencias_se_dice_y_se_ofrece_quitar_filtros(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->lote($cliente, 'PPQ uno', '2026-06-10');

        $this->historial(['q' => 'no existe nada así'])
            ->assertOk()
            ->assertSeeText('Ningún lote coincide con los filtros')
            ->assertSeeText('Quitar filtros');
    }

    // ------------------------------------------------------------------ páginas y orden

    public function test_las_paginas_conservan_los_filtros_y_no_pierden_ni_repiten_lotes_con_la_misma_fecha(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        // 45 en borrador, muchos con la MISMA fecha: el id tiene que desempatar.
        $borradores = collect(range(1, 45))->map(fn (int $i) => $this->lote(
            $cliente, 'PPQ '.$i, $i <= 30 ? '2026-06-10' : '2026-06-'.str_pad((string) ($i - 19), 2, '0', STR_PAD_LEFT)
        ));
        foreach (range(1, 5) as $i) {
            $this->lote($cliente, 'PPQ pagado '.$i, '2026-06-10', 'pagado');
        }
        $usuario = $this->usuario();

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            $respuesta = $this->historial(['estado' => 'borrador', 'cliente_id' => $cliente->id, 'page' => $pagina], $usuario)->assertOk();
            $paginador = $respuesta->viewData('lotes');
            $this->assertSame(45, $paginador->total());
            $vistos = array_merge($vistos, $paginador->pluck('id')->all());

            if ($pagina < 3) {
                $siguiente = $paginador->url($pagina + 1);
                $this->assertStringContainsString('estado=borrador', $siguiente);
                $this->assertStringContainsString('cliente_id='.$cliente->id, $siguiente);
            }
        }

        $this->assertCount(45, array_unique($vistos), 'Ningún lote repetido.');
        $this->assertEqualsCanonicalizing($borradores->pluck('id')->all(), $vistos, 'Ningún lote perdido.');

        // Orden: fecha descendente y, a igual fecha, id descendente.
        $esperado = $borradores
            ->sortBy([
                fn (PpqLote $x, PpqLote $y) => strcmp($y->fecha->toDateString(), $x->fecha->toDateString()),
                fn (PpqLote $x, PpqLote $y) => $y->id <=> $x->id,
            ])
            ->pluck('id')->values()->all();
        $this->assertSame($esperado, $vistos);
    }

    public function test_cada_fila_trae_su_conteo_y_total_neto_de_ccf_menos_nc(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $lote = $this->lote($cliente, 'PPQ con items', '2026-06-10');
        foreach ([['03', 100.00, '0001'], ['03', 50.00, '0002'], ['05', 20.00, '0003']] as [$tipo, $monto, $n]) {
            PpqItem::create([
                'ppq_lote_id' => $lote->id,
                'origen' => 'gmail',
                'tipo_dte' => $tipo,
                'numero_control' => 'DTE-'.$tipo.'-M001P002-00000000000'.$n,
                'monto_dte' => $monto,
                'sin_albaran' => true,
            ]);
        }

        $fila = $this->historial(['q' => 'con items'])->viewData('lotes')->first();

        $this->assertSame(3, $fila->items_count);
        $this->assertEqualsWithDelta(130.00, (float) $fila->total_dte, 0.001, 'CCF suma, NC resta.');
    }

    // ------------------------------------------------------------------ permisos

    public function test_la_lectura_basta_con_ppq_ver(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->lote($cliente, 'PPQ uno', '2026-06-10');

        $this->historial(['estado' => 'borrador'], $this->usuario(RolSistema::Jefatura))
            ->assertOk()
            ->assertSee('PPQ uno')
            ->assertDontSee(route('ppq.lotes.create'), false);

        $this->historial([], $this->usuario(RolSistema::Produccion))->assertForbidden();
    }
}
