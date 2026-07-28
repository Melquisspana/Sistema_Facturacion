<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use Tests\TestCase;

/**
 * Enums de catálogo de Planta: vocabulario cerrado de insumos, unidades,
 * ubicaciones, mercados y tipos de ajuste.
 */
class CatalogosPlantaEnumsTest extends TestCase
{
    public function test_tipos_de_insumo_del_plan(): void
    {
        $this->assertSame(
            ['materia_prima', 'bolsa', 'vinieta', 'empaque', 'otro'],
            array_map(fn (TipoInsumo $t) => $t->value, TipoInsumo::cases())
        );
    }

    public function test_la_unidad_base_es_libra_o_unidad(): void
    {
        // Deliberadamente corta: la recepción convierte UNA vez desde la unidad
        // de compra (saco, caja, kg) y el inventario nunca se reconvierte.
        $this->assertSame(
            ['libra', 'unidad'],
            array_map(fn (UnidadBase $u) => $u->value, UnidadBase::cases())
        );

        $this->assertSame('lb', UnidadBase::Libra->abreviatura());
        $this->assertSame('u', UnidadBase::Unidad->abreviatura());
    }

    public function test_solo_la_ubicacion_de_transito_es_transito(): void
    {
        $this->assertTrue(TipoUbicacion::Transito->esTransito());
        $this->assertFalse(TipoUbicacion::Fisica->esTransito());
    }

    public function test_el_transito_no_admite_operacion_manual(): void
    {
        // Su saldo solo lo mueven los traslados: ninguna recepción, ajuste ni
        // conteo puede apuntarle.
        $this->assertFalse(TipoUbicacion::Transito->permiteOperacionManual());
        $this->assertTrue(TipoUbicacion::Fisica->permiteOperacionManual());
    }

    public function test_mercados_del_plan(): void
    {
        $this->assertSame(
            ['nacional', 'exportacion', 'otro'],
            array_map(fn (MercadoPlanta $m) => $m->value, MercadoPlanta::cases())
        );
    }

    public function test_tipos_de_ajuste_del_plan(): void
    {
        $this->assertSame([
            'carga_inicial', 'positivo', 'negativo', 'merma',
            'dano', 'vencimiento', 'correccion_conteo',
        ], array_map(fn (TipoAjuste $t) => $t->value, TipoAjuste::cases()));
    }

    public function test_solo_la_carga_inicial_emite_movimiento_de_carga_inicial(): void
    {
        // Comparte el flujo del ajuste positivo, pero se distingue en el mayor
        // para poder aislar el arranque del sistema de la operación real.
        $this->assertTrue(TipoAjuste::CargaInicial->esCargaInicial());
        $this->assertSame(TipoMovimientoPlanta::CargaInicial, TipoAjuste::CargaInicial->tipoMovimiento());

        foreach (TipoAjuste::cases() as $tipo) {
            if ($tipo === TipoAjuste::CargaInicial) {
                continue;
            }

            $this->assertFalse($tipo->esCargaInicial(), "{$tipo->value} no es carga inicial.");
            $this->assertSame(
                TipoMovimientoPlanta::Ajuste,
                $tipo->tipoMovimiento(),
                "{$tipo->value} debería emitir un movimiento de tipo `ajuste`."
            );
        }
    }

    public function test_ningun_tipo_de_ajuste_emite_movimiento_de_reversion(): void
    {
        // Reversar un ajuste es otra operación, con su propio tipo: confirmar
        // un ajuste jamás debe producir una fila marcada como compensación.
        foreach (TipoAjuste::cases() as $tipo) {
            $this->assertFalse(
                $tipo->tipoMovimiento()->esReversion(),
                "Confirmar un ajuste {$tipo->value} no puede emitir un movimiento de reversión."
            );
        }
    }
}
