<?php

namespace App\Services\Reportes;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\DteEnvio;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Filtros y consulta del "Reporte contadora". SOLO LECTURA: arma un query sobre
 * los DTE de ESTE sistema (no toca emisión, transmisión ni correlativos).
 *
 * Por defecto excluye pruebas/mock: ambiente Producción (01) + aceptados REALMENTE
 * por Hacienda (sello real + fecha de procesamiento del MH, nunca sellos MOCK), más
 * los emitidos en contingencia que esperan sello, que se informan aparte y no suman.
 */
class ReporteContadoraQuery
{
    /** Tipos de documento admitidos en el filtro (además de "todos"). */
    public const TIPOS = [
        '03' => 'CCF',
        '01' => 'Factura',
        '05' => 'Nota de crédito',
        '11' => 'Factura de exportación (FEX)',
    ];

    /** Rangos rápidos de fecha. `personalizado` respeta fecha_desde/fecha_hasta del formulario. */
    public const RANGOS = [
        'este_mes' => 'Este mes',
        'mes_pasado' => 'Mes pasado',
        'ultimos_7' => 'Últimos 7 días',
        'personalizado' => 'Personalizado',
    ];

    /**
     * Filtro por el estado del envío a contabilidad. Solo un envío con canal
     * `contabilidad` y estado `enviado` cuenta como enviado: `simulado` (mailer no real),
     * `error`, `pendiente` (en cola), canal `cliente` y los históricos (canal NULL)
     * siguen contando como PENDIENTES.
     */
    public const CONTABILIDAD = [
        'todos' => 'Todos',
        'pendientes' => 'Pendientes de enviar a contabilidad',
        'enviados' => 'Enviados a contabilidad',
    ];

    /**
     * Normaliza los filtros crudos del request a valores seguros con defaults. Un rango
     * rápido (distinto de `personalizado`) RESUELVE fecha_desde/fecha_hasta acá, para que
     * la pantalla muestre las fechas efectivas.
     *
     * @param  array<string, mixed>  $input
     * @return array{fecha_desde: ?string, fecha_hasta: ?string, tipo: string, estado: string, ambiente: string, rango: string, contabilidad: string}
     */
    public static function filtros(array $input): array
    {
        $tipo = (string) ($input['tipo_documento'] ?? 'todos');
        if ($tipo !== 'todos' && ! array_key_exists($tipo, self::TIPOS)) {
            $tipo = 'todos';
        }
        $estado = (string) ($input['estado'] ?? 'aceptado');
        if (! in_array($estado, ['aceptado', 'todos'], true)) {
            $estado = 'aceptado';
        }
        $ambiente = (string) ($input['ambiente'] ?? AmbienteHacienda::Produccion->value);
        if (! in_array($ambiente, [AmbienteHacienda::Produccion->value, AmbienteHacienda::Pruebas->value, 'todos'], true)) {
            $ambiente = AmbienteHacienda::Produccion->value;
        }
        $rango = (string) ($input['rango'] ?? 'personalizado');
        if (! array_key_exists($rango, self::RANGOS)) {
            $rango = 'personalizado';
        }
        $contabilidad = (string) ($input['contabilidad'] ?? 'todos');
        if (! array_key_exists($contabilidad, self::CONTABILIDAD)) {
            $contabilidad = 'todos';
        }

        [$desde, $hasta] = self::rangoRapido($rango)
            ?? [self::fecha($input['fecha_desde'] ?? null), self::fecha($input['fecha_hasta'] ?? null)];

        return [
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'tipo' => $tipo,
            'estado' => $estado,
            'ambiente' => $ambiente,
            'rango' => $rango,
            'contabilidad' => $contabilidad,
        ];
    }

    /**
     * Fechas de un rango rápido, o null si es `personalizado` (manda el formulario).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function rangoRapido(string $rango): ?array
    {
        return match ($rango) {
            'este_mes' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'mes_pasado' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'ultimos_7' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            default => null,
        };
    }

    /**
     * Query de DTE según los filtros normalizados. No pagina ni ejecuta.
     *
     * @param  array{fecha_desde: ?string, fecha_hasta: ?string, tipo: string, estado: string, ambiente: string, rango?: string, contabilidad?: string}  $f
     */
    public static function query(array $f): Builder
    {
        $q = Dte::query()->with('cliente:id,nombre,nombre_comercial,num_documento,nrc');

        // Estado del ÚLTIMO envío de correo (para las columnas del reporte), como subconsulta.
        $q->addSelect(['ultimo_envio_estado' => DteEnvio::select('estado')
            ->whereColumn('dte_id', 'dtes.id')->latest('id')->limit(1)]);
        $q->addSelect(['ultimo_envio_fecha' => DteEnvio::select('updated_at')
            ->whereColumn('dte_id', 'dtes.id')->latest('id')->limit(1)]);

        // Envío a CONTABILIDAD (canal 'contabilidad', igualdad estricta: canal NULL y
        // 'cliente' quedan fuera). Estado/error del último intento para el badge, y la
        // fecha del último envío EXITOSO (solo estado 'enviado').
        $q->addSelect(['envio_conta_estado' => DteEnvio::select('estado')
            ->whereColumn('dte_id', 'dtes.id')->where('canal', DteEnvio::CANAL_CONTABILIDAD)->latest('id')->limit(1)]);
        $q->addSelect(['envio_conta_error' => DteEnvio::select('error')
            ->whereColumn('dte_id', 'dtes.id')->where('canal', DteEnvio::CANAL_CONTABILIDAD)->latest('id')->limit(1)]);
        $q->addSelect(['envio_conta_enviado_at' => DteEnvio::select('updated_at')
            ->whereColumn('dte_id', 'dtes.id')->where('canal', DteEnvio::CANAL_CONTABILIDAD)
            ->where('estado', 'enviado')->latest('id')->limit(1)]);

        // Pendientes / enviados a contabilidad: SOLO un envío 'enviado' por el canal
        // 'contabilidad' cuenta como enviado (simulado, error y en cola siguen pendientes).
        if (($f['contabilidad'] ?? 'todos') === 'pendientes') {
            $q->whereDoesntHave('envios', fn ($e) => $e->where('canal', DteEnvio::CANAL_CONTABILIDAD)->where('estado', 'enviado'));
        } elseif (($f['contabilidad'] ?? 'todos') === 'enviados') {
            $q->whereHas('envios', fn ($e) => $e->where('canal', DteEnvio::CANAL_CONTABILIDAD)->where('estado', 'enviado'));
        }

        // Ambiente (default producción 01). "todos" no filtra por ambiente.
        if ($f['ambiente'] !== 'todos') {
            $q->where('ambiente', $f['ambiente']);
        }

        // Estado: "aceptado" (default) = aceptados REALMENTE por el MH y pendientes de sello.
        if ($f['estado'] === 'aceptado') {
            $q->where(fn ($q) => $q->aceptadoRealMh()
                ->orWhere(fn ($q) => self::pendienteDeSello($q)));
        } elseif ($f['estado'] === 'fiscal') {
            // Uso interno del paquete; filtros() no admite este estado desde la pantalla.
            $q->where(fn ($q) => $q->aceptadoRealMh()
                ->orWhere(fn ($q) => self::invalidadoRealMh($q))
                ->orWhere(fn ($q) => self::pendienteDeSello($q)));
        }

        if ($f['tipo'] !== 'todos') {
            $q->where('tipo_dte', $f['tipo']);
        }
        if ($f['fecha_desde']) {
            $q->whereDate('fecha_emision', '>=', $f['fecha_desde']);
        }
        if ($f['fecha_hasta']) {
            $q->whereDate('fecha_emision', '<=', $f['fecha_hasta']);
        }

        return $q->orderBy('fecha_emision')->orderBy('id');
    }

    /**
     * Invalidados REALMENTE ante el MH: el documento fue aceptado con sello real y el
     * evento de invalidación también tiene sello real. Quedan fuera el mock y la
     * anulación interna (Generado → Invalidado), que nunca fue venta fiscal.
     *
     * Vive acá y no como scope de Dte para que el paquete no dependa de cambios en el
     * modelo (mismos criterios que {@see Dte::scopeAceptadoRealMh()}).
     */
    public static function invalidadoRealMh(Builder $q): Builder
    {
        return $q->where('estado', EstadoDte::Invalidado->value)
            ->whereNotNull('sello_recepcion')->where('sello_recepcion', '!=', '')
            ->whereRaw('UPPER(sello_recepcion) NOT LIKE ?', ['MOCK%'])
            ->whereNotNull('fecha_procesamiento_mh')
            ->whereNotNull('sello_invalidacion')->where('sello_invalidacion', '!=', '')
            ->whereRaw('UPPER(sello_invalidacion) NOT LIKE ?', ['MOCK%']);
    }

    /** La misma regla que {@see self::invalidadoRealMh()}, sobre un DTE ya cargado. */
    public static function esInvalidadoRealMh(Dte $dte): bool
    {
        $real = fn (?string $sello) => filled($sello) && ! str_starts_with(strtoupper($sello), 'MOCK');

        return $dte->estado === EstadoDte::Invalidado
            && $real($dte->sello_recepcion)
            && $dte->fecha_procesamiento_mh !== null
            && $real($dte->sello_invalidacion);
    }

    /**
     * No depende del interruptor de contingencia: los documentos sin sello siguen
     * visibles para contabilidad aunque se deshabilite la contingencia.
     */
    public static function pendienteDeSello(Builder $q): Builder
    {
        return $q->where('estado', EstadoDte::Firmado->value)
            ->whereNotNull('contingencia_id')
            ->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''));
    }

    /** La misma regla que {@see self::pendienteDeSello()}, sobre un DTE ya cargado. */
    public static function esPendienteDeSello(Dte $dte): bool
    {
        return $dte->estado === EstadoDte::Firmado
            && $dte->contingencia_id !== null
            && ($dte->sello_recepcion === null || $dte->sello_recepcion === '');
    }

    private static function fecha(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
