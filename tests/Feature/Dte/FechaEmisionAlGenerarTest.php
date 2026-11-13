<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Regla del dueño (12/11/2026): la fecha y la hora de emisión del documento son las del
 * momento en que se GENERA (cuando toma número de control y código de generación), en la
 * hora del negocio. No las de cuando se creó el borrador. Una vez generado no cambian.
 */
class FechaEmisionAlGenerarTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['dte.storage.disk' => 'local']);
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function en(string $fechaHora): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHora, HoraNegocio::zona()));
    }

    private function borradorCcf(): Dte
    {
        $borradores = app(DteBorradorService::class);
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => 'cliente@ejemplo.sv']);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        app(DteGeneracionService::class)->generar($dte);

        return Dte::query()->findOrFail($dte->id);
    }

    /** @return array<string, mixed> */
    private function identificacion(Dte $dte): array
    {
        return json_decode((string) Storage::disk('local')->get($dte->json_generado_path), true)['identificacion'];
    }

    public function test_borrador_de_ayer_generado_hoy_lleva_la_fecha_y_hora_de_hoy(): void
    {
        $this->en('2026-11-11 16:00:00');
        $borrador = $this->borradorCcf();
        $this->assertSame('2026-11-11', $borrador->fecha_emision->format('Y-m-d'));

        $this->en('2026-11-12 09:15:30');
        $dte = $this->generar($borrador);

        $this->assertSame('2026-11-12', $dte->fecha_emision->format('Y-m-d'));
        $this->assertSame('09:15:30', (string) $dte->hora_emision);
        $this->assertSame('2026-11-12', $this->identificacion($dte)['fecEmi']);
        $this->assertSame('09:15:30', $this->identificacion($dte)['horEmi']);
    }

    public function test_una_vez_generado_ni_la_firma_ni_un_reintento_cambian_la_fecha(): void
    {
        $this->en('2026-11-11 16:00:00');
        $borrador = $this->borradorCcf();
        $this->en('2026-11-12 09:15:30');
        $dte = $this->generar($borrador);
        $jsonGenerado = Storage::disk('local')->get($dte->json_generado_path);

        // Al día siguiente: un segundo «Generar» no pasa, y la firma usa lo ya fijado.
        $this->en('2026-11-13 08:00:00');
        try {
            $this->generar($dte);
            $this->fail('Un documento ya generado no se vuelve a generar.');
        } catch (GeneracionException) {
        }
        config(['dte.firma.mock' => true]);
        app(DteFirmaService::class)->firmar($dte->refresh());

        $dte->refresh();
        $this->assertSame('2026-11-12', $dte->fecha_emision->format('Y-m-d'));
        $this->assertSame('09:15:30', (string) $dte->hora_emision);
        $this->assertSame($jsonGenerado, Storage::disk('local')->get($dte->json_generado_path));
        $this->assertSame('2026-11-12', $this->identificacion($dte)['fecEmi']);
    }

    public function test_en_contingencia_se_marca_igual_que_antes_y_la_fecha_queda_dentro_de_ella(): void
    {
        config(['dte.contingencia.enabled' => true]);
        Http::fake();

        $this->en('2026-10-09 09:00:00');
        $borrador = $this->borradorCcf();

        $this->en('2026-10-09 10:00:00');
        $contingencia = app(ContingenciaService::class)->activar(3, 'Interrupción de prueba', 'manual', User::factory()->create());

        $this->en('2026-10-09 10:05:00');
        $dte = $this->generar($borrador);
        $identificacion = $this->identificacion($dte);

        $this->assertSame($contingencia->id, $dte->contingencia_id);
        $this->assertSame(2, $identificacion['tipoModelo']);
        $this->assertSame(2, $identificacion['tipoOperacion']);
        $this->assertSame(3, $identificacion['tipoContingencia']);
        // Hacienda rechaza un documento de contingencia emitido antes del inicio informado.
        $this->assertSame('2026-10-09', $identificacion['fecEmi']);
        $this->assertSame('10:05:00', $identificacion['horEmi']);
        Http::assertNothingSent();
    }
}
