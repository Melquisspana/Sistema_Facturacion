<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use Tests\TestCase;

/**
 * Control BÁSICO de disponibilidad (Fase 2): qué saldo se puede usar y qué
 * transiciones entre estados son legales.
 *
 * Estas pruebas fijan la decisión funcional aprobada: solo el saldo disponible
 * se traslada o utiliza, el retenido existe pero está fuera de la operación, y
 * el rechazado es terminal.
 */
class EstadoDisponibilidadTest extends TestCase
{
    public function test_solo_el_saldo_disponible_es_utilizable(): void
    {
        $this->assertTrue(EstadoDisponibilidad::Disponible->esUtilizable());

        // El retenido existe FÍSICAMENTE pero no cuenta como disponible: es la
        // regla que impide trasladar o consumir mercancía sin liberar.
        $this->assertFalse(EstadoDisponibilidad::Retenido->esUtilizable());
        $this->assertFalse(EstadoDisponibilidad::Rechazado->esUtilizable());
    }

    public function test_retenido_puede_liberarse_o_rechazarse(): void
    {
        $retenido = EstadoDisponibilidad::Retenido;

        $this->assertTrue($retenido->puedeTransicionarA(EstadoDisponibilidad::Disponible));
        $this->assertTrue($retenido->puedeTransicionarA(EstadoDisponibilidad::Rechazado));

        $this->assertEqualsCanonicalizing(
            [EstadoDisponibilidad::Disponible, EstadoDisponibilidad::Rechazado],
            $retenido->transicionesPermitidas()
        );
    }

    public function test_disponible_solo_puede_retenerse(): void
    {
        $disponible = EstadoDisponibilidad::Disponible;

        $this->assertTrue($disponible->puedeTransicionarA(EstadoDisponibilidad::Retenido));

        // Saltar de disponible a rechazado sin pasar por retenido dejaría sin
        // registro la decisión de apartar la mercancía.
        $this->assertFalse($disponible->puedeTransicionarA(EstadoDisponibilidad::Rechazado));
        $this->assertSame([EstadoDisponibilidad::Retenido], $disponible->transicionesPermitidas());
    }

    public function test_rechazado_es_terminal_en_fase_2(): void
    {
        $rechazado = EstadoDisponibilidad::Rechazado;

        $this->assertTrue($rechazado->esTerminal());
        $this->assertSame([], $rechazado->transicionesPermitidas());

        foreach (EstadoDisponibilidad::cases() as $destino) {
            $this->assertFalse(
                $rechazado->puedeTransicionarA($destino),
                "El rechazado no debería poder pasar a {$destino->value}."
            );
        }
    }

    public function test_ningun_estado_transiciona_a_si_mismo(): void
    {
        // Un par compensado hacia el mismo bucket sumaría cero sobre una sola
        // fila: no es una operación, es ruido en el mayor.
        foreach (EstadoDisponibilidad::cases() as $estado) {
            $this->assertFalse(
                $estado->puedeTransicionarA($estado),
                "{$estado->value} no debería transicionar a sí mismo."
            );
        }
    }

    public function test_solo_rechazado_es_terminal(): void
    {
        $this->assertFalse(EstadoDisponibilidad::Disponible->esTerminal());
        $this->assertFalse(EstadoDisponibilidad::Retenido->esTerminal());
        $this->assertTrue(EstadoDisponibilidad::Rechazado->esTerminal());
    }

    public function test_existen_exactamente_los_tres_estados_del_plan(): void
    {
        $this->assertSame(
            ['disponible', 'retenido', 'rechazado'],
            array_map(fn (EstadoDisponibilidad $e) => $e->value, EstadoDisponibilidad::cases())
        );
    }
}
