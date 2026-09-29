<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDocumento;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Services\Gastos\Dinero;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ComprobanteAdelanto;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\IdentidadNegocio;
use App\Services\Planilla\InformePlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\TotalesPlanilla;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * El flujo de la planilla después de prepararla: confirmar, ver su estado, imprimir,
 * adjuntar lo firmado y anular.
 *
 * Los importes exigen `planilla.salarios` en TODAS las pantallas de acá. Los documentos
 * firmados exigen `planilla.documentos`, que es un permiso aparte a propósito: quien
 * archiva papeles no necesita ver el sueldo de todos.
 */
class PlanillaFlujoController extends Controller
{
    public function show(
        Request $request,
        Planilla $planilla,
        TotalesPlanilla $totales,
        EstadoPlanilla $estado,
        PrepararPlanilla $preparar,
        InformePlanilla $informe,
    ) {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $planilla->load('detalles.conceptos.anticipo', 'detalles.gasto.cuotas', 'registrador');

        return view('planilla.show', [
            'planilla' => $planilla,
            'totales' => $totales,
            'resumen' => $totales->dePlanilla($planilla),
            'estado' => $estado,
            'avance' => $estado->dePlanilla($planilla),
            'reparos' => $planilla->borrador() ? $preparar->reparosParaConfirmar($planilla) : [],
            'terceros' => PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto.cuotas')->get(),
            'documentos' => PlanillaDocumento::where('planilla_id', $planilla->id)->orderByDesc('id')->get(),

            // Las dos preguntas separadas: qué se debe y qué salió. Van aparte del
            // «avance del pago» porque el desembolso incluye los anticipos, cuyo dinero
            // salió antes y por su propio gasto.
            'desembolsos' => $planilla->borrador() ? null : $informe->desembolsos($planilla),
            'cuadre' => $planilla->borrador() ? null : $informe->cuadre($planilla),
        ]);
    }

    public function confirmar(Request $request, Planilla $planilla, ConfirmarPlanilla $confirmar)
    {
        $confirmar->confirmar($request->user(), $planilla);

        return redirect()
            ->route('planilla.show', $planilla)
            ->with('planilla.aviso', 'Planilla confirmada. Se crearon las obligaciones con cada persona y con los terceros, una sola vez.');
    }

    public function anular(Request $request, Planilla $planilla, AnularPlanilla $anular)
    {
        $anular->anular($request->user(), $planilla, (string) $request->input('motivo', ''));

        return redirect()
            ->route('planilla.show', $planilla)
            ->with('planilla.aviso', 'Planilla anulada. Las obligaciones se extinguieron con un ajuste interno —no se emitió ninguna nota de crédito— y los anticipos volvieron a quedar pendientes.');
    }

    /**
     * Impresos REALES de esta planilla: hoja de firmas y recibos.
     *
     * Todo lo que sale en el papel viene de lo que ya está registrado. En particular
     * tres cosas que no se pueden inventar:
     *
     *  · CUÁNTO SE ENTREGÓ y CUÁNDO salen de los pagos, no de lo que se debía. Si se
     *    pagó a cuenta, el papel dice cuánto se entregó y cuánto falta.
     *  · QUIÉN LO ENTREGÓ sale de `pagado_por` de cada pago. La empleadora encabeza el
     *    documento; entregar el dinero es un hecho de cada pago y puede haberlo hecho
     *    otra persona.
     *  · EL PERÍODO de quien entró o salió a mitad es el suyo, no el de la quincena.
     */
    public function impresos(
        Request $request,
        Planilla $planilla,
        TotalesPlanilla $totales,
        EstadoPlanilla $estado,
        IdentidadNegocio $identidad,
    ) {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $planilla->load('detalles.conceptos', 'detalles.gasto.cuotas');

        $formato = $request->query('formato') === 'recibo' ? 'recibo' : 'hoja';
        $pagos = $this->pagosPorDetalle($planilla);

        $lineas = [];
        $suma = ['total_ingresos' => 0, 'descuentos' => 0, 'a_pagar' => 0, 'pagado' => 0];
        $entregaron = [];

        foreach ($planilla->detalles as $i => $detalle) {
            $t = $totales->deDetalle($detalle);
            $e = $estado->deDetalle($detalle);
            $p = $pagos[$detalle->id] ?? ['pagado' => 0, 'fecha' => null, 'entrego' => null];

            $propio = $detalle->periodo_desde !== null || $detalle->periodo_hasta !== null;
            $desde = $detalle->periodo_desde ?? $planilla->desde;
            $hasta = $detalle->periodo_hasta ?? $planilla->hasta;

            if (filled($p['entrego'])) {
                $entregaron[$p['entrego']] = true;
            }

            $lineas[] = [
                'detalle_id' => $detalle->id,
                'nombre' => $detalle->nombre_snapshot,
                'dui' => $detalle->dui_snapshot,
                'cargo' => $detalle->cargo_snapshot,
                'salario' => (string) $detalle->salario,
                'folio' => $planilla->periodo.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'periodo' => $desde->format('d').'–'.$hasta->format('d').' '.mb_strtolower($hasta->translatedFormat('M')),
                'periodo_largo' => 'del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'),
                'periodo_propio' => $propio,
                'ingresos' => $detalle->conceptos->where('tipo', 'ingreso')
                    ->map(fn ($c) => ['concepto' => $c->concepto, 'importe' => (string) $c->importe])->values()->all(),
                'descuentos' => $detalle->conceptos->where('tipo', 'descuento')
                    ->map(fn ($c) => ['concepto' => $c->concepto, 'importe' => (string) $c->importe,
                        'destino' => $c->destino, 'tercero' => $c->tercero, 'referencia' => $c->referencia])->values()->all(),
                't' => $t + [
                    'total_ingresos_txt' => Dinero::mostrar($t['total_ingresos']),
                    'descuentos_txt' => Dinero::mostrar($t['descuentos']),
                    'a_pagar_txt' => Dinero::mostrar($t['a_pagar']),
                ],
                'pagado' => $p['pagado'],
                'pagado_txt' => Dinero::mostrar($p['pagado']),
                'pendiente_txt' => Dinero::mostrar(max($e['pendiente'], 0)),
                // Parcial: salió dinero, pero no todo. Cero pagado no es «parcial», es
                // «sin pagar», y merece otra palabra.
                'parcial' => $p['pagado'] > 0 && $e['pendiente'] > 0,
                'fecha_pago' => $p['fecha']?->format('d/m/Y'),
                // Solo se señala la fecha del renglón cuando DIFIERE de la del
                // encabezado: repetirla en los diez renglones no informaría de nada.
                'fecha_pago_propia' => $p['fecha'] !== null
                    && $planilla->fecha_pago !== null
                    && ! $p['fecha']->isSameDay($planilla->fecha_pago)
                        ? $p['fecha']->format('d/m/Y') : null,
                'entrego' => $p['entrego'],
                'estado' => $e + ['etiqueta' => EstadoPlanilla::ETIQUETAS[$e['estado']]],
            ];

            $suma['total_ingresos'] += $t['total_ingresos'];
            $suma['descuentos'] += $t['descuentos'];
            $suma['a_pagar'] += $t['a_pagar'];
            $suma['pagado'] += $p['pagado'];
        }

        return view('planilla.impresos', [
            'planilla' => $planilla,
            'formato' => $formato,
            'lineas' => $lineas,
            'suma' => $suma + [
                'total_ingresos_txt' => Dinero::mostrar($suma['total_ingresos']),
                'descuentos_txt' => Dinero::mostrar($suma['descuentos']),
                'a_pagar_txt' => Dinero::mostrar($suma['a_pagar']),
                'pagado_txt' => Dinero::mostrar($suma['pagado']),
            ],
            'moneda' => $planilla->moneda,
            'negocio' => $identidad->negocio(),
            'empleadora' => $identidad->empleadora(),
            'logo' => asset('images/dte/logo-transparent.png'),
            'entregaron' => implode(' y ', array_keys($entregaron)),
            'cabecera' => [
                'periodo' => $planilla->periodo.' · '.$planilla->periodoEnPalabras(),
                'periodo_largo' => $planilla->periodoEnPalabras(),
                'fecha_pago' => $planilla->fecha_pago?->format('d/m/Y'),
                'hoy' => now()->format('d/m/Y'),
            ],
        ]);
    }

    /**
     * Lo realmente pagado a cada línea: importe, fecha y quién lo entregó.
     *
     * Se lee de los pagos de Gastos, que es donde vive el dinero. Los revertidos no
     * cuentan: un pago deshecho no se entregó.
     *
     * @return array<int, array{pagado: int, fecha: ?Carbon, entrego: ?string}>
     */
    private function pagosPorDetalle(Planilla $planilla): array
    {
        $porGasto = $planilla->detalles->pluck('id', 'gasto_id')->filter();

        if ($porGasto->isEmpty()) {
            return [];
        }

        $filas = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.pagado_por')
            ->whereIn('c.gasto_id', $porGasto->keys())
            ->whereNull('p.revertido_at')
            ->orderBy('p.fecha')->orderBy('p.id')
            ->get(['c.gasto_id', 'a.importe', 'p.fecha', 'u.name as entrego']);

        $salida = [];

        foreach ($filas as $fila) {
            $id = $porGasto[$fila->gasto_id] ?? null;

            if ($id === null) {
                continue;
            }

            $salida[$id] ??= ['pagado' => 0, 'fecha' => null, 'entrego' => null];
            $salida[$id]['pagado'] += Dinero::centavos((string) $fila->importe);
            // La fecha que se imprime es la del ÚLTIMO pago: es cuando terminó de
            // entregarse. Con un solo pago son la misma.
            $salida[$id]['fecha'] = Carbon::parse($fila->fecha);
            $salida[$id]['entrego'] ??= $fila->entrego;
        }

        return $salida;
    }

    /**
     * Comprobante de adelanto: logo, nombre, DUI, monto entregado, la resta del saldo
     * y firma. Dos por hoja, original y copia.
     *
     * No registra nada: imprime un adelanto que ya existe.
     */
    public function adelanto(
        Request $request,
        PlanillaAnticipo $anticipo,
        ComprobanteAdelanto $comprobante,
        IdentidadNegocio $identidad,
    ) {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $anticipo->load('empleado', 'pago.pagador');
        $resta = $comprobante->resta($anticipo->empleado, $anticipo);

        return view('planilla.adelanto', [
            'anticipo' => $anticipo,
            'folio' => $comprobante->folio($anticipo),
            'resta' => [
                'anterior_txt' => Dinero::mostrar($resta['pendiente_anterior']),
                'este_txt' => Dinero::mostrar($resta['este_adelanto']),
                'nuevo_txt' => Dinero::mostrar($resta['nuevo_saldo']),
            ],
            'negocio' => $identidad->negocio(),
            'empleadora' => $identidad->empleadora(),
            'logo' => asset('images/dte/logo-transparent.png'),
            // Quien lo entregó sale del pago enlazado. Sin pago enlazado —dinero
            // entregado fuera del sistema— se deja la línea en blanco para escribirlo
            // a mano, que es más honesto que poner un nombre que nadie registró.
            'entrego' => $anticipo->pago?->pagador?->name,
        ]);
    }

    public function subirDocumento(Request $request, Planilla $planilla)
    {
        abort_unless($request->user()->can('planilla.documentos'), 403);

        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(PlanillaDocumento::TIPOS))],
            'planilla_detalle_id' => ['nullable', 'integer', Rule::exists('planilla_detalles', 'id')->where('planilla_id', $planilla->id)],
            'documento' => ['required', 'file', 'mimes:'.implode(',', config('gastos.mimes')), 'max:'.config('gastos.max_archivo_kb')],
        ], [
            'documento.required' => 'Elegí el archivo firmado.',
        ]);

        $archivo = $request->file('documento');

        // Disco PRIVADO y nombre generado por el servidor: un recibo de sueldo no puede
        // quedar detrás de una URL adivinable.
        $ruta = $archivo->storeAs(
            'planilla/firmados',
            Str::uuid().'.'.$archivo->extension(),
            'local',
        );

        PlanillaDocumento::create([
            'planilla_id' => $planilla->id,
            'planilla_detalle_id' => $datos['planilla_detalle_id'] ?? null,
            'tipo' => $datos['tipo'],
            'ruta' => $ruta,
            'nombre' => mb_substr(basename($archivo->getClientOriginalName()), 0, 240),
            'mime' => $archivo->getMimeType(),
            'bytes' => $archivo->getSize(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
            'registrado_por' => $request->user()->id,
            'created_at' => now(),
        ]);

        DB::table('gastos_eventos')->insert([
            'usuario_id' => $request->user()->id,
            'accion' => 'planilla_documento_adjuntado',
            'datos' => json_encode(['planilla_id' => $planilla->id, 'tipo' => $datos['tipo']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return back()->with('planilla.aviso', 'Documento firmado adjuntado.');
    }

    /** Los documentos se entregan SOLO por acá, nunca por una URL pública. */
    public function verDocumento(Request $request, PlanillaDocumento $documento)
    {
        abort_unless($request->user()->can('planilla.documentos'), 403);
        abort_unless(Storage::disk('local')->exists($documento->ruta), 404);

        return Storage::disk('local')->download($documento->ruta, $documento->nombre, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
