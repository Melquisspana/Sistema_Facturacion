<?php

namespace App\Support\Planta;

use App\Enums\Planta\TipoDiferenciaReconciliacion;

/**
 * Informe de una pasada de reconciliación.
 *
 * Incluye la HUELLA DEL MAYOR —número de filas, suma total y mayor id— tomada
 * antes y, cuando se aplican correcciones, también después. No es adorno: es la
 * prueba de que `--apply` no tocó el libro mayor. Las tres cifras juntas
 * detectan cualquier escritura posible: un INSERT mueve el count y el max(id),
 * un DELETE mueve el count, y un UPDATE de importes mueve la suma aunque count
 * y max(id) queden igual.
 */
final readonly class ResultadoReconciliacion
{
    /**
     * @param  array<int, DiferenciaReconciliacion>  $diferencias
     * @param  array{filas: int, suma: string, max_id: int}  $huellaMayorAntes
     * @param  array{filas: int, suma: string, max_id: int}|null  $huellaMayorDespues
     * @param  array<string, int>  $correcciones  Cuántas filas se insertaron/actualizaron/borraron.
     * @param  array{filas: int, suma: string}|null  $huellaProyeccionAntes  Estado de la
     *                                                                       proyección al empezar.
     * @param  array{filas: int, suma: string}|null  $huellaProyeccionDespues  Y al terminar.
     */
    public function __construct(
        public array $diferencias,
        public int $bucketsMayor,
        public int $bucketsProyectados,
        public array $huellaMayorAntes,
        public bool $aplicado = false,
        public ?array $huellaMayorDespues = null,
        public array $correcciones = ['insertadas' => 0, 'actualizadas' => 0, 'eliminadas' => 0],
        public ?array $huellaProyeccionAntes = null,
        public ?array $huellaProyeccionDespues = null,
    ) {}

    public function sinDiferencias(): bool
    {
        return $this->diferencias === [];
    }

    /** @return array<int, DiferenciaReconciliacion> */
    public function corregibles(): array
    {
        return array_values(array_filter($this->diferencias, fn ($d) => $d->esCorregible()));
    }

    /**
     * Diferencias que `--apply` NO puede arreglar porque el defecto está en el
     * mayor. Su presencia mantiene el código de salida en distinto de cero
     * aunque todo lo demás se haya corregido.
     *
     * @return array<int, DiferenciaReconciliacion>
     */
    public function irreparables(): array
    {
        return array_values(array_filter($this->diferencias, fn ($d) => ! $d->esCorregible()));
    }

    /** @return array<int, DiferenciaReconciliacion> */
    public function deTipo(TipoDiferenciaReconciliacion $tipo): array
    {
        return array_values(array_filter($this->diferencias, fn ($d) => $d->tipo === $tipo));
    }

    /** @return array<string, int> Conteo por tipo, para el resumen del comando. */
    public function conteoPorTipo(): array
    {
        $conteo = [];

        foreach ($this->diferencias as $diferencia) {
            $clave = $diferencia->tipo->value;
            $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
        }

        return $conteo;
    }

    /**
     * ¿El libro mayor quedó intacto? Compara las tres cifras de la huella. Sin
     * pasada de aplicación no hay nada que comparar y se considera intacto.
     */
    public function mayorIntacto(): bool
    {
        return $this->huellaMayorDespues === null
            || $this->huellaMayorAntes === $this->huellaMayorDespues;
    }

    /** @return array<string, mixed> Forma serializable, para Activitylog. */
    public function aArreglo(): array
    {
        return [
            'aplicado' => $this->aplicado,
            'buckets_mayor' => $this->bucketsMayor,
            'buckets_proyectados' => $this->bucketsProyectados,
            'conteo_por_tipo' => $this->conteoPorTipo(),
            'correcciones' => $this->correcciones,
            'huella_mayor_antes' => $this->huellaMayorAntes,
            'huella_mayor_despues' => $this->huellaMayorDespues,
            'mayor_intacto' => $this->mayorIntacto(),
            // Resumen ANTES/DESPUÉS de lo único que esta operación puede cambiar.
            'huella_proyeccion_antes' => $this->huellaProyeccionAntes,
            'huella_proyeccion_despues' => $this->huellaProyeccionDespues,
            'diferencias' => array_map(fn ($d) => $d->aArreglo(), $this->diferencias),
        ];
    }
}
