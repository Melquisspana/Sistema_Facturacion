<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Sistema\IntegridadDatos;
use Illuminate\Support\Facades\Cache;

/**
 * Las alertas de datos que Salud del sistema marca en rojo ({@see IntegridadDatos}). Las
 * advertencias quedan en la pantalla y no mandan correo. El resultado (solo nombres de
 * regla y cantidades) se guarda una hora. Lo que ya estaba mal se avisa una vez y
 * después solo si sube.
 */
class DatosQueNoCuadran implements Detector
{
    public const CLAVE = 'alertas.vigilancia.datos';

    private const MINUTOS_GUARDADO = 60;

    public function __construct(private readonly IntegridadDatos $integridad) {}

    public function tipo(): string
    {
        return 'integridad_datos';
    }

    public function nombre(): string
    {
        return 'Datos que no cuadran';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $criticas = Cache::remember(self::CLAVE, self::MINUTOS_GUARDADO * 60, fn () => array_values(array_map(
            fn (array $fila) => ['label' => $fila['label'], 'count' => (int) $fila['count']],
            array_filter($this->integridad->contar(), fn (array $fila) => $fila['estado'] === 'critico' && $fila['count'] > 0),
        )));
        if ($criticas === []) {
            return null;
        }

        return new Hallazgo(
            titulo: count($criticas) === 1 ? 'Hay datos que no cuadran' : count($criticas).' tipos de datos no cuadran',
            detalle: implode('; ', array_map(fn (array $f) => $f['label'].': '.$f['count'], $criticas)).'.',
            queHacer: 'Abrí Salud del sistema › Alertas de datos y corregí cada uno; si no sabés cómo, pasá la lista a quien te ayuda con el sistema.',
            conteo: array_sum(array_column($criticas, 'count')),
            enlace: 'admin.salud-sistema',
        );
    }
}
