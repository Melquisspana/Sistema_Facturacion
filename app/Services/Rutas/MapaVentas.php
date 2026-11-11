<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * El mapa de ventas por zona del tablero de Rutas (paso 1, aprobado por Melqui el
 * 09/11/2026): por departamento y por distrito, lo vendido en el mes, cuántas salas hay,
 * cuántas compraron, a cuántas les toca visita o están atrasadas, la última entrega y qué
 * rutas pasan. Nada se inventa acá:
 *
 *  · Ventas: {@see VentasRutas::porSala()}, las reglas de la pantalla de Ventas. Solo
 *    nacional, sin IVA, sin invalidados, y la NC en el mes del documento que corrige.
 *  · Dónde falta: el estado de cada sala por su RITMO ({@see RitmoPedidos}, el del tablero)
 *    al último día del tramo: «le toca» si llegó su fecha y «atrasada» si pasó con margen
 *    ({@see Ritmo::estado()}). Se cuenta desde su última visita: el último pedido o CCF del
 *    ritmo, el último CCF o la última entrega, la más nueva. Sin 3 visitas no hay ritmo: la
 *    sala se cuenta aparte, sin darla por atrasada.
 *  · Última entrega: {@see EntregasCcf::ultimaVisitaPorSala()}, la de las entregas de Rutas,
 *    hasta el último día del tramo. Así, en un mes pasado todo se cuenta al cierre del mes.
 *  · Rutas que pasan: la cobertura de las rutas activas ({@see RutaCobertura}) y la ruta de
 *    las salas del lugar (`cliente_sucursales.ruta_id`). Como en {@see PropuestaRutas::para()},
 *    la cobertura de un distrito le gana a la de su departamento.
 *
 * El mes en curso va del 1 a hoy y se compara con el mismo tramo del mes anterior; un mes
 * pasado se compara con el mes anterior completo. Una sala cuenta en el mes si ya existía
 * (o si compró en él). Las salas sin distrito cargado no tienen lugar en el mapa: se cuentan
 * aparte. Con una ruta elegida, cada zona dice también cuántas salas tiene en total
 * (`todas`), para no tomar por vacía una zona que atiende otra ruta. Todo sale de unas pocas
 * consultas agregadas, sin importar cuántas salas, zonas o rutas haya.
 */
class MapaVentas
{
    /** Meses que se pueden elegir: el actual y los doce anteriores. */
    public const MESES = 13;

    /** Salas que la ficha de una zona lista: las atrasadas y después a las que les toca. */
    private const POR_VISITAR = 5;

    public function __construct(
        private readonly VentasRutas $ventas,
        private readonly EntregasCcf $entregas,
        private readonly RitmoPedidos $ritmo,
    ) {}

    /**
     * Los meses del filtro, el actual primero: «Noviembre 2026 (va del 1 al 9)».
     *
     * @return array<string, string> 'Y-m' => etiqueta
     */
    public static function meses(): array
    {
        $hoy = HoraNegocio::hoy();
        $meses = [];
        for ($i = 0; $i < self::MESES; $i++) {
            $mes = $hoy->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $etiqueta = Str::ucfirst($mes->locale('es')->translatedFormat('F Y'));
            if ($i === 0) {
                $etiqueta .= $hoy->day === 1 ? ' (solo el día 1)' : ' (va del 1 al '.$hoy->day.')';
            }
            $meses[$mes->format('Y-m')] = $etiqueta;
        }

        return $meses;
    }

    /**
     * @param  string  $mes  'Y-m', ya validado
     * @return array{
     *     mes: string, hoy: string, enCurso: bool,
     *     periodo: array{desde: string, hasta: string, anteriorDesde: string, anteriorHasta: string},
     *     totales: array{ventas: float, anterior: float, salas: int, compraron: int, faltan: int, atrasadas: int, distritosSinSalas: int},
     *     sinUbicacion: array{salas: int, ventas: float, sinSala: float},
     *     rutas: array<int, array{id: int, nombre: string}>,
     *     departamentos: array<string, array>,
     *     distritos: array<string, array>,
     * }
     */
    public function calcular(string $mes, ?int $rutaId = null): array
    {
        $hoy = HoraNegocio::hoy();
        $desde = Carbon::createFromFormat('!Y-m', $mes, HoraNegocio::zona())->startOfMonth();
        $enCurso = $desde->format('Y-m') === $hoy->format('Y-m');
        $hasta = $enCurso ? $hoy->copy() : $desde->copy()->endOfMonth()->startOfDay();
        $anteriorDesde = $desde->copy()->subMonthNoOverflow();
        $anteriorHasta = $enCurso
            ? $anteriorDesde->copy()->day(min($hoy->day, $anteriorDesde->daysInMonth))
            : $anteriorDesde->copy()->endOfMonth()->startOfDay();

        $rutas = Ruta::activas()->orderBy('nombre')->get(['id', 'nombre']);
        $activas = array_flip($rutas->pluck('id')->all());

        // Lo vendido a cada sala (una consulta) y las salas: las activas y las que vendieron,
        // aunque estén desactivadas o borradas, para que el total sea el de Ventas. Lo vendido
        // sin sala (consumidor final) suma al total y no tiene lugar en el mapa. Las salas de
        // las otras rutas también se leen: dicen qué zonas tienen salas y por dónde pasan.
        $ventas = $this->ventas->porSala($desde, $hasta, $anteriorDesde, $anteriorHasta, $rutaId);
        $sinSala = $ventas[0] ?? ['actual' => 0.0, 'anterior' => 0.0];
        unset($ventas[0]);
        $salas = ClienteSucursal::withTrashed()
            ->leftJoin('distritos', 'distritos.id', '=', 'cliente_sucursales.distrito_id')
            ->leftJoin('departamentos', 'departamentos.id', '=', 'distritos.departamento_id')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('cliente_sucursales.activo', true)->whereNull('cliente_sucursales.deleted_at'))
                ->orWhereIn('cliente_sucursales.id', array_keys($ventas)))
            ->orderBy('cliente_sucursales.nombre')
            ->toBase()
            ->get(['cliente_sucursales.id', 'cliente_sucursales.nombre', 'cliente_sucursales.activo', 'cliente_sucursales.deleted_at', 'cliente_sucursales.created_at', 'cliente_sucursales.ruta_id', 'distritos.codigo as distrito', 'departamentos.codigo as departamento']);
        $ids = $salas->pluck('id')->all();

        // El último CCF y la última entrega de cada sala, los dos hasta el fin del tramo.
        $ultimoCcf = $this->entregas->baseCcf()
            ->whereIn('cliente_sucursal_id', $ids)
            ->whereDate('fecha_emision', '<=', $hasta->toDateString())
            ->groupBy('cliente_sucursal_id')
            ->toBase()
            ->selectRaw('cliente_sucursal_id, MAX(fecha_emision) as ultima')
            ->pluck('ultima', 'cliente_sucursal_id');
        $entregas = $this->entregas->ultimaVisitaPorSala($ids, $hasta);
        $ritmos = $this->ritmo->porSala($ids, CarbonImmutable::parse($hasta->toDateString()));

        $departamentos = Departamento::orderBy('codigo')->get(['id', 'codigo', 'nombre']);
        $distritos = Distrito::query()
            ->join('departamentos', 'departamentos.id', '=', 'distritos.departamento_id')
            ->toBase()
            ->get(['distritos.id', 'distritos.codigo', 'distritos.nombre', 'departamentos.codigo as departamento']);

        $zonasDep = [];
        foreach ($departamentos as $d) {
            $zonasDep[$d->codigo] = self::zona($d->nombre, $d->codigo);
        }
        $zonasDis = [];
        $claveDistrito = [];
        foreach ($distritos as $d) {
            $claveDistrito[$d->id] = self::clave($d->departamento, $d->codigo);
            if ($claveDistrito[$d->id] !== null) {
                $zonasDis[$claveDistrito[$d->id]] = self::zona($d->nombre, $d->departamento);
            }
        }

        // Por dónde pasa cada ruta: su cobertura y la ruta de las salas del lugar, de TODAS
        // las salas (el modo «Rutas» del mapa no depende del filtro).
        $pasan = function (?string $dis, ?string $dep, int $ruta) use (&$zonasDis, &$zonasDep, $activas) {
            if (! isset($activas[$ruta])) {
                return;
            }
            if ($dis !== null && isset($zonasDis[$dis])) {
                $zonasDis[$dis]['rutas'][$ruta] = true;
            }
            if ($dep !== null && isset($zonasDep[$dep])) {
                $zonasDep[$dep]['rutas'][$ruta] = true;
            }
        };
        $depDeId = $departamentos->pluck('codigo', 'id');
        $coberturas = RutaCobertura::query()->get(['ruta_id', 'departamento_id', 'distrito_id'])
            ->filter(fn (RutaCobertura $c) => isset($activas[$c->ruta_id]));
        // Un distrito con cobertura propia es de esa ruta, no de la que cubre su departamento.
        $propios = [];
        foreach ($coberturas as $c) {
            if ($c->distrito_id !== null && isset($claveDistrito[$c->distrito_id])) {
                $propios[$claveDistrito[$c->distrito_id]] = true;
            }
        }
        foreach ($coberturas as $c) {
            if ($c->departamento_id !== null) {
                $dep = $depDeId[$c->departamento_id] ?? null;
                $pasan(null, $dep, $c->ruta_id);
                foreach ($zonasDis as $clave => $zona) {
                    if ($zona['dep'] === $dep && ! isset($propios[$clave])) {
                        $pasan($clave, null, $c->ruta_id);
                    }
                }
            } elseif (isset($claveDistrito[$c->distrito_id])) {
                $clave = $claveDistrito[$c->distrito_id];
                $pasan($clave, $zonasDis[$clave]['dep'], $c->ruta_id);
            }
        }

        // Cada sala suma a su distrito, a su departamento y a los totales. La plata cuenta
        // siempre; como sala solo cuenta la activa que ya existía en el tramo o la que compró
        // en él: una que se desactivó no es una sala «sin compra», y una dada de alta después
        // no estaba. Cuándo se desactivó una sala no queda guardado: para eso vale cómo está hoy.
        $totales = ['ventas' => $sinSala['actual'], 'anterior' => $sinSala['anterior'], 'salas' => 0, 'compraron' => 0, 'faltan' => 0, 'atrasadas' => 0, 'distritosSinSalas' => 0];
        $sinUbicacion = ['salas' => 0, 'ventas' => 0.0];
        foreach ($salas as $sala) {
            $venta = $ventas[$sala->id] ?? ['actual' => 0.0, 'anterior' => 0.0, 'compro' => false];
            // La última visita: el último pedido o CCF del ritmo, el último CCF o la última entrega.
            $ritmo = $ritmos[$sala->id];
            $ultima = collect([
                $ritmo->ultima()?->toDateString(),
                isset($ultimoCcf[$sala->id]) ? substr((string) $ultimoCcf[$sala->id], 0, 10) : null,
                isset($entregas[$sala->id]) ? $entregas[$sala->id]->toDateString() : null,
            ])->filter()->max();
            $dias = $ultima === null ? null : max(0, (int) Carbon::parse($ultima, HoraNegocio::zona())->diffInDays($hasta));
            $estado = $ritmo->dias === null ? Ritmo::SIN_RITMO : Ritmo::estado($ritmo->dias, (int) $dias);
            $abierta = (bool) $sala->activo && $sala->deleted_at === null;
            $existia = $sala->created_at === null || substr((string) $sala->created_at, 0, 10) <= $hasta->toDateString();
            $cuenta = ($abierta && $existia) || $venta['compro'];
            $dis = self::clave($sala->departamento, $sala->distrito);
            $ubicada = $dis !== null && isset($zonasDis[$dis]);

            // De todas las rutas: la ruta de la sala pasa por su zona y la zona tiene salas.
            if ($ubicada && $abierta && $sala->ruta_id !== null) {
                $pasan($dis, $sala->departamento, (int) $sala->ruta_id);
            }
            if ($ubicada && $cuenta) {
                $zonasDis[$dis]['todas']++;
                $zonasDep[$sala->departamento]['todas']++;
            }
            if ($rutaId !== null && (int) $sala->ruta_id !== $rutaId) {
                continue;
            }

            $fila = [
                'nombre' => $sala->nombre,
                'cuenta' => $cuenta,
                'dias' => $dias,
                'actual' => $venta['actual'],
                'anterior' => $venta['anterior'],
                'compro' => $venta['compro'],
                'estado' => $estado,
                // Días para la fecha esperada; negativo = días de atraso.
                'faltan' => $ritmo->dias === null ? null : $ritmo->dias - (int) $dias,
                'entrega' => isset($entregas[$sala->id]) ? $entregas[$sala->id]->toDateString() : null,
            ];

            $totales['salas'] += (int) $cuenta;
            $totales['ventas'] += $fila['actual'];
            $totales['anterior'] += $fila['anterior'];
            $totales['compraron'] += (int) $fila['compro'];
            $totales['faltan'] += (int) ($cuenta && in_array($estado, [Ritmo::PRONTO, Ritmo::ATRASADA], true));
            $totales['atrasadas'] += (int) ($cuenta && $estado === Ritmo::ATRASADA);

            if (! $ubicada) {
                $sinUbicacion['salas'] += (int) $cuenta;
                $sinUbicacion['ventas'] += $fila['actual'];

                continue;
            }
            self::sumar($zonasDis[$dis], $fila);
            self::sumar($zonasDep[$sala->departamento], $fila);
        }
        // Del país entero, sin el filtro de ruta: como la tarjeta lo dice.
        $totales['distritosSinSalas'] = count($claveDistrito) - count(array_filter($zonasDis, fn (array $z) => $z['todas'] > 0));

        $cerrar = fn (array $zona) => self::cerrar($zona, $rutas->pluck('id')->all(), $hasta);

        return [
            'mes' => $desde->format('Y-m'),
            'hoy' => $hoy->toDateString(),
            'enCurso' => $enCurso,
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'anteriorDesde' => $anteriorDesde->toDateString(),
                'anteriorHasta' => $anteriorHasta->toDateString(),
            ],
            'totales' => ['ventas' => round($totales['ventas'], 2), 'anterior' => round($totales['anterior'], 2)] + $totales,
            'sinUbicacion' => ['salas' => $sinUbicacion['salas'], 'ventas' => round($sinUbicacion['ventas'], 2), 'sinSala' => round($sinSala['actual'], 2)],
            'rutas' => $rutas->map(fn (Ruta $r) => ['id' => $r->id, 'nombre' => $r->nombre])->all(),
            'departamentos' => array_map($cerrar, $zonasDep),
            // Solo los distritos con salas (de cualquier ruta), ventas o alguna ruta: el resto
            // se dibuja vacío.
            'distritos' => array_map($cerrar, array_filter($zonasDis, fn (array $z) => $z['todas'] > 0 || $z['ventas'] != 0 || $z['anterior'] != 0 || $z['rutas'] !== [])),
        ];
    }

    /** «06-14»: el código del departamento y el del distrito (CAT-008 reinicia por departamento). */
    public static function clave(?string $departamento, ?string $distrito): ?string
    {
        return filled($departamento) && filled($distrito) ? $departamento.'-'.$distrito : null;
    }

    private static function zona(string $nombre, string $departamento): array
    {
        return ['nombre' => $nombre, 'dep' => $departamento, 'ventas' => 0.0, 'anterior' => 0.0, 'salas' => 0, 'todas' => 0, 'compraron' => 0, 'faltan' => 0, 'leToca' => 0, 'atrasadas' => 0, 'sinRitmo' => 0, 'entrega' => null, 'rutas' => [], 'lista' => []];
    }

    private static function sumar(array &$zona, array $fila): void
    {
        $zona['ventas'] += $fila['actual'];
        $zona['anterior'] += $fila['anterior'];
        if (! $fila['cuenta']) {
            return;
        }
        $zona['salas']++;
        $zona['compraron'] += (int) $fila['compro'];
        $zona['leToca'] += (int) ($fila['estado'] === Ritmo::PRONTO);
        $zona['atrasadas'] += (int) ($fila['estado'] === Ritmo::ATRASADA);
        $zona['sinRitmo'] += (int) ($fila['estado'] === Ritmo::SIN_RITMO);
        if ($fila['entrega'] !== null && ($zona['entrega'] === null || $fila['entrega'] > $zona['entrega'])) {
            $zona['entrega'] = $fila['entrega'];
        }
        if (in_array($fila['estado'], [Ritmo::PRONTO, Ritmo::ATRASADA], true)) {
            $zona['lista'][] = ['nombre' => $fila['nombre'], 'estado' => $fila['estado'], 'dias' => $fila['dias'], 'faltan' => $fila['faltan']];
        }
    }

    /**
     * Redondea, ordena las rutas como la lista de rutas y deja las 5 salas que toca visitar:
     * las atrasadas primero y, en cada grupo, la que más pasó de su fecha.
     *
     * @param  array<int, int>  $orden  ids de las rutas activas, por nombre
     * @param  Carbon  $hasta  el último día del tramo, el mismo de los días sin compra
     */
    private static function cerrar(array $zona, array $orden, Carbon $hasta): array
    {
        $lista = $zona['lista'];
        usort($lista, fn ($a, $b) => ($b['estado'] === Ritmo::ATRASADA) <=> ($a['estado'] === Ritmo::ATRASADA)
            ?: $a['faltan'] <=> $b['faltan']
            ?: strcmp($a['nombre'], $b['nombre']));
        unset($zona['lista']);

        return array_merge($zona, [
            'ventas' => round($zona['ventas'], 2),
            'anterior' => round($zona['anterior'], 2),
            'entregaDias' => $zona['entrega'] === null ? null : max(0, (int) Carbon::parse($zona['entrega'], HoraNegocio::zona())->diffInDays($hasta)),
            'rutas' => array_values(array_filter($orden, fn (int $id) => isset($zona['rutas'][$id]))),
            'faltan' => $zona['leToca'] + $zona['atrasadas'],
            'porVisitar' => array_slice($lista, 0, self::POR_VISITAR),
        ]);
    }
}
