<?php

namespace App\Services\Rutas;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PedidoSalaLinea;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\PrecioProductoResolver;
use App\Support\Dte\CondicionOperacion;
use App\Support\OrdenCompra;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Facturar» lo que llegó de la fábrica para un pedido de sala. Solo la oficina: la fábrica
 * nunca factura. Primero llega el producto y después se factura: el CCF sale SIEMPRE con
 * todo lo que llegó y no se facturó (uno o varios envíos → un solo CCF); lo que la OC no
 * pide se baja a mano en el editor de siempre, y eso es lo que «regresa».
 *
 * El enlace va en `pedido_sala_envios.dte_id`. «Facturado» lo decide
 * `Dte::cuentaComoFacturado()`: un borrador borrado o un CCF invalidado o rechazado devuelve
 * el envío a «Por facturar» sin limpiar nada a mano. Salvo que otro CCF lo corrija (el que lo
 * reemplaza al invalidarlo, o su copia tras un rechazo): ese se queda con los envíos
 * ({@see pasarAlCcfQueCorrige()}).
 *
 * Nunca se crea un CCF incompleto: un producto sin precio, inactivo o borrado, o una
 * cantidad con decimales frena todo. Las cantidades se suman con bcmath y pasan a entero
 * exacto, sin redondear. El precio lo pone el servidor, como en el editor.
 */
class FacturarPedido
{
    public const YA_FACTURADO = 'Ya se facturó todo lo que llegó de este pedido.';

    public const NADA = 'Todavía no llegó nada de la fábrica para este pedido.';

    /** Lo que llegó después de crear el borrador va en ese mismo CCF, no en otro con la misma OC. */
    public const BORRADOR_ABIERTO = 'Este pedido ya tiene un borrador abierto. Borralo y tocá «Facturar» otra vez: así sale un solo CCF con todo lo que llegó.';

    /** En la tarjeta y el detalle del pedido: hay un borrador abierto y llegó otro envío. */
    public const LLEGO_DESPUES_DEL_BORRADOR = 'Llegó algo después del borrador. Borralo y tocá «Facturar» otra vez: así sale un solo CCF con todo.';

    /** Bitácora de {@see pasarAlCcfQueCorrige()}. */
    public const PASO_AL_QUE_CORRIGE = 'el CCF que corrige se quedó con lo que llegó del pedido';

    public function __construct(
        private readonly DteBorradorService $borradores,
        private readonly PrecioProductoResolver $precios,
    ) {}

    /**
     * Lo que se va a facturar, de solo lectura (formulario y «Lo que llegó»). `problemas`
     * frena «Crear borrador»; `oc_con_ccf` avisa si la OC del pedido ya tiene un CCF.
     *
     * @return array{
     *     envios: Collection<int, PedidoSalaEnvio>,
     *     lineas: list<array{producto: ?Producto, nombre: string, cantidad: string, entero: ?int, precio: ?string, origen: string, problema: ?string}>,
     *     problemas: list<string>,
     *     oc: ?string,
     *     oc_con_ccf: ?Dte,
     *     borrador: ?Dte,
     * }
     */
    public function propuesta(PedidoSala $pedido): array
    {
        $envios = $pedido->envios()->porFacturar()->orderBy('numero')->with('lineas.linea.producto')->get();
        $oc = filled($pedido->numero_orden_compra) ? $pedido->numero_orden_compra : null;

        return $this->armar($pedido, $envios) + [
            'envios' => $envios,
            'oc' => $oc,
            // Un CCF rechazado no avisa: no factura nada y hay que volver a facturar.
            'oc_con_ccf' => $oc === null ? null : (OrdenCompraRecibida::ccfPorNumero([OrdenCompra::normalizar($oc)])[OrdenCompra::normalizar($oc)] ?? null),
            'borrador' => $this->borradorAbierto($pedido),
        ];
    }

    /**
     * Crea el borrador del CCF con todo lo que llegó y no se facturó, y lo enlaza en esos
     * envíos. Un doble toque devuelve el mismo borrador. El cliente y la sala salen del
     * pedido: lo que mande el formulario se ignora.
     *
     * @param  array<string, mixed>  $datosCabecera  ya validados por FacturarPedidoRequest
     *
     * @throws ValidationException
     * @throws OrdenCompraRequeridaException
     */
    public function crear(PedidoSala $pedido, array $datosCabecera, User $usuario): Dte
    {
        return DB::transaction(function () use ($pedido, $datosCabecera, $usuario) {
            // Candados en el MISMO orden que PedidosFabrica::anularEnvio: pedido → líneas →
            // envíos. El primer statement es el del pedido (ver la nota de PedidosFabrica).
            $pedido = PedidoSala::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $pedido->lineas()->orderBy('id')->lockForUpdate()->get();
            $envios = $pedido->envios()->porFacturar()->orderBy('id')->lockForUpdate()->get();

            if ($envios->isEmpty()) {
                return $this->borradorAbierto($pedido) ?? throw ValidationException::withMessages([
                    'pedido' => $pedido->envios()->vigentes()->exists() ? self::YA_FACTURADO : self::NADA,
                ]);
            }
            if (! $pedido->estaVigente()) {
                throw ValidationException::withMessages(['pedido' => PedidosFabrica::ANULADO]);
            }
            if ($this->borradorAbierto($pedido) !== null) {
                throw ValidationException::withMessages(['pedido' => self::BORRADOR_ABIERTO]);
            }

            $envios->load('lineas.linea.producto');
            $propuesta = $this->armar($pedido, $envios);
            if ($propuesta['problemas'] !== []) {
                throw ValidationException::withMessages(['pedido' => $propuesta['problemas']]);
            }

            $sala = $pedido->sala;
            $dte = $this->borradores->crearBorrador(array_merge(
                Arr::except($datosCabecera, ['tipo_dte', 'cliente_id', 'cliente_sucursal_id', 'condicion_operacion', 'correlativo_id', 'descuento_global']),
                [
                    'tipo_dte' => TipoDte::CreditoFiscal->value,
                    'cliente_id' => $sala->cliente_id,
                    'cliente_sucursal_id' => $sala->id,
                    'condicion_operacion' => CondicionOperacion::para($sala->cliente, $sala),
                ],
            ), $usuario);
            foreach ($propuesta['lineas'] as $linea) {
                $this->borradores->establecerCantidadProducto($dte, $linea['producto'], $linea['entero']);
            }

            $anteriores = $envios->pluck('dte_id')->filter()->unique()->values()->all();
            PedidoSalaEnvio::whereKey($envios->modelKeys())->update(['dte_id' => $dte->id]);

            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties([
                    'dte_id' => $dte->id,
                    'numero_orden_compra' => $dte->numero_orden_compra,
                    'envios' => $envios->map(fn (PedidoSalaEnvio $e) => ['envio_id' => $e->id, 'numero' => $e->numero])->values()->all(),
                    'lineas' => array_map(fn (array $l) => ['producto_id' => $l['producto']->id, 'cantidad' => $l['entero']], $propuesta['lineas']),
                    // El CCF anterior (invalidado, rechazado o borrado) que tenían esos envíos.
                    'dte_anteriores' => $anteriores,
                ])
                ->log('facturó lo que llegó del pedido');

            return $dte;
        }, 3);
    }

    /**
     * El CCF $nuevo corrige a $anterior: lo reemplaza en una invalidación aceptada, o es su
     * copia («Duplicar») después de un rechazo. Los envíos vigentes que estaban en $anterior
     * pasan a $nuevo, para que no vuelvan a «Por facturar» con la mercadería ya facturada en
     * el nuevo (otro «Facturar» la cobraría dos veces). La bitácora del pedido guarda los dos.
     */
    public static function pasarAlCcfQueCorrige(Dte $anterior, Dte $nuevo, string $como, ?User $usuario = null): void
    {
        $envios = PedidoSalaEnvio::query()->vigentes()->where('dte_id', $anterior->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'pedido_sala_id', 'numero']);
        if ($envios->isEmpty()) {
            return;
        }
        PedidoSalaEnvio::whereKey($envios->modelKeys())->update(['dte_id' => $nuevo->id]);

        $pedidos = PedidoSala::query()->whereKey($envios->pluck('pedido_sala_id')->unique()->all())->get();
        foreach ($pedidos as $pedido) {
            $log = activity('pedido_sala')->performedOn($pedido)->withProperties([
                'dte_id' => $nuevo->id,
                'dte_anterior' => $anterior->id,
                'como' => $como,
                'envios' => $envios->where('pedido_sala_id', $pedido->id)
                    ->map(fn (PedidoSalaEnvio $e) => ['envio_id' => $e->id, 'numero' => $e->numero])->values()->all(),
            ]);
            if ($usuario !== null) {
                $log->causedBy($usuario);
            }
            $log->log(self::PASO_AL_QUE_CORRIGE);
        }
    }

    /**
     * Por producto, lo que «regresa»: lo que llegó en los envíos YA facturados menos lo que
     * quedó en sus CCF vigentes. Se calcula, no se guarda. Vacío si no hay nada facturado.
     *
     * @return array<int, array{llego: string, facturado: string, regresa: string}> por producto_id
     */
    public function regresa(PedidoSala $pedido): array
    {
        $envios = $pedido->envios()->vigentes()->whereNotNull('dte_id')
            ->whereHas('dte', fn ($d) => $d->cuentaComoFacturado())
            ->with('lineas.linea:id,producto_id')->get();
        if ($envios->isEmpty()) {
            return [];
        }

        $cero = ['llego' => '0.0000', 'facturado' => '0.0000'];
        $porProducto = [];
        foreach ($envios as $envio) {
            foreach ($envio->lineas as $l) {
                $id = $l->linea->producto_id;
                $porProducto[$id] ??= $cero;
                $porProducto[$id]['llego'] = bcadd($porProducto[$id]['llego'], (string) $l->cantidad, 4);
            }
        }
        $lineasCcf = DteLinea::query()->whereIn('dte_id', $envios->pluck('dte_id')->unique()->all())
            ->whereNotNull('producto_id')->get(['producto_id', 'cantidad']);
        foreach ($lineasCcf as $l) {
            $porProducto[$l->producto_id] ??= $cero;
            $porProducto[$l->producto_id]['facturado'] = bcadd($porProducto[$l->producto_id]['facturado'], (string) $l->cantidad, 4);
        }

        // Lo que regresa nunca es negativo: si el CCF lleva más de lo que llegó, no regresa
        // nada (el editor ya avisa que pasa de lo que llegó).
        return array_map(function (array $p) {
            $resto = bcsub($p['llego'], $p['facturado'], 4);

            return $p + ['regresa' => bccomp($resto, '0', 4) < 0 ? '0.0000' : $resto];
        }, $porProducto);
    }

    /**
     * Aviso SUAVE del editor (no frena la emisión): en un CCF que salió de envíos de la
     * fábrica, los envíos del mismo pedido que llegaron después y no están en él (para que
     * salga un solo CCF con todo), y los productos que pasan de lo que llegó o que no
     * vinieron. Vacío si el CCF no viene de ningún pedido. El editor lo vuelve a pedir con
     * cada cambio de cantidad (va en el panel que se repinta).
     *
     * @return list<string>
     */
    public function avisosDelCcf(Dte $dte): array
    {
        $envios = $dte->enviosPedido()->vigentes()->with('lineas.linea:id,producto_id')->get();
        if ($envios->isEmpty()) {
            return [];
        }

        $avisos = [];
        $despues = PedidoSalaEnvio::query()->whereIn('pedido_sala_id', $envios->pluck('pedido_sala_id')->unique()->all())
            ->porFacturar()->orderBy('numero')->pluck('numero');
        if ($despues->isNotEmpty()) {
            $avisos[] = ($despues->count() === 1 ? 'El envío ' : 'Los envíos ').$despues->join(', ', ' y ')
                .' de este pedido '.($despues->count() === 1 ? 'llegó después y no está' : 'llegaron después y no están').' en este borrador.'
                .' Para que salga un solo CCF con todo, eliminá este borrador en la lista de Facturación y tocá «Facturar» otra vez.';
        }

        $llego = [];
        foreach ($envios as $envio) {
            foreach ($envio->lineas as $l) {
                $id = $l->linea->producto_id;
                $llego[$id] = bcadd($llego[$id] ?? '0', (string) $l->cantidad, 4);
            }
        }

        foreach ($dte->lineas()->whereNotNull('producto_id')->orderBy('numero_linea')->get(['producto_id', 'descripcion', 'cantidad']) as $l) {
            $vino = $llego[$l->producto_id] ?? null;
            if ($vino === null) {
                $avisos[] = $l->descripcion.' no vino de la fábrica en este pedido.';
            } elseif (bccomp((string) $l->cantidad, $vino, 4) > 0) {
                $avisos[] = $l->descripcion.': van '.PedidoSalaLinea::legible((string) $l->cantidad).' y llegaron '.PedidoSalaLinea::legible($vino).'.';
            }
        }

        return $avisos;
    }

    /**
     * Cómo va la facturación de un pedido, con `envios.dte` ya cargados (sin consultas):
     * los envíos por facturar, el borrador abierto y los CCF ya hechos.
     *
     * @return array{por_facturar: Collection<int, PedidoSalaEnvio>, borrador: ?Dte, facturados: Collection<int, Dte>}
     */
    public static function estado(PedidoSala $pedido): array
    {
        $vigentes = $pedido->envios->whereNull('anulado_en');
        $dtes = $vigentes->filter(fn (PedidoSalaEnvio $e) => $e->estaFacturado())
            ->map(fn (PedidoSalaEnvio $e) => $e->dte)->unique('id')->sortBy('id')->values();

        return [
            'por_facturar' => $vigentes->reject(fn (PedidoSalaEnvio $e) => $e->estaFacturado())->values(),
            'borrador' => $dtes->first(fn (Dte $d) => $d->estado === EstadoDte::Borrador),
            'facturados' => $dtes->reject(fn (Dte $d) => $d->estado === EstadoDte::Borrador)->values(),
        ];
    }

    /**
     * Suma por producto y revisa lo que frena: producto borrado o inactivo, cantidad con
     * decimales o sin precio aplicable para la sala (el mismo criterio que el editor).
     *
     * @param  Collection<int, PedidoSalaEnvio>  $envios  con `lineas.linea.producto`
     * @return array{lineas: list<array<string, mixed>>, problemas: list<string>}
     */
    private function armar(PedidoSala $pedido, Collection $envios): array
    {
        $porProducto = [];
        foreach ($envios as $envio) {
            foreach ($envio->lineas as $l) {
                $id = $l->linea->producto_id;
                $porProducto[$id] ??= ['orden' => $l->linea->id, 'producto' => $l->linea->producto, 'cantidad' => '0.0000'];
                $porProducto[$id]['orden'] = min($porProducto[$id]['orden'], $l->linea->id);
                $porProducto[$id]['cantidad'] = bcadd($porProducto[$id]['cantidad'], (string) $l->cantidad, 4);
            }
        }
        uasort($porProducto, fn (array $a, array $b) => $a['orden'] <=> $b['orden']);

        $sala = $pedido->sala;
        $lineas = [];
        $problemas = [];
        foreach ($porProducto as $p) {
            $producto = $p['producto'];
            $nombre = $producto?->nombre ?? 'Un producto';
            $entero = self::entero($p['cantidad']);
            $precio = $producto ? $this->precios->resolverConOrigen($producto, $sala?->cliente_id, $sala?->id) : ['precio' => null, 'origen' => 'general'];
            $sinPrecio = $precio['precio'] === null || ! is_numeric($precio['precio']) || (float) $precio['precio'] <= 0;
            $problema = match (true) {
                $producto === null || $producto->trashed() => $nombre.' ya no está en Productos.',
                ! $producto->activo => $nombre.' está inactivo en Productos.',
                $entero === null => $nombre.' llegó con decimales ('.PedidoSalaLinea::legible($p['cantidad']).'): el CCF solo lleva cantidades enteras.',
                $sinPrecio => $nombre.' no tiene precio para esta sala.',
                default => null,
            };
            if ($problema !== null) {
                $problemas[] = $problema;
            }
            $lineas[] = [
                'producto' => $producto,
                'nombre' => $nombre,
                'cantidad' => $p['cantidad'],
                'entero' => $entero,
                'precio' => $sinPrecio ? null : (string) $precio['precio'],
                'origen' => $precio['origen'],
                'problema' => $problema,
            ];
        }

        return ['lineas' => $lineas, 'problemas' => $problemas];
    }

    /** La cantidad como entero EXACTO ('5.0000' → 5); null si tiene decimales. Nunca redondea. */
    private static function entero(string $cantidad): ?int
    {
        $entero = bcadd($cantidad, '0', 0);

        return bccomp($cantidad, $entero, 4) === 0 ? (int) $entero : null;
    }

    /** El borrador abierto que ya tienen los envíos vigentes del pedido, si hay. */
    private function borradorAbierto(PedidoSala $pedido): ?Dte
    {
        return Dte::query()
            ->whereIn('id', $pedido->envios()->vigentes()->whereNotNull('dte_id')->select('dte_id'))
            ->where('estado', EstadoDte::Borrador->value)
            ->latest('id')
            ->first();
    }
}
