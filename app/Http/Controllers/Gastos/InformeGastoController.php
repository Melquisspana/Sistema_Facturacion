<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\InformeGastos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Informes básicos: pendientes a una fecha de corte y pagos de un período.
 *
 * Son dos preguntas distintas y por eso son dos informes. Un saldo pendiente no
 * pertenece a ningún mes; un pago sí. Mezclarlos en una sola tabla invita a
 * sumarlos, que es justamente lo que no hay que hacer.
 *
 * Exportar exige `gastos.exportar`, aparte de `gastos.ver`: un archivo se guarda,
 * se reenvía y sobrevive a cualquier permiso que se quite después.
 */
class InformeGastoController extends Controller
{
    public function index(Request $request, InformeGastos $informes)
    {
        $datos = $this->parametros($request);
        $usuario = $request->user();

        $pendientes = $informes->pendientes($usuario, $datos['corte'], $datos);
        $pagos = $informes->pagos($usuario, $datos['desde'], $datos['hasta'], $datos);

        return view('gastos.informes', [
            'parametros' => $datos,
            'pendientes' => $pendientes,
            'pagos' => $pagos,
            // pendiente y vencido salen del SQL en CENTAVOS; aplicado es una columna
            // DECIMAL y viene como «60.00».
            'totalesPendientes' => $informes->totalesPorAmbito($pendientes, 'pendiente', enCentavos: true),
            'totalesVencidos' => $informes->totalesPorAmbito($pendientes, 'vencido', enCentavos: true),
            'totalesPagos' => $informes->totalesPorAmbito($pagos, 'aplicado', enCentavos: false),
        ]);
    }

    public function exportar(Request $request, InformeGastos $informes)
    {
        abort_unless($request->user()->can('gastos.exportar'), 403);

        $datos = $this->parametros($request);
        $cual = $request->validate(['informe' => ['required', 'in:pendientes,pagos']])['informe'];
        $usuario = $request->user();

        if ($cual === 'pendientes') {
            $filas = $informes->pendientes($usuario, $datos['corte'], $datos);

            return $informes->csv(
                'gastos-pendientes-'.$datos['corte'].'.csv',
                ['Gasto', 'Destinatario', 'Concepto', 'Categoría', 'Ámbito', 'Persona', 'Moneda',
                    'Pendiente', 'De ello vencido', 'Pagado', 'Próximo vencimiento', 'Responsable'],
                $filas->map(fn ($f) => [
                    $f->id, $f->beneficiario, $f->concepto, $f->categoria,
                    $f->ambito === 'personal' ? 'Personal' : 'Empresarial', $f->persona ?? '', $f->moneda,
                    Dinero::decimal((int) $f->pendiente), Dinero::decimal((int) $f->vencido),
                    Dinero::decimal((int) $f->pagado), $f->proxima ?? 'Sin fecha', $f->responsable,
                ]),
                [
                    'Gastos pendientes al '.$datos['corte'].'.',
                    'Pendiente es el saldo A ESA FECHA. «De ello vencido» es un SUBCONJUNTO del pendiente, no una cifra aparte: no los sumes.',
                    'Cada moneda lleva sus propios totales. No hay conversión.',
                    'Exportado por '.$usuario->name.' el '.now()->format('d/m/Y H:i').'.',
                ],
            );
        }

        $filas = $informes->pagos($usuario, $datos['desde'], $datos['hasta'], $datos);

        return $informes->csv(
            'gastos-pagos-'.$datos['desde'].'-a-'.$datos['hasta'].'.csv',
            ['Pago', 'Fecha', 'Método', 'Referencia', 'Gasto', 'Destinatario', 'Concepto',
                'Categoría', 'Ámbito', 'Naturaleza', 'Moneda', 'Aplicado', 'Pagó', 'Registró', 'Revertido después del período'],
            $filas->map(fn ($f) => [
                $f->pago_id, $f->fecha, config('gastos.metodos')[$f->metodo] ?? $f->metodo, $f->referencia ?? '',
                $f->gasto_id, $f->beneficiario, $f->concepto, $f->categoria,
                $f->ambito === 'personal' ? 'Personal' : 'Empresarial', $f->naturaleza, $f->moneda,
                $f->aplicado, $f->pagador, $f->registrador,
                $f->revertido_despues ? 'SÍ · '.$f->motivo_reversion : '',
            ]),
            [
                'Pagos del '.$datos['desde'].' al '.$datos['hasta'].', por fecha real del pago.',
                'UNA FILA POR APLICACIÓN, no por pago: un pago que cubre dos obligaciones aparece dos veces, con lo que tocó a cada una. Para contar salidas de dinero, agrupá por la columna «Pago».',
                'Un pago revertido DENTRO del período no está. Uno revertido DESPUÉS sí aparece —durante el período constaba como salido— y lleva SÍ en la última columna.',
                'Exportado por '.$usuario->name.' el '.now()->format('d/m/Y H:i').'.',
            ],
        );
    }

    /** @return array<string, string> */
    private function parametros(Request $request): array
    {
        $datos = $request->validate([
            'corte' => ['nullable', 'date_format:Y-m-d'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'ambito' => ['nullable', Rule::in(['empresarial', 'personal'])],
        ]);

        return [
            'corte' => $datos['corte'] ?? now()->toDateString(),
            'desde' => $datos['desde'] ?? now()->startOfMonth()->toDateString(),
            'hasta' => $datos['hasta'] ?? now()->toDateString(),
            'ambito' => $request->user()->can('gastos.personales') ? ($datos['ambito'] ?? '') : '',
        ];
    }
}
