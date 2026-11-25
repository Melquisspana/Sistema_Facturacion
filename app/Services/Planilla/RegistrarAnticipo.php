<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Pago;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Anticipos: dinero que ya se le entregó a alguien y que se recupera por planilla.
 *
 * ═══════ Por qué no alcanza con escribir la referencia ═══════
 *
 * Un descuento que dice «anticipo, recibo 148» no impide nada. En la quincena siguiente
 * alguien escribe otra vez «recibo 148» y el mismo dinero se recupera dos veces, sin
 * que ningún control salte: los dos descuentos son válidos por separado.
 *
 * Con el anticipo como fila, cada recuperación se APLICA contra él y lo que queda es
 * una resta comprobable. Descontar de más se rechaza con la cifra exacta que queda.
 *
 * ═══════ Y se enlaza con el pago real cuando lo hay ═══════
 *
 * Si el anticipo salió por Gastos —hay un pago registrado—, se enlaza. Ese vínculo es
 * único: el mismo pago no puede quedar registrado como dos anticipos distintos, que es
 * la otra forma de duplicar. Cuando el dinero se entregó fuera del sistema, el vínculo
 * queda vacío y la referencia lo describe; el control sigue siendo el importe.
 */
final class RegistrarAnticipo
{
    /** @param  array<string, mixed>  $datos */
    public function registrar(User $usuario, PlanillaEmpleado $empleado, array $datos): PlanillaAnticipo
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        // `gt:0` no necesita `numeric`: el formato ya exige cifras, y con cifras Laravel
        // compara como número. `bail` deja un solo aviso cuando el importe está mal escrito.
        $datos = Validator::make($datos, [
            'clave' => ['nullable', 'uuid'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'importe' => ['bail', 'required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'pago_id' => ['nullable', 'integer', Rule::exists('gastos_pagos', 'id')],
            'referencia' => ['nullable', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:250'],
        ], [
            'importe.regex' => 'Escribí el anticipo con hasta dos decimales, sin comas.',
            'importe.gt' => 'El anticipo tiene que ser mayor que cero.',
        ])->validate();

        $clave = $datos['clave'] ?? (string) Str::uuid();

        return DB::transaction(function () use ($usuario, $empleado, $datos, $clave) {
            if ($existente = PlanillaAnticipo::where('clave', $clave)->first()) {
                return $existente;
            }

            if (filled($datos['pago_id'] ?? null)) {
                $pago = Pago::whereKey($datos['pago_id'])->lockForUpdate()->firstOrFail();

                if ($pago->revertido_at !== null) {
                    throw ValidationException::withMessages([
                        'pago_id' => 'Ese pago está revertido: no representa dinero entregado.',
                    ]);
                }

                // Solo un pago a esta persona, como los que ofrece la pantalla
                // ({@see pagosCandidatos()}): si no, se le descontaría del sueldo dinero
                // que se le entregó a otro. El aviso no dice a quién se le pagó: puede ser
                // un gasto que quien registra el anticipo no tiene por qué ver.
                if (! $this->esPagoDe($pago, $empleado)) {
                    throw ValidationException::withMessages([
                        'pago_id' => 'Ese pago no se le hizo a '.$empleado->nombre.': no puede ser su anticipo.',
                    ]);
                }

                if ($this->pagosDePlanilla()->where('a.pago_id', $pago->id)->exists()) {
                    throw ValidationException::withMessages([
                        'pago_id' => 'Ese pago es de una planilla, lo que se le pagó por su trabajo: no puede ser un anticipo.',
                    ]);
                }

                // Se recupera lo que salió en ese pago, o una parte: un cero de más al
                // escribirlo se le descontaría del sueldo.
                if (Dinero::centavos($datos['importe']) > Dinero::centavos((string) $pago->importe)) {
                    throw ValidationException::withMessages([
                        'importe' => 'El anticipo no puede ser mayor que el pago con el que se entregó ('.$pago->moneda.' '.$pago->importe.').',
                    ]);
                }

                if (PlanillaAnticipo::where('pago_id', $pago->id)->exists()) {
                    throw ValidationException::withMessages([
                        'pago_id' => 'Ese pago ya está registrado como anticipo. Un mismo pago no puede recuperarse dos veces.',
                    ]);
                }
            }

            return PlanillaAnticipo::create([
                'clave' => $clave,
                'planilla_empleado_id' => $empleado->id,
                'fecha' => $datos['fecha'],
                'importe' => Dinero::decimal(Dinero::centavos($datos['importe'])),
                'moneda' => $datos['moneda'],
                'pago_id' => $datos['pago_id'] ?? null,
                'referencia' => $datos['referencia'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'registrado_por' => $usuario->id,
            ]);
        });
    }

    /**
     * Anticipos de una persona que todavía tienen algo por recuperar.
     *
     * @return Collection<int, PlanillaAnticipo>
     */
    public function disponibles(PlanillaEmpleado $empleado): Collection
    {
        return PlanillaAnticipo::where('planilla_empleado_id', $empleado->id)
            ->orderBy('fecha')
            ->get()
            ->filter(fn (PlanillaAnticipo $a) => $a->pendiente() > 0)
            ->values();
    }

    /**
     * Pagos de Gastos que podrían ser el anticipo de esta persona: los que se le
     * hicieron a ella, siguen vigentes, no son de una planilla y todavía no están
     * registrados como anticipo.
     *
     * La identidad es el NOMBRE, porque es lo que `gastos_pagos` guarda. No se fuerza
     * el enlace: si no aparece, el anticipo se registra igual sin pago.
     *
     * @return Collection<int, Pago>
     */
    public function pagosCandidatos(PlanillaEmpleado $empleado): Collection
    {
        $yaUsados = PlanillaAnticipo::whereNotNull('pago_id')->pluck('pago_id');

        return Pago::where('beneficiario', $empleado->nombre)
            ->whereNull('revertido_at')
            ->whereNotIn('id', $yaUsados)
            ->whereNotIn('id', $this->pagosDePlanilla())
            ->orderByDesc('fecha')
            ->limit(25)
            ->get()
            // La consulta compara según la base: en MySQL «jose lopez» encuentra a
            // «José López». Se deja solo lo que registrar() acepta, para que la pantalla
            // no ofrezca un pago que al guardar se rechaza.
            ->filter(fn (Pago $pago) => $this->esPagoDe($pago, $empleado))
            ->values();
    }

    /** El nombre exacto, sin fusionar por parecido, como al registrar un pago en Gastos. */
    private function esPagoDe(Pago $pago, PlanillaEmpleado $empleado): bool
    {
        return $pago->beneficiario === $empleado->nombre;
    }

    /**
     * Pagos que saldan lo que una planilla le debía a una persona (su quincena, sus
     * vacaciones…): son lo que cobró por trabajar, no un adelanto. Como anticipo, la
     * planilla siguiente le descontaría lo que ya se había ganado.
     */
    private function pagosDePlanilla(): Builder
    {
        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('planilla_detalles as d', 'd.gasto_id', '=', 'c.gasto_id')
            ->select('a.pago_id');
    }
}
