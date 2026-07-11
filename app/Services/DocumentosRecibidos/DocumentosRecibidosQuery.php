<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filtros y consulta de "Documentos recibidos". SOLO LECTURA sobre los registros
 * locales (no toca el buzón ni DTE emitidos). Reutilizado por el listado y por la
 * exportación a Excel, para que ambos respeten exactamente los mismos filtros.
 *
 * Por defecto, para que la pantalla no se llene con el histórico: pestaña
 * "pendientes" + rango "mes actual".
 */
class DocumentosRecibidosQuery
{
    /** Pestañas (definen el estado base). */
    public const VISTAS = ['bandeja', 'pendientes', 'enviados', 'ignorados'];

    /** Rangos rápidos sobre la fecha del correo. */
    public const RANGOS = ['mes_actual', 'mes_pasado', 'ultimos_7', 'personalizado', 'todos'];

    public const POR_PAGINA = [25, 50, 100];

    /**
     * Normaliza los filtros crudos a valores seguros con defaults.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function filtros(array $in): array
    {
        $vista = (string) ($in['vista'] ?? 'pendientes');
        if (! in_array($vista, self::VISTAS, true)) {
            $vista = 'pendientes';
        }

        $desde = self::fecha($in['fecha_desde'] ?? null);
        $hasta = self::fecha($in['fecha_hasta'] ?? null);

        $rango = (string) ($in['rango'] ?? '');
        if (! in_array($rango, self::RANGOS, true)) {
            // Sin rango explícito: si hay fechas manuales es personalizado; si no, mes actual.
            $rango = ($desde || $hasta) ? 'personalizado' : 'mes_actual';
        }

        $porPagina = (int) ($in['por_pagina'] ?? 25);
        if (! in_array($porPagina, self::POR_PAGINA, true)) {
            $porPagina = 25;
        }

        return [
            'vista' => $vista,
            'rango' => $rango,
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'emisor' => trim((string) ($in['emisor'] ?? '')),
            'tipo_documento' => (string) ($in['tipo_documento'] ?? ''),
            'numero_control' => trim((string) ($in['numero_control'] ?? '')),
            'codigo_generacion' => trim((string) ($in['codigo_generacion'] ?? '')),
            'monto_min' => self::num($in['monto_min'] ?? null),
            'monto_max' => self::num($in['monto_max'] ?? null),
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Rango de fechas [desde, hasta] (Carbon o null) según el rango rápido elegido.
     *
     * @param  array<string, mixed>  $f
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function rangoFechas(array $f): array
    {
        return match ($f['rango']) {
            'mes_actual' => [now()->startOfMonth(), now()->endOfMonth()],
            'mes_pasado' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'ultimos_7' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'personalizado' => [
                $f['fecha_desde'] ? Carbon::parse($f['fecha_desde'])->startOfDay() : null,
                $f['fecha_hasta'] ? Carbon::parse($f['fecha_hasta'])->endOfDay() : null,
            ],
            default => [null, null], // 'todos'
        };
    }

    /**
     * Query de documentos recibidos según los filtros. Si $aplicarEstado es false,
     * NO filtra por el estado de la pestaña (útil para el resumen por estado).
     *
     * @param  array<string, mixed>  $f
     */
    public static function query(array $f, bool $aplicarEstado = true): Builder
    {
        $q = DocumentoRecibido::query();

        if ($aplicarEstado) {
            match ($f['vista']) {
                'pendientes' => $q->where('estado', 'pendiente'),
                'enviados' => $q->where('estado', 'enviado'),
                'ignorados' => $q->where('estado', 'ignorado'),
                default => null, // bandeja = todos los estados
            };
        }

        [$desde, $hasta] = self::rangoFechas($f);
        if ($desde) {
            $q->where('fecha_correo', '>=', $desde);
        }
        if ($hasta) {
            $q->where('fecha_correo', '<=', $hasta);
        }

        if ($f['emisor'] !== '') {
            $q->where('emisor_nombre', 'like', '%'.$f['emisor'].'%');
        }
        if ($f['tipo_documento'] !== '') {
            $q->where('tipo_documento', $f['tipo_documento']);
        }
        if ($f['numero_control'] !== '') {
            $q->where('numero_control', 'like', '%'.$f['numero_control'].'%');
        }
        if ($f['codigo_generacion'] !== '') {
            $q->where('codigo_generacion', 'like', '%'.$f['codigo_generacion'].'%');
        }
        if ($f['monto_min'] !== null) {
            $q->where('total', '>=', $f['monto_min']);
        }
        if ($f['monto_max'] !== null) {
            $q->where('total', '<=', $f['monto_max']);
        }

        return $q;
    }

    /** Etiqueta del rango para el nombre del Excel (YYYY-MM o desde_a_hasta o todos). */
    public static function etiquetaArchivo(array $f): string
    {
        [$desde, $hasta] = self::rangoFechas($f);

        if (in_array($f['rango'], ['mes_actual', 'mes_pasado'], true) && $desde) {
            return $desde->format('Y-m');
        }
        if ($desde || $hasta) {
            return ($desde?->format('Y-m-d') ?? 'inicio').'_a_'.($hasta?->format('Y-m-d') ?? 'hoy');
        }

        return 'todos';
    }

    private static function fecha(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }
}
