<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\LineaDocumento;
use App\DataTransferObjects\Dte\ResultadoCalculo;
use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoItemExportacion;
use App\Enums\TipoNotaCredito;
use App\Enums\TipoProducto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Http\Requests\Dte\AgregarLineaDteRequest;
use App\Http\Requests\Dte\CrearBorradorRequest;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Producto;
use App\Models\User;
use App\Support\Dinero;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\Dte\ResuelveEmisorUnico;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        private readonly PerfilDocumentoResolver $perfiles,
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
                // Factura de exportación (11): por-DTE, no por-emisor (puede cambiar de un
                // envío a otro). desc_incoterms SIEMPRE se resuelve acá desde CAT-031 por
                // código; no se acepta texto libre del formulario aunque llegara en $datos.
                'tipo_item_expor' => $datos['tipo_item_expor'] ?? TipoItemExportacion::Bienes->value,
                'recinto_fiscal' => $datos['recinto_fiscal'] ?? null,
                'tipo_regimen' => $datos['tipo_regimen'] ?? null,
                'regimen' => $datos['regimen'] ?? null,
                'cod_incoterms' => $datos['cod_incoterms'] ?? null,
                'desc_incoterms' => $this->descIncoterms($datos['cod_incoterms'] ?? null),
                'created_by' => $usuario?->id ?? Auth::id(),
            ]);

            $this->maquina->registrarCreacion($dte, $usuario);

            // Refresca para exponer los defaults de BD (totales en 0.00).
            return $dte->refresh();
        });
    }

    /**
     * Actualiza los DATOS ADUANEROS (tipo de ítem, recinto fiscal, tipo de régimen,
     * régimen, incoterm) de una Factura de exportación (11) que sigue en borrador.
     * Reservado a tipo 11 y solo mientras el documento sea editable (esEditable()):
     * NO genera JSON, NO consume correlativo, NO cambia el estado.
     *
     * @param  array<string, mixed>  $datos  tipo_item_expor, recinto_fiscal, tipo_regimen, regimen, cod_incoterms
     *
     * @throws DocumentoInmutableException si el DTE no está en borrador
     * @throws ValidationException si el DTE no es FEX o algún código no es válido
     */
    public function actualizarDatosAduaneros(Dte $dte, array $datos): Dte
    {
        $this->verificarEditable($dte);

        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            throw ValidationException::withMessages([
                'tipo_dte' => 'Los datos aduaneros solo se editan en una Factura de exportación (11).',
            ]);
        }

        $validado = Validator::make($datos, [
            'tipo_item_expor' => ['required', Rule::in(array_map(fn (TipoItemExportacion $t) => $t->value, TipoItemExportacion::cases()))],
            'recinto_fiscal' => ['required', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '027')],
            'tipo_regimen' => ['required', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '033')],
            'regimen' => ['required', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '028')],
            'cod_incoterms' => ['required', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '031')],
        ], [
            'recinto_fiscal.exists' => 'El recinto fiscal seleccionado no es válido (CAT-027).',
            'tipo_regimen.exists' => 'El tipo de régimen seleccionado no es válido (CAT-033).',
            'regimen.exists' => 'El régimen seleccionado no es válido (CAT-028).',
            'cod_incoterms.exists' => 'El INCOTERM seleccionado no es válido (CAT-031).',
        ])->validate();

        return DB::transaction(function () use ($dte, $validado) {
            $dte->tipo_item_expor = $validado['tipo_item_expor'];
            $dte->recinto_fiscal = $validado['recinto_fiscal'];
            $dte->tipo_regimen = $validado['tipo_regimen'];
            $dte->regimen = $validado['regimen'];
            $dte->cod_incoterms = $validado['cod_incoterms'];
            // desc_incoterms SIEMPRE se resuelve server-side desde CAT-031; nunca texto libre.
            $dte->desc_incoterms = $this->descIncoterms($validado['cod_incoterms']);
            $dte->save();

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
     * @throws ValidationException
     */
    public function crearNotaCredito(?Dte $original, array $datos = [], ?User $usuario = null): Dte
    {
        // Modalidad interna (por productos vs. por monto/concepto).
        $tipoRaw = $datos['tipo'] ?? TipoNotaCredito::DevolucionProducto->value;
        $tipo = $tipoRaw instanceof TipoNotaCredito ? $tipoRaw : TipoNotaCredito::from((string) $tipoRaw);

        // REGLA OBLIGATORIA: TODA Nota de Crédito (05) — devolución, avería, pronto pago,
        // cualquier tipo — debe estar vinculada a un CCF (03) ACEPTADO por Hacienda. Sin un CCF
        // aceptado relacionado no puede crearse ni emitirse (no existe oficialmente ante el MH).
        // La avería puede guardarse sin documento relacionado todavía. No es otra clase de
        // avería: es un BORRADOR INCOMPLETO. El producto dañado existe y hay que anotarlo
        // aunque en ese momento no se sepa contra qué CCF acreditarlo, y colgarlo de un CCF
        // cualquiera sería inventar una relación fiscal que no ocurrió.
        //
        // Guardar no es emitir. El esquema oficial del MH para la NC (05) declara
        // `documentoRelacionado` como requerido con minItems 1, tanto en v3 como en v4, así
        // que sin CCF la nota NO puede generarse, firmarse ni transmitirse: el candado está
        // en DteGeneracionService::validar() y en ValidacionPreJsonService.
        //
        // Las demás modalidades sí lo exigen desde el inicio: una devolución acredita
        // líneas de un CCF concreto y un pronto pago descuenta sobre su monto; sin original
        // no hay nada que acreditar ni sobre qué calcular.
        $averiaSinCcf = $original === null && $tipo->esPorAveria();

        if ($original === null && ! $averiaSinCcf) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Para generar una Nota de Crédito debe seleccionar un CCF aceptado relacionado.',
            ]);
        }

        if ($averiaSinCcf) {
            return $this->crearAveriaSinCcf($datos, $tipo, $usuario);
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
        // En PRODUCCIÓN (ambiente 01) no basta el estado Aceptado genérico: el CCF debe
        // tener aceptación REAL de Hacienda (sello oficial no-mock + fecha de procesamiento
        // MH). En pruebas/mock (ambiente 00) se conserva el comportamiento actual (basta
        // Aceptado), para no bloquear los flujos de prueba. Reutiliza el MISMO scope
        // aceptadoRealMh() para no divergir de criterio.
        if (($original->ambiente?->value ?? null) === AmbienteHacienda::Produccion->value
            && ! Dte::whereKey($original->id)->aceptadoRealMh()->exists()) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'En producción, la nota de crédito solo puede crearse desde un CCF con aceptación REAL de Hacienda (sello oficial). Este CCF de producción no tiene una aceptación real registrada.',
            ]);
        }
        if (array_key_exists('cliente_id', $datos)
            && $datos['cliente_id'] !== null
            && (int) $datos['cliente_id'] !== (int) $original->cliente_id) {
            throw ValidationException::withMessages([
                'cliente_id' => 'La nota de crédito debe usar el mismo cliente del documento original.',
            ]);
        }

        // Series independientes: la NC debe usar el MISMO establecimiento/punto de venta
        // del CCF relacionado (ej. no permitir NC en P002 contra un CCF de P001, ni al
        // revés). Si no llegan en $datos, se heredan directo del original más abajo (sin
        // posibilidad de mismatch); esto solo actúa cuando SÍ llegó un valor explícito.
        if (array_key_exists('establecimiento_id', $datos)
            && $datos['establecimiento_id'] !== null
            && (int) $datos['establecimiento_id'] !== (int) $original->establecimiento_id) {
            throw ValidationException::withMessages([
                'establecimiento_id' => 'La nota de crédito debe usar el mismo establecimiento del CCF relacionado.',
            ]);
        }
        if (array_key_exists('punto_venta_id', $datos)
            && $datos['punto_venta_id'] !== null
            && (int) $datos['punto_venta_id'] !== (int) $original->punto_venta_id) {
            throw ValidationException::withMessages([
                'punto_venta_id' => 'La nota de crédito debe usar el mismo punto de venta del CCF relacionado.',
            ]);
        }

        // Cliente: SIEMPRE el del original (es el contribuyente con el NIT/NRC).
        $clienteId = $original?->cliente_id ?? ($datos['cliente_id'] ?? null);

        // Sala RECEPTORA de la nota de crédito. Ver resolverSalaNotaCredito(): por
        // defecto la del CCF, y solo las modalidades por MONTO (pronto pago, descuento
        // posterior, ajuste comercial, otro) admiten una sala distinta del mismo cliente.
        $sucursalId = $this->resolverSalaNotaCredito($original, $tipo, $datos, $clienteId);

        // Sala a la que CORRESPONDE la avería, independiente de la sala receptora.
        $salaAveria = $this->resolverSalaAveria($tipo, $datos, $clienteId);

        // Cruzar de sala nunca es rutina. Hay dos formas de hacerlo y las dos cuestan lo
        // mismo: una explicación escrita.
        //
        //   RECEPTORA distinta  → cambia el establecimiento y la dirección impresos en la
        //                         nota, sin cambiar el cliente fiscal.
        //   HALLAZGO distinto   → la avería se encontró en una sala y se acredita contra
        //                         un CCF de otra sala del mismo cliente.
        //
        // Sin motivo escrito nadie puede reconstruir después por qué se hizo. La pantalla
        // lo avisa con un cartel; esto es la parte que no depende del navegador.
        $salaCcf = $original?->cliente_sucursal_id;
        $receptoraCruzada = $original !== null && $sucursalId !== null
            && (int) $sucursalId !== (int) $salaCcf;
        $averiaCruzada = $original !== null && $salaAveria !== null
            && (int) $salaAveria !== (int) $salaCcf;

        if (($receptoraCruzada || $averiaCruzada) && trim((string) ($datos['motivo'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'motivo' => $receptoraCruzada
                    ? 'Para emitir la nota de crédito a una sala distinta a la del CCF relacionado, el motivo es obligatorio: explique por qué.'
                    : 'La avería corresponde a una sala distinta a la del CCF relacionado: el motivo es obligatorio para dejar constancia de por qué se acredita contra ese CCF.',
            ]);
        }

        // Orden de compra: se CONGELA desde el CCF relacionado (no se acepta del request).
        $ordenCompra = $original?->numero_orden_compra;

        return DB::transaction(function () use ($original, $datos, $tipo, $clienteId, $sucursalId, $ordenCompra, $salaAveria, $usuario) {
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
                'sucursal_averia_id' => $salaAveria,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => $original?->moneda ?? 'USD',
                'descuento_global' => '0.00',
                'aplica_retencion_iva' => false,
                'created_by' => $usuario?->id ?? Auth::id(),
            ]);

            $this->maquina->registrarCreacion($nc, $usuario, 'Creación de nota de crédito');

            // AUDITORÍA: la sala receptora de la NC difiere de la del CCF relacionado
            // (caso pronto pago a una sala administrativa). Se deja rastro explícito de
            // quién lo hizo, sobre qué CCF y entre qué salas.
            if ($original && (int) $sucursalId !== (int) $original->cliente_sucursal_id) {
                activity('dte_nota_credito_sala')
                    ->performedOn($nc)
                    ->causedBy($usuario ?? Auth::user())
                    ->withProperties([
                        'dte_relacionado_id' => $original->id,
                        'ccf_numero' => $original->numero_control ?? $original->numero_interno,
                        'sala_ccf_id' => $original->cliente_sucursal_id,
                        'sala_ccf_nombre' => $original->clienteSucursal?->nombre,
                        'sala_nc_id' => $sucursalId,
                        'sala_nc_nombre' => ClienteSucursal::find($sucursalId)?->nombre,
                        'cliente_id' => $clienteId,
                        'tipo_nota_credito' => $tipo->value,
                        'motivo' => $datos['motivo'] ?? null,
                    ])
                    ->log('emitió la nota de crédito a una sala distinta a la del CCF relacionado');
            }

            return $nc->refresh();
        });
    }

    /**
     * Vincula un CCF aceptado a una nota que se registró SIN documento relacionado.
     *
     * Es el segundo tiempo de una avería guardada sin CCF: ya está anotada y ahora
     * aparece un CCF del mismo cliente contra el cual acreditarla. Recién con esto la nota
     * puede generarse, porque recién con esto tiene el `documentoRelacionado` que el
     * esquema del MH exige.
     *
     * Lo que se valida es lo mismo que al crear una nota normal, sin descuentos:
     *  - la nota sigue siendo un borrador (un documento emitido es inmutable);
     *  - todavía NO tiene documento relacionado (esto no sirve para cambiar de CCF: eso
     *    sería mover una nota de un original a otro, que es otra cosa y no se permite acá);
     *  - el CCF es un 03 ACEPTADO —real en producción—, del MISMO cliente, del mismo
     *    ambiente, ni archivado ni con invalidación sellada;
     *  - si el CCF es de OTRA SALA que la de la avería, hace falta motivo escrito.
     *
     * La sala de la avería NO se toca: a qué sala pertenece el producto dañado es un
     * hecho, y el CCF que se le vincule después no lo cambia. Lo que sí se adopta del CCF es lo que la
     * nota necesita para emitirse con su original: emisor, punto de venta, ambiente,
     * orden de compra y la sala receptora del documento.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws DocumentoInmutableException
     * @throws ValidationException
     */
    public function vincularCcfANotaCredito(Dte $nc, Dte $ccf, array $datos = [], ?User $usuario = null): Dte
    {
        $this->verificarEditable($nc);

        if ($nc->tipo_dte !== TipoDte::NotaCredito) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Solo una nota de crédito puede relacionarse con un CCF.',
            ]);
        }

        if ($nc->dte_relacionado_id !== null) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Esta nota de crédito ya tiene un CCF relacionado.',
            ]);
        }

        $this->validarCcfRelacionable($ccf, $nc->cliente_id);

        // Cruzar de sala es legítimo —el producto se encontró en una sala y se acredita
        // contra un CCF de otra del mismo cliente—, pero nunca es rutina: sin explicación
        // escrita nadie puede reconstruir después por qué se eligió ese CCF.
        $salaAveria = $nc->sucursal_averia_id ?? $nc->cliente_sucursal_id;
        $motivo = trim((string) ($datos['motivo'] ?? ''));

        if ($salaAveria !== null
            && (int) $salaAveria !== (int) $ccf->cliente_sucursal_id
            && $motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'El CCF elegido es de otra sala del mismo cliente: el motivo es obligatorio para dejar constancia de por qué se acredita contra ese CCF.',
            ]);
        }

        return DB::transaction(function () use ($nc, $ccf, $motivo, $usuario, $salaAveria) {
            $nc->dte_relacionado_id = $ccf->id;
            $nc->cliente_id = $ccf->cliente_id;
            // Sala RECEPTORA del documento: la del CCF, como en cualquier nota por avería.
            // La de la avería queda intacta, en su propia columna.
            $nc->cliente_sucursal_id = $ccf->cliente_sucursal_id;
            $nc->establecimiento_id = $ccf->establecimiento_id;
            $nc->punto_venta_id = $ccf->punto_venta_id;
            $nc->ambiente = $ccf->ambiente?->value ?? $nc->ambiente?->value;
            $nc->numero_orden_compra = $ccf->numero_orden_compra;
            if ($motivo !== '') {
                $nc->motivo = $motivo;
            }
            $nc->save();

            // El descuento y la retención dependen del CCF relacionado, que hasta recién
            // no existía: sin recalcular, la nota conservaría los totales que tenía cuando
            // no había original del cual heredar nada.
            $this->recalcular($nc);

            activity('dte_nota_credito_vinculo_ccf')
                ->performedOn($nc)
                ->causedBy($usuario ?? Auth::user())
                ->withProperties([
                    'dte_relacionado_id' => $ccf->id,
                    'ccf_numero' => $ccf->numero_control ?? $ccf->numero_interno,
                    'sala_ccf_id' => $ccf->cliente_sucursal_id,
                    'sala_averia_id' => $salaAveria,
                    'cruza_de_sala' => $salaAveria !== null && (int) $salaAveria !== (int) $ccf->cliente_sucursal_id,
                    'motivo' => $motivo !== '' ? $motivo : null,
                ])
                ->log('vinculó un CCF aceptado a una avería registrada sin documento relacionado');

            return $nc->refresh();
        });
    }

    /**
     * Reglas de ELEGIBILIDAD de un CCF como documento relacionado de una nota.
     *
     * Es la misma lista que aplica al crear una nota desde un CCF; vive aparte porque la
     * vinculación posterior tiene que exigir exactamente lo mismo y no una versión
     * relajada. Un CCF que no se podía elegir al crear tampoco puede colarse después.
     *
     * @throws ValidationException
     */
    private function validarCcfRelacionable(Dte $ccf, ?int $clienteId): void
    {
        if ($ccf->tipo_dte !== TipoDte::CreditoFiscal) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'El documento relacionado de una nota de crédito debe ser un Comprobante de Crédito Fiscal (CCF).',
            ]);
        }

        if ($ccf->estado !== EstadoDte::Aceptado) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'Solo se puede relacionar un CCF ACEPTADO por Hacienda (estado actual: '.$ccf->estado->label().').',
            ]);
        }

        if ($clienteId !== null && (int) $ccf->cliente_id !== (int) $clienteId) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'El CCF debe pertenecer al mismo cliente de la nota de crédito.',
            ]);
        }

        // Ambiente: una nota de producción no puede acreditar un documento de pruebas.
        if (($ccf->ambiente?->value ?? null) !== config('dte.ambiente')) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'El CCF pertenece a otro ambiente: no puede relacionarse con esta nota de crédito.',
            ]);
        }

        if (filled($ccf->sello_invalidacion) || $ccf->archivado) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'El CCF fue invalidado o archivado: no puede relacionarse con una nota de crédito.',
            ]);
        }

        // En PRODUCCIÓN no basta el estado Aceptado: hace falta aceptación REAL (sello
        // oficial no-mock + fecha de procesamiento), igual que al crear la nota.
        if (($ccf->ambiente?->value ?? null) === AmbienteHacienda::Produccion->value
            && ! Dte::whereKey($ccf->id)->aceptadoRealMh()->exists()) {
            throw ValidationException::withMessages([
                'dte_relacionado_id' => 'En producción, la nota de crédito solo puede relacionarse con un CCF con aceptación REAL de Hacienda (sello oficial).',
            ]);
        }
    }

    /**
     * Crea el borrador de una avería que TODAVÍA no tiene documento relacionado.
     *
     * Es un borrador INCOMPLETO, no un documento emitible: nace sin CCF, sin orden de
     * compra y sin nada heredado, porque no hay de dónde heredarlo. El cliente y la sala
     * los declara quien registra, y el emisor sale de la configuración como en cualquier
     * otro documento propio.
     *
     * Lo que sí se valida acá es lo único que después no tendría arreglo: que haya
     * cliente, y que la sala pertenezca a ese cliente. Cruzar de cliente no se permite
     * nunca, ni siquiera antes de haber elegido un CCF.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function crearAveriaSinCcf(array $datos, TipoNotaCredito $tipo, ?User $usuario): Dte
    {
        $clienteId = $datos['cliente_id'] ?? null;
        if (blank($clienteId)) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Indique el cliente al que corresponde la avería.',
            ]);
        }
        $clienteId = (int) $clienteId;

        $salaId = blank($datos['cliente_sucursal_id'] ?? null) ? null : (int) $datos['cliente_sucursal_id'];
        if ($salaId !== null) {
            $sala = ClienteSucursal::find($salaId);
            if (! $sala || (int) $sala->cliente_id !== $clienteId) {
                throw ValidationException::withMessages([
                    'cliente_sucursal_id' => 'La sala debe pertenecer al cliente indicado.',
                ]);
            }
        }

        // Guardar una avería SIN documento relacionado es excepcional: lo normal es que el
        // CCF exista. Sin una explicación escrita, un borrador incompleto a propósito no se
        // distingue de uno que quedó a medias por descuido, y el que queda a medias no lo
        // reclama nadie. El formulario además exige activar la excepción a mano; esto es la
        // parte que no depende del navegador.
        //
        // Se comprueba DESPUÉS del cliente y la sala: si la sala es de otro cliente, ese es
        // el problema concreto que hay que decir, y no la falta de motivo.
        if (trim((string) ($datos['motivo'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Para guardar una avería sin CCF relacionado, explique el motivo de la excepción.',
            ]);
        }

        $emisor = ResuelveEmisorUnico::resolver(
            $datos['establecimiento_id'] ?? null,
            $datos['punto_venta_id'] ?? null,
        );

        return DB::transaction(function () use ($datos, $tipo, $clienteId, $salaId, $emisor, $usuario) {
            $nc = Dte::create([
                'tipo_dte' => TipoDte::NotaCredito->value,
                'tipo_nota_credito' => $tipo->value,
                'estado' => EstadoDte::Borrador->value,
                'ambiente' => config('dte.ambiente'),
                'establecimiento_id' => $emisor['establecimiento_id'] ?? null,
                'punto_venta_id' => $emisor['punto_venta_id'] ?? null,
                'correlativo_id' => null,
                'cliente_id' => $clienteId,
                'cliente_sucursal_id' => $salaId,
                // Sin CCF: no hay documento relacionado ni orden de compra que copiar.
                'dte_relacionado_id' => null,
                'numero_orden_compra' => null,
                'condicion_operacion' => 1,
                'motivo' => $datos['motivo'] ?? null,
                // Sala a la que CORRESPONDE la avería: la declarada. No hay CCF del que
                // difiera todavía, y cuando lo haya seguirá siendo esta.
                'sucursal_averia_id' => $salaId,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => 'USD',
                'descuento_global' => '0.00',
                'aplica_retencion_iva' => false,
                'created_by' => $usuario?->id ?? Auth::id(),
            ]);

            $this->maquina->registrarCreacion($nc, $usuario, 'Registro de avería sin CCF relacionado todavía');

            return $nc->refresh();
        });
    }

    /**
     * Sala a la que CORRESPONDE la avería.
     *
     * Solo la avería la lleva; en cualquier otra modalidad el valor se DESCARTA en vez de
     * guardarse, para que nadie tenga que interpretar después qué significaba una sala de
     * avería colgada de un pronto pago.
     *
     * Es independiente de la sala RECEPTORA del documento: acreditar contra un CCF de otra
     * sala del mismo cliente no mueve de lugar al producto dañado.
     *
     * Lo único que se valida es que la sala EXISTA y sea DEL MISMO CLIENTE. Que esté
     * activa no se exige: una sala se desactiva cuando deja de operar, y una avería puede
     * anotarse justo al vaciar el inventario de una sala que se está cerrando. Cruzar de
     * CLIENTE, en cambio, no se permite nunca.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function resolverSalaAveria(TipoNotaCredito $tipo, array $datos, ?int $clienteId): ?int
    {
        if (! $tipo->esPorAveria()) {
            return null;
        }

        $pedida = $datos['sucursal_averia_id'] ?? null;
        $pedida = ($pedida === '' || $pedida === null) ? null : (int) $pedida;

        if ($pedida === null) {
            return null;
        }

        $sucursal = ClienteSucursal::find($pedida);
        if (! $sucursal) {
            throw ValidationException::withMessages([
                'sucursal_averia_id' => 'La sala indicada no existe.',
            ]);
        }
        if ($clienteId !== null && (int) $sucursal->cliente_id !== (int) $clienteId) {
            throw ValidationException::withMessages([
                'sucursal_averia_id' => 'La sala de la avería debe pertenecer al mismo cliente del CCF relacionado.',
            ]);
        }

        return $pedida;
    }

    /**
     * Resuelve la SALA RECEPTORA de una nota de crédito.
     *
     * El cliente fiscal (NIT/NRC/razón social) siempre es el del CCF relacionado; la
     * sala solo define el establecimiento mostrado y la DIRECCIÓN del receptor. Eso
     * permite el flujo real de PRONTO PAGO de Calleja: el CCF pertenece a una sala de
     * Súper Selectos, pero la nota se emite a una sala administrativa ("Bodega Oficina
     * Central Calleja") que normalmente nunca recibe un CCF propio.
     *
     * Reglas:
     *  - Por defecto SIEMPRE la sala del CCF relacionado.
     *  - Solo las modalidades por MONTO ({@see TipoNotaCredito::esPorMonto()}: pronto
     *    pago, descuento posterior, ajuste comercial, otro) admiten una sala distinta.
     *  - Devolución / faltante / avería quedan atadas a la sala del CCF: si llega otra,
     *    se rechaza en lugar de ignorarla en silencio.
     *  - La sala elegida debe pertenecer al MISMO cliente, estar activa y permitir NC.
     *  - NO se exige que la sala tenga un CCF propio previo.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function resolverSalaNotaCredito(?Dte $original, TipoNotaCredito $tipo, array $datos, ?int $clienteId): ?int
    {
        $salaCcf = $original?->cliente_sucursal_id;
        $pedida = $datos['cliente_sucursal_id'] ?? null;
        $pedida = ($pedida === '' || $pedida === null) ? null : (int) $pedida;

        // Sin selección explícita: la sala del CCF (o la de los datos si no hay original).
        if ($pedida === null) {
            return $salaCcf !== null ? (int) $salaCcf : null;
        }

        // Misma sala del CCF: nada que validar más allá de lo ya heredado.
        if ($salaCcf !== null && $pedida === (int) $salaCcf) {
            return $pedida;
        }

        // Sala DISTINTA: solo permitido en las modalidades por monto.
        if ($salaCcf !== null && ! $tipo->esPorMonto()) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'Una nota de crédito por '.mb_strtolower($tipo->label())
                    .' debe emitirse a la misma sala del CCF relacionado. Solo las notas por monto '
                    .'(pronto pago, descuento posterior, ajuste comercial u otro) pueden usar otra sala.',
            ]);
        }

        $sucursal = ClienteSucursal::find($pedida);
        if (! $sucursal) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'La sala seleccionada no existe.',
            ]);
        }
        if ($clienteId !== null && (int) $sucursal->cliente_id !== (int) $clienteId) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'La sala receptora debe pertenecer al mismo cliente del CCF relacionado.',
            ]);
        }
        if (! $sucursal->activo) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'La sala seleccionada está inactiva.',
            ]);
        }
        if ($sucursal->permite_nota_credito === false) {
            throw ValidationException::withMessages([
                'cliente_sucursal_id' => 'Esta sala no permite notas de crédito.',
            ]);
        }

        return $pedida;
    }

    /**
     * Reversión TOTAL de un CCF mediante una Nota de Crédito por DEVOLUCIÓN.
     *
     * Crea el borrador relacionado reutilizando {@see crearNotaCredito()} (que hereda
     * cliente, sala, orden de compra, ambiente, establecimiento y punto de venta desde
     * el CCF, y valida que sea un CCF ACEPTADO —real en producción—), y luego acredita
     * AUTOMÁTICAMENTE cada línea del CCF por su SALDO ACREDITABLE DISPONIBLE reutilizando
     * {@see acreditarLinea()}. Respeta notas de crédito parciales previas (solo copia el
     * saldo que queda) y omite las líneas ya totalmente acreditadas.
     *
     * TODO ocurre dentro de una ÚNICA transacción: si cualquier línea falla, se revierte
     * también la creación del borrador (no queda una NC a medias). Si NINGUNA línea tiene
     * saldo, se hace rollback total con un mensaje claro.
     *
     * NO emite, genera, firma ni transmite, y NO toca el CCF original: la NC queda en
     * estado Borrador para revisión y emisión manual.
     *
     * @throws ValidationException si el CCF no es válido o ya no
     *                             queda saldo acreditable en ninguna línea
     */
    public function revertirCcfCompleto(?Dte $original, ?User $usuario = null): Dte
    {
        return DB::transaction(function () use ($original, $usuario) {
            $referencia = $original?->numero_control ?? $original?->numero_interno ?? ('#'.$original?->id);

            // Borrador de devolución con TODAS las herencias y validaciones existentes.
            $nc = $this->crearNotaCredito($original, [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
                'motivo' => 'Reversión total del CCF '.$referencia,
            ], $usuario);

            $lineasAcreditadas = 0;
            foreach ($original->lineas as $lineaOriginal) {
                $disponible = $this->saldoAcreditableDisponible($lineaOriginal);
                if (Dinero::comparar($disponible, '0') <= 0) {
                    continue; // línea ya totalmente acreditada por NC previas
                }

                $this->acreditarLinea($nc, $lineaOriginal, $disponible);
                $lineasAcreditadas++;
            }

            if ($lineasAcreditadas === 0) {
                // Rollback total: sin líneas con saldo no dejamos un borrador vacío.
                throw ValidationException::withMessages([
                    'dte_relacionado_id' => 'Este CCF ya no tiene saldo acreditable disponible: '
                        .'todas sus líneas ya fueron revertidas por notas de crédito previas.',
                ]);
            }

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
     * @throws ValidationException
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
            'tipo_impuesto' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, TipoImpuesto::cases()))],
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
     * @throws ValidationException
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
     * @throws ValidationException
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
     * ESTABLECE la cantidad acreditada de una línea del CCF original, en lugar de sumarla.
     *
     * Es la operación que necesita la captura de productos para sentirse como la del CCF:
     * ahí se escribe la cantidad que uno quiere y el borrador queda con esa cantidad,
     * escriba uno una vez o cinco. {@see acreditarLinea()} SUMA —dos envíos de 3 dejan 6—,
     * que es lo correcto para acreditar de a poco pero convierte cualquier corrección en
     * una resta mental, y una línea acreditada de más no se podía arreglar sin borrarla.
     *
     * Cantidad vacía o 0 QUITA la línea, igual que en el catálogo del CCF. No es un caso
     * especial escondido: es la forma natural de decir «esta línea al final no va».
     *
     * La cuenta del saldo se apoya en una propiedad de la transacción: la línea anterior
     * se borra ANTES de volver a acreditar, así que el saldo disponible que ve
     * {@see acreditarLinea()} ya no se cuenta a sí mismo. Sin eso, subir de 3 a 4 sobre un
     * saldo de 5 fallaría por «3 + 4 > 5», que es una cuenta que no le importa a nadie. Y
     * como todo ocurre dentro de la misma transacción, un saldo insuficiente deja la línea
     * vieja intacta en vez de una nota a medias.
     *
     * @return array{accion: string, linea: DteLinea|null}
     *
     * @throws DocumentoInmutableException
     * @throws SaldoAcreditableExcedidoException
     * @throws ValidationException
     */
    public function establecerCantidadAcreditada(Dte $nc, DteLinea $lineaOriginal, string|int|float|null $cantidad): array
    {
        $this->verificarEditable($nc);

        if ($nc->tipo_nota_credito !== null && ! $nc->tipo_nota_credito->esPorProductos()) {
            throw ValidationException::withMessages([
                'tipo' => 'Esta nota de crédito no acredita líneas del documento original.',
            ]);
        }

        if ((int) $nc->dte_relacionado_id !== (int) $lineaOriginal->dte_id) {
            throw ValidationException::withMessages([
                'dte_linea_original_id' => 'La línea no pertenece al documento original de la nota de crédito.',
            ]);
        }

        $existente = $nc->lineas()->where('dte_linea_original_id', $lineaOriginal->id)->first();

        $vacia = $cantidad === null || $cantidad === ''
            || Dinero::comparar(Dinero::de($cantidad), '0') <= 0;

        if ($vacia) {
            if ($existente === null) {
                return ['accion' => 'sin_cambio', 'linea' => null];
            }

            $this->eliminarLinea($existente);

            return ['accion' => 'eliminada', 'linea' => null];
        }

        return DB::transaction(function () use ($nc, $lineaOriginal, $cantidad, $existente) {
            if ($existente !== null) {
                $existente->delete();
            }

            $linea = $this->acreditarLinea($nc, $lineaOriginal, $cantidad);

            return [
                'accion' => $existente !== null ? 'actualizada' : 'agregada',
                'linea' => $linea,
            ];
        });
    }

    /**
     * Saldo acreditable disponible de una línea del documento original: cantidad
     * original − lo ya acreditado por notas de crédito que siguen consumiendo saldo.
     * Fuente única del cálculo de saldo, reutilizada por {@see validarSaldoAcreditable()}
     * (acreditación puntual) y por {@see revertirCcfCompleto()} (reversión total).
     */
    private function saldoAcreditableDisponible(DteLinea $lineaOriginal): string
    {
        // Qué NC dejan de consumir saldo (invalidadas y rechazadas archivadas) vive en
        // Dte::scopeConsumeSaldoAcreditable(), única fuente de la regla.
        $yaAcreditado = Dinero::de(
            DteLinea::where('dte_linea_original_id', $lineaOriginal->id)
                ->whereHas('dte', fn ($q) => $q->consumeSaldoAcreditable())
                ->sum('cantidad') ?? 0
        );

        return Dinero::restar(Dinero::de($lineaOriginal->cantidad), $yaAcreditado);
    }

    /**
     * Verifica que la cantidad a acreditar no supere el saldo de la línea original
     * (cantidad original − lo ya acreditado en cualquier NC).
     *
     * @throws SaldoAcreditableExcedidoException
     */
    private function validarSaldoAcreditable(DteLinea $lineaOriginal, string $cantidad): void
    {
        if (Dinero::comparar($cantidad, $this->saldoAcreditableDisponible($lineaOriginal)) > 0) {
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
     * Agrega una línea SIN producto del catálogo nacional (producto_id queda NULL):
     * descripción libre congelada. Reservado a Factura de Exportación (11) — pensado
     * para copiar líneas desde una Lista de Empaque (cajas × precio por caja), donde
     * el producto de exportación no es un `App\Models\Producto` del catálogo DTE.
     *
     * @param  array<string, mixed>  $datos  descripcion, unidad_codigo, cantidad, precio_unitario, descuento_monto?, tipo_producto?, tipo_impuesto?
     *
     * @throws DocumentoInmutableException
     * @throws ValidationException
     */
    public function agregarLineaLibre(Dte $dte, array $datos): DteLinea
    {
        $this->verificarEditable($dte);

        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            throw ValidationException::withMessages([
                'tipo_dte' => 'Las líneas sin producto de catálogo (descripción libre) solo se admiten en Factura de exportación (11).',
            ]);
        }

        $validado = Validator::make($datos, [
            'descripcion' => ['required', 'string', 'max:1000'],
            'unidad_codigo' => ['required', 'string', 'max:3'],
            'cantidad' => ['required'],
            'precio_unitario' => ['required'],
            'descuento_monto' => ['nullable', 'numeric', 'min:0'],
            'tipo_producto' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, TipoProducto::cases()))],
            'tipo_impuesto' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, TipoImpuesto::cases()))],
        ])->validate();

        $descuento = $this->montoDe($validado['descuento_monto'] ?? 0);
        // Reusa la misma validación de negocio de cantidad/precio/descuento que las
        // líneas por producto (entero ≥ 1, precio ≥ 0, descuento no mayor al importe).
        AgregarLineaDteRequest::validarValores($validado['cantidad'], $validado['precio_unitario'], $descuento);

        return DB::transaction(function () use ($dte, $validado, $descuento) {
            $linea = new DteLinea([
                'descripcion' => $validado['descripcion'],
                'unidad_codigo' => $validado['unidad_codigo'],
                'tipo_producto' => $validado['tipo_producto'] ?? TipoProducto::Bien->value,
                'tipo_impuesto' => $validado['tipo_impuesto'] ?? TipoImpuesto::Gravado->value,
            ]);
            $linea->dte_id = $dte->id;
            $linea->numero_linea = $this->siguienteNumeroLinea($dte);
            $linea->cantidad = (string) $validado['cantidad'];
            $linea->precio_unitario = (string) $validado['precio_unitario'];
            $linea->descuento_monto = $descuento;
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
            // 'descripcion'/'unidad_codigo' solo llegan aquí para líneas SIN producto de
            // catálogo (FEX libre); el FormRequest es quien restringe cuándo se aceptan.
            foreach (['cantidad', 'precio_unitario', 'descuento_monto', 'tipo_impuesto', 'descripcion', 'unidad_codigo'] as $campo) {
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

            // Retención AUTOMÁTICA: gran contribuyente + (CCF, o NC cuyo CCF retuvo) +
            // base gravada neta > umbral. La base se evalúa DESPUÉS del descuento (con el
            // monto recién calculado), nunca sobre el bruto.
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
     * Decide automáticamente si aplica retención de IVA. En los DOS tipos que pueden
     * retener el receptor debe ser gran contribuyente; lo que cambia es el resto:
     *
     *   CCF (03): receptor agente de retención.
     *   NC  (05): receptor agente de retención Y CCF relacionado sujeto a retención,
     *             cualquiera que sea la modalidad ({@see retencionHeredadaDeNotaCredito}).
     *
     * Y en ambos, base gravada NETA (total_gravado − descuento_gravado) > umbral.
     *
     * El umbral se evalúa SIEMPRE sobre la base neta del documento que se está
     * calculando — también en las notas de crédito, que juzgan su propio monto y no el
     * del CCF original.
     *
     * @param  array<int, LineaDocumento>  $documentos
     */
    private function decidirRetencionAutomatica(Dte $dte, array $documentos, string $montoDescuento = '0.00'): bool
    {
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            if (! $this->retencionHeredadaDeNotaCredito($dte)) {
                return false;
            }
        } elseif ($dte->tipo_dte !== TipoDte::CreditoFiscal || ! $this->esAgenteRetencion($dte)) {
            return false;
        }

        return $this->baseNetaSuperaUmbralRetencion(
            $this->baseGravadaNeta($dte, $documentos, $montoDescuento)
        );
    }

    /**
     * Base gravada NETA (total gravado − descuento gravado) del documento: cálculo
     * sin retención pero CON el descuento ya aplicado, solo para poder juzgar el umbral.
     *
     * @param  array<int, LineaDocumento>  $documentos
     */
    private function baseGravadaNeta(Dte $dte, array $documentos, string $montoDescuento): string
    {
        $base = $this->calculadora->calcular(
            $documentos,
            $dte->tipo_dte,
            $montoDescuento,
            $dte->flete ?? 0,
            $dte->seguro ?? 0,
            false,
        );

        return Dinero::redondear(Dinero::restar($base->totalGravado, $base->descuentoGravado), 2);
    }

    /**
     * ¿La base gravada neta alcanza para retener? Comparación ESTRICTA contra
     * dte.retencion_iva_umbral: una base exactamente igual al umbral NO retiene.
     * Es la semántica con la que se emitieron todos los CCF aceptados y la misma
     * que ahora usan las NC.
     */
    private function baseNetaSuperaUmbralRetencion(string $baseNeta): bool
    {
        return Dinero::comparar($baseNeta, (string) config('dte.retencion_iva_umbral', 100)) > 0;
    }

    /**
     * ¿La NC puede retener? Dos condiciones, ninguna de ellas la MODALIDAD:
     *
     *   1. El receptor es gran contribuyente (agente de retención), resuelto con la misma
     *      prioridad sucursal → cliente que en el CCF ({@see esAgenteRetencion}).
     *   2. El CCF relacionado quedó sujeto a retención: un original que no retuvo nunca
     *      contagia retención a sus notas, y una NC sin documento relacionado tampoco
     *      tiene de dónde heredarla.
     *
     * La tercera condición —base gravada neta de la PROPIA NC mayor que el umbral— la
     * aplica {@see decidirRetencionAutomatica}, nunca la del CCF original.
     *
     * POR QUÉ SE QUITÓ EL FILTRO POR MODALIDAD: antes solo heredaban las NC por PRODUCTOS
     * y por AVERÍA; las NC por MONTO (pronto pago, descuento posterior, ajuste comercial,
     * concepto «otro») quedaban excluidas por su tipo y salían SIN retención aunque
     * cumplieran las tres condiciones fiscales. Eso dejaba la NC corta frente al albarán
     * del cliente: el caso real fue una NC de pronto pago de base $124.30 que debía
     * retener $1.24 y totalizar $139.22, y salía por $140.46.
     *
     * El filtro por modalidad se había puesto para que no retuvieran las notas chicas
     * (una NC por avería de $0.90, base neta $0.85, sobre un CCF que sí retuvo). Pero de
     * eso ya se encarga el UMBRAL sobre la base propia: con $0.85 no se retiene por
     * monto, sea cual sea la modalidad. La modalidad era una aproximación al umbral, y
     * además equivocada — la retención de IVA la determina el hecho imponible del
     * documento, no el motivo comercial por el que se emite.
     *
     * El descuento global sí sigue dependiendo de la modalidad
     * ({@see porcentajeDescuentoNotaCredito}): son dos reglas distintas y no se mezclan.
     */
    private function retencionHeredadaDeNotaCredito(Dte $nc): bool
    {
        if (! $this->esAgenteRetencion($nc)) {
            return false;
        }

        $nc->loadMissing('dteRelacionado');

        return (bool) $nc->dteRelacionado?->aplica_retencion_iva;
    }

    /**
     * Porcentaje de descuento vigente para el documento: prioridad sucursal →
     * cliente → 0. Las notas de crédito tienen su propia regla ({@see porcentajeDescuentoNotaCredito()}).
     */
    private function porcentajeDescuentoVigente(Dte $dte): string
    {
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            return $this->porcentajeDescuentoNotaCredito($dte);
        }

        $dte->loadMissing(['cliente', 'clienteSucursal']);

        return $this->porcentajeDesde($dte->cliente, $dte->clienteSucursal);
    }

    /**
     * Porcentaje de descuento GLOBAL de una nota de crédito. Se aplica como descuento del
     * resumen (ventaGravada bruto, descuGravada en el resumen), tal como la NC v3 aceptada
     * por el MH; no toca los precios de línea.
     *
     * El origen del porcentaje lo declara el PERFIL del cliente por modalidad, porque la
     * regla real no es la misma para todas. El caso que lo obligó: en los albaranes de
     * crédito de Calleja la AVERÍA sí lleva el descuento comercial (2.89 bruto − 0.14 =
     * 2.75 gravado) y la DEVOLUCIÓN no lo lleva (6 × 1.04 = 6.24 gravado, con «Descuentos
     * Generales: Porcentaje 0» impreso en el propio albarán) aunque su CCF tenga el 5 %
     * de siempre. Con una sola condición para las dos, una de las dos tenía que salir mal.
     *
     * SIN PERFIL se conserva EXACTAMENTE el criterio histórico —heredan las NC por
     * productos y por avería, el resto va a 0 %—, así que ningún cliente que no haya
     * declarado nada cambia de comportamiento.
     */
    private function porcentajeDescuentoNotaCredito(Dte $nc): string
    {
        $regla = $this->perfiles->reglaNotaCredito($nc);

        // Sin regla declarada, el criterio histórico decide el origen.
        $origen = $regla?->descuento_origen ?? ($this->heredaDescuentoPorDefecto($nc)
            ? OrigenDescuentoNc::Ccf
            : OrigenDescuentoNc::Ninguno);

        // Ninguno y tasa_propia traen el porcentaje consigo; ccf lo pide al relacionado.
        $fijo = $origen === OrigenDescuentoNc::Ccf ? null : ($regla?->porcentajeFijo() ?? '0.00');
        if ($fijo !== null) {
            return $fijo;
        }

        return $nc->dteRelacionado !== null
            ? $this->acotarPorcentaje((float) ($nc->dteRelacionado->descuento_porcentaje_aplicado ?? 0))
            : '0.00';
    }

    /**
     * Criterio HISTÓRICO de herencia, previo a los perfiles: solo las NC que acreditan
     * productos —devolución, faltante y avería— heredan el descuento del CCF. Sigue vivo
     * como comportamiento por defecto de todo cliente sin perfil declarado.
     */
    private function heredaDescuentoPorDefecto(Dte $nc): bool
    {
        return ($nc->tipo_nota_credito?->esPorProductos() ?? false)
            || ($nc->tipo_nota_credito?->esPorAveria() ?? false);
    }

    /** Encierra un porcentaje en [0, 100] y lo devuelve con 2 decimales. */
    private function acotarPorcentaje(float $pct): string
    {
        return number_format(max(0.0, min(100.0, $pct)), 2, '.', '');
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
        $requiere = ReglaOrdenCompra::requerida($cliente, $sucursal);

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
     * @throws ValidationException
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
     * @throws ValidationException
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

    /** Descripción del INCOTERM (CAT-031) por código; null si no se seleccionó ninguno. */
    private function descIncoterms(?string $codIncoterms): ?string
    {
        if (blank($codIncoterms)) {
            return null;
        }

        return CatalogoMh::where('cat', '031')->where('codigo', $codIncoterms)->value('valor');
    }
}
