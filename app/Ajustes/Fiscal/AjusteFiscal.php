<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\EstadoAjuste;

/**
 * UNA FILA de las pantallas de facturación electrónica: qué es, cuánto vale
 * ahora, de dónde sale ese valor y qué se podrá hacer con él.
 *
 * Es el único objeto que llega a esas vistas. Igual que {@see EstadoAjuste}, la
 * garantía de que no lleva secretos es estructural: `$valor` es SIEMPRE un texto
 * ya preparado para leerse, y quien construye la fila de una credencial escribe
 * ahí «configurada» o «sin configurar», nunca el valor. No hay un camino por el
 * que una contraseña llegue a esta clase y salga por la vista.
 *
 * `$nota` es para lo que el valor por sí solo no cuenta: que una clave esté
 * duplicada en otro sitio, que nadie la lea, que su nombre no signifique lo que
 * parece. Es la mitad del trabajo de estas pantallas — un inventario que solo
 * lista valores no evita ni un error.
 */
class AjusteFiscal
{
    /**
     * @param  string  $etiqueta  Nombre HUMANO. Es lo que se lee primero.
     * @param  string  $valor  Texto ya listo para mostrar. NUNCA un secreto.
     * @param  string|null  $env  Variable de entorno equivalente, para cruzar con el .env del servidor.
     * @param  string|null  $fuente  De dónde salió el valor («Archivo de configuración», «Valor por defecto»...).
     * @param  string|null  $nota  Advertencia o matiz: duplicado, sin consumidor, nombre confuso.
     * @param  bool  $atencion  Pinta la fila como problema (incoherencia, candado abierto de más).
     */
    private function __construct(
        public readonly string $etiqueta,
        public readonly string $valor,
        public readonly ClasificacionFiscal $clasificacion,
        public readonly ?string $descripcion,
        public readonly ?string $env,
        public readonly ?string $fuente,
        public readonly ?string $nota,
        public readonly bool $atencion,
    ) {}

    public static function hacer(
        string $etiqueta,
        string $valor,
        ClasificacionFiscal $clasificacion,
        ?string $descripcion = null,
        ?string $env = null,
        ?string $fuente = null,
        ?string $nota = null,
        bool $atencion = false,
    ): self {
        return new self($etiqueta, $valor, $clasificacion, $descripcion, $env, $fuente, $nota, $atencion);
    }

    /**
     * Fila a partir de un ajuste YA DECLARADO en el catálogo. Es la vía preferente:
     * la etiqueta, la descripción y la fuente salen de la declaración, así que la
     * pantalla no puede describir el ajuste de una forma y el registry de otra.
     *
     * `$valor` se pasa aparte porque el texto legible casi nunca es el valor crudo:
     * «00» se lee «00 — Pruebas», y `false` se lee «deshabilitada».
     */
    public static function deEstado(
        EstadoAjuste $estado,
        string $valor,
        ClasificacionFiscal $clasificacion,
        ?string $env = null,
        ?string $nota = null,
        bool $atencion = false,
    ): self {
        return new self(
            etiqueta: $estado->etiqueta,
            valor: $valor,
            clasificacion: $clasificacion,
            descripcion: $estado->descripcion !== '' ? $estado->descripcion : null,
            env: $env,
            fuente: $estado->fuente->etiqueta(),
            nota: $nota,
            atencion: $atencion,
        );
    }
}
