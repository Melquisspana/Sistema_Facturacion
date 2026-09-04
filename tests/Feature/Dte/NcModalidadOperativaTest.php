<?php

namespace Tests\Feature\Dte;

use App\Enums\ModalidadNotaCredito;
use App\Enums\TipoNotaCredito;
use App\Models\ClientePerfilTipoNc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La capa de MODALIDAD OPERATIVA: cuatro opciones de pantalla sobre las siete
 * modalidades internas que ya estaban en `dtes.tipo_nota_credito`.
 *
 * Lo que se protege acá es que la capa sea solo traducción: que las cuatro cubran los
 * siete tipos sin dejar ninguno afuera (o un documento viejo se quedaría sin pantalla
 * que lo sepa mostrar) y que ningún tipo caiga en dos modalidades a la vez.
 */
class NcModalidadOperativaTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_cuatro_modalidades_operativas_son_las_pedidas(): void
    {
        $this->assertSame(
            ['devolucion_faltante', 'averia', 'pronto_pago', 'otro_ajuste'],
            array_map(fn (ModalidadNotaCredito $m) => $m->value, ModalidadNotaCredito::cases()),
        );
    }

    /**
     * Devolución y faltante son EL MISMO tratamiento fiscal, así que comparten
     * modalidad; el submotivo conserva cuál de los dos hechos fue.
     */
    public function test_devolucion_y_faltante_comparten_modalidad(): void
    {
        $modalidad = ModalidadNotaCredito::DevolucionFaltante;

        $this->assertSame($modalidad, ModalidadNotaCredito::desdeTipo(TipoNotaCredito::DevolucionProducto));
        $this->assertSame($modalidad, ModalidadNotaCredito::desdeTipo(TipoNotaCredito::FaltanteEntrega));
        $this->assertSame(
            ['devolucion_producto', 'faltante_entrega'],
            array_keys($modalidad->submotivos()),
        );
    }

    /** Ningún tipo interno puede quedar sin modalidad: incluye los ya retirados del alta. */
    public function test_toda_modalidad_interna_tiene_modalidad_operativa(): void
    {
        foreach (TipoNotaCredito::cases() as $tipo) {
            $this->assertNotNull(
                ModalidadNotaCredito::desdeTipo($tipo),
                "El tipo interno {$tipo->value} quedó sin modalidad operativa que lo muestre.",
            );
        }
    }

    /** Y ninguno puede caer en dos: la traducción tiene que ser única. */
    public function test_ningun_tipo_interno_pertenece_a_dos_modalidades(): void
    {
        foreach (TipoNotaCredito::cases() as $tipo) {
            $coincidencias = array_filter(
                ModalidadNotaCredito::cases(),
                fn (ModalidadNotaCredito $m) => $m->admiteTipo($tipo),
            );

            $this->assertCount(1, $coincidencias, "El tipo {$tipo->value} pertenece a más de una modalidad.");
        }
    }

    /**
     * Las dos modalidades retiradas del formulario (descuento posterior y ajuste
     * comercial) siguen leyéndose como «Otro ajuste»: los documentos que ya las tienen
     * no se quedan sin pantalla.
     */
    public function test_las_modalidades_retiradas_se_leen_como_otro_ajuste(): void
    {
        foreach ([TipoNotaCredito::DescuentoPosterior, TipoNotaCredito::AjusteComercial, TipoNotaCredito::Otro] as $tipo) {
            $this->assertSame(ModalidadNotaCredito::OtroAjuste, ModalidadNotaCredito::desdeTipo($tipo));
        }

        // Pero el alta usa siempre `otro`: las otras dos no se crean más.
        $this->assertSame(TipoNotaCredito::Otro, ModalidadNotaCredito::OtroAjuste->tipoPorDefecto());
    }

    /**
     * La enum NO puede traer códigos de albarán adentro. Un código de ejemplo metido acá
     * se convertiría en la regla de todos los clientes, y la inmensa mayoría no tiene
     * ningún código: sus notas se emiten con las reglas fiscales generales. El código,
     * cuando existe, lo declara el perfil documental del cliente
     * ({@see ClientePerfilTipoNc}).
     */
    public function test_la_modalidad_no_conoce_codigos_de_albaran(): void
    {
        $this->assertFalse(
            method_exists(ModalidadNotaCredito::class, 'codigoAlbaranReferencia'),
            'La modalidad volvió a traer códigos de albarán cableados: deben salir del perfil del cliente.'
        );

        foreach (ModalidadNotaCredito::cases() as $m) {
            foreach ([$m->label(), $m->descripcion()] as $texto) {
                $this->assertDoesNotMatchRegularExpression(
                    '/AC\d{2}/',
                    $texto,
                    "La modalidad {$m->value} nombra un código de albarán en un texto de pantalla."
                );
            }
        }
    }

    /**
     * Las dos reglas que la modalidad decide: quién puede guardarse sin CCF todavía, y
     * quién admite emitirse a una sala distinta a la del CCF relacionado.
     */
    public function test_reglas_por_modalidad(): void
    {
        // Solo la avería puede quedar como borrador incompleto: las demás no tienen
        // sentido sin original (acreditan SUS líneas o descuentan sobre SU monto).
        $this->assertTrue(ModalidadNotaCredito::Averia->admiteGuardarSinCcf());
        foreach ([ModalidadNotaCredito::DevolucionFaltante, ModalidadNotaCredito::ProntoPago, ModalidadNotaCredito::OtroAjuste] as $m) {
            $this->assertFalse($m->admiteGuardarSinCcf());
        }

        $this->assertTrue(ModalidadNotaCredito::ProntoPago->permiteOtraSalaReceptora());
        $this->assertTrue(ModalidadNotaCredito::OtroAjuste->permiteOtraSalaReceptora());
        $this->assertFalse(ModalidadNotaCredito::DevolucionFaltante->permiteOtraSalaReceptora());
        $this->assertFalse(ModalidadNotaCredito::Averia->permiteOtraSalaReceptora());
    }

    /**
     * Hay UNA sola avería. La pantalla no la subdivide por cómo ni dónde apareció: eso se
     * intentó y no aportaba nada operativo, solo clasificaba.
     */
    public function test_la_averia_es_una_sola_modalidad(): void
    {
        $this->assertSame([TipoNotaCredito::Averia], ModalidadNotaCredito::Averia->tiposInternos());
        $this->assertSame([], ModalidadNotaCredito::Averia->submotivos());
    }
}
