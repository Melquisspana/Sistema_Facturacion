<?php

namespace App\Services\TiendaWeb;

use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPedidoWeb;
use App\Enums\OrigenPedidoSala;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Services\Rutas\PropuestaRutas;
use App\Support\HoraNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Trae los pedidos pagados de la tienda. Con --aplicar deja además en la caché, para
 * los avisos de la tienda (TiendaWebSinRespuesta y TiendaWebFallas), cómo terminó la
 * lectura, los pedidos que la página dejó en revisión y su lista de errores. Solo
 * números, motivos de una lista fija y códigos de pedido: nada del cliente, del cuerpo
 * remoto ni de la excepción.
 */
class ImportarPedidosWeb
{
    /** {ok, fallo, fallo_desde, invalidos, invalidos_ids}: la última lectura buena y la falla que siguió. */
    public const CLAVE_LECTURA = 'tienda.lectura';

    /** {en, cobra, errores}: lo que contestó GET /api/sistema/errores, ya filtrado. */
    public const CLAVE_ERRORES = 'tienda.errores';

    /** Desde cuándo se vigila si nunca hubo lectura: da la gracia tras vaciar la caché. */
    public const CLAVE_VIGILANDO = 'tienda.vigilando';

    /** {en, n, codigos}: pedidos que la página dejó en revisión y no llegaron al sistema. */
    public const CLAVE_REVISION = 'tienda.revision';

    /** Cada cuánto se pide esa lista ({@see self::revisionAlDia()}). */
    public const MINUTOS_REVISION = 5;

    /** Cuándo se pidió la última limpieza a la página; vence sola a la hora. */
    public const CLAVE_LIMPIEZA = 'tienda.limpieza';

    private const MINUTOS_LIMPIEZA = 60;

    /** Los únicos tipos que se aceptan de la lista de errores de la página. */
    public const TIPOS_ERROR = ['error_cobro', 'error_interno', 'error_consulta', 'consulta_fallida', 'firma_invalida', 'vencido_sin_confirmar'];

    private const NOMBRADOS = 3;

    private const MAX_ERRORES = 20;

    /** Más que esto no cambia el aviso y, sumado entre filas, desbordaría el conteo. */
    private const MAX_CANTIDAD = 1_000_000;

    private const CODIGO = '/^LN-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/D';

    public function __construct(private readonly ConfiguracionTiendaWeb $configuracion) {}

    /** Una pasada completa. Nunca conserva el cursor entre pasadas. */
    public function ejecutar(bool $aplicar): array
    {
        if (! $this->configuracion->completo()) {
            if ($aplicar) {
                $this->anotarFallo('configuracion');
            }
            throw new RuntimeException('La integración con la tienda no está completa.');
        }

        $conteo = ['importados' => 0, 'para_revisar' => 0, 'existentes' => 0, 'invalidos' => 0, 'avisos_pendientes' => 0];
        $intentados = [];
        $invalidosIds = [];
        // Por qué se cortó, de una lista fija: nunca del cuerpo ni de la excepción.
        $motivo = null;
        try {
            $despues = 0;
            do {
                $respuesta = $this->http()->get($this->configuracion->url().'/api/sistema/pedidos-pagados', [
                    'despues' => $despues, 'limite' => 100,
                ]);
                if (! $respuesta->successful()) {
                    $motivo = match (true) {
                        $respuesta->status() === 401 => 'clave',
                        $respuesta->status() === 503 => 'apagada',
                        $respuesta->redirect() || in_array($respuesta->status(), [404, 405], true) => 'direccion',
                        default => 'error',
                    };
                    throw new RuntimeException('Respuesta de tienda inválida.');
                }
                if (! is_array($pedidos = $respuesta->json('pedidos')) || ! array_is_list($pedidos)) {
                    $motivo = 'respuesta';
                    throw new RuntimeException('Respuesta de tienda inválida.');
                }
                foreach ($pedidos as $datos) {
                    // Sin un id entero no se puede seguir paginando: eso sí corta la pasada.
                    if (! is_array($datos) || ! is_int($datos['id'] ?? null) || $datos['id'] <= $despues) {
                        $motivo = 'respuesta';
                        throw new RuntimeException('Paginación de tienda inválida.');
                    }
                    $despues = $datos['id'];
                    // Un pedido con formato inesperado se salta y se cuenta, sin datos personales en el
                    // registro: si cortara la pasada, como siempre se lee desde el principio, ningún
                    // pedido posterior entraría nunca.
                    if (! $this->esValido($datos)) {
                        $conteo['invalidos']++;
                        if (count($invalidosIds) < self::NOMBRADOS) {
                            $invalidosIds[] = $datos['id'];
                        }

                        continue;
                    }
                    $existente = PedidoWeb::where('web_id', $datos['id'])->exists();
                    if ($aplicar) {
                        $pedido = $this->importar($datos);
                        $conteo[$existente ? 'existentes' : ($pedido->estado === EstadoPedidoWeb::Importado ? 'importados' : 'para_revisar')]++;
                        $intentados[] = $pedido->id;
                        // El HTTP nunca participa en la transacción que crea el pedido.
                        DB::afterCommit(fn () => $this->informar($pedido));
                    } else {
                        [, $motivo] = $this->resolverLineas($datos);
                        $conteo[$existente ? 'existentes' : ($motivo === null ? 'importados' : 'para_revisar')]++;
                    }
                }
            } while (count($pedidos) === 100);
            if ($aplicar) {
                // Solo una lectura completa: una a medias nunca baja los que no se pudieron leer.
                $this->guardar(self::CLAVE_LECTURA, [
                    'ok' => now()->getTimestamp(), 'fallo' => null, 'fallo_desde' => null,
                    'invalidos' => $conteo['invalidos'], 'invalidos_ids' => $invalidosIds,
                ]);
            }
        } catch (Throwable $e) {
            if ($aplicar) {
                $this->anotarFallo($motivo ?? ($e instanceof ConnectionException ? 'sin_conexion' : 'sistema'));
            }
            // Ni el cuerpo remoto ni excepciones HTTP/SQL (con bindings) salen al log.
            throw new RuntimeException('No se pudo completar la lectura de pedidos de la tienda. Se reintentará en la próxima pasada.');
        } finally {
            if ($aplicar) {
                $this->traerAlertas();
                // Con su propio catch: si la página no tiene esta ruta, la importación no se marca caída.
                $this->traerErrores();
                // También cubre la caída tras el POST remoto: puede ya no venir en el GET.
                // Por pedido de fábrica y no por estado: la factura (etapa 2) lo mueve a
                // «facturando» o «facturado» aunque el aviso todavía no haya salido.
                PedidoWeb::whereNotNull('pedido_sala_id')->whereNull('informado_preparando_en')
                    ->whereNotIn('id', $intentados)->eachById(fn (PedidoWeb $p) => $this->informar($p));
                $this->pedirLimpieza();
            }
        }
        if ($aplicar) {
            $conteo['avisos_pendientes'] = PedidoWeb::whereNotNull('pedido_sala_id')->whereNull('informado_preparando_en')->count();
        }

        return $conteo;
    }

    private function http(): PendingRequest
    {
        return $this->configuracion->cliente();
    }

    /** La última lectura buena y los ilegibles se conservan; el desde de la falla, también. */
    private function anotarFallo(string $motivo): void
    {
        $anterior = rescue(fn () => Cache::get(self::CLAVE_LECTURA), null, report: false);
        $anterior = is_array($anterior) ? $anterior : [];
        $this->guardar(self::CLAVE_LECTURA, [
            'ok' => $anterior['ok'] ?? null, 'fallo' => $motivo,
            'fallo_desde' => $anterior['fallo_desde'] ?? now()->getTimestamp(),
            'invalidos' => $anterior['invalidos'] ?? 0, 'invalidos_ids' => $anterior['invalidos_ids'] ?? [],
        ]);
    }

    /**
     * La limpieza de la página vence, vacía y borra releyendo tablas enteras (Cloudflare
     * cobra por fila leída) y le pregunta a Wompi por los pendientes: como mucho una vez
     * por hora, salga bien o mal. Sin caché donde anotarla se pide igual, como antes: los
     * datos personales vencidos de la página no se quedan sin borrar. Es de mejor
     * esfuerzo y no invalida lo importado.
     */
    private function pedirLimpieza(): void
    {
        $toca = rescue(fn () => Cache::add(self::CLAVE_LIMPIEZA, now()->getTimestamp(), now()->addMinutes(self::MINUTOS_LIMPIEZA)), true, report: false);
        if (! $toca) {
            return;
        }
        try {
            $this->http()->withBody('', 'application/json')->post($this->configuracion->url().'/api/sistema/limpieza');
        } catch (Throwable) {
        }
    }

    /** Sin caché no hay dónde anotar: la pasada sigue su curso y la excepción no sale. */
    private function guardar(string $clave, array $valor): void
    {
        try {
            Cache::forever($clave, $valor);
        } catch (Throwable) {
        }
    }

    private function esValido(array $datos): bool
    {
        return ! Validator::make($datos, [
            'id' => ['required', 'integer', 'min:1'],
            'codigo' => ['required', 'string', 'regex:/^LN-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/D'],
            'idioma' => ['required', 'in:es,en'],
            'pagado' => ['required', 'date'],
            'alerta' => ['present', 'nullable', 'in:pago_doble'],
            'total' => ['required', 'integer', 'min:0'],
            'moneda' => ['required', 'in:USD'],
            'cliente.nombre' => ['required', 'string', 'max:100'],
            'cliente.correo' => ['required', 'email', 'max:255'],
            'cliente.telefono' => ['required', 'string', 'max:40'],
            'entrega.tipo' => ['required', 'in:ruta,recoger'],
            'entrega.departamento' => ['nullable', 'regex:/^\d{2}$/D'],
            'entrega.municipio' => ['nullable', 'string', 'max:100'],
            'entrega.direccion' => ['nullable', 'string', 'max:1000'],
            'entrega.notas' => ['nullable', 'string', 'max:2000'],
            'regalo' => ['nullable', 'array'],
            'cajitas_detalle' => ['present', 'array'],
            'lineas' => ['present', 'array'],
            'pasarela' => ['required', 'string', 'max:30'],
            'referencia' => ['nullable', 'string', 'max:255'],
        ])->fails();
    }

    private function importar(array $datos): PedidoWeb
    {
        return DB::transaction(function () use ($datos) {
            // La restricción única arbitra también dos procesos que no encontraron fila.
            $pedido = PedidoWeb::where('web_id', $datos['id'])->lockForUpdate()->first();
            if ($pedido !== null) {
                return $pedido;
            }
            $pedido = PedidoWeb::query()->createOrFirst(['web_id' => $datos['id']], [
                'codigo' => $datos['codigo'], 'idioma' => $datos['idioma'],
                'pagado_en' => Carbon::parse($datos['pagado'])->setTimezone(config('app.timezone')),
                'alerta' => $datos['alerta'] ?? '', 'total_centavos' => $datos['total'], 'moneda' => $datos['moneda'],
                'cliente_nombre' => $datos['cliente']['nombre'], 'cliente_correo' => $datos['cliente']['correo'],
                'cliente_telefono' => $datos['cliente']['telefono'], 'entrega_tipo' => $datos['entrega']['tipo'],
                'departamento_codigo' => $datos['entrega']['departamento'] ?? null,
                'municipio' => $datos['entrega']['municipio'] ?? null, 'direccion' => $datos['entrega']['direccion'] ?? null,
                'notas' => $datos['entrega']['notas'] ?? null, 'regalo' => $datos['regalo'] ?? null,
                'cajitas_detalle' => $datos['cajitas_detalle'], 'lineas' => $datos['lineas'],
                'pasarela' => $datos['pasarela'], 'referencia' => $datos['referencia'] ?? null,
                'estado' => EstadoPedidoWeb::ParaRevisar,
            ]);
            if (! $pedido->wasRecentlyCreated) {
                return PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            }

            [$lineas, $motivo] = $this->resolverLineas($datos);
            if ($motivo !== null) {
                $pedido->update(['motivo_revision' => $motivo]);
            } else {
                $sala = PedidoSala::create([
                    'origen' => OrigenPedidoSala::Web, 'cliente_sucursal_id' => null,
                    'registrado_por' => null, 'sacado_por_id' => null,
                    'fecha' => HoraNegocio::aLocal(Carbon::parse($datos['pagado']))->toDateString(),
                    'estado' => EstadoPedidoSala::Registrado, 'ruta_id' => $this->zona($datos),
                ]);
                foreach ($lineas as $productoId => $cantidad) {
                    $sala->lineas()->create(['producto_id' => $productoId, 'cantidad' => $cantidad]);
                }
                $pedido->update(['pedido_sala_id' => $sala->id, 'estado' => EstadoPedidoWeb::Importado]);
            }
            activity('pedido_web')->performedOn($pedido)->causedByAnonymous()
                ->withProperties(['web_id' => $pedido->web_id, 'codigo' => $pedido->codigo, 'origen' => 'tienda_web'])
                ->log($motivo === null ? 'importó pedido web' : 'pedido web para revisar');

            return $pedido;
        });
    }

    /** Cantidades estrictas, agregadas antes del unique(pedido, producto). */
    private function resolverLineas(array $datos): array
    {
        $lineas = [];
        if (! is_int($datos['unidades'] ?? null) || $datos['unidades'] < 1 || $datos['lineas'] === []) {
            return [[], 'Unidades o líneas inválidas.'];
        }
        foreach ($datos['lineas'] as $linea) {
            if (! is_array($linea) || ! is_int($linea['cantidad'] ?? null) || $linea['cantidad'] < 1
                || $linea['cantidad'] > PedidoSala::CANTIDAD_MAXIMA) {
                return [[], 'La cantidad debe ser entera, positiva y estar dentro del límite.'];
            }
            $barra = $linea['barra'] ?? null;
            $producto = is_string($barra) && $barra !== '' ? Producto::where('codigo_barra', $barra)->first() : null;
            if ($producto === null) {
                return [[], 'No se encontró un producto por su código de barras.'];
            }
            $lineas[$producto->id] = ($lineas[$producto->id] ?? 0) + $linea['cantidad'];
            if ($lineas[$producto->id] > PedidoSala::CANTIDAD_MAXIMA) {
                return [[], 'La cantidad por producto supera el límite.'];
            }
        }
        if (array_sum($lineas) !== $datos['unidades']) {
            return [[], 'La suma de cantidades no coincide con las unidades.'];
        }

        return [$lineas, null];
    }

    private function zona(array $datos): ?int
    {
        if ($datos['entrega']['tipo'] === 'recoger') {
            return null;
        }
        $departamento = Departamento::where('codigo', $datos['entrega']['departamento'] ?? '')->first();
        $nombre = Str::lower(Str::ascii(trim($datos['entrega']['municipio'] ?? '')));
        $distrito = $departamento ? Distrito::where('departamento_id', $departamento->id)->get()
            ->first(fn (Distrito $d) => Str::lower(Str::ascii(trim($d->nombre))) === $nombre) : null;
        if ($departamento === null) {
            return null;
        }

        return app(PropuestaRutas::class)->zonaDe(null, $distrito?->id, $departamento->id)?->id;
    }

    private function traerAlertas(): void
    {
        $revision = 0;
        $codigos = [];
        try {
            if ($this->revisionAlDia()) {
                return;
            }
            $despues = 0;
            do {
                $respuesta = $this->http()->get($this->configuracion->url().'/api/sistema/pedidos-a-revisar', ['despues' => $despues]);
                $pedidos = $respuesta->json('pedidos');
                if ($respuesta->status() !== 200 || ! is_array($pedidos) || ! array_is_list($pedidos)) {
                    return;
                }
                foreach ($pedidos as $datos) {
                    if (! is_array($datos) || ! is_int($datos['id'] ?? null) || $datos['id'] <= $despues) {
                        return;
                    }
                    $despues = $datos['id'];
                    if (($datos['alerta'] ?? '') === 'pago_doble') {
                        PedidoWeb::where('web_id', $datos['id'])->where(fn ($q) => $q->whereNull('alerta')->orWhere('alerta', ''))
                            ->update(['alerta' => 'pago_doble']);
                    }
                    // En revisión en la página y nunca llegado al sistema: el pago no se pudo confirmar.
                    if (($datos['estado'] ?? null) === 'para_revisar' && ! in_array($datos['pasarela'] ?? null, ['demo', 'wompi-prueba'], true)
                        && ! PedidoWeb::where('web_id', $datos['id'])->exists()) {
                        $revision++;
                        if (count($codigos) < self::NOMBRADOS && is_string($datos['codigo'] ?? null) && preg_match(self::CODIGO, $datos['codigo']) === 1) {
                            $codigos[] = $datos['codigo'];
                        }
                    }
                }
            } while (count($pedidos) === 100);
            // Solo si se recorrió la lista completa: una a medias no baja el número.
            Cache::forever(self::CLAVE_REVISION, ['en' => now()->getTimestamp(), 'n' => $revision, 'codigos' => $codigos]);
        } catch (Throwable) {
            // Mejor esfuerzo: se vuelve a consultar en la próxima pasada.
        }
    }

    /**
     * La lista de pedidos a revisar recorre la tabla entera de la página (Cloudflare cobra
     * por fila leída): se pide cada MINUTOS_REVISION. Su «en» solo se escribe con la lista
     * completa, así que una que no llegó se vuelve a pedir en la pasada siguiente. Con un
     * pedido importado sin borrador de factura, en cada pasada: su pago doble la frena.
     */
    private function revisionAlDia(): bool
    {
        $en = Cache::get(self::CLAVE_REVISION)['en'] ?? null;

        return is_int($en) && $en > now()->subMinutes(self::MINUTOS_REVISION)->getTimestamp()
            && ! PedidoWeb::where('estado', EstadoPedidoWeb::Importado)->exists();
    }

    /**
     * La lista de errores de la página (GET /api/sistema/errores), fila por fila: solo
     * tipos conocidos, cantidades enteras, fechas válidas y un detalle corto de
     * caracteres comunes y sin código de pedido. Si la respuesta no sirve (una página
     * vieja sin la ruta, un error), la caché no se toca y el aviso la ve envejecer.
     */
    private function traerErrores(): void
    {
        try {
            $respuesta = $this->http()->get($this->configuracion->url().'/api/sistema/errores');
            $errores = $respuesta->json('errores');
            $cobra = $respuesta->json('cobra');
            if ($respuesta->status() !== 200 || ! is_array($errores) || ! array_is_list($errores) || ! is_bool($cobra)) {
                return;
            }
            $filas = [];
            foreach ($errores as $fila) {
                if (count($filas) === self::MAX_ERRORES) {
                    break;
                }
                if (! is_array($fila) || ! in_array($fila['tipo'] ?? null, self::TIPOS_ERROR, true)
                    || ! is_int($fila['cantidad'] ?? null) || $fila['cantidad'] < 1
                    || ($primera = $this->fecha($fila['primera'] ?? null)) === null
                    || ($ultima = $this->fecha($fila['ultima'] ?? null)) === null) {
                    continue;
                }
                $detalle = $fila['detalle'] ?? '';
                if (! is_string($detalle) || mb_strlen($detalle) > 120 || str_contains($detalle, 'LN-')
                    || preg_match('/^[\p{L}\p{N} .:·_\/-]*$/u', $detalle) !== 1) {
                    $detalle = '';
                }
                $filas[] = ['tipo' => $fila['tipo'], 'detalle' => $detalle, 'cantidad' => min($fila['cantidad'], self::MAX_CANTIDAD), 'primera' => $primera, 'ultima' => $ultima];
            }
            Cache::put(self::CLAVE_ERRORES, ['en' => now()->getTimestamp(), 'cobra' => $cobra, 'errores' => $filas], now()->addDay());
        } catch (Throwable) {
            // Mejor esfuerzo y sin log: la lista vieja envejece y eso es lo que avisa.
        }
    }

    /** Una fecha ISO de la página («2026-11-19T20:00:00.000Z») como marca de tiempo. */
    private function fecha(mixed $texto): ?int
    {
        if (! is_string($texto) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $texto) !== 1) {
            return null;
        }

        return rescue(fn () => Carbon::parse($texto)->getTimestamp(), null, report: false);
    }

    private function informar(PedidoWeb $pedido): void
    {
        // Solo los que llegaron a fábrica; el estado puede ya ser de la factura (etapa 2).
        if ($pedido->pedido_sala_id === null || $pedido->informado_preparando_en !== null
            || RespuestaAvisoPedidoWeb::rechazado($pedido, 'preparando')) {
            return;
        }
        try {
            $respuesta = $this->http()->post($this->configuracion->url().'/api/sistema/pedidos/'.$pedido->web_id.'/estado', ['estado' => 'preparando']);
            if (RespuestaAvisoPedidoWeb::estadoAceptado($respuesta, 'preparando')) {
                $pedido->update(['informado_preparando_en' => now()]);
            } else {
                RespuestaAvisoPedidoWeb::anotar($pedido, $respuesta, 'preparando');
            }
        } catch (Throwable) {
            // Se conserva sin informar para la próxima pasada. Nunca loguear la excepción.
        }
    }
}
