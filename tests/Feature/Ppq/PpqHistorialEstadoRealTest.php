<?php

namespace Tests\Feature\Ppq;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ReporteCasoCalleja;
use App\Services\Ppq\EstadoRealLotePpq;
use App\Support\IdentidadPpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PpqHistorialEstadoRealTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente historial']);
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate(RolSistema::Administrador->value, 'web')->syncPermissions(PermisoSistema::paraRol(RolSistema::Administrador));
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value));
    }

    private function documento(): CobroDocumento
    {
        $this->numero++;
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.$this->numero, 'monto_albaran' => 100]);

        return CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'gmail', 'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->numero, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-09-01', 'monto' => 100, 'ppq_albaran_id' => $albaran->id]);
    }

    private function real(PpqLote $lote): array
    {
        return app(EstadoRealLotePpq::class)->calcular(collect([$lote->fresh()]))[$lote->id];
    }

    public function test_circuito_desde_seguimiento_hasta_pago_y_diferencias(): void
    {
        $a = $this->documento();
        $b = $this->documento();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]);
        $this->assertSame('armado', $this->real($lote)['estado']['key']);
        $this->get(route('ppq.lotes.index'))->assertOk()->assertSeeText('Armado');
        app(ReporteCasoCalleja::class)->aplicar($this->cliente, ['caso' => '123', 'documentos' => [
            IdentidadPpq::normalizar($a->numero_control) => 'REGISTRADO',
            IdentidadPpq::normalizar($b->numero_control) => 'REGISTRADO',
        ]], null, $lote);
        $this->assertSame('presentado', $this->real($lote)['estado']['key']);
        $this->assertSame(today()->toDateString(), $this->real($lote)['fecha']->toDateString());
        $a->refresh()->forceFill(['pago_estado' => 'pagado', 'monto_pagado' => 100])->save();
        $this->get(route('ppq.lotes.index'))->assertOk()->assertSeeText('En cobro · 1 de 2 pagados');
        $b->refresh()->forceFill(['pago_estado' => 'pagado', 'monto_pagado' => 100])->save();
        $this->assertSame('pagado', $this->real($lote)['estado']['key']);
        $b->refresh()->forceFill(['pago_estado' => 'parcial', 'monto_pagado' => 50])->save();
        $this->get(route('ppq.lotes.show', $lote))->assertOk()->assertSeeText('Con diferencias');
    }

    public function test_devuelto_por_caso_es_etiqueta_aparte(): void
    {
        $a = $this->documento();
        $b = $this->documento();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]);
        app(ReporteCasoCalleja::class)->aplicar($this->cliente, ['caso' => '456', 'documentos' => [IdentidadPpq::normalizar($a->numero_control) => 'REGISTRADO']], null, $lote);
        $this->assertSame(1, $this->real($lote)['devueltos']);
        $this->get(route('ppq.lotes.index'))->assertOk()->assertSeeText('Devuelto: 1');
    }

    public function test_filtro_derivado_pagina_y_conserva_query(): void
    {
        foreach (range(1, 21) as $i) {
            $a = $this->documento();
            $b = $this->documento();
            app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]);
            $a->refresh()->forceFill(['pago_estado' => 'pagado', 'monto_pagado' => 100])->save();
        }
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->documento()->id]);
        $uno = $this->get(route('ppq.lotes.index', ['estado' => 'en_cobro', 'q' => 'PPQ']))->assertOk()->viewData('lotes');
        $dos = $this->get(route('ppq.lotes.index', ['estado' => 'en_cobro', 'q' => 'PPQ', 'page' => 2]))->assertOk()->viewData('lotes');
        $this->assertSame(21, $uno->total());
        $this->assertCount(20, $uno);
        $this->assertCount(1, $dos);
        $this->assertStringContainsString('estado=en_cobro', $uno->url(2));
        $this->assertStringContainsString('q=PPQ', $uno->url(2));
        $this->assertEmpty($uno->pluck('id')->intersect($dos->pluck('id')));
    }

    public function test_anterior_sin_seguimiento(): void
    {
        PpqLote::create(['referencia' => 'Viejo', 'fecha' => today(), 'estado' => 'enviado']);
        $this->get(route('ppq.lotes.index', ['estado' => 'anterior']))->assertOk()->assertSeeText('Presentado (anterior)');
    }

    public function test_cliente_derivado_comando_seco_e_idempotente_incluye_borrados(): void
    {
        $doc = $this->documento();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $lote->refresh()->update(['cliente_id' => null]);
        $this->get(route('ppq.lotes.index', ['cliente_id' => $this->cliente->id]))->assertOk()->assertSeeText($this->cliente->nombre);
        $this->artisan('ppq:completar-cliente-lotes', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($lote->refresh()->cliente_id);
        $lote->delete();
        $this->artisan('ppq:completar-cliente-lotes')->assertSuccessful();
        $this->assertSame($this->cliente->id, $lote->refresh()->cliente_id);
        $this->artisan('ppq:completar-cliente-lotes')->expectsOutput('Propuestos: 0 · Actualizados: 0 · Omitidos: 0')->assertSuccessful();
    }

    public function test_importes_con_nc(): void
    {
        $doc = $this->documento();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $lote->items()->create(['origen' => 'gmail', 'tipo_dte' => '05', 'numero_control' => 'DTE-05-M001P002-000000000000001', 'monto_dte' => 20]);
        $doc->refresh()->forceFill(['pago_estado' => 'parcial', 'monto_pagado' => 50])->save();
        $real = $this->real($lote);
        $this->assertSame(80.0, $real['total_neto']);
        $this->assertSame(50.0, $real['cobrado']);
        $this->assertSame(30.0, $real['pendiente']);
        $this->get(route('ppq.lotes.index'))->assertOk()->assertSeeText('Cobrado $50.00 · Pendiente $30.00');
    }
}
