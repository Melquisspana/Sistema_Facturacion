<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Regla;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\CompletarMonto;
use App\Services\Gastos\CompraContado;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Gastos\DestinoVuelta;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\InstalacionGastos;
use App\Services\Gastos\PanelGastos;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La pantalla principal de Gastos y las cuatro acciones que salen de ella.
 *
 * ───────────────────────── Qué hace este controlador, y qué no ─────────────────────────
 *
 * NO tiene lógica de negocio. Cada acción termina en el MISMO servicio que ya usaban
 * las pantallas viejas —RegistrarPago, CuentaProveedor, CompraContado,
 * CompletarMonto—, con los mismos permisos y las mismas validaciones. Esta clase
 * junta los datos, elige la plantilla y devuelve a la pantalla principal.
 *
 * Eso es deliberado y es lo que hace barato este rediseño: si la lógica viviera acá
 * habría dos caminos para pagar —el viejo y el nuevo— y tarde o temprano divergirían.
 * Las pantallas anteriores siguen existiendo y siguen funcionando; esta es otra
 * PUERTA a lo mismo, no un atajo por fuera.
 *
 * ───────────────────────────── Los permisos, por acción ─────────────────────────────
 *
 * Ver la pantalla alcanza con `gastos.ver`. Cada acción exige el suyo, en la ruta y
 * otra vez en el servicio. «Compré y pagué» exige los dos —registrar y pagar— porque
 * hace las dos cosas en una transacción.
 */
class PanelController extends Controller
{
    /** La pantalla principal, y la PUERTA del área. */
    public function index(Request $request, PanelGastos $panel, InstalacionGastos $instalacion)
    {
        $filtros = ['ambito' => $request->query('ambito')];

        return view('gastos.panel', [
            'datos' => $panel->armar($request->user(), $filtros, CarbonImmutable::now()->startOfDay()),
            'ambito' => $filtros['ambito'],
            'clave' => (string) Str::uuid(),
            'hoy' => now()->toDateString(),
            // ¿Está migrada la fase 2? Se pregunta ACÁ y no en la plantilla. Entre
            // desplegar el código y correr las migraciones hay una ventana, y durante
            // esa ventana el enlace a «Gastos que se repiten» llevaría a un 503. Una
            // vista que consulta el estado del módulo por su cuenta es justo lo que
            // una vez tumbó el sistema entero desde el menú.
            'fase2' => $instalacion->fase2Instalada(),
        ]);
    }

    // ═══════════════════════════════ Pagar ═══════════════════════════════

    /**
     * Pagar una obligación que ya existe.
     *
     * El reparto por cuotas lo arma el servidor —lo más viejo primero— y el detalle
     * de la obligación sigue siendo el lugar donde se reparte a mano. Acá no se pide
     * nada que el sistema ya sepa: solo fecha y método.
     */
    public function pagar(Request $request, Gasto $gasto, RegistrarPago $pagos, SaldosGastos $saldos, AccesoGastos $acceso)
    {
        abort_unless($acceso->ver($request->user(), $gasto), 403);

        // Un gasto sin importe no se puede pagar: primero hay que completar el recibo.
        abort_if($gasto->montoDesconocido(), 422, 'Esta obligación todavía no tiene importe.');

        $datos = $request->validate([
            'clave' => ['required', 'uuid'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'referencia' => ['nullable', 'string', 'max:180'],
            'sin_comprobante' => ['nullable', 'string', 'max:250'],
        ]);

        $aplicaciones = [];
        $total = 0;

        foreach ($gasto->cuotas()->orderBy('numero')->get() as $cuota) {
            $pendiente = $saldos->pendienteCuota($cuota);
            if ($pendiente <= 0) {
                continue;
            }
            $aplicaciones[] = ['cuota_id' => $cuota->id, 'importe' => Dinero::decimal($pendiente)];
            $total += $pendiente;
        }

        abort_if($aplicaciones === [], 422, 'Esta obligación ya no tiene saldo.');

        $pago = $pagos->registrar($request->user(), [
            'clave' => $datos['clave'],
            'importe' => Dinero::decimal($total),
            'fecha' => $datos['fecha'],
            'metodo' => $datos['metodo'],
            'pagado_por' => $request->user()->id,
            'referencia' => $datos['referencia'] ?? null,
            'sin_comprobante' => $datos['sin_comprobante'] ?? null,
        ], $aplicaciones);

        return redirect()->route('gastos.panel')->with('gastos.aviso',
            'Pagado: '.$gasto->beneficiario.' · '.$pago->moneda.' '.$pago->importe
            .'. El comprobante se adjunta desde el detalle del pago.');
    }

    /**
     * Pagar una fila PROGRAMADA o de FECHA PASADA, sin pasar por la pantalla de reglas.
     *
     * ─────────────────────────── Qué hace, en una confirmación ───────────────────────────
     *
     *   1. Comprueba que el período sea uno que la regla de verdad produce y que esté
     *      dentro de la ventana pagable (del arranque al fin del mes en curso).
     *   2. Crea la obligación de ese período, O REUTILIZA la que ya exista.
     *   3. Si es de monto variable, le pone el importe del recibo.
     *   4. Registra el pago sobre sus cuotas.
     *
     * ─────────────────────── Por qué no puede duplicar ni fabricar deuda ───────────────────────
     *
     * ABRIR EL PANEL NO CREA NADA. Este método solo corre con un POST confirmado: el
     * formulario se despliega en el navegador, sin tocar el servidor. Abrirlo y
     * cancelarlo deja la base exactamente como estaba.
     *
     * CONFIRMAR DOS VECES TAMPOCO. El paso 2 baja a la misma clave derivada e índice
     * único que usa la corrida programada, así que el segundo intento encuentra la
     * obligación del primero en vez de crear una gemela. El pago lleva su propia clave
     * de idempotencia, como todos.
     *
     * EL PERÍODO NO SE CREE. Llega en un campo oculto, así que se REVALIDA contra el
     * calendario de la regla. Un período anterior al arranque no pasa —da 422—, y esa
     * exclusión es entonces del sistema y no de la pantalla.
     *
     * SI EL PAGO FALLA, NO QUEDA LA OBLIGACIÓN CREADA. Los cuatro pasos van en UNA
     * transacción. Sin ella —y así estaba— un método de pago inválido dejaba el gasto,
     * su cuota y su ocurrencia escritos y ningún pago: una deuda nueva nacida de una
     * operación que el operador vio fallar. Se comprobó contra MySQL, no solo contra
     * SQLite, porque de eso dependen el bloqueo de fila y el SAVEPOINT.
     *
     * Rehacer el paso 2 dentro de la transacción tiene un segundo efecto, y es el que
     * arregla la carrera: el bloqueo sobre la regla que toma la generación se sostiene
     * hasta el final, así que dos confirmaciones simultáneas se serializan enteras. La
     * segunda no lee un pendiente viejo —lo que la hacía fallar con «el pago supera el
     * pendiente»— sino el que dejó la primera, y responde que ya estaba saldada.
     */
    public function pagarProgramado(Request $request, Regla $regla, PanelGastos $panel, GenerarObligaciones $generador, CompletarMonto $completar, RegistrarPago $pagos, SaldosGastos $saldos)
    {
        $datos = $request->validate([
            'clave' => ['required', 'uuid'],
            'periodo' => ['required', 'string', 'max:20'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            // Contra el catálogo, no un string cualquiera. El servicio de pagos lo
            // valida igual, pero para entonces la obligación ya estaría creada: una
            // petición que no puede terminar bien se rechaza antes de escribir nada.
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'referencia' => ['nullable', 'string', 'max:180'],
            // Solo lo pide el monto variable: el recibo trae la cifra.
            'importe' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
        ]);

        // Ámbito: una regla personal produce gastos personales.
        abort_unless($regla->ambito !== 'personal' || $request->user()->can('gastos.personales'), 403);

        $vence = $panel->periodoPagable($regla, $datos['periodo'], CarbonImmutable::now()->startOfDay());
        abort_if($vence === null, 422, 'Ese período no corresponde a esta regla, o está fuera de lo que se puede pagar desde acá.');

        return DB::transaction(function () use ($request, $regla, $datos, $vence, $generador, $completar, $pagos, $saldos) {
            $gasto = $generador->asegurarPeriodo($regla, $datos['periodo'], $vence, $request->user());

            // Monto variable: la obligación nace sin importe y hay que ponérselo antes
            // de poder pagarla. Es el mismo servicio que usa «Anotar el monto».
            if ($gasto->montoDesconocido()) {
                if (blank($datos['importe'] ?? null)) {
                    throw ValidationException::withMessages([
                        'importe' => 'Este servicio cambia cada período: escribí lo que dice el recibo.',
                    ]);
                }

                $gasto = $completar->completar($request->user(), $gasto, [[
                    'importe' => $datos['importe'],
                    'vence' => $vence->toDateString(),
                ]]);
            }

            $aplicaciones = [];
            $total = 0;

            foreach ($gasto->cuotas()->orderBy('numero')->get() as $cuota) {
                $pendiente = $saldos->pendienteCuota($cuota);
                if ($pendiente <= 0) {
                    continue;
                }
                $aplicaciones[] = ['cuota_id' => $cuota->id, 'importe' => Dinero::decimal($pendiente)];
                $total += $pendiente;
            }

            // Ya estaba saldada —normalmente porque otra confirmación simultánea llegó
            // primero—. La obligación queda creada y correcta, y se dice, en vez de
            // registrar un pago de cero o reventar con un error del reparto.
            if ($aplicaciones === []) {
                return redirect()->route('gastos.panel')->with('gastos.aviso',
                    'La obligación de '.$regla->beneficiario.' ('.$datos['periodo'].') ya estaba saldada. No se registró otro pago.');
            }

            $pago = $pagos->registrar($request->user(), [
                'clave' => $datos['clave'],
                'importe' => Dinero::decimal($total),
                'fecha' => $datos['fecha'],
                'metodo' => $datos['metodo'],
                'pagado_por' => $request->user()->id,
                'referencia' => $datos['referencia'] ?? null,
            ], $aplicaciones);

            return redirect()->route('gastos.panel')->with('gastos.aviso',
                'Registrado: '.$regla->beneficiario.' · '.$datos['periodo'].' · '.$pago->moneda.' '.$pago->importe
                .'. Se creó la obligación del período y se pagó en una sola operación.');
        });
    }

    // ═══════════════════════════════ Abonar ═══════════════════════════════

    /**
     * Abonar a una cuenta abierta.
     *
     * El reparto EDITABLE sigue vivo y sigue estando donde estaba: en la cuenta del
     * proveedor. Desde acá se manda el importe y el servicio reparte de la compra más
     * vieja a la más nueva, que es lo que se quiere el 95 % de las veces; cuando el
     * abono era por una compra concreta, el enlace «ver el reparto» lleva a la
     * pantalla donde se elige línea por línea.
     */
    public function abonar(Request $request, CuentaProveedor $cuentas)
    {
        $pago = $cuentas->abonar($request->user(), $request->all());

        // Mismo resolutor que la libreta del proveedor, para que haya UNA sola forma de
        // decidir la vuelta en todo el módulo. Desde acá el origen es el panel, pero no
        // se da por supuesto: se declara y se resuelve contra la lista cerrada.
        $origen = $request->validate(['origen' => DestinoVuelta::regla()])['origen'] ?? null;

        return redirect()->to(DestinoVuelta::deAbono($origen, (string) $request->input('beneficiario'), $pago->moneda))
            ->with('gastos.aviso',
                'Abono registrado: '.$request->input('beneficiario').' · '.$pago->moneda.' '.$pago->importe.'.');
    }

    // ═════════════════════════ Registrar una compra ═════════════════════════

    /**
     * Compré y pagué: el gasto y el pago nacen juntos, en una sola transacción.
     *
     * Nunca existe una deuda por las bolsas que ya se pagaron en la tienda. Si algo
     * falla —validación, archivo o base— no se confirma nada a medias.
     */
    public function compra(Request $request, CompraContado $compras)
    {
        $resultado = $compras->registrar($request->user(), $request->all());
        $gasto = $resultado['gasto'] ?? null;

        return redirect()->route('gastos.panel')->with('gastos.aviso',
            'Compra registrada y pagada'.($gasto !== null ? ': '.$gasto->concepto.' · '.$gasto->moneda.' '.$gasto->importe : '').'.');
    }

    // ═════════════════════════ Completar el recibo ═════════════════════════

    /**
     * Ponerle el importe a una obligación que lo estaba esperando.
     *
     * COMPLETA la que ya existe; no crea otra. Crear una segunda es exactamente lo
     * que deja dos filas por la misma deuda, y por eso este camino pasa por el mismo
     * servicio que el de la ficha.
     *
     * El vencimiento real del recibo se respeta: si viene, se guarda; si no viene, la
     * cuota se queda sin fecha. No se inventa ninguna.
     */
    public function completarRecibo(Request $request, Gasto $gasto, CompletarMonto $servicio, AccesoGastos $acceso)
    {
        abort_unless($acceso->ver($request->user(), $gasto), 403);

        $datos = $request->validate([
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'vence' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'importe.required' => 'Escribí lo que dice el recibo.',
        ]);

        $gasto = $servicio->completar($request->user(), $gasto, [[
            'importe' => $datos['importe'],
            'vence' => $datos['vence'] ?? null,
        ]]);

        return redirect()->route('gastos.panel')->with('gastos.aviso',
            'Monto anotado: '.$gasto->beneficiario.' · '.$gasto->moneda.' '.$gasto->importe.'. Ya se puede pagar.');
    }
}
