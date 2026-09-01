<?php

namespace App\Services\Contabilidad;

use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Illuminate\Support\Carbon;

/**
 * ¿Se puede confiar en el paquete de un período?
 *
 * POR QUÉ EXISTE. El paquete mensual contaba lo que había en la base y lo entregaba.
 * No tenía forma de saber que faltaban quince días de correos, así que un período
 * incompleto se veía exactamente igual que uno completo: mismo ZIP, mismo resumen,
 * misma confianza. Ese es el fallo que hay que cerrar, no el de los correos.
 *
 * Reúne tres cosas que hasta ahora vivían separadas y ninguna llegaba a la pantalla:
 *   - el progreso REAL de la sincronización, día por día;
 *   - la bitácora de la última corrida (cuándo funcionó, qué falló);
 *   - los documentos que no pueden entrar en ningún período porque no tienen fecha
 *     fiscal legible.
 *
 * SOLO LECTURA: no sincroniza, no toca el buzón, no escribe nada.
 */
class CoberturaPaquete
{
    public function __construct(
        private readonly ProgresoSincronizacionCompras $progreso,
        private readonly BitacoraSincronizacionCompras $bitacora,
        private readonly ConfiguracionDocumentosRecibidos $configuracion,
    ) {}

    /**
     * @return array{
     *   desde: string, hasta: string, carpeta: string,
     *   dias_totales: int, dias_completos: int, dias_pendientes: array<int, array{dia: string, estado: string, error: ?string}>,
     *   dias_con_error: int, cubierto: bool, sin_datos: bool,
     *   correos: int, nuevos: int, duplicados: int, descartados: int, rechazados: int,
     *   ultima_sincronizacion: ?string, ultimo_exito: ?string, ultimo_error: ?string,
     *   compras_validas: int, compras_ignoradas: int, compras_sin_fecha_fiscal: int,
     *   bloquea_envio: bool, motivo: ?string
     * }
     */
    public function para(string $desde, string $hasta): array
    {
        $d = Carbon::parse($desde)->startOfDay();
        $h = Carbon::parse($hasta)->startOfDay();
        $carpeta = $this->configuracion->carpeta();

        // HORIZONTE EXIGIBLE: nunca se pide cobertura de días que todavía no ocurrieron.
        // Para el mes en curso el período llega al 30, pero solo se puede haber revisado
        // hasta hoy; exigir el resto pondría en ámbar todos los meses corrientes para
        // siempre, y un aviso que está siempre encendido deja de significar algo.
        $hoy = Carbon::today()->startOfDay();
        $tope = $h->min($hoy);

        // Período enteramente futuro: no hay ningún día que revisar todavía, así que no
        // falta nada. Se informa como cubierto y sin días, no como incompleto.
        if ($d->gt($tope)) {
            return $this->periodoSinDiasExigibles($d, $h, $carpeta, $desde, $hasta);
        }

        $c = $this->progreso->cobertura($d, $tope, $carpeta);
        // Lo que se muestra es el período PEDIDO; lo que se exige es hasta el tope.
        $c['hasta'] = $h->toDateString();

        // Documentos SIN fecha fiscal: no entran en ningún período (no se sabe a cuál
        // pertenecen), así que se cuentan aparte en vez de desaparecer del paquete sin
        // que nadie se entere. Se miran TODOS, no solo los del rango: por definición no
        // se pueden asignar a un rango.
        $sinFechaFiscal = DocumentoRecibido::query()->sinFechaFiscal()->paraContabilidad()->count();

        $validas = DocumentoRecibido::query()->paraContabilidad()->periodoFiscal($desde, $hasta)->count();
        $ignoradas = DocumentoRecibido::query()->where('estado', 'ignorado')
            ->periodoFiscal($desde, $hasta)->count();

        $ultimoError = $this->bitacora->ultimoError();
        $cubierto = $c['completo'] && ! $c['sin_datos'];

        return [
            'desde' => $c['desde'],
            'hasta' => $c['hasta'],
            'hasta_exigible' => $tope->toDateString(),
            'periodo_en_curso' => $tope->lt($h),
            'carpeta' => $carpeta,
            'dias_totales' => $c['dias_totales'],
            'dias_completos' => $c['dias_completos'],
            'dias_pendientes' => $c['dias_sin_cubrir'],
            'dias_con_error' => $c['dias_con_error'],
            'cubierto' => $cubierto,
            'sin_datos' => $c['sin_datos'],
            'correos' => $c['correos'],
            'nuevos' => $c['nuevos'],
            'duplicados' => $c['duplicados'],
            'descartados' => $c['descartados'],
            'rechazados' => $c['rechazados'],
            'ultima_sincronizacion' => $c['ultima_sincronizacion'],
            'ultimo_exito' => $this->bitacora->ultimoExito()?->toDateTimeString(),
            'ultimo_error' => $ultimoError,
            'compras_validas' => $validas,
            'compras_ignoradas' => $ignoradas,
            'compras_sin_fecha_fiscal' => $sinFechaFiscal,
            'bloquea_envio' => ! $cubierto || $ultimoError !== null,
            'motivo' => $this->motivo($cubierto, $c + ['hasta_exigible' => $tope->toDateString(), 'periodo_en_curso' => $tope->lt($h)], $ultimoError),
        ];
    }

    /**
     * Período que todavía no empezó: no hay ningún día que se pudiera haber revisado.
     *
     * No falta nada, así que no se bloquea ni se avisa. El paquete estará vacío, que es
     * lo correcto para un mes que no ocurrió.
     *
     * @return array<string, mixed>
     */
    private function periodoSinDiasExigibles(Carbon $d, Carbon $h, string $carpeta, string $desde, string $hasta): array
    {
        return [
            'desde' => $d->toDateString(),
            'hasta' => $h->toDateString(),
            'hasta_exigible' => null,
            'periodo_en_curso' => true,
            'carpeta' => $carpeta,
            'dias_totales' => 0,
            'dias_completos' => 0,
            'dias_pendientes' => [],
            'dias_con_error' => 0,
            'cubierto' => true,
            'sin_datos' => false,
            'correos' => 0,
            'nuevos' => 0,
            'duplicados' => 0,
            'descartados' => 0,
            'rechazados' => 0,
            'ultima_sincronizacion' => null,
            'ultimo_exito' => $this->bitacora->ultimoExito()?->toDateTimeString(),
            'ultimo_error' => $this->bitacora->ultimoError(),
            'compras_validas' => DocumentoRecibido::query()->paraContabilidad()->periodoFiscal($desde, $hasta)->count(),
            'compras_ignoradas' => DocumentoRecibido::query()->where('estado', 'ignorado')->periodoFiscal($desde, $hasta)->count(),
            'compras_sin_fecha_fiscal' => DocumentoRecibido::query()->sinFechaFiscal()->paraContabilidad()->count(),
            'bloquea_envio' => false,
            'motivo' => 'El período todavía no empezó: no hay días que revisar.',
        ];
    }

    /**
     * Frase que explica por qué el período no es de fiar, en el orden en que le sirve a
     * quien la lee: primero lo que impide actuar, después lo que hay que revisar.
     *
     * @param  array<string, mixed>  $c
     */
    private function motivo(bool $cubierto, array $c, ?string $ultimoError): ?string
    {
        if ($c['sin_datos']) {
            return 'No hay registro de sincronización para este período: es anterior al control de cobertura, '
                .'así que no se puede afirmar que esté completo. Para asegurarlo, recuperá el período desde Compras.';
        }

        if (! $cubierto) {
            $pendientes = collect($c['dias_sin_cubrir'])->pluck('dia');
            $muestra = $pendientes->take(8)->implode(', ');
            $resto = $pendientes->count() > 8 ? ' y '.($pendientes->count() - 8).' día(s) más' : '';

            return 'Faltan '.$pendientes->count().' día(s) por revisar en este período: '.$muestra.$resto
                .'. Recuperá el período desde Compras antes de enviarlo.';
        }

        if ($ultimoError !== null) {
            return 'La última sincronización terminó con error: '.$ultimoError;
        }

        // Un mes en curso está cubierto "hasta hoy", no "entero". Decirlo evita que
        // alguien lo cierre creyendo que ya está completo el mes.
        if ($c['periodo_en_curso'] ?? false) {
            return 'Todos los correos revisados hasta hoy ('.$c['hasta_exigible'].'). '
                .'El período todavía no terminó: van a seguir entrando compras.';
        }

        return null;
    }
}
