<?php

namespace App\Services\Sistema;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Services\Alertas\Detectores\DatosQueNoCuadran;

/**
 * Alertas de integridad de datos: conteos de lo que rompe una regla (clientes o precios
 * duplicados, documentos sin número o sin líneas, notas sin tipo…). Las muestra Salud
 * del sistema y las críticas las avisa por correo
 * {@see DatosQueNoCuadran}. Solo lee.
 */
class IntegridadDatos
{
    /** @return array<int, array{label: string, count: int, estado: string}> */
    public function contar(): array
    {
        // Productos activos sin precio aplicable (general <=0 y sin especial activo).
        $conEspecial = ProductoPrecioCliente::where('activo', true)->pluck('producto_id')->unique();
        $sinPrecio = Producto::where('activo', true)
            ->where(fn ($q) => $q->whereNull('precio_unitario')->orWhere('precio_unitario', '<=', 0))
            ->whereNotIn('id', $conEspecial)->count();

        $sinBarra = Producto::where('activo', true)
            ->where(fn ($q) => $q->whereNull('codigo_barra')->orWhere('codigo_barra', ''))->count();

        $cliDup = Cliente::whereNotNull('num_documento')
            ->selectRaw('num_documento, count(*) c')->groupBy('num_documento')->havingRaw('count(*) > 1')->get()->count();

        // Salas duplicadas por cliente + nombre normalizado (colapsar espacios).
        $salasDup = ClienteSucursal::where('activo', true)->get(['cliente_id', 'nombre'])
            ->groupBy(fn ($s) => $s->cliente_id.'|'.mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s->nombre))))
            ->filter(fn ($g) => $g->count() > 1)->count();

        $preciosDup = ProductoPrecioCliente::where('activo', true)
            ->selectRaw('producto_id, cliente_id, cliente_sucursal_id, count(*) c')
            ->groupBy('producto_id', 'cliente_id', 'cliente_sucursal_id')->havingRaw('count(*) > 1')->get()->count();

        $ccfSinNumero = Dte::where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('estado', EstadoDte::Generado->value)->whereNull('numero_interno')->count();

        $generadosSinLineas = Dte::where('estado', EstadoDte::Generado->value)->whereDoesntHave('lineas')->count();

        $borradorTotal0 = Dte::where('estado', EstadoDte::Borrador->value)->where('total_pagar', 0)->count();

        $ncAutoRel = Dte::where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereColumn('dte_relacionado_id', 'id')->count();

        $ncSinTipo = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->whereNull('tipo_nota_credito')->count();

        $ncDevolSinRel = Dte::where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereIn('tipo_nota_credito', [TipoNotaCredito::DevolucionProducto->value, TipoNotaCredito::FaltanteEntrega->value])
            ->whereNull('dte_relacionado_id')->count();

        // estado: 'critico' = rompe invariantes; 'advertencia' = a revisar.
        return [
            ['label' => 'Productos activos sin precio aplicable', 'count' => $sinPrecio, 'estado' => 'advertencia'],
            ['label' => 'Productos activos sin código de barra', 'count' => $sinBarra, 'estado' => 'advertencia'],
            ['label' => 'Clientes duplicados por número de documento', 'count' => $cliDup, 'estado' => 'critico'],
            ['label' => 'Salas duplicadas (cliente + nombre normalizado)', 'count' => $salasDup, 'estado' => 'advertencia'],
            ['label' => 'Precios activos duplicados (producto+cliente+sucursal)', 'count' => $preciosDup, 'estado' => 'critico'],
            ['label' => 'CCF generados sin número interno', 'count' => $ccfSinNumero, 'estado' => 'critico'],
            ['label' => 'Documentos generados sin líneas', 'count' => $generadosSinLineas, 'estado' => 'critico'],
            ['label' => 'Documentos borrador con total 0', 'count' => $borradorTotal0, 'estado' => 'advertencia'],
            ['label' => 'Notas de crédito relacionadas consigo mismas', 'count' => $ncAutoRel, 'estado' => 'critico'],
            ['label' => 'Notas de crédito sin tipo', 'count' => $ncSinTipo, 'estado' => 'critico'],
            ['label' => 'NC devolución/faltante sin documento relacionado', 'count' => $ncDevolSinRel, 'estado' => 'critico'],
        ];
    }
}
