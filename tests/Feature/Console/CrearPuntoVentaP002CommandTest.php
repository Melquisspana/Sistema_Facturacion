<?php

namespace Tests\Feature\Console;

use App\Models\Correlativo;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comando dte:crear-punto-venta-p002: --ambiente es OBLIGATORIO (00 o 01), activa un
 * solo ambiente por corrida (4 correlativos, no 8), idempotente, dry-run por defecto,
 * nunca toca P001 ni el otro ambiente ni la tabla dtes. NO emite/firma/transmite/envía correo.
 */
class CrearPuntoVentaP002CommandTest extends TestCase
{
    use RefreshDatabase;

    private function emisor(): Establecimiento
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

        return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
    }

    private function p001ConCorrelativo(Establecimiento $estab): array
    {
        $p001 = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Facturación principal', 'activo' => true]);
        $corr = Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $p001->id, 'ambiente' => '01', 'ultimo_numero' => 1136, 'activo' => true]);

        return compact('p001', 'corr');
    }

    // --- 1. dry-run 00 no modifica ---

    public function test_dry_run_ambiente_00_no_escribe_nada(): void
    {
        $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00'])->assertExitCode(0);

        $this->assertSame(0, PuntoVenta::count());
        $this->assertSame(0, Correlativo::count());
    }

    // --- 2. apply 00 crea solo cuatro filas ambiente 00 ---

    public function test_apply_ambiente_00_crea_solo_cuatro_correlativos_de_ese_ambiente(): void
    {
        $estab = $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(0);

        $pv = PuntoVenta::where('establecimiento_id', $estab->id)->where('codigo', 'P002')->first();
        $this->assertNotNull($pv);
        $this->assertTrue($pv->activo);

        $correlativos = Correlativo::where('punto_venta_id', $pv->id)->get();
        $this->assertCount(4, $correlativos);
        foreach (['01', '03', '05', '11'] as $tipo) {
            $c = $correlativos->first(fn ($c) => $c->tipo_dte->value === $tipo);
            $this->assertNotNull($c, "Falta correlativo tipo {$tipo}");
            $this->assertSame('00', $c->ambiente->value);
            $this->assertSame(0, $c->ultimo_numero);
            $this->assertTrue($c->activo);
            $this->assertNull($c->serie);
        }
        // Ambiente 01 no se tocó en absoluto.
        $this->assertSame(0, Correlativo::where('punto_venta_id', $pv->id)->where('ambiente', '01')->count());
    }

    // --- 3. apply 01 crea solo cuatro filas ambiente 01 ---

    public function test_apply_ambiente_01_reutiliza_p002_y_crea_solo_cuatro_correlativos_de_ese_ambiente(): void
    {
        $estab = $this->emisor();
        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(0);
        $pvIdAntes = PuntoVenta::where('establecimiento_id', $estab->id)->where('codigo', 'P002')->value('id');

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '01', '--apply' => true])->assertExitCode(0);

        // Mismo punto de venta (no crea uno nuevo), no duplicado.
        $this->assertSame(1, PuntoVenta::where('codigo', 'P002')->count());
        $pv = PuntoVenta::where('establecimiento_id', $estab->id)->where('codigo', 'P002')->first();
        $this->assertSame($pvIdAntes, $pv->id);

        $correlativos01 = Correlativo::where('punto_venta_id', $pv->id)->where('ambiente', '01')->get();
        $this->assertCount(4, $correlativos01);
        foreach (['01', '03', '05', '11'] as $tipo) {
            $c = $correlativos01->first(fn ($c) => $c->tipo_dte->value === $tipo);
            $this->assertNotNull($c, "Falta correlativo tipo {$tipo} ambiente 01");
            $this->assertSame(0, $c->ultimo_numero);
        }
        // Ambiente 00 (de la corrida anterior) sigue intacto: 4 filas, no se duplicó ni tocó.
        $this->assertSame(4, Correlativo::where('punto_venta_id', $pv->id)->where('ambiente', '00')->count());
        $this->assertSame(8, Correlativo::where('punto_venta_id', $pv->id)->count());
    }

    // --- 4. apply 00 repetido no duplica ---

    public function test_apply_ambiente_00_repetido_no_duplica(): void
    {
        $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(0);
        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(0);

        $this->assertSame(1, PuntoVenta::where('codigo', 'P002')->count());
        $this->assertSame(4, Correlativo::count());
    }

    // --- 5. apply 01 repetido no duplica ---

    public function test_apply_ambiente_01_repetido_no_duplica(): void
    {
        $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '01', '--apply' => true])->assertExitCode(0);
        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '01', '--apply' => true])->assertExitCode(0);

        $this->assertSame(1, PuntoVenta::where('codigo', 'P002')->count());
        $this->assertSame(4, Correlativo::count());
        $this->assertSame(0, Correlativo::where('ambiente', '00')->count());
    }

    // --- 6. ambiente inválido / faltante no modifica nada ---

    public function test_sin_ambiente_falla_y_no_modifica_nada(): void
    {
        $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--apply' => true])->assertExitCode(1);

        $this->assertSame(0, PuntoVenta::count());
        $this->assertSame(0, Correlativo::count());
    }

    public function test_ambiente_invalido_falla_y_no_modifica_nada(): void
    {
        $this->emisor();

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '02', '--apply' => true])->assertExitCode(1);

        $this->assertSame(0, PuntoVenta::count());
        $this->assertSame(0, Correlativo::count());
    }

    // --- 7. P001 permanece intacto en todos los casos ---

    public function test_p001_permanece_intacto_al_aplicar_ambiente_00(): void
    {
        $estab = $this->emisor();
        ['p001' => $p001, 'corr' => $corrP001] = $this->p001ConCorrelativo($estab);

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(0);

        $this->assertSame('P001', $p001->fresh()->codigo);
        $this->assertSame(1136, $corrP001->fresh()->ultimo_numero);
    }

    public function test_p001_permanece_intacto_al_aplicar_ambiente_01(): void
    {
        $estab = $this->emisor();
        ['p001' => $p001, 'corr' => $corrP001] = $this->p001ConCorrelativo($estab);

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '01', '--apply' => true])->assertExitCode(0);

        $this->assertSame('P001', $p001->fresh()->codigo);
        $this->assertSame(1136, $corrP001->fresh()->ultimo_numero);
    }

    public function test_p001_permanece_intacto_con_ambiente_invalido(): void
    {
        $estab = $this->emisor();
        ['p001' => $p001, 'corr' => $corrP001] = $this->p001ConCorrelativo($estab);

        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => 'xx', '--apply' => true])->assertExitCode(1);

        $this->assertSame('P001', $p001->fresh()->codigo);
        $this->assertSame(1136, $corrP001->fresh()->ultimo_numero);
    }

    public function test_aborta_si_el_establecimiento_no_existe_o_esta_inactivo(): void
    {
        $this->artisan('dte:crear-punto-venta-p002', ['--ambiente' => '00', '--apply' => true])->assertExitCode(1);

        $this->assertSame(0, PuntoVenta::count());
    }
}
