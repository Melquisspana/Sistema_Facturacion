<?php

namespace Tests\Feature\Dte;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando dte:insumos reporta (solo lectura) el estado de schemas y catálogos.
 * No genera JSON ni toca facturación.
 */
class DteInsumosCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporta_schemas_faltantes_y_catalogos(): void
    {
        $this->artisan('dte:insumos')
            ->assertExitCode(0)
            ->expectsOutputToContain('JSON Schemas')
            ->expectsOutputToContain('FALTA')        // ningún schema oficial colocado
            ->expectsOutputToContain('No se genera JSON oficial');
    }
}
