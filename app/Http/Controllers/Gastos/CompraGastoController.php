<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Gasto;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\VincularCompra;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Puente OPCIONAL entre Compras y Gastos.
 *
 * Dos caminos y ninguno crea deuda por su cuenta:
 *
 *  - «Registrar gasto» desde un documento: abre el formulario PRELLENADO. La deuda
 *    nace cuando el operador guarda, no al hacer clic acá.
 *  - «Vincular a gasto existente»: cuelga el documento como RESPALDO de una
 *    obligación que ya estaba. No genera otra deuda; solo pone el papel donde va.
 *
 * El vínculo nunca toca el `estado` de Compras: enviada a contabilidad y pendiente
 * de pago son dos cosas distintas y pueden convivir.
 */
class CompraGastoController extends Controller
{
    public function elegir(Request $request, DocumentoRecibido $documento, VincularCompra $compras, ConsultaGastos $consulta)
    {
        abort_unless($request->user()->can('documentos-recibidos.ver'), 403);
        abort_unless($request->user()->can('gastos.registrar'), 403);

        $usuario = $request->user();
        $candidatos = Gasto::query()
            ->where('beneficiario', $documento->emisor_nombre)
            ->when(! $usuario->can('gastos.personales'), fn ($q) => $q->where('ambito', 'empresarial'))
            ->orderByDesc('id')->limit(25)->get();

        return view('gastos.compras.elegir', [
            'documento' => $documento,
            'motivoBloqueo' => $compras->motivoNoGeneraDeuda($documento),
            'gastoExistente' => $compras->gastoDeLaDeuda($documento),
            'candidatos' => $candidatos,
            'prellenado' => $compras->prellenado($documento),
        ]);
    }

    public function vincular(Request $request, DocumentoRecibido $documento, VincularCompra $compras, AccesoGastos $acceso)
    {
        $datos = $request->validate([
            'gasto_id' => ['required', 'integer', Rule::exists('gastos', 'id')],
            // Desde acá solo se cuelga como respaldo: la deuda ya existe.
            'papel' => ['required', 'in:respaldo'],
        ]);

        $gasto = Gasto::findOrFail($datos['gasto_id']);
        $compras->vincular($request->user(), $gasto, $documento, 'respaldo');

        return redirect()->route('gastos.show', $gasto)
            ->with('gastos.aviso', 'Documento vinculado como respaldo. No se creó otra deuda.');
    }
}
