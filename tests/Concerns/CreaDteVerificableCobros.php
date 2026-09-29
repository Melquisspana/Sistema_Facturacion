<?php

namespace Tests\Concerns;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use Illuminate\Support\Str;

/** DTE aceptado mínimo para pruebas de Cobros que deben poder verificar sus NC. */
trait CreaDteVerificableCobros
{
    private ?Establecimiento $establecimientoCobrosPrueba = null;

    protected function dteVerificableCobros(
        Cliente $cliente,
        string $numeroControl,
        string $fechaEmision,
        string $monto,
        string $tipo = '03',
    ): Dte {
        if ($this->establecimientoCobrosPrueba === null) {
            $empresa = Empresa::create(['razon_social' => 'Emisor de prueba']);
            $this->establecimientoCobrosPrueba = Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz de prueba',
            ]);
        }

        return Dte::create([
            'establecimiento_id' => $this->establecimientoCobrosPrueba->id,
            'cliente_id' => $cliente->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => $numeroControl,
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => 'SELLO-PRUEBA-'.Str::random(12),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => $fechaEmision,
            'hora_emision' => '08:00:00',
            'total_pagar' => $monto,
        ]);
    }
}
