<?php

namespace App\Services\TiendaWeb;

use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoWeb;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteEmisionEnCursoException;
use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Exceptions\TiendaWeb\FacturaWebNoArmadaException;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\EnvioDteCorreoService;
use App\Services\Dte\FirmaTransmisionDte;
use App\Support\Dinero;
use App\Support\Dte\CandadoEmisionDte;
use App\Support\Dte\CondicionOperacion;
use App\Support\Dte\ResuelveEmisorUnico;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Factura (01) automática de un pedido de la tienda web (ADR 0011). Uno por pedido e
 * idempotente: se puede pasar el mismo pedido las veces que haga falta y nunca crea una
 * segunda factura ni repite el correo o el aviso a la página.
 *
 *  1. Importado: los candados de la 0011, con la fila del pedido bloqueada. Si alguno
 *     falla queda «para revisar» y no se crea nada. Si pasan, el borrador, su dte_id y
 *     el estado «facturando» se guardan en UNA transacción.
 *  2. Facturando: generar, firmar y transmitir bajo el candado del documento, por el
 *     mismo camino que la oficina ({@see FirmaTransmisionDte}). Cualquier falla deja el
 *     pedido como está para la pasada siguiente.
 *  3. Facturado: el correo al cliente y el aviso a la página, una vez cada uno.
 *
 * Sin usuario: la emisión no tiene persona responsable. Nunca se registra el JSON, el
 * secreto ni datos del cliente.
 */
class FacturarPedidoWeb
{
    /** Tarjeta de crédito (CAT-017). Pendiente de confirmar con la contadora si va 02 o 03. */
    public const FORMA_PAGO = '03';

    /** Contado (CAT-016). {@see CondicionOperacion::para()} sin cliente daría crédito. */
    private const CONTADO = 1;

    /**
     * Entre un intento de emisión y el siguiente del mismo pedido. La tarea corre cada
     * minuto: sin esta espera, un documento que no sale (Hacienda caída, firmador apagado)
     * se le mandaría a Hacienda una y otra vez, cada minuto.
     */
    public const MINUTOS_ENTRE_INTENTOS = 5;

    public function __construct(
        private readonly DteBorradorService $borradores,
        private readonly DteGeneracionService $generacion,
        private readonly FirmaTransmisionDte $firmaTransmision,
        private readonly EnvioDteCorreoService $correos,
        private readonly ConfiguracionTiendaWeb $tienda,
    ) {}

    /**
     * Recorre los pedidos con algo pendiente. Sin $aplicar solo cuenta: no emite, no
     * escribe y no llama a nadie.
     *
     * @return array<string, int>
     */
    public function pasada(bool $aplicar): array
    {
        $pendientes = PedidoWeb::query()->where(fn ($q) => $q
            ->whereIn('estado', [EstadoPedidoWeb::Importado, EstadoPedidoWeb::Facturando])
            ->orWhere(fn ($q) => $q->where('estado', EstadoPedidoWeb::Facturado)
                ->where(fn ($q) => $q->whereNull('factura_correo_en')->orWhereNull('informado_factura_en'))));

        if (! $aplicar) {
            return ['por_facturar' => $pendientes->count()];
        }

        $conteo = ['facturados' => 0, 'facturando' => 0, 'facturas_para_revisar' => 0, 'facturas_con_error' => 0];
        $pendientes->eachById(function (PedidoWeb $pedido) use (&$conteo) {
            try {
                $estado = $this->procesar($pedido);
            } catch (Throwable $e) {
                // Un pedido roto no frena a los demás. Del error, solo la clase: el mensaje
                // y los bindings pueden traer datos del cliente.
                Log::warning('Pedido web: no se pudo procesar su factura', ['pedido_web_id' => $pedido->id, 'excepcion' => class_basename($e)]);
                $conteo['facturas_con_error']++;

                return;
            }
            $conteo[match ($estado) {
                EstadoPedidoWeb::Facturado => 'facturados',
                EstadoPedidoWeb::ParaRevisar => 'facturas_para_revisar',
                default => 'facturando',
            }]++;
        });

        return $conteo;
    }

    /** Lleva el pedido tan lejos como se pueda en esta pasada y devuelve dónde quedó. */
    public function procesar(PedidoWeb $pedido): EstadoPedidoWeb
    {
        $pedido->refresh();
        if ($pedido->estado === EstadoPedidoWeb::Importado) {
            $this->prepararBorrador($pedido);
            $pedido->refresh();
        }
        if ($pedido->estado === EstadoPedidoWeb::Facturando) {
            $this->emitir($pedido);
            $pedido->refresh();
        }
        if ($pedido->estado === EstadoPedidoWeb::Facturado) {
            $this->enviarCorreo($pedido);
            $this->avisarPagina($pedido);
        }

        return $pedido->estado;
    }

    /** Candados y borrador, con la fila bloqueada. Si el borrador no cuadra, se revierte todo. */
    private function prepararBorrador(PedidoWeb $pedido): void
    {
        try {
            DB::transaction(function () use ($pedido) {
                $fila = PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
                if ($fila->estado !== EstadoPedidoWeb::Importado) {
                    return; // otra pasada ya lo tomó
                }
                [$lineas, $motivo] = $this->revisarCandados($fila);
                if ($motivo !== null) {
                    $this->paraRevisar($fila, $motivo);

                    return;
                }
                $dte = $this->crearBorrador($fila, $lineas);
                $fila->update(['dte_id' => $dte->id, 'estado' => EstadoPedidoWeb::Facturando]);
                $this->auditar($fila, 'borrador', 'creó el borrador de la factura del pedido web');
            });
        } catch (FacturaWebNoArmadaException $e) {
            // Lo creado ya se revirtió; queda anotado por qué, sin factura.
            DB::transaction(function () use ($pedido, $e) {
                $fila = PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
                if ($fila->estado === EstadoPedidoWeb::Importado && $fila->dte_id === null) {
                    $this->paraRevisar($fila, $e->getMessage());
                }
            });
        }
    }

    /**
     * Los candados de la 0011 que dependen del pedido. Devuelve las líneas listas para el
     * borrador o el motivo por el que no se factura.
     *
     * @return array{0: list<array{producto: Producto, cantidad: int, precio: int}>, 1: ?string}
     */
    private function revisarCandados(PedidoWeb $pedido): array
    {
        if ($pedido->pedido_sala_id === null) {
            return [[], 'El pedido no tiene pedido de fábrica.'];
        }
        if ($pedido->dte_id !== null) {
            return [[], 'El pedido ya tiene una factura.'];
        }
        if (strtolower(trim((string) $pedido->pasarela)) === 'demo') {
            return [[], 'El pago es de la pasarela de demostración: no se factura.'];
        }
        if (filled($pedido->alerta)) {
            return [[], 'La tienda marcó el pedido con una alerta ('.$pedido->alerta.').'];
        }
        if (Validator::make(
            ['nombre' => trim((string) $pedido->cliente_nombre), 'correo' => trim((string) $pedido->cliente_correo)],
            ['nombre' => ['required', 'string', 'max:250'], 'correo' => ['required', 'email:rfc,filter', 'min:6', 'max:100']],
        )->fails()) {
            return [[], 'El nombre o el correo del cliente no sirven para la factura.'];
        }

        $lineas = [];
        $suma = 0;
        foreach (is_array($pedido->lineas) ? $pedido->lineas : [] as $linea) {
            if (! is_array($linea) || ! is_int($linea['cantidad'] ?? null) || $linea['cantidad'] < 1
                || ! is_int($linea['precio'] ?? null) || $linea['precio'] <= 0) {
                return [[], 'Cada línea necesita una cantidad entera y un precio mayor que cero.'];
            }
            $barra = $linea['barra'] ?? null;
            $producto = is_string($barra) && $barra !== '' ? Producto::where('codigo_barra', $barra)->first() : null;
            if ($producto === null) {
                return [[], 'No se encontró un producto por su código de barras.'];
            }
            $clave = $producto->id.':'.$linea['precio'];
            $lineas[$clave] ??= ['producto' => $producto, 'cantidad' => 0, 'precio' => $linea['precio']];
            $lineas[$clave]['cantidad'] += $linea['cantidad'];
            $suma += $linea['cantidad'] * $linea['precio'];
        }
        if ($lineas === []) {
            return [[], 'El pedido no trae líneas.'];
        }
        if ($suma !== $pedido->total_centavos) {
            return [[], 'La suma de las líneas no es igual al total cobrado.'];
        }

        return [array_values($lineas), null];
    }

    /**
     * El borrador por el mismo servicio que la oficina. Cualquier dato que no sirva corta
     * con {@see FacturaWebNoArmadaException}, que revierte la transacción entera.
     *
     * @param  list<array{producto: Producto, cantidad: int, precio: int}>  $lineas
     */
    private function crearBorrador(PedidoWeb $pedido, array $lineas): Dte
    {
        try {
            $emisor = ResuelveEmisorUnico::resolver(null, null);
        } catch (PuntoVentaPredeterminadoInvalidoException $e) {
            throw new FacturaWebNoArmadaException($e->getMessage());
        }
        if (blank($emisor['establecimiento_id']) || blank($emisor['punto_venta_id'])) {
            throw new FacturaWebNoArmadaException('No se pudo determinar el emisor: hay más de un establecimiento o punto de venta activo.');
        }

        try {
            $dte = $this->borradores->crearBorrador([
                'tipo_dte' => TipoDte::Factura,
                'establecimiento_id' => $emisor['establecimiento_id'],
                'punto_venta_id' => $emisor['punto_venta_id'],
                'condicion_operacion' => self::CONTADO,
            ]);
            $dte->update([
                'forma_pago' => self::FORMA_PAGO,
                'receptor_nombre' => trim($pedido->cliente_nombre),
                'receptor_correo' => trim($pedido->cliente_correo),
            ]);
            foreach ($lineas as $linea) {
                // El precio con IVA que pagó el cliente, exacto desde los centavos.
                $precio = Dinero::redondear(Dinero::dividir($linea['precio'], 100));
                $this->borradores->agregarLineaDesdeProducto($dte, $linea['producto'], $linea['cantidad'], 0, $precio);
            }
            $this->borradores->recalcular($dte);
        } catch (ValidationException $e) {
            throw new FacturaWebNoArmadaException('No se pudo armar la factura: '.collect($e->errors())->flatten()->first());
        }

        if (Dinero::comparar((string) $dte->total_pagar, Dinero::dividir($pedido->total_centavos, 100)) !== 0) {
            throw new FacturaWebNoArmadaException('El total de la factura no es igual al total cobrado.');
        }

        return $dte;
    }

    /** Generar, firmar y transmitir. Fuera de toda transacción y bajo el candado del documento. */
    private function emitir(PedidoWeb $pedido): void
    {
        $dte = Dte::withTrashed()->find($pedido->dte_id);
        if ($dte === null || $dte->trashed()) {
            $this->cerrar($pedido, EstadoPedidoWeb::ParaRevisar, 'La factura borrador del pedido ya no existe.', 'sin_factura');

            return;
        }

        // Un intento cada {@see MINUTOS_ENTRE_INTENTOS} por pedido. El primero (recién creado
        // el borrador) sale enseguida; los reintentos esperan.
        if (! Cache::add('tienda-web:emitir:'.$pedido->id, true, now()->addMinutes(self::MINUTOS_ENTRE_INTENTOS))) {
            return;
        }

        try {
            CandadoEmisionDte::ejecutar($dte, fn () => $this->emitirConCandado($dte));
        } catch (DteEmisionEnCursoException) {
            return; // otro proceso lo está emitiendo: lo retoma la pasada siguiente
        } catch (Throwable $e) {
            // Generación, firma, candados del entorno o transmisión: sigue «facturando»,
            // sin perder nada. Al registro solo los números y la clase: el mensaje puede
            // traer datos del documento.
            Log::warning('Pedido web: la factura no terminó de emitirse; se reintenta en la pasada siguiente', [
                'pedido_web_id' => $pedido->id, 'dte_id' => $dte->id, 'excepcion' => class_basename($e),
            ]);

            return;
        }

        $dte->refresh();
        match ($dte->estado) {
            EstadoDte::Aceptado => $this->cerrar($pedido, EstadoPedidoWeb::Facturado, null, 'facturado'),
            EstadoDte::Rechazado => $this->cerrar($pedido, EstadoPedidoWeb::ParaRevisar, $this->motivoRechazo($dte), 'rechazado'),
            // Transitorio, incierto o sin respuesta: la pasada siguiente reintenta.
            EstadoDte::Borrador, EstadoDte::Generado, EstadoDte::Firmado => null,
            default => $this->cerrar($pedido, EstadoPedidoWeb::ParaRevisar,
                'La factura quedó «'.$dte->estado->label().'»: revisala en la oficina.', 'otro_estado'),
        };
    }

    private function emitirConCandado(Dte $dte): void
    {
        if (! in_array($dte->estado, [EstadoDte::Borrador, EstadoDte::Generado, EstadoDte::Firmado], true)
            || filled($dte->sello_recepcion)) {
            return; // ya tiene desenlace: no se repite nada
        }
        if ($dte->estado === EstadoDte::Borrador) {
            $this->generacion->generar($dte, null);
            $dte->refresh();
        }
        $this->firmaTransmision->procesar($dte, null);
    }

    /** Cambia el estado de un pedido que seguía facturando, con la fila bloqueada. */
    private function cerrar(PedidoWeb $pedido, EstadoPedidoWeb $estado, ?string $motivo, string $resultado): void
    {
        DB::transaction(function () use ($pedido, $estado, $motivo, $resultado) {
            $fila = PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if ($fila->estado !== EstadoPedidoWeb::Facturando) {
                return;
            }
            $fila->update(['estado' => $estado] + ($motivo !== null ? ['motivo_revision' => $motivo] : []));
            $this->auditar($fila, $resultado, $estado === EstadoPedidoWeb::Facturado
                ? 'Hacienda aceptó la factura del pedido web'
                : 'pedido web para revisar: la factura no quedó aceptada');
        });
    }

    /** El motivo de Hacienda en una línea, para la oficina. */
    private function motivoRechazo(Dte $dte): string
    {
        $respuesta = is_array($dte->respuesta_mh) ? $dte->respuesta_mh : [];
        $partes = array_filter([
            trim((string) ($respuesta['descripcionMsg'] ?? '')),
            collect($respuesta['observaciones'] ?? [])->filter(fn ($o) => is_string($o) && trim($o) !== '')->implode('; '),
        ]);

        return Str::limit(Str::squish('Hacienda rechazó la factura: '.($partes !== [] ? implode(' — ', $partes) : 'sin motivo informado.')), 1000);
    }

    private function enviarCorreo(PedidoWeb $pedido): void
    {
        if ($pedido->factura_correo_en !== null) {
            return;
        }
        DB::transaction(function () use ($pedido) {
            $fila = PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $dte = Dte::find($fila->dte_id);
            if ($fila->factura_correo_en !== null || $fila->estado !== EstadoPedidoWeb::Facturado || blank($dte?->receptor_correo)) {
                return;
            }
            // Mismo camino que la oficina; si ya hay uno igual pendiente, no lo duplica.
            $this->correos->encolar($dte, [$dte->receptor_correo], null, DteEnvio::CANAL_CLIENTE);
            $fila->update(['factura_correo_en' => now()]);
        });
        $pedido->refresh();
    }

    private function avisarPagina(PedidoWeb $pedido): void
    {
        $dte = Dte::find($pedido->dte_id);
        if ($pedido->informado_factura_en !== null || $dte === null) {
            return;
        }
        try {
            $respuesta = $this->tienda->cliente()->post($this->tienda->url().'/api/sistema/pedidos/'.$pedido->web_id.'/estado', [
                'factura' => [
                    'numero_control' => $dte->numero_control,
                    'codigo_generacion' => $dte->codigo_generacion,
                    'fecha' => $dte->fecha_emision?->format('Y-m-d'),
                ],
            ]);
            if ($respuesta->status() === 200) {
                $pedido->update(['informado_factura_en' => now()]);
            }
        } catch (Throwable) {
            // Se reintenta en la pasada siguiente. Nunca registrar la excepción.
        }
    }

    /** No se emitió nada: queda para la oficina, con el motivo en el pedido. */
    private function paraRevisar(PedidoWeb $fila, string $motivo): void
    {
        $fila->update(['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => $motivo]);
        $this->auditar($fila, 'para_revisar', 'pedido web para revisar: no se emitió la factura');
    }

    /** Sin persona responsable y sin datos del cliente. */
    private function auditar(PedidoWeb $pedido, string $resultado, string $descripcion): void
    {
        activity('pedido_web')->performedOn($pedido)->causedByAnonymous()
            ->withProperties([
                'web_id' => $pedido->web_id, 'codigo' => $pedido->codigo, 'dte_id' => $pedido->dte_id,
                'origen' => 'tienda_web', 'resultado' => $resultado,
            ])
            ->log($descripcion);
    }
}
