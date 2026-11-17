<?php

namespace App\Services\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que muestra «Facturas por cobrar» de cada cliente, antes de los filtros de la pantalla.
 * La pantalla (CobrosController) y la tarjeta «Por cobrar» del Inicio parten de acá: si
 * cambia la regla, cambia en las dos. Solo lee.
 */
class BandejaCobros
{
    /** @var array<int, array<int, int>> por cliente, ver {@see saldadosConNc()} */
    private array $saldadosConNc = [];

    /**
     * Los clientes que la bandeja muestra: los de perfil documental activo.
     *
     * @return Collection<int, Cliente>
     */
    public function clientes(): Collection
    {
        return Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nombre_comercial']);
    }

    /**
     * CCF que sus NC dejan en saldo 0: fuera de la bandeja. Se calcula una vez por cliente
     * porque la bandeja arma muchas consultas (tarjetas, meses, lista).
     *
     * @return array<int, int>
     */
    public function saldadosConNc(Cliente $cliente): array
    {
        return $this->saldadosConNc[$cliente->id] ??= CobroDocumento::idsSaldadosConNc($cliente->id);
    }

    /**
     * Los documentos de la bandeja de un cliente, del tipo pedido (la lista es de CCF; las NC
     * sueltas se ven con «tipo=05»): sin los invalidados que ya no hay que mirar, sin los que
     * sus NC dejan en saldo 0 y, en el servidor real, sin los DTE del ambiente de pruebas.
     *
     * @return Builder<CobroDocumento>
     */
    public function documentos(Cliente $cliente, string $tipo = '03'): Builder
    {
        return CobroDocumento::deCliente($cliente->id)
            ->sinInvalidadosRetirables()
            ->whereKeyNot($this->saldadosConNc($cliente))
            ->where('tipo_dte', $tipo)
            ->when(config('dte.ambiente') === '01', fn ($q) => $q->whereDoesntHave('dte', fn ($d) => $d->where('ambiente', '00')));
    }

    /**
     * La tarjeta «Por cobrar» del Inicio: los CCF de la bandeja de todos sus clientes que no
     * están pagados (todas las pestañas menos «Pagados») y la suma de su saldo
     * ({@see CobroDocumento::saldo()}: el total a pagar con IVA, menos sus NC y lo cobrado).
     * Un cobro de más resta. Las consultas no crecen con la cantidad de documentos.
     *
     * @return array{cantidad: int, saldo: string}
     */
    public function porCobrar(): array
    {
        $cantidad = 0;
        $saldo = '0';
        foreach ($this->clientes() as $cliente) {
            $pendientes = $this->documentos($cliente)->pendienteDeCobro()
                ->get(['id', 'dte_id', 'tipo_dte', 'monto', 'monto_pagado']);
            $cantidad += $pendientes->count();
            foreach (CobroDocumento::saldos($pendientes) as $saldoDocumento) {
                $saldo = Dinero::sumar($saldo, $saldoDocumento);
            }
        }

        return ['cantidad' => $cantidad, 'saldo' => Dinero::redondear($saldo)];
    }
}
