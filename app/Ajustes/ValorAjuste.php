<?php

namespace App\Ajustes;

use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;

/**
 * Resultado INTERNO de resolver un ajuste: el valor tipado y de dónde salió.
 *
 * Para un secreto, `$valor` sí lleva el contenido: es lo que necesita el servicio
 * autorizado que va a autenticarse. Por eso este objeto NUNCA se pasa a una vista
 * ni se serializa a JSON — lo que va a pantalla es {@see EstadoAjuste}, que no
 * tiene forma de llevar el valor de un secreto aunque alguien lo intente.
 */
class ValorAjuste
{
    public function __construct(
        public readonly DefinicionAjuste $definicion,
        public readonly mixed $valor,
        public readonly FuenteAjuste $fuente,
    ) {}

    public function configurado(): bool
    {
        return $this->valor !== null && $this->valor !== '';
    }
}
