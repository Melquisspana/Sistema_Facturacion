<?php

namespace Tests\Concerns;

use App\Models\Cliente;
use Illuminate\Testing\TestResponse;

trait RevisaYAplicaTxt
{
    protected function revisarYAplicarTxt(Cliente $cliente, array $datos): TestResponse
    {
        $revision = $this->post(route('cobros.pagos.revisar', $cliente), [
            'archivos' => [$datos['archivo']],
        ] + array_intersect_key($datos, ['fecha_pago' => true]));
        if ($revision->status() !== 200) {
            return $revision;
        }
        $hashes = collect($revision->viewData('archivos'))->where('estado', 'aplicado')->pluck('hash')->unique()->values()->all();
        if ($hashes === []) {
            return $revision;
        }

        return $this->post(route('cobros.pagos.aplicar', $cliente), ['hashes' => $hashes]);
    }
}
