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
 * El correo de las 17:00 con lo que hizo la fábrica en el día, por sala: lo que salió en
 * cada envío, lo que faltó (con su motivo), lo que se marcó «No se trae» y lo que sigue por
 * mandar con sus días de atraso. Si no salió nada, sale igual con «Hoy no salió ningún
 * pedido» y los pendientes.
 *
 * A QUIÉN. El ajuste `pedidos.correo_resumen_diario` (Configuración › Rutas, dato
 * personal). Vacío: no se manda y queda en el log.
 *
 * UNA VEZ POR DÍA. La auditoría («mandó el resumen diario de pedidos», con la fecha) es la
 * marca que dura: sobrevive a un `cache:clear` del despliegue. `Cache::add` con la fecha en
 * la clave cubre dos corridas a la vez. Si el envío falla, la marca de la caché se suelta y
 * no se audita, así que se puede volver a correr a mano.
 *
 * FUERA DE PRODUCCIÓN NO SALE: pasa por {@see CandadoCorreoReal}, como los demás correos,
 * y queda como simulado.
 *
 * Solo lee pedidos: no cambia ninguno.
 */
class ResumenDiarioPedidos
{
    public const AUDITORIA = 'mandó el resumen diario de pedidos';

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
     * Manda el resumen del día de negocio de hoy.
     *
     * @return 'sin_destinatarios'|'ya_enviado'|'enviado'|'simulado'|'fallido'
     */
    public function enviar(): string
    {
        $fecha = HoraNegocio::fechaHoy();
        $destinatarios = $this->destinatarios();

        if ($destinatarios === []) {
            Log::info('Pedidos: resumen diario NO enviado, falta el correo en Configuración › Rutas', ['fecha' => $fecha]);

            return 'sin_destinatarios';
        }

        if ($this->yaEnviado($fecha)) {
            return 'ya_enviado';
        }

        // Se arma ANTES de tomar la marca: si la consulta falla, no queda tomada.
        $contenido = $this->contenido($fecha);

        $clave = 'pedidos.correo_resumen_diario.'.$fecha;
        if (! Cache::add($clave, true, now()->addDays(2))) {
            return 'ya_enviado';
        }

        if ($this->candado->debeSimular()) {
            Log::info('Pedidos: resumen diario SIMULADO', ['fecha' => $fecha, 'motivo' => $this->candado->motivo()]);
            $this->auditar($fecha, 'simulado', count($destinatarios));

            return 'simulado';
        }

        try {
            // El servidor de correo guardado en Configuración, no el del arranque del proceso.
            app(ConfiguracionCorreoRuntime::class)->aplicar();
            Mail::to($destinatarios)->send(new ResumenDiarioPedidosCorreo($contenido));
        } catch (Throwable $e) {
            // Puede haber salido igual; se suelta la marca para poder reintentarlo a mano.
            Cache::forget($clave);
            Log::warning('Pedidos: resumen diario FALLIDO', ['fecha' => $fecha, 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'fallido';
        }

        $this->auditar($fecha, 'enviado', count($destinatarios));

        return 'enviado';
    }

    /**
     * Lo del día, agrupado por sala y ordenado por su nombre.
     *
     * @return array{fecha: string, salio_algo: bool, salas: array<string, array{salio: list<array{numero: int, hora: string, productos: list<array{nombre: string, cantidad: string}>}>, falto: list<array{nombre: string, cantidad: string, motivo: ?string}>, no_se_trae: list<array{nombre: string, cantidad: string}>, por_mandar: list<array{fecha: string, atraso: int, productos: list<array{nombre: string, cantidad: string}>}>}>}
     */
    public function contenido(string $fecha): array
    {
        $salas = [];
        $sala = function (PedidoSala $pedido) use (&$salas): string {
            $nombre = (string) ($pedido->sala?->nombre ?? 'Sala sin nombre');
            $salas[$nombre] ??= ['salio' => [], 'falto' => [], 'no_se_trae' => [], 'por_mandar' => []];

            return $nombre;
        };

        $envios = PedidoSalaEnvio::query()->vigentes()->whereDate('fecha', $fecha)
            ->with(['pedido.sala:id,nombre', 'pedido.lineas.producto:id,nombre', 'pedido.lineas.envioLineasVigentes', 'lineas.linea.producto:id,nombre'])
            ->orderBy('id')
            ->get();

        foreach ($envios as $envio) {
            $salas[$sala($envio->pedido)]['salio'][] = [
                'numero' => $envio->numero,
                'hora' => HoraNegocio::aLocal($envio->created_at)->format('H:i'),
                'productos' => $envio->lineas->map(fn ($l) => [
                    'nombre' => (string) $l->linea?->producto?->nombre,
                    'cantidad' => PedidoSalaLinea::legible((string) $l->cantidad),
                ])->values()->all(),
            ];
        }

        // Lo que faltó de los pedidos que salieron hoy y quedaron cerrados. Si lo que falta
        // va en otro envío (sigue por mandar), sale abajo, con los pendientes.
        foreach ($envios->pluck('pedido')->unique('id') as $pedido) {
            if ($pedido->estaPorMandar()) {
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

        // `updated_at` se guarda en la hora del negocio (app.timezone).
        $noSeTraen = PedidoSala::query()->where('estado', EstadoPedidoSala::NoSeTrae->value)
            ->whereDate('updated_at', $fecha)
            ->with(['sala:id,nombre', 'lineas.producto:id,nombre'])
            ->orderBy('id')
            ->get();

        foreach ($noSeTraen as $pedido) {
            foreach ($pedido->lineas as $linea) {
                $salas[$sala($pedido)]['no_se_trae'][] = [
                    'nombre' => (string) $linea->producto?->nombre,
                    'cantidad' => $linea->cantidadLegible(),
                ];
            }
        }

        $hoy = Carbon::parse($fecha);
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

    private function yaEnviado(string $fecha): bool
    {
        return Activity::query()
            ->where('log_name', 'pedido_sala')
            ->where('description', self::AUDITORIA)
            ->where('properties->fecha', $fecha)
            ->exists();
    }

    /** Sin los correos: son datos personales y la auditoría la lee más gente. */
    private function auditar(string $fecha, string $estado, int $destinatarios): void
    {
        activity('pedido_sala')
            ->withProperties(['fecha' => $fecha, 'estado' => $estado, 'destinatarios' => $destinatarios])
            ->log(self::AUDITORIA);
    }
}
