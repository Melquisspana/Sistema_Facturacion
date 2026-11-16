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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportarPedidosWeb
{
    public function __construct(private readonly ConfiguracionTiendaWeb $configuracion) {}

    /** Una pasada completa. Nunca conserva el cursor entre pasadas. */
    public function ejecutar(bool $aplicar): array
    {
        if (! $this->configuracion->completo()) {
            throw new RuntimeException('La integración con la tienda no está completa.');
        }

        $conteo = ['importados' => 0, 'para_revisar' => 0, 'existentes' => 0, 'invalidos' => 0, 'avisos_pendientes' => 0];
        $intentados = [];
        try {
            $despues = 0;
            do {
                $respuesta = $this->http()->get($this->configuracion->url().'/api/sistema/pedidos-pagados', [
                    'despues' => $despues, 'limite' => 100,
                ]);
                if (! $respuesta->successful() || ! is_array($pedidos = $respuesta->json('pedidos')) || ! array_is_list($pedidos)) {
                    throw new RuntimeException('Respuesta de tienda inválida.');
                }
                foreach ($pedidos as $datos) {
                    // Sin un id entero no se puede seguir paginando: eso sí corta la pasada.
                    if (! is_array($datos) || ! is_int($datos['id'] ?? null) || $datos['id'] <= $despues) {
                        throw new RuntimeException('Paginación de tienda inválida.');
                    }
                    $despues = $datos['id'];
                    // Un pedido con formato inesperado se salta y se cuenta, sin datos personales en el
                    // registro: si cortara la pasada, como siempre se lee desde el principio, ningún
                    // pedido posterior entraría nunca.
                    if (! $this->esValido($datos)) {
                        $conteo['invalidos']++;

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
        } catch (Throwable) {
            // Ni el cuerpo remoto ni excepciones HTTP/SQL (con bindings) salen al log.
            throw new RuntimeException('No se pudo completar la lectura de pedidos de la tienda. Se reintentará en la próxima pasada.');
        } finally {
            if ($aplicar) {
                // También cubre la caída tras el POST remoto: puede ya no venir en el GET.
                PedidoWeb::where('estado', EstadoPedidoWeb::Importado)->whereNull('informado_preparando_en')
                    ->whereNotIn('id', $intentados)->eachById(fn (PedidoWeb $p) => $this->informar($p));
                try {
                    $this->http()->withBody('', 'application/json')->post($this->configuracion->url().'/api/sistema/limpieza');
                } catch (Throwable) {
                    // La limpieza es de mejor esfuerzo y no invalida lo importado.
                }
            }
        }
        if ($aplicar) {
            $conteo['avisos_pendientes'] = PedidoWeb::where('estado', EstadoPedidoWeb::Importado)->whereNull('informado_preparando_en')->count();
        }

        return $conteo;
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->configuracion->secreto())->acceptJson()->timeout(10)
            ->withOptions(['allow_redirects' => false]);
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
        if ($distrito === null) {
            return null;
        }

        return app(PropuestaRutas::class)->zonaDe(null, $distrito->id, $departamento->id)?->id;
    }

    private function informar(PedidoWeb $pedido): void
    {
        if ($pedido->estado !== EstadoPedidoWeb::Importado || $pedido->informado_preparando_en !== null) {
            return;
        }
        try {
            $respuesta = $this->http()->post($this->configuracion->url().'/api/sistema/pedidos/'.$pedido->web_id.'/estado', ['estado' => 'preparando']);
            if ($respuesta->status() === 200 || ($respuesta->status() === 409 && $respuesta->json('error') === 'estado')) {
                $pedido->update(['informado_preparando_en' => now()]);
            }
        } catch (Throwable) {
            // Se conserva sin informar para la próxima pasada. Nunca loguear la excepción.
        }
    }
}
