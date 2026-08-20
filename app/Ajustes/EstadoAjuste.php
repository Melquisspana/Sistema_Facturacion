<?php

namespace App\Ajustes;

use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Lo ÚNICO que puede salir hacia una vista, un JSON o un log.
 *
 * La garantía es estructural, no una convención: para un ajuste secreto este
 * objeto se construye SIEMPRE con `$valor = null` (ver {@see deSecreto()}), así
 * que no existe un camino —ni por descuido, ni por un `@dd`, ni por un
 * `response()->json()`— que devuelva la contraseña al navegador. De un secreto se
 * publica lo que hace falta para administrarlo:
 *
 *   { configurado: true, fuente: "base_de_datos" }
 *
 * y nunca:
 *
 *   { valor: "..." }
 *
 * Es también el DTO que consumirá la futura pantalla "Resumen": `configurado`,
 * `fuente` y `advertencia` ya están; `ultimaVerificacion` se agregará cuando
 * exista algo que verificar (una prueba de conexión SMTP, un login a Hacienda),
 * porque hoy no habría con qué llenarlo.
 */
class EstadoAjuste implements Arrayable, JsonSerializable
{
    /**
     * @param  mixed  $valor  SIEMPRE null para secretos.
     * @param  string|null  $advertencia  Aviso para el administrador (ej. sin configurar).
     */
    private function __construct(
        public readonly string $clave,
        public readonly string $seccion,
        public readonly string $etiqueta,
        public readonly string $descripcion,
        public readonly string $tipo,
        public readonly string $nivel,
        public readonly string $impacto,
        public readonly bool $editable,
        public readonly bool $esSecreto,
        public readonly bool $configurado,
        public readonly FuenteAjuste $fuente,
        public readonly mixed $valor,
        public readonly ?string $advertencia,
    ) {}

    public static function desde(ValorAjuste $resuelto): self
    {
        $definicion = $resuelto->definicion;

        return $definicion->valorMostrable()
            ? self::deValorVisible($definicion, $resuelto)
            : self::deSecreto($definicion, $resuelto);
    }

    private static function deValorVisible(DefinicionAjuste $definicion, ValorAjuste $resuelto): self
    {
        return new self(
            clave: $definicion->clave,
            seccion: $definicion->seccion,
            etiqueta: $definicion->etiqueta,
            descripcion: $definicion->descripcion,
            tipo: $definicion->tipo->value,
            nivel: $definicion->nivel->value,
            impacto: $definicion->impacto->value,
            editable: $definicion->editabilidad->permiteEscritura(),
            esSecreto: false,
            configurado: $resuelto->configurado(),
            fuente: $resuelto->fuente,
            valor: $resuelto->valor,
            advertencia: self::advertencia($resuelto),
        );
    }

    /** El valor se DESCARTA acá, en el constructor, no "se evita mostrar" más adelante. */
    private static function deSecreto(DefinicionAjuste $definicion, ValorAjuste $resuelto): self
    {
        return new self(
            clave: $definicion->clave,
            seccion: $definicion->seccion,
            etiqueta: $definicion->etiqueta,
            descripcion: $definicion->descripcion,
            tipo: $definicion->tipo->value,
            nivel: $definicion->nivel->value,
            impacto: $definicion->impacto->value,
            editable: $definicion->editabilidad->permiteEscritura(),
            esSecreto: true,
            configurado: $resuelto->configurado(),
            fuente: $resuelto->fuente,
            valor: null,
            advertencia: self::advertencia($resuelto),
        );
    }

    private static function advertencia(ValorAjuste $resuelto): ?string
    {
        if (! $resuelto->configurado()) {
            return "«{$resuelto->definicion->etiqueta}» no está configurado.";
        }

        if ($resuelto->fuente === FuenteAjuste::Defecto) {
            return "«{$resuelto->definicion->etiqueta}» está usando el valor por defecto del sistema.";
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'seccion' => $this->seccion,
            'etiqueta' => $this->etiqueta,
            'descripcion' => $this->descripcion,
            'tipo' => $this->tipo,
            'nivel' => $this->nivel,
            'impacto' => $this->impacto,
            'editable' => $this->editable,
            'es_secreto' => $this->esSecreto,
            'configurado' => $this->configurado,
            'fuente' => $this->fuente->value,
            // Para un secreto esta clave vale null porque el objeto se construyó
            // así, no porque acá se decida ocultarla.
            'valor' => $this->valor,
            'advertencia' => $this->advertencia,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
