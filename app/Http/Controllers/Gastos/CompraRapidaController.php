<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Gasto;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\CompraContado;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Gastos\DestinoVuelta;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Las tres pantallas simples: compra al contado, cuentas con proveedores y pago rápido.
 *
 * Ninguna inventa una vía paralela. Los tres formularios terminan en los mismos
 * servicios de siempre —con sus permisos, sus claves únicas y sus saldos calculados—;
 * lo único que cambia es cuántas preguntas hay que responder para llegar ahí.
 *
 * El ámbito se pregunta SIEMPRE, con las dos opciones a la vista. Quien no tiene
 * `gastos.personales` solo ve «De la empresa», y el servicio lo vuelve a comprobar:
 * esconder una opción nunca es autorizar.
 */
class CompraRapidaController extends Controller
{
    // ══════════════ Compré y pagué ══════════════

    public function crearCompra(Request $request)
    {
        abort_unless($request->user()->can('gastos.registrar'), 403);
        abort_unless($request->user()->can('gastos.pagos.registrar'), 403);

        return view('gastos.compre-y-pague', [
            'clave' => (string) Str::uuid(),
            'hoy' => now()->toDateString(),
            'vePersonales' => $request->user()->can('gastos.personales'),
        ]);
    }

    public function guardarCompra(Request $request, CompraContado $compras)
    {
        $resultado = $compras->registrar($request->user(), $request->all());

        // Se vuelve al panel, que es la pantalla desde la que se trabaja, y el enlace
        // conserva el camino a la ficha recién creada: volver al resumen no puede
        // costar perder de vista lo que se acaba de registrar.
        return redirect()
            ->route('gastos.panel')
            ->with('gastos.aviso', 'Registrado y pagado: '.$resultado['gasto']->concepto
                .' · '.$resultado['gasto']->moneda.' '.$resultado['gasto']->importe.'.')
            ->with('gastos.aviso_enlace', [
                'url' => route('gastos.show', $resultado['gasto']),
                'texto' => 'Abrir la ficha',
            ]);
    }

    // ══════════════ Cuentas con proveedores ══════════════

    public function cuentas(Request $request, CuentaProveedor $cuentas)
    {
        abort_unless($request->user()->can('gastos.ver'), 403);

        return view('gastos.cuentas.index', [
            'cuentas' => $cuentas->cuentas($request->user()),
        ]);
    }

    public function cuenta(Request $request, CuentaProveedor $cuentas, SaldosGastos $saldos)
    {
        abort_unless($request->user()->can('gastos.ver'), 403);

        $proveedor = (string) $request->query('proveedor', '');
        $moneda = (string) $request->query('moneda', 'USD');

        abort_if($proveedor === '', 404);

        $abiertas = $cuentas->abiertas($request->user(), $proveedor, $moneda);

        // El movimiento: compras y abonos mezclados, lo más reciente arriba. Es la
        // libreta de toda la vida, que es como se entiende una cuenta.
        $movimientos = [];

        foreach ($abiertas as $cuota) {
            $movimientos[] = [
                'tipo' => 'compra',
                'fecha' => $cuota->gasto->created_at,
                'concepto' => $cuota->gasto->concepto,
                'vence' => $cuota->vence?->format('d/m/Y'),
                'importe' => Dinero::centavos((string) $cuota->importe),
                'gasto_id' => $cuota->gasto_id,
            ];
        }

        foreach ($this->abonosDe($abiertas->pluck('id')->all()) as $abono) {
            $movimientos[] = [
                'tipo' => 'abono',
                'fecha' => $abono->fecha,
                'concepto' => 'Abono'.($abono->referencia ? ' · '.$abono->referencia : ''),
                'vence' => null,
                'importe' => Dinero::centavos((string) $abono->total),
                'gasto_id' => null,
            ];
        }

        usort($movimientos, fn ($a, $b) => $b['fecha'] <=> $a['fecha']);

        return view('gastos.cuentas.show', [
            'proveedor' => $proveedor,
            'moneda' => $moneda,
            'saldo' => $cuentas->saldo($request->user(), $proveedor, $moneda),
            'abiertas' => $abiertas->map(fn ($c) => [
                'cuota_id' => $c->id,
                'concepto' => $c->gasto->concepto,
                'fecha' => $c->gasto->created_at?->format('d/m/Y'),
                'vence' => $c->vence?->format('d/m/Y'),
                'pendiente' => $saldos->pendienteCuota($c),
            ])->all(),
            'movimientos' => $movimientos,
            'clave' => (string) Str::uuid(),
            'claveAbono' => (string) Str::uuid(),
            'hoy' => now()->toDateString(),
            'vePersonales' => $request->user()->can('gastos.personales'),
            'puedeRegistrar' => $request->user()->can('gastos.registrar'),
            'puedePagar' => $request->user()->can('gastos.pagos.registrar'),
        ]);
    }

    public function agregarCompra(Request $request, CuentaProveedor $cuentas)
    {
        $gasto = $cuentas->agregarCompra($request->user(), $request->all());

        // Igual que el abono: se agrega desde la libreta del proveedor y ahí se vuelve.
        $origen = $request->validate(['origen' => DestinoVuelta::regla()])['origen'] ?? null;

        $vuelta = redirect()->to(DestinoVuelta::deAbono($origen, $gasto->beneficiario, $gasto->moneda))
            ->with('gastos.aviso', 'Compra agregada: '.$gasto->concepto.' · '.$gasto->moneda.' '.$gasto->importe.'.');

        return $origen === 'cuenta' ? $vuelta : $vuelta->with('gastos.aviso_enlace', [
            'url' => route('gastos.cuentas.show', ['proveedor' => $gasto->beneficiario, 'moneda' => $gasto->moneda]),
            'texto' => 'Ver la cuenta de '.$gasto->beneficiario,
        ]);
    }

    public function abonar(Request $request, CuentaProveedor $cuentas)
    {
        $datos = $request->all();

        // El reparto llega solo si la persona lo cambió a mano. Si no viene, el servicio
        // usa el suyo —lo más antiguo primero—.
        $reparto = null;

        if ($request->boolean('dirigido') && is_array($request->input('reparto'))) {
            $reparto = array_values(array_filter(
                array_map(fn ($fila) => [
                    'cuota_id' => (int) ($fila['cuota_id'] ?? 0),
                    'importe' => (string) ($fila['importe'] ?? ''),
                ], $request->input('reparto')),
                fn ($fila) => $fila['cuota_id'] > 0 && Dinero::centavos($fila['importe'] ?: '0') > 0
            ));
        }

        $pago = $cuentas->abonar($request->user(), $datos, $reparto);

        // Se vuelve al LUGAR DE ORIGEN, resuelto desde una lista cerrada de destinos
        // nuestros: nunca desde una URL del formulario. Ver DestinoVuelta.
        $origen = $request->validate(['origen' => DestinoVuelta::regla()])['origen'] ?? null;

        $vuelta = redirect()->to(DestinoVuelta::deAbono($origen, $datos['beneficiario'], $pago->moneda))
            ->with('gastos.aviso', 'Abono registrado: '.$datos['beneficiario'].' · '.$pago->moneda.' '.$pago->importe.'.');

        // Si la vuelta es al panel, el camino a la cuenta se conserva a un clic.
        return $origen === 'cuenta' ? $vuelta : $vuelta->with('gastos.aviso_enlace', [
            'url' => route('gastos.cuentas.show', ['proveedor' => $datos['beneficiario'], 'moneda' => $pago->moneda]),
            'texto' => 'Ver la cuenta de '.$datos['beneficiario'],
        ]);
    }

    // ══════════════ Pago rápido de un pendiente ══════════════

    public function pagarRapido(Request $request, Gasto $gasto, SaldosGastos $saldos)
    {
        abort_unless($request->user()->can('gastos.pagos.registrar'), 403);
        abort_unless(app(AccesoGastos::class)->ver($request->user(), $gasto), 403);

        $cuotas = $gasto->cuotas()->orderBy('numero')->get()
            ->map(fn ($c) => ['cuota_id' => $c->id, 'numero' => $c->numero,
                'vence' => $c->vence?->format('d/m/Y'), 'pendiente' => $saldos->pendienteCuota($c)])
            ->filter(fn ($c) => $c['pendiente'] > 0)
            ->values();

        abort_if($cuotas->isEmpty(), 404);

        return view('gastos.pagar-rapido', [
            'gasto' => $gasto,
            'cuotas' => $cuotas,
            'pendiente' => $cuotas->sum('pendiente'),
            'clave' => (string) Str::uuid(),
            'hoy' => now()->toDateString(),
        ]);
    }

    /**
     * Los abonos que tocaron estas cuotas, agrupados por pago.
     *
     * Se agrupan porque un abono puede repartirse entre varias compras, y en la libreta
     * tiene que aparecer como UNA línea —lo que se entregó— y no como tres.
     *
     * @param  array<int, int>  $cuotaIds
     */
    private function abonosDe(array $cuotaIds)
    {
        if ($cuotaIds === []) {
            return collect();
        }

        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereIn('a.cuota_id', $cuotaIds)
            ->whereNull('p.revertido_at')
            ->groupBy('p.id', 'p.fecha', 'p.referencia')
            ->select('p.id', 'p.fecha', 'p.referencia')
            ->selectRaw('SUM(a.importe) as total')
            ->get()
            ->map(function ($fila) {
                $fila->fecha = Carbon::parse($fila->fecha);

                return $fila;
            });
    }
}
