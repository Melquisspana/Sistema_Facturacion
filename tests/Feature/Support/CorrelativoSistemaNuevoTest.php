<?php

namespace Tests\Feature\Support;

use App\Models\Correlativo;
use App\Models\Establecimiento;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Support\Dte\CorrelativoSistemaNuevo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresión del bug real encontrado en la puesta en producción: las pantallas de
 * readiness consultaban `Correlativo::where(...)->first()` SIN filtrar por punto de
 * venta, así que con P001 (Conta Portable) y P002 (sistema nuevo) como filas activas
 * distintas del mismo tipo/ambiente, devolvían la de P001 (mostrando "1137" en vez del
 * próximo real de P002). {@see CorrelativoSistemaNuevo} resuelve SIEMPRE el punto de
 * venta predeterminado del sistema nuevo, nunca el de Conta.
 */
class CorrelativoSistemaNuevoTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $p001;

    private PuntoVenta $p002;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->p001 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Conta', 'activo' => true]);
        $this->p002 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Sistema nuevo', 'activo' => true]);
    }

    private function correlativo(string $tipo, int $ultimoNumero, PuntoVenta $pv): Correlativo
    {
        return Correlativo::create([
            'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => $ultimoNumero, 'activo' => true,
        ]);
    }

    public function test_resuelve_p002_como_punto_de_venta_predeterminado(): void
    {
        $r = CorrelativoSistemaNuevo::establecimientoYPuntoVenta();

        $this->assertSame($this->estab->id, $r['establecimiento_id']);
        $this->assertSame($this->p002->id, $r['punto_venta_id']);
    }

    #[DataProvider('proximosPorTipo')]
    public function test_proximo_numero_por_tipo(string $tipo, int $ultimoP002, int $esperado): void
    {
        $this->correlativo($tipo, $ultimoP002, $this->p002);

        $this->assertSame($esperado, CorrelativoSistemaNuevo::proximoNumero($tipo, '01'));
    }

    /** Reproduce el estado real reportado: 01=0→1, 03=1→2, 05=0→1, 11=0→1. */
    public static function proximosPorTipo(): array
    {
        return [
            'Factura (01)' => ['01', 0, 1],
            'CCF (03)' => ['03', 1, 2],
            'Nota de Crédito (05)' => ['05', 0, 1],
            'Factura Exportación (11)' => ['11', 0, 1],
        ];
    }

    public function test_no_depende_del_correlativo_de_p001_aunque_sea_mucho_mas_alto(): void
    {
        // El bug real: P001 (Conta) con un ultimo_numero mucho más alto que P002.
        $this->correlativo('03', 1136, $this->p001);
        $this->correlativo('03', 1, $this->p002);

        $this->assertSame(2, CorrelativoSistemaNuevo::proximoNumero('03', '01'));
        $this->assertNotSame(1137, CorrelativoSistemaNuevo::proximoNumero('03', '01'));
    }

    public function test_null_si_no_hay_correlativo_activo_para_p002(): void
    {
        $this->correlativo('03', 1136, $this->p001); // solo P001 tiene fila

        $this->assertNull(CorrelativoSistemaNuevo::proximoNumero('03', '01'));
    }

    public function test_cae_a_fila_sin_punto_de_venta_si_no_hay_una_especifica_de_p002(): void
    {
        // Mismo criterio que el motor real de emisión (DteGeneracionService): una fila
        // con punto_venta_id NULL es un contador compartido válido para el
        // establecimiento, y se usa si no hay una fila específica de P002.
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => null,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 41, 'activo' => true,
        ]);

        $this->assertSame(42, CorrelativoSistemaNuevo::proximoNumero('03', '01'));
    }

    public function test_ignora_correlativo_inactivo(): void
    {
        $corr = $this->correlativo('03', 1, $this->p002);
        $corr->update(['activo' => false]);

        $this->assertNull(CorrelativoSistemaNuevo::proximoNumero('03', '01'));
    }
}
