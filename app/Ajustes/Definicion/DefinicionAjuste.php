<?php

namespace App\Ajustes\Definicion;

/**
 * Metadata COMPLETA de un ajuste. Vive en código (no en base de datos) por tres
 * razones concretas:
 *
 *  1. una fila de BD no puede declarar reglas de validación ni valores por
 *     defecto sin convertirse en un mini-lenguaje;
 *  2. la metadata es parte de la revisión de código: agregar un ajuste fiscal
 *     crítico debe verse en un diff, no aparecer por un INSERT;
 *  3. la tabla queda mínima (clave/valor/cifrado), que es lo único que de verdad
 *     cambia en tiempo de ejecución.
 *
 * Un objeto de esta clase es inmutable y se construye con {@see hacer()}.
 */
class DefinicionAjuste
{
    /**
     * @param  string  $clave  Identificador único, «seccion.subseccion.nombre».
     * @param  string  $seccion  Agrupador para la futura pantalla.
     * @param  array<int, string>  $opciones  Allowlist para TipoAjuste::Enumerado.
     * @param  string|null  $claveConfig  Clave de config() usada como fallback. NULL = sin fallback.
     * @param  string|null  $claveLegacy  Clave en la tabla `configuraciones` (transición).
     * @param  array<int, string>  $reglas  Reglas Laravel adicionales a las del tipo.
     */
    private function __construct(
        public readonly string $clave,
        public readonly string $seccion,
        public readonly TipoAjuste $tipo,
        public readonly Sensibilidad $sensibilidad,
        public readonly Impacto $impacto,
        public readonly NivelConfirmacion $nivel,
        public readonly Editabilidad $editabilidad,
        public readonly Persistencia $persistencia,
        public readonly ?string $claveConfig,
        public readonly ?string $claveLegacy,
        public readonly mixed $porDefecto,
        public readonly array $opciones,
        public readonly array $reglas,
        public readonly string $etiqueta,
        public readonly string $descripcion,
    ) {}

    /**
     * @param  array<int, string>  $opciones
     * @param  array<int, string>  $reglas
     */
    public static function hacer(
        string $clave,
        string $seccion,
        TipoAjuste $tipo,
        Sensibilidad $sensibilidad,
        Impacto $impacto,
        NivelConfirmacion $nivel,
        Editabilidad $editabilidad,
        Persistencia $persistencia,
        string $etiqueta,
        string $descripcion = '',
        ?string $claveConfig = null,
        ?string $claveLegacy = null,
        mixed $porDefecto = null,
        array $opciones = [],
        array $reglas = [],
    ): self {
        // Invariantes que NO deben poder llegar a producción como bug silencioso.
        if ($tipo === TipoAjuste::Enumerado && $opciones === []) {
            throw new \InvalidArgumentException("El ajuste enumerado «{$clave}» debe declarar sus opciones.");
        }

        if ($tipo->esSecreto() && $sensibilidad !== Sensibilidad::SecretoCritico) {
            throw new \InvalidArgumentException("El ajuste secreto «{$clave}» debe declararse con sensibilidad secreto_critico.");
        }

        // Un secreto solo puede persistirse donde hay cifrado. Si alguien intenta
        // mandarlo a la tabla legacy (texto plano), falla al arrancar, no en runtime.
        if ($tipo->esSecreto() && $persistencia->admiteOverride() && ! $persistencia->admiteSecretos()) {
            throw new \InvalidArgumentException("El ajuste secreto «{$clave}» solo puede persistirse en la tabla nueva (cifrada).");
        }

        if ($persistencia === Persistencia::Legacy && $claveLegacy === null) {
            throw new \InvalidArgumentException("El ajuste «{$clave}» persiste en la tabla anterior pero no declara su clave legacy.");
        }

        // Un ajuste editable tiene que tener DÓNDE guardarse.
        if ($editabilidad->permiteEscritura() && ! $persistencia->admiteOverride()) {
            throw new \InvalidArgumentException("El ajuste «{$clave}» es editable pero no declara dónde persistirse.");
        }

        return new self(
            clave: $clave,
            seccion: $seccion,
            tipo: $tipo,
            sensibilidad: $sensibilidad,
            impacto: $impacto,
            nivel: $nivel,
            editabilidad: $editabilidad,
            persistencia: $persistencia,
            claveConfig: $claveConfig,
            claveLegacy: $claveLegacy,
            porDefecto: $porDefecto,
            opciones: array_values($opciones),
            reglas: array_values($reglas),
            etiqueta: $etiqueta,
            descripcion: $descripcion,
        );
    }

    /** ¿Su valor se guarda cifrado? Hoy equivale a "es secreto"; queda aislado por si mañana no. */
    public function seCifra(): bool
    {
        return $this->tipo->esSecreto();
    }

    /** ¿Su valor puede salir hacia una vista/JSON? */
    public function valorMostrable(): bool
    {
        return ! $this->tipo->esSecreto() && $this->sensibilidad !== Sensibilidad::SecretoCritico;
    }

    /** ¿Su valor puede quedar escrito en la auditoría? */
    public function valorAuditable(): bool
    {
        return $this->valorMostrable() && $this->sensibilidad->valorAuditable();
    }
}
