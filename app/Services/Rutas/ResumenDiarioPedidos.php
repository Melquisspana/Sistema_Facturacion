<?php

namespace App\Services\Rutas;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Enums\EstadoPedidoSala;
use App\Facades\Ajustes;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PedidoSalaLinea;
use App\Support\Correo\CandadoCorreoReal;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * El correo de las 17:00 con lo que hizo la fábrica, por sala: lo que salió en cada envío,
 * lo que se deshizo de un envío que ya había salido en un correo anterior, lo que faltó
 * (con su motivo), lo que se marcó «No se trae» y lo que sigue por mandar con sus días de
 * atraso. Si no salió nada, sale igual con «Hoy no salió ningún pedido» y los pendientes.
 *
 * DESDE EL CORREO ANTERIOR. Cubre desde el instante hasta el que llegó el último resumen
 * (`hasta` en su auditoría) hasta ahora, no solo el día: lo que la fábrica hace después
 * de las 17:00 sale en el correo siguiente. Sin un resumen anterior, desde el inicio del
 * día. Los dos extremos entran: algo del mismo segundo del corte puede salir en los dos
 * correos, pero nunca en ninguno.
 *
 * A QUIÉN. El ajuste `pedidos.correo_resumen_diario` (Configuración › Rutas, dato
 * personal). Vacío: no se manda y queda en el log.
 *
 * UNA VEZ POR DÍA, DESDE LAS 17:00. Antes de esa hora no sale: una corrida a mano de la
 * mañana no le quita el día al de las 17:00. La auditoría («mandó el resumen diario de
 * pedidos», con la fecha) es la marca que dura: sobrevive a un `cache:clear` del despliegue.
 * `Cache::add` con la fecha en la clave cubre dos corridas a la vez. Si el envío falla, la
 * marca de la caché se suelta y no se audita, así que se puede volver a correr a mano.
 *
 * A MANO (`--forzar`). Sale ya, a cualquier hora y aunque el del día ya haya salido, con lo
 * mismo que traería el siguiente. Queda auditado aparte y no cuenta como el de las 17:00:
 * ni lo frena ni corre el período del siguiente.
 *
 * FUERA DE PRODUCCIÓN NO SALE: pasa por {@see CandadoCorreoReal}, como los demás correos,
 * y queda como simulado.
 *
 * Solo lee pedidos: no cambia ninguno.
 */
class ResumenDiarioPedidos
{
    public const AUDITORIA = 'mandó el resumen diario de pedidos';

    public const AUDITORIA_A_MANO = 'mandó a mano el resumen de pedidos';

    /** La hora del día (de El Salvador) a la que sale; la usa también el planificador. */
    public const HORA = '17:00';

    public function __construct(
        private PedidosFabrica $fabrica,
        private CandadoCorreoReal $candado,
    ) {}

    /** @return list<string> */
    public function destinatarios(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) Ajustes::texto('pedidos.correo_resumen_diario', ''))),
            fn (string $c) => filter_var($c, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /**
     * Manda el resumen del día de negocio de hoy. Con `$forzar` (a mano) sale aunque sea
     * temprano o ya haya salido, sin contar como el del día.
     *
     * @return 'sin_destinatarios'|'temprano'|'ya_enviado'|'enviado'|'simulado'|'fallido'
     */
    public function enviar(bool $forzar = false): string
    {
        $fecha = HoraNegocio::fechaHoy();
        $destinatarios = $this->destinatarios();

        if ($destinatarios === []) {
            Log::info('Pedidos: resumen diario NO enviado, falta el correo en Configuración › Rutas', ['fecha' => $fecha]);

            return 'sin_destinatarios';
        }

        if (! $forzar && HoraNegocio::ahora()->format('H:i') < self::HORA) {
            return 'temprano';
        }

        if (! $forzar && $this->yaEnviado($fecha)) {
            return 'ya_enviado';
        }

        // Se arma ANTES de tomar la marca: si la consulta falla, no queda tomada.
        $hasta = now()->startOfSecond();
        $contenido = $this->contenido($this->desdeElAnterior() ?? Carbon::parse($fecha), $hasta);

        $clave = 'pedidos.correo_resumen_diario.'.$fecha;
        if (! $forzar && ! Cache::add($clave, true, now()->addDays(2))) {
            return 'ya_enviado';
        }

        if ($this->candado->debeSimular()) {
            Log::info('Pedidos: resumen diario SIMULADO', ['fecha' => $fecha, 'motivo' => $this->candado->motivo()]);
            $this->auditar($fecha, 'simulado', count($destinatarios), $hasta, $forzar);

            return 'simulado';
        }

        try {
            // El servidor de correo guardado en Configuración, no el del arranque del proceso.
            app(ConfiguracionCorreoRuntime::class)->aplicar();
            Mail::to($destinatarios)->send(new ResumenDiarioPedidosCorreo($contenido));
        } catch (Throwable $e) {
            // Puede haber salido igual; se suelta la marca para poder reintentarlo a mano.
            if (! $forzar) {
                Cache::forget($clave);
            }
            Log::warning('Pedidos: resumen diario FALLIDO', ['fecha' => $fecha, 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'fallido';
        }

        $this->auditar($fecha, 'enviado', count($destinatarios), $hasta, $forzar);

        return 'enviado';
    }

    /**
     * Lo que pasó entre `$desde` y `$hasta` (en la hora de la base, app.timezone), agrupado
     * por sala y ordenado por su nombre.
     *
     * @return array{fecha: string, salio_algo: bool, salas: array<string, array{salio: list<array{numero: int, hora: string, productos: list<array{nombre: string, cantidad: string}>}>, deshecho: list<array{numero: int, hora: string, productos: list<array{nombre: string, cantidad: string}>}>, falto: list<array{nombre: string, cantidad: string, motivo: ?string}>, no_se_trae: list<array{nombre: string, cantidad: string}>, por_mandar: list<array{fecha: string, atraso: int, productos: list<array{nombre: string, cantidad: string}>}>}>}
     */
    public function contenido(Carbon $desde, Carbon $hasta): array
    {
        $salas = [];
        $sala = function (PedidoSala $pedido) use (&$salas): string {
            $nombre = $pedido->nombreSala();
            $salas[$nombre] ??= ['salio' => [], 'deshecho' => [], 'falto' => [], 'no_se_trae' => [], 'por_mandar' => []];

            return $nombre;
        };
        $hoy = Carbon::parse($hasta->toDateString());
        // La hora sola si es del día del correo; si no (después de las 17:00 de ayer), con la fecha.
        $envio = function (PedidoSalaEnvio $envio) use ($hoy): array {
            $local = HoraNegocio::aLocal($envio->created_at);

            return [
                'numero' => $envio->numero,
                'hora' => $local->format($local->isSameDay($hoy) ? 'H:i' : 'd/m H:i'),
                'productos' => $envio->lineas->map(fn ($l) => [
                    'nombre' => (string) $l->linea?->producto?->nombre,
                    'cantidad' => PedidoSalaLinea::legible((string) $l->cantidad),
                ])->values()->all(),
            ];
        };
        $relaciones = ['pedido.sala:id,nombre', 'lineas.linea.producto:id,nombre'];

        $envios = PedidoSalaEnvio::query()->vigentes()->whereBetween('created_at', [$desde, $hasta])
            ->with($relaciones)->orderBy('id')->get();
        foreach ($envios as $e) {
            $salas[$sala($e->pedido)]['salio'][] = $envio($e);
        }

        // Un envío que un correo anterior dio por salido y se deshizo después. Uno que salió y
        // se deshizo entre los dos correos no se nombra: nunca se informó.
        $deshechos = PedidoSalaEnvio::query()->whereBetween('anulado_en', [$desde, $hasta])->where('created_at', '<', $desde)
            ->with($relaciones)->orderBy('id')->get();
        foreach ($deshechos as $e) {
            $salas[$sala($e->pedido)]['deshecho'][] = $envio($e);
        }

        // Lo que faltó y lo que no se trae: los pedidos que se CERRARON en el período, según la
        // auditoría del cierre, aunque su envío haya salido otro día («Lo que falta no se
        // trae» no crea envío). No se usa `updated_at`: agregar la OC después lo movería.
        // Un pedido que se reabrió y sigue por mandar sale abajo, con los pendientes.
        $cerrados = PedidoSala::query()
            ->whereIn('id', Activity::query()
                ->where('log_name', 'pedido_sala')
                ->where('subject_type', (new PedidoSala)->getMorphClass())
                ->whereIn('description', [PedidosFabrica::AUDITORIA_PARTE, PedidosFabrica::AUDITORIA_NO_SE_TRAE, PedidosFabrica::AUDITORIA_LO_QUE_FALTA])
                ->whereBetween('created_at', [$desde, $hasta])
                ->select('subject_id'))
            ->whereIn('estado', [EstadoPedidoSala::Enviado->value, EstadoPedidoSala::NoSeTrae->value])
            ->with(['sala:id,nombre', 'lineas' => fn ($q) => $q->orderBy('id'), 'lineas.producto:id,nombre', 'lineas.envioLineasVigentes'])
            ->orderBy('id')
            ->get();

        foreach ($cerrados as $pedido) {
            if ($pedido->estado === EstadoPedidoSala::NoSeTrae) {
                foreach ($pedido->lineas as $linea) {
                    $salas[$sala($pedido)]['no_se_trae'][] = [
                        'nombre' => (string) $linea->producto?->nombre,
                        'cantidad' => $linea->cantidadLegible(),
                    ];
                }

                continue;
            }
            foreach ($pedido->lineas->filter->tienePendiente() as $linea) {
                $salas[$sala($pedido)]['falto'][] = [
                    'nombre' => (string) $linea->producto?->nombre,
                    'cantidad' => PedidoSalaLinea::legible($linea->pendiente()),
                    'motivo' => $linea->motivo_no_enviado,
                ];
            }
        }

        foreach ($this->fabrica->porMandar()->get() as $pedido) {
            $salas[$sala($pedido)]['por_mandar'][] = [
                'fecha' => $pedido->fecha->format('d/m/Y'),
                'atraso' => (int) $pedido->fecha->copy()->startOfDay()->diffInDays($hoy),
                'productos' => $pedido->lineas->filter->tienePendiente()->map(fn ($l) => [
                    'nombre' => (string) $l->producto?->nombre,
                    'cantidad' => PedidoSalaLinea::legible($l->pendiente()),
                ])->values()->all(),
            ];
        }

        ksort($salas, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'fecha' => $hoy->format('d/m/Y'),
            'salio_algo' => $envios->isNotEmpty(),
            'salas' => $salas,
        ];
    }

    /** Hasta dónde llegó el último resumen, en la hora de la base; null si nunca salió uno. */
    private function desdeElAnterior(): ?Carbon
    {
        $anterior = Activity::query()
            ->where('log_name', 'pedido_sala')
            ->where('description', self::AUDITORIA)
            ->latest('id')
            ->first();
        $hasta = $anterior?->properties['hasta'] ?? null;

        return $hasta ? Carbon::parse($hasta) : $anterior?->created_at;
    }

    private function yaEnviado(string $fecha): bool
    {
        return Activity::query()
            ->where('log_name', 'pedido_sala')
            ->where('description', self::AUDITORIA)
            ->where('properties->fecha', $fecha)
            ->exists();
    }

    /** Sin los correos: son datos personales y la auditoría la lee más gente. */
    private function auditar(string $fecha, string $estado, int $destinatarios, Carbon $hasta, bool $aMano): void
    {
        activity('pedido_sala')
            ->withProperties(['fecha' => $fecha, 'estado' => $estado, 'destinatarios' => $destinatarios, 'hasta' => $hasta->format('Y-m-d H:i:s')])
            ->log($aMano ? self::AUDITORIA_A_MANO : self::AUDITORIA);
    }
}
