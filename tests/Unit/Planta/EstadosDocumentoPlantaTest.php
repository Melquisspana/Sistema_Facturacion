<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\EstadoTrasladoPlanta;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Máquinas de estado de los cuatro documentos de Planta.
 *
 * Reglas comunes que se verifican para todos: solo el borrador es editable, los
 * estados finales no tienen salida, y ningún documento puede transicionar a sí
 * mismo (lo que haría posible confirmar dos veces).
 */
class EstadosDocumentoPlantaTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function enumsDeDocumento(): array
    {
        return [
            'recepción' => [EstadoRecepcionPlanta::class],
            'traslado' => [EstadoTrasladoPlanta::class],
            'ajuste' => [EstadoAjustePlanta::class],
            'cambio de disponibilidad' => [EstadoCambioDisponibilidad::class],
        ];
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('enumsDeDocumento')]
    public function test_solo_el_borrador_es_editable(string $enum): void
    {
        foreach ($enum::cases() as $estado) {
            $this->assertSame(
                $estado->value === 'borrador',
                $estado->esEditable(),
                "{$enum}::{$estado->name} no debería ser editable salvo en borrador."
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('enumsDeDocumento')]
    public function test_ningun_estado_transiciona_a_si_mismo(string $enum): void
    {
        // Es lo que hace imposible «confirmar una segunda vez» a nivel de
        // vocabulario, antes incluso de llegar al lock del documento.
        foreach ($enum::cases() as $estado) {
            $this->assertFalse(
                $estado->puedeTransicionarA($estado),
                "{$enum}::{$estado->name} no debería transicionar a sí mismo."
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('enumsDeDocumento')]
    public function test_toda_transicion_declarada_es_coherente(string $enum): void
    {
        foreach ($enum::cases() as $estado) {
            foreach ($estado->siguientesEstados() as $destino) {
                $this->assertInstanceOf($enum, $destino);
                $this->assertTrue(
                    $estado->puedeTransicionarA($destino),
                    "{$enum}: {$estado->value} declara {$destino->value} pero puedeTransicionarA() lo niega."
                );
            }
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('enumsDeDocumento')]
    public function test_desde_el_borrador_siempre_se_puede_avanzar(string $enum): void
    {
        $borrador = $enum::from('borrador');

        $this->assertNotEmpty($borrador->siguientesEstados());
    }

    public function test_recepcion_confirma_anula_y_reversa(): void
    {
        $this->assertEqualsCanonicalizing(
            [EstadoRecepcionPlanta::Confirmada, EstadoRecepcionPlanta::Anulada],
            EstadoRecepcionPlanta::Borrador->siguientesEstados()
        );

        // Reversar es la única salida de una recepción confirmada: nunca se
        // vuelve a borrador ni se edita.
        $this->assertSame([EstadoRecepcionPlanta::Reversada], EstadoRecepcionPlanta::Confirmada->siguientesEstados());

        // Estados finales.
        $this->assertSame([], EstadoRecepcionPlanta::Anulada->siguientesEstados());
        $this->assertSame([], EstadoRecepcionPlanta::Reversada->siguientesEstados());
    }

    public function test_anular_solo_es_posible_desde_borrador(): void
    {
        // Anular descarta un borrador que nunca tocó inventario; una recepción
        // confirmada se deshace reversando, que sí emite movimientos espejo.
        $this->assertFalse(EstadoRecepcionPlanta::Confirmada->puedeTransicionarA(EstadoRecepcionPlanta::Anulada));
        $this->assertFalse(EstadoAjustePlanta::Confirmado->puedeTransicionarA(EstadoAjustePlanta::Anulado));
        $this->assertFalse(EstadoCambioDisponibilidad::Confirmado->puedeTransicionarA(EstadoCambioDisponibilidad::Anulado));
    }

    public function test_traslado_recorre_borrador_enviado_recibido(): void
    {
        $this->assertTrue(EstadoTrasladoPlanta::Borrador->puedeTransicionarA(EstadoTrasladoPlanta::Enviado));
        $this->assertTrue(EstadoTrasladoPlanta::Enviado->puedeTransicionarA(EstadoTrasladoPlanta::Recibido));

        // No se puede recibir lo que no se ha enviado: es la transición que
        // evita que aparezca saldo en destino sin haber salido del origen.
        $this->assertFalse(EstadoTrasladoPlanta::Borrador->puedeTransicionarA(EstadoTrasladoPlanta::Recibido));
    }

    public function test_traslado_es_reversable_enviado_y_recibido(): void
    {
        $this->assertTrue(EstadoTrasladoPlanta::Enviado->puedeTransicionarA(EstadoTrasladoPlanta::Reversado));
        $this->assertTrue(EstadoTrasladoPlanta::Recibido->puedeTransicionarA(EstadoTrasladoPlanta::Reversado));

        // Un borrador se cancela, no se reversa: no hay nada que deshacer.
        $this->assertFalse(EstadoTrasladoPlanta::Borrador->puedeTransicionarA(EstadoTrasladoPlanta::Reversado));
        $this->assertTrue(EstadoTrasladoPlanta::Borrador->puedeTransicionarA(EstadoTrasladoPlanta::Cancelado));
    }

    public function test_solo_el_traslado_enviado_esta_en_transito(): void
    {
        foreach (EstadoTrasladoPlanta::cases() as $estado) {
            $this->assertSame(
                $estado === EstadoTrasladoPlanta::Enviado,
                $estado->estaEnTransito(),
                "{$estado->value} no refleja correctamente si su saldo está en tránsito."
            );
        }
    }

    public function test_un_documento_reversado_no_se_puede_volver_a_reversar(): void
    {
        // Idempotencia a nivel de vocabulario: `reversado` no tiene salida.
        $this->assertSame([], EstadoRecepcionPlanta::Reversada->siguientesEstados());
        $this->assertSame([], EstadoTrasladoPlanta::Reversado->siguientesEstados());
        $this->assertSame([], EstadoAjustePlanta::Reversado->siguientesEstados());
        $this->assertSame([], EstadoCambioDisponibilidad::Reversado->siguientesEstados());
    }

    public function test_ajuste_y_cambio_de_disponibilidad_comparten_ciclo(): void
    {
        // Mismo ciclo porque en ambos «confirmar» es el acto que emite los
        // movimientos, aunque el ajuste altere la cantidad física y el cambio
        // de disponibilidad no.
        $this->assertSame(
            array_map(fn ($e) => $e->value, EstadoAjustePlanta::cases()),
            array_map(fn ($e) => $e->value, EstadoCambioDisponibilidad::cases())
        );
    }
}
