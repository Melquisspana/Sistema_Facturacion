<?php

namespace Tests\Feature\Alertas;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Services\Alertas\Detectores\CobrosFueraDeRegla;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\NcPosterioresLotePpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Hallazgo del probador: una NC que el TXT devolvió va en el próximo PPQ (decisión del
 * 06/10/2026, {@see NcPosterioresLotePpq}) y su renglón queda también en
 * el lote anterior. El vigilante la contaba como «CCF en dos PPQ». La regla es de CCF.
 * Datos inventados.
 */
class NcDevueltaEnOtroPpqTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    public function test_una_nc_devuelta_por_el_txt_que_va_en_el_siguiente_ppq_no_cuenta_como_ccf_en_dos_ppq(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Secreto PPQ']);
        ClientePerfilDocumento::create(['cliente_id' => $cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
        $estab = $this->crearEmisorDte()['estab'];
        $dte = fn (string $tipo, string $numero, ?int $relacionado = null) => Dte::create([
            'establecimiento_id' => $estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $cliente->id, 'numero_control' => 'DTE-'.$tipo.'-M001P002-'.$numero, 'dte_relacionado_id' => $relacionado,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()), 'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(), 'fecha_emision' => '2026-11-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
        $ccf = $dte('03', '000000000000201');
        $doc = CobroDocumento::create([
            'cliente_id' => $cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $ccf->id,
            'tipo_dte' => '03', 'numero_control' => $ccf->numero_control, 'fecha_emision' => '2026-11-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/5001', 'tipo_codigo' => 'AC01', 'fecha_albaran' => '2026-11-05',
            'monto_albaran' => '98.95', 'sala_codigo' => '0017', 'numero_orden_compra' => '26110017000001']);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();
        $primero = app(CrearPpqDesdeSeguimiento::class)->crear($cliente, [$doc->id]);
        $nc = $dte('05', '000000000000031', $ccf->id);
        $renglonNc = $primero->items()->first()->replicate()->fill(['dte_id' => $nc->id, 'numero_control' => $nc->numero_control, 'tipo_dte' => '05']);
        $renglonNc->save();

        // El TXT dice que Calleja no aplicó la NC: vuelve para el próximo PPQ.
        CobroEvento::create(['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'origen' => 'manual',
            'referencia_linea' => 'txt-abcdef123456-nc-'.$nc->id, 'fecha' => today(),
            'datos' => ['nc_dte_id' => $nc->id, 'ppq_lote_id' => $primero->id]]);
        $siguiente = PpqLote::create(['referencia' => 'PPQ inventado', 'fecha' => now()->toDateString(),
            'estado' => $primero->estado, 'cliente_id' => $cliente->id]);
        $renglonNc->replicate()->fill(['ppq_lote_id' => $siguiente->id])->save();
        $this->travel(11)->minutes();
        Cache::flush();

        $this->assertNull(app(CobrosFueraDeRegla::class)->revisar(), 'Una NC no es un «CCF en dos PPQ».');
    }
}
