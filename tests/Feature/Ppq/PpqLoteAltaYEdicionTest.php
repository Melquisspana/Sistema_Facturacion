<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPpq;
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
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * «Nuevo PPQ» y «Editar» de un lote, recorridos como la oficina y como alguien que arma
 * el pedido a mano.
 *
 * El formulario solo manda referencia, fecha y observaciones. El estado lo mueve el
 * sistema (borrador → presentado → pagado) y el cliente es el del pronto pago: si se
 * pudieran mandar en la petición, un lote presentado volvía a borrador y dejaba quitar o
 * agregar CCF que en Cobros ya figuran presentados (el CCF trabado del «quitar»).
 */
class PpqLoteAltaYEdicionTest extends TestCase
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

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
        config(['ppq.cliente_default_id' => (string) $this->cliente->id]);
    }

    private function usuario(RolSistema $rol = RolSistema::Facturacion): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function ccf(): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (900 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOPRUEBA'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
        ]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(6000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    /** Lote con un CCF, marcado presentado por el botón de la ficha. */
    private function lotePresentado(User $usuario): array
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $lote->update(['fecha' => today()->subDay()]);
        $this->actingAs($usuario)->post(route('ppq.lotes.marcar-presentado', $lote), ['fecha' => today()->toDateString()])
            ->assertSessionHas('status');

        $this->assertSame(EstadoPpq::Enviado, $lote->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $doc->refresh()->presentacion_estado);

        return [$lote, $doc];
    }

    // ------------------------------------------------------------------ Nuevo PPQ

    public function test_nuevo_ppq_nace_en_borrador_y_con_el_cliente_del_pronto_pago_aunque_la_peticion_diga_otra_cosa(): void
    {
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('ppq.lotes.store'), [
            'referencia' => 'PPQ semana de prueba',
            'fecha' => '2026-11-02',
            'observaciones' => 'Armado a mano',
            'estado' => EstadoPpq::Pagado->value,
            'cliente_id' => $otro->id,
        ])->assertRedirect();

        $lote = PpqLote::where('referencia', 'PPQ semana de prueba')->sole();
        $this->assertSame(EstadoPpq::Borrador, $lote->estado, 'Un lote nunca nace pagado ni presentado.');
        $this->assertSame($this->cliente->id, $lote->cliente_id, 'El cliente es el del pronto pago, no el que mande la petición.');
        $this->assertSame($usuario->id, $lote->user_id);
        $this->assertSame('Armado a mano', $lote->observaciones);
    }

    public function test_quien_solo_ve_ppq_no_crea_lotes(): void
    {
        foreach ([RolSistema::Jefatura, RolSistema::Contabilidad] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('ppq.lotes.store'), ['referencia' => 'No va', 'fecha' => '2026-11-02'])
                ->assertForbidden();
        }

        $this->assertSame(0, PpqLote::count());
    }

    public function test_nuevo_ppq_sin_referencia_o_con_fecha_invalida_no_se_crea(): void
    {
        $this->actingAs($this->usuario())->post(route('ppq.lotes.store'), ['referencia' => '', 'fecha' => 'ayer'])
            ->assertSessionHasErrors(['referencia', 'fecha']);

        $this->assertSame(0, PpqLote::count());
    }

    // ------------------------------------------------------------------ Editar

    public function test_editar_un_borrador_cambia_referencia_fecha_y_observaciones_y_se_ven_en_la_ficha(): void
    {
        $usuario = $this->usuario();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);

        $this->actingAs($usuario)->put(route('ppq.lotes.update', $lote), [
            'referencia' => 'PPQ corregido',
            'fecha' => '2026-09-15',
            'observaciones' => 'Va con la entrega del lunes',
        ])->assertRedirect(route('ppq.lotes.show', $lote))->assertSessionHas('status', 'Lote PPQ actualizado.');

        $lote->refresh();
        $this->assertSame('PPQ corregido', $lote->referencia);
        $this->assertSame('2026-09-15', $lote->fecha->toDateString());
        $this->assertSame('Va con la entrega del lunes', $lote->observaciones);
        $this->assertSame(EstadoPpq::Borrador, $lote->estado);

        $this->actingAs($usuario)->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertSee('PPQ corregido')->assertSee('Va con la entrega del lunes');

        $this->assertSame(1, DB::table('activity_log')->where('description', 'ppq.lote_editado')
            ->where('subject_id', $lote->id)->where('causer_id', $usuario->id)->count(), 'La edición queda en la bitácora.');
    }

    public function test_editar_un_borrador_no_acepta_estado_ni_cliente_desde_la_peticion(): void
    {
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);

        $this->actingAs($this->usuario())->put(route('ppq.lotes.update', $lote), [
            'referencia' => 'PPQ corregido',
            'fecha' => '2026-09-15',
            'estado' => EstadoPpq::Pagado->value,
            'cliente_id' => $otro->id,
        ])->assertRedirect(route('ppq.lotes.show', $lote));

        $lote->refresh();
        $this->assertSame(EstadoPpq::Borrador, $lote->estado, 'El estado lo mueve el sistema, no el formulario.');
        $this->assertSame($this->cliente->id, $lote->cliente_id);
    }

    public function test_un_ppq_presentado_no_se_reabre_con_un_put_hecho_a_mano(): void
    {
        $usuario = $this->usuario();
        [$lote, $doc] = $this->lotePresentado($usuario);
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);
        $antes = $lote->only(['referencia', 'observaciones', 'cliente_id']) + ['fecha' => $lote->fecha->toDateString()];

        $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))->put(route('ppq.lotes.update', $lote), [
            'referencia' => 'Reabierto',
            'fecha' => '2026-09-01',
            'estado' => EstadoPpq::Borrador->value,
            'cliente_id' => $otro->id,
        ])->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('error', 'Este PPQ está Presentado y ya no se edita.');

        $lote->refresh();
        $this->assertSame(EstadoPpq::Enviado, $lote->estado, 'El lote presentado no vuelve a borrador.');
        $this->assertSame($antes, $lote->only(['referencia', 'observaciones', 'cliente_id']) + ['fecha' => $lote->fecha->toDateString()]);

        // Y por eso el CCF sigue sin poder quitarse: en Cobros sigue presentado.
        $item = $lote->items()->where('dte_id', $doc->dte_id)->sole();
        $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))
            ->delete(route('ppq.lotes.items.destroy', [$lote, $item]))->assertSessionHas('error');
        $this->assertTrue($lote->items()->whereKey($item->id)->exists());
        $this->assertSame(EstadoPresentacionCobro::Presentada, $doc->refresh()->presentacion_estado);
    }

    public function test_un_ppq_pagado_tampoco_se_edita(): void
    {
        $usuario = $this->usuario();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $lote->update(['estado' => EstadoPpq::Pagado]);

        $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))->put(route('ppq.lotes.update', $lote), [
            'referencia' => 'Reabierto', 'fecha' => '2026-09-01', 'estado' => EstadoPpq::Borrador->value,
        ])->assertSessionHas('error');

        $this->assertSame(EstadoPpq::Pagado, $lote->refresh()->estado);
        $this->assertNotSame('Reabierto', $lote->referencia);
    }

    public function test_quien_solo_ve_ppq_no_edita_y_no_cambia_nada(): void
    {
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $referencia = $lote->referencia;

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->put(route('ppq.lotes.update', $lote), ['referencia' => 'No va', 'fecha' => '2026-09-01'])
            ->assertForbidden();

        $this->assertSame($referencia, $lote->refresh()->referencia);
    }
}
