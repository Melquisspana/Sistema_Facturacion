<?php

namespace App\Ajustes\Ceremonias;

use App\Ajustes\Ajustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;

/**
 * Confirmación de nivel N2: "esto es lo que vas a cambiar, ¿seguro?".
 *
 * A diferencia de N3 no pide frase exacta ni contraseña. Lo que aporta es lo que
 * de verdad evita el error en un formulario de configuración: enseñar el ANTES y
 * el DESPUÉS de cada campo que cambia, decir qué se rompe si se equivoca, y
 * obligar a un segundo clic. Pedir la contraseña para cambiar el puerto SMTP no
 * añadiría seguridad; añadiría gente que la escribe sin leer.
 *
 * DE DÓNDE SALEN LAS CLAVES. De aquí, no del navegador. Quien llama pasa un mapa
 * `clave del registry ⇒ valor propuesto` construido a partir de campos con nombre
 * fijo del formulario; el registry valida que cada clave exista. No hay ninguna
 * vía por la que un campo oculto manipulado pueda decidir qué ajuste se escribe
 * ni con qué nivel se trata.
 *
 * SOLO SE LISTA LO QUE CAMBIA. Un resumen que repite los ocho campos del
 * formulario, siete de ellos idénticos, es un resumen que nadie lee.
 */
class ConfirmacionN2
{
    public function __construct(
        private readonly CatalogoAjustes $catalogo,
        private readonly Ajustes $ajustes,
        private readonly ConversorValor $conversor,
    ) {}

    /**
     * Cambios REALES que produciría este envío, en orden de catálogo.
     *
     * @param  array<string, mixed>  $propuestos  clave del registry ⇒ valor propuesto
     * @return array<int, CambioPropuesto>
     */
    public function calcular(array $propuestos): array
    {
        $cambios = [];

        foreach ($propuestos as $clave => $propuesto) {
            $definicion = $this->catalogo->definicion($clave);

            if ($definicion->tipo->esSecreto()) {
                // Un secreto solo "cambia" si vino un valor nuevo. El campo vacío
                // significa "no lo toques", nunca "bórralo".
                if (filled($propuesto)) {
                    $cambios[] = CambioPropuesto::deSecreto($definicion);
                }

                continue;
            }

            // Se comparan los textos NORMALIZADOS, no lo que escribió el usuario:
            // si no, " 587 " parecería distinto de "587" y la pantalla anunciaría
            // un cambio que no existe. Un valor inválido no se filtra acá — lo
            // rechazará la validación del formulario con su mensaje.
            try {
                $textoNuevo = $this->conversor->validarYNormalizar($definicion, $propuesto);
            } catch (ValorAjusteInvalidoException) {
                continue;
            }

            $actual = $this->ajustes->resolver($clave)->valor;
            $textoActual = $this->conversor->aTexto($definicion, $actual);

            if ($textoNuevo !== $textoActual) {
                $cambios[] = CambioPropuesto::deValor(
                    $definicion,
                    $actual,
                    $this->conversor->aValor($definicion, $textoNuevo),
                );
            }
        }

        return $cambios;
    }

    /**
     * ¿Hay que parar y pedir confirmación?
     *
     * @param  array<int, CambioPropuesto>  $cambios
     */
    public function requiereConfirmacion(array $cambios): bool
    {
        foreach ($cambios as $cambio) {
            if ($cambio->nivel->requiereConfirmacion()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nivel más alto entre los cambios, para elegir la ceremonia.
     *
     * @param  array<int, CambioPropuesto>  $cambios
     */
    public function nivelMaximo(array $cambios): NivelConfirmacion
    {
        $nivel = NivelConfirmacion::N1;

        foreach ($cambios as $cambio) {
            if ($cambio->nivel === NivelConfirmacion::N3) {
                return NivelConfirmacion::N3;
            }
            if ($cambio->nivel === NivelConfirmacion::N2) {
                $nivel = NivelConfirmacion::N2;
            }
        }

        return $nivel;
    }
}
