<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteStateMachine;
use App\Support\HoraNegocio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Normativa 2.0, regla 7.1 (p.16) y Anexo IV, campo 5.3: el número de control «no se
 * repite durante el ejercicio comprendido del 01 de enero al 31 de diciembre» y el
 * consecutivo «deberá reiniciarse en 000000000000001 a partir del primer DTE de cada
 * ejercicio impositivo». Issue #42, D1.
 *
 * El ejercicio de un documento es el año de su fecha de emisión. El reinicio ocurre al
 * generar, con el correlativo bloqueado (lockForUpdate): la prueba con dos conexiones
 * reales está en CorrelativoAnualMysqlTest (solo corre en el job MySQL).
 *
 * ⚠ No se despliega sin #121: Cobros, Rutas y PPQ identifican documentos solo por número
 * de control.
 */
class CorrelativoAnualTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    private Correlativo $correlativo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->correlativo = Correlativo::create([
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

    private function correlativoDe(Dte $dte): string
    {
        return substr((string) $dte->numero_control, -15);
    }

    public function test_el_primer_documento_del_anio_nuevo_vuelve_a_uno(): void
    {
        $this->en('2026-12-31 23:50:00');
        $a = $this->generar($this->borradorCcf());
        $b = $this->generar($this->borradorCcf());
        $this->assertSame('000000000000001', $this->correlativoDe($a));
        $this->assertSame('000000000000002', $this->correlativoDe($b));
        $this->assertSame(2026, (int) $b->ejercicio);

        $this->en('2027-01-01 00:00:05');
        $c = $this->generar($this->borradorCcf());
        $d = $this->generar($this->borradorCcf());

        $this->assertSame('000000000000001', $this->correlativoDe($c));
        $this->assertSame('000000000000002', $this->correlativoDe($d));
        $this->assertSame(2027, (int) $c->ejercicio);
        $this->assertSame($a->numero_control, $c->numero_control, 'El número de control se repite entre ejercicios: es lo que exige la norma.');
        $this->assertNotSame($a->numero_interno, $c->numero_interno, 'El número interno no puede repetirse.');
        $this->assertStringContainsString('2027', (string) $c->numero_interno);
        $this->assertSame(2, $this->correlativo->refresh()->ultimo_numero);
    }

    public function test_dentro_del_mismo_anio_no_se_reinicia(): void
    {
        $this->en('2027-03-10 09:00:00');
        $this->generar($this->borradorCcf());
        $this->en('2027-12-31 23:59:00');
        $ultimo = $this->generar($this->borradorCcf());

        $this->assertSame('000000000000002', $this->correlativoDe($ultimo));
    }

    public function test_serie_sin_dtes_con_numeros_previos_se_reinicia_en_2027_y_se_audita(): void
    {
        $this->correlativo->update(['ultimo_numero' => 22]);
        $this->en('2027-01-01 08:00:00');

        $dte = $this->generar($this->borradorCcf());

        $this->assertSame('000000000000001', $this->correlativoDe($dte));
        $this->assertSame(1, $this->correlativo->refresh()->ultimo_numero);
        $registro = Activity::query()
            ->where('subject_type', $this->correlativo->getMorphClass())
            ->where('subject_id', $this->correlativo->id)
            ->where('description', 'reinició el correlativo por cambio de ejercicio de 2026 a 2027')
            ->sole();
        $this->assertSame(2026, $registro->properties->get('ejercicio_anterior'));
        $this->assertSame(2027, $registro->properties->get('ejercicio'));
        $this->assertSame(22, $registro->properties->get('ultimo_numero_anterior'));
    }

    public function test_serie_sin_dtes_con_numeros_previos_continua_en_2026(): void
    {
        $this->correlativo->update(['ultimo_numero' => 22]);
        $this->en('2026-12-31 08:00:00');

        $dte = $this->generar($this->borradorCcf());

        $this->assertSame('000000000000023', $this->correlativoDe($dte));
        $this->assertSame(23, $this->correlativo->refresh()->ultimo_numero);
        $this->assertFalse(Activity::query()
            ->where('subject_type', $this->correlativo->getMorphClass())
            ->where('subject_id', $this->correlativo->id)
            ->where('description', 'like', '%ejercicio%')
            ->exists());
    }

    public function test_los_dtes_eliminados_y_rechazados_de_2027_conservan_la_numeracion(): void
    {
        $this->en('2027-01-01 08:00:00');
        $eliminado = $this->generar($this->borradorCcf());
        // Simula un histórico eliminado: el observer no permite borrar DTE generados.
        DB::table('dtes')->where('id', $eliminado->id)->update(['deleted_at' => now()]);

        $rechazado = $this->generar($this->borradorCcf());
        $this->assertSame('000000000000002', $this->correlativoDe($rechazado));
        app(DteStateMachine::class)->transicionar($rechazado, EstadoDte::Rechazado);

        $siguiente = $this->generar($this->borradorCcf());

        $this->assertSame('000000000000003', $this->correlativoDe($siguiente));
        $this->assertSame(3, $this->correlativo->refresh()->ultimo_numero);
        $this->assertFalse(Activity::query()
            ->where('subject_type', $this->correlativo->getMorphClass())
            ->where('subject_id', $this->correlativo->id)
            ->where('description', 'like', '%ejercicio%')
            ->exists());
    }

    public function test_el_reinicio_queda_en_la_auditoria(): void
    {
        $this->en('2026-12-31 10:00:00');
        $this->generar($this->borradorCcf());
        $this->en('2027-01-01 08:00:00');
        $this->generar($this->borradorCcf());

        $registro = Activity::query()
            ->where('subject_type', $this->correlativo->getMorphClass())
            ->where('subject_id', $this->correlativo->id)
            ->get()
            ->first(fn (Activity $a) => str_contains((string) $a->description, 'ejercicio'));
        $this->assertNotNull($registro, 'El reinicio anual del correlativo debe quedar auditado.');
    }

    public function test_borrador_con_fecha_de_un_ejercicio_ya_reiniciado_no_se_genera(): void
    {
        $this->en('2026-12-31 22:00:00');
        $this->generar($this->borradorCcf());
        $viejo = $this->borradorCcf(); // fecha de emisión 2026-12-31, sin generar

        $this->en('2027-01-02 08:00:00');
        $this->generar($this->borradorCcf()); // ya reinició el ejercicio 2027

        try {
            $this->generar($viejo);
            $this->fail('Un documento con fecha de 2026 no puede tomar número después del reinicio de 2027.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('ejercicio', $e->getMessage());
        }
        $this->assertNull(Dte::query()->findOrFail($viejo->id)->numero_control);
    }

    public function test_el_borrador_con_fecha_del_anio_anterior_se_genera_si_todavia_no_hubo_reinicio(): void
    {
        $this->en('2026-12-31 22:00:00');
        $this->generar($this->borradorCcf());
        $viejo = $this->borradorCcf();

        // Se genera el 01/01 antes que cualquier documento de 2027: sigue la serie de 2026.
        $this->en('2027-01-01 07:00:00');
        $generado = $this->generar($viejo);

        $this->assertSame('000000000000002', $this->correlativoDe($generado));
        $this->assertSame(2026, (int) $generado->ejercicio);
    }

    public function test_la_base_admite_el_mismo_numero_de_control_en_otro_ejercicio_pero_no_en_el_mismo(): void
    {
        $this->en('2026-06-01 10:00:00');
        $dte = $this->generar($this->borradorCcf());

        $copia = fn (int $ejercicio) => DB::table('dtes')->insert(array_merge(
            collect(DB::table('dtes')->where('id', $dte->id)->first())->except(['id', 'codigo_generacion', 'numero_interno', 'numero_sistema'])->all(),
            ['ejercicio' => $ejercicio, 'codigo_generacion' => strtoupper((string) Str::uuid())],
        ));

        $copia(2027); // otro ejercicio: permitido
        $this->expectException(UniqueConstraintViolationException::class);
        $copia(2026); // mismo ejercicio y ambiente: no
    }
}
