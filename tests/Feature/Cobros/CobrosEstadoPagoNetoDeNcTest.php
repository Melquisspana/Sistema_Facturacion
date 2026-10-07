<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * No existen pagos parciales: el cliente paga el CCF MENOS sus notas de crédito, y la
 * línea CF del archivo de pagos llega neta (issue #14). Pagado = lo cobrado cubre el total
 * del CCF menos sus NC aceptadas y sin invalidar; cualquier otro faltante o sobrante es
 * una diferencia visible, nunca «Pagado».
 */
class CobrosEstadoPagoNetoDeNcTest extends TestCase
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
            'sello_recepcion' => '2026SELLO'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '100.00',
        ]);
    }

    private function nc(Dte $ccf, string $total, array $extra = []): Dte
    {
        return $this->dte('05', $extra + ['dte_relacionado_id' => $ccf->id, 'total_pagar' => $total]);
    }

    /** Un CCF de 100.00 con su seguimiento de cobro. */
    private function ccf(): CobroDocumento
    {
        $ccf = $this->dte('03');

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

    private function pagar(CobroDocumento $documento, string $valor): CobroDocumento
    {
        $numero = str_replace('-', '', $documento->numero_control);
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;{$numero};10-SEP-26;{$valor}\n";

        app(AplicadorPagosTxt::class)->aplicar(
            $this->cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'pagos-'.$documento->id.'.txt'),
        );

        return $documento->refresh();
    }

    private function bandeja(string $etapa)
    {
        return $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'etapa' => $etapa]))
            ->assertOk();
    }

    public function test_ccf_con_nc_aceptada_pagado_neto_queda_pagado(): void
    {
        $documento = $this->ccf();
        $this->nc($documento->dte, '12.34');

        $this->pagar($documento, '87.66');

        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
        $this->assertSame(0, Dinero::comparar('0', $documento->saldo()));
    }

    public function test_solo_descuentan_las_nc_aceptadas_y_sin_invalidar(): void
    {
        $documento = $this->ccf();
        $this->nc($documento->dte, '10.00');
        $this->nc($documento->dte, '20.00', ['estado' => 'firmado', 'sello_recepcion' => null]);
        $this->nc($documento->dte, '30.00', ['estado' => 'invalidado', 'sello_invalidacion' => 'SELLOINV'.Str::random(8)]);

        $this->pagar($documento, '90.00');

        $this->assertSame(0, Dinero::comparar('90.00', $documento->facturadoEfectivo()));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
    }

    /** Si la línea llega bruta pese a una NC aceptada, se cobró de más: no cuadra. */
    public function test_linea_bruta_con_nc_aceptada_queda_en_diferencia(): void
    {
        $documento = $this->ccf();
        $this->nc($documento->dte, '12.34');

        $this->pagar($documento, '100.00');

        $this->assertSame(EstadoPagoCobro::Diferencia, $documento->pago_estado);
    }

    /** Un faltante sin NC que lo explique no es un pago: va aparte y señalado. */
    public function test_faltante_sin_nc_no_figura_como_pagado(): void
    {
        $pagado = $this->pagar($this->ccf(), '100.00');
        $faltante = $this->pagar($this->ccf(), '60.00');

        $this->assertSame(EstadoPagoCobro::Pagado, $pagado->pago_estado);
        $this->assertSame(EstadoPagoCobro::Parcial, $faltante->pago_estado);

        $pagados = $this->bandeja('pagados');
        $this->assertSame(1, $pagados->viewData('etapas')['pagados']);
        $this->assertSame(1, $pagados->viewData('etapas')['diferencias']);
        $pagados->assertSee($pagado->numero_control);
        $pagados->assertDontSee($faltante->numero_control);

        $diferencias = $this->bandeja('diferencias');
        $diferencias->assertSee($faltante->numero_control);
        $diferencias->assertDontSee($pagado->numero_control);
        $diferencias->assertSeeText('Faltante');
    }

    /** El documento de seguimiento de una NC, como lo da de alta el Seguimiento. */
    private function seguimientoNc(Dte $nc): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $nc->id,
            'tipo_dte' => '05', 'numero_control' => $nc->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => $nc->total_pagar,
        ]);
    }

    /** @param  array<int, array{0: string, 1: string, 2: string}>  $lineas  [tipo, numero_control, valor] */
    private function archivo(array $lineas, string $nombre): void
    {
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n";
        foreach ($lineas as [$tipo, $numero, $valor]) {
            $txt .= "000123;TITULAR DE EJEMPLO;{$tipo};".str_replace('-', '', $numero).";10-SEP-26;{$valor}\n";
        }

        app(AplicadorPagosTxt::class)->aplicar(
            $this->cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, $nombre),
        );
    }

    /**
     * Formato «bruto» real de Calleja: la línea CF por el TOTAL del CCF y la NC en su propia
     * línea negativa. El CCF queda pagado, venga la NC antes o después de la CF.
     */
    public function test_cf_por_el_total_y_nc_en_linea_aparte_queda_pagado(): void
    {
        foreach (['nc_despues' => false, 'nc_antes' => true] as $caso => $ncPrimero) {
            $documento = $this->ccf();
            $nc = $this->nc($documento->dte, '12.34');
            $seguimientoNc = $this->seguimientoNc($nc);
            $lineas = [['CF', $documento->numero_control, '100.00'], ['NC', $nc->numero_control, '-12.34']];

            $this->archivo($ncPrimero ? array_reverse($lineas) : $lineas, "bruto-{$caso}.txt");

            $this->assertSame(EstadoPagoCobro::Pagado, $documento->refresh()->pago_estado, $caso);
            $this->assertSame(EstadoPagoCobro::Pagado, $seguimientoNc->refresh()->pago_estado, $caso);
            $this->assertSame(0, Dinero::comparar('100.00', $documento->facturadoEfectivo()), $caso);
        }
    }

    /** El mismo archivo puede traer un CCF neto y otro bruto: los dos quedan pagados. */
    public function test_archivo_mezclado_neto_y_bruto_queda_pagado(): void
    {
        $neto = $this->ccf();
        $this->nc($neto->dte, '12.34');
        $bruto = $this->ccf();
        $ncBruto = $this->nc($bruto->dte, '4.75');
        $this->seguimientoNc($ncBruto);

        $this->archivo([
            ['CF', $neto->numero_control, '87.66'],
            ['CF', $bruto->numero_control, '100.00'],
            ['NC', $ncBruto->numero_control, '-4.75'],
        ], 'mezclado.txt');

        $this->assertSame(EstadoPagoCobro::Pagado, $neto->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pagado, $bruto->refresh()->pago_estado);
    }

    /**
     * Caso del CCF 35: la NC se registró como descontada A MANO (sin archivo de pagos) y el
     * CCF se cobró por su total. También cuenta como descuento propio de la NC.
     */
    public function test_nc_descontada_con_pago_manual_tampoco_se_resta(): void
    {
        $documento = $this->ccf();
        $nc = $this->nc($documento->dte, '6.50');
        $seguimientoNc = $this->seguimientoNc($nc);
        CobroEvento::create(['cobro_documento_id' => $seguimientoNc->id, 'tipo' => 'pago', 'origen' => 'manual',
            'monto' => '6.50', 'fecha' => '2026-08-04', 'detalle' => 'Descontada por Calleja (registro manual).']);

        $this->archivo([['CF', $documento->numero_control, '100.00']], 'sin-linea-nc.txt');

        $this->assertSame(0, Dinero::comparar('100.00', $documento->refresh()->facturadoEfectivo()));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
    }

    /** Reparación de los CCF que quedaron «con diferencia» con la regla anterior. */
    public function test_comando_recalcula_los_afectados_y_es_idempotente(): void
    {
        $documento = $this->ccf();
        $nc = $this->nc($documento->dte, '1.93');
        $this->seguimientoNc($nc);
        $this->archivo([['CF', $documento->numero_control, '100.00'], ['NC', $nc->numero_control, '-1.93']], 'agosto.txt');
        $documento->refresh()->forceFill(['pago_estado' => EstadoPagoCobro::Diferencia->value])->save();   // como quedó en producción

        $this->artisan('cobros:recalcular-pagos', ['--dry-run' => true])
            ->expectsOutputToContain('Cambiarían: 1 de')->assertSuccessful();
        $this->assertSame(EstadoPagoCobro::Diferencia, $documento->refresh()->pago_estado);

        $this->artisan('cobros:recalcular-pagos')->expectsOutputToContain('Recalculados: 1 de')->assertSuccessful();
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->refresh()->pago_estado);
        $this->artisan('cobros:recalcular-pagos')->expectsOutputToContain('Recalculados: 0 de')->assertSuccessful();
    }
}
