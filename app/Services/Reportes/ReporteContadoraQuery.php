<?php

namespace App\Services\Reportes;

use App\Enums\AmbienteHacienda;
use App\Models\Dte;
use App\Models\DteEnvio;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Filtros y consulta del "Reporte contadora". SOLO LECTURA: arma un query sobre
 * los DTE de ESTE sistema (no toca emisión, transmisión ni correlativos).
 *
 * Por defecto excluye pruebas/mock: ambiente Producción (01) + aceptados REALMENTE
 * por Hacienda (sello real + fecha de procesamiento del MH, nunca sellos MOCK).
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

    /**
     * Normaliza los filtros crudos del request a valores seguros con defaults.
     *
     * @param  array<string, mixed>  $input
     * @return array{fecha_desde: ?string, fecha_hasta: ?string, tipo: string, estado: string, ambiente: string}
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

        return [
            'fecha_desde' => self::fecha($input['fecha_desde'] ?? null),
            'fecha_hasta' => self::fecha($input['fecha_hasta'] ?? null),
            'tipo' => $tipo,
            'estado' => $estado,
            'ambiente' => $ambiente,
        ];
    }

    /**
     * Query de DTE según los filtros normalizados. No pagina ni ejecuta.
     *
     * @param  array{fecha_desde: ?string, fecha_hasta: ?string, tipo: string, estado: string, ambiente: string}  $f
     */
    public static function query(array $f): Builder
    {
        $q = Dte::query()->with('cliente:id,nombre,nombre_comercial,num_documento,nrc');

        // Estado del ÚLTIMO envío de correo (para las columnas del reporte), como subconsulta.
        $q->addSelect(['ultimo_envio_estado' => DteEnvio::select('estado')
            ->whereColumn('dte_id', 'dtes.id')->latest('id')->limit(1)]);
        $q->addSelect(['ultimo_envio_fecha' => DteEnvio::select('updated_at')
            ->whereColumn('dte_id', 'dtes.id')->latest('id')->limit(1)]);

        // Ambiente (default producción 01). "todos" no filtra por ambiente.
        if ($f['ambiente'] !== 'todos') {
            $q->where('ambiente', $f['ambiente']);
        }

        // Estado: "aceptado" (default) = aceptados REALMENTE por el MH (excluye mock).
        if ($f['estado'] === 'aceptado') {
            $q->aceptadoRealMh();
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

    private static function fecha(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
