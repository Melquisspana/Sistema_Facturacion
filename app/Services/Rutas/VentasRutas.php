<?php

namespace App\Services\Rutas;

use App\Models\Dte;
use App\Models\Producto;
use App\Models\Ruta;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ventas NACIONALES por producto, sala y ruta: CCF − NC, sin IVA, solo documentos reales.
 * Una NC resta en el período del CCF que corrige. La exportación (FEX) nunca se suma acá.
 */
class VentasRutas
{
    public const BAJA_ALERTA = 0.30;

    public function consultar(array $entrada = []): array
    {
        $filtros = Validator::make($entrada, [
            'periodo' => ['sometimes', Rule::in(['mes', 'mes_pasado', 'tres_meses', 'rango'])],
            'desde' => ['required_if:periodo,rango', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['required_if:periodo,rango', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'ruta' => ['nullable', 'integer', 'min:0', function ($atributo, $valor, $fallar) {
                if ((int) $valor !== 0 && ! Ruta::whereKey($valor)->exists()) {
                    $fallar('La ruta no existe.');
                }
            }],
            'cliente' => ['nullable', 'integer', 'exists:clientes,id'],
            'sala' => ['nullable', 'integer', 'exists:cliente_sucursales,id'],
            'producto' => ['nullable', 'integer', 'min:0', function ($atributo, $valor, $fallar) {
                if ((int) $valor !== 0 && ! Producto::whereKey($valor)->exists()) {
                    $fallar('El producto no existe.');
                }
            }],
            'vista' => ['sometimes', Rule::in(['productos', 'salas', 'rutas'])],
            'top' => ['sometimes', Rule::in(['monto', 'cantidad'])],
        ])->validate();
        $periodo = $this->periodo($filtros);

        // La fecha efectiva de una NC viene del CCF, incluso si la NC se emitió después.
        // Los joins evitan N+1 y permiten limitar en SQL el rango efectivo completo.
        $referencia = "CASE WHEN dtes.tipo_dte = '05' THEN COALESCE(original.dte_id, dtes.dte_relacionado_id, dtes.id) ELSE dtes.id END";
        $reales = Dte::query()->produccion()->aceptadoRealMh()->noArchivados()->select('id');
        $query = Dte::query()->whereIn('dtes.id', $reales)
            ->whereIn('dtes.tipo_dte', ['03', '05'])
            ->join('dte_lineas as linea', 'linea.dte_id', '=', 'dtes.id')
            ->leftJoin('dte_lineas as original', 'original.id', '=', 'linea.dte_linea_original_id')
            ->join('dtes as referencia', fn ($join) => $join->on('referencia.id', '=', DB::raw($referencia)))
            ->leftJoin('cliente_sucursales as sala', 'sala.id', '=', 'referencia.cliente_sucursal_id')
            ->leftJoin('clientes as cliente', 'cliente.id', '=', 'referencia.cliente_id')
            ->leftJoin('rutas as ruta', 'ruta.id', '=', 'sala.ruta_id')
            ->leftJoin('productos as producto', function ($join) {
                $join->on('producto.id', '=', DB::raw("CASE WHEN dtes.tipo_dte = '05' THEN COALESCE(original.producto_id, linea.producto_id) ELSE linea.producto_id END"));
            })
            ->whereBetween('referencia.fecha_emision', [$periodo['anterior_desde']->toDateString(), $periodo['hasta']->toDateString()])
            ->selectRaw('dtes.id as documento_id, dtes.tipo_dte as tipo, dtes.subtotal as base, dtes.total_descuento as descuento, linea.cantidad, linea.venta_gravada, linea.venta_exenta, linea.venta_no_sujeta, referencia.fecha_emision as fecha, sala.id as sala_id, sala.nombre as sala_nombre, cliente.nombre as cliente_nombre, ruta.id as ruta_id, ruta.nombre as ruta_nombre, producto.id as producto_id, producto.nombre as producto_nombre');

        foreach (['ruta' => 'sala.ruta_id', 'sala' => 'referencia.cliente_sucursal_id', 'cliente' => 'referencia.cliente_id'] as $filtro => $columna) {
            if (isset($filtros[$filtro])) {
                if ($filtro === 'ruta' && (int) $filtros[$filtro] === 0) {
                    $query->whereNull($columna);
                } else {
                    $query->where($columna, $filtros[$filtro]);
                }
            }
        }
        if (isset($filtros['producto'])) {
            if ((int) $filtros['producto'] === 0) {
                $query->whereNull('producto.id');
            } else {
                $query->where('producto.id', $filtros['producto']);
            }
        }

        $totales = $this->vacio();
        $grupos = ['productos' => [], 'salas' => [], 'rutas' => []];
        $ccf = $salasCompra = [];
        $notas = 0;
        foreach ($query->toBase()->get() as $linea) {
            $actual = substr($linea->fecha, 0, 10) >= $periodo['desde']->toDateString();
            $campo = $actual ? 'actual' : 'anterior';
            $nc = $linea->tipo === '05';
            $factor = ! $nc && (float) $linea->base != 0 ? 1 - (float) $linea->descuento / (float) $linea->base : 1;
            $monto = ((float) $linea->venta_gravada + (float) $linea->venta_exenta + (float) $linea->venta_no_sujeta) * $factor * ($nc ? -1 : 1);
            $cantidad = (float) $linea->cantidad * ($nc ? -1 : 1);
            $totales[$campo] += $monto;
            if ($actual) {
                $totales['cantidad'] += $cantidad;
                if ($nc) {
                    $notas += $monto;
                } else {
                    $ccf[$linea->documento_id] = true;
                    if ($linea->sala_id && $monto > 0) {
                        $salasCompra[$linea->sala_id] = true;
                    }
                }
            }
            foreach (['productos' => ['producto', 'Ajustes sin producto'], 'salas' => ['sala', 'Sin sala'], 'rutas' => ['ruta', 'Sin ruta']] as $grupo => [$prefijo, $sinNombre]) {
                $id = $linea->{$prefijo.'_id'} ?? 0;
                $grupos[$grupo][$id] ??= $this->vacio() + ['id' => $id, 'nombre' => $linea->{$prefijo.'_nombre'} ?? $sinNombre, 'cliente' => $grupo === 'salas' ? $linea->cliente_nombre : null];
                $grupos[$grupo][$id][$campo] += $monto;
                if ($actual) {
                    $grupos[$grupo][$id]['cantidad'] += $cantidad;
                }
            }
        }
        foreach ($grupos as &$filas) {
            $filas = array_map(fn ($fila) => $this->comparar($fila), array_values($filas));
            usort($filas, fn ($a, $b) => $b['actual'] <=> $a['actual'] ?: strcmp($a['nombre'], $b['nombre']));
        }
        unset($filas);
        // Resumen de productos: los «Ajustes sin producto» (id 0) no son un producto.
        $conProducto = array_values(array_filter($grupos['productos'], fn ($fila) => $fila['id'] !== 0));
        $porCantidad = $conProducto;
        usort($porCantidad, fn ($a, $b) => $b['cantidad'] <=> $a['cantidad'] ?: strcmp($a['nombre'], $b['nombre']));
        $porDiferencia = $conProducto;
        usort($porDiferencia, fn ($a, $b) => $b['diferencia'] <=> $a['diferencia'] ?: strcmp($a['nombre'], $b['nombre']));
        $resumen = [
            'monto' => array_slice($conProducto, 0, 5),
            'cantidad' => array_slice($porCantidad, 0, 5),
            'suben' => array_slice(array_values(array_filter($porDiferencia, fn ($fila) => $fila['diferencia'] > 0)), 0, 3),
            'bajan' => array_slice(array_reverse(array_values(array_filter($porDiferencia, fn ($fila) => $fila['diferencia'] < 0))), 0, 3),
        ];

        $alertas = [];
        foreach ($grupos['salas'] as $sala) {
            if ($sala['anterior'] > 0 && $sala['actual'] <= $sala['anterior'] * (1 - self::BAJA_ALERTA) + 0.00000001) {
                $sala['alerta'] = $sala['actual'] == 0 ? 'No compró' : '▼ '.number_format(abs($sala['porcentaje']), 0).' %';
                $alertas[] = $sala;
            }
        }

        return $grupos + ['totales' => $this->comparar($totales), 'periodo' => $periodo, 'filtros' => $filtros, 'alertas' => $alertas, 'resumen' => $resumen, 'contadores' => ['ccf' => count($ccf), 'salas' => count($salasCompra), 'nc' => round($notas, 2)]];
    }

    private function vacio(): array
    {
        return ['actual' => 0.0, 'anterior' => 0.0, 'cantidad' => 0.0];
    }

    private function comparar(array $fila): array
    {
        $fila['actual'] = round($fila['actual'], 2);
        $fila['anterior'] = round($fila['anterior'], 2);
        $fila['diferencia'] = round($fila['actual'] - $fila['anterior'], 2);
        $fila['porcentaje'] = $fila['anterior'] != 0 ? $fila['diferencia'] / $fila['anterior'] * 100 : null;

        return $fila;
    }

    private function periodo(array $filtros): array
    {
        $preset = $filtros['periodo'] ?? 'mes';
        if ($preset === 'rango') {
            $desde = Carbon::parse($filtros['desde'], HoraNegocio::zona())->startOfDay();
            $hasta = Carbon::parse($filtros['hasta'], HoraNegocio::zona())->startOfDay();
            $dias = (int) $desde->diffInDays($hasta) + 1;
            if ($dias > 366) {
                throw ValidationException::withMessages(['hasta' => 'El rango no puede superar 366 días.']);
            }
            $anteriorDesde = $desde->copy()->subDays($dias);
        } else {
            $meses = $preset === 'tres_meses' ? 3 : 1;
            $desde = HoraNegocio::hoy()->startOfMonth();
            if ($preset === 'mes_pasado') {
                $desde->subMonth();
            }
            $hasta = $desde->copy()->endOfMonth()->startOfDay();
            $desde->subMonths($meses - 1);
            $anteriorDesde = $desde->copy()->subMonths($meses);
        }
        $anteriorHasta = $desde->copy()->subDay();
        $etiqueta = $preset === 'rango' ? $desde->translatedFormat('d M Y').' – '.$hasta->translatedFormat('d M Y') : ($preset === 'tres_meses' ? $desde->translatedFormat('F').' – ' : '').$hasta->translatedFormat('F Y');
        $etiquetaAnterior = $preset === 'rango' ? $anteriorDesde->translatedFormat('d M Y').' – '.$anteriorHasta->translatedFormat('d M Y') : ($preset === 'tres_meses' ? $anteriorDesde->translatedFormat('F').' – ' : '').$anteriorHasta->translatedFormat('F Y');

        return ['desde' => $desde, 'hasta' => $hasta, 'anterior_desde' => $anteriorDesde, 'anterior_hasta' => $anteriorHasta, 'etiqueta' => $etiqueta, 'etiqueta_anterior' => $etiquetaAnterior];
    }
}
