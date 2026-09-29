<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\AdjuntarDespues;
use App\Services\Gastos\AlmacenAdjuntos;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\RegistrarPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pagos posteriores y abonos.
 *
 * Un pago puede cubrir VARIAS obligaciones del mismo destinatario y moneda. No
 * tiene ámbito propio: los subtotales empresarial/personal se derivan de sus
 * aplicaciones a cuotas.
 *
 * El reparto es SIEMPRE explícito. La pantalla propone cubrir las más antiguas
 * primero, pero lo que se guarda es lo que el operador dejó en pantalla, y
 * {@see RegistrarPago} lo vuelve a verificar contra el pendiente real bajo
 * bloqueo. Nada se reparte solo en el servidor.
 */
class PagoController extends Controller
{
    /**
     * HISTORIAL DE PAGOS: el dinero que salió.
     *
     * Tres cosas que esta pantalla tiene que hacer bien, y que no son cosméticas:
     *
     *  1. RESPETAR EL ALCANCE. Un pago mixto —empresa y personal del mismo
     *     destinatario— se muestra a quien solo alcanza lo empresarial con SU
     *     subtotal, nunca con el importe total: el total delataría el importe
     *     personal aunque se oculte su fila. El recorte va en la consulta.
     *  2. DISTINGUIR LOS REVERTIDOS. Un pago revertido no es dinero que salió, pero
     *     tampoco desapareció: se lista, se marca, y NO suma a los totales.
     *  3. SEPARAR LAS MONEDAS. Cada una lleva su total. No hay conversión.
     */
    public function index(Request $request, ConsultaGastos $consulta)
    {
        $filtros = [
            'q' => trim((string) $request->query('q', '')),
            'desde' => $request->query('desde'),
            'hasta' => $request->query('hasta'),
            'metodo' => $request->query('metodo'),
            'moneda' => $request->query('moneda'),
            // Empresa / Personal. Comodidad sobre lo ya alcanzable: el candado real
            // sigue siendo el permiso, aplicado dentro de la consulta.
            'ambito' => in_array($request->query('ambito'), ['empresarial', 'personal'], true)
                ? $request->query('ambito')
                : null,
            'revertidos' => in_array($request->query('revertidos'), ['excluir', 'solo'], true)
                ? $request->query('revertidos')
                : 'incluir',
        ];

        $pagos = $consulta->pagosVisibles($request->user(), $filtros);

        return view('gastos.pagos.index', [
            'filtros' => $filtros,
            'pagos' => $pagos,
            'totales' => $consulta->totalesPagos($request->user(), $filtros),
            // Qué se pagó en cada uno. Se pide solo para la PÁGINA que se está
            // mirando, no para el historial entero.
            'reparto' => $consulta->repartoDePagos($request->user(), $pagos->pluck('id')->all()),
        ]);
    }

    public function create(Request $request, ConsultaGastos $consulta, AccesoGastos $acceso)
    {
        $usuario = $request->user();
        abort_unless($usuario->can('gastos.pagos.registrar'), 403);

        $hoy = now()->toDateString();

        // Se puede entrar desde una obligación concreta o eligiendo destinatario.
        $gasto = $request->filled('gasto') ? Gasto::find((int) $request->input('gasto')) : null;
        if ($gasto !== null) {
            abort_unless($acceso->ver($usuario, $gasto), 403);
        }

        $beneficiario = $gasto?->beneficiario ?? (string) $request->input('beneficiario', '');
        $moneda = $gasto?->moneda ?? (string) $request->input('moneda', config('gastos.monedas')[0]);

        $cuotas = $beneficiario === ''
            ? collect()
            : $consulta->cuotasPagables($usuario, $beneficiario, $moneda, $hoy);

        return view('gastos.pagos.create', [
            'clave' => (string) Str::uuid(),
            'beneficiarios' => $consulta->beneficiariosConSaldo($usuario, $hoy),
            'beneficiario' => $beneficiario,
            'moneda' => $moneda,
            'cuotas' => $cuotas,
            'gastoOrigen' => $gasto,
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'hoy' => $hoy,
        ]);
    }

    public function store(Request $request, RegistrarPago $registrar, AlmacenAdjuntos $almacen)
    {
        $usuario = $request->user();
        abort_unless($usuario->can('gastos.pagos.registrar'), 403);

        $datos = $request->validate([
            'clave' => ['required', 'uuid'],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'pagado_por' => ['required', Rule::exists('users', 'id')->where('activo', true)],
            'referencia' => ['nullable', 'string', 'max:180'],
            'sin_comprobante' => ['nullable', 'required_without:comprobantes', 'string', 'max:250'],
            'aplicar' => ['required', 'array', 'min:1'],
            'aplicar.*' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'comprobantes' => ['nullable', 'array', 'max:'.config('gastos.max_archivos')],
            'comprobantes.*' => ['file', 'mimes:'.implode(',', config('gastos.mimes')), 'max:'.config('gastos.max_archivo_kb')],
        ], [
            'sin_comprobante.required_without' => 'Adjuntá el comprobante o indicá por qué no lo tenés.',
        ]);

        // Solo entran las cuotas con importe > 0. Una aplicación de cero no existe.
        $aplicaciones = [];
        foreach ($datos['aplicar'] as $cuotaId => $importe) {
            $importe = trim((string) $importe);
            if ($importe !== '' && Dinero::centavos($importe) > 0) {
                $aplicaciones[] = ['cuota_id' => (int) $cuotaId, 'importe' => $importe];
            }
        }

        if ($aplicaciones === []) {
            throw ValidationException::withMessages([
                'aplicar' => 'Indicá a qué cuota o cuotas se aplica este pago.',
            ]);
        }

        $rutas = [];

        try {
            $pago = DB::transaction(function () use ($usuario, $datos, $aplicaciones, $almacen, &$rutas) {
                $pago = app(RegistrarPago::class)->registrar($usuario, [
                    'clave' => $datos['clave'],
                    'importe' => $datos['importe'],
                    'fecha' => $datos['fecha'],
                    'metodo' => $datos['metodo'],
                    'pagado_por' => $datos['pagado_por'],
                    'referencia' => $datos['referencia'] ?? null,
                    'sin_comprobante' => $datos['sin_comprobante'] ?? null,
                ], $aplicaciones);

                // Los comprobantes van DENTRO de la misma transacción: si el archivo
                // falla, no queda un pago registrado sin su respaldo.
                if (! empty($datos['comprobantes'])) {
                    $almacen->guardar($datos['comprobantes'], null, $pago->id, $usuario->id, $rutas, 'comprobantes');
                }

                return $pago;
            });
        } catch (\Throwable $e) {
            $almacen->limpiar($rutas);
            throw $e;
        }

        // `volver_a` lo manda la ficha de una obligación: ahí el operador pidió
        // explícitamente volver a ESE gasto, y esa intención manda. Sin ella, el
        // destino es el panel, con el detalle del pago a un clic.
        if ($request->filled('volver_a')) {
            return redirect()->route('gastos.show', (int) $request->input('volver_a'))
                ->with('gastos.aviso', 'Pago #'.$pago->id.' registrado.');
        }

        return redirect()->route('gastos.panel')
            ->with('gastos.aviso', 'Pago #'.$pago->id.' registrado.')
            ->with('gastos.aviso_enlace', [
                'url' => route('gastos.pagos.show', $pago),
                'texto' => 'Ver el pago y adjuntar el comprobante',
            ]);
    }

    public function show(Request $request, Pago $pago, AccesoGastos $acceso)
    {
        // Cabecera de un pago: exige alcanzar TODAS sus obligaciones. Mostrarla con
        // filas ocultas dejaría un total que revela lo que se quiso esconder.
        abort_unless($acceso->pagoCompleto($request->user(), $pago), 403);

        $aplicaciones = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->where('a.pago_id', $pago->id)
            ->orderBy('g.id')->orderBy('c.numero')
            ->get(['a.importe', 'c.numero', 'c.vence', 'g.id as gasto_id', 'g.concepto', 'g.ambito']);

        return view('gastos.pagos.show', [
            'pago' => $pago->load(['pagador', 'registrador', 'reversor']),
            'aplicaciones' => $aplicaciones,
            'comprobantes' => DB::table('gastos_adjuntos')->where('pago_id', $pago->id)->orderBy('id')->get(),
            'historial' => DB::table('gastos_eventos as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.usuario_id')
                ->where('e.pago_id', $pago->id)
                ->orderByDesc('e.id')
                ->get(['e.accion', 'e.datos', 'e.created_at', 'u.name as usuario']),
        ]);
    }

    /** Comprobantes que se descargaron del banco al día siguiente. */
    public function comprobantes(Request $request, Pago $pago, AdjuntarDespues $adjuntar)
    {
        $datos = $request->validate([
            'comprobantes' => ['required', 'array', 'max:'.config('gastos.max_archivos')],
            'comprobantes.*' => ['file', 'mimes:'.implode(',', config('gastos.mimes')), 'max:'.config('gastos.max_archivo_kb')],
        ]);

        $cuantos = $adjuntar->comprobantes($request->user(), $pago, $datos['comprobantes']);

        return back()->with('gastos.aviso', $cuantos === 1 ? 'Comprobante adjuntado.' : "{$cuantos} comprobantes adjuntados.");
    }

    /**
     * Reversión: la ÚNICA forma de corregir un pago. No se edita ni se borra.
     * Las aplicaciones originales se conservan; lo que cambia es que dejan de contar.
     */
    public function revertir(Request $request, Pago $pago, RegistrarPago $servicio)
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Explicá por qué se revierte este pago.',
            'motivo.min' => 'El motivo tiene que decir algo: una reversión sin explicación no se puede auditar después.',
        ]);

        $servicio->revertir($request->user(), $pago, $datos['motivo']);

        return back()->with('gastos.aviso',
            'Pago #'.$pago->id.' revertido. El saldo volvió a la deuda; el registro queda en el historial.');
    }

    /** Cuotas pagables del destinatario elegido, para refrescar la lista sin recargar. */
    public function cuotas(Request $request, ConsultaGastos $consulta)
    {
        abort_unless($request->user()->can('gastos.pagos.registrar'), 403);

        $datos = $request->validate([
            'beneficiario' => ['required', 'string', 'max:180'],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
        ]);

        $cuotas = $consulta->cuotasPagables($request->user(), $datos['beneficiario'], $datos['moneda'], now()->toDateString());

        return response()->json($cuotas->map(fn ($c) => [
            'cuota_id' => (int) $c->cuota_id,
            'gasto_id' => (int) $c->gasto_id,
            'concepto' => $c->concepto,
            'ambito' => $c->ambito,
            'numero' => (int) $c->numero,
            'vence' => $c->vence,
            'vencida' => (bool) $c->vencida,
            // `saldo` se queda CRUDO: el formulario lo compara para avisar si un
            // importe supera la cuota, y `parseFloat('1,200.00')` devuelve 1 —corta en
            // la coma—, así que un separador acá dejaría pasar pagos de más.
            'saldo' => Dinero::decimal((int) $c->saldo),
            // Y esta es la que se pinta.
            'saldo_txt' => Dinero::mostrar((int) $c->saldo),
        ]));
    }

    /** Cuota por id, comprobando que el usuario la alcance. Usado por las vistas. */
    public static function cuotaVisible(User $usuario, int $cuotaId, AccesoGastos $acceso): ?Cuota
    {
        $cuota = Cuota::find($cuotaId);

        return $cuota && $acceso->ver($usuario, $cuota->gasto) ? $cuota : null;
    }
}
