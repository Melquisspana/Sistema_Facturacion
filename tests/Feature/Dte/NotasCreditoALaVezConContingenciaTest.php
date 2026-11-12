<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Dte;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConsultasConCandado;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * DOS NOTAS DE CRÉDITO A LA VEZ SOBRE EL MISMO CCF, CON LA CONTINGENCIA ENCENDIDA.
 *
 * Las dos se ponen en fila por el CCF. Pero en MySQL (REPEATABLE READ) la primera lectura
 * común de la transacción fija la foto que verán las demás, y generar leía la contingencia
 * ANTES de esperar el candado del CCF: al entrar, la segunda nota sumaba el saldo con esa
 * foto vieja, no veía la primera y las dos pasaban. Dos notas de 60 contra un CCF de 100.
 *
 * El arreglo: nada se lee «en común» dentro de la transacción antes de tener el CCF. La
 * contingencia se busca antes de abrirla y adentro solo se toma su candado.
 *
 * Por pasos, porque SQLite no tiene esa foto: (1) antes del candado del CCF toda lectura
 * de la transacción lleva candado, y el orden de candados es nota → contingencia → CCF;
 * (2) si la otra nota entra mientras esta esperaba, esta se rechaza por saldo.
 */
class NotasCreditoALaVezConContingenciaTest extends TestCase
{
    use ConsultasConCandado;
    use DocumentosParaEmitir;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local']);
        $this->prepararEmisor();
    }

    /** CCF aceptado de 10 × 10.00 gravados: 100.00 más IVA. */
    private function ccfAceptado(): Dte
    {
        return $this->aceptarCcf($this->generado());
    }

    private function notaPor(Dte $ccf, float $monto): Dte
    {
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);
        $this->borradores->agregarConceptoNotaCredito($nc, [
            'descripcion' => 'Pronto pago', 'monto' => $monto, 'tipo_impuesto' => 'gravado',
        ]);

        return $nc->refresh();
    }

    /** @return array<int, array{sql: string, valores: array<int, mixed>}> */
    private function generarRegistrando(Dte $nc): array
    {
        $consultas = $this->consultasEnTransaccion();
        app(DteGeneracionService::class)->generar($nc);

        return $consultas->getArrayCopy();
    }

    /** @param  array<int, array{sql: string, valores: array<int, mixed>}>  $consultas */
    private function posicionDelCandado(array $consultas, string $tabla, ?int $id = null): ?int
    {
        foreach ($consultas as $i => $c) {
            if (str_starts_with($c['sql'], 'select') && preg_match('/from [`"]'.$tabla.'[`"]/', $c['sql']) === 1
                && str_contains($c['sql'], 'for update') && ($id === null || in_array($id, $c['valores'], false))) {
                return $i;
            }
        }

        return null;
    }

    public function test_antes_de_tener_el_ccf_la_nota_no_hace_ninguna_lectura_sin_candado(): void
    {
        app(ContingenciaService::class)->activar(3, 'Internet de prueba interrumpido', 'manual', $this->usuario());
        $ccf = $this->ccfAceptado();
        $nc = $this->notaPor($ccf, 60);

        $consultas = $this->generarRegistrando($nc);

        $delCcf = $this->posicionDelCandado($consultas, 'dtes', $ccf->id);
        $this->assertNotNull($delCcf, 'Generar la nota no tomó el candado de su CCF.');

        foreach (array_slice($consultas, 0, $delCcf) as $c) {
            if (str_starts_with($c['sql'], 'select')) {
                $this->assertTrue($this->llevaCandado($c['sql']),
                    'Lectura sin candado antes de bloquear el CCF: en MySQL fija la foto y el saldo se suma viejo. '.$c['sql']);
            }
        }

        // El orden, que es el mismo de la conversión automática a contingencia
        // (contingencia → documento): si se invirtiera, las dos podrían esperarse entre sí.
        $deLaNota = $this->posicionDelCandado($consultas, 'dtes', $nc->id);
        $deLaContingencia = $this->posicionDelCandado($consultas, 'contingencias');
        $this->assertNotNull($deLaNota);
        $this->assertNotNull($deLaContingencia, 'Con la contingencia activa, generar toma su candado.');
        $this->assertLessThan($deLaContingencia, $deLaNota);
        $this->assertLessThan($delCcf, $deLaContingencia);

        $this->assertSame(EstadoDte::Generado, $nc->fresh()->estado);
        $this->assertNotNull($nc->fresh()->contingencia_id);
    }

    public function test_con_la_contingencia_habilitada_pero_sin_ninguna_activa_tampoco_se_lee_antes_del_ccf(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->notaPor($ccf, 60);

        $consultas = $this->generarRegistrando($nc);

        $delCcf = $this->posicionDelCandado($consultas, 'dtes', $ccf->id);
        $this->assertNotNull($delCcf);
        foreach (array_slice($consultas, 0, $delCcf) as $c) {
            if (str_starts_with($c['sql'], 'select')) {
                $this->assertTrue($this->llevaCandado($c['sql']), 'Lectura sin candado antes de bloquear el CCF: '.$c['sql']);
            }
        }
        $this->assertNull($nc->fresh()->contingencia_id);
    }

    public function test_si_la_otra_nota_entra_mientras_esta_esperaba_el_ccf_esta_no_pasa_del_saldo(): void
    {
        app(ContingenciaService::class)->activar(3, 'Internet de prueba interrumpido', 'manual', $this->usuario());
        $ccf = $this->ccfAceptado();
        $primera = $this->notaPor($ccf, 60);
        $segunda = $this->notaPor($ccf, 60);

        // La primera termina de generarse justo cuando la segunda toma el candado del CCF.
        $this->consultasConCandado();
        $hecho = false;
        DB::listen(function ($q) use (&$hecho, $ccf, $primera) {
            $sql = mb_strtolower($q->sql);
            if ($hecho || preg_match('/from [`"]dtes[`"]/', $sql) !== 1 || ! str_contains($sql, 'for update')
                || ! in_array($ccf->id, $q->bindings, false)) {
                return;
            }
            $hecho = true;
            DB::table('dtes')->where('id', $primera->id)->update(['estado' => EstadoDte::Generado->value]);
        });

        try {
            app(DteGeneracionService::class)->generar($segunda);
            $this->fail('Dos notas de 60 no caben en un CCF de 100.');
        } catch (GeneracionException $e) {
            $this->assertTrue($hecho);
        }

        $this->assertSame(EstadoDte::Borrador, $segunda->fresh()->estado);
    }
}
