<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\User;
use App\Services\Cobros\EstadoCobroDesdeInicio;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Dte\SaldoMontoCcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RevisaYAplicaTxt;
use Tests\TestCase;

/**
 * La NC de PRONTO PAGO es el descuento global del QD de un PPQ. Para tener relacionado
 * fiscal se ata a UN solo CCF, pero Calleja paga ese CCF por su total en la línea CF y el
 * descuento viene aparte en la línea QD. Por eso no se resta del cobro de ningún CCF:
 * restarlo dejaba el CCF «Pagado con diferencia» (caso del usuario: el 308, de 149.70, salía
 * esperando 48.02). El saldo FISCAL para emitir NC sí la sigue contando.
 */
class CobrosProntoPagoNoRestaDelCcfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RevisaYAplicaTxt;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['cobros.inicio_seguimiento' => '2026-07-01']);

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
            'monto_total_operacion' => '100.00',
        ]);
    }

    private function ccf(string $monto): CobroDocumento
    {
        $dte = $this->dte('03', ['total_pagar' => $monto, 'monto_total_operacion' => $monto]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => $monto,
        ]);
    }

    private function nc(CobroDocumento $ccf, string $total, TipoNotaCredito $tipo): Dte
    {
        return $this->dte('05', ['dte_relacionado_id' => $ccf->dte_id, 'total_pagar' => $total,
            'monto_total_operacion' => $total, 'tipo_nota_credito' => $tipo->value]);
    }

    private function linea(string $tipo, string $numero, string $valor): string
    {
        return '000123;TITULAR DE EJEMPLO;'.$tipo.';'.$numero.';10-SEP-26;'.$valor."\n";
    }

    private function subir(string $lineas): void
    {
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n".$lineas;
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->revisarYAplicarTxt($this->cliente, ['archivo' => UploadedFile::fake()->createWithContent('pagos.txt', $txt)])
            ->assertOk();
    }

    public function test_el_ccf_que_lleva_la_nc_de_pronto_pago_del_qd_queda_pagado_sin_diferencia(): void
    {
        [$a, $b, $c] = [$this->ccf('100.00'), $this->ccf('100.00'), $this->ccf('149.70')];
        // Los dos patrones que se vieron en producción: NC de pronto pago chicas, una por CCF
        // (A y B), y la del QD completo del PPQ atada a un solo CCF (C).
        $this->nc($a, '1.93', TipoNotaCredito::ProntoPago);
        $this->nc($b, '0.96', TipoNotaCredito::ProntoPago);
        $pronto = $this->nc($c, '101.68', TipoNotaCredito::ProntoPago);

        $this->subir($this->linea('CF', str_replace('-', '', $a->numero_control), '100.00')
            .$this->linea('CF', str_replace('-', '', $b->numero_control), '100.00')
            .$this->linea('CF', str_replace('-', '', $c->numero_control), '149.70')
            .$this->linea('QD', 'PPQ/33762', '-104.57'));

        foreach ([$a, $b, $c] as $doc) {
            $this->assertSame(EstadoPagoCobro::Pagado, $doc->refresh()->pago_estado, $doc->numero_control);
        }
        $this->assertSame('149.70', $c->facturadoEfectivo());
        $this->assertSame('0.00', $c->saldo());

        $fila = collect(app(EstadoCobroDesdeInicio::class)->generar($this->cliente)['ccf'])->firstWhere('documento.id', $c->id);
        $this->assertSame('pagado', $fila['estado']);
        $this->assertSame('149.70', $fila['a_cobrar']);

        // El saldo FISCAL para emitir más NC sigue contando la de pronto pago.
        $this->assertSame('48.02', app(SaldoMontoCcf::class)->saldo($c->dte));
        $this->assertNotNull($pronto->id);
    }

    public function test_un_ccf_cubierto_por_una_nc_de_pronto_pago_no_se_da_por_anulado(): void
    {
        $c = $this->ccf('100.00');
        $this->nc($c, '100.00', TipoNotaCredito::ProntoPago);

        $this->assertNotContains($c->id, CobroDocumento::idsSaldadosConNc($this->cliente->id));
        $this->assertSame('100.00', $c->facturadoEfectivo());
    }

    public function test_la_nc_normal_se_sigue_restando_del_cobro(): void
    {
        // #34: lo esperado es el CCF menos sus NC aceptadas (devolución, faltante, etc.).
        $c = $this->ccf('100.00');
        $this->nc($c, '10.00', TipoNotaCredito::DevolucionProducto);

        $this->assertSame('90.00', $c->facturadoEfectivo());
        $this->subir($this->linea('CF', str_replace('-', '', $c->numero_control), '90.00'));
        $this->assertSame(EstadoPagoCobro::Pagado, $c->refresh()->pago_estado);
    }
}
