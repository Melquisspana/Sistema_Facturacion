<?php

namespace App\Services\Importacion;

/**
 * Resumen del resultado de una importación: contadores + detalle por fila.
 *
 * Cada detalle es ['fila','accion','nombre','detalle'] con accion ∈
 * creado | actualizado | ignorado | advertencia | error.
 */
class ResultadoImportacion
{
    public int $leidas = 0;

    public int $creadas = 0;

    public int $actualizadas = 0;

    public int $ignoradas = 0;

    public int $advertencias = 0;

    public int $errores = 0;

    /** @var array<int, array{fila: int, accion: string, nombre: string, detalle: string}> */
    public array $detalles = [];

    public function creada(int $fila, string $nombre, string $detalle = ''): void
    {
        $this->creadas++;
        $this->push($fila, 'creado', $nombre, $detalle);
    }

    public function actualizada(int $fila, string $nombre, string $detalle = ''): void
    {
        $this->actualizadas++;
        $this->push($fila, 'actualizado', $nombre, $detalle);
    }

    public function ignorada(int $fila, string $nombre, string $detalle = ''): void
    {
        $this->ignoradas++;
        $this->push($fila, 'ignorado', $nombre, $detalle);
    }

    public function advertencia(int $fila, string $nombre, string $detalle = ''): void
    {
        $this->advertencias++;
        $this->push($fila, 'advertencia', $nombre, $detalle);
    }

    public function error(int $fila, string $nombre, string $detalle = ''): void
    {
        $this->errores++;
        $this->push($fila, 'error', $nombre, $detalle);
    }

    private function push(int $fila, string $accion, string $nombre, string $detalle): void
    {
        $this->detalles[] = [
            'fila' => $fila,
            'accion' => $accion,
            'nombre' => $nombre,
            'detalle' => $detalle,
        ];
    }

    /** @return array<string, mixed> Para guardar en sesión y mostrar en la vista. */
    public function aArray(): array
    {
        return [
            'leidas' => $this->leidas,
            'creadas' => $this->creadas,
            'actualizadas' => $this->actualizadas,
            'ignoradas' => $this->ignoradas,
            'advertencias' => $this->advertencias,
            'errores' => $this->errores,
            'detalles' => $this->detalles,
        ];
    }
}
