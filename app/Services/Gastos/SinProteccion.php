<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Database\Query\Builder;

/**
 * Nadie protege nada: todo gasto es un gasto corriente.
 *
 * Es la implementación por defecto y el comportamiento correcto cuando el módulo que
 * protegería no está instalado. Gastos sigue funcionando igual que siempre, y ninguna
 * de sus consultas recibe una cláusula de más.
 */
final class SinProteccion implements ProteccionDeGastos
{
    public function ocultosPara(User $usuario): ?Builder
    {
        return null;
    }

    public function protegidos(): ?Builder
    {
        return null;
    }

    public function puedeVer(User $usuario, int $gastoId): bool
    {
        return true;
    }

    public function motivoParaNoPagar(User $usuario, array $gastoIds): ?string
    {
        return null;
    }

    public function motivoParaNoRevertir(User $usuario, Pago $pago): ?string
    {
        return null;
    }

    public function impedimentoParaRevertir(Pago $pago): ?string
    {
        return null;
    }
}
