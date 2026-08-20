<?php

namespace App\Ajustes\Resumen;

use App\Ajustes\EstadoAjuste;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * Una tarjeta de la pantalla Resumen. Solo lectura: describe cómo está algo, no
 * ofrece forma de cambiarlo (para eso está `$ruta`, que lleva a la pantalla que
 * sí lo administra).
 *
 * NO LLEVA VALORES SECRETOS. Es un DTO de pantalla, hermano de
 * {@see EstadoAjuste}: quien lo construye ya decidió qué se puede
 * publicar. Las `$lineas` son texto ya formateado y pensado para leerse
 * ("Servidor: smtp.gmail.com:587"), nunca un volcado de configuración.
 *
 * `$ruta` es null cuando todavía no existe la pantalla correspondiente. La
 * tarjeta se muestra igual, sin botón: un enlace que no lleva a ninguna parte es
 * peor que la ausencia del enlace.
 */
class TarjetaResumen implements Arrayable
{
    /**
     * @param  array<int, string>  $lineas  Datos legibles, ya formateados.
     * @param  string|null  $fuente  De dónde sale el valor (.env, base de datos, config).
     * @param  string|null  $ruta  URL de la pantalla que lo administra, si existe.
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $titulo,
        public readonly EstadoTarjeta $estado,
        public readonly string $detalle,
        public readonly array $lineas = [],
        public readonly ?string $fuente = null,
        public readonly ?Carbon $ultimaVerificacion = null,
        public readonly ?string $resultadoVerificacion = null,
        public readonly ?string $advertencia = null,
        public readonly ?string $ruta = null,
        public readonly ?string $etiquetaRuta = null,
    ) {}

    /**
     * "hace 2 horas" para la interfaz. El timestamp REAL viaja aparte en
     * `$ultimaVerificacion`: el texto relativo es cómodo de leer pero inútil para
     * comparar, y guardar solo la frase habría dejado la fecha exacta perdida.
     */
    public function verificacionRelativa(): ?string
    {
        return $this->ultimaVerificacion?->diffForHumans();
    }

    public function verificacionExacta(): ?string
    {
        return $this->ultimaVerificacion?->format('d/m/Y H:i');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'titulo' => $this->titulo,
            'estado' => $this->estado->value,
            'detalle' => $this->detalle,
            'lineas' => $this->lineas,
            'fuente' => $this->fuente,
            'ultima_verificacion' => $this->ultimaVerificacion?->toIso8601String(),
            'resultado_verificacion' => $this->resultadoVerificacion,
            'advertencia' => $this->advertencia,
            'ruta' => $this->ruta,
        ];
    }
}
