<?php

namespace App\Support\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use Carbon\CarbonImmutable;

/**
 * QUÉ marcaciones se quieren. Un objeto de criterios, inmutable y sin nada de HTTP.
 *
 * Existe para que {@see ConsultaAsistencia} reciba UNA cosa en vez de seis
 * argumentos opcionales, y sobre todo para que quien la llame no tenga que ser una
 * petición web. El futuro módulo de Formatos va a construir uno de estos desde la
 * definición de un formato —«marzo, sucursal X»— sin que exista un `Request` por
 * ningún lado; un comando de consola, igual.
 *
 * ───────────────────────── Las fechas son DÍAS LOCALES ─────────────────────────
 *
 * `$desde` y `$hasta` son días en la zona del módulo, NO instantes, y el rango es
 * INCLUSIVO en los dos extremos. Se comparan contra `fecha_local`, que es la
 * columna que guarda exactamente eso.
 *
 * No es un detalle de implementación: en El Salvador (UTC−6) una marcación de las
 * 19:30 del día 5 se guarda como 01:30 UTC del día 6. Filtrar por `marcado_at`
 * —el instante— dejaría esa marcación FUERA del día 5 y la metería en el 6. La
 * pregunta «qué se marcó el jueves» es una pregunta sobre el día local, y esta
 * clase la representa como tal.
 *
 * ────────────────────────────── Inmutable ──────────────────────────────
 *
 * Los `con*()` devuelven una copia. Así un filtro base —«marzo entero»— se puede
 * reutilizar para cada persona de un formato sin que una iteración contamine la
 * siguiente.
 */
final class FiltroAsistencia
{
    private function __construct(
        public readonly ?int $empleadoId,
        public readonly ?CarbonImmutable $desde,
        public readonly ?CarbonImmutable $hasta,
        public readonly ?int $dispositivoId,
        public readonly ?TipoMarcacion $tipo,
        public readonly ?string $origen,
        /** Orden cronológico ascendente. La UI quiere lo último arriba; un formato, al revés. */
        public readonly bool $ascendente,
    ) {}

    /** Sin ningún criterio: todas las marcaciones. */
    public static function vacio(): self
    {
        return new self(null, null, null, null, null, null, false);
    }

    /**
     * Desde datos sueltos (un formulario ya validado, una definición de formato, los
     * argumentos de un comando). Ignora lo que no reconoce y normaliza lo que sí:
     * las fechas se recortan a día, y un tipo u origen que no existe se descarta en
     * vez de producir una consulta que no devuelve nada por un motivo invisible.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function desdeArray(array $datos): self
    {
        return new self(
            empleadoId: self::entero($datos['empleado_id'] ?? null),
            desde: self::dia($datos['desde'] ?? null),
            hasta: self::dia($datos['hasta'] ?? null),
            dispositivoId: self::entero($datos['dispositivo_id'] ?? null),
            tipo: TipoMarcacion::tryFrom((string) ($datos['tipo'] ?? '')),
            origen: self::origen($datos['origen'] ?? null),
            ascendente: (bool) ($datos['ascendente'] ?? false),
        );
    }

    public function conEmpleado(?int $empleadoId): self
    {
        return new self($empleadoId, $this->desde, $this->hasta, $this->dispositivoId, $this->tipo, $this->origen, $this->ascendente);
    }

    /** Rango de DÍAS locales, inclusivo en ambos extremos. */
    public function conRango(?CarbonImmutable $desde, ?CarbonImmutable $hasta): self
    {
        return new self(
            $this->empleadoId,
            $desde?->startOfDay(),
            $hasta?->startOfDay(),
            $this->dispositivoId,
            $this->tipo,
            $this->origen,
            $this->ascendente,
        );
    }

    public function conDispositivo(?int $dispositivoId): self
    {
        return new self($this->empleadoId, $this->desde, $this->hasta, $dispositivoId, $this->tipo, $this->origen, $this->ascendente);
    }

    public function conTipo(?TipoMarcacion $tipo): self
    {
        return new self($this->empleadoId, $this->desde, $this->hasta, $this->dispositivoId, $tipo, $this->origen, $this->ascendente);
    }

    public function conOrigen(?string $origen): self
    {
        return new self($this->empleadoId, $this->desde, $this->hasta, $this->dispositivoId, $this->tipo, self::origen($origen), $this->ascendente);
    }

    /** Cronológico ascendente: lo que quiere un documento, no una pantalla. */
    public function ascendente(bool $ascendente = true): self
    {
        return new self($this->empleadoId, $this->desde, $this->hasta, $this->dispositivoId, $this->tipo, $this->origen, $ascendente);
    }

    public function tieneFiltros(): bool
    {
        return $this->empleadoId !== null
            || $this->desde !== null
            || $this->hasta !== null
            || $this->dispositivoId !== null
            || $this->tipo !== null
            || $this->origen !== null;
    }

    /** ¿El rango está al revés? Lo pregunta quien valide; esta clase no corrige nada sola. */
    public function rangoInvertido(): bool
    {
        return $this->desde !== null && $this->hasta !== null && $this->desde->greaterThan($this->hasta);
    }

    /**
     * Los criterios en texto, para encabezar un documento o un registro. Vive acá
     * —y no en una vista— porque el futuro módulo de Formatos necesita rotular el
     * documento con el mismo criterio que usó para consultarlo, y no tiene vistas
     * de asistencia de donde copiarlo.
     *
     * @param  array<int, string>  $nombres  ['empleado' => 'Ana Pérez', 'dispositivo' => 'Entrada']
     */
    public function descripcion(array $nombres = []): string
    {
        $partes = [];

        if ($this->empleadoId !== null) {
            $partes[] = 'Empleado: '.($nombres['empleado'] ?? '#'.$this->empleadoId);
        }

        if ($this->desde !== null && $this->hasta !== null) {
            $partes[] = $this->desde->equalTo($this->hasta)
                ? 'Día '.$this->desde->format('d/m/Y')
                : 'Del '.$this->desde->format('d/m/Y').' al '.$this->hasta->format('d/m/Y');
        } elseif ($this->desde !== null) {
            $partes[] = 'Desde el '.$this->desde->format('d/m/Y');
        } elseif ($this->hasta !== null) {
            $partes[] = 'Hasta el '.$this->hasta->format('d/m/Y');
        }

        if ($this->dispositivoId !== null) {
            $partes[] = 'Lector: '.($nombres['dispositivo'] ?? '#'.$this->dispositivoId);
        }

        if ($this->tipo !== null) {
            $partes[] = 'Solo '.strtolower($this->tipo->label()).'s';
        }

        if ($this->origen !== null) {
            $partes[] = 'Origen: '.$this->origen;
        }

        return $partes === [] ? 'Todas las marcaciones' : implode(' · ', $partes);
    }

    /** @return array<string, mixed> Para reconstruirlo, o para conservar los filtros en una URL. */
    public function toArray(): array
    {
        return [
            'empleado_id' => $this->empleadoId,
            'desde' => $this->desde?->format('Y-m-d'),
            'hasta' => $this->hasta?->format('Y-m-d'),
            'dispositivo_id' => $this->dispositivoId,
            'tipo' => $this->tipo?->value,
            'origen' => $this->origen,
            'ascendente' => $this->ascendente,
        ];
    }

    // ---------------------------------------------------------------- interno

    private static function entero(mixed $valor): ?int
    {
        return ($valor === null || $valor === '' || ! is_numeric($valor)) ? null : (int) $valor;
    }

    private static function dia(mixed $valor): ?CarbonImmutable
    {
        if ($valor instanceof CarbonImmutable) {
            return $valor->startOfDay();
        }

        if ($valor === null || $valor === '') {
            return null;
        }

        // Una fecha ilegible se descarta en vez de reventar: quien llama puede ser
        // un formato guardado hace meses, y un criterio que ya no se entiende no
        // debería tumbar el documento entero.
        try {
            return CarbonImmutable::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Los dos únicos orígenes que la tabla puede contener hoy. */
    private static function origen(mixed $valor): ?string
    {
        $origen = is_string($valor) ? trim($valor) : '';

        return in_array($origen, ['dispositivo', 'manual'], true) ? $origen : null;
    }
}
