<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * «Compré y pagué»: lo que se paga en el momento, en una sola operación.
 *
 * ═══════ El problema que resuelve ═══════
 *
 * Registrar unas bolsas ya pagadas costaba 19 campos y 3 pantallas: había que crear una
 * deuda de 85 dólares, ir a buscarla y saldarla. Esa deuda vivía unos segundos y durante
 * ese rato el sistema afirmaba algo falso —que se le debía a alguien—. Y de paso pedía
 * un vencimiento para algo ya pagado.
 *
 * Acá el gasto y su pago nacen juntos, dentro de la misma transacción. O están los dos o
 * no está ninguno: no existe el estado intermedio.
 *
 * ═══════ Lo que NO hace ═══════
 *
 * No inventa una vía paralela. El gasto que crea es un gasto normal y el pago es un pago
 * normal, con su clave única, su aplicación por cuota y su rastro. Aparecen en el
 * historial, en los informes y en las búsquedas como cualquier otro, y los permisos son
 * los de siempre: hace falta registrar gastos Y registrar pagos, porque se hacen las dos
 * cosas.
 *
 * ═══════ El ámbito se PREGUNTA ═══════
 *
 * Dos opciones a la vista, empresa o casa. Fijarlo en «empresa» habría ahorrado un campo
 * a costa de clasificar mal todo lo personal que entre por acá, y un gasto mal
 * clasificado ensucia los informes de los dos lados. Quien no tiene permiso para lo
 * personal no ve la opción, y el servicio lo vuelve a comprobar.
 */
final class CompraContado
{
    /** Espacio de nombres propio para derivar la clave del pago desde la del gasto. */
    private const NS_PAGO = 'b1d4c7e2-3a58-4f16-9c02-7e5a8d3f1b46';

    public function __construct(
        private AccesoGastos $acceso,
        private RegistrarPago $pagos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @return array{gasto: Gasto, pago: Pago}
     */
    public function registrar(User $usuario, array $datos): array
    {
        abort_unless($usuario->activo, 403);
        // Las dos cosas, porque se hacen las dos. Un atajo en la pantalla no puede ser
        // un atajo en los permisos.
        abort_unless($usuario->can('gastos.registrar'), 403);
        abort_unless($usuario->can('gastos.pagos.registrar'), 403);

        $datos = Validator::make($datos, [
            'clave' => ['required', 'uuid'],
            'concepto' => ['required', 'string', 'max:200'],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'ambito' => ['required', Rule::in(array_keys(Gasto::AMBITOS))],
            'beneficiario' => ['nullable', 'string', 'max:180'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'persona' => ['nullable', 'string', 'max:180'],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'sin_comprobante' => ['nullable', 'string', 'max:250'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'concepto.required' => 'Escribí qué compraste.',
            'importe.required' => 'Escribí cuánto pagaste.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura: esto ya se pagó.',
            'ambito.required' => 'Elegí si es de la empresa o de la casa.',
        ])->validate();

        // El candado de ámbito, otra vez y en el servicio: la pantalla esconde la opción
        // personal a quien no la tiene, pero esconder no autoriza.
        abort_unless(
            $datos['ambito'] === 'empresarial' || $usuario->can('gastos.personales'),
            403
        );

        $centavos = Dinero::centavos((string) $datos['importe']);

        return DB::transaction(function () use ($usuario, $datos, $centavos) {
            // Idempotencia: la misma clave devuelve lo que ya se guardó. Un doble clic no
            // registra la compra dos veces.
            if ($existente = Gasto::where('clave', $datos['clave'])->first()) {
                abort_unless($this->acceso->ver($usuario, $existente), 403);

                return ['gasto' => $existente, 'pago' => $this->pagoDe($existente)];
            }

            $gasto = Gasto::create([
                'clave' => $datos['clave'],
                'huella_peticion' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)),
                // Sin proveedor: se dice así, no se deja vacío. «Sin proveedor» es un
                // dato; una cadena en blanco es una pregunta sin responder.
                'beneficiario' => filled($datos['beneficiario'] ?? null)
                    ? trim($datos['beneficiario'])
                    : 'Sin proveedor',
                'concepto' => trim($datos['concepto']),
                'categoria' => filled($datos['categoria'] ?? null) ? trim($datos['categoria']) : 'Compras',
                'ambito' => $datos['ambito'],
                'persona' => $datos['persona'] ?? null,
                'naturaleza' => 'compra',
                'moneda' => $datos['moneda'],
                'importe' => Dinero::decimal($centavos),
                'documentacion' => filled($datos['sin_comprobante'] ?? null) ? 'no_entregaron' : 'pendiente',
                'observaciones' => $datos['observaciones'] ?? null,
                'responsable_id' => $usuario->id,
                'registrado_por' => $usuario->id,
            ]);

            // Una cuota, vencida el mismo día del pago. NO se pide vencimiento: lo que ya
            // se pagó venció cuando se pagó, y preguntarlo sería pedir un dato que la
            // propia operación ya contiene.
            $cuota = $gasto->cuotas()->create([
                'numero' => 1,
                'importe' => Dinero::decimal($centavos),
                'vence' => $datos['fecha'],
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id, 'usuario_id' => $usuario->id,
                'accion' => 'gasto_registrado',
                'datos' => json_encode(['via' => 'compre_y_pague'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            // El pago pasa por el servicio de siempre: mismos candados, mismo control de
            // duplicados, mismo rastro. Su clave se DERIVA de la del gasto para que
            // reintentar la operación entera tampoco pague dos veces.
            $pago = $this->pagos->registrar($usuario, [
                // DERIVADA de la clave del gasto: reintentar la operación entera produce
                // la misma clave de pago, así que el índice único la frena aunque el
                // gasto ya existiera.
                'clave' => Uuid::uuid5(self::NS_PAGO, $datos['clave'])->toString(),
                'importe' => Dinero::decimal($centavos),
                'fecha' => $datos['fecha'],
                'metodo' => $datos['metodo'],
                'pagado_por' => $usuario->id,
                'referencia' => null,
                'sin_comprobante' => $datos['sin_comprobante'] ?? null,
            ], [['cuota_id' => $cuota->id, 'importe' => Dinero::decimal($centavos)]]);

            return ['gasto' => $gasto->fresh(), 'pago' => $pago];
        });
    }

    /** El pago que saldó este gasto, para devolverlo en un reintento. */
    private function pagoDe(Gasto $gasto): ?Pago
    {
        return Pago::whereIn('id', DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->where('c.gasto_id', $gasto->id)
            ->select('a.pago_id'))->first();
    }
}
