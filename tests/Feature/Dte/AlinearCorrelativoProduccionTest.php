<?php

namespace Tests\Feature\Dte;

use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comando dte:alinear-correlativo-produccion. Alinea SOLO el contador de producción
 * (CCF 03, ambiente 01, serie M001P001). Dry-run por defecto; aplicar exige --aplicar
 * + frase exacta. Nunca toca ambiente 00, documentos DTE ni el CCF 1078.
 */
class AlinearCorrelativoProduccionTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        // Códigos M001/P001: de ahí sale la "serie" M001P001 (el correlativo se ubica
        // por establecimiento + punto de venta; la columna serie va en NULL, como en real).
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    /** Contador de producción CCF M001/P001 en 1078 (próximo interno 1079), serie NULL como en real. */
    private function correlativoProduccion(int $ultimo = 1078): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => $ultimo, 'activo' => true,
        ]);
    }

    /** Contador de PRUEBAS (ambiente 00) que jamás debe tocarse. */
    private function correlativoPruebas(int $ultimo = 54): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'serie' => null, 'ultimo_numero' => $ultimo, 'activo' => true,
        ]);
    }

    /** CCF 1078 real aceptado (documento) — debe quedar intacto. */
    private function ccf1078(): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'aceptado',
            'ambiente' => '01', 'numero_control' => 'DTE-03-M001P001-000000000001078',
            'codigo_generacion' => (string) Str::uuid(), 'sello_recepcion' => '202697ABB5D1REAL',
            'fecha_emision' => now(), 'hora_emision' => '10:00:00', 'total_pagar' => 168.88,
        ]);
    }

    // ---------- dry-run / sin aplicar ----------

    public function test_dry_run_no_cambia_la_bd(): void
    {
        $corr = $this->correlativoProduccion();

        $this->artisan('dte:alinear-correlativo-produccion', [
            '--tipo' => '03', '--ambiente' => '01', '--serie' => 'M001P001', '--ultimo' => '1093', '--dry-run' => true,
        ])->expectsOutputToContain('DRY RUN: no se modificó ningún correlativo.')->assertExitCode(0);

        $this->assertSame(1078, (int) $corr->fresh()->ultimo_numero);
    }

    public function test_sin_aplicar_es_dry_run_y_no_cambia_la_bd(): void
    {
        $corr = $this->correlativoProduccion();

        // Sin --aplicar ni --dry-run: por defecto NO escribe.
        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ultimo' => '1093',
        ])->expectsOutputToContain('DRY RUN')->assertExitCode(0);

        $this->assertSame(1078, (int) $corr->fresh()->ultimo_numero);
    }

    // ---------- aplicar ----------

    public function test_aplicar_sin_frase_exacta_aborta_sin_cambiar_bd(): void
    {
        $corr = $this->correlativoProduccion();

        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ultimo' => '1093', '--aplicar' => true, '--frase' => 'ALINEAR CORRELATIVO 9999',
        ])->assertExitCode(1);

        $this->assertSame(1078, (int) $corr->fresh()->ultimo_numero);
    }

    public function test_aplicar_con_frase_correcta_actualiza_solo_produccion(): void
    {
        $prod = $this->correlativoProduccion();
        $pruebas = $this->correlativoPruebas();
        $ccf = $this->ccf1078();
        $dtesAntes = Dte::count();

        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ultimo' => '1093', '--aplicar' => true, '--frase' => 'ALINEAR CORRELATIVO 1094',
        ])->expectsOutputToContain('APLICADO')->assertExitCode(0);

        // Producción alineada: último 1093 → próximo 1094.
        $prod->refresh();
        $this->assertSame(1093, (int) $prod->ultimo_numero);
        $this->assertSame(1094, (int) $prod->siguiente_numero);

        // Ambiente 00 intacto.
        $this->assertSame(54, (int) $pruebas->fresh()->ultimo_numero);

        // Documentos DTE intactos: mismo conteo y el CCF 1078 sin cambios.
        $this->assertSame($dtesAntes, Dte::count());
        $ccf->refresh();
        $this->assertSame('aceptado', $ccf->estado->value);
        $this->assertSame('DTE-03-M001P001-000000000001078', $ccf->numero_control);
        $this->assertSame('202697ABB5D1REAL', $ccf->sello_recepcion);
    }

    public function test_aplicar_con_dry_run_explicito_no_escribe(): void
    {
        $prod = $this->correlativoProduccion();

        // --aplicar + --dry-run: la seguridad gana, no escribe.
        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ultimo' => '1093', '--aplicar' => true, '--dry-run' => true,
            '--frase' => 'ALINEAR CORRELATIVO 1094',
        ])->expectsOutputToContain('DRY RUN')->assertExitCode(0);

        $this->assertSame(1078, (int) $prod->fresh()->ultimo_numero);
    }

    // ---------- validaciones ----------

    public function test_rechaza_externo_menor_o_igual_al_interno(): void
    {
        $prod = $this->correlativoProduccion(1078);

        // 1078 <= 1078 → rechazado.
        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ultimo' => '1078', '--aplicar' => true, '--frase' => 'ALINEAR CORRELATIVO 1079',
        ])->assertExitCode(1);

        $this->assertSame(1078, (int) $prod->fresh()->ultimo_numero);
    }

    public function test_rechaza_ambiente_00(): void
    {
        $this->correlativoPruebas();

        $this->artisan('dte:alinear-correlativo-produccion', [
            '--ambiente' => '00', '--ultimo' => '100', '--aplicar' => true, '--frase' => 'ALINEAR CORRELATIVO 101',
        ])->assertExitCode(1);

        $this->assertSame(54, (int) Correlativo::where('ambiente', '00')->value('ultimo_numero'));
    }

    public function test_rechaza_tipo_o_serie_incorrectos(): void
    {
        $this->correlativoProduccion();

        $this->artisan('dte:alinear-correlativo-produccion', ['--tipo' => '01', '--ultimo' => '1093'])->assertExitCode(1);
        $this->artisan('dte:alinear-correlativo-produccion', ['--serie' => 'M002P002', '--ultimo' => '1093'])->assertExitCode(1);
    }

    public function test_falla_si_no_hay_correlativo_produccion(): void
    {
        // Sin fila de producción creada.
        $this->artisan('dte:alinear-correlativo-produccion', ['--ultimo' => '1093'])->assertExitCode(1);
    }
}
