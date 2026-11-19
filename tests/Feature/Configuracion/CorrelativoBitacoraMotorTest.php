<?php

namespace Tests\Feature\Configuracion;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Lo que el motor de emisión le hace al correlativo por su cuenta (reiniciarlo al cambiar
 * el año, adelantarlo para no repetir un número) se lee en la auditoría de Configuración
 * con su motivo, y no como si quien facturó lo hubiera movido a mano.
 */
class CorrelativoBitacoraMotorTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
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

    private function emitir(): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    /** @return Collection<int, Activity> */
    private function registros(Correlativo $correlativo)
    {
        return Activity::query()
            ->where('subject_type', $correlativo->getMorphClass())
            ->where('subject_id', $correlativo->id)
            ->orderBy('id')
            ->get();
    }

    public function test_el_reinicio_del_anio_queda_en_configuracion_y_no_como_edicion_a_mano(): void
    {
        $facturador = User::factory()->create();
        $this->actingAs($facturador);
        $this->en('2026-12-30 10:00:00');
        $correlativo = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
        $this->emitir();
        $this->emitir();

        $this->en('2027-01-02 08:00:00');
        $this->assertStringEndsWith('000000000000001', (string) $this->emitir()->numero_control);

        $registros = $this->registros($correlativo);
        $reinicio = $registros->sole(fn (Activity $a) => $a->description === 'reinició el correlativo por cambio de ejercicio de 2026 a 2027');
        $this->assertSame('configuracion', $reinicio->log_name);
        $this->assertSame($facturador->id, (int) $reinicio->causer_id);
        $this->assertSame(2, $reinicio->properties['ultimo_numero_anterior']);
        $this->assertSame(1, $reinicio->properties['ultimo_numero']);

        // Los consumos normales siguen anotados; el paso de 2 a 1 no sale como edición a mano.
        $this->assertSame(2, $registros->where('description', 'consumió el correlativo')->count());
        $this->assertSame(0, $registros->where('description', 'modificó el correlativo')->count());
    }
}
