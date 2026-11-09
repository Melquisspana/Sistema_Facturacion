<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoDte;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * ALTA en el seguimiento: los CCF aceptados entran solos, los incorporados a mano no se
 * duplican, y lo que ya pasó por el circuito anterior se SEÑALA en vez de suponerse.
 *
 * La regla que define el módulo está en la primera prueba: el alta la dispara la
 * ACEPTACIÓN del documento, no que alguien se acuerde de agregarlo. Si dependiera de que
 * alguien lo agregue, la factura olvidada —la que hay que controlar— sería justo la que
 * nunca aparecería.
 */
class CobrosAltaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** @var array{estab: Establecimiento, pv: PuntoVenta}|null */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
    }

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisorUnico(): array
    {
        if ($this->emisor !== null) {
            return $this->emisor;
        }

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return $this->emisor = compact('estab', 'pv');
    }

    /** Un CCF realmente ACEPTADO por Hacienda. */
    private function ccfAceptado(Cliente $cliente, string $oc = '26090017003463'): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisorUnico();

        $producto = Producto::factory()->create([
            'nombre' => 'MANI HORNEADO',
            'precio_unitario' => 1.04,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $ccf = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $ccf->forceFill(['numero_orden_compra' => $oc])->save();
        app(DteBorradorService::class)->agregarLineaDesdeProducto($ccf, $producto, cantidad: 100);
        app(DteGeneracionService::class)->generar($ccf);

        $ccf->refresh()->forceFill([
            'sello_recepcion' => '2026SELLO'.str_pad((string) $ccf->id, 31, 'X'),
            'fecha_procesamiento_mh' => now(),
            'estado' => EstadoDte::Aceptado->value,
        ])->save();

        return $ccf->refresh();
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · un CCF aceptado entra al seguimiento sin que nadie lo agregue, con su
     * identidad fiscal completa y su serie conservada.
     */
    public function test_dorada_un_ccf_aceptado_entra_solo_al_seguimiento(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertSame(1, $resumen['creados']);

        $documento = CobroDocumento::firstOrFail();
        $this->assertSame($ccf->id, $documento->dte_id);
        $this->assertSame(OrigenCobroDocumento::Dte, $documento->origen);
        $this->assertSame($ccf->numero_control, $documento->numero_control);
        $this->assertSame($ccf->codigo_generacion, $documento->codigo_generacion);
        $this->assertSame($ccf->sello_recepcion, $documento->sello_recepcion);
        $this->assertSame(0, bccomp((string) $ccf->total_pagar, (string) $documento->monto, 2));
        $this->assertNotNull($documento->establecimiento_codigo);
        $this->assertNotNull($documento->punto_venta_codigo);
    }

    /** DORADA · sincronizar dos veces no crea nada nuevo ni pisa ningún estado. */
    public function test_dorada_sincronizar_es_idempotente(): void
    {
        $cliente = $this->cliente();
        $this->ccfAceptado($cliente);

        $servicio = app(AltaCobrosService::class);
        $servicio->sincronizar($cliente);

        $documento = CobroDocumento::firstOrFail();
        $documento->forceFill(['observaciones' => 'Nota que no se debe perder.'])->save();

        $segundo = $servicio->sincronizar($cliente);

        $this->assertSame(0, $segundo['creados']);
        $this->assertSame(1, $segundo['sin_cambio']);
        $this->assertSame(1, CobroDocumento::count());
        $this->assertSame('Nota que no se debe perder.', $documento->refresh()->observaciones);
    }

    /**
     * DORADA · un documento incorporado a mano que después aparece emitido no se duplica:
     * el alta lo ADOPTA y le completa lo que no podía tener.
     */
    public function test_dorada_el_alta_adopta_el_documento_incorporado_a_mano(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);

        // Alguien lo había cargado a mano, sin sello y con el número escrito sin guiones.
        $servicio = app(AltaCobrosService::class);
        $manual = $servicio->incorporar($cliente, [
            'numero_control' => str_replace('-', '', (string) $ccf->numero_control),
            'monto' => null,
            'observaciones' => 'Lo pasó contabilidad.',
        ]);

        $resumen = $servicio->sincronizar($cliente);

        $this->assertSame(0, $resumen['creados'], 'No se crea un segundo seguimiento.');
        $this->assertSame(1, $resumen['adoptados']);
        $this->assertSame(1, CobroDocumento::count());

        $manual->refresh();
        $this->assertSame($ccf->id, $manual->dte_id);
        $this->assertSame(OrigenCobroDocumento::Dte, $manual->origen);
        $this->assertSame($ccf->sello_recepcion, $manual->sello_recepcion, 'Se completó lo que faltaba.');
        $this->assertSame('Lo pasó contabilidad.', $manual->observaciones, 'No se pisó lo que había.');
    }

    /**
     * DORADA · un documento que ya viajó por el circuito anterior se SEÑALA para revisión
     * histórica, con el lote, en vez de darse por pendiente o por cobrado.
     */
    public function test_dorada_un_antecedente_en_ppq_marca_revision_historica(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);

        $lote = PpqLote::create(['referencia' => 'PPQ-JUNIO', 'fecha' => now(), 'estado' => 'listo']);
        PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'tipo_dte' => '03',
            'numero_control' => $ccf->numero_control,
            'monto_dte' => $ccf->total_pagar,
            'conciliacion_estado' => 'pagado',
            'monto_pagado' => $ccf->total_pagar,
        ]);

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertSame(1, $resumen['revision_historica']);

        $documento = CobroDocumento::firstOrFail();
        $this->assertTrue($documento->revisar_historico);
        $this->assertStringContainsString('PPQ-JUNIO', (string) $documento->revisar_historico_motivo);
        $this->assertStringContainsString('cobrado', (string) $documento->revisar_historico_motivo);

        // Y NO se le copia el pago: la evidencia de aquel cobro vive en el otro circuito.
        $this->assertSame(0, bccomp('0', (string) $documento->monto_pagado, 2));
        $this->assertSame('pendiente', $documento->pago_estado->value);
    }

    /** Un CCF que todavía no tiene aceptación real del MH no entra. */
    public function test_un_documento_sin_aceptacion_real_no_entra(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);

        // Sello simulado: no es una aceptación real.
        $ccf->forceFill(['sello_recepcion' => 'MOCK-123'])->save();

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertSame(0, $resumen['creados']);
        $this->assertSame(0, CobroDocumento::count());
    }

    /** Los documentos de otro cliente no entran en este seguimiento. */
    public function test_solo_entran_los_documentos_del_cliente(): void
    {
        $calleja = $this->cliente();
        $otro = Cliente::factory()->contribuyente()->create();

        $this->ccfAceptado($calleja);
        $this->ccfAceptado($otro);

        app(AltaCobrosService::class)->sincronizar($calleja);

        $this->assertSame(1, CobroDocumento::count());
        $this->assertSame($calleja->id, CobroDocumento::firstOrFail()->cliente_id);
    }

    /**
     * Issue #13: el número de control ya está en el seguimiento de OTRO cliente (la llave es
     * única en todo el sistema). Antes la tarea de cada 10 minutos se caía ahí y los demás
     * no entraban; ahora lo informa, no toca el ajeno y sigue.
     */
    public function test_un_numero_que_ya_esta_en_otro_cliente_no_tumba_el_alta(): void
    {
        Log::spy();
        $cliente = $this->cliente();
        $otro = Cliente::factory()->contribuyente()->create();
        $choca = $this->ccfAceptado($cliente, '26090017003463');
        $sigue = $this->ccfAceptado($cliente, '26090017003464');
        $ajeno = CobroDocumento::create([
            'cliente_id' => $otro->id, 'origen' => OrigenCobroDocumento::Externo->value, 'tipo_dte' => '03',
            'numero_control' => $choca->numero_control, 'fecha_emision' => $choca->fecha_emision, 'monto' => '10.00',
        ]);

        config()->set('cobros.alta.automatica', true);
        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->expectsOutputToContain('1 ya está(n) en el seguimiento de otro cliente')
            ->assertExitCode(0);

        $this->assertSame($sigue->id, CobroDocumento::deCliente($cliente->id)->sole()->dte_id, 'Los demás sí entraron.');
        $this->assertSame($otro->id, (int) $ajeno->refresh()->cliente_id);
        $this->assertNull($ajeno->dte_id, 'El del otro cliente no se toca.');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($mensaje, $datos) => str_contains($mensaje, 'otro cliente')
            && $datos['dte_id'] === $choca->id && $datos['cobro_documento_id'] === $ajeno->id);

        // El botón de la pantalla también lo dice.
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate(RolSistema::Administrador->value, 'web')->syncPermissions(PermisoSistema::paraRol(RolSistema::Administrador));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->post(route('cobros.sincronizar', $cliente))->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 no entraron porque ya están en el seguimiento de otro cliente'));
    }
}
