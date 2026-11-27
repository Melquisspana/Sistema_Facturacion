<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\ResultadoEntrega;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\SalidaRutaEntrega;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * «Pedidos y entregas» (Melqui, 26/11/2026): lo que la dueña mira desde el celular, de hoy,
 * de ayer o de la semana (de lunes a hoy, en la hora del negocio). No calcula nada propio:
 * junta lo que ya sale en otros lados, para que los números coincidan.
 *
 *  - Pedidos de las salas, la fábrica y lo que salió de ella: {@see ResumenDiarioPedidos::contenido()},
 *    el del correo de las 18:00. La fábrica es lo de ahora, de cualquier día, con el atraso
 *    contado hasta hoy aunque se mire ayer.
 *  - Lo entregado: las mismas entregas del PDF del día ({@see DiaVendedor}: sin los
 *    facturados después de entregar), en la zona de la sala como la cuenta el tablero
 *    ({@see PropuestaRutas::zonaDe()}).
 *  - Lo vendido: {@see VentasRutas::delDia()}, día por día, como «Ventas del día» del correo.
 *    Si esa cuenta falla, se reporta y la pantalla lo dice; lo demás sale igual.
 *
 * Solo lee.
 */
class PedidosYEntregas
{
    public const PERIODOS = ['hoy', 'ayer', 'semana'];

    public function __construct(
        private readonly ResumenDiarioPedidos $resumen,
        private readonly VentasRutas $ventas,
        private readonly PropuestaRutas $propuesta,
    ) {}

    /**
     * Primer y último día del período, en la hora del negocio. Lo que no es un período
     * conocido es hoy.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dias(string $periodo): array
    {
        $hoy = HoraNegocio::hoy();

        return match ($periodo) {
            'ayer' => [$hoy->copy()->subDay(), $hoy->copy()->subDay()],
            'semana' => [$hoy->copy()->startOfWeek(Carbon::MONDAY), $hoy],
            default => [$hoy, $hoy->copy()],
        };
    }

    /**
     * @return array{
     *     periodo: string, desde: Carbon, hasta: Carbon,
     *     pedidos: array{total: int, por_estado: array<string, int>, lista: list<array{numero: int, sala: string, vendedor: ?string, estado: EstadoPedidoSala}>},
     *     fabrica: array{
     *         sin_empezar: list<array{numero: int, sala: string, fecha: string, atraso: int, resto: bool}>,
     *         armando: list<array{numero: int, sala: string, fecha: string, atraso: int, resto: bool}>,
     *         listos: list<array{numero: int, sala: string, fecha: string, atraso: int, resto: bool}>,
     *         salieron: list<array{numero: int, sala: string, hora: string}>,
     *     },
     *     entregas: array{entregadas: int, no_se_pudo: int, zonas: list<array{nombre: string, entregadas: int, no_se_pudo: int, lista: list<array{sala: string, documento: ?string, tipo: string, quien: ?string, hora: string, entregado: bool}>}>},
     *     vendido: array{nacional: array{monto: float, facturas: int}, exportacion: array{monto: float, facturas: int}}|null,
     * }
     */
    public function calcular(string $periodo): array
    {
        $periodo = in_array($periodo, self::PERIODOS, true) ? $periodo : 'hoy';
        [$desde, $hasta] = self::dias($periodo);
        // El correo lo pide en la hora de la base (app.timezone), de punta a punta de cada día.
        $contenido = $this->resumen->contenido(Carbon::parse($desde->toDateString())->startOfDay(), Carbon::parse($hasta->toDateString())->endOfDay());

        return [
            'periodo' => $periodo,
            'desde' => $desde,
            'hasta' => $hasta,
            'pedidos' => $this->pedidos($contenido['salas']),
            'fabrica' => $this->fabrica($contenido['salas']),
            'entregas' => $this->entregas($desde, $hasta),
            'vendido' => $this->vendido($desde, $hasta),
        ];
    }

    /** Los pedidos que entraron en el período (sin los anulados), con su estado de ahora. */
    private function pedidos(array $salas): array
    {
        $lista = [];
        foreach ($salas as $nombre => $sala) {
            foreach ($sala['llego'] as $pedido) {
                $lista[] = ['numero' => $pedido['numero'], 'sala' => $this->nombre($nombre), 'vendedor' => $pedido['vendedor']];
            }
        }
        $estados = PedidoSala::query()->whereKey(array_column($lista, 'numero'))->pluck('estado', 'id');
        $porEstado = [];
        // Cada estado, con su etiqueta: lo que volvió a la fábrica va en el suyo, no en nada por entregar.
        foreach (EstadoPedidoSala::cases() as $estado) {
            if ($estado !== EstadoPedidoSala::Anulado) {
                $porEstado[$estado->value] = 0;
            }
        }
        // Uno que se anuló mientras se armaba la pantalla ya no se cuenta.
        $lista = array_values(array_filter(
            array_map(fn (array $fila) => $fila + ['estado' => $estados[$fila['numero']] ?? null], $lista),
            fn (array $fila) => $fila['estado'] !== null && isset($porEstado[$fila['estado']->value]),
        ));
        foreach ($lista as $fila) {
            $porEstado[$fila['estado']->value]++;
        }

        return ['total' => count($lista), 'por_estado' => $porEstado, 'lista' => $lista];
    }

    /** Lo que está ahora en la fábrica, del más atrasado al más nuevo, y lo que salió en el período (una vez por pedido). */
    private function fabrica(array $salas): array
    {
        $hoy = Carbon::parse(HoraNegocio::fechaHoy());
        $quedo = ['sin_empezar' => [], 'armando' => [], 'listos' => []];
        $salieron = [];
        foreach ($salas as $nombre => $sala) {
            $fila = fn (array $p) => [
                'numero' => $p['numero'],
                'sala' => $this->nombre($nombre),
                'fecha' => $p['fecha'],
                'atraso' => max(0, (int) Carbon::createFromFormat('!d/m/Y', $p['fecha'])->diffInDays($hoy)),
                'resto' => $p['resto'],
            ];
            foreach ($sala['listos'] as $pedido) {
                $quedo['listos'][] = $fila($pedido);
            }
            foreach ($sala['por_mandar'] as $pedido) {
                $quedo[$pedido['armando'] ? 'armando' : 'sin_empezar'][] = $fila($pedido);
            }
            foreach ($sala['salio'] as $envio) {
                $salieron[$envio['pedido']] = ['numero' => $envio['pedido'], 'sala' => $this->nombre($nombre), 'hora' => $envio['hora']];
            }
        }
        foreach ($quedo as &$grupo) {
            usort($grupo, fn (array $a, array $b) => [$b['atraso'], $a['numero']] <=> [$a['atraso'], $b['numero']]);
        }
        unset($grupo);
        ksort($salieron);

        return $quedo + ['salieron' => array_values($salieron)];
    }

    /** Lo entregado y lo que no se pudo en el período, por zona; «Sin zona» al final. */
    private function entregas(Carbon $desde, Carbon $hasta): array
    {
        $unDia = $desde->isSameDay($hasta);
        $entregas = SalidaRutaEntrega::query()->sinFacturadosDespues()
            ->whereIn('resultado', [ResultadoEntrega::Entregado->value, ResultadoEntrega::NoEntregado->value])
            ->whereDate('fecha_resultado', '>=', $desde->toDateString())
            ->whereDate('fecha_resultado', '<=', $hasta->toDateString())
            ->with(['sala:id,nombre,ruta_id,distrito_id,departamento_id', 'dte:id,numero_control,tipo_dte', ...SalidaRutaEntrega::PEDIDO_WEB, 'entregadoPor:id,nombre', 'registradoPor:id,name'])
            ->orderBy('fecha_resultado')->orderBy('id')
            ->get();

        $zonas = [];
        foreach ($entregas as $entrega) {
            $dte = $entrega->dte instanceof Dte ? $entrega->dte : null;
            // El pedido web no tiene sala: va en la zona de su pedido.
            $zona = $entrega->sala
                ? $this->propuesta->zonaDe($entrega->sala->ruta_id, $entrega->sala->distrito_id, $entrega->sala->departamento_id)
                : $this->propuesta->zonaDe($dte?->pedidoWeb?->pedidoSala?->ruta_id, null, null);
            $clave = $zona->id ?? 0;
            $zonas[$clave] ??= ['nombre' => $zona->nombre ?? 'Sin zona', 'entregadas' => 0, 'no_se_pudo' => 0, 'lista' => []];
            $entregado = $entrega->resultado === ResultadoEntrega::Entregado;
            $zonas[$clave][$entregado ? 'entregadas' : 'no_se_pudo']++;
            $zonas[$clave]['lista'][] = [
                'sala' => $entrega->nombreDestino(),
                'documento' => $dte?->numero_control,
                // El pedido web se entrega con su Factura; las salas, con el CCF.
                'tipo' => $dte?->tipo_dte === '01' ? 'Factura' : 'CCF',
                // Un «No se pudo» de antes, sin quién fue, es de quien lo anotó.
                'quien' => $entrega->entregadoPor->nombre ?? $entrega->registradoPor?->name,
                'hora' => HoraNegocio::aLocal($entrega->fecha_resultado)->format($unDia ? 'H:i' : 'd/m H:i'),
                'entregado' => $entregado,
            ];
        }
        uksort($zonas, fn (int $a, int $b) => [$a === 0, $zonas[$a]['nombre']] <=> [$b === 0, $zonas[$b]['nombre']]);

        return [
            'entregadas' => array_sum(array_column($zonas, 'entregadas')),
            'no_se_pudo' => array_sum(array_column($zonas, 'no_se_pudo')),
            'zonas' => array_values($zonas),
        ];
    }

    /** Lo vendido sin IVA, la suma de cada día del período. Null si la cuenta falla. */
    private function vendido(Carbon $desde, Carbon $hasta): ?array
    {
        $total = ['nacional' => ['monto' => 0.0, 'facturas' => 0], 'exportacion' => ['monto' => 0.0, 'facturas' => 0]];
        try {
            for ($dia = $desde->copy(); $dia->lte($hasta); $dia->addDay()) {
                foreach ($this->ventas->delDia($dia->toDateString()) as $canal => $cuenta) {
                    $total[$canal]['monto'] += $cuenta['monto'];
                    $total[$canal]['facturas'] += $cuenta['facturas'];
                }
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return array_map(fn (array $c) => ['monto' => round($c['monto'], 2), 'facturas' => $c['facturas']], $total);
    }

    /** El nombre de la sala sin la marca « (web)» con que el correo distingue los pedidos de la tienda. */
    private function nombre(string $sala): string
    {
        return (string) preg_replace('/ \(web\)$/u', '', $sala);
    }
}
