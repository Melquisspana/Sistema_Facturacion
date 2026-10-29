<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Services\Ppq\AlbaranParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\PpqGmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlbaranAc04CorreoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private string $texto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cadena Jardines de Ejemplo']);
        $this->cliente->sucursales()->create(['codigo' => '0148', 'nombre' => 'Sala Jardines de Ejemplo']);
        config(['ppq.cliente_default_id' => $this->cliente->id, 'ppq.albaranes.sincronizacion_automatica' => true, 'dte.ambiente' => '01']);
        $this->texto = file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt'));
        $this->app->instance(AlbaranParser::class, new class extends AlbaranParser
        {
            public function desdePdf(string $pdfBytes): array
            {
                return $this->desdeTexto($pdfBytes);
            }
        });
    }

    private function ccf(?Cliente $cliente = null, string $control = 'DTE-03-M001P002-000000000004826'): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Dulcería de Ejemplo', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);

        return Dte::create(['establecimiento_id' => $estab->id, 'cliente_id' => ($cliente ?? $this->cliente)->id,
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01', 'sello_recepcion' => 'SELLO-FICTICIO',
            'numero_control' => $control, 'numero_orden_compra' => '26110148008765',
            'fecha_emision' => '2026-11-17', 'hora_emision' => '09:00:00', 'total_pagar' => '245.60']);
    }

    private function sincronizar(?string $motivo = null): void
    {
        $this->app->instance(GmailClient::class, new class($this->texto) extends GmailClient
        {
            public function __construct(private string $texto) {}

            public function disponible(): bool
            {
                return true;
            }

            protected function listar(string $q, int $limite): array
            {
                return [['id' => 'msg-ac04-ficticia', 'asunto' => 'Nota de devolución', 'fecha' => '2026-11-18']];
            }

            public function adjuntos(string $messageId): array
            {
                return [['filename' => '26-11-0148-00-008427-AC04-0001.PDF', 'mime' => 'application/pdf', 'data' => $this->texto]];
            }
        });
        $this->app->forgetInstance(PpqGmailService::class);
        $comando = $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-11-18', '--hasta' => '2026-11-18', '--aplicar' => true]);
        if ($motivo !== null) {
            $comando->expectsOutputToContain($motivo);
        }
        $comando->assertSuccessful();
    }

    public function test_ac04_parseada_se_guarda_con_total_y_se_vincula_por_control_sin_oc(): void
    {
        $ccf = $this->ccf();
        $this->sincronizar();
        $nota = PpqAlbaran::sole();
        $this->assertSame('AC04/0148/00/8427', $nota->numero_albaran);
        $this->assertSame('2026-11-18', $nota->fecha_albaran->toDateString());
        $this->assertSame('31.87', $nota->monto_albaran);
        $this->assertSame('0148', $nota->sala_codigo);
        $this->assertNull($nota->numero_orden_compra);
        $this->assertSame($ccf->id, $nota->dte_id);
        $this->sincronizar();
        $this->assertSame(1, PpqAlbaran::count());
    }

    public function test_ac04_sin_control_se_vincula_por_oc_explicita(): void
    {
        $ccf = $this->ccf();
        $this->texto = str_replace('DTE03M001P002000000000004826', 'Sin referencia', $this->texto)."\nOrden de compra: 26110148008765";
        $this->sincronizar();
        $this->assertSame($ccf->id, PpqAlbaran::sole()->dte_id);
    }

    public function test_referencia_a_ccf_de_otro_cliente_se_rechaza_con_motivo(): void
    {
        $otro = Cliente::factory()->create(['nombre' => 'Distribuidora El Bosque']);
        $this->ccf($otro);
        $this->sincronizar('otro cliente');
        $this->assertNull(PpqAlbaran::sole()->dte_id);
    }

    public function test_sin_ccf_permanece_sin_vincular_y_reintenta_sin_duplicar(): void
    {
        $this->sincronizar('sin vincular');
        $this->assertNull(PpqAlbaran::sole()->dte_id);
        $ccf = $this->ccf();
        $this->sincronizar();
        $this->assertSame($ccf->id, PpqAlbaran::sole()->dte_id);
    }

    public function test_referencia_inexistente_no_cae_a_una_oc_que_coincide(): void
    {
        $this->ccf(control: 'DTE-03-M001P002-000000000004827');
        $this->texto .= "\nOrden de compra: 26110148008765";
        $this->sincronizar('sin vincular');
        $this->assertNull(PpqAlbaran::sole()->dte_id);
    }

    public function test_ac04_con_dos_referencias_no_elige_por_oc(): void
    {
        $this->ccf();
        $this->texto .= "\nDTE03M001P002000000000004827\nOrden de compra: 26110148008765";
        $this->sincronizar('no se pudo leer inequívocamente');
        $this->assertNull(PpqAlbaran::sole()->dte_id);
    }

    public function test_ac04_vinculada_por_control_sin_oc_se_descuenta_en_cobros(): void
    {
        $ccf = $this->ccf();
        $this->sincronizar();
        $doc = CobroDocumento::create(['dte_id' => $ccf->id, 'cliente_id' => $this->cliente->id,
            'tipo_dte' => '03', 'numero_control' => $ccf->numero_control, 'monto' => '245.60', 'fecha_emision' => '2026-11-17']);
        PpqAlbaran::create(['numero_albaran' => 'AC01/0148/00/9427', 'numero_orden_compra' => '26110148008765',
            'fecha_albaran' => '2026-11-17', 'monto_albaran' => '277.47']);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado,
            app(VinculadorAlbaranes::class)->aplicar($doc));
    }

    public function test_cobros_recupera_la_referencia_del_pdf_si_el_ccf_se_incorpora_despues(): void
    {
        $this->sincronizar();
        $ccf = $this->ccf();
        config(['cobros.alta.automatica' => true, 'cobros.vinculacion.automatica' => true]);
        $this->artisan('cobros:sincronizar', ['--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true])->assertSuccessful();
        $this->assertSame($ccf->id, PpqAlbaran::sole()->dte_id);
    }

    public function test_un_reenvio_no_cambia_la_referencia_de_la_evidencia_guardada(): void
    {
        $this->sincronizar();
        $this->ccf(control: 'DTE-03-M001P002-000000000004827');
        $this->texto = str_replace('DTE03M001P002000000000004826', 'DTE03M001P002000000000004827', $this->texto);
        $this->sincronizar('contradice la evidencia guardada');
        $this->assertNull(PpqAlbaran::sole()->dte_id);
    }
}
