<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Una cuenta abierta con un proveedor: se compra a crédito y se abona de a poco.
 *
 * ═══════ Por qué merece su propia pantalla ═══════
 *
 * Con Proveedor A no hay cuotas ni vencimientos mensuales: hay compras que suben el saldo y
 * abonos que lo bajan. Forzarlo dentro del formulario general obligaba a inventar un
 * vencimiento en cada compra y a elegir a mano contra qué cuota iba cada abono.
 *
 * ═══════ El saldo no se guarda ═══════
 *
 * No hay ninguna columna «saldo». Es la suma de lo que cada compra tiene pendiente,
 * calculada por {@see SaldosGastos}, el mismo servicio que calcula el de un alquiler. Si
 * se guardara, habría dos cifras para el mismo dinero y tarde o temprano discreparían.
 *
 * ═══════ El reparto del abono ═══════
 *
 * Por defecto, lo más antiguo primero: es lo que hace cualquiera con una libreta, y
 * evita que una compra vieja quede abierta para siempre mientras se pagan las nuevas.
 *
 * Pero el reparto se PROPONE, no se impone. {@see repartir()} devuelve el plan para que
 * la pantalla lo muestre, y {@see abonar()} acepta un reparto explícito cuando el pago
 * corresponde a una compra concreta —«estos 400 son de la pepitoria del martes»—. Con
 * dinero, adivinar y no dejar corregir es peor que preguntar.
 */
final class CuentaProveedor
{
    public function __construct(
        private AccesoGastos $acceso,
        private SaldosGastos $saldos,
        private RegistrarPago $pagos,
        private ProteccionDeGastos $proteccion,
    ) {}

    /**
     * Las compras con saldo vivo de un proveedor, de la más antigua a la más nueva.
     *
     * @return Collection<int, Cuota>
     */
    public function abiertas(User $usuario, string $proveedor, string $moneda): Collection
    {
        return Cuota::query()
            ->join('gastos as g', 'g.id', '=', 'gastos_cuotas.gasto_id')
            ->where('g.beneficiario', $proveedor)
            ->where('g.moneda', $moneda)
            ->whereNotNull('g.importe')
            ->when(! $usuario->can('gastos.personales'), fn ($q) => $q->where('g.ambito', 'empresarial'))
            // Las obligaciones de planilla también son gastos con el nombre del empleado como
            // beneficiario: sin este recorte, escribir ese nombre en «proveedor» mostraba la
            // cuota y el saldo de un sueldo a quien no tiene `planilla.salarios`.
            ->when($this->proteccion->ocultosPara($usuario), fn ($q, $ocultos) => $q->whereNotIn('g.id', $ocultos))
            // Más antiguas primero: por fecha de la compra, y con el id como desempate
            // para que el orden sea estable entre dos compras del mismo día.
            ->orderBy('g.created_at')
            ->orderBy('gastos_cuotas.id')
            ->select('gastos_cuotas.*')
            ->with('gasto')
            ->get()
            ->filter(fn (Cuota $c) => $this->saldos->pendienteCuota($c) > 0)
            ->values();
    }

    /** Lo que se le debe a un proveedor, en centavos. */
    public function saldo(User $usuario, string $proveedor, string $moneda): int
    {
        return $this->abiertas($usuario, $proveedor, $moneda)
            ->sum(fn (Cuota $c) => $this->saldos->pendienteCuota($c));
    }

    /**
     * Propone cómo repartir un abono: lo más antiguo primero.
     *
     * Devuelve el plan para que la pantalla lo enseñe ANTES de confirmar. Quien mira
     * tiene que poder ver contra qué compras va su dinero, y cambiarlo si no es eso.
     *
     * @return array{
     *     lineas: array<int, array{cuota_id: int, gasto_id: int, concepto: string,
     *                              fecha: ?string, pendiente: int, aplica: int, queda: int}>,
     *     aplicado: int, sobrante: int
     * }
     */
    public function repartir(User $usuario, string $proveedor, string $moneda, int $centavos): array
    {
        $resto = $centavos;
        $lineas = [];

        foreach ($this->abiertas($usuario, $proveedor, $moneda) as $cuota) {
            if ($resto <= 0) {
                break;
            }

            $pendiente = $this->saldos->pendienteCuota($cuota);
            $aplica = min($resto, $pendiente);
            $resto -= $aplica;

            $lineas[] = [
                'cuota_id' => $cuota->id,
                'gasto_id' => $cuota->gasto_id,
                'concepto' => $cuota->gasto->concepto,
                'fecha' => $cuota->gasto->created_at?->format('d/m/Y'),
                'vence' => $cuota->vence?->format('d/m/Y'),
                'pendiente' => $pendiente,
                'aplica' => $aplica,
                'queda' => $pendiente - $aplica,
            ];
        }

        return [
            'lineas' => $lineas,
            'aplicado' => $centavos - $resto,
            // Lo que no cabe en ninguna compra abierta. No se guarda «a favor»: se avisa,
            // porque un abono mayor que la deuda casi siempre es un error de tecleo.
            'sobrante' => $resto,
        ];
    }

    /**
     * Agrega una compra a la cuenta.
     *
     * El vencimiento es OPCIONAL: una cuenta abierta normalmente no lo tiene, pero una
     * compra concreta sí puede haberse pactado para una fecha. No se exige y no se
     * inventa; si viene, se guarda y la compra aparece con su fecha.
     *
     * @param  array<string, mixed>  $datos
     */
    public function agregarCompra(User $usuario, array $datos): Gasto
    {
        abort_unless($usuario->activo && $usuario->can('gastos.registrar'), 403);

        $datos = Validator::make($datos, [
            'clave' => ['required', 'uuid'],
            'beneficiario' => ['required', 'string', 'max:180'],
            'concepto' => ['required', 'string', 'max:200'],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'ambito' => ['required', Rule::in(array_keys(Gasto::AMBITOS))],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'categoria' => ['nullable', 'string', 'max:100'],
            // Opcional, y a propósito: ver el docblock.
            'vence' => ['nullable', 'date_format:Y-m-d'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'concepto.required' => 'Escribí qué compraste.',
            'importe.required' => 'Escribí cuánto fue la compra.',
            'fecha.before_or_equal' => 'La compra no puede ser de una fecha futura.',
        ])->validate();

        abort_unless(
            $datos['ambito'] === 'empresarial' || $usuario->can('gastos.personales'),
            403
        );

        $centavos = Dinero::centavos((string) $datos['importe']);

        return DB::transaction(function () use ($usuario, $datos, $centavos) {
            if ($existente = Gasto::where('clave', $datos['clave'])->first()) {
                abort_unless($this->acceso->ver($usuario, $existente), 403);

                return $existente;
            }

            $gasto = Gasto::create([
                'clave' => $datos['clave'],
                'huella_peticion' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)),
                'beneficiario' => trim($datos['beneficiario']),
                'concepto' => trim($datos['concepto']),
                'categoria' => filled($datos['categoria'] ?? null) ? trim($datos['categoria']) : 'Compras',
                'ambito' => $datos['ambito'],
                'naturaleza' => 'compra',
                'moneda' => $datos['moneda'],
                'importe' => Dinero::decimal($centavos),
                'documentacion' => 'pendiente',
                'observaciones' => $datos['observaciones'] ?? null,
                'responsable_id' => $usuario->id,
                'registrado_por' => $usuario->id,
                'created_at' => $datos['fecha'],
            ]);

            $gasto->cuotas()->create([
                'numero' => 1,
                'importe' => Dinero::decimal($centavos),
                'vence' => $datos['vence'] ?? null,
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id, 'usuario_id' => $usuario->id,
                'accion' => 'gasto_registrado',
                'datos' => json_encode(['via' => 'cuenta_proveedor'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $gasto->fresh();
        });
    }

    /**
     * Registra un abono contra la cuenta.
     *
     * Sin `$reparto`, se usa el propuesto —lo más antiguo primero—. Con `$reparto`, se
     * respeta lo que diga quien paga: es el caso de «estos 400 son de la compra del
     * martes».
     *
     * El pago en sí lo hace {@see RegistrarPago}, que ya valida que ninguna aplicación
     * supere el pendiente de su cuota y que la clave no se haya usado con otros datos.
     * Acá no se reimplementa ni una de esas dos comprobaciones.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<int, array{cuota_id: int, importe: string}>|null  $reparto
     */
    public function abonar(User $usuario, array $datos, ?array $reparto = null): Pago
    {
        $datos = Validator::make($datos, [
            'clave' => ['required', 'uuid'],
            'beneficiario' => ['required', 'string', 'max:180'],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'referencia' => ['nullable', 'string', 'max:180'],
            'sin_comprobante' => ['nullable', 'string', 'max:250'],
        ], [
            'importe.required' => 'Escribí cuánto le abonaste.',
        ])->validate();

        $centavos = Dinero::centavos((string) $datos['importe']);

        if ($reparto === null) {
            $plan = $this->repartir($usuario, $datos['beneficiario'], $datos['moneda'], $centavos);

            if ($plan['sobrante'] > 0) {
                throw ValidationException::withMessages([
                    'importe' => 'El abono supera lo que se le debe por '
                        .$datos['moneda'].' '.Dinero::decimal($plan['sobrante'])
                        .'. Revisá el importe, o agregá primero la compra que falta.',
                ]);
            }

            if ($plan['lineas'] === []) {
                throw ValidationException::withMessages([
                    'importe' => 'Esta cuenta no tiene compras pendientes. Agregá la compra antes de abonar.',
                ]);
            }

            $reparto = array_map(fn (array $l) => [
                'cuota_id' => $l['cuota_id'],
                'importe' => Dinero::decimal($l['aplica']),
            ], $plan['lineas']);
        }

        return $this->pagos->registrar($usuario, [
            'clave' => $datos['clave'],
            'importe' => Dinero::decimal($centavos),
            'fecha' => $datos['fecha'],
            'metodo' => $datos['metodo'],
            'pagado_por' => $usuario->id,
            'referencia' => $datos['referencia'] ?? null,
            'sin_comprobante' => $datos['sin_comprobante'] ?? null,
        ], $reparto);
    }

    /**
     * Los proveedores que tienen cuenta abierta, con su saldo. Es la lista de la
     * pantalla «Cuentas con proveedores».
     *
     * @return Collection<int, object>
     */
    public function cuentas(User $usuario): Collection
    {
        $consulta = Gasto::query()
            ->join('gastos_cuotas as c', 'c.gasto_id', '=', 'gastos.id')
            ->whereNotNull('gastos.importe')
            ->where('gastos.naturaleza', 'compra');

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        }

        return $consulta
            ->groupBy('gastos.beneficiario', 'gastos.moneda')
            ->select('gastos.beneficiario', 'gastos.moneda')
            ->selectRaw('COUNT(DISTINCT gastos.id) as compras')
            ->orderBy('gastos.beneficiario')
            ->get()
            ->map(function (object $fila) use ($usuario) {
                $fila->saldo = $this->saldo($usuario, $fila->beneficiario, $fila->moneda);

                return $fila;
            })
            // Solo las que deben algo: una cuenta saldada no es una cuenta abierta.
            ->filter(fn (object $fila) => $fila->saldo > 0)
            ->values();
    }
}
