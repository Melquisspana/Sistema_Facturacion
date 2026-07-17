<?php

namespace App\Services\Exportaciones;

use App\Enums\TipoCliente;
use App\Enums\TipoDte;
use App\Enums\TipoItemExportacion;
use App\Exceptions\Exportaciones\FexYaExisteException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Support\Dte\ResuelveEmisorUnico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea un ÚNICO borrador de Factura de Exportación (FEX, tipo 11) a partir de una
 * Lista de Empaque (Exportacion), copiando sus líneas como snapshot independiente
 * (cajas × precio por caja, sin producto de catálogo nacional).
 *
 * NO firma, NO transmite, NO consume correlativo (crearBorrador no lo hace), NO
 * envía correo, NO genera JSON. Todo dentro de una única transacción: si falla
 * cualquier línea, se revierte por completo (nunca queda un DTE huérfano ni una
 * vinculación parcial).
 */
class CrearFexDesdeExportacionService
{
    public function __construct(
        private readonly DteBorradorService $borradores,
    ) {}

    /**
     * @throws FexYaExisteException  si la Lista ya tiene una FEX vinculada
     * @throws ValidationException   si falta algún requisito (cliente vinculado, líneas válidas, emisor, etc.)
     */
    public function crear(Exportacion $exportacion, ?User $usuario = null): Dte
    {
        return DB::transaction(function () use ($exportacion, $usuario) {
            // Bloquea la fila de la Lista: serializa solicitudes concurrentes sobre
            // la MISMA Lista (la segunda espera, y al continuar ve dte_id ya puesto).
            $exportacion = Exportacion::whereKey($exportacion->id)->lockForUpdate()->firstOrFail();

            if ($exportacion->dte_id !== null) {
                throw new FexYaExisteException($exportacion->dte_id);
            }

            $exportacionCliente = $exportacion->cliente; // ExportacionCliente (belongsTo)
            if ($exportacionCliente === null) {
                throw ValidationException::withMessages([
                    'exportacion_cliente_id' => 'La lista no tiene un cliente de exportación asignado.',
                ]);
            }

            if ($exportacionCliente->cliente_id === null) {
                throw ValidationException::withMessages([
                    'cliente_id' => 'El cliente de exportación no tiene un Cliente DTE vinculado. Vinculalo antes de crear la FEX.',
                ]);
            }

            $cliente = Cliente::find($exportacionCliente->cliente_id);
            if ($cliente === null) {
                throw ValidationException::withMessages([
                    'cliente_id' => 'El Cliente DTE vinculado ya no existe.',
                ]);
            }

            if ($cliente->tipo_cliente !== TipoCliente::Exportacion) {
                throw ValidationException::withMessages([
                    'cliente_id' => 'El Cliente DTE vinculado no es de tipo exportación.',
                ]);
            }

            $exportacion->loadMissing('items');
            if ($exportacion->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'La lista no tiene productos para crear la factura de exportación.',
                ]);
            }

            foreach ($exportacion->items as $item) {
                if (blank($item->nombre_es) && blank($item->nombre_en)) {
                    throw ValidationException::withMessages([
                        'items' => 'Hay un producto de la lista sin descripción.',
                    ]);
                }
                if ((int) $item->cantidad_cajas <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "«{$item->nombre_es}»: la cantidad de cajas debe ser mayor que cero.",
                    ]);
                }
                if ($item->precio_caja === null || (float) $item->precio_caja < 0) {
                    throw ValidationException::withMessages([
                        'items' => "«{$item->nombre_es}»: no tiene un precio por caja válido.",
                    ]);
                }
            }

            // Snapshot ya armado (descripcion/cantidad/precio_unitario/total) desde el
            // propio modelo Exportacion; no se relee el catálogo ni el precio actual.
            $lineasFactura = $exportacion->lineasFactura();

            $emisor = ResuelveEmisorUnico::resolver(null, null);
            if (blank($emisor['establecimiento_id']) || blank($emisor['punto_venta_id'])) {
                throw ValidationException::withMessages([
                    'establecimiento_id' => 'No se pudo determinar el emisor automáticamente: hay más de un establecimiento o punto de venta activo.',
                ]);
            }

            $dte = $this->borradores->crearBorrador([
                'tipo_dte' => TipoDte::FacturaExportacion,
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $emisor['establecimiento_id'],
                'punto_venta_id' => $emisor['punto_venta_id'],
                'condicion_operacion' => 1,
                // Exportacion no guarda flete/seguro hoy (no existen esas columnas en la
                // tabla `exportaciones`); no se inventan datos, quedan en 0.
                'flete' => 0,
                'seguro' => 0,
                'tipo_item_expor' => TipoItemExportacion::Bienes->value,
                'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1',
                'regimen' => '1000.000',
                'cod_incoterms' => '09',
            ], $usuario);

            foreach ($lineasFactura as $linea) {
                $this->borradores->agregarLineaLibre($dte, [
                    'descripcion' => $linea['descripcion'],
                    'unidad_codigo' => '99',
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'descuento_monto' => 0,
                ]);
            }

            $exportacion->update(['dte_id' => $dte->id]);

            return $dte->fresh();
        });
    }
}
