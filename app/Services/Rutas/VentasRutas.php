<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\Ruta;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'vista' => ['sometimes', Rule::in(['productos', 'salas', 'rutas', 'devoluciones'])],
            'top' => ['sometimes', Rule::in(['monto', 'cantidad'])],
        ])->validate();
        $periodo = $this->periodo($filtros);

        $anioDesde = $periodo['desde']->copy()->subYearNoOverflow();
        $anioHasta = $periodo['hasta']->copy()->subYearNoOverflow();
        $tendenciaDesde = $periodo['hasta']->copy()->startOfMonth()->subMonths(11);
        $cargaDesde = min($periodo['anterior_desde'], $anioDesde, $tendenciaDesde);
        $lineas = $this->lineas($cargaDesde, $periodo['hasta']->copy()->endOfMonth(), $filtros);
        $totales = $this->vacio();
        $grupos = ['productos' => [], 'salas' => [], 'rutas' => []];
        $ccf = $salasCompra = [];
        $notas = 0;
        foreach ($lineas as $linea) {
            if ($linea->fecha < $periodo['anterior_desde']->toDateString() || $linea->fecha > $periodo['hasta']->toDateString()) {
                continue;
            }
            $actual = substr($linea->fecha, 0, 10) >= $periodo['desde']->toDateString();
            $campo = $actual ? 'actual' : 'anterior';
            $nc = $linea->tipo === '05';
            $monto = $linea->monto;
            $cantidad = $linea->cantidad;
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

        $anio = $lineas->filter(fn ($l) => $l->fecha >= $anioDesde->toDateString() && $l->fecha <= $anioHasta->toDateString());
        $totales = $this->comparar($totales);
        $totales['anio_anterior'] = round($anio->sum('monto'), 2);
        $totales['porcentaje_anio'] = $totales['anio_anterior'] != 0 ? ($totales['actual'] - $totales['anio_anterior']) / $totales['anio_anterior'] * 100 : null;
        foreach (['productos' => 'producto_id', 'salas' => 'sala_id', 'rutas' => 'ruta_id'] as $grupo => $campo) {
            $porId = $anio->groupBy(fn ($l) => $l->$campo ?? 0)->map(fn ($ls) => round($ls->sum('monto'), 2));
            foreach ($grupos[$grupo] as &$fila) {
                $fila['anio_anterior'] = $porId[$fila['id']] ?? 0.0;
            }
            unset($fila);
        }
        $tendencia = [];
        for ($i = 0; $i < 12; $i++) {
            $mes = $tendenciaDesde->copy()->addMonths($i);
            $tendencia[] = ['mes' => $mes->format('Y-m'), 'etiqueta' => $mes->translatedFormat('M Y'), 'monto' => round($lineas->filter(fn ($l) => substr($l->fecha, 0, 7) === $mes->format('Y-m'))->sum('monto'), 2)];
        }
        $actuales = $lineas->filter(fn ($l) => $l->fecha >= $periodo['desde']->toDateString() && $l->fecha <= $periodo['hasta']->toDateString());
        $devoluciones = $this->devoluciones($actuales);
        $ficha = isset($filtros['sala']) ? $this->fichaSala((int) $filtros['sala']) : null;

        return $grupos + ['totales' => $totales, 'tendencia' => $tendencia, 'devoluciones' => $devoluciones, 'ficha_sala' => $ficha, 'periodo' => $periodo, 'filtros' => $filtros, 'alertas' => $alertas, 'resumen' => $resumen, 'contadores' => ['ccf' => count($ccf), 'salas' => count($salasCompra), 'nc' => round($notas, 2)]];
    }

    private function devoluciones(Collection $lineas): array
    {
        $grupos = ['productos' => [], 'salas' => []];
        $vendido = $devuelto = 0.0;
        foreach ($lineas as $l) {
            if ($l->tipo === '05' && $l->tipo_nota_credito === 'pronto_pago') {
                continue;
            }
            $campo = $l->tipo === '03' ? 'vendido' : 'devuelto';
            $monto = $l->tipo === '03' ? $l->monto : -$l->monto;
            if ($campo === 'vendido') {
                $vendido += $monto;
            } else {
                $devuelto += $monto;
            }
            foreach (['productos' => 'producto', 'salas' => 'sala'] as $grupo => $prefijo) {
                $id = $l->{$prefijo.'_id'} ?? 0;
                $grupos[$grupo][$id] ??= ['id' => $id, 'nombre' => $l->{$prefijo.'_nombre'} ?? ($grupo === 'productos' ? 'Ajustes sin producto' : 'Sin sala'), 'vendido' => 0.0, 'devuelto' => 0.0];
                $grupos[$grupo][$id][$campo] += $monto;
            }
        }
        foreach ($grupos as &$filas) {
            $filas = array_values(array_filter($filas, fn ($f) => $f['devuelto'] > 0));
            foreach ($filas as &$fila) {
                $fila['porcentaje'] = $fila['vendido'] > 0 ? $fila['devuelto'] / $fila['vendido'] * 100 : null;
                $fila['vendido'] = round($fila['vendido'], 2);
                $fila['devuelto'] = round($fila['devuelto'], 2);
            }
            unset($fila);
            usort($filas, fn ($a, $b) => ($b['porcentaje'] ?? -1) <=> ($a['porcentaje'] ?? -1) ?: $b['devuelto'] <=> $a['devuelto'] ?: strcmp($a['nombre'], $b['nombre']));
        }
        unset($filas);

        return $grupos + ['total' => round($devuelto, 2), 'vendido' => round($vendido, 2), 'porcentaje' => $vendido > 0 ? $devuelto / $vendido * 100 : null];
    }

    private function fichaSala(int $id): array
    {
        $hoy = HoraNegocio::hoy();
        $desde = $hoy->copy()->subYearNoOverflow();
        $lineas = $this->lineas($desde, $hoy, ['sala' => $id]);
        // Último CCF real, incluso si no tiene líneas o queda fuera de los 12 meses.
        $documentos = Dte::query()->produccion()->aceptadoRealMh()->noArchivados()->where('tipo_dte', '03')
            ->where('cliente_sucursal_id', $id)->whereDate('fecha_emision', '<=', $hoy->toDateString())
            ->orderByDesc('fecha_emision')->pluck('fecha_emision')->map(fn ($fecha) => Carbon::parse($fecha)->toDateString());
        $ultima = $documentos->first();
        $recientes = $documentos->filter(fn ($fecha) => $fecha >= $desde->toDateString());
        $fechas = $recientes->unique()->sort()->values();
        $frecuencia = $fechas->count() >= 2 ? (float) Carbon::parse($fechas->first())->diffInDays(Carbon::parse($fechas->last())) / ($fechas->count() - 1) : null;
        $numero = $recientes->count();
        $sala = ClienteSucursal::withTrashed()->findOrFail($id);
        $oportunidades = [];
        if ($sala->ruta_id) {
            $salas = ClienteSucursal::where('ruta_id', $sala->ruta_id)->where('activo', true)->pluck('id');
            $recientes = $this->lineas($hoy->copy()->subDays(90), $hoy, ['ruta' => $sala->ruta_id])->where('tipo', '03')->filter(fn ($l) => $l->monto > 0);
            $activas = $recientes->whereIn('sala_id', $salas)->pluck('sala_id')->unique()->count();
            $propios = $recientes->where('sala_id', $id)->pluck('producto_id')->all();
            foreach ($recientes->whereIn('sala_id', $salas)->filter(fn ($l) => $l->producto_id && ! in_array($l->producto_id, $propios))->groupBy('producto_id') as $productoId => $filas) {
                $cuantas = $filas->pluck('sala_id')->unique()->count();
                if ($cuantas >= 2 && $cuantas >= $activas * 0.3) {
                    $oportunidades[] = ['id' => $productoId, 'nombre' => $filas->first()->producto_nombre, 'salas' => $cuantas, 'total' => $activas];
                }
            }
            usort($oportunidades, fn ($a, $b) => $b['salas'] <=> $a['salas'] ?: strcmp($a['nombre'], $b['nombre']));
        }

        return ['ultima_compra' => $ultima ? substr($ultima, 0, 10) : null, 'dias' => $ultima ? (int) Carbon::parse($ultima, HoraNegocio::zona())->startOfDay()->diffInDays($hoy) : null, 'frecuencia' => $frecuencia, 'promedio' => $numero ? round($lineas->sum('monto') / $numero, 2) : null, 'oportunidades' => array_slice($oportunidades, 0, 5)];
    }

    /** Filas con fecha y dimensiones del CCF de referencia, sin redondear antes de sumar. */
    private function lineas(Carbon $desde, Carbon $hasta, array $filtros): Collection
    {
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
            ->whereBetween('referencia.fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('dtes.tipo_nota_credito, dtes.id as documento_id, dtes.tipo_dte as tipo, dtes.subtotal as base, dtes.total_descuento as descuento, linea.cantidad, linea.venta_gravada, linea.venta_exenta, linea.venta_no_sujeta, referencia.fecha_emision as fecha, sala.id as sala_id, sala.nombre as sala_nombre, cliente.nombre as cliente_nombre, ruta.id as ruta_id, ruta.nombre as ruta_nombre, producto.id as producto_id, producto.nombre as producto_nombre');

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

        return $query->toBase()->get()->map(function ($linea) {
            $nc = $linea->tipo === '05';
            $factor = ! $nc && (float) $linea->base != 0 ? 1 - (float) $linea->descuento / (float) $linea->base : 1;
            $linea->monto = ((float) $linea->venta_gravada + (float) $linea->venta_exenta + (float) $linea->venta_no_sujeta) * $factor * ($nc ? -1 : 1);
            $linea->cantidad = (float) $linea->cantidad * ($nc ? -1 : 1);
            $linea->fecha = substr($linea->fecha, 0, 10);
            $linea->cliente = $linea->cliente_nombre;

            return $linea;
        });
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
