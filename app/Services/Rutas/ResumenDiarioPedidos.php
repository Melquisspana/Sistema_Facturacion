<?php

namespace App\Services\Rutas;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Enums\EstadoPedidoSala;
use App\Enums\OrigenPedidoSala;
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
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * El correo de las 18:00 (a quién va: ver A QUIÉN; lleva dinero). En tres partes, en este orden
 * (Melqui, 25/11/2026), una línea por sala: «Llegó de los vendedores» (los pedidos que
 * entraron desde el correo anterior), «Salió de la fábrica» (lo que se mandó, con lo que
 * faltó y por qué) y «Quedó allá» (primero «Listos para salir», después «Armando» y al final
 * «Sin empezar», cada uno con su día); al final, lo vendido. El PDF adjunto («Pedidos del
 * día») lleva las mismas tres partes con los productos y después la hoja de cada envío.
 * Si no hubo nada en una parte, lo dice en una frase («Hoy no salió ningún pedido»).
 *
 * ES EL CIERRE DEL DÍA (11/11/2026): lleva además lo vendido HOY en una línea (el total y
 * cuántas facturas, con la cuenta de la pantalla de Ventas; ni cada CCF ni el mes) y,
 * adjunto, el PDF del día de cada vendedor con movimiento, el mismo de «Terminar el día»,
 * con lo que lleve marcado a esa hora. Es una foto de esa hora y el correo lo dice: lo
 * facturado o marcado después no entra en ningún correo. Si falla la cuenta de ventas o el PDF de alguien, se
 * reporta y el correo sale igual diciendo qué faltó. Lleva dinero: va solo a la lista de
 * Configuración › Rutas.
 *
 * LO DE LA FÁBRICA, DESDE EL CORREO ANTERIOR. Cubre desde el instante hasta el que llegó
 * el último resumen (`hasta` en su auditoría) hasta ahora, no solo el día: lo que la fábrica hace después
 * de las 18:00 sale en el correo siguiente. Sin un resumen anterior, desde el inicio del
 * día. Los dos extremos entran: algo del mismo segundo del corte puede salir en los dos
 * correos, pero nunca en ninguno.
 *
 * A QUIÉN. El ajuste `pedidos.correo_resumen_diario` (Configuración › Rutas, dato
 * personal). Vacío: no se manda y queda en el log.
 *
 * UNA VEZ POR DÍA, DESDE LAS 18:00. Antes de esa hora no sale: una corrida a mano de la
 * mañana no le quita el día al de las 18:00. La auditoría («mandó el resumen diario de
 * pedidos», con la fecha) es la marca que dura: sobrevive a un `cache:clear` del despliegue.
 * `Cache::add` con la fecha en la clave cubre dos corridas a la vez. Si el envío falla, la
 * marca de la caché se suelta y no se audita, así que se puede volver a correr a mano.
 *
 * A MANO (`--forzar`). Sale ya, a cualquier hora y aunque el del día ya haya salido, con lo
 * mismo que traería el siguiente. Queda auditado aparte y no cuenta como el de las 18:00:
 * ni lo frena ni corre el período del siguiente.
 *
 * FUERA DE PRODUCCIÓN NO SALE: pasa por {@see CandadoCorreoReal}, como los demás correos,
 * y queda como simulado.
 *
 * Solo lee: no cambia pedidos, entregas ni documentos.
 */
class ResumenDiarioPedidos
{
    public const AUDITORIA = 'mandó el resumen diario de pedidos';

    public const AUDITORIA_A_MANO = 'mandó a mano el resumen de pedidos';

    /** La hora del día (de El Salvador) a la que sale; la usa también el planificador. */
    public const HORA = '18:00';

    public function __construct(
        private PedidosFabrica $fabrica,
        private CandadoCorreoReal $candado,
        private VentasRutas $ventas,
        private DiaVendedor $dia,
    ) {}

    /**
     * Una hora «HH:MM» como se dice: «las 6 de la tarde», «las 10:15 de la mañana». Sin
     * argumento, la del envío de todos los días ({@see HORA}): los textos la toman de acá.
     */
    public static function horaDicha(string $hora = self::HORA): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $doce = $h % 12 ?: 12;

        return ($doce === 1 ? 'la ' : 'las ').$doce.($m > 0 ? ':'.sprintf('%02d', $m) : '')
            .' de la '.($h < 12 ? 'mañana' : ($h < 19 ? 'tarde' : 'noche'));
    }

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
        $contenido = $this->contenido($this->desdeElAnterior() ?? Carbon::parse($fecha), $hasta)
            + ['ventas' => $this->vendido($fecha)];
        [$contenido['vendedores'], $pdf] = $this->pdfDeVendedores($fecha);
        $contenido['fabrica_pdf_fallo'] = false;
        if ($contenido['salas'] !== []) {
            try {
                $pdf['pedidos-del-'.$fecha.'.pdf'] = app(PdfFabricaDiario::class)->binario($contenido);
            } catch (Throwable $e) {
                // No registrar el mensaje del render: podría contener datos del pedido.
                report(new \RuntimeException('No se pudo adjuntar el PDF de la fábrica ('.get_class($e).')'));
                $contenido['fabrica_pdf_fallo'] = true;
            }
        }
        // Es una foto: lo vendido y lo marcado después no entra. El de todos los días es de
        // su hora; el que se manda a mano, de la hora en que sale.
        $contenido['hasta_las'] = self::horaDicha($forzar ? HoraNegocio::ahora()->format('H:i') : self::HORA);

        $clave = 'pedidos.correo_resumen_diario.'.$fecha;
        if (! $forzar && ! Cache::add($clave, true, now()->addDays(2))) {
            return 'ya_enviado';
        }

        if ($this->candado->debeSimular()) {
            Log::info('Pedidos: resumen diario SIMULADO', ['fecha' => $fecha, 'motivo' => $this->candado->motivo()]);
            $this->auditar($fecha, 'simulado', count($destinatarios), $hasta, $forzar, count($pdf));

            return 'simulado';
        }

        try {
            // El servidor de correo guardado en Configuración, no el del arranque del proceso.
            app(ConfiguracionCorreoRuntime::class)->aplicar();
            Mail::to($destinatarios)->send(new ResumenDiarioPedidosCorreo($contenido, $pdf));
        } catch (Throwable $e) {
            // Puede haber salido igual; se suelta la marca para poder reintentarlo a mano.
            if (! $forzar) {
                Cache::forget($clave);
            }
            Log::warning('Pedidos: resumen diario FALLIDO', ['fecha' => $fecha, 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'fallido';
        }

        $this->auditar($fecha, 'enviado', count($destinatarios), $hasta, $forzar, count($pdf));

        return 'enviado';
    }

    /**
     * Lo que pasó entre `$desde` y `$hasta` (en la hora de la base, app.timezone), agrupado
     * por sala y ordenado por su nombre, en las tres partes del correo:
     *  - `llego`: los pedidos vigentes que entraron al sistema en el período (también los de
     *    la tienda web, con su nombre), con quién los sacó y sus productos.
     *  - `salio` y `deshecho`: los envíos del período y los que un correo anterior dio por
     *    salidos y se deshicieron; `cerrados`: lo que faltó o no se mandó, y por qué.
     *  - `listos` (listos para salir) y `por_mandar` (con `armando` si la fábrica ya lo
     *    empezó): lo que quedó, de cualquier día, con su fecha y `resto` si ya salió una parte.
     *
     * @return array{fecha: string, salio_algo: bool, envios_ids: list<int>, salas: array<string, array{
     *     llego: list<array{numero: int, vendedor: ?string, cajitas: int, productos: list<array{nombre: string, cantidad: string}>}>,
     *     salio: list<array{numero: int, pedido: int, cajitas: int, hora: string, productos: list<array{nombre: string, cantidad: string, linea: int}>}>,
     *     deshecho: list<array{numero: int, pedido: int, cajitas: int, hora: string, productos: list<array{nombre: string, cantidad: string, linea: int}>}>,
     *     cerrados: list<array{numero: int, nada: bool, motivo: ?string, productos: list<array{nombre: string, cantidad: string, motivo: ?string}>}>,
     *     listos: list<array{numero: int, fecha: string, atraso: int, resto: bool, productos: list<array{nombre: string, cantidad: string}>}>,
     *     por_mandar: list<array{numero: int, fecha: string, atraso: int, resto: bool, armando: bool, productos: list<array{nombre: string, cantidad: string}>}>,
     * }>}
     */
    public function contenido(Carbon $desde, Carbon $hasta): array
    {
        $salas = [];
        $sala = function (PedidoSala $pedido) use (&$salas): string {
            $nombre = $pedido->nombreSala().($pedido->origen === OrigenPedidoSala::Web ? ' (web)' : '');
            $salas[$nombre] ??= ['llego' => [], 'salio' => [], 'deshecho' => [], 'cerrados' => [], 'listos' => [], 'por_mandar' => []];

            return $nombre;
        };
        $hoy = Carbon::parse($hasta->toDateString());
        $producto = fn (PedidoSalaLinea $l, string $cantidad) => ['nombre' => (string) $l->producto?->nombre, 'cantidad' => PedidoSalaLinea::legible($cantidad)];
        // Un pedido de la tienda se cuenta en cajitas, igual en «Llegó» y en «Salió»; 0 si no es
        // de la tienda o no trae el detalle (entonces se cuentan sus productos).
        $cajitas = fn (PedidoSala $p) => $p->origen === OrigenPedidoSala::Web
            ? (int) collect($p->pedidoWeb?->cajitas_detalle ?? [])->sum(fn ($c) => $c['cantidad'] ?? 1) : 0;
        // La hora sola si es del día del correo; si no (después de las 18:00 de ayer), con la fecha.
        $envio = function (PedidoSalaEnvio $envio) use ($hoy, $cajitas): array {
            $local = HoraNegocio::aLocal($envio->created_at);

            return [
                'numero' => $envio->numero,
                'pedido' => $envio->pedido_sala_id,
                'cajitas' => $cajitas($envio->pedido),
                'hora' => $local->format($local->isSameDay($hoy) ? 'H:i' : 'd/m H:i'),
                // `linea`: la línea del pedido, para contar cada producto una vez aunque salga en
                // dos envíos. No el nombre: dos presentaciones pueden llamarse igual.
                'productos' => $envio->lineas->map(fn ($l) => [
                    'nombre' => (string) $l->linea?->producto?->nombre,
                    'cantidad' => PedidoSalaLinea::legible((string) $l->cantidad),
                    'linea' => $l->pedido_sala_linea_id,
                ])->values()->all(),
            ];
        };
        $relaciones = ['pedido.sala:id,nombre', 'pedido.pedidoWeb', 'lineas.linea.producto:id,nombre'];

        // 1. Lo que llegó: el pedido que entró al sistema entre los dos correos. Uno que el
        // vendedor ya anuló no se nombra.
        $llegaron = PedidoSala::query()->vigentes()->whereBetween('created_at', [$desde, $hasta])
            ->with(['sala:id,nombre', 'pedidoWeb', 'sacadoPor:id,nombre', 'lineas' => fn ($q) => $q->orderBy('id'), 'lineas.producto:id,nombre'])
            ->orderBy('id')->get();
        foreach ($llegaron as $pedido) {
            $salas[$sala($pedido)]['llego'][] = [
                'numero' => $pedido->id,
                'vendedor' => $pedido->sacadoPor?->nombre,
                'cajitas' => $cajitas($pedido),
                'productos' => $pedido->lineas->map(fn ($l) => $producto($l, (string) $l->cantidad))->values()->all(),
            ];
        }

        // 2. Lo que salió.
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

        // Lo que faltó y lo que no se mandó: los pedidos que se CERRARON en el período, según la
        // auditoría del cierre, aunque su envío haya salido otro día («Lo que falta no se
        // trae» no crea envío). No se usa `updated_at`: agregar la OC después lo movería.
        // Un pedido que se reabrió y sigue por mandar sale en lo que quedó.
        $cerrados = PedidoSala::query()
            ->whereIn('id', Activity::query()
                ->where('log_name', 'pedido_sala')
                ->where('subject_type', (new PedidoSala)->getMorphClass())
                ->whereIn('description', [PedidosFabrica::AUDITORIA_PARTE, PedidosFabrica::AUDITORIA_NO_SE_TRAE, PedidosFabrica::AUDITORIA_LO_QUE_FALTA])
                ->whereBetween('created_at', [$desde, $hasta])
                ->select('subject_id'))
            ->whereIn('estado', [EstadoPedidoSala::Enviado->value, EstadoPedidoSala::NoSeTrae->value])
            ->with(['sala:id,nombre', 'pedidoWeb', 'lineas' => fn ($q) => $q->orderBy('id'), 'lineas.producto:id,nombre', 'lineas.envioLineasVigentes'])
            ->orderBy('id')
            ->get();

        // Por qué no se mandó, si se cerró con «No mandar…» (13/11/2026): uno por pedido. El
        // de cada producto que faltó es el que marcaron al mandar.
        $motivos = $this->fabrica->motivosDeCierre($cerrados->modelKeys());
        foreach ($cerrados as $pedido) {
            $nada = $pedido->estado === EstadoPedidoSala::NoSeTrae;
            $productos = $nada
                ? $pedido->lineas->map(fn ($l) => $producto($l, (string) $l->cantidad) + ['motivo' => null])
                : $pedido->lineas->filter->tienePendiente()->map(fn ($l) => $producto($l, $l->pendiente()) + ['motivo' => $l->motivo_no_enviado]);
            if (! $nada && $productos->isEmpty()) {
                continue;
            }
            $salas[$sala($pedido)]['cerrados'][] = [
                'numero' => $pedido->id,
                'nada' => $nada,
                'motivo' => $motivos[$pedido->id] ?? null,
                'productos' => $productos->values()->all(),
            ];
        }

        // 3. Lo que quedó allá, de cualquier día. La caja lista para salir lleva lo que va en
        // ella; lo demás, lo que falta mandar y si ya lo están armando.
        foreach ($this->fabrica->porMandar()->with('pedidoWeb')->get() as $pedido) {
            $quedo = [
                'numero' => $pedido->id,
                'fecha' => $pedido->fecha->format('d/m/Y'),
                'atraso' => (int) $pedido->fecha->copy()->startOfDay()->diffInDays($hoy),
                'resto' => $pedido->enParte(),
            ];
            if ($pedido->estado === EstadoPedidoSala::Listo) {
                $salas[$sala($pedido)]['listos'][] = $quedo + [
                    'productos' => $pedido->lineas->filter(fn ($l) => bccomp($l->vaEnLaCaja(), '0', 4) === 1)
                        ->map(fn ($l) => $producto($l, $l->vaEnLaCaja()))->values()->all(),
                ];

                continue;
            }
            $salas[$sala($pedido)]['por_mandar'][] = $quedo + [
                'armando' => $pedido->armando_desde !== null,
                'productos' => $pedido->lineas->filter->tienePendiente()->map(fn ($l) => $producto($l, $l->pendiente()))->values()->all(),
            ];
        }

        ksort($salas, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'fecha' => $hoy->format('d/m/Y'),
            'salio_algo' => $envios->isNotEmpty(),
            'envios_ids' => $envios->modelKeys(),
            'salas' => $salas,
        ];
    }

    /**
     * Lo vendido en el día, con la cuenta de la pantalla de Ventas ({@see VentasRutas::delDia()}).
     * Null si la cuenta falla: el correo sale igual y lo dice.
     *
     * @return array{nacional: array{monto: float, facturas: int}, exportacion: array{monto: float, facturas: int}}|null
     */
    private function vendido(string $fecha): ?array
    {
        try {
            return $this->ventas->delDia($fecha);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * El PDF del día de cada vendedor con movimiento, el mismo de «Terminar el día»
     * ({@see DiaVendedor::pdfBinario()}), con lo que lleve marcado hasta ahora. De a uno: si
     * el de alguien falla, se reporta, el correo lo nombra y siguen los demás. Devuelve los
     * nombres para el cuerpo (null si no se pudo ni buscar quién tuvo movimiento) y los PDF,
     * nombre de archivo => contenido.
     *
     * @return array{0: array{adjuntos: list<string>, fallaron: list<string>, resumenes: list<array>, cuentas_fallaron: list<string>}|null, 1: array<string, string>}
     */
    private function pdfDeVendedores(string $fecha): array
    {
        try {
            $personas = $this->dia->personasConMovimiento($fecha);
        } catch (Throwable $e) {
            report($e);

            return [null, []];
        }

        $nombres = ['adjuntos' => [], 'fallaron' => [], 'resumenes' => [], 'cuentas_fallaron' => []];
        $pdf = [];
        foreach ($personas as $persona) {
            $resumen = null;
            try {
                $resumen = $this->dia->resumen($persona, $persona->user, $fecha);
                $nombres['resumenes'][] = ['nombre' => $persona->nombre, 'entregas' => $resumen['entregadas']->count(),
                    'no_se_pudo' => $resumen['no_entregadas']->count(), 'cobrado' => $resumen['cobrado'],
                    'quedan' => $resumen['quedan']['monto'], 'portal' => $resumen['portal']['monto']];
            } catch (Throwable $e) {
                report($e);
                $nombres['cuentas_fallaron'][] = $persona->nombre;
            }
            try {
                $archivo = DiaVendedor::nombrePdf($persona, $fecha);
                // Dos personas con el mismo nombre: la segunda lleva su número.
                if (isset($pdf[$archivo])) {
                    $archivo = Str::replaceLast('.pdf', '-'.$persona->id.'.pdf', $archivo);
                }
                $pdf[$archivo] = $this->dia->pdfBinario($persona, $persona->user, $fecha, $resumen);
                $nombres['adjuntos'][] = $persona->nombre;
            } catch (Throwable $e) {
                report($e);
                $nombres['fallaron'][] = $persona->nombre;
            }
        }

        return [$nombres, $pdf];
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

    /**
     * Sin los correos, sin montos y sin nombres de vendedores: la auditoría la lee más gente.
     * De los PDF, solo cuántos fueron.
     */
    private function auditar(string $fecha, string $estado, int $destinatarios, Carbon $hasta, bool $aMano, int $adjuntos): void
    {
        activity('pedido_sala')
            ->withProperties(['fecha' => $fecha, 'estado' => $estado, 'destinatarios' => $destinatarios, 'hasta' => $hasta->format('Y-m-d H:i:s'), 'adjuntos' => $adjuntos])
            ->log($aMano ? self::AUDITORIA_A_MANO : self::AUDITORIA);
    }
}
