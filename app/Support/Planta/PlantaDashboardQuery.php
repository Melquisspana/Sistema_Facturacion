<?php

namespace App\Support\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Http\Controllers\Planta\PlantaDashboardController;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaExistencia;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaTraslado;
use App\Services\Planta\PlantaTrasladoService;
use App\Services\Planta\ReconciliacionExistenciasService;
use Illuminate\Support\Carbon;

/**
 * Indicadores del panel de inicio de Planta. SOLO LECTURA.
 *
 * TRES REGLAS QUE NO SE NEGOCIAN:
 *
 *  1. NO ESCRIBE y NO RECONCILIA. Aquí no hay `update`, `insert` ni `delete`, no
 *     se inyecta ningún servicio de inventario y NO se llama a
 *     {@see ReconciliacionExistenciasService}. Esa clase agrupa TODO el libro
 *     mayor por las cinco dimensiones del bucket y lee entera la proyección:
 *     dos escaneos completos de las dos tablas que más crecen. Es mantenimiento
 *     excepcional, no la pantalla de inicio.
 *
 *  2. NO SE SUMAN CANTIDADES. Ni una sola cifra de este panel es una suma de
 *     `cantidad`. Todas son CONTEOS: insumos distintos, filas de saldo,
 *     documentos. La razón es concreta: `planta_existencias` guarda libras,
 *     litros y unidades en la misma columna, y sumarlas produce un número que no
 *     significa nada físico. Por eso NO se usa
 *     `ExistenciaQuery::totalesPorEstado()['total']`, que es exactamente esa
 *     suma: sirve en la pantalla de Existencias, donde se puede filtrar por un
 *     insumo y leer su unidad al lado, pero como indicador global mentiría.
 *     El campo `buckets` de esa misma clase sí sería válido; aquí se calcula
 *     junto con el resto en una sola agregación para no repetir el escaneo.
 *
 *  3. LA AUTORIZACIÓN NO VIVE AQUÍ. Esta clase no consulta permisos ni recibe al
 *     usuario: cada método asume que el llamador ya decidió que puede ejecutarse.
 *     Quien decide es {@see PlantaDashboardController}, que NO invoca el método
 *     cuando falta el permiso. Es deliberado: mezclar
 *     `can()` con SQL haría imposible probar las consultas sin montar un usuario,
 *     y esconder la decisión de autorización dentro de una clase de lectura es
 *     justo lo que hace que un día se olvide.
 *
 * DÍAS EN TRÁNSITO. Los umbrales y el cálculo viven aquí y en ningún otro sitio:
 * los usan el panel y el listado de traslados, y tenerlos duplicados entre
 * controlador y vista garantizaría que un día dejen de coincidir. Se miden con
 * `enviado_en` —el instante real de la salida, que escribe siempre
 * {@see PlantaTrasladoService::enviar()}— y no con `fecha`, que es la fecha
 * OPERATIVA del documento y puede haberse capturado otro día.
 */
final class PlantaDashboardQuery
{
    /**
     * Desde estos días en tránsito, la señal deja de ser neutra.
     *
     * El viaje de Casa a Fábrica dura alrededor de UNA HORA y lo normal es
     * recibirlo el mismo día. Por eso el umbral es 1: algo que amanece todavía
     * en tránsito ya no siguió el curso normal, aunque no sea grave todavía.
     */
    public const DIAS_TRANSITO_ADVERTENCIA = 1;

    /**
     * Desde estos días en tránsito, la señal es de peligro.
     *
     * Dos días son dos amaneceres con mercancía que salió de una bodega y no
     * llegó a la otra: no está en ninguna de las dos y nadie la ha echado en
     * falta. Es el fallo de inventario más probable del módulo.
     */
    public const DIAS_TRANSITO_PELIGRO = 2;

    /** Ventana de «reciente»: ajustes confirmados y lotes por vencer. */
    public const DIAS_VENTANA = 30;

    /** Vocabulario de color del módulo, el mismo que devuelven los enums. */
    public const SEVERIDAD_NEUTRA = 'gray';

    public const SEVERIDAD_ADVERTENCIA = 'amber';

    public const SEVERIDAD_PELIGRO = 'red';

    // -----------------------------------------------------------------
    // Traslados
    // -----------------------------------------------------------------

    /**
     * Cuántos traslados están viajando ahora y desde hace cuánto salió el más
     * antiguo. Las dos cifras en UNA agregación: son la misma pregunta.
     *
     * Solo cuenta `enviado`. Un traslado `recibido` ya llegó, y `cancelado` o
     * `reversado` no están viajando: incluirlos afirmaría que hay mercancía en
     * camino que no lo está, que es justo el error que este indicador existe
     * para evitar.
     *
     * @return array{cantidad: int, dias: int|null}
     */
    public function traslados(): array
    {
        $fila = PlantaTraslado::query()
            ->where('estado', EstadoTrasladoPlanta::Enviado->value)
            ->getQuery()
            ->selectRaw('COUNT(*) as cantidad, MIN(enviado_en) as mas_antiguo')
            ->first();

        $cantidad = (int) ($fila->cantidad ?? 0);

        return [
            'cantidad' => $cantidad,
            // Sin traslados en tránsito no hay antigüedad que informar: `null`,
            // no cero. «Cero días» diría que algo salió hoy.
            'dias' => $cantidad === 0 ? null : self::diasDesde($fila->mas_antiguo ?? null),
        ];
    }

    /**
     * Días que lleva viajando ESTE traslado, o null si no está en tránsito.
     *
     * El null es lo que impide que un traslado ya recibido muestre una cifra de
     * tránsito vigente: lo que se conserva en `enviado_en` es historia, no un
     * viaje en curso.
     */
    public static function diasEnTransito(PlantaTraslado $traslado): ?int
    {
        if (! $traslado->estado->estaEnTransito()) {
            return null;
        }

        return self::diasDesde($traslado->enviado_en);
    }

    /**
     * Días enteros y NUNCA negativos entre una salida y hoy.
     *
     * Se compara a nivel de DÍA, no de instante: lo enviado esta mañana lleva 0
     * días, no «0,3». Un `enviado_en` en el futuro —solo posible con el reloj
     * adelantado— devuelve 0 en vez de un negativo sin sentido.
     */
    public static function diasDesde(Carbon|string|null $enviadoEn): ?int
    {
        if ($enviadoEn === null || $enviadoEn === '') {
            return null;
        }

        $salida = Carbon::parse($enviadoEn)->startOfDay();
        $hoy = Carbon::today();

        if ($salida->greaterThanOrEqualTo($hoy)) {
            return 0;
        }

        return (int) $salida->diffInDays($hoy, absolute: true);
    }

    /** Severidad de una antigüedad en tránsito. Regla única de todo el módulo. */
    public static function severidadTransito(?int $dias): string
    {
        return match (true) {
            $dias === null => self::SEVERIDAD_NEUTRA,
            $dias >= self::DIAS_TRANSITO_PELIGRO => self::SEVERIDAD_PELIGRO,
            $dias >= self::DIAS_TRANSITO_ADVERTENCIA => self::SEVERIDAD_ADVERTENCIA,
            default => self::SEVERIDAD_NEUTRA,
        };
    }

    // -----------------------------------------------------------------
    // Existencias
    // -----------------------------------------------------------------

    /**
     * Las tres cifras de inventario del panel, en UNA sola pasada.
     *
     * Se agrupan aquí porque comparten permiso (`planta.existencias.ver`), tabla
     * y filtro (`cantidad > 0`): pedirlas por separado costaría tres escaneos de
     * `planta_existencias` para responder la misma pregunta.
     *
     * QUÉ ES CADA UNA, porque la diferencia importa:
     *   - `insumosDisponibles` cuenta INSUMOS DISTINTOS con saldo disponible. No
     *     filas y no cantidad: responde «de cuántas cosas hay algo utilizable».
     *   - `retenidos` y `rechazados` cuentan FILAS DE SALDO (buckets), es decir
     *     combinaciones insumo+lote+ubicación con saldo en ese estado. No es una
     *     cantidad física y la vista debe decirlo con esas palabras.
     *
     * `conSaldo()` es el mismo scope que usa la pantalla de Existencias, así que
     * el panel y el listado entienden lo mismo por «hay saldo».
     *
     * @return array{insumosDisponibles: int, retenidos: int, rechazados: int}
     */
    public function existencias(): array
    {
        $fila = PlantaExistencia::query()
            ->conSaldo()
            ->getQuery()
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN estado = ? THEN planta_insumo_id END) as insumos_disponibles, '
                .'COUNT(CASE WHEN estado = ? THEN 1 END) as retenidos, '
                .'COUNT(CASE WHEN estado = ? THEN 1 END) as rechazados',
                [
                    EstadoDisponibilidad::Disponible->value,
                    EstadoDisponibilidad::Retenido->value,
                    EstadoDisponibilidad::Rechazado->value,
                ]
            )
            ->first();

        return [
            'insumosDisponibles' => (int) ($fila->insumos_disponibles ?? 0),
            'retenidos' => (int) ($fila->retenidos ?? 0),
            'rechazados' => (int) ($fila->rechazados ?? 0),
        ];
    }

    // -----------------------------------------------------------------
    // Documentos
    // -----------------------------------------------------------------

    /**
     * Recepciones a medio capturar. Un borrador NO ha movido inventario: es
     * trabajo pendiente de confirmar, no mercancía pendiente de llegar.
     */
    public function recepcionesEnBorrador(): int
    {
        return PlantaRecepcion::query()
            ->where('estado', EstadoRecepcionPlanta::Borrador->value)
            ->count();
    }

    /**
     * Ajustes CONFIRMADOS en la ventana. Confirmado es el único estado que movió
     * inventario; un borrador todavía no alteró nada y contarlo exageraría
     * cuánta corrección manual está necesitando el almacén.
     *
     * Se mide contra `fecha` —la fecha operativa del ajuste— y no contra
     * `confirmado_en`: lo que interesa es a qué día se imputó la corrección.
     */
    public function ajustesConfirmadosRecientes(): int
    {
        return PlantaAjuste::query()
            ->where('estado', EstadoAjustePlanta::Confirmado->value)
            ->whereDate('fecha', '>=', self::inicioDeVentana()->toDateString())
            ->count();
    }

    // -----------------------------------------------------------------
    // Lotes
    // -----------------------------------------------------------------

    /**
     * Vencidos y por vencer, en UNA agregación: son la misma tarjeta.
     *
     * TRES FILTROS, y ninguno es decorativo:
     *   - `reales()`: el lote genérico `GEN-<insumo>` es un detalle interno del
     *     motor —existe para que la clave del bucket no tenga nulos— y nunca
     *     lleva fecha de vencimiento;
     *   - `activo`: un lote retirado ya está fuera de la operación;
     *   - con saldo (`EXISTS`): un lote vencido y AGOTADO no es accionable. Ya
     *     se consumió; contarlo llenaría el indicador de ruido histórico que
     *     nadie puede resolver.
     *
     * El `EXISTS` es barato porque el conjunto candidato —lotes activos con
     * fecha de vencimiento— ya viene acotado por el índice
     * `planta_lote_vencimiento_idx`.
     *
     * @return array{vencidos: int, porVencer: int}
     */
    public function lotesPorVencimiento(): array
    {
        $hoy = Carbon::today()->toDateString();
        $limite = Carbon::today()->addDays(self::DIAS_VENTANA)->toDateString();

        $fila = PlantaLote::query()
            ->reales()
            ->where('activo', true)
            ->whereNotNull('fecha_vencimiento')
            ->whereHas('existencias', fn ($q) => $q->where('cantidad', '>', 0))
            ->selectRaw(
                'COUNT(CASE WHEN fecha_vencimiento < ? THEN 1 END) as vencidos, '
                .'COUNT(CASE WHEN fecha_vencimiento >= ? AND fecha_vencimiento <= ? THEN 1 END) as por_vencer',
                [$hoy, $hoy, $limite]
            )
            ->first();

        return [
            'vencidos' => (int) ($fila->vencidos ?? 0),
            'porVencer' => (int) ($fila->por_vencer ?? 0),
        ];
    }

    // -----------------------------------------------------------------
    // Ventana
    // -----------------------------------------------------------------

    /** Primer día de la ventana de «reciente». También alimenta el enlace. */
    public static function inicioDeVentana(): Carbon
    {
        return Carbon::today()->subDays(self::DIAS_VENTANA);
    }
}
