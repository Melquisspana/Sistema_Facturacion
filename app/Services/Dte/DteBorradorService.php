<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\LineaDocumento;
use App\DataTransferObjects\Dte\ResultadoCalculo;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Enums\TipoProducto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Http\Requests\Dte\AgregarLineaDteRequest;
use App\Http\Requests\Dte\CrearBorradorRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Producto;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Arma y mantiene un DTE en estado BORRADOR: crea la cabecera, agrega líneas
 * desde productos (con snapshot), y recalcula los totales con la CalculadoraDte,
 * persistiendo cabecera y líneas.
 *
 * Alcance de este paso (motor de borradores):
 *  - NO consume correlativos ni asigna número de control real.
 *  - NO genera JSON/PDF, no firma, no contacta Hacienda.
 *  - NO cambia el estado fuera de borrador.
 *  - Solo permite modificar si el DTE está en borrador (si no: DocumentoInmutableException).
 */
class DteBorradorService
{
    public function __construct(
        private readonly CalculadoraDte $calculadora,
        private readonly SnapshotProductoService $snapshots,
        private readonly DteStateMachine $maquina,
        private readonly PrecioProductoResolver $precios,
    ) {}

    /**
     * Crea la cabecera de un borrador. No consume correlativo: solo lo referencia
     * si se pasa. Determina la retención por defecto y exige orden de compra si
     * el cliente lo requiere (en CCF).
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws OrdenCompraRequeridaException
     */
    public function crearBorrador(array $datos, ?User $usuario = null): Dte
    {
        $cliente = $this->resolverCliente($datos['cliente_id'] ?? null);

        // Validación de campos + coherencia cliente/tipo (ValidationException).
        $sucursal = $this->resolverSucursal($datos['cliente_sucursal_id'] ?? null, $cliente);
        $tipo = $this->validarDatosBorrador($datos, $cliente);

        // La sala debe permitir CCF si el documento es un CCF.
        if ($tipo === TipoDte::CreditoFiscal && $sucursal && $sucursal->permite_ccf === false) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'Esta sala no permite emitir CCF.',
            ]);
        }

        // La orden de compra es regla de dominio (excepción propia, no de campo).
        // La exige el cliente O la sucursal seleccionada.
        $numeroOrdenCompra = $this->normalizarOrdenCompra($datos['numero_orden_compra'] ?? null);
        $this->validarOrdenCompra($tipo, $cliente, $sucursal, $numeroOrdenCompra);

        // La retención NO se decide aquí ni se acepta del request: se evalúa
        // automáticamente al recalcular (agente de retención + umbral de monto).
        return DB::transaction(function () use ($datos, $tipo, $cliente, $sucursal, $numeroOrdenCompra, $usuario) {
            $dte = Dte::create([
                'tipo_dte' => $tipo->value,
                'estado' => EstadoDte::Borrador->value,
                'ambiente' => $datos['ambiente'] ?? config('dte.ambiente'),
                'establecimiento_id' => $datos['establecimiento_id'],
                'punto_venta_id' => $datos['punto_venta_id'] ?? null,
                'correlativo_id' => $datos['correlativo_id'] ?? null,
                'cliente_id' => $cliente?->id,
                'cliente_sucursal_id' => $sucursal?->id,
                'dte_relacionado_id' => $datos['dte_relacionado_id'] ?? null,
                'condicion_operacion' => $datos['condicion_operacion'] ?? 1,
                'numero_orden_compra' => $numeroOrdenCompra,
                'fecha_emision' => $datos['fecha_emision'] ?? now()->toDateString(),
                'hora_emision' => $datos['hora_emision'] ?? now()->toTimeString(),
                'observaciones' => $datos['observaciones'] ?? null,
                'moneda' => $datos['moneda'] ?? 'USD',
                // El descuento del cliente/sucursal es un PORCENTAJE. El monto se
                // calcula al recalcular (sobre el subtotal); aquí solo se congela
                // el porcentaje aplicado.
                'descuento_global' => '0.00',
                'descuento_porcentaje_aplicado' => $this->porcentajeDesde($cliente, $sucursal),
                'flete' => $this->montoDe($datos['flete'] ?? 0),
                'seguro' => $this->montoDe($datos['seguro'] ?? 0),
                'aplica_retencion_iva' => false, // se decide al recalcular
                'created_by' => $usuario?->id ?? Auth::id(),
            ]);

            $this->maquina->registrarCreacion($dte, $usuario);

            // Refresca para exponer los defaults de BD (totales en 0.00).
            return $dte->refresh();
        });
    }

    /**
     * DUPLICA un CCF: crea un borrador NUEVO con los mismos datos base (cliente, sala,
     * emisor, condición, orden de compra, observaciones, % de descuento) y una copia
     * SNAPSHOT de las líneas (productos, cantidades, precios y descuentos congelados del
     * original, aunque el producto haya cambiado de precio o esté inactivo).
     *
     * NO toca el original y NO copia nada fiscal/operativo: ni numeración (interna u
     * oficial), ni correlativo, ni JSON/JWS, ni firma, ni sello/respuesta MH, ni envíos
     * de correo, ni anulación/invalidación. El duplicado nace en borrador con la fecha
     * de hoy y sus totales se recalculan (la retención se decide sola al recalcular).
     *
     * @throws ValidationException si el original no es un CCF
     * @throws OrdenCompraRequeridaException si el cliente/sala ahora exige OC y el original no tenía
     */
    public function duplicarCcf(Dte $original, ?User $usuario = null): Dte
    {
        if ($original->tipo_dte !== TipoDte::CreditoFiscal) {
            throw ValidationException::withMessages([
                'duplicar' => 'Solo se puede duplicar un Comprobante de Crédito Fiscal (CCF).',
            ]);
        }

        return DB::transaction(function () use ($original, $usuario) {
            $nuevo = $this->crearBorrador([
                'tipo_dte' => TipoDte::CreditoFiscal,
                'cliente_id' => $original->cliente_id,
                'cliente_sucursal_id' => $original->cliente_sucursal_id,
                'establecimiento_id' => $original->establecimiento_id,
                'punto_venta_id' => $original->punto_venta_id,
                // El cast del modelo devuelve el enum CondicionPago; la validación espera el valor.
                'condicion_operacion' => $original->condicion_operacion instanceof \BackedEnum
                    ? $original->condicion_operacion->value
                    : $original->condicion_operacion,
                'numero_orden_compra' => $original->numero_orden_compra,
                'observaciones' => $original->observaciones,
            ], $usuario);

            // Fidelidad: mismo % de descuento del ORIGINAL (crearBorrador resuelve el
            // vigente del cliente/sala, que pudo haber cambiado).
            $nuevo->descuento_porcentaje_aplicado = $original->descuento_porcentaje_aplicado;
            $nuevo->save();

            // Copia snapshot de las líneas (sin dte_linea_original_id: eso es de las NC).
            $numero = 1;
            foreach ($original->lineas()->get() as $linea) {
                $copia = $linea->replicate(['dte_id', 'numero_linea', 'dte_linea_original_id']);
                $copia->dte_id = $nuevo->id;
                $copia->numero_linea = $numero++;
                $copia->save();
            }

            $this->recalcular($nuevo);

            return $nuevo->refresh();
        });
    }

    /**
     * Crea una NOTA DE CRÉDITO (05) en borrador relacionada a un documento original.
     *
     * Reglas (alcance actual):
     *  - El original es obligatorio y debe ser un CCF (03) ya emitido (no borrador).
     *  - El cliente de la NC es SIEMPRE el del original (no se puede cambiar).
     *  - Guarda dte_relacionado_id; las líneas se agregan luego con acreditarLinea().
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function crearNotaCredito(?Dte $original, array $datos = [], ?User $usuario = null): Dte
    {
        // Modalidad interna (por productos vs. por monto/concepto).
        $tipoRaw = $datos['tipo'] ?? TipoNotaCredito::DevolucionProducto->value;
        $tipo = $tipoRaw instanceof TipoNotaCredito ? $tipoRaw : TipoNotaCredito::from((string) $tipoRaw);

        // REGLA OBLIGATORIA: TODA Nota de Crédito (05) — devolución, avería, pronto pago,
        // cualquier tipo — debe estar vinculada a un CCF (03) ACEPTADO por Hacienda. Sin un CCF
        // aceptado relacionado no puede crearse ni emitirse (no existe oficialmente ante el MH).
        if ($original === null) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Para generar una Nota de Crédito debe seleccionar un CCF aceptado relacionado.',
            ]);
        }
        if ($original->tipo_dte !== TipoDte::CreditoFiscal) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'El documento relacionado de una nota de crédito debe ser un Comprobante de Crédito Fiscal (CCF).',
            ]);
        }
        if ($original->estado !== EstadoDte::Aceptado) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Solo se puede crear una nota de crédito desde un CCF ACEPTADO por Hacienda (estado actual: '.$original->estado->label().').',
            ]);
        }
        if (array_key_exists('cliente_id', $datos)
            && $datos['cliente_id'] !== null
            && (int) $datos['cliente_id'] !== (int) $original->cliente_id) {
            throw ValidationException::withMessages([
                'cliente_id' => 'La nota de crédito debe usar el mismo cliente del documento original.',
            ]);
        }

        // Cliente y sala: del original si existe; si no, de los datos (NC independiente).
        $clienteId = $original?->cliente_id ?? ($datos['cliente_id'] ?? null);
        $sucursalId = $original?->cliente_sucursal_id ?? ($datos['cliente_sucursal_id'] ?? null);

        // La sala (si se indica) debe pertenecer al cliente y permitir notas de crédito.
        if ($sucursalId !== null) {
            $sucursal = ClienteSucursal::find($sucursalId);
            if ($sucursal && $clienteId !== null && (int) $sucursal->cliente_id !== (int) $clienteId) {
                throw ValidationException::withMessages(['cliente_sucursal_id' => 'La sala no pertenece al cliente.']);
            }
            if ($sucursal && $sucursal->permite_nota_credito === false) {
                throw ValidationException::withMessages(['cliente_sucursal_id' => 'Esta sala no permite notas de crédito.']);
            }
        }

        // Orden de compra: se CONGELA desde el CCF relacionado (no se acepta del request).
        $ordenCompra = $original?->numero_orden_compra;

        return DB::transaction(function () use ($original, $datos, $tipo, $clienteId, $sucursalId, $ordenCompra, $usuario) {
            $nc = Dte::create([
                'tipo_dte' => TipoDte::NotaCredito->value,
                'tipo_nota_credito' => $tipo->value,
                'estado' => EstadoDte::Borrador->value,
                'ambiente' => $original?->ambiente?->value ?? config('dte.ambiente'),
                'establecimiento_id' => $datos['establecimiento_id'] ?? $original?->establecimiento_id,
                'punto_venta_id' => $datos['punto_venta_id'] ?? $original?->punto_venta_id,
                'correlativo_id' => null,
                'cliente_id' => $clienteId,
                'cliente_sucursal_id' => $sucursalId,
                'dte_relacionado_id' => $original?->id,
                // La NC es un ajuste fiscal, NO una venta a plazo: condición de operación
                // CONTADO (1). No se hereda el "a crédito" del CCF porque la NC no lleva
                // bloque pagos/plazo en el schema tipo 05 (sería una declaración inconsistente).
                'condicion_operacion' => $datos['condicion_operacion'] ?? 1,
                'numero_orden_compra' => $ordenCompra,
                'motivo' => $datos['motivo'] ?? null,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => $original?->moneda ?? 'USD',
                'descuento_global' => '0.00',
                'aplica_retencion_iva' => false,
                'created_by' => $usuario?->id ?? Auth::id(),
            ]);

            $this->maquina->registrarCreacion($nc, $usuario, 'Creación de nota de crédito');

            return $nc->refresh();
        });
    }

    /**
     * Agrega una LÍNEA MANUAL DE CONCEPTO a una NC por monto (pronto pago,
     * descuento posterior, ajuste comercial, otro). NO usa producto ni
     * dte_linea_original_id: es un ajuste por monto, no un producto físico.
     *
     * @param  array<string, mixed>  $datos  descripcion, monto, tipo_impuesto?
     *
     * @throws DocumentoInmutableException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function agregarConceptoNotaCredito(Dte $nc, array $datos): DteLinea
    {
        $this->verificarEditable($nc);

        if ($nc->tipo_dte !== TipoDte::NotaCredito || ! $nc->tipo_nota_credito?->esPorMonto()) {
            throw ValidationException::withMessages([
                'tipo' => 'Solo las notas de crédito por monto admiten conceptos manuales.',
            ]);
        }

        // No mezclar conceptos con líneas de devolución de producto.
        if ($nc->lineas()->whereNotNull('dte_linea_original_id')->exists()) {
            throw ValidationException::withMessages([
                'concepto' => 'No se pueden mezclar líneas de producto con conceptos manuales en la misma NC.',
            ]);
        }

        $validado = Validator::make($datos, [
            'descripcion' => ['required', 'string', 'max:1000'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'tipo_impuesto' => ['nullable', \Illuminate\Validation\Rule::in(array_map(fn ($t) => $t->value, TipoImpuesto::cases()))],
        ])->validate();

        $tipoImpuesto = $validado['tipo_impuesto'] ?? TipoImpuesto::Gravado->value;
        $monto = $this->montoDe($validado['monto']);

        return DB::transaction(function () use ($nc, $validado, $tipoImpuesto, $monto) {
            $linea = new DteLinea([
                'descripcion' => $validado['descripcion'],
                'tipo_producto' => TipoProducto::Servicio->value, // concepto, no bien físico
                'tipo_impuesto' => $tipoImpuesto,
                // Un concepto manual (pronto pago / descuento / ajuste) no tiene producto
                // ni unidad física, pero el esquema del MH exige una unidad de medida
                // (CAT-014) en TODA línea. Se usa el código 99 = "Otra".
                'unidad_codigo' => '99',
            ]);
            $linea->dte_id = $nc->id;
            $linea->numero_linea = $this->siguienteNumeroLinea($nc);
            $linea->cantidad = '1';
            $linea->precio_unitario = $monto;
            $linea->descuento_monto = '0.00';
            $linea->save();

            $this->recalcular($nc);

            return $linea->refresh();
        });
    }

    /**
     * Agrega un PRODUCTO DEL CATÁLOGO a una NC por AVERÍA. A diferencia de la
     * devolución/faltante (que acreditan líneas del CCF original), la avería puede
     * acreditar cualquier producto activo: resuelve el precio por cliente/sucursal,
     * congela el snapshot, NO asigna dte_linea_original_id y NO valida saldo.
     *
     * @throws DocumentoInmutableException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function agregarProductoNotaCreditoAveria(Dte $nc, Producto $producto, string|int|float $cantidad): DteLinea
    {
        $this->verificarEditable($nc);

        if ($nc->tipo_dte !== TipoDte::NotaCredito || ! ($nc->tipo_nota_credito?->esPorAveria() ?? false)) {
            throw ValidationException::withMessages([
                'tipo' => 'Solo las notas de crédito por avería admiten productos del catálogo.',
            ]);
        }

        // Bloquea productos sin precio aplicable (general ni especial).
        $r = $this->precios->resolverConOrigen($producto, $nc->cliente_id, $nc->cliente_sucursal_id);
        if ($r['precio'] === null || ! is_numeric($r['precio']) || (float) $r['precio'] <= 0) {
            throw ValidationException::withMessages([
                'producto_id' => 'El producto no tiene un precio aplicable para este cliente; no se puede agregar.',
            ]);
        }

        // Reutiliza el alta normal por producto (snapshot + precio congelado +
        // recálculo en modo Nota de crédito). No setea dte_linea_original_id.
        return $this->agregarLineaDesdeProducto($nc, $producto, $cantidad);
    }

    /**
     * Acredita (total o parcialmente) una línea del documento original en la NC.
     * Copia el snapshot de la línea original, prorratea su descuento a la cantidad
     * acreditada, enlaza dte_linea_original_id y recalcula.
     *
     * @throws DocumentoInmutableException
     * @throws SaldoAcreditableExcedidoException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function acreditarLinea(Dte $nc, DteLinea $lineaOriginal, string|int|float $cantidad): DteLinea
    {
        $this->verificarEditable($nc);

        if ($nc->tipo_nota_credito !== null && ! $nc->tipo_nota_credito->esPorProductos()) {
            throw ValidationException::withMessages([
                'tipo' => 'Esta nota de crédito es por monto; no acredita líneas de producto.',
            ]);
        }

        if ((int) $nc->dte_relacionado_id !== (int) $lineaOriginal->dte_id) {
            throw ValidationException::withMessages([
                'dte_linea_original_id' => 'La línea no pertenece al documento original de la nota de crédito.',
            ]);
        }

        $cantidad = Dinero::de($cantidad);
        if (Dinero::comparar($cantidad, '0') <= 0) {
            throw ValidationException::withMessages([
                'cantidad' => 'La cantidad a acreditar debe ser mayor que cero.',
            ]);
        }

        $this->validarSaldoAcreditable($lineaOriginal, $cantidad);

        return DB::transaction(function () use ($nc, $lineaOriginal, $cantidad) {
            // Descuento DE LÍNEA del original, prorrateado a la fracción acreditada. El descuento
            // GLOBAL del CCF NO va aquí: se hereda como descuento global del resumen (ver
            // porcentajeDescuentoVigente()), igual que la NC v3 aceptada (ventaGravada bruto,
            // descuGravada en el resumen). Así montoDescu por línea queda en 0 cuando el CCF
            // aplicó descuento global.
            $factor = Dinero::dividir($cantidad, Dinero::de($lineaOriginal->cantidad));
            $descuentoProrateado = Dinero::redondear(
                Dinero::multiplicar($lineaOriginal->descuento_monto, $factor),
                2
            );

            $linea = new DteLinea([
                'producto_id' => $lineaOriginal->producto_id,
                'codigo' => $lineaOriginal->codigo,
                'codigo_barra' => $lineaOriginal->codigo_barra,
                'descripcion' => $lineaOriginal->descripcion,
                'unidad_medida_id' => $lineaOriginal->unidad_medida_id,
                'unidad_codigo' => $lineaOriginal->unidad_codigo,
                'unidad_nombre' => $lineaOriginal->unidad_nombre,
                'tipo_producto' => $lineaOriginal->tipo_producto?->value,
                'tipo_impuesto' => $lineaOriginal->tipo_impuesto->value,
            ]);
            $linea->dte_id = $nc->id;
            $linea->numero_linea = $this->siguienteNumeroLinea($nc);
            $linea->cantidad = $cantidad;
            $linea->precio_unitario = (string) $lineaOriginal->precio_unitario;
            $linea->descuento_monto = $descuentoProrateado;
            $linea->dte_linea_original_id = $lineaOriginal->id;
            $linea->save();

            $this->recalcular($nc);

            return $linea->refresh();
        });
    }

    /**
     * Verifica que la cantidad a acreditar no supere el saldo de la línea original
     * (cantidad original − lo ya acreditado en cualquier NC).
     *
     * @throws SaldoAcreditableExcedidoException
     */
    private function validarSaldoAcreditable(DteLinea $lineaOriginal, string $cantidad): void
    {
        // Ignora las NC anuladas (invalidado): su acreditación vuelve a estar disponible.
        $yaAcreditado = Dinero::de(
            DteLinea::where('dte_linea_original_id', $lineaOriginal->id)
                ->whereHas('dte', fn ($q) => $q->where('estado', '!=', EstadoDte::Invalidado->value))
                ->sum('cantidad') ?? 0
        );
        $disponible = Dinero::restar(Dinero::de($lineaOriginal->cantidad), $yaAcreditado);

        if (Dinero::comparar($cantidad, $disponible) > 0) {
            throw new SaldoAcreditableExcedidoException(
                'No se puede acreditar más que el saldo disponible de la línea original.'
            );
        }
    }

    /**
     * Agrega una línea tomando el snapshot del producto y recalcula los totales.
     *
     * @throws DocumentoInmutableException
     */
    public function agregarLineaDesdeProducto(
        Dte $dte,
        Producto $producto,
        string|int|float $cantidad,
        string|int|float $descuento = 0,
        string|int|float|null $precioOverride = null,
    ): DteLinea {
        $this->verificarEditable($dte);

        // Precio efectivo: override explícito, o el resuelto por cliente/sucursal
        // del DTE (sala → cliente → precio general). Se CONGELA en la línea.
        $precio = $precioOverride ?? $this->precios->resolver($producto, $dte->cliente_id, $dte->cliente_sucursal_id);
        AgregarLineaDteRequest::validarValores($cantidad, $precio, $descuento);

        return DB::transaction(function () use ($dte, $producto, $cantidad, $descuento, $precio) {
            $linea = new DteLinea($this->snapshots->paraLinea($producto));
            $linea->dte_id = $dte->id;
            $linea->numero_linea = $this->siguienteNumeroLinea($dte);
            $linea->cantidad = (string) $cantidad;
            $linea->precio_unitario = (string) $precio; // snapshot del precio aplicado
            $linea->descuento_monto = $this->montoDe($descuento);
            $linea->save();

            $this->recalcular($dte);

            return $linea->refresh();
        });
    }

    /**
     * Actualiza campos capturables de una línea (cantidad, precio, descuento,
     * tipo de impuesto) y recalcula. El snapshot de identidad del producto no se toca.
     *
     * @param  array<string, mixed>  $cambios
     *
     * @throws DocumentoInmutableException
     */
    public function actualizarLinea(DteLinea $linea, array $cambios): DteLinea
    {
        $dte = $linea->dte()->first(); // estado fresco, sin relación cacheada
        $this->verificarEditable($dte);

        // Valida sobre los valores RESULTANTES (mezcla de cambios + línea actual).
        AgregarLineaDteRequest::validarValores(
            $cambios['cantidad'] ?? $linea->cantidad,
            $cambios['precio_unitario'] ?? $linea->precio_unitario,
            $cambios['descuento_monto'] ?? $linea->descuento_monto,
        );

        return DB::transaction(function () use ($dte, $linea, $cambios) {
            foreach (['cantidad', 'precio_unitario', 'descuento_monto', 'tipo_impuesto'] as $campo) {
                if (array_key_exists($campo, $cambios)) {
                    $linea->{$campo} = $cambios[$campo];
                }
            }
            $linea->save();

            $this->recalcular($dte);

            return $linea->refresh();
        });
    }

    /**
     * Fija la cantidad de un producto en el borrador de forma IDEMPOTENTE por producto
     * (no duplica): si el producto ya es una línea, actualiza su cantidad; si no existe
     * y cantidad > 0, la agrega; si la cantidad es null/0 y la línea existe, la elimina.
     * Reusa la resolución de precio, la validación y el recálculo existentes; no cambia
     * ninguna regla fiscal.
     *
     * @return array{accion: string, linea: ?DteLinea}
     *
     * @throws DocumentoInmutableException
     */
    public function establecerCantidadProducto(Dte $dte, Producto $producto, ?int $cantidad): array
    {
        $this->verificarEditable($dte);

        $linea = $dte->lineas()->where('producto_id', $producto->id)->first();

        // Sin cantidad (null/0): quitar la línea si estaba; si no, no hacer nada.
        if ($cantidad === null || $cantidad <= 0) {
            if ($linea) {
                $this->eliminarLinea($linea);

                return ['accion' => 'eliminada', 'linea' => null];
            }

            return ['accion' => 'sin_cambio', 'linea' => null];
        }

        // Con cantidad: actualizar la línea existente o crear una nueva (nunca duplicar).
        if ($linea) {
            return ['accion' => 'actualizada', 'linea' => $this->actualizarLinea($linea, ['cantidad' => (string) $cantidad])];
        }

        return ['accion' => 'agregada', 'linea' => $this->agregarLineaDesdeProducto($dte, $producto, $cantidad)];
    }

    /**
     * Elimina una línea y recalcula (renumerando las restantes en recalcular()).
     *
     * @throws DocumentoInmutableException
     */
    public function eliminarLinea(DteLinea $linea): void
    {
        $dte = $linea->dte()->first(); // estado fresco, sin relación cacheada
        $this->verificarEditable($dte);

        DB::transaction(function () use ($dte, $linea) {
            $linea->delete();
            $this->recalcular($dte);
        });
    }

    /**
     * Recalcula los totales del borrador con la CalculadoraDte y persiste cabecera
     * y líneas. Sin líneas → todos los totales en cero.
     *
     * @throws DocumentoInmutableException
     */
    public function recalcular(Dte $dte): Dte
    {
        $this->verificarEditable($dte);

        return DB::transaction(function () use ($dte) {
            $lineas = $dte->lineas()->get();

            if ($lineas->isEmpty()) {
                $this->ponerTotalesEnCero($dte);
                $dte->save();

                return $dte;
            }

            // Renumerar de forma estable (cubre huecos por eliminación).
            $numero = 1;
            $documentos = [];
            foreach ($lineas as $linea) {
                if ($linea->numero_linea !== $numero) {
                    $linea->numero_linea = $numero;
                }
                $documentos[] = new LineaDocumento(
                    cantidad: (string) $linea->cantidad,
                    precioUnitario: (string) $linea->precio_unitario,
                    tipoImpuesto: $linea->tipo_impuesto,
                    descuentoMonto: (string) $linea->descuento_monto,
                    descripcion: $linea->descripcion,
                );
                $numero++;
            }

            // El descuento del cliente/sucursal es un PORCENTAJE: se convierte a
            // monto sobre el subtotal bruto (suma de buckets antes del descuento).
            $porcentaje = $this->porcentajeDescuentoVigente($dte);
            $dte->descuento_porcentaje_aplicado = $porcentaje;
            $montoDescuento = $this->montoDescuentoDesdePorcentaje($dte, $documentos, $porcentaje);
            $dte->descuento_global = $montoDescuento;

            // Retención AUTOMÁTICA: CCF + agente de retención + base gravada neta > umbral.
            // La base se evalúa DESPUÉS del descuento (con el monto recién calculado).
            $aplicaRetencion = $this->decidirRetencionAutomatica($dte, $documentos, $montoDescuento);

            $resultado = $this->calculadora->calcular(
                $documentos,
                $dte->tipo_dte,
                $montoDescuento,
                $dte->flete ?? 0,
                $dte->seguro ?? 0,
                $aplicaRetencion,
            );

            // Resultado por línea (mismo orden que se enviaron).
            foreach ($lineas as $i => $linea) {
                $calc = $resultado->lineas[$i];
                $linea->venta_gravada = $calc->ventaGravada;
                $linea->venta_exenta = $calc->ventaExenta;
                $linea->venta_no_sujeta = $calc->ventaNoSujeta;
                $linea->venta_exportacion = $calc->ventaExportacion;
                $linea->iva_linea = $calc->ivaLinea;
                $linea->total_linea = $calc->totalLinea;
                $linea->save();
            }

            $this->aplicarTotales($dte, $resultado);
            $dte->save();

            return $dte;
        });
    }

    /**
     * ¿El receptor del documento es agente de retención?
     * Prioridad: override de la sucursal (no null) → cliente → false.
     */
    public function esAgenteRetencion(Dte $dte): bool
    {
        $dte->loadMissing(['cliente', 'clienteSucursal']);
        $sucursal = $dte->clienteSucursal;

        if ($sucursal && $sucursal->es_agente_retencion !== null) {
            return (bool) $sucursal->es_agente_retencion;
        }

        return (bool) $dte->cliente?->es_agente_retencion;
    }

    /**
     * Decide automáticamente si aplica retención de IVA: solo CCF, receptor agente
     * de retención, y base gravada NETA (total_gravado − descuento_gravado) > umbral.
     *
     * @param  array<int, LineaDocumento>  $documentos
     */
    private function decidirRetencionAutomatica(Dte $dte, array $documentos, string $montoDescuento = '0.00'): bool
    {
        if ($dte->tipo_dte !== TipoDte::CreditoFiscal || ! $this->esAgenteRetencion($dte)) {
            return false;
        }

        // Cálculo sin retención (pero CON el descuento ya aplicado) solo para
        // conocer la base gravada NETA.
        $base = $this->calculadora->calcular(
            $documentos,
            $dte->tipo_dte,
            $montoDescuento,
            $dte->flete ?? 0,
            $dte->seguro ?? 0,
            false,
        );
        $baseNeta = Dinero::redondear(Dinero::restar($base->totalGravado, $base->descuentoGravado), 2);
        $umbral = (string) config('dte.retencion_iva_umbral', 100);

        return Dinero::comparar($baseNeta, $umbral) > 0;
    }

    /**
     * Porcentaje de descuento vigente para el documento: prioridad sucursal →
     * cliente → 0.
     *
     * Notas de crédito POR PRODUCTOS (devolución/faltante) y POR AVERÍA: heredan el MISMO
     * descuento global del CCF relacionado (su descuento_porcentaje_aplicado), aplicado como
     * descuento GLOBAL del resumen (ventaGravada bruto, descuGravada en el resumen), tal como
     * la NC v3 aceptada por el MH. La avería usa catálogo libre, pero al estar relacionada a un
     * CCF con descuento global debe reflejar el mismo descuento (igual que Conta Portable).
     * Concepto / pronto pago (por monto) no heredan (0%).
     */
    private function porcentajeDescuentoVigente(Dte $dte): string
    {
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            $heredaDescuento = ($dte->tipo_nota_credito?->esPorProductos() ?? false)
                || ($dte->tipo_nota_credito?->esPorAveria() ?? false);
            if ($heredaDescuento && $dte->dteRelacionado !== null) {
                $pct = (float) ($dte->dteRelacionado->descuento_porcentaje_aplicado ?? 0);

                return number_format(max(0.0, min(100.0, $pct)), 2, '.', '');
            }

            return '0.00';
        }

        $dte->loadMissing(['cliente', 'clienteSucursal']);

        return $this->porcentajeDesde($dte->cliente, $dte->clienteSucursal);
    }

    /** Porcentaje de descuento (0–100) desde sucursal → cliente → 0. */
    private function porcentajeDesde(?Cliente $cliente, ?ClienteSucursal $sucursal): string
    {
        $valor = $sucursal?->descuento_global_default ?? $cliente?->descuento_global_default ?? 0;
        $valor = max(0.0, min(100.0, (float) $valor));

        return number_format($valor, 2, '.', '');
    }

    /**
     * Convierte el porcentaje en MONTO sobre el subtotal bruto (suma de buckets
     * antes del descuento). Como el porcentaje es ≤ 100, el monto nunca supera el
     * subtotal y el prorrateo no falla por "descuento mayor al subtotal".
     *
     * @param  array<int, LineaDocumento>  $documentos
     */
    private function montoDescuentoDesdePorcentaje(Dte $dte, array $documentos, string $porcentaje): string
    {
        if (Dinero::comparar($porcentaje, '0') <= 0) {
            return '0.00';
        }

        $bruto = $this->calculadora->calcular(
            $documentos,
            $dte->tipo_dte,
            '0',
            $dte->flete ?? 0,
            $dte->seguro ?? 0,
            false,
        );

        return Dinero::redondear(
            Dinero::dividir(Dinero::multiplicar($bruto->subtotal, $porcentaje), '100'),
            2
        );
    }

    /**
     * Vuelca los totales de la CalculadoraDte en la cabecera del DTE.
     */
    private function aplicarTotales(Dte $dte, ResultadoCalculo $r): void
    {
        $dte->subtotal = $r->subtotal;
        $dte->total_gravado = $r->totalGravado;
        $dte->total_exento = $r->totalExento;
        $dte->total_no_sujeto = $r->totalNoSujeto;
        $dte->total_exportacion = $r->totalExportacion;

        $dte->descuento_gravado = $r->descuentoGravado;
        $dte->descuento_exento = $r->descuentoExento;
        $dte->descuento_no_sujeto = $r->descuentoNoSujeto;
        $dte->total_descuento = $r->descuentoTotal;

        $dte->iva = $r->ivaTotal;

        $dte->aplica_retencion_iva = $r->aplicaRetencion;
        $dte->iva_retenido = $r->retencionIva;

        // En CCF la calculadora da total_antes_retencion; en Factura/FEX coincide
        // con el total a pagar (no hay retención). monto_total_operacion = bruto pre-retención.
        $totalAntes = $r->totalAntesRetencion ?? $r->totalPagar;
        $dte->total_antes_retencion = $totalAntes;
        $dte->monto_total_operacion = $totalAntes;
        $dte->total_pagar = $r->totalPagar;

        $dte->flete = $r->flete;
        $dte->seguro = $r->seguro;
    }

    private function ponerTotalesEnCero(Dte $dte): void
    {
        foreach ([
            'subtotal', 'total_gravado', 'total_exento', 'total_no_sujeto', 'total_exportacion',
            'descuento_gravado', 'descuento_exento', 'descuento_no_sujeto', 'total_descuento',
            'iva', 'iva_retenido', 'monto_total_operacion', 'total_antes_retencion', 'total_pagar',
            'flete', 'seguro',
        ] as $campo) {
            $dte->{$campo} = '0.00';
        }
    }

    private function siguienteNumeroLinea(Dte $dte): int
    {
        return (int) $dte->lineas()->max('numero_linea') + 1;
    }

    private function verificarEditable(?Dte $dte): void
    {
        if (! $dte || ! $dte->esEditable()) {
            throw new DocumentoInmutableException(
                'Solo se pueden modificar las líneas/totales de un DTE en borrador'.
                ($dte ? ' (estado actual: '.$dte->estado->label().').' : '.')
            );
        }
    }

    /**
     * @throws OrdenCompraRequeridaException
     */
    private function validarOrdenCompra(TipoDte $tipo, ?Cliente $cliente, ?ClienteSucursal $sucursal, ?string $numeroOrdenCompra): void
    {
        $requiere = \App\Support\Dte\ReglaOrdenCompra::requerida($cliente, $sucursal);

        if ($tipo === TipoDte::CreditoFiscal
            && $requiere
            && ($numeroOrdenCompra === null || $numeroOrdenCompra === '')
        ) {
            throw new OrdenCompraRequeridaException(
                'Este cliente requiere número de orden de compra para emitir CCF.'
            );
        }
    }

    /**
     * Resuelve la sucursal y verifica que pertenezca al cliente indicado.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolverSucursal(ClienteSucursal|int|null $sucursal, ?Cliente $cliente): ?ClienteSucursal
    {
        if ($sucursal === null) {
            return null;
        }

        $sucursal = $sucursal instanceof ClienteSucursal ? $sucursal : ClienteSucursal::find($sucursal);

        if ($sucursal && $cliente && $sucursal->cliente_id !== $cliente->id) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'La sucursal seleccionada no pertenece al cliente.',
            ]);
        }

        return $sucursal;
    }

    /**
     * Valida los campos del borrador y la coherencia cliente/tipo usando las
     * mismas reglas que el FormRequest de la UI. Devuelve el TipoDte ya validado.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validarDatosBorrador(array $datos, ?Cliente $cliente): TipoDte
    {
        $tipoRaw = $datos['tipo_dte'] ?? null;
        $tipoValue = $tipoRaw instanceof TipoDte ? $tipoRaw->value : (is_string($tipoRaw) ? $tipoRaw : null);

        // Normaliza para el validador (enum→valor; sin el modelo cliente).
        $paraValidar = array_merge($datos, ['tipo_dte' => $tipoValue]);
        unset($paraValidar['cliente_id']);

        $validator = Validator::make($paraValidar, CrearBorradorRequest::reglasBase());
        CrearBorradorRequest::validarCoherencia($validator, $datos, $cliente);
        $validator->validate();

        return TipoDte::from((string) $tipoValue);
    }

    private function resolverCliente(Cliente|int|null $cliente): ?Cliente
    {
        if ($cliente instanceof Cliente) {
            return $cliente;
        }

        return $cliente === null ? null : Cliente::find($cliente);
    }

    private function normalizarOrdenCompra(?string $valor): ?string
    {
        $valor = $valor !== null ? trim($valor) : null;

        return ($valor === null || $valor === '') ? null : $valor;
    }

    private function montoDe(string|int|float $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
