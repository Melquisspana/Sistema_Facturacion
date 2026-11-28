<?php

namespace App\Services\Rutas;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoWeb;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenPedidoSala;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Facades\Ajustes;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Support\HoraNegocio;
use App\Support\IdentidadPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Qué CCF hay que entregar, qué lleva cada salida y qué pasó con cada uno. Toda la regla
 * vive acá: controladores y vistas no la repiten.
 *
 * ─────────────────────────── Pendiente de entregar ───────────────────────────
 *
 * Un CCF está pendiente cuando:
 *
 *   · es un CCF (03) aceptado de verdad por Hacienda y no archivado, del AMBIENTE
 *     OPERATIVO de esta instalación: producción en el servidor, pruebas en desarrollo
 *     (el mismo criterio del listado de Facturación), para que se pueda probar;
 *   · se emitió desde `rutas.entregas_desde` (lo anterior se entregó antes de que
 *     existiera este control y no tiene registro);
 *   · nadie registró que se entregó;
 *   · no tiene albarán de ENTREGA inequívoco ({@see AlbaranLocalizador}): en Calleja, que
 *     caiga el albarán ya prueba la entrega aunque nadie la haya marcado;
 *   · y no está sin registrar en otra salida abierta —ya va en camino—.
 *
 * Un «no entregado» NO saca al CCF de pendientes: sigue en «Por entregar».
 *
 * El pedido web con ruta (16/11/2026) va aparte, en {@see self::pendientesWeb()}: su Factura
 * 01 no entra en los CCF de las salas. Se registra igual, en el viaje, «Ya pagado» y sin cobro.
 *
 * ─────────────────────────── Controles ───────────────────────────
 *
 * Un CCF se entrega una sola vez y va sin registrar en una sola salida abierta.
 * Agregar, registrar y deshacer bloquean la fila del DTE (y la salida) dentro de una
 * transacción y vuelven a comprobar con lecturas bloqueantes: dos cargas simultáneas no
 * meten el mismo CCF en dos salidas. Solo se registra en salidas en curso, y un resultado
 * ya anotado se cambia deshaciéndolo antes.
 */
class EntregasCcf
{
    /** Forma de pago elegible al entregar: no se cobra ahora (quedan o crédito). */
    public const SIN_COBRO = 'sin_cobro';

    /** Al vendedor, en el viaje o la automática, le falta decir cómo le pagaron. */
    public const FALTA_FORMA_COBRO = 'Elegí cómo te pagaron: efectivo, cheque, transferencia o quedan.';

    /** Un dato del formulario llegó como lista en vez de texto (un enlace armado a mano). */
    public const DATO_EN_LISTA = 'Ese dato no llegó bien. Volvé a marcar la entrega.';

    /** Ventana de «Por entregar»: lo facturado hace más quedó viejo y no se lista. */
    public const DIAS_POR_ENTREGAR = 45;

    public function __construct(private readonly AlbaranLocalizador $albaranes, private readonly PropuestaRutas $propuesta) {}

    // ================================================================ pendientes

    /**
     * «Por entregar» del vendedor: lo pendiente de TODAS las salas, con ruta o sin ella, de
     * los últimos {@see self::DIAS_POR_ENTREGAR} días (y nunca antes de `rutas.entregas_desde`).
     * A diferencia de la carga de una salida, incluye lo que ya va sin registrar en una salida
     * abierta: sigue por entregar y se marca dentro de esa salida. Los más viejos primero.
     *
     * Con `$dteId`, solo ese CCF (vacío si no está por entregar).
     *
     * @return Collection<int, Dte>
     */
    public function porEntregar(?int $dteId = null): Collection
    {
        $desde = max($this->desde(), Carbon::parse(HoraNegocio::fechaHoy())->subDays(self::DIAS_POR_ENTREGAR));

        return $this->pendientesDeSalas(null, $desde, enCamino: true, dteId: $dteId);
    }

    /**
     * La lista de «Por entregar»: lo de las salas ({@see porEntregar()}) y los pedidos web con
     * ruta ({@see pendientesWeb()}), los más viejos primero. Cada DTE trae `pedidoWeb` cargado
     * (null en las salas), así la pantalla los distingue sin consultar de a uno. El tablero y
     * los contadores de salas siguen con {@see porEntregar()}.
     *
     * @return Collection<int, Dte>
     */
    public function porEntregarConWeb(?int $dteId = null): Collection
    {
        return $this->porEntregar($dteId)->each(fn (Dte $d) => $d->setRelation('pedidoWeb', null))
            ->merge($this->pendientesWeb($dteId))->sortBy(fn (Dte $d) => [$d->fecha_emision, $d->id])->values();
    }

    /**
     * Pedidos web con ruta por entregar (16/11/2026): su Factura 01 aceptada de verdad por
     * Hacienda, sin archivar y del ambiente operativo; la fábrica ya lo mandó (`lista_en`),
     * nadie lo entregó y su pedido sigue vigente. Es una fuente aparte, no {@see baseCcf()}:
     * así no cambia nada de lo que cuentan las salas. Sin ventana de días: ya está pagado y
     * hay que entregarlo. Con `$dteId`, solo ese (vacío si no está por entregar).
     *
     * @return EloquentCollection<int, Dte> con `pedidoWeb.pedidoSala` (y su nombre)
     */
    public function pendientesWeb(?int $dteId = null): EloquentCollection
    {
        return $this->webPorEntregar($dteId)
            // `pedidoSala.pedidoWeb`: PedidoSala::nombreSala() («Pedido web · Nombre I.») lo lee.
            ->with(['pedidoWeb.pedidoSala.pedidoWeb:id,pedido_sala_id,cliente_nombre'])
            ->orderBy('fecha_emision')->orderBy('id')->get();
    }

    /** La regla de {@see pendientesWeb()}, sin cargar nada: la reusa la cuenta del tablero. */
    private function webPorEntregar(?int $dteId = null): Builder
    {
        return Dte::query()->where('tipo_dte', '01')->aceptadoRealMh()->noArchivados()
            ->where('ambiente', (string) (config('rutas.ambiente_ccf') ?: config('dte.ambiente')))
            ->when($dteId !== null, fn ($q) => $q->whereKey($dteId))
            ->whereHas('pedidoWeb', fn ($q) => $q->where('entrega_tipo', 'ruta')->whereNotNull('lista_en')->whereNull('entregado_en')
                ->where('estado', EstadoPedidoWeb::Facturado)
                ->whereHas('pedidoSala', fn ($q) => $q->vigentes()->where('origen', OrigenPedidoSala::Web)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('salida_ruta_entregas')
                ->whereColumn('salida_ruta_entregas.dte_id', 'dtes.id')->where('resultado', ResultadoEntrega::Entregado->value));
    }

    /**
     * Pendientes de estas salas (todas, con `null`), en una consulta para todas.
     *
     * @param  array<int, int>|null  $salas
     * @return Collection<int, Dte>
     */
    public function pendientesDeSalas(?array $salas, ?Carbon $desde = null, bool $enCamino = false, ?int $dteId = null): Collection
    {
        if ($salas === []) {
            return collect();
        }

        $candidatos = $this->baseCcf()
            ->when($salas === null, fn ($q) => $q->whereNotNull('cliente_sucursal_id'), fn ($q) => $q->whereIn('cliente_sucursal_id', $salas))
            ->when($dteId !== null, fn ($q) => $q->whereKey($dteId))
            ->whereDate('fecha_emision', '>=', $desde ?? $this->desde())
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('salida_ruta_entregas')
                    ->whereColumn('salida_ruta_entregas.dte_id', 'dtes.id')->where('salida_ruta_entregas.resultado', ResultadoEntrega::Entregado->value);
            })
            ->unless($enCamino, fn ($q) => $q->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('salida_ruta_entregas')
                    ->join('salidas_ruta', 'salidas_ruta.id', '=', 'salida_ruta_entregas.salida_ruta_id')
                    ->whereColumn('salida_ruta_entregas.dte_id', 'dtes.id')->whereNull('salida_ruta_entregas.resultado')
                    ->whereIn('salidas_ruta.estado', [EstadoSalidaRuta::Planificada->value, EstadoSalidaRuta::EnCurso->value]);
            }))
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();

        return $this->sinAlbaranDeEntrega($candidatos);
    }

    /**
     * Cuántos CCF pendientes y por cuánto, por zona. Para el tablero y el aviso del Panel.
     * Cuentan TODAS las salas activas, cada una en su zona con el criterio del tablero
     * ({@see PropuestaRutas::zonaDe()}): su ruta, la que cubre su lugar o, en la clave 0, «Sin
     * zona». Las mismas consultas con 2 zonas que con 20: se buscan todos juntos y se reparten.
     *
     * Los pedidos web con ruta por entregar ({@see pendientesWeb()}, 16/11/2026) van en `web`,
     * en la zona de su pedido (0 si no tiene o ya no está activa): solo cuántos, porque ya se
     * pagaron en la página y no suman al monto. Una consulta más, fija.
     *
     * @return array<int, array{cantidad: int, monto: float, web: int}> ruta_id (0: sin zona) => pendientes
     */
    public function pendientesPorRuta(): array
    {
        $activas = $this->propuesta->activas()->modelKeys();
        $zonaDeSala = [];
        foreach (ClienteSucursal::where('activo', true)->get(['id', 'ruta_id', 'distrito_id', 'departamento_id']) as $sala) {
            $zonaDeSala[$sala->id] = $this->propuesta->zonaDe($sala->ruta_id, $sala->distrito_id, $sala->departamento_id)?->id ?? 0;
        }
        $porZona = $this->pendientesDeSalas(null)
            ->filter(fn (Dte $dte) => isset($zonaDeSala[$dte->cliente_sucursal_id]))
            ->groupBy(fn (Dte $dte) => $zonaDeSala[$dte->cliente_sucursal_id]);
        $webPorZona = PedidoSala::query()
            ->whereHas('pedidoWeb', fn ($q) => $q->whereIn('dte_id', $this->webPorEntregar()->select('dtes.id')))
            ->pluck('ruta_id')
            ->countBy(fn ($ruta) => in_array((int) $ruta, $activas, true) ? (int) $ruta : 0);

        $resumen = [];

        foreach ([...$activas, 0] as $id) {
            $pendientes = $porZona->get($id, collect());
            $resumen[$id] = [
                'cantidad' => $pendientes->count(),
                'monto' => round((float) $pendientes->sum('total_pagar'), 2),
                'web' => $webPorZona->get($id, 0),
            ];
        }

        return $resumen;
    }

    // ================================================================ la salida

    /**
     * Alta manual por número de control, de cualquier sala. No exige la fecha de corte ni
     * que la sala sea de la ruta: es justamente para lo que se sale de la regla.
     *
     * @throws ValidationException
     */
    public function agregar(SalidaRuta $salida, string $numeroControl, ?User $usuario = null, ?int $ejercicio = null): SalidaRutaEntrega
    {
        $this->exigirAbierta($salida);
        $dte = $this->ccfCargable($numeroControl, $ejercicio);

        return $this->meterCcf($salida, $dte->id);
    }

    /**
     * Mete este CCF en la salida, con la salida y el DTE bloqueados y los mismos controles de
     * la carga: que no esté ya en ella, ni entregado, ni sin registrar en otra salida abierta.
     *
     * @throws ValidationException
     */
    public function meterCcf(SalidaRuta $salida, int $dteId): SalidaRutaEntrega
    {
        return DB::transaction(function () use ($salida, $dteId) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $this->exigirAbierta($salida);
            $dte = Dte::whereKey($dteId)->lockForUpdate()->firstOrFail();
            if (($motivo = $this->conflictoCarga($salida, $dte->id)) !== null) {
                throw ValidationException::withMessages(['numero_control' => $motivo]);
            }

            return $salida->entregas()->create([
                'dte_id' => $dte->id,
                'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            ]);
        });
    }

    /** Quita un CCF de la salida. Solo si nadie registró nada y la salida sigue abierta. */
    public function quitar(SalidaRutaEntrega $entrega): void
    {
        $this->exigirAbierta($entrega->salida);

        if (! $entrega->estaPendiente()) {
            throw ValidationException::withMessages([
                'entrega' => 'Ese CCF ya tiene un resultado registrado. Deshacelo primero si fue un error.',
            ]);
        }

        $entrega->delete();
    }

    // ================================================================ registrar

    /**
     * Anota qué pasó con un CCF.
     *
     * `forma_cobro` es cómo pagó la sala ESTA vez: una {@see FormaCobroEntrega} o
     * {@see self::SIN_COBRO} (quedan). Si no viene, se usa la habitual del cliente, salvo
     * cuando registra el vendedor en su viaje o en la automática: ahí es obligatoria (y en el
     * supermercado del portal siempre es sin cobro). No toca la ficha del cliente. En «Entregado», `nota` es la observación (una devolución); en
     * «No entregado», el detalle del motivo. `entregado_por_id` es quién lo entregó (o quién
     * fue, en «No entregado»): alguien que va en la salida. Obligatorio en los dos; los «No
     * entregado» de antes del 12/11/2026 pueden tenerlo vacío y se leen igual.
     *
     * Las claves que se leen: resultado, entregado_por_id, forma_cobro, trae_nota_averia,
     * motivo_no_entrega, nota y fecha_resultado. Llegan tal cual del pedido HTTP, así que
     * cualquiera puede venir en lista: se rechaza abajo antes de leerlas.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function registrar(SalidaRutaEntrega $entrega, array $datos, ?User $usuario, OrigenRegistroEntrega $origen): SalidaRutaEntrega
    {
        // Las pantallas mandan cada dato tal cual llega (`$request->only()`): uno en lista no
        // se convierte a texto (daba 500), se rechaza con aviso y sin anotar nada.
        foreach ($datos as $campo => $valor) {
            if (is_array($valor)) {
                throw ValidationException::withMessages([$campo => self::DATO_EN_LISTA]);
            }
        }

        return DB::transaction(function () use ($entrega, $datos, $usuario, $origen) {
            $original = $entrega;
            $salida = SalidaRuta::lockForUpdate()->findOrFail($entrega->salida_ruta_id);
            $entrega = SalidaRutaEntrega::lockForUpdate()->findOrFail($entrega->id);
            $this->exigirRegistrable($salida);
            if (! $entrega->estaPendiente()) {
                throw ValidationException::withMessages(['entrega' => 'Ese CCF ya tiene un resultado registrado. Deshacelo primero si fue un error.']);
            }

            $resultado = ResultadoEntrega::tryFrom((string) ($datos['resultado'] ?? ''))
                ?? throw ValidationException::withMessages(['resultado' => 'Indicá si se entregó o no.']);

            $comun = [
                'resultado' => $resultado,
                'fecha_resultado' => filled($datos['fecha_resultado'] ?? null) ? Carbon::parse($datos['fecha_resultado']) : now(),
                'origen_registro' => $origen,
                'registrado_por' => $usuario?->id,
            ];

            if ($resultado === ResultadoEntrega::Entregado) {
                // Solo lo entregado entra en el cierre del día. Un «no entregado» no mueve
                // dinero: se anota aunque el día esté terminado (p. ej. al terminar la ruta).
                $this->exigirDiaAbierto($salida, $comun['fecha_resultado']->toDateString());
                $dte = Dte::with('cliente')->whereKey($entrega->dte_id)->lockForUpdate()->firstOrFail();
                // El pedido web (16/11/2026), bloqueado: solo si sigue por entregar. `entregado_en`
                // se mira en la fila bloqueada, que en MySQL ve lo último confirmado.
                $web = PedidoWeb::where('dte_id', $dte->id)->lockForUpdate()->first();
                if ($web !== null && ($web->entregado_en !== null || $this->pendientesWeb($dte->id)->isEmpty())) {
                    throw ValidationException::withMessages(['entrega' => 'Ese pedido web no está por entregar.']);
                }
                if ($this->existeBloqueando(SalidaRutaEntrega::where('dte_id', $entrega->dte_id)->where('id', '!=', $entrega->id)->entregadas())) {
                    throw ValidationException::withMessages(['entrega' => 'Ese CCF ya consta como entregado en otra salida.']);
                }
                $personaId = (int) ($datos['entregado_por_id'] ?? 0);
                $this->exigirQuienEntrega($salida, $personaId, $usuario, 'Elegí quién lo entregó entre las personas que van en la salida.');

                // El supermercado que cobra por el portal (la sala con albarán) no se cobra al
                // entregar, por ninguna pantalla ni origen: la forma que llegue se ignora, y con
                // ella el quedan. El vendedor, en su viaje o en la automática, dice cómo le
                // pagaron: ahí no hay «la habitual del cliente» (el PDF del día es el control del
                // dinero). La regla va acá para que valga por cualquier pantalla. El pedido web ya
                // se pagó en la página: «Ya pagado», sin cobro, quedan, observación ni avería.
                if ($web !== null) {
                    $datos = ['quedan_numero' => null, 'quedan_fecha_pago' => null, 'nota' => null, 'trae_nota_averia' => false] + $datos;
                } elseif (DiaVendedor::esPortal($dte)) {
                    $datos = ['forma_cobro' => self::SIN_COBRO, 'quedan_numero' => null, 'quedan_fecha_pago' => null] + $datos;
                } elseif ($origen === OrigenRegistroEntrega::Vendedor && $salida->esAutomatica() && ! filled($datos['forma_cobro'] ?? null)) {
                    throw ValidationException::withMessages(['forma_cobro' => self::FALTA_FORMA_COBRO]);
                }
                $forma = $web !== null ? FormaCobroEntrega::YaPagado : $this->formaCobro($datos, $dte->cliente?->cobro_en_entrega);
                $quedan = $forma === null ? $this->quedan($datos) : ['quedan_numero' => null, 'quedan_fecha_pago' => null];
                // La observación de la entrega (una devolución, por ejemplo): opcional.
                $observacion = trim((string) ($datos['nota'] ?? '')) ?: null;
                if ($observacion !== null && mb_strlen($observacion) > 300) {
                    throw ValidationException::withMessages(['nota' => 'La observación puede tener hasta 300 caracteres.']);
                }

                $cobra = $forma !== null && $forma !== FormaCobroEntrega::YaPagado;
                $entrega->update($comun + [
                    'forma_cobro' => $forma,
                    'monto_esperado' => $cobra ? $dte->total_pagar : null,
                    'monto_cobrado' => $cobra ? $dte->total_pagar : null,
                    'entregado_por_id' => $personaId,
                    'trae_nota_averia' => filter_var($datos['trae_nota_averia'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'motivo_no_entrega' => null,
                    'nota' => $observacion,
                ] + $quedan);

                // La página se entera en la próxima pasada de tienda:traer-pedidos. Sin datos
                // personales en la auditoría: quién (la ficha) y la entrega; el cuándo es la fila.
                if ($web !== null) {
                    $web->update(['entregado_en' => $comun['fecha_resultado'], 'estado_por_informar' => 'entregado']);
                    activity('pedido_web')->performedOn($web)->causedBy($usuario)
                        ->withProperties(['estado' => 'entregado', 'entrega_id' => $entrega->id, 'entregado_por_id' => $personaId])
                        ->log('entregó el pedido web');
                }

                $original->setRawAttributes($entrega->getAttributes(), true);

                return $entrega;
            }

            $motivo = MotivoNoEntrega::tryFrom((string) ($datos['motivo_no_entrega'] ?? ''))
                ?? throw ValidationException::withMessages(['motivo_no_entrega' => 'Elegí por qué no se entregó.']);

            $nota = trim((string) ($datos['nota'] ?? '')) ?: null;

            // La columna es de 300: el `maxlength` del formulario no alcanza.
            if ($nota !== null && mb_strlen($nota) > 300) {
                throw ValidationException::withMessages(['nota' => 'La nota puede tener hasta 300 caracteres.']);
            }
            if ($motivo->exigeNota() && $nota === null) {
                throw ValidationException::withMessages(['nota' => 'Con «Otro», escribí qué pasó.']);
            }
            // Quién fue (12/11/2026): va en su PDF del día. Alguien de la salida, por cualquier
            // pantalla (antes la oficina podía dejarlo vacío).
            $fue = (int) ($datos['entregado_por_id'] ?? 0);
            $this->exigirQuienEntrega($salida, $fue, $usuario, 'Elegí quién fue entre las personas que van en la salida.');

            $entrega->update($comun + [
                'motivo_no_entrega' => $motivo,
                'nota' => $nota,
                'entregado_por_id' => $fue,
                'trae_nota_averia' => false,
            ]);

            $original->setRawAttributes($entrega->getAttributes(), true);

            return $entrega;
        });
    }

    /**
     * Quien entregó (o fue) va en la salida y puede figurar como el que entregó: un vendedor
     * activo o la ficha activa del que anota ({@see PersonalRuta::scopePuedenEntregar()}, la misma
     * regla de la tarjeta de «Por entregar»). Vale para el vendedor y para la oficina: una ficha
     * que se desactivó, o que no es de vendedor, no recibe la entrega aunque siga en la salida.
     *
     * @throws ValidationException
     */
    private function exigirQuienEntrega(SalidaRuta $salida, int $personaId, ?User $usuario, string $noVa): void
    {
        if (! $salida->participantes()->where('rutas_personal_id', $personaId)->exists()) {
            throw ValidationException::withMessages(['entregado_por_id' => $noVa]);
        }
        if (! PersonalRuta::query()->puedenEntregar($usuario)->whereKey($personaId)->exists()) {
            throw ValidationException::withMessages(['entregado_por_id' => 'Elegí a un vendedor activo: esa persona está inactiva o no es vendedor.']);
        }
    }

    /** Largo máximo del motivo de «Corregir quién». */
    public const MOTIVO_QUIEN_MAX = 200;

    /**
     * «Corregir quién» (16/11/2026): cambia solo a quién se atribuye lo anotado (quién lo
     * entregó o, en «No entregado», quién fue). Fecha, resultado, cobro y montos quedan igual,
     * así que vale también en un día cerrado o en una salida liquidada: lo que esas guardaron
     * son totales de la salida y del día, no de cada persona. El PDF del día de cada uno se
     * calcula al vuelo ({@see DiaVendedor}): pasa solo de uno al otro. La persona nueva sigue la
     * regla de siempre ({@see exigirQuienEntrega()}). Con la fila bloqueada y auditado, con
     * motivo.
     *
     * @throws ValidationException
     */
    public function corregirQuien(SalidaRutaEntrega $entrega, int $personaId, string $motivo, User $usuario): SalidaRutaEntrega
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > self::MOTIVO_QUIEN_MAX) {
            throw ValidationException::withMessages(['motivo' => 'Escribí un motivo corto, de hasta '.self::MOTIVO_QUIEN_MAX.' caracteres.']);
        }

        return DB::transaction(function () use ($entrega, $personaId, $motivo, $usuario) {
            $original = $entrega;
            $salida = SalidaRuta::lockForUpdate()->findOrFail($entrega->salida_ruta_id);
            $entrega = SalidaRutaEntrega::with('entregadoPor:id,nombre')->lockForUpdate()->findOrFail($entrega->id);
            if ($entrega->estaPendiente() || $entrega->esFacturadoDespues()) {
                throw ValidationException::withMessages(['entrega' => 'Ese CCF no tiene una entrega anotada: no hay a quién corregir.']);
            }
            if ($salida->estado === EstadoSalidaRuta::Cancelada) {
                throw ValidationException::withMessages(['entrega' => 'La salida está cancelada: no se corrige nada en ella.']);
            }
            if ((int) $entrega->entregado_por_id === $personaId) {
                throw ValidationException::withMessages(['entregado_por_id' => 'Esa persona ya figura: elegí a otra.']);
            }
            $this->exigirQuienEntrega($salida, $personaId, $usuario, 'Elegí a alguien que va en la salida.');

            $antes = $entrega->entregadoPor;
            // Una sola entrada en la bitácora, la de abajo, con el motivo y los nombres.
            $entrega->disableLogging()->update(['entregado_por_id' => $personaId]);
            $entrega->enableLogging()->load('entregadoPor:id,nombre');

            activity('salida_ruta_entrega')->performedOn($entrega)->causedBy($usuario)
                ->withProperties([
                    'dte_id' => $entrega->dte_id,
                    'resultado' => $entrega->resultado->value,
                    'fecha_resultado' => $entrega->fecha_resultado?->toDateTimeString(),
                    'antes' => ['id' => $antes?->id, 'nombre' => $antes?->nombre],
                    'despues' => ['id' => $entrega->entregadoPor->id, 'nombre' => $entrega->entregadoPor->nombre],
                    'motivo' => $motivo,
                ])
                ->log('corrigió quién lo entregó');

            $original->setRawAttributes($entrega->getAttributes(), true);

            return $entrega;
        });
    }

    /** @throws ValidationException */
    private function formaCobro(array $datos, ?FormaCobroEntrega $habitual): ?FormaCobroEntrega
    {
        $elegida = $datos['forma_cobro'] ?? null;

        return match (true) {
            $elegida === null => $habitual,
            $elegida === self::SIN_COBRO => null,
            // «Ya pagado» es solo del pedido web: una sala no se marca así ni a mano.
            $elegida === FormaCobroEntrega::YaPagado->value => throw ValidationException::withMessages(['forma_cobro' => 'Elegí una forma de pago de la lista.']),
            default => FormaCobroEntrega::tryFrom((string) $elegida)
                ?? throw ValidationException::withMessages(['forma_cobro' => 'Elegí una forma de pago de la lista.']),
        };
    }

    /**
     * El quedan que dio la sala (opcional): su número y la fecha de pago, que no puede ser
     * anterior a hoy.
     *
     * @return array{quedan_numero: ?string, quedan_fecha_pago: ?string}
     *
     * @throws ValidationException
     */
    private function quedan(array $datos): array
    {
        $validos = Validator::make([
            'quedan_numero' => filled($datos['quedan_numero'] ?? null) ? trim((string) $datos['quedan_numero']) : null,
            'quedan_fecha_pago' => filled($datos['quedan_fecha_pago'] ?? null) ? (string) $datos['quedan_fecha_pago'] : null,
        ], [
            'quedan_numero' => ['nullable', 'string', 'max:30'],
            'quedan_fecha_pago' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.HoraNegocio::fechaHoy()],
        ], [
            'quedan_numero.max' => 'El número del quedan puede tener hasta 30 caracteres.',
            'quedan_fecha_pago.date_format' => 'Elegí una fecha de pago válida.',
            'quedan_fecha_pago.after_or_equal' => 'La fecha de pago no puede ser anterior a hoy.',
        ])->validate();

        return ['quedan_numero' => $validos['quedan_numero'], 'quedan_fecha_pago' => $validos['quedan_fecha_pago']];
    }

    /** Vuelve a «sin registrar»: corrige un registro equivocado. */
    public function deshacer(SalidaRutaEntrega $entrega): void
    {
        DB::transaction(function () use ($entrega) {
            $original = $entrega;
            $salida = SalidaRuta::lockForUpdate()->findOrFail($entrega->salida_ruta_id);
            $entrega = SalidaRutaEntrega::lockForUpdate()->findOrFail($entrega->id);
            if ($entrega->esFacturadoDespues()) {
                throw ValidationException::withMessages(['entrega' => 'Ese CCF se anotó como facturado después de entregar (un registro de antes): no se deshace.']);
            }
            Dte::whereKey($entrega->dte_id)->lockForUpdate()->firstOrFail();
            CierreDia::exigirSinLiquidar($salida);
            if ($entrega->fecha_resultado) {
                $this->exigirDiaAbierto($salida, $entrega->fecha_resultado->toDateString());
            }
            if ($salida->estado === EstadoSalidaRuta::Cancelada) {
                throw ValidationException::withMessages(['entrega' => 'La salida está cancelada: no se corrige nada en ella.']);
            }

            // El pedido web vuelve a «Por entregar». A la página se le vuelve a decir «en_ruta»:
            // si ya recibió «entregado» lo rechaza (su seguimiento solo avanza) y no pasa nada.
            $web = PedidoWeb::where('dte_id', $entrega->dte_id)->lockForUpdate()->first();
            if ($web !== null && $entrega->resultado === ResultadoEntrega::Entregado) {
                $web->update(['entregado_en' => null, 'estado_por_informar' => 'en_ruta']);
                activity('pedido_web')->performedOn($web)->causedBy(auth()->user())
                    ->withProperties(['estado' => 'en_ruta', 'entrega_id' => $entrega->id])->log('deshizo la entrega del pedido web');
            }

            // La automática ({@see PorEntregar}) lleva solo lo marcado: si la fila quedara
            // pendiente, el CCF quedaría tomado por ella y ninguna otra salida lo podría llevar.
            // Se borra, así que no choca con que el CCF vaya en otra salida abierta; si la
            // salida queda vacía, se cancela.
            if ($salida->esAutomatica()) {
                $entrega->delete();
                $original->exists = false;
                $this->cancelarAutomaticaVacia($salida);

                return;
            }
            if (! $salida->estado->esTerminal() && $this->existeBloqueando($this->enOtraSalidaAbierta($salida, $entrega->dte_id))) {
                throw ValidationException::withMessages(['entrega' => 'Ese CCF ya va en otra salida abierta: no puede quedar pendiente en dos salidas.']);
            }

            $entrega->update([
                'resultado' => null,
                'motivo_no_entrega' => null,
                'nota' => null,
                'trae_nota_averia' => false,
                'entregado_por_id' => null,
                'fecha_resultado' => null,
                'origen_registro' => null,
                'registrado_por' => null,
                'forma_cobro' => null,
                'monto_esperado' => null,
                'monto_cobrado' => null,
                'quedan_numero' => null,
                'quedan_fecha_pago' => null,
            ]);
            $original->setRawAttributes($entrega->getAttributes(), true);
        });
    }

    /**
     * La automática en curso, o que se cerró sola, que quedó sin entregas, gastos, cierres
     * ni pedidos vigentes no fue una salida: se cancela (no cuenta como visita en el
     * tablero). El viaje con pedidos sigue: espera que se entreguen. Con
     * la salida ya bloqueada.
     */
    public function cancelarAutomaticaVacia(SalidaRuta $salida): bool
    {
        $anterior = $salida->estado;
        if (! $salida->esAutomatica() || ! ($anterior === EstadoSalidaRuta::EnCurso || $salida->seCerroSola())
            || $salida->entregas()->exists() || $salida->gastos()->exists() || $salida->cierres()->exists()
            || $salida->pedidos()->vigentes()->exists()) {
            return false;
        }
        $anterior === EstadoSalidaRuta::EnCurso ? $salida->cancelar() : $salida->anularAutomaticaFinalizada();
        activity('salida_ruta')->performedOn($salida)
            ->withProperties(['estado_anterior' => $anterior->value, 'estado_nuevo' => EstadoSalidaRuta::Cancelada->value, 'desde' => 'por_entregar'])
            ->log('se canceló sola: quedó vacía');

        return true;
    }

    // ================================================================ lectura

    /** La descripción del intento anterior que se reintenta ({@see PorEntregar}). */
    public const REINTENTO = 'reintento desde Por entregar';

    /** Desde cuántos «No se pudo» de la misma factura se avisa al jefe. */
    public const FALLAS_AVISO = 2;

    /**
     * Cuántas veces no se pudo entregar cada CCF: los «No se pudo» anotados y los que se
     * reintentaron después (el reintento reusa la fila y deja el intento anterior en la
     * bitácora). Dos consultas para todos. Con {@see self::FALLAS_AVISO} o más, el jefe lo ve.
     *
     * @param  array<int, int>  $dteIds
     * @return array<int, int> dte_id => fallas
     */
    public function fallas(array $dteIds): array
    {
        if ($dteIds === []) {
            return [];
        }
        $fallas = SalidaRutaEntrega::whereIn('dte_id', $dteIds)->where('resultado', ResultadoEntrega::NoEntregado->value)
            ->groupBy('dte_id')->selectRaw('dte_id, COUNT(*) as fallas')->pluck('fallas', 'dte_id')
            ->map(fn ($n) => (int) $n)->all();
        $buscados = array_flip($dteIds);
        Activity::query()->where('log_name', 'salida_ruta')->where('description', self::REINTENTO)
            ->where('created_at', '>=', Carbon::parse(HoraNegocio::fechaHoy())->subDays(self::DIAS_POR_ENTREGAR + 30))
            ->get(['id', 'properties'])
            ->each(function (Activity $a) use (&$fallas, $buscados) {
                $dte = (int) ($a->properties['dte_id'] ?? 0);
                if (isset($buscados[$dte])) {
                    $fallas[$dte] = ($fallas[$dte] ?? 0) + 1;
                }
            });

        return $fallas;
    }

    /**
     * Salas con albarán (el supermercado que cobra por el portal, {@see DiaVendedor::esPortal()})
     * que alguien anotó como entregadas y cuyo albarán de entrega todavía no cayó. No hay un
     * estado aparte: «esperando albarán» es «Entregado» con quién lo entregó y sin albarán
     * ({@see AlbaranLocalizador}). Con `$antesDe`, solo lo anotado antes de ese día: el
     * tablero avisa desde el día siguiente. De los últimos {@see self::DIAS_POR_ENTREGAR} días.
     *
     * @return Collection<int, SalidaRutaEntrega> con `dte`, `sala` y `entregadoPor`
     */
    public function esperandoAlbaran(?string $antesDe = null): Collection
    {
        $portal = DiaVendedor::clientePortal();
        if ($portal === null) {
            return collect();
        }
        $entregas = SalidaRutaEntrega::query()->entregadas()->sinFacturadosDespues()->whereNotNull('entregado_por_id')
            ->whereDate('fecha_resultado', '>=', Carbon::parse(HoraNegocio::fechaHoy())->subDays(self::DIAS_POR_ENTREGAR)->toDateString())
            ->when($antesDe !== null, fn ($q) => $q->whereDate('fecha_resultado', '<', $antesDe))
            ->whereHas('dte', fn ($q) => $q->where('cliente_id', $portal))
            ->with(['dte:id,numero_control,numero_orden_compra,total_pagar,cliente_id', 'sala:id,nombre', 'entregadoPor:id,nombre'])
            ->orderBy('fecha_resultado')->orderBy('id')
            ->get();
        $resoluciones = $this->resolucionesAlbaran($entregas);

        return $entregas->reject(fn (SalidaRutaEntrega $e) => ($resoluciones[$e->dte_id] ?? null)?->estaVinculado())->values();
    }

    /**
     * Salas con albarán cuyo albarán de entrega cayó hoy o ayer sin que nadie anotara quién las
     * entregó: ya constan entregadas y salieron de «Por entregar»; solo falta el nombre, que se
     * anota desde «Marcados hoy». Con `$dteId`, solo ese CCF (vacío si no está en la lista).
     *
     * @return EloquentCollection<int, Dte>
     */
    public function conAlbaranSinQuien(?int $dteId = null): EloquentCollection
    {
        $portal = DiaVendedor::clientePortal();
        if ($portal === null) {
            return new EloquentCollection;
        }
        $hoy = Carbon::parse(HoraNegocio::fechaHoy());
        $candidatos = $this->baseCcf()->where('cliente_id', $portal)->whereNotNull('cliente_sucursal_id')
            ->when($dteId !== null, fn ($q) => $q->whereKey($dteId))
            ->whereDate('fecha_emision', '>=', max($this->desde(), $hoy->copy()->subDays(self::DIAS_POR_ENTREGAR))->toDateString())
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('salida_ruta_entregas')
                    ->whereColumn('salida_ruta_entregas.dte_id', 'dtes.id')->where('salida_ruta_entregas.resultado', ResultadoEntrega::Entregado->value);
            })
            ->orderBy('fecha_emision')->orderBy('id')
            ->get();
        if ($candidatos->isEmpty()) {
            return $candidatos;
        }
        [$porDte, $porOrden] = $this->albaranes->indices($candidatos->pluck('id')->all(), $candidatos->pluck('numero_orden_compra')->all());
        $ayer = $hoy->subDay()->toDateString();

        return $candidatos->filter(function (Dte $d) use ($porDte, $porOrden, $ayer) {
            $resolucion = $this->albaranes->elegir($porDte, $porOrden, $d->id, $d->numero_orden_compra);

            return $resolucion->estaVinculado() && $resolucion->albaran->created_at !== null
                && HoraNegocio::aLocal($resolucion->albaran->created_at)->toDateString() >= $ayer;
        })->values();
    }

    /**
     * Qué dice el albarán de cada CCF de estas entregas. Una fila sin resultado propio y con
     * albarán de entrega cuenta como entregada.
     *
     * @param  Collection<int, SalidaRutaEntrega>  $entregas  con `dte` cargado
     * @return array<int, ResolucionAlbaran> dte_id => resolución
     */
    public function resolucionesAlbaran(Collection $entregas): array
    {
        $dtes = $entregas->pluck('dte')->filter();
        [$porDte, $porOrden] = $this->albaranes->indices($dtes->pluck('id')->all(), $dtes->pluck('numero_orden_compra')->all());

        $resoluciones = [];
        foreach ($dtes as $dte) {
            $resoluciones[$dte->id] = $this->albaranes->elegir($porDte, $porOrden, $dte->id, $dte->numero_orden_compra);
        }

        return $resoluciones;
    }

    /**
     * Resumen de una salida: total, entregados (incluye los confirmados por albarán), no
     * entregados y sin registrar.
     *
     * @param  Collection<int, SalidaRutaEntrega>  $entregas
     * @param  array<int, ResolucionAlbaran>  $resoluciones
     * @return array{total: int, entregados: int, no_entregados: int, sin_registrar: int}
     */
    public function resumen(Collection $entregas, array $resoluciones): array
    {
        // Los facturados después de entregar van aparte, en su propio bloque de la hoja.
        $entregas = $entregas->reject(fn (SalidaRutaEntrega $e) => $e->esFacturadoDespues());
        $porAlbaran = fn (SalidaRutaEntrega $e) => $e->estaPendiente() && ($resoluciones[$e->dte_id] ?? null)?->estaVinculado();

        return [
            'total' => $entregas->count(),
            'entregados' => $entregas->filter(fn ($e) => $e->resultado === ResultadoEntrega::Entregado || $porAlbaran($e))->count(),
            'no_entregados' => $entregas->filter(fn ($e) => $e->resultado === ResultadoEntrega::NoEntregado)->count(),
            'sin_registrar' => $entregas->filter(fn ($e) => $e->estaPendiente() && ! $porAlbaran($e))->count(),
        ];
    }

    /**
     * Última entrega conocida en cada sala: la más reciente entre lo que registró alguien y
     * lo que prueba un albarán de entrega vinculado a un CCF de esa sala. Con `$hasta`, solo
     * hasta ese día (el mapa de ventas lo pide así para un mes pasado).
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, Carbon> sala_id => fecha
     */
    public function ultimaVisitaPorSala(array $salaIds, ?Carbon $hasta = null): array
    {
        if ($salaIds === []) {
            return [];
        }

        $fechas = [];

        SalidaRutaEntrega::query()
            ->entregadas()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->when($hasta !== null, fn ($q) => $q->whereDate('fecha_resultado', '<=', $hasta->toDateString()))
            ->groupBy('cliente_sucursal_id')
            ->selectRaw('cliente_sucursal_id, MAX(fecha_resultado) as ultima')
            ->get()
            ->each(function ($fila) use (&$fechas) {
                $fechas[$fila->cliente_sucursal_id] = Carbon::parse($fila->ultima)->startOfDay();
            });

        // Albaranes de entrega vinculados por `dte_id`: es el vínculo explícito. La orden de
        // compra no se usa acá; basta con que el albarán diga de qué CCF es. Una fila por
        // sala (el más nuevo), no todos los albaranes: el tablero pide esto para todas las
        // salas. El tipo se compara en mayúsculas, como {@see PpqAlbaran::esDeEntrega()}.
        PpqAlbaran::query()
            ->join('dtes', 'dtes.id', '=', 'ppq_albaranes.dte_id')
            ->whereIn('dtes.cliente_sucursal_id', $salaIds)
            ->whereNotNull('ppq_albaranes.fecha_albaran')
            ->when($hasta !== null, fn ($q) => $q->whereDate('ppq_albaranes.fecha_albaran', '<=', $hasta->toDateString()))
            ->whereRaw('UPPER(ppq_albaranes.tipo_codigo) = ?', [PpqAlbaran::tipoDeEntrega()])
            ->groupBy('dtes.cliente_sucursal_id')
            ->toBase()
            ->selectRaw('dtes.cliente_sucursal_id as sala_id, MAX(ppq_albaranes.fecha_albaran) as ultima')
            ->get()
            ->each(function ($fila) use (&$fechas) {
                $fecha = Carbon::parse($fila->ultima)->startOfDay();
                $actual = $fechas[$fila->sala_id] ?? null;
                if ($actual === null || $fecha->gt($actual)) {
                    $fechas[$fila->sala_id] = $fecha;
                }
            });

        return $fechas;
    }

    public function desde(): Carbon
    {
        return Carbon::parse((string) Ajustes::texto('rutas.entregas_desde', '2026-09-28'))->startOfDay();
    }

    // ================================================================ apoyo

    /**
     * Los CCF que cuentan: aceptados de verdad por Hacienda, sin archivar, del ambiente
     * operativo. También es el filtro del ritmo de compra ({@see FuentePedidosCcf}).
     *
     * @return Builder<Dte>
     */
    public function baseCcf(): Builder
    {
        return Dte::query()
            ->where('tipo_dte', '03')
            ->where('ambiente', (string) (config('rutas.ambiente_ccf') ?: config('dte.ambiente')))
            ->noArchivados()
            ->aceptadoRealMh();
    }

    /**
     * El CCF por su número de control, si se puede cargar a una salida: CCF, aceptado por
     * Hacienda en este ambiente y sin albarán de entrega.
     *
     * @throws ValidationException
     */
    private function ccfCargable(string $numeroControl, ?int $ejercicio): Dte
    {
        $anios = $ejercicio === null ? IdentidadPpq::ejerciciosDe($numeroControl) : [];
        if (count($anios) > 1) {
            $ultimo = array_pop($anios);
            throw ValidationException::withMessages(['numero_control' => 'Ese número de control existe en '.implode(', ', $anios).' y '.$ultimo.': indicá el año.']);
        }
        $dte = IdentidadPpq::dteLocal($numeroControl, null, $ejercicio);

        $motivo = match (true) {
            $dte === null => 'No hay ningún documento con ese número de control.',
            $dte->tipo_dte?->value !== '03' => 'Ese documento no es un CCF.',
            ! $this->baseCcf()->whereKey($dte->id)->exists() => 'Ese CCF no está aceptado por Hacienda en este ambiente (borrador, rechazado, invalidado o de otro ambiente).',
            $this->albaranes->paraUno($dte->id, $dte->numero_orden_compra)->estaVinculado() => 'Ese CCF ya tiene albarán de entrega: consta como entregado.',
            default => null,
        };

        if ($motivo !== null) {
            throw ValidationException::withMessages(['numero_control' => $motivo]);
        }

        return $dte;
    }

    private function conflictoCarga(SalidaRuta $salida, int $dteId): ?string
    {
        return match (true) {
            $this->existeBloqueando(SalidaRutaEntrega::where('salida_ruta_id', $salida->id)->where('dte_id', $dteId)) => 'Ese CCF ya está en esta salida.',
            $this->existeBloqueando(SalidaRutaEntrega::where('dte_id', $dteId)->entregadas()) => 'Ese CCF ya consta como entregado.',
            $this->existeBloqueando($this->enOtraSalidaAbierta($salida, $dteId)) => 'Ese CCF ya va sin registrar en otra salida abierta.',
            default => null,
        };
    }

    /**
     * Lectura CON bloqueo: dentro de la transacción, en MySQL (REPEATABLE READ) una lectura
     * normal puede ver una foto vieja; la bloqueante ve lo último confirmado. Con `first()`
     * y no `exists()`, que pondría el FOR UPDATE dentro de una subconsulta.
     */
    private function existeBloqueando(Builder $consulta): bool
    {
        return $consulta->lockForUpdate()->first(['salida_ruta_entregas.id']) !== null;
    }

    private function enOtraSalidaAbierta(SalidaRuta $salida, int $dteId): Builder
    {
        return SalidaRutaEntrega::where('dte_id', $dteId)
            ->where('salida_ruta_id', '!=', $salida->id)
            ->pendientes()
            ->whereHas('salida', fn ($q) => $q->abiertas());
    }

    /**
     * @param  Collection<int, SalidaRutaEntrega>  $entregas  con `dte` cargado
     * @return array<int, string>
     */
    public function noVigentes(Collection $entregas): array
    {
        $vigentes = Dte::whereIn('id', $entregas->pluck('dte_id'))->aceptadoRealMh()->pluck('id')->all();

        return $entregas->reject(fn ($e) => in_array($e->dte_id, $vigentes, true))
            ->mapWithKeys(fn ($e) => [$e->dte_id => $e->dte?->estado === EstadoDte::Invalidado ? 'Invalidado' : 'No vigente'])
            ->all();
    }

    /**
     * @param  Collection<int, Dte>  $dtes
     * @return Collection<int, Dte>
     */
    private function sinAlbaranDeEntrega(Collection $dtes): Collection
    {
        if ($dtes->isEmpty()) {
            return $dtes;
        }

        [$porDte, $porOrden] = $this->albaranes->indices($dtes->pluck('id')->all(), $dtes->pluck('numero_orden_compra')->all());

        return $dtes
            ->reject(fn (Dte $d) => $this->albaranes->elegir($porDte, $porOrden, $d->id, $d->numero_orden_compra)->estaVinculado())
            ->values();
    }

    private function exigirAbierta(SalidaRuta $salida): void
    {
        if ($salida->estado->esTerminal()) {
            throw ValidationException::withMessages([
                'salida' => 'La salida ya está '.mb_strtolower($salida->estado->label()).': no se le agregan ni quitan CCF.',
            ]);
        }
    }

    private function exigirRegistrable(SalidaRuta $salida): void
    {
        if ($salida->estado !== EstadoSalidaRuta::EnCurso) {
            throw ValidationException::withMessages([
                'salida' => $salida->estado === EstadoSalidaRuta::Planificada
                    ? 'La salida quedó programada y ya no sale: no se registran entregas en ella.'
                    : ($salida->estado === EstadoSalidaRuta::Finalizada
                        ? 'La salida ya está finalizada: no se registran entregas en ella.'
                        : 'La salida está cancelada: no se registran entregas en ella.'),
            ]);
        }
    }

    private function exigirDiaAbierto(SalidaRuta $salida, string $fecha): void
    {
        // Lectura bloqueante: la transacción pudo abrir antes una foto de REPEATABLE READ.
        $cierre = $salida->cierres()->whereDate('fecha', $fecha)->lockForUpdate()->first();
        abort_if($cierre?->estaCerrado(), 403, 'Ese día de la salida ya está cerrado: sus entregas no se cambian.');
    }
}
